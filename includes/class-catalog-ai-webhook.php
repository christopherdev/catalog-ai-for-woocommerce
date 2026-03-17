<?php
/**
 * Webhook Receiver.
 *
 * Registers a secure REST API endpoint that Google Cloud can call
 * asynchronously when image generation completes. This replaces
 * polling — the plugin simply waits for the push notification.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Catalog_AI_Webhook {

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

	/**
	 * Register the webhook endpoint.
	 *
	 * POST /wp-json/catalog-ai/v1/webhook/image-ready
	 */
	public function register_routes(): void {
		register_rest_route( CATALOG_AI_REST_NAMESPACE, '/webhook/image-ready', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_image_ready' ],
			'permission_callback' => [ $this, 'verify_webhook_signature' ],
		] );
	}

	/**
	 * Verify the incoming webhook is authentic.
	 *
	 * Checks for a shared secret in the X-Webhook-Secret header.
	 */
	public function verify_webhook_signature( \WP_REST_Request $request ): bool {
		$secret   = get_option( CATALOG_AI_OPTION_PREFIX . 'webhook_secret', '' );
		$provided = $request->get_header( 'X-Webhook-Secret' );

		if ( empty( $secret ) || empty( $provided ) ) {
			return false;
		}

		return hash_equals( $secret, $provided );
	}

	/**
	 * Handle the image-ready webhook payload.
	 *
	 * Expected payload:
	 * {
	 *   "job_id":  "uuid",
	 *   "status":  "success" | "error",
	 *   "image":   "base64-encoded-image" | null,
	 *   "gcs_uri": "gs://bucket/path" | null,
	 *   "mime_type": "image/png",
	 *   "error":   "error message" | null
	 * }
	 */
	public function handle_image_ready( \WP_REST_Request $request ): \WP_REST_Response {
		$job_id    = sanitize_text_field( $request->get_param( 'job_id' ) );
		$status    = sanitize_text_field( $request->get_param( 'status' ) );
		$image_b64 = $request->get_param( 'image' );
		$gcs_uri   = sanitize_url( $request->get_param( 'gcs_uri' ) ?? '' );
		$mime_type = sanitize_mime_type( $request->get_param( 'mime_type' ) ?? 'image/png' );
		$error_msg = sanitize_text_field( $request->get_param( 'error' ) ?? '' );

		if ( empty( $job_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Missing job_id' ], 400 );
		}

		$queue = Catalog_AI_Queue::instance();
		$job   = $queue->get_job_meta( $job_id );

		if ( ! $job ) {
			return new \WP_REST_Response( [ 'error' => 'Unknown job_id' ], 404 );
		}

		// Handle failure.
		if ( 'error' === $status ) {
			$queue->update_job_status( $job_id, Catalog_AI_Queue::STATUS_FAILED, [
				'error'     => $error_msg,
				'failed_at' => current_time( 'mysql', true ),
			] );

			do_action( 'catalog_ai_job_failed', $job_id, $error_msg, $job );
			return new \WP_REST_Response( [ 'received' => true ], 200 );
		}

		// Resolve image bytes.
		$image_bytes = null;

		if ( ! empty( $image_b64 ) ) {
			$image_bytes = base64_decode( $image_b64 );
		} elseif ( ! empty( $gcs_uri ) ) {
			$image_bytes = $this->fetch_from_gcs( $gcs_uri );
		}

		if ( empty( $image_bytes ) ) {
			$queue->update_job_status( $job_id, Catalog_AI_Queue::STATUS_FAILED, [
				'error'     => 'No image data in webhook payload.',
				'failed_at' => current_time( 'mysql', true ),
			] );
			return new \WP_REST_Response( [ 'error' => 'No image data' ], 422 );
		}

		// Ingest into Media Library.
		$attachment_id = Catalog_AI_Media::instance()->ingest_image(
			$image_bytes,
			$mime_type,
			$job['product_id'] ?? 0,
			$job['target'] ?? 'gallery'
		);

		if ( is_wp_error( $attachment_id ) ) {
			$queue->update_job_status( $job_id, Catalog_AI_Queue::STATUS_FAILED, [
				'error'     => $attachment_id->get_error_message(),
				'failed_at' => current_time( 'mysql', true ),
			] );
			return new \WP_REST_Response( [ 'error' => $attachment_id->get_error_message() ], 500 );
		}

		$queue->update_job_status( $job_id, Catalog_AI_Queue::STATUS_COMPLETED, [
			'attachment_id' => $attachment_id,
			'completed_at'  => current_time( 'mysql', true ),
		] );

		do_action( 'catalog_ai_job_completed', $job_id, $attachment_id, $job );

		return new \WP_REST_Response( [
			'received'      => true,
			'attachment_id'  => $attachment_id,
		], 200 );
	}

	/**
	 * Fetch image bytes from a Google Cloud Storage URI.
	 *
	 * Uses the authenticated Vertex AI client credentials.
	 *
	 * @param string $gcs_uri GCS URI (gs://bucket/object).
	 * @return string|null Raw image bytes or null on failure.
	 */
	private function fetch_from_gcs( string $gcs_uri ): ?string {
		// Validate the URI strictly: must start with gs:// and contain only safe characters.
		if ( ! preg_match( '#^gs://[a-z0-9][a-z0-9._-]{1,61}[a-z0-9]/[a-zA-Z0-9._/%-]+$#', $gcs_uri ) ) {
			Catalog_AI_Queue::log( 'fetch_from_gcs: rejected invalid GCS URI — ' . $gcs_uri );
			return null;
		}

		// Convert gs://bucket/object to https://storage.googleapis.com/bucket/object
		$uri = str_replace( 'gs://', 'https://storage.googleapis.com/', $gcs_uri );

		$token = get_transient( CATALOG_AI_OPTION_PREFIX . 'access_token' );
		$headers = [];
		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get( $uri, [
			'headers' => $headers,
			'timeout' => 60,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return wp_remote_retrieve_body( $response );
	}
}
