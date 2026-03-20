<?php
/**
 * Vertex AI API Client.
 *
 * Wraps all HTTP communication with Google Cloud Vertex AI through
 * the WP AI Client SDK pattern — using wp_remote_* under the hood
 * with credentials stored securely in the database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Catalog_AI_API_Client {

	private static ?self $instance = null;

	/** Vertex AI model endpoints. */
	const MODEL_VIRTUAL_TRY_ON    = 'virtual-try-on-001';
	const MODEL_PRODUCT_RECONTEXT = 'imagen-product-recontext-preview-06-30';
	const MODEL_BGSWAP            = 'imagen-3.0-capability-001';

	/** Supported generation modes. */
	const MODE_TRY_ON    = 'try_on';
	const MODE_RECONTEXT = 'recontext';
	const MODE_BGSWAP    = 'bgswap';

	/** Estimated cost per image (USD). */
	const COST_PER_IMAGE = [
		self::MODE_TRY_ON    => 0.05,
		self::MODE_RECONTEXT => 0.06,
		self::MODE_BGSWAP    => 0.04,
	];

	private string $project_id;
	private string $location;
	private string $base_url;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->project_id = get_option( CATALOG_AI_OPTION_PREFIX . 'gcp_project_id', '' );
		$this->location   = get_option( CATALOG_AI_OPTION_PREFIX . 'gcp_location', 'us-central1' );
		$this->base_url   = sprintf(
			'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models',
			$this->location,
			$this->project_id,
			$this->location
		);
	}

	/**
	 * Retrieve a short-lived OAuth2 access token.
	 *
	 * Uses the service account JSON key stored in the database.
	 * In production, consider using Workload Identity Federation instead.
	 *
	 * @return string|WP_Error Access token or error.
	 */
	private function get_access_token(): string|\WP_Error {
		$cached = get_transient( CATALOG_AI_OPTION_PREFIX . 'access_token' );
		if ( $cached ) {
			return $cached;
		}

		$service_account_json = self::decrypt_option( 'service_account_key' );
		if ( empty( $service_account_json ) ) {
			return new \WP_Error( 'catalog_ai_no_credentials', __( 'Google Cloud service account key is not configured.', 'catalog-ai' ) );
		}

		$sa = json_decode( $service_account_json, true );
		if ( ! $sa || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			return new \WP_Error( 'catalog_ai_invalid_credentials', __( 'Service account key is malformed.', 'catalog-ai' ) );
		}

		// Build JWT for token exchange.
		$now    = time();
		$header = base64_encode( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
		$claims = base64_encode( wp_json_encode( [
			'iss'   => $sa['client_email'],
			'scope' => 'https://www.googleapis.com/auth/cloud-platform',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
		] ) );

		$signature_input = $header . '.' . $claims;

		// json_decode already converts \n to real newlines, so the PEM should be ready.
		$pem = $sa['private_key'];

		$private_key = openssl_pkey_get_private( $pem );
		if ( ! $private_key ) {
			Catalog_AI_Queue::log( 'get_access_token: openssl error — ' . openssl_error_string() );
			return new \WP_Error( 'catalog_ai_key_error', __( 'Unable to parse private key.', 'catalog-ai' ) );
		}

		openssl_sign( $signature_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
		$jwt = $signature_input . '.' . base64_encode( $signature );

		// Exchange JWT for access token.
		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
			'body' => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new \WP_Error(
				'catalog_ai_token_error',
				$body['error_description'] ?? __( 'Failed to obtain access token.', 'catalog-ai' )
			);
		}

		// Cache token for 50 minutes (tokens last 60 min).
		set_transient( CATALOG_AI_OPTION_PREFIX . 'access_token', $body['access_token'], 3000 );

		return $body['access_token'];
	}

	/**
	 * Send a prediction request to Vertex AI.
	 *
	 * @param string $model    Model ID.
	 * @param array  $payload  Request payload (instances + parameters).
	 * @return array|WP_Error  Decoded response body or error.
	 */
	public function predict( string $model, array $payload ): array|\WP_Error {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			Catalog_AI_Queue::log( 'predict: token error — ' . $token->get_error_message() );
			return $token;
		}

		$url = sprintf( '%s/%s:predict', $this->base_url, $model );
		// Log payload structure (keys only, not image data).
		$debug_payload = $payload;
		array_walk_recursive( $debug_payload, function ( &$val, $key ) {
			if ( $key === 'bytesBase64Encoded' ) {
				$val = '[BASE64:' . strlen( $val ) . ' chars]';
			}
		} );
		Catalog_AI_Queue::log( "predict: POST {$url}" );
		Catalog_AI_Queue::log( "predict: payload structure — " . wp_json_encode( $debug_payload ) );

		$response = wp_remote_post( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 120,
		] );

		if ( is_wp_error( $response ) ) {
			Catalog_AI_Queue::log( 'predict: HTTP error — ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		Catalog_AI_Queue::log( "predict: HTTP {$code} received" );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'catalog_ai_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message */
					__( 'Vertex AI returned HTTP %1$d: %2$s', 'catalog-ai' ),
					$code,
					$body['error']['message'] ?? 'Unknown error'
				),
				[ 'status' => $code, 'body' => $body ]
			);
		}

		return $body;
	}

	/**
	 * Build the payload for a virtual try-on request.
	 *
	 * Confirmed payload structure from Vertex AI docs:
	 * - personImage.image.bytesBase64Encoded
	 * - productImages[].image.bytesBase64Encoded
	 *
	 * @param string $person_image_b64  Base64-encoded person image.
	 * @param string $garment_image_b64 Base64-encoded garment/product image.
	 * @param array  $params            Optional parameters.
	 * @return array Vertex AI request payload.
	 */
	public function build_try_on_payload( string $person_image_b64, string $garment_image_b64, array $params = [] ): array {
		return [
			'instances' => [
				[
					'personImage'   => [
						'image' => [ 'bytesBase64Encoded' => $person_image_b64 ],
					],
					'productImages' => [
						[
							'image' => [ 'bytesBase64Encoded' => $garment_image_b64 ],
						],
					],
				],
			],
			'parameters' => array_merge( [
				'sampleCount'      => 1,
				'personGeneration' => 'allow_all',
			], $params ),
		];
	}

	/**
	 * Build the payload for a product recontextualization request.
	 *
	 * @param string $product_image_b64 Base64-encoded product image.
	 * @param string $scene_prompt      Text describing the desired scene.
	 * @param array  $params            Optional parameters.
	 * @return array Vertex AI request payload.
	 */
	public function build_recontext_payload( string $product_image_b64, string $scene_prompt, array $params = [] ): array {
		return [
			'instances' => [
				[
					'prompt'        => $scene_prompt,
					'productImages' => [
						[
							'image' => [ 'bytesBase64Encoded' => $product_image_b64 ],
						],
					],
				],
			],
			'parameters' => array_merge( [
				'sampleCount'      => 1,
				'personGeneration' => 'allow_all',
			], $params ),
		];
	}

	/**
	 * Build the payload for a background swap request (Imagen 3).
	 *
	 * @param string $product_image_b64 Base64-encoded product image.
	 * @param string $scene_prompt      Text describing the desired background.
	 * @param array  $params            Optional parameters.
	 * @return array Vertex AI request payload.
	 */
	public function build_bgswap_payload( string $product_image_b64, string $scene_prompt, array $params = [] ): array {
		return [
			'instances' => [
				[
					'prompt'          => $scene_prompt,
					'referenceImages' => [
						[
							'referenceType'  => 'REFERENCE_TYPE_RAW',
							'referenceId'    => 1,
							'referenceImage' => [
								'bytesBase64Encoded' => $product_image_b64,
							],
						],
						[
							'referenceType'   => 'REFERENCE_TYPE_MASK',
							'referenceId'     => 2,
							'maskImageConfig' => [
								'maskMode' => 'MASK_MODE_BACKGROUND',
							],
						],
					],
				],
			],
			'parameters' => array_merge( [
				'editMode'         => 'EDIT_MODE_BGSWAP',
				'sampleCount'      => 1,
				'personGeneration' => 'allow_all',
			], $params ),
		];
	}

	/**
	 * Get the model ID for a given generation mode.
	 */
	public function get_model_for_mode( string $mode ): string {
		return match ( $mode ) {
			self::MODE_TRY_ON    => self::MODEL_VIRTUAL_TRY_ON,
			self::MODE_RECONTEXT => self::MODEL_PRODUCT_RECONTEXT,
			self::MODE_BGSWAP    => self::MODEL_BGSWAP,
			default              => self::MODEL_BGSWAP,
		};
	}

	/**
	 * Check if credentials are configured.
	 */
	public function is_configured(): bool {
		return ! empty( $this->project_id ) && ! empty( get_option( CATALOG_AI_OPTION_PREFIX . 'service_account_key', '' ) );
	}

	/**
	 * Encrypt a value before storing in wp_options.
	 *
	 * Uses AES-256-CBC with a key derived from AUTH_KEY (wp-config.php).
	 * Falls back to plain text if OpenSSL is unavailable.
	 */
	public static function encrypt_option( string $plain_text ): string {
		if ( ! function_exists( 'openssl_encrypt' ) || ! defined( 'AUTH_KEY' ) ) {
			return $plain_text;
		}

		$key = hash( 'sha256', AUTH_KEY . 'catalog_ai_encryption', true );
		$iv  = openssl_random_pseudo_bytes( 16 );

		$encrypted = openssl_encrypt( $plain_text, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return $plain_text;
		}

		return 'enc:' . base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a value stored in wp_options.
	 *
	 * @param string $option_key The option key (without prefix).
	 * @return string Decrypted value or empty string.
	 */
	public static function decrypt_option( string $option_key ): string {
		$value = get_option( CATALOG_AI_OPTION_PREFIX . $option_key, '' );
		if ( empty( $value ) ) {
			return '';
		}

		// Not encrypted (legacy or fallback) — return as-is.
		if ( ! str_starts_with( $value, 'enc:' ) ) {
			return $value;
		}

		if ( ! function_exists( 'openssl_decrypt' ) || ! defined( 'AUTH_KEY' ) ) {
			return '';
		}

		$key  = hash( 'sha256', AUTH_KEY . 'catalog_ai_encryption', true );
		$data = base64_decode( substr( $value, 4 ) );
		if ( strlen( $data ) < 17 ) {
			return '';
		}

		$iv        = substr( $data, 0, 16 );
		$encrypted = substr( $data, 16 );

		$decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Get the estimated cost for generating images.
	 *
	 * @param string $mode         Generation mode.
	 * @param int    $product_count Number of products.
	 * @param int    $sample_count  Images per product.
	 * @return float Estimated cost in USD.
	 */
	public static function estimate_cost( string $mode, int $product_count, int $sample_count = 1 ): float {
		$per_image = self::COST_PER_IMAGE[ $mode ] ?? 0.06;
		return $per_image * $product_count * $sample_count;
	}

	/**
	 * Record a completed generation for usage tracking.
	 *
	 * @param string $mode Generation mode used.
	 * @param int    $image_count Number of images generated.
	 */
	public static function record_usage( string $mode, int $image_count = 1 ): void {
		$usage = get_option( CATALOG_AI_OPTION_PREFIX . 'usage_stats', [] );
		$month = gmdate( 'Y-m' );

		if ( ! isset( $usage[ $month ] ) ) {
			$usage[ $month ] = [
				self::MODE_TRY_ON    => [ 'count' => 0, 'cost' => 0.0 ],
				self::MODE_RECONTEXT => [ 'count' => 0, 'cost' => 0.0 ],
				self::MODE_BGSWAP    => [ 'count' => 0, 'cost' => 0.0 ],
			];
		}

		$per_image = self::COST_PER_IMAGE[ $mode ] ?? 0.06;
		$usage[ $month ][ $mode ]['count'] += $image_count;
		$usage[ $month ][ $mode ]['cost']  += $per_image * $image_count;

		// Keep only the last 12 months.
		$months = array_keys( $usage );
		sort( $months );
		while ( count( $months ) > 12 ) {
			unset( $usage[ array_shift( $months ) ] );
		}

		update_option( CATALOG_AI_OPTION_PREFIX . 'usage_stats', $usage, false );
	}

	/**
	 * Get usage statistics.
	 *
	 * @param string|null $month Specific month (Y-m) or null for all.
	 * @return array Usage stats.
	 */
	public static function get_usage_stats( ?string $month = null ): array {
		$usage = get_option( CATALOG_AI_OPTION_PREFIX . 'usage_stats', [] );

		if ( $month ) {
			return $usage[ $month ] ?? [
				self::MODE_TRY_ON    => [ 'count' => 0, 'cost' => 0.0 ],
				self::MODE_RECONTEXT => [ 'count' => 0, 'cost' => 0.0 ],
				self::MODE_BGSWAP    => [ 'count' => 0, 'cost' => 0.0 ],
			];
		}

		return $usage;
	}
}
