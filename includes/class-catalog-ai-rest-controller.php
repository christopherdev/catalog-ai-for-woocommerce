<?php
/**
 * REST API Controller.
 *
 * Exposes endpoints for the admin UI (and external integrations) to:
 *  - Submit generation jobs (single + batch).
 *  - Query job/batch status.
 *  - List generated images for a product.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Catalog_AI_REST_Controller {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		// Submit a single generation job.
		register_rest_route( CATALOG_AI_REST_NAMESPACE, '/generate', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_generate' ],
			'permission_callback' => [ $this, 'check_admin_permission' ],
			'args'                => [
				'product_id'   => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
				'mode'         => [ 'required' => true, 'type' => 'string', 'enum' => [ 'try_on', 'recontext', 'bgswap' ] ],
				'scene_prompt' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'person_image_id' => [ 'type' => 'integer', 'sanitize_callback' => 'absint' ],
				'target'       => [ 'type' => 'string', 'default' => 'gallery', 'enum' => [ 'thumbnail', 'gallery' ] ],
			],
		] );

		// Submit a batch of products.
		register_rest_route( CATALOG_AI_REST_NAMESPACE, '/generate/batch', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_batch' ],
			'permission_callback' => [ $this, 'check_admin_permission' ],
			'args'                => [
				'product_ids'  => [ 'required' => true, 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
				'mode'         => [ 'required' => true, 'type' => 'string', 'enum' => [ 'try_on', 'recontext', 'bgswap' ] ],
				'scene_prompt' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'target'       => [ 'type' => 'string', 'default' => 'gallery', 'enum' => [ 'thumbnail', 'gallery' ] ],
			],
		] );

		// Get job status.
		register_rest_route( CATALOG_AI_REST_NAMESPACE, '/jobs/(?P<job_id>[a-f0-9\-]+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_job_status' ],
			'permission_callback' => [ $this, 'check_admin_permission' ],
		] );

		// Get batch status.
		register_rest_route( CATALOG_AI_REST_NAMESPACE, '/batches/(?P<batch_id>[a-f0-9\-]+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_batch_status' ],
			'permission_callback' => [ $this, 'check_admin_permission' ],
		] );

		// List generated images for a product.
		register_rest_route( CATALOG_AI_REST_NAMESPACE, '/products/(?P<product_id>\d+)/images', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_product_images' ],
			'permission_callback' => [ $this, 'check_admin_permission' ],
		] );

		// Usage & cost statistics.
		register_rest_route( CATALOG_AI_REST_NAMESPACE, '/usage', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_usage' ],
			'permission_callback' => [ $this, 'check_admin_permission' ],
		] );

		// Cost estimate.
		register_rest_route( CATALOG_AI_REST_NAMESPACE, '/estimate', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_estimate' ],
			'permission_callback' => [ $this, 'check_admin_permission' ],
			'args'                => [
				'mode'          => [ 'required' => true, 'type' => 'string', 'enum' => [ 'try_on', 'recontext', 'bgswap' ] ],
				'product_count' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
				'sample_count'  => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
			],
		] );

		// Plugin status / health check.
		register_rest_route( CATALOG_AI_REST_NAMESPACE, '/status', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_status' ],
			'permission_callback' => [ $this, 'check_admin_permission' ],
		] );
	}

	public function check_admin_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function handle_generate( \WP_REST_Request $request ): \WP_REST_Response {
		$job_data = [
			'product_id'      => $request->get_param( 'product_id' ),
			'mode'            => $request->get_param( 'mode' ),
			'scene_prompt'    => $request->get_param( 'scene_prompt' ) ?? '',
			'person_image_id' => $request->get_param( 'person_image_id' ) ?? 0,
			'target'          => $request->get_param( 'target' ),
		];

		$result = Catalog_AI_Queue::instance()->enqueue( $job_data );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );
		}

		return new \WP_REST_Response( [
			'job_id'    => $job_data['job_id'] ?? null,
			'action_id' => $result,
			'status'    => 'queued',
		], 202 );
	}

	public function handle_batch( \WP_REST_Request $request ): \WP_REST_Response {
		$product_ids = array_map( 'absint', $request->get_param( 'product_ids' ) );
		$mode        = $request->get_param( 'mode' );
		$options     = [
			'scene_prompt'    => $request->get_param( 'scene_prompt' ) ?? '',
			'person_image_id' => $request->get_param( 'person_image_id' ) ?? 0,
			'target'          => $request->get_param( 'target' ),
		];

		$batch_id = Catalog_AI_Queue::instance()->enqueue_batch( $product_ids, $mode, $options );

		return new \WP_REST_Response( [
			'batch_id' => $batch_id,
			'total'    => count( $product_ids ),
			'status'   => 'queued',
		], 202 );
	}

	public function handle_job_status( \WP_REST_Request $request ): \WP_REST_Response {
		$job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
		$job    = Catalog_AI_Queue::instance()->get_job_meta( $job_id );

		if ( ! $job ) {
			return new \WP_REST_Response( [ 'error' => 'Job not found' ], 404 );
		}

		return new \WP_REST_Response( $job, 200 );
	}

	public function handle_batch_status( \WP_REST_Request $request ): \WP_REST_Response {
		$batch_id = sanitize_text_field( $request->get_param( 'batch_id' ) );
		$batch    = get_option( CATALOG_AI_OPTION_PREFIX . 'batch_' . $batch_id );

		if ( ! $batch ) {
			return new \WP_REST_Response( [ 'error' => 'Batch not found' ], 404 );
		}

		return new \WP_REST_Response( $batch, 200 );
	}

	public function handle_product_images( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = absint( $request->get_param( 'product_id' ) );
		$image_ids  = Catalog_AI_Media::instance()->get_generated_images( $product_id );

		$images = array_map( function ( int $id ) {
			return [
				'id'  => $id,
				'url' => wp_get_attachment_url( $id ),
				'sizes' => wp_get_attachment_metadata( $id )['sizes'] ?? [],
			];
		}, $image_ids );

		return new \WP_REST_Response( $images, 200 );
	}

	public function handle_usage( \WP_REST_Request $request ): \WP_REST_Response {
		$all_stats    = Catalog_AI_API_Client::get_usage_stats();
		$current_month = gmdate( 'Y-m' );
		$current       = $all_stats[ $current_month ] ?? [
			Catalog_AI_API_Client::MODE_TRY_ON    => [ 'count' => 0, 'cost' => 0.0 ],
			Catalog_AI_API_Client::MODE_RECONTEXT => [ 'count' => 0, 'cost' => 0.0 ],
		];

		$total_images = $current[ Catalog_AI_API_Client::MODE_TRY_ON ]['count']
			+ $current[ Catalog_AI_API_Client::MODE_RECONTEXT ]['count'];
		$total_cost   = $current[ Catalog_AI_API_Client::MODE_TRY_ON ]['cost']
			+ $current[ Catalog_AI_API_Client::MODE_RECONTEXT ]['cost'];

		return new \WP_REST_Response( [
			'current_month' => $current_month,
			'breakdown'     => $current,
			'total_images'  => $total_images,
			'total_cost'    => round( $total_cost, 2 ),
			'history'       => $all_stats,
			'cost_per_image' => Catalog_AI_API_Client::COST_PER_IMAGE,
		], 200 );
	}

	public function handle_estimate( \WP_REST_Request $request ): \WP_REST_Response {
		$mode          = $request->get_param( 'mode' );
		$product_count = $request->get_param( 'product_count' );
		$sample_count  = $request->get_param( 'sample_count' );

		$cost = Catalog_AI_API_Client::estimate_cost( $mode, $product_count, $sample_count );

		return new \WP_REST_Response( [
			'mode'           => $mode,
			'product_count'  => $product_count,
			'sample_count'   => $sample_count,
			'cost_per_image' => Catalog_AI_API_Client::COST_PER_IMAGE[ $mode ] ?? 0.06,
			'estimated_cost' => round( $cost, 2 ),
		], 200 );
	}

	public function handle_status( \WP_REST_Request $request ): \WP_REST_Response {
		$client = Catalog_AI_API_Client::instance();

		return new \WP_REST_Response( [
			'version'              => CATALOG_AI_VERSION,
			'configured'           => $client->is_configured(),
			'action_scheduler'     => function_exists( 'as_enqueue_async_action' ),
			'woocommerce'          => class_exists( 'WooCommerce' ),
			'webhook_url'          => rest_url( CATALOG_AI_REST_NAMESPACE . '/webhook/image-ready' ),
		], 200 );
	}
}
