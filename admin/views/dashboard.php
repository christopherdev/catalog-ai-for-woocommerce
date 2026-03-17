<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$client     = Catalog_AI_API_Client::instance();
$configured = $client->is_configured();
$products   = wc_get_products( [ 'limit' => 100, 'status' => 'publish' ] );
?>
<div class="wrap catalog-ai-dashboard">
	<h1><?php esc_html_e( 'Catalog AI for WooCommerce', 'catalog-ai' ); ?></h1>

	<?php if ( ! $configured ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL */
					esc_html__( 'Google Cloud credentials are not configured. %s to set up your Vertex AI connection.', 'catalog-ai' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=catalog-ai-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'catalog-ai' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="catalog-ai-layout">

		<!-- LEFT COLUMN: Generation Form -->
		<div class="catalog-ai-col-left">
			<div class="card catalog-ai-card">
				<h2><?php esc_html_e( 'Generate Product Images', 'catalog-ai' ); ?></h2>

				<form id="catalog-ai-generate-form">
					<table class="form-table">
						<tr>
							<th><label for="catalog-ai-mode"><?php esc_html_e( 'Mode', 'catalog-ai' ); ?></label></th>
							<td>
								<select id="catalog-ai-mode" name="mode">
									<option value="try_on"><?php esc_html_e( 'Virtual Try-On (Apparel)', 'catalog-ai' ); ?></option>
									<option value="bgswap"><?php esc_html_e( 'Background Swap (Imagen 3)', 'catalog-ai' ); ?></option>
									<option value="recontext" disabled><?php esc_html_e( 'Product Recontextualization (Currently Unavailable)', 'catalog-ai' ); ?></option>
								</select>
							</td>
						</tr>
						<tr id="catalog-ai-scene-row">
							<th><label for="catalog-ai-scene"><?php esc_html_e( 'Scene Prompt', 'catalog-ai' ); ?></label></th>
							<td>
								<textarea id="catalog-ai-scene" name="scene_prompt" rows="3" class="large-text"
									placeholder="<?php esc_attr_e( 'e.g., Product on a modern kitchen counter with natural morning light', 'catalog-ai' ); ?>"></textarea>
							</td>
						</tr>
						<tr id="catalog-ai-person-row">
							<th><label><?php esc_html_e( 'Person / Model Image', 'catalog-ai' ); ?></label></th>
							<td>
								<div id="catalog-ai-person-preview" style="margin-bottom:10px;"></div>
								<input type="hidden" id="catalog-ai-person-image-id" name="person_image_id" value="" />
								<button type="button" class="button" id="catalog-ai-person-select">
									<?php esc_html_e( 'Select Image', 'catalog-ai' ); ?>
								</button>
								<button type="button" class="button" id="catalog-ai-person-remove" style="display:none;">
									<?php esc_html_e( 'Remove', 'catalog-ai' ); ?>
								</button>
								<p class="description"><?php esc_html_e( 'Select a person/model image from the Media Library. The garment will be placed on this person.', 'catalog-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="catalog-ai-target"><?php esc_html_e( 'Assign To', 'catalog-ai' ); ?></label></th>
							<td>
								<select id="catalog-ai-target" name="target">
									<option value="gallery"><?php esc_html_e( 'Product Gallery', 'catalog-ai' ); ?></option>
									<option value="thumbnail"><?php esc_html_e( 'Featured Image (Thumbnail)', 'catalog-ai' ); ?></option>
								</select>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary" id="catalog-ai-submit" <?php disabled( ! $configured ); ?>>
							<?php esc_html_e( 'Generate Images', 'catalog-ai' ); ?>
						</button>
						<span id="catalog-ai-status" class="description" style="margin-left:12px;"></span>
					</p>
				</form>
			</div>
		</div>

		<!-- CENTER COLUMN: Product Cards -->
		<div class="catalog-ai-col-center">
			<div class="card catalog-ai-card catalog-ai-products-card">
				<div class="catalog-ai-products-header">
					<h2><?php esc_html_e( 'Select Products', 'catalog-ai' ); ?></h2>
					<span class="catalog-ai-selected-count">
						<strong><span id="catalog-ai-selected-num">0</span></strong> <?php esc_html_e( 'selected', 'catalog-ai' ); ?>
						&nbsp;
						<button type="button" class="button button-small" id="catalog-ai-select-all"><?php esc_html_e( 'All', 'catalog-ai' ); ?></button>
						<button type="button" class="button button-small" id="catalog-ai-deselect-all"><?php esc_html_e( 'None', 'catalog-ai' ); ?></button>
					</span>
				</div>
				<input type="hidden" id="catalog-ai-products" name="product_ids" value="" />

				<div class="catalog-ai-product-grid">
					<?php if ( ! empty( $products ) ) : ?>
						<?php foreach ( $products as $product ) :
							$image_id  = $product->get_image_id();
							$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );
							$sku       = $product->get_sku() ?: '&mdash;';
							$price     = $product->get_price_html();
						?>
							<div class="catalog-ai-product-card" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
								<div class="catalog-ai-product-card-check">
									<span class="dashicons dashicons-yes-alt"></span>
								</div>
								<div class="catalog-ai-product-card-image">
									<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" />
								</div>
								<div class="catalog-ai-product-card-info">
									<strong class="catalog-ai-product-card-name"><?php echo esc_html( $product->get_name() ); ?></strong>
									<span class="catalog-ai-product-card-sku">SKU: <?php echo esc_html( $sku ); ?></span>
									<span class="catalog-ai-product-card-price"><?php echo wp_kses_post( $price ); ?></span>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No published products found.', 'catalog-ai' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- RIGHT COLUMN: Cost, Usage, Jobs -->
		<div class="catalog-ai-col-right">

			<!-- Cost Estimation -->
			<div class="card catalog-ai-card">
				<h2><?php esc_html_e( 'Cost Estimate', 'catalog-ai' ); ?></h2>
				<div id="catalog-ai-cost-estimate">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Mode', 'catalog-ai' ); ?></th>
								<th><?php esc_html_e( 'Cost / Image', 'catalog-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><?php esc_html_e( 'Virtual Try-On', 'catalog-ai' ); ?></td>
								<td>$0.05</td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Background Swap', 'catalog-ai' ); ?></td>
								<td>$0.04</td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Recontextualization', 'catalog-ai' ); ?></td>
								<td>$0.06</td>
							</tr>
						</tbody>
					</table>
					<p class="description" id="catalog-ai-estimate-result" style="margin-top:10px; font-size:14px;"></p>
				</div>
			</div>

			<!-- Usage & Billing -->
			<div class="card catalog-ai-card">
				<h2><?php esc_html_e( 'Usage This Month', 'catalog-ai' ); ?></h2>
				<div id="catalog-ai-usage">
					<p class="description"><?php esc_html_e( 'Loading usage data...', 'catalog-ai' ); ?></p>
				</div>
			</div>

			<!-- Job Queue -->
			<div class="card catalog-ai-card">
				<h2><?php esc_html_e( 'Recent Jobs', 'catalog-ai' ); ?></h2>
				<div id="catalog-ai-jobs">
					<p class="description"><?php esc_html_e( 'Jobs will appear here after submission.', 'catalog-ai' ); ?></p>
				</div>
			</div>

		</div>
	</div>
</div>
