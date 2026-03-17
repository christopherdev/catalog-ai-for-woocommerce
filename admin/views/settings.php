<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Catalog AI Settings', 'catalog-ai' ); ?></h1>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'catalog-ai-settings' );
		do_settings_sections( 'catalog-ai-settings' );
		submit_button();
		?>
	</form>
</div>
