<?php
/**
 * Action Scheduler Queue Manager.
 *
 * Handles bulk image generation by offloading tasks to background jobs.
 * Each product/image pair is queued as an individual action so that
 * failures are isolated and retryable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Catalog_AI_Queue {

	private static ?self $instance = null;

	/** Action hook names. */
	const ACTION_GENERATE   = 'catalog_ai_generate_image';
	const ACTION_BATCH_INIT = 'catalog_ai_batch_init';

	/** Job statuses. */
	const STATUS_PENDING    = 'pending';
	const STATUS_PROCESSING = 'processing';
	const STATUS_COMPLETED  = 'completed';
	const STATUS_FAILED     = 'failed';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Register Action Scheduler callbacks.
		add_action( self::ACTION_GENERATE, [ $this, 'process_single_generation' ], 10, 1 );
		add_action( self::ACTION_BATCH_INIT, [ $this, 'process_batch_init' ], 10, 1 );
	}

	/**
	 * Enqueue a single image generation job.
	 *
	 * @param array $job_data {
	 *     @type int    $product_id   WooCommerce product ID.
	 *     @type string $mode         Generation mode (try_on|recontext).
	 *     @type string $scene_prompt Scene description (for recontext mode).
	 *     @type int    $person_image_id Attachment ID of person image (for try_on mode).
	 *     @type string $target       Where to assign: 'thumbnail' or 'gallery'.
	 * }
	 * @return int|WP_Error Action ID or error.
	 */
	public function enqueue( array $job_data ): int|\WP_Error {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new \WP_Error( 'catalog_ai_no_scheduler', __( 'Action Scheduler is not available.', 'catalog-ai' ) );
		}

		// Generate a unique job ID for tracking.
		$job_id = wp_generate_uuid4();
		$job_data['job_id']     = $job_id;
		$job_data['created_at'] = current_time( 'mysql', true );

		// Store job metadata.
		$this->save_job_meta( $job_id, $job_data, self::STATUS_PENDING );

		// Schedule the async action.
		$action_id = as_enqueue_async_action(
			self::ACTION_GENERATE,
			[ $job_id ],
			CATALOG_AI_QUEUE_GROUP
		);

		if ( 0 === $action_id ) {
			return new \WP_Error( 'catalog_ai_queue_failed', __( 'Failed to enqueue generation job.', 'catalog-ai' ) );
		}

		do_action( 'catalog_ai_job_enqueued', $job_id, $job_data );

		return $action_id;
	}

	/**
	 * Enqueue a bulk batch of products.
	 *
	 * @param int[]  $product_ids Array of WooCommerce product IDs.
	 * @param string $mode        Generation mode.
	 * @param array  $options     Shared options for all jobs in the batch.
	 * @return string Batch ID.
	 */
	public function enqueue_batch( array $product_ids, string $mode, array $options = [] ): string {
		$batch_id = wp_generate_uuid4();

		$batch_data = [
			'batch_id'    => $batch_id,
			'product_ids' => $product_ids,
			'mode'        => $mode,
			'options'     => $options,
			'total'       => count( $product_ids ),
			'completed'   => 0,
			'failed'      => 0,
			'created_at'  => current_time( 'mysql', true ),
		];

		update_option( CATALOG_AI_OPTION_PREFIX . 'batch_' . $batch_id, $batch_data, false );

		// Schedule the batch initializer (fans out individual jobs).
		as_enqueue_async_action(
			self::ACTION_BATCH_INIT,
			[ $batch_id ],
			CATALOG_AI_QUEUE_GROUP
		);

		return $batch_id;
	}

	/**
	 * Process batch initialization — creates individual jobs for each product.
	 *
	 * @param string $batch_id Batch UUID.
	 */
	public function process_batch_init( string $batch_id ): void {
		$batch = get_option( CATALOG_AI_OPTION_PREFIX . 'batch_' . $batch_id );
		if ( ! $batch ) {
			return;
		}

		foreach ( $batch['product_ids'] as $product_id ) {
			$job_data = array_merge( $batch['options'], [
				'product_id' => $product_id,
				'mode'       => $batch['mode'],
				'batch_id'   => $batch_id,
			] );

			$this->enqueue( $job_data );
		}
	}

	/**
	 * Process a single image generation job.
	 *
	 * This is the Action Scheduler callback. It:
	 *  1. Reads product image data.
	 *  2. Sends the request to Vertex AI.
	 *  3. Passes the result to the Media handler.
	 *
	 * @param string $job_id Job UUID.
	 */
	public function process_single_generation( string $job_id ): void {
		self::log( "Job {$job_id}: starting" );

		$job = $this->get_job_meta( $job_id );
		if ( ! $job ) {
			self::log( "Job {$job_id}: metadata not found, aborting" );
			return;
		}

		self::log( "Job {$job_id}: product_id={$job['product_id']}, mode={$job['mode']}" );
		$this->update_job_status( $job_id, self::STATUS_PROCESSING );

		$client = Catalog_AI_API_Client::instance();
		$mode   = $job['mode'] ?? Catalog_AI_API_Client::MODE_RECONTEXT;
		$model  = $client->get_model_for_mode( $mode );

		self::log( "Job {$job_id}: using model {$model}" );

		// Build payload based on mode.
		$payload = $this->build_payload_for_job( $job, $client );
		if ( is_wp_error( $payload ) ) {
			self::log( "Job {$job_id}: payload error — " . $payload->get_error_message() );
			$this->fail_job( $job_id, $payload->get_error_message(), $job );
			return;
		}

		self::log( "Job {$job_id}: payload built, calling Vertex AI..." );

		// Call Vertex AI.
		$response = $client->predict( $model, $payload );
		if ( is_wp_error( $response ) ) {
			self::log( "Job {$job_id}: API error — " . $response->get_error_message() );
			$this->fail_job( $job_id, $response->get_error_message(), $job );
			return;
		}

		self::log( "Job {$job_id}: API response received" );

		// Extract generated image from response.
		$image_data = $this->extract_image_from_response( $response );
		if ( is_wp_error( $image_data ) ) {
			self::log( "Job {$job_id}: extract error — " . $image_data->get_error_message() );
			$this->fail_job( $job_id, $image_data->get_error_message(), $job );
			return;
		}

		$image_size = strlen( $image_data['bytes'] ?? '' );
		self::log( "Job {$job_id}: image extracted, {$image_size} bytes, mime={$image_data['mime_type']}" );

		// Write to media library and assign to product.
		$attachment_id = Catalog_AI_Media::instance()->ingest_image(
			$image_data['bytes'],
			$image_data['mime_type'],
			$job['product_id'],
			$job['target'] ?? 'gallery'
		);

		if ( is_wp_error( $attachment_id ) ) {
			self::log( "Job {$job_id}: media error — " . $attachment_id->get_error_message() );
			$this->fail_job( $job_id, $attachment_id->get_error_message(), $job );
			return;
		}

		self::log( "Job {$job_id}: completed, attachment_id={$attachment_id}" );

		// Mark job complete.
		$this->update_job_status( $job_id, self::STATUS_COMPLETED, [
			'attachment_id' => $attachment_id,
			'completed_at'  => current_time( 'mysql', true ),
		] );

		// Record usage for cost tracking.
		Catalog_AI_API_Client::record_usage( $mode );

		// Update batch counter if part of a batch.
		if ( ! empty( $job['batch_id'] ) ) {
			$this->increment_batch_counter( $job['batch_id'], 'completed' );
		}

		do_action( 'catalog_ai_job_completed', $job_id, $attachment_id, $job );
	}

	/**
	 * Build the API payload from job data.
	 */
	private function build_payload_for_job( array $job, Catalog_AI_API_Client $client ): array|\WP_Error {
		$product_id = $job['product_id'];
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return new \WP_Error( 'catalog_ai_no_product', __( 'Product not found.', 'catalog-ai' ) );
		}

		$image_id = $product->get_image_id();
		if ( ! $image_id ) {
			return new \WP_Error( 'catalog_ai_no_image', __( 'Product has no image.', 'catalog-ai' ) );
		}

		$image_path = get_attached_file( $image_id );
		if ( ! $image_path || ! file_exists( $image_path ) ) {
			return new \WP_Error( 'catalog_ai_image_missing', __( 'Product image file not found.', 'catalog-ai' ) );
		}

		$product_image_b64 = base64_encode( file_get_contents( $image_path ) );
		$mode = $job['mode'] ?? Catalog_AI_API_Client::MODE_RECONTEXT;

		if ( Catalog_AI_API_Client::MODE_TRY_ON === $mode ) {
			// Virtual try-on requires a person image.
			$person_image_id = $job['person_image_id'] ?? 0;
			if ( ! $person_image_id ) {
				return new \WP_Error( 'catalog_ai_no_person', __( 'Person image is required for try-on mode.', 'catalog-ai' ) );
			}

			$person_path = get_attached_file( $person_image_id );
			if ( ! $person_path || ! file_exists( $person_path ) ) {
				return new \WP_Error( 'catalog_ai_person_missing', __( 'Person image file not found.', 'catalog-ai' ) );
			}

			$person_b64 = base64_encode( file_get_contents( $person_path ) );
			return $client->build_try_on_payload( $person_b64, $product_image_b64, $job['params'] ?? [] );
		}

		$scene_prompt = $job['scene_prompt'] ?? __( 'Professional product photography on a clean white background', 'catalog-ai' );

		// Background Swap mode (Imagen 3).
		if ( Catalog_AI_API_Client::MODE_BGSWAP === $mode ) {
			return $client->build_bgswap_payload( $product_image_b64, $scene_prompt, $job['params'] ?? [] );
		}

		// Recontextualization mode (preview — requires access).
		return $client->build_recontext_payload( $product_image_b64, $scene_prompt, $job['params'] ?? [] );
	}

	/**
	 * Extract image bytes from Vertex AI response.
	 */
	private function extract_image_from_response( array $response ): array|\WP_Error {
		// Standard prediction response format.
		$predictions = $response['predictions'] ?? [];
		if ( empty( $predictions ) ) {
			return new \WP_Error( 'catalog_ai_no_predictions', __( 'No predictions returned from Vertex AI.', 'catalog-ai' ) );
		}

		$prediction = $predictions[0];

		// Image may be base64 encoded directly.
		if ( ! empty( $prediction['bytesBase64Encoded'] ) ) {
			return [
				'bytes'     => base64_decode( $prediction['bytesBase64Encoded'] ),
				'mime_type' => $prediction['mimeType'] ?? 'image/png',
			];
		}

		// Or it may be a GCS URI.
		if ( ! empty( $prediction['gcsUri'] ) ) {
			return [
				'gcs_uri'   => $prediction['gcsUri'],
				'mime_type'  => $prediction['mimeType'] ?? 'image/png',
				'bytes'      => null, // Will be fetched by Media handler.
			];
		}

		return new \WP_Error( 'catalog_ai_bad_response', __( 'Unexpected response format from Vertex AI.', 'catalog-ai' ) );
	}

	/**
	 * Mark a job as failed.
	 */
	private function fail_job( string $job_id, string $reason, array $job ): void {
		$this->update_job_status( $job_id, self::STATUS_FAILED, [
			'error'      => $reason,
			'failed_at'  => current_time( 'mysql', true ),
		] );

		if ( ! empty( $job['batch_id'] ) ) {
			$this->increment_batch_counter( $job['batch_id'], 'failed' );
		}

		do_action( 'catalog_ai_job_failed', $job_id, $reason, $job );
	}

	/**
	 * Increment a batch counter (completed or failed).
	 */
	private function increment_batch_counter( string $batch_id, string $field ): void {
		$batch = get_option( CATALOG_AI_OPTION_PREFIX . 'batch_' . $batch_id );
		if ( $batch ) {
			$batch[ $field ] = ( $batch[ $field ] ?? 0 ) + 1;
			update_option( CATALOG_AI_OPTION_PREFIX . 'batch_' . $batch_id, $batch, false );
		}
	}

	// --- Job Meta Storage (uses WP options for simplicity; swap to custom table for scale) ---

	private function save_job_meta( string $job_id, array $data, string $status ): void {
		$data['status'] = $status;
		update_option( CATALOG_AI_OPTION_PREFIX . 'job_' . $job_id, $data, false );
	}

	public function get_job_meta( string $job_id ): ?array {
		$data = get_option( CATALOG_AI_OPTION_PREFIX . 'job_' . $job_id, null );
		return is_array( $data ) ? $data : null;
	}

	public function update_job_status( string $job_id, string $status, array $extra = [] ): void {
		$data = $this->get_job_meta( $job_id );
		if ( $data ) {
			$data['status'] = $status;
			$data = array_merge( $data, $extra );
			update_option( CATALOG_AI_OPTION_PREFIX . 'job_' . $job_id, $data, false );
		}
	}

	/**
	 * Log a message with the Catalog AI prefix.
	 */
	public static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Catalog AI] ' . $message );
		}
	}
}
