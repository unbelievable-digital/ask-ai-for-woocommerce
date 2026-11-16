<?php
/**
 * Plugin settings storage.
 *
 * @package Ask_AI_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class AIPC_Settings {

	const OPTION = 'aipc_settings';

	/** Product meta keys. */
	const META_PROMPT     = '_aipc_prompt';
	const META_HEADING    = '_aipc_heading';
	const META_SUBHEADING = '_aipc_subheading';
	const META_DISABLED   = '_aipc_disabled';

	/** Product category (term) meta keys. */
	const TERM_PROMPT     = 'aipc_prompt';
	const TERM_HEADING    = 'aipc_heading';
	const TERM_SUBHEADING = 'aipc_subheading';

	/** @var array|null */
	private static $cache = null;

	public static function default_template() {
		/* translators: Default AI prompt template. Translate the sentences, but keep every {placeholder} exactly as it is (e.g. {product_name}, {product_url}, {attributes}). Keep one "Label: {placeholder}" per line. */
		return __(
			"I'm looking at {product_name} on {site_name}: {product_url}
Help me decide whether this product is right for my needs. Explain what it is best suited for, its strengths and its limitations. If it does not fit my needs, suggest up to two better alternatives from {site_name}. Do not recommend products from other stores unless I explicitly ask.

Important: do not invent specifications, prices or availability. Use the information from the product page above. If you cannot open it or something is missing, say so clearly.

PRODUCT DETAILS
Product: {product_name}
SKU: {sku}
Brand: {brand}
Category: {category}
Price: {price}
{attributes}
Product page: {product_url}

Please answer in {language}.",
			'ask-ai-for-woocommerce'
		);
	}

	public static function defaults() {
		return array(
			'enabled'        => 1,
			'position'       => 'after_add_to_cart',
			'heading'        => __( 'Is this product right for you?', 'ask-ai-for-woocommerce' ),
			'subheading'     => __( 'Ask AI about the strengths and limitations of this model.', 'ask-ai-for-woocommerce' ),
			'template'       => self::default_template(),
			'display'        => 'icons',
			'new_tab'        => 1,
			'max_url_length' => 7000,
			'providers'      => array(),
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	public static function positions() {
		return array(
			'after_add_to_cart' => __( 'Product summary – after the Add to cart button', 'ask-ai-for-woocommerce' ),
			'summary_end'       => __( 'Product summary – at the end', 'ask-ai-for-woocommerce' ),
			'after_summary'     => __( 'Below the product summary (before tabs)', 'ask-ai-for-woocommerce' ),
			'tab'               => __( 'As a product tab', 'ask-ai-for-woocommerce' ),
			'shortcode'         => __( 'Manual only (shortcode / page builder)', 'ask-ai-for-woocommerce' ),
		);
	}

	private static function normalize( $text ) {
		return trim( str_replace( "\r\n", "\n", (string) $text ) );
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();

		$out['enabled']  = empty( $input['enabled'] ) ? 0 : 1;
		$out['new_tab']  = empty( $input['new_tab'] ) ? 0 : 1;
		$out['position'] = isset( $input['position'], self::positions()[ $input['position'] ] ) ? $input['position'] : $defaults['position'];
		$out['display']  = ( isset( $input['display'] ) && in_array( $input['display'], array( 'icons', 'icons_labels' ), true ) ) ? $input['display'] : 'icons';

		$out['heading']    = isset( $input['heading'] ) ? sanitize_text_field( $input['heading'] ) : '';
		$out['subheading'] = isset( $input['subheading'] ) ? sanitize_text_field( $input['subheading'] ) : '';
		$out['template']   = isset( $input['template'] ) ? sanitize_textarea_field( $input['template'] ) : '';
		if ( '' === trim( $out['template'] ) ) {
			unset( $out['template'] );
		}

		// Texts left unchanged are not stored, so they keep following the site language
		// (and pick up translations later). An empty heading/subheading is stored and hides it.
		foreach ( array( 'heading', 'subheading', 'template' ) as $key ) {
			if ( isset( $out[ $key ] ) && self::normalize( $out[ $key ] ) === self::normalize( $defaults[ $key ] ) ) {
				unset( $out[ $key ] );
			}
		}

		$max                   = isset( $input['max_url_length'] ) ? absint( $input['max_url_length'] ) : $defaults['max_url_length'];
		$out['max_url_length'] = min( 32000, max( 1000, $max ) );

		$out['providers'] = array();
		$raw_providers    = isset( $input['providers'] ) && is_array( $input['providers'] ) ? $input['providers'] : array();
		foreach ( AIPC_Providers::defaults() as $id => $provider ) {
			$raw = isset( $raw_providers[ $id ] ) && is_array( $raw_providers[ $id ] ) ? $raw_providers[ $id ] : array();
			$url = isset( $raw['url'] ) ? trim( (string) $raw['url'] ) : '';

			if ( '' !== $url && ! AIPC_Providers::is_valid_url_template( $url ) ) {
				add_settings_error(
					self::OPTION,
					'aipc_bad_url_' . $id,
					/* translators: %s: provider name */
					sprintf( __( 'The URL for %s must start with https:// and contain {prompt}. The default URL was restored.', 'ask-ai-for-woocommerce' ), $provider['label'] )
				);
				$url = '';
			}

			$out['providers'][ $id ] = array(
				'enabled' => empty( $raw['enabled'] ) ? 0 : 1,
				'label'   => isset( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : '',
				'url'     => $url,
				'order'   => isset( $raw['order'] ) ? (int) $raw['order'] : 0,
			);
		}

		self::flush_cache();
		return $out;
	}
}
