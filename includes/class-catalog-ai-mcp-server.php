<?php
/**
 * MCP (Model Context Protocol) Server.
 *
 * Exposes the plugin's capabilities as MCP tools so that external
 * AI agents can interact with the catalog generation system.
 *
 * MCP tools registered:
 *  - catalog_ai.generate_image    — Queue a single product image generation.
 *  - catalog_ai.generate_batch    — Queue a batch of products.
 *  - catalog_ai.get_job_status    — Check the status of a generation job.
 *  - catalog_ai.list_images       — List AI-generated images for a product.
 *  - catalog_ai.get_product_info  — Get product details for context.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Catalog_AI_MCP_Server {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		// Hook into MCP registration if a WordPress MCP plugin is active.
		add_action( 'mcp_register_tools', [ $this, 'register_mcp_tools' ] );
		add_filter( 'mcp_server_capabilities', [ $this, 'declare_capabilities' ] );
	}

	/**
	 * Register MCP-specific REST endpoints.
	 *
	 * These follow the MCP JSON-RPC transport spec over HTTP.
	 */
	public function register_routes(): void {
		// MCP tool discovery endpoint.
		register_rest_route( CATALOG_AI_REST_NAMESPACE, '/mcp/tools', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_list_tools' ],
			'permission_callback' => [ $this, 'check_mcp_permission' ],
		] );

		// MCP tool execution endpoint.
		register_rest_route( CATALOG_AI_REST_NAMESPACE, '/mcp/execute', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_execute_tool' ],
			'permission_callback' => [ $this, 'check_mcp_permission' ],
		] );
	}

	/**
	 * Check MCP access permission.
	 *
	 * Supports both WordPress authentication and API key auth for external agents.
	 */
	public function check_mcp_permission( \WP_REST_Request $request ): bool {
		// WordPress admin access.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		// API key authentication for external agents.
		$api_key  = $request->get_header( 'X-MCP-API-Key' );
		$expected = get_option( CATALOG_AI_OPTION_PREFIX . 'mcp_api_key', '' );

		if ( ! empty( $expected ) && ! empty( $api_key ) ) {
			return hash_equals( $expected, $api_key );
		}

		return false;
	}

	/**
	 * Return the list of available MCP tools.
	 */
	public function handle_list_tools( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'tools' => $this->get_tool_definitions(),
		], 200 );
	}

	/**
	 * Execute an MCP tool.
	 */
	public function handle_execute_tool( \WP_REST_Request $request ): \WP_REST_Response {
		$tool_name = sanitize_text_field( $request->get_param( 'tool' ) );
		$arguments = $request->get_param( 'arguments' ) ?? [];

		$tools = $this->get_tool_handlers();

		if ( ! isset( $tools[ $tool_name ] ) ) {
			return new \WP_REST_Response( [
				'error' => [
					'code'    => 'tool_not_found',
					'message' => sprintf( 'Tool "%s" not found.', $tool_name ),
				],
			], 404 );
		}

		$result = call_user_func( $tools[ $tool_name ], $arguments );

		return new \WP_REST_Response( [
			'result' => $result,
		], 200 );
	}

	/**
	 * Register tools with external MCP plugin (if available).
	 */
	public function register_mcp_tools( $registry ): void {
		foreach ( $this->get_tool_definitions() as $tool ) {
			if ( method_exists( $registry, 'register_tool' ) ) {
				$registry->register_tool( $tool );
			}
		}
	}

	/**
	 * Declare MCP server capabilities.
	 */
	public function declare_capabilities( array $capabilities ): array {
		$capabilities['catalog_ai'] = [
			'name'        => 'Catalog AI',
			'description' => 'AI-powered product catalog image generation for WooCommerce.',
			'version'     => CATALOG_AI_VERSION,
			'tools'       => array_column( $this->get_tool_definitions(), 'name' ),
		];
		return $capabilities;
	}

	/**
	 * MCP tool definitions (schema).
	 */
	private function get_tool_definitions(): array {
		return [
			[
				'name'        => 'catalog_ai.generate_image',
				'description' => 'Generate an AI product image for a WooCommerce product. Supports virtual try-on (apparel on a model) and recontextualization (product in a lifestyle scene).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_id'      => [ 'type' => 'integer', 'description' => 'WooCommerce product ID.' ],
						'mode'            => [ 'type' => 'string', 'enum' => [ 'try_on', 'recontext' ], 'description' => 'Generation mode.' ],
						'scene_prompt'    => [ 'type' => 'string', 'description' => 'Scene description for recontextualization.' ],
						'person_image_id' => [ 'type' => 'integer', 'description' => 'Attachment ID of person image for try-on.' ],
						'target'          => [ 'type' => 'string', 'enum' => [ 'thumbnail', 'gallery' ], 'default' => 'gallery' ],
					],
					'required'   => [ 'product_id', 'mode' ],
				],
			],
			[
				'name'        => 'catalog_ai.generate_batch',
				'description' => 'Queue AI image generation for multiple WooCommerce products at once.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_ids'  => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of product IDs.' ],
						'mode'         => [ 'type' => 'string', 'enum' => [ 'try_on', 'recontext' ] ],
						'scene_prompt' => [ 'type' => 'string' ],
						'target'       => [ 'type' => 'string', 'enum' => [ 'thumbnail', 'gallery' ], 'default' => 'gallery' ],
					],
					'required'   => [ 'product_ids', 'mode' ],
				],
			],
			[
				'name'        => 'catalog_ai.get_job_status',
				'description' => 'Check the status of an image generation job.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'job_id' => [ 'type' => 'string', 'description' => 'Job UUID.' ],
					],
					'required'   => [ 'job_id' ],
				],
			],
			[
				'name'        => 'catalog_ai.list_images',
				'description' => 'List all AI-generated images for a WooCommerce product.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_id' => [ 'type' => 'integer', 'description' => 'WooCommerce product ID.' ],
					],
					'required'   => [ 'product_id' ],
				],
			],
			[
				'name'        => 'catalog_ai.get_product_info',
				'description' => 'Get WooCommerce product details (name, SKU, price, categories, image URLs) for context.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_id' => [ 'type' => 'integer', 'description' => 'WooCommerce product ID.' ],
					],
					'required'   => [ 'product_id' ],
				],
			],
		];
	}

	/**
	 * MCP tool handler map.
	 */
	private function get_tool_handlers(): array {
		return [
			'catalog_ai.generate_image'  => [ $this, 'tool_generate_image' ],
			'catalog_ai.generate_batch'  => [ $this, 'tool_generate_batch' ],
			'catalog_ai.get_job_status'  => [ $this, 'tool_get_job_status' ],
			'catalog_ai.list_images'     => [ $this, 'tool_list_images' ],
			'catalog_ai.get_product_info' => [ $this, 'tool_get_product_info' ],
		];
	}

	// --- Tool Implementations ---

	private function tool_generate_image( array $args ): array {
		$result = Catalog_AI_Queue::instance()->enqueue( [
			'product_id'      => absint( $args['product_id'] ?? 0 ),
			'mode'            => sanitize_text_field( $args['mode'] ?? 'recontext' ),
			'scene_prompt'    => sanitize_text_field( $args['scene_prompt'] ?? '' ),
			'person_image_id' => absint( $args['person_image_id'] ?? 0 ),
			'target'          => sanitize_text_field( $args['target'] ?? 'gallery' ),
		] );

		if ( is_wp_error( $result ) ) {
			return [ 'error' => $result->get_error_message() ];
		}

		return [ 'status' => 'queued', 'action_id' => $result ];
	}

	private function tool_generate_batch( array $args ): array {
		$product_ids = array_map( 'absint', $args['product_ids'] ?? [] );
		$mode        = sanitize_text_field( $args['mode'] ?? 'recontext' );

		$batch_id = Catalog_AI_Queue::instance()->enqueue_batch( $product_ids, $mode, [
			'scene_prompt' => sanitize_text_field( $args['scene_prompt'] ?? '' ),
			'target'       => sanitize_text_field( $args['target'] ?? 'gallery' ),
		] );

		return [ 'batch_id' => $batch_id, 'total' => count( $product_ids ), 'status' => 'queued' ];
	}

	private function tool_get_job_status( array $args ): array {
		$job_id = sanitize_text_field( $args['job_id'] ?? '' );
		$job    = Catalog_AI_Queue::instance()->get_job_meta( $job_id );

		return $job ?? [ 'error' => 'Job not found' ];
	}

	private function tool_list_images( array $args ): array {
		$product_id = absint( $args['product_id'] ?? 0 );
		$image_ids  = Catalog_AI_Media::instance()->get_generated_images( $product_id );

		return array_map( function ( int $id ) {
			return [
				'id'  => $id,
				'url' => wp_get_attachment_url( $id ),
			];
		}, $image_ids );
	}

	private function tool_get_product_info( array $args ): array {
		$product_id = absint( $args['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return [ 'error' => 'Product not found' ];
		}

		return [
			'id'         => $product->get_id(),
			'name'       => $product->get_name(),
			'sku'        => $product->get_sku(),
			'price'      => $product->get_price(),
			'type'       => $product->get_type(),
			'status'     => $product->get_status(),
			'categories' => wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] ),
			'image_url'  => wp_get_attachment_url( $product->get_image_id() ),
			'gallery'    => array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ),
		];
	}
}
