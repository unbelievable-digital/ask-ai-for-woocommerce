<?php
/**
 * Plugin Name:       Ask AI for WooCommerce – ChatGPT, Claude & Perplexity Product Buttons
 * Description:       "Is this product right for you?" buttons that open ChatGPT, Claude, Google AI Mode, Grok or Perplexity with a ready-made prompt about the product. Prompts come from templates with product placeholders, with per-category and per-product overrides.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Unbelievable Digital
 * Text Domain:       ask-ai-for-woocommerce
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 7.0
 * WC tested up to:   11.3
 */

defined( 'ABSPATH' ) || exit;

define( 'AIPC_VERSION', '1.0.0' );
define( 'AIPC_FILE', __FILE__ );
define( 'AIPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIPC_URL', plugin_dir_url( __FILE__ ) );

require_once AIPC_DIR . 'includes/class-aipc-settings.php';
require_once AIPC_DIR . 'includes/class-aipc-providers.php';
require_once AIPC_DIR . 'includes/class-aipc-prompt.php';
require_once AIPC_DIR . 'includes/class-aipc-frontend.php';
require_once AIPC_DIR . 'includes/class-aipc-admin.php';
require_once AIPC_DIR . 'includes/class-aipc-product-admin.php';

add_action(
	'plugins_loaded',
	static function () {
		AIPC_Frontend::init();
		add_action( 'init', array( 'AIPC_Product_Admin', 'register_meta' ) );

		if ( is_admin() ) {
			AIPC_Admin::init();
			AIPC_Product_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once AIPC_DIR . 'includes/class-aipc-cli.php';
			WP_CLI::add_command( 'aipc', 'AIPC_CLI' );
		}
	}
);

// The plugin never touches orders, so it is compatible with WooCommerce HPOS.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', AIPC_FILE, true );
		}
	}
);

// No defaults are written on activation: untouched settings fall back to
// AIPC_Settings::defaults() at runtime, so they always follow the current site language.

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( AIPC_Admin::page_url() ), esc_html__( 'Settings', 'ask-ai-for-woocommerce' ) )
		);
		return $links;
	}
);
