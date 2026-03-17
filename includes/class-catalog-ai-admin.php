<?php
/**
 * Admin interface.
 *
 * Registers the settings page, admin menu, enqueues assets,
 * and provides the UI for configuring credentials and managing
 * generation jobs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Catalog_AI_Admin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register admin menu pages.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Catalog AI', 'catalog-ai' ),
			__( 'Catalog AI', 'catalog-ai' ),
			'manage_woocommerce',
			'catalog-ai',
			[ $this, 'render_dashboard' ],
			'dashicons-book-alt',
			30
		);

		add_submenu_page(
			'catalog-ai',
			__( 'Dashboard', 'catalog-ai' ),
			__( 'Dashboard', 'catalog-ai' ),
			'manage_woocommerce',
			'catalog-ai',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'catalog-ai',
			__( 'Settings', 'catalog-ai' ),
			__( 'Settings', 'catalog-ai' ),
			'manage_options',
			'catalog-ai-settings',
			[ $this, 'render_settings' ]
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings(): void {
		// GCP settings section.
		add_settings_section(
			'catalog_ai_gcp',
			__( 'Google Cloud Configuration', 'catalog-ai' ),
			function () {
				echo '<p>' . esc_html__( 'Configure your Google Cloud project and Vertex AI credentials.', 'catalog-ai' ) . '</p>';
			},
			'catalog-ai-settings'
		);

		$this->add_setting( 'gcp_project_id', __( 'GCP Project ID', 'catalog-ai' ), 'text', 'catalog_ai_gcp' );
		$this->add_setting( 'gcp_location', __( 'GCP Location', 'catalog-ai' ), 'text', 'catalog_ai_gcp', 'us-central1' );
		$this->add_setting( 'service_account_key', __( 'Service Account Key (JSON)', 'catalog-ai' ), 'json', 'catalog_ai_gcp' );

		// MCP settings section.
		add_settings_section(
			'catalog_ai_mcp',
			__( 'MCP Server Configuration', 'catalog-ai' ),
			function () {
				echo '<p>' . esc_html__( 'Configure access for external AI agents via the Model Context Protocol.', 'catalog-ai' ) . '</p>';
			},
			'catalog-ai-settings'
		);

		$this->add_setting( 'mcp_api_key', __( 'MCP API Key', 'catalog-ai' ), 'text', 'catalog_ai_mcp' );

		// Webhook section (read-only display).
		add_settings_section(
			'catalog_ai_webhook',
			__( 'Webhook', 'catalog-ai' ),
			function () {
				$url    = rest_url( CATALOG_AI_REST_NAMESPACE . '/webhook/image-ready' );
				$secret = get_option( CATALOG_AI_OPTION_PREFIX . 'webhook_secret', '' );
				echo '<p>' . esc_html__( 'Configure your Google Cloud workflow to POST to this URL when generation completes.', 'catalog-ai' ) . '</p>';
				echo '<table class="form-table"><tbody>';
				echo '<tr><th>' . esc_html__( 'Webhook URL', 'catalog-ai' ) . '</th><td><code>' . esc_url( $url ) . '</code></td></tr>';
				echo '<tr><th>' . esc_html__( 'Webhook Secret', 'catalog-ai' ) . '</th><td><code>' . esc_html( $secret ) . '</code></td></tr>';
				echo '</tbody></table>';
			},
			'catalog-ai-settings'
		);
	}

	/**
	 * Helper to register a single setting field.
	 */
	private function add_setting( string $key, string $label, string $type, string $section, string $default = '' ): void {
		$option_name = CATALOG_AI_OPTION_PREFIX . $key;

		$sanitize = match ( $type ) {
			'json'     => [ $this, 'sanitize_json_field' ],
			'textarea' => 'sanitize_textarea_field',
			default    => 'sanitize_text_field',
		};

		register_setting( 'catalog-ai-settings', $option_name, [
			'type'              => 'string',
			'sanitize_callback' => $sanitize,
			'default'           => $default,
		] );

		add_settings_field(
			$option_name,
			$label,
			function () use ( $option_name, $key, $type, $default ) {
				$value = get_option( $option_name, $default );

				// Decrypt encrypted values for display in the form.
				if ( 'json' === $type ) {
					$value = Catalog_AI_API_Client::decrypt_option( $key );
				}

				if ( 'textarea' === $type || 'json' === $type ) {
					echo '<textarea name="' . esc_attr( $option_name ) . '" rows="6" cols="60" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
				} else {
					echo '<input type="text" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
				}
			},
			'catalog-ai-settings',
			$section
		);
	}

	/**
	 * Sanitize a JSON field — validates it's valid JSON, preserves content.
	 */
	public function sanitize_json_field( string $value ): string {
		// WordPress strips backslashes via wp_unslash on POST data.
		// We need the raw value to preserve \n in private keys.
		$raw = $value;

		// Try to decode as-is first.
		$decoded = json_decode( $raw, true );

		// If that fails, try unslashing (in case WP hasn't done it yet).
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$raw = wp_unslash( $raw );
			$decoded = json_decode( $raw, true );
		}

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			add_settings_error(
				'catalog-ai-settings',
				'invalid_json',
				__( 'Service Account Key must be valid JSON.', 'catalog-ai' ),
				'error'
			);
			return get_option( CATALOG_AI_OPTION_PREFIX . 'service_account_key', '' );
		}

		// Re-encode to ensure clean JSON with proper escaping (preserves \n in private_key).
		$clean_json = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );

		// Encrypt before storing in the database.
		return Catalog_AI_API_Client::encrypt_option( $clean_json );
	}

	/**
	 * Render the main dashboard page.
	 */
	public function render_dashboard(): void {
		include CATALOG_AI_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings(): void {
		include CATALOG_AI_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/**
	 * Enqueue admin assets.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'catalog-ai' ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'catalog-ai-admin',
			CATALOG_AI_PLUGIN_URL . 'admin/css/admin.css',
			[],
			CATALOG_AI_VERSION
		);

		wp_enqueue_script(
			'catalog-ai-admin',
			CATALOG_AI_PLUGIN_URL . 'admin/js/admin.js',
			[ 'jquery', 'wp-api-fetch' ],
			CATALOG_AI_VERSION,
			true
		);

		wp_localize_script( 'catalog-ai-admin', 'catalogAi', [
			'restUrl'      => rest_url( CATALOG_AI_REST_NAMESPACE ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'costPerImage' => Catalog_AI_API_Client::COST_PER_IMAGE,
			'i18n'         => [
				'generating' => __( 'Generating...', 'catalog-ai' ),
				'queued'     => __( 'Queued', 'catalog-ai' ),
				'completed'  => __( 'Completed', 'catalog-ai' ),
				'failed'     => __( 'Failed', 'catalog-ai' ),
			],
		] );
	}
}
