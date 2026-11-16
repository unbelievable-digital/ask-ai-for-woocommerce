<?php
/**
 * Remove all plugin data on uninstall.
 *
 * @package Ask_AI_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'aipc_settings' );

foreach ( array( '_aipc_prompt', '_aipc_heading', '_aipc_subheading', '_aipc_disabled' ) as $aipc_key ) {
	delete_post_meta_by_key( $aipc_key );
}

foreach ( array( 'aipc_prompt', 'aipc_heading', 'aipc_subheading' ) as $aipc_key ) {
	delete_metadata( 'term', 0, $aipc_key, '', true );
}
