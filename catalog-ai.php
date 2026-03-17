<?php
/**
 * Plugin Name:       Catalog AI for WooCommerce
 * Plugin URI:        https://github.com/christopherdev/catalog-ai-for-woocommerce
 * Description:       AI-powered product catalog image generation for WooCommerce using Google Vertex AI Imagen models. Supports virtual try-on, product recontextualization, bulk processing via Action Scheduler, and MCP server integration.
 * Version:           1.0.0
 * Author:            Christopher Buray
 * Author URI:        https://github.com/christopherdev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       catalog-ai
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CATALOG_AI_VERSION', '1.0.0' );
define( 'CATALOG_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CATALOG_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CATALOG_AI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CATALOG_AI_OPTION_PREFIX', 'catalog_ai_' );
define( 'CATALOG_AI_REST_NAMESPACE', 'catalog-ai/v1' );
define( 'CATALOG_AI_QUEUE_GROUP', 'catalog-ai' );

/**
 * Autoloader for plugin classes.
 *
 * Maps class prefixes to directories:
 *   Catalog_AI_*  => includes/class-catalog-ai-*.php
 */
spl_autoload_register( function ( string $class_name ) {
	if ( 0 !== strpos( $class_name, 'Catalog_AI_' ) ) {
		return;
	}

	$file = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
	$path = CATALOG_AI_PLUGIN_DIR . 'includes/' . $file;

	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

/**
 * Bootstrap Action Scheduler.
 *
 * If Action Scheduler is not already loaded by WooCommerce or another plugin,
 * load the bundled version. In production, run:
 *   composer require woocommerce/action-scheduler
 * and point this to vendor/woocommerce/action-scheduler/action-scheduler.php.
 */
function catalog_ai_load_action_scheduler() {
	// Action Scheduler is bundled with WooCommerce. If WC is active, it's already loaded.
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		return;
	}

	$as_path = CATALOG_AI_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
	if ( file_exists( $as_path ) ) {
		require_once $as_path;
	}
}
add_action( 'plugins_loaded', 'catalog_ai_load_action_scheduler', 1 );

/**
 * Main plugin initialization — fires after all plugins are loaded.
 */
function catalog_ai_init() {
	// Dependency check: WooCommerce must be active.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Catalog AI requires WooCommerce to be installed and active.', 'catalog-ai' );
			echo '</p></div>';
		} );
		return;
	}

	// Core services.
	Catalog_AI_API_Client::instance();
	Catalog_AI_Queue::instance();
	Catalog_AI_Webhook::instance();
	Catalog_AI_Media::instance();

	// Admin.
	if ( is_admin() ) {
		Catalog_AI_Admin::instance();
	}

	// REST API & MCP.
	Catalog_AI_REST_Controller::instance();
	Catalog_AI_MCP_Server::instance();
}
add_action( 'plugins_loaded', 'catalog_ai_init', 20 );

/**
 * Plugin activation.
 */
function catalog_ai_activate() {
	// Generate a unique webhook secret on first activation.
	if ( ! get_option( CATALOG_AI_OPTION_PREFIX . 'webhook_secret' ) ) {
		update_option( CATALOG_AI_OPTION_PREFIX . 'webhook_secret', wp_generate_password( 40, false ) );
	}

	// Flush rewrite rules so REST endpoints register immediately.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'catalog_ai_activate' );

/**
 * Plugin deactivation.
 */
function catalog_ai_deactivate() {
	// Unschedule any pending actions.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'catalog_ai_generate_image' );
	}

	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'catalog_ai_deactivate' );

/**
 * Load plugin textdomain.
 */
function catalog_ai_load_textdomain() {
	load_plugin_textdomain( 'catalog-ai', false, dirname( CATALOG_AI_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'plugins_loaded', 'catalog_ai_load_textdomain' );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
