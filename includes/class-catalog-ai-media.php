<?php
/**
 * Media Library Manager.
 *
 * Handles ingesting AI-generated images into the WordPress Media Library
 * and assigning them to WooCommerce products as thumbnails or gallery images.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Catalog_AI_Media {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Ingest raw image bytes into the WordPress Media Library.
	 *
	 * @param string $image_bytes  Raw image binary data.
	 * @param string $mime_type    MIME type (image/png, image/jpeg, etc.).
	 * @param int    $product_id   WooCommerce product ID to associate with.
	 * @param string $target       'thumbnail' to set as featured image, 'gallery' to append to gallery.
	 * @return int|WP_Error        Attachment ID or error.
	 */
	public function ingest_image( string $image_bytes, string $mime_type, int $product_id, string $target = 'gallery' ): int|\WP_Error {
		if ( empty( $image_bytes ) ) {
			return new \WP_Error( 'catalog_ai_empty_image', __( 'Image data is empty.', 'catalog-ai' ) );
		}

		// Determine file extension from MIME type.
		$extensions = [
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
		];

		$ext = $extensions[ $mime_type ] ?? 'png';

		// Build a unique filename.
		$filename = sprintf( 'catalog-ai-%d-%s.%s', $product_id, wp_generate_uuid4(), $ext );

		// Write to the uploads directory.
		$upload = wp_upload_bits( $filename, null, $image_bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'catalog_ai_upload_failed', $upload['error'] );
		}

		// Prepare attachment data.
		$product_title = '';
		if ( $product_id ) {
			$product = wc_get_product( $product_id );
			$product_title = $product ? $product->get_name() : '';
		}

		$attachment_data = [
			'post_mime_type' => $mime_type,
			'post_title'     => sprintf(
				/* translators: %s: product title */
				__( 'AI Generated — %s', 'catalog-ai' ),
				$product_title ?: $filename
			),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'meta_input'     => [
				'_catalog_ai_generated' => true,
				'_catalog_ai_product'   => $product_id,
				'_catalog_ai_date'      => current_time( 'mysql', true ),
			],
		];

		// Insert into Media Library.
		$attachment_id = wp_insert_attachment( $attachment_data, $upload['file'], $product_id );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate all intermediate image sizes.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Assign to the WooCommerce product.
		if ( $product_id ) {
			$this->assign_to_product( $attachment_id, $product_id, $target );
		}

		do_action( 'catalog_ai_image_ingested', $attachment_id, $product_id, $target );

		return $attachment_id;
	}

	/**
	 * Assign an attachment to a WooCommerce product.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $product_id    Product ID.
	 * @param string $target        'thumbnail' or 'gallery'.
	 */
	public function assign_to_product( int $attachment_id, int $product_id, string $target = 'gallery' ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		if ( 'thumbnail' === $target ) {
			$product->set_image_id( $attachment_id );
			$product->save();
			return;
		}

		// Append to product gallery.
		$gallery_ids   = $product->get_gallery_image_ids();
		$gallery_ids[] = $attachment_id;
		$product->set_gallery_image_ids( array_unique( $gallery_ids ) );
		$product->save();
	}

	/**
	 * Get all AI-generated attachments for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return int[] Array of attachment IDs.
	 */
	public function get_generated_images( int $product_id ): array {
		$query = new \WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => '_catalog_ai_generated',
					'value' => '1',
				],
				[
					'key'   => '_catalog_ai_product',
					'value' => $product_id,
				],
			],
		] );

		return $query->posts;
	}
}
