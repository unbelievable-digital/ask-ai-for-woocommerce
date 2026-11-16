<?php
/**
 * Prompt resolution and placeholder rendering.
 *
 * Resolution order for a product:
 *   1. Custom prompt saved on the product (may itself use placeholders)
 *   2. Template from the product's category (primary category first, inherited from parent categories)
 *   3. Global template from Settings
 *
 * @package Ask_AI_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class AIPC_Prompt {

	/** Per-request cache of placeholder values, keyed by post ID. */
	private static $values = array();

	/**
	 * Built-in placeholders and their descriptions (shown in the admin).
	 *
	 * @return array
	 */
	public static function placeholder_help() {
		return array(
			'{product_name}'      => __( 'Product name', 'ask-ai-for-woocommerce' ),
			'{product_url}'       => __( 'Product page URL', 'ask-ai-for-woocommerce' ),
			'{product_id}'        => __( 'Product ID', 'ask-ai-for-woocommerce' ),
			'{sku}'               => __( 'SKU', 'ask-ai-for-woocommerce' ),
			'{price}'             => __( 'Current price incl. currency (range for variable products)', 'ask-ai-for-woocommerce' ),
			'{regular_price}'     => __( 'Regular price (only when on sale)', 'ask-ai-for-woocommerce' ),
			'{stock_status}'      => __( 'Stock status', 'ask-ai-for-woocommerce' ),
			'{short_description}' => __( 'Short description (plain text, shortened)', 'ask-ai-for-woocommerce' ),
			'{description}'       => __( 'Description (plain text, shortened)', 'ask-ai-for-woocommerce' ),
			'{category}'          => __( 'Primary category', 'ask-ai-for-woocommerce' ),
			'{categories}'        => __( 'All categories', 'ask-ai-for-woocommerce' ),
			'{brand}'             => __( 'Brand', 'ask-ai-for-woocommerce' ),
			'{tags}'              => __( 'Tags', 'ask-ai-for-woocommerce' ),
			'{attributes}'        => __( 'All visible attributes, one "Name: value" per line', 'ask-ai-for-woocommerce' ),
			'{weight}'            => __( 'Weight', 'ask-ai-for-woocommerce' ),
			'{dimensions}'        => __( 'Dimensions', 'ask-ai-for-woocommerce' ),
			'{site_name}'         => __( 'Store name', 'ask-ai-for-woocommerce' ),
			'{site_url}'          => __( 'Store URL', 'ask-ai-for-woocommerce' ),
			'{language}'          => __( 'Store language, e.g. "Bulgarian"', 'ask-ai-for-woocommerce' ),
			'{attr:pa_cpu}'       => __( 'A single attribute by slug', 'ask-ai-for-woocommerce' ),
			'{meta:_my_field}'    => __( 'Any custom field / ACF field', 'ask-ai-for-woocommerce' ),
			'{tax:product_brand}' => __( 'Terms of any taxonomy', 'ask-ai-for-woocommerce' ),
		);
	}

	/**
	 * Find which template applies to a product and where it comes from.
	 *
	 * @param int $post_id Product ID.
	 * @return array { template: string, source: product|category|global, term: WP_Term|null }
	 */
	public static function resolve_template( $post_id ) {
		$custom = (string) get_post_meta( $post_id, AIPC_Settings::META_PROMPT, true );
		if ( '' !== trim( $custom ) ) {
			return array(
				'template' => $custom,
				'source'   => 'product',
				'term'     => null,
			);
		}

		$from_cat = self::category_value( $post_id, AIPC_Settings::TERM_PROMPT );
		if ( $from_cat ) {
			return array(
				'template' => $from_cat['value'],
				'source'   => 'category',
				'term'     => $from_cat['term'],
			);
		}

		return array(
			'template' => (string) AIPC_Settings::get( 'template' ),
			'source'   => 'global',
			'term'     => null,
		);
	}

	/**
	 * Heading / subheading with the same product > category > global fallback.
	 *
	 * @param int    $post_id Product ID.
	 * @param string $which   'heading' or 'subheading'.
	 * @return string
	 */
	public static function resolve_text( $post_id, $which ) {
		$meta_key = 'heading' === $which ? AIPC_Settings::META_HEADING : AIPC_Settings::META_SUBHEADING;
		$term_key = 'heading' === $which ? AIPC_Settings::TERM_HEADING : AIPC_Settings::TERM_SUBHEADING;

		$text = $post_id ? (string) get_post_meta( $post_id, $meta_key, true ) : '';
		if ( '' === trim( $text ) && $post_id ) {
			$from_cat = self::category_value( $post_id, $term_key );
			$text     = $from_cat ? $from_cat['value'] : '';
		}
		if ( '' === trim( $text ) ) {
			$text = (string) AIPC_Settings::get( $which );
		}

		return $post_id ? self::render( $text, $post_id ) : $text;
	}

	/**
	 * Final prompt for a product, ready to be URL-encoded.
	 *
	 * @param int $post_id Product ID.
	 * @return string
	 */
	public static function build( $post_id ) {
		$resolved = self::resolve_template( $post_id );
		$prompt   = self::render( $resolved['template'], $post_id );

		/** Filter the final prompt text. */
		return (string) apply_filters( 'aipc_prompt', $prompt, $post_id, $resolved );
	}

	/**
	 * Replace placeholders. Lines whose placeholders are all empty are removed,
	 * so "Brand: {brand}" disappears for products without a brand.
	 *
	 * @param string $template Template text.
	 * @param int    $post_id  Product ID.
	 * @return string
	 */
	public static function render( $template, $post_id ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $template );
		$out   = array();

		foreach ( $lines as $line ) {
			$had_placeholder = false;
			$had_value       = false;

			$rendered = preg_replace_callback(
				'/\{([a-z_]+)(?::([^{}\s]+))?\}/i',
				static function ( $m ) use ( $post_id, &$had_placeholder, &$had_value ) {
					$value = self::value( $post_id, strtolower( $m[1] ), isset( $m[2] ) ? $m[2] : '' );
					if ( null === $value ) {
						return $m[0]; // Unknown placeholder: leave untouched.
					}
					$had_placeholder = true;
					if ( '' !== $value ) {
						$had_value = true;
					}
					return $value;
				},
				$line
			);

			if ( $had_placeholder && ! $had_value ) {
				continue;
			}
			$out[] = rtrim( $rendered );
		}

		$text = implode( "\n", $out );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Value of a single placeholder, or null when the placeholder is unknown.
	 *
	 * @param int    $post_id Product ID.
	 * @param string $name    Placeholder name.
	 * @param string $arg     Optional argument after the colon.
	 * @return string|null
	 */
	public static function value( $post_id, $name, $arg = '' ) {
		switch ( $name ) {
			case 'attr':
				return self::attribute_value( $post_id, $arg );
			case 'meta':
				return self::meta_value( $post_id, $arg );
			case 'tax':
				return self::term_names( $post_id, $arg );
		}

		$values = self::values( $post_id );
		if ( array_key_exists( $name, $values ) ) {
			return (string) $values[ $name ];
		}

		/** Return a string from this filter to support your own placeholders, e.g. {warranty}. */
		$custom = apply_filters( 'aipc_placeholder_value', null, $name, $arg, $post_id );
		return null === $custom ? null : (string) $custom;
	}

	/**
	 * Simple placeholder values for a product (cached per request).
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	public static function values( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( self::$values[ $post_id ] ) ) {
			return self::$values[ $post_id ];
		}

		$v = array(
			'product_id'        => $post_id ? (string) $post_id : '',
			'product_name'      => $post_id ? self::plain( get_the_title( $post_id ) ) : '',
			'product_url'       => $post_id ? (string) get_permalink( $post_id ) : '',
			'sku'               => '',
			'price'             => '',
			'regular_price'     => '',
			'stock_status'      => '',
			'short_description' => $post_id ? self::shorten( get_the_excerpt( $post_id ), 400 ) : '',
			'description'       => $post_id ? self::shorten( get_post_field( 'post_content', $post_id ), 600 ) : '',
			'category'          => '',
			'categories'        => '',
			'brand'             => '',
			'tags'              => '',
			'attributes'        => '',
			'weight'            => '',
			'dimensions'        => '',
			'site_name'         => self::plain( get_bloginfo( 'name' ) ),
			'site_url'          => home_url( '/' ),
			'language'          => self::language_name(),
		);

		$product = ( $post_id && function_exists( 'wc_get_product' ) ) ? wc_get_product( $post_id ) : null;

		if ( $product ) {
			$v['product_name']      = self::plain( $product->get_name() );
			$v['sku']               = (string) $product->get_sku();
			$v['short_description'] = self::shorten( $product->get_short_description(), 400 );
			$v['description']       = self::shorten( $product->get_description(), 600 );
			$v['tags']              = self::term_names( $post_id, 'product_tag' );
			$v['categories']        = self::term_names( $post_id, 'product_cat' );
			$v['attributes']        = self::attributes_block( $product );

			$primary = self::primary_category( $post_id );
			if ( $primary ) {
				$v['category'] = self::plain( $primary->name );
			}

			$v = array_merge( $v, self::price_values( $product ) );

			$statuses          = function_exists( 'wc_get_product_stock_status_options' ) ? wc_get_product_stock_status_options() : array();
			$status            = $product->get_stock_status();
			$v['stock_status'] = isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;

			if ( $product->has_weight() ) {
				$v['weight'] = wc_format_weight( $product->get_weight() );
			}
			if ( $product->has_dimensions() ) {
				$v['dimensions'] = self::plain( wc_format_dimensions( $product->get_dimensions( false ) ) );
			}

		}

		/** Taxonomies checked (in order) for {brand}. */
		$brand_taxonomies = apply_filters( 'aipc_brand_taxonomies', array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'pa_brand', 'pa_marka' ) );
		foreach ( (array) $brand_taxonomies as $tax ) {
			$brand = self::term_names( $post_id, $tax );
			if ( '' !== $brand ) {
				$v['brand'] = $brand;
				break;
			}
		}

		/** Add or change placeholder values: $values['warranty'] = '24 months'. */
		$v = apply_filters( 'aipc_placeholders', $v, $post_id, $product );

		self::$values[ $post_id ] = $v;
		return $v;
	}

	/**
	 * Clear the per-request cache (used after saving a product in the admin).
	 *
	 * @param int|null $post_id Product ID or null for all.
	 */
	public static function flush( $post_id = null ) {
		if ( null === $post_id ) {
			self::$values = array();
		} else {
			unset( self::$values[ (int) $post_id ] );
		}
	}

	/**
	 * Truncate a prompt so the encoded URL stays below the configured limit.
	 *
	 * @param string $prompt     Prompt text.
	 * @param int    $max_bytes  Maximum encoded length.
	 * @return string
	 */
	public static function fit( $prompt, $max_bytes ) {
		if ( strlen( rawurlencode( $prompt ) ) <= $max_bytes ) {
			return $prompt;
		}

		$used   = 0;
		$budget = $max_bytes - 9; // Room for an encoded ellipsis.
		$cut    = '';
		$chars  = preg_split( '//u', $prompt, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( $chars as $char ) {
			$len = strlen( rawurlencode( $char ) );
			if ( $used + $len > $budget ) {
				break;
			}
			$used += $len;
			$cut  .= $char;
		}

		// Cut back to the last whitespace so we don't split a word.
		$last_space = max( strrpos( $cut, ' ' ), strrpos( $cut, "\n" ) );
		if ( $last_space && $last_space > strlen( $cut ) * 0.8 ) {
			$cut = substr( $cut, 0, $last_space );
		}

		return rtrim( $cut ) . '…';
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function price_values( $product ) {
		$out = array(
			'price'         => '',
			'regular_price' => '',
		);

		if ( $product->is_type( 'variable' ) ) {
			$min = $product->get_variation_price( 'min', true );
			$max = $product->get_variation_price( 'max', true );
			if ( '' !== $min && null !== $min ) {
				$out['price'] = ( $min === $max )
					? self::money( $min )
					: self::money( $min ) . ' – ' . self::money( $max );
			}
			return $out;
		}

		$price = wc_get_price_to_display( $product );
		if ( '' !== $product->get_price() ) {
			$out['price'] = self::money( $price );
		}
		if ( $product->is_on_sale() && '' !== $product->get_regular_price() ) {
			$out['regular_price'] = self::money( wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ) );
		}
		return $out;
	}

	private static function money( $amount ) {
		return self::plain( wc_price( $amount ) );
	}

	private static function attributes_block( $product ) {
		$lines = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, 'WC_Product_Attribute' ) || ! $attribute->get_visible() ) {
				continue;
			}
			$name   = $attribute->get_name();
			$label  = wc_attribute_label( $name, $product );
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'names' ) )
				: $attribute->get_options();
			$values = array_filter( array_map( array( __CLASS__, 'plain' ), (array) $values ) );
			if ( $values ) {
				$lines[] = self::plain( $label ) . ': ' . implode( ', ', $values );
			}
		}
		return implode( "\n", $lines );
	}

	private static function attribute_value( $post_id, $slug ) {
		if ( '' === $slug || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $post_id );
		return $product ? self::plain( $product->get_attribute( $slug ) ) : '';
	}

	private static function meta_value( $post_id, $key ) {
		if ( '' === $key || ! $post_id ) {
			return '';
		}
		$value = function_exists( 'get_field' ) ? get_field( $key, $post_id, true ) : null;
		if ( null === $value || false === $value ) {
			$value = get_post_meta( $post_id, $key, true );
		}
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) );
		}
		return is_scalar( $value ) ? self::plain( (string) $value ) : '';
	}

	private static function term_names( $post_id, $taxonomy ) {
		if ( ! $post_id || '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		return implode( ', ', array_map( array( __CLASS__, 'plain' ), wp_list_pluck( $terms, 'name' ) ) );
	}

	/**
	 * The product's primary category: Yoast / Rank Math primary, else the deepest one.
	 *
	 * @param int $post_id Product ID.
	 * @return WP_Term|null
	 */
	public static function primary_category( $post_id ) {
		$terms = self::ordered_categories( $post_id );
		return $terms ? $terms[0] : null;
	}

	/**
	 * Product categories ordered: SEO-plugin primary first, then deepest first.
	 *
	 * @param int $post_id Product ID.
	 * @return WP_Term[]
	 */
	private static function ordered_categories( $post_id ) {
		$terms = get_the_terms( $post_id, 'product_cat' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}

		$primary_id = (int) get_post_meta( $post_id, '_yoast_wpseo_primary_product_cat', true );
		if ( ! $primary_id ) {
			$primary_id = (int) get_post_meta( $post_id, 'rank_math_primary_product_cat', true );
		}

		usort(
			$terms,
			static function ( $a, $b ) use ( $primary_id ) {
				if ( $primary_id ) {
					if ( (int) $a->term_id === $primary_id ) {
						return -1;
					}
					if ( (int) $b->term_id === $primary_id ) {
						return 1;
					}
				}
				return count( get_ancestors( $b->term_id, 'product_cat', 'taxonomy' ) ) - count( get_ancestors( $a->term_id, 'product_cat', 'taxonomy' ) );
			}
		);

		return $terms;
	}

	/**
	 * First non-empty category meta value, walking up parent categories.
	 *
	 * @param int    $post_id  Product ID.
	 * @param string $meta_key Term meta key.
	 * @return array|null { value: string, term: WP_Term }
	 */
	public static function category_value( $post_id, $meta_key ) {
		foreach ( self::ordered_categories( $post_id ) as $term ) {
			$chain = array_merge( array( $term->term_id ), get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
			foreach ( $chain as $term_id ) {
				$value = (string) get_term_meta( $term_id, $meta_key, true );
				if ( '' !== trim( $value ) ) {
					return array(
						'value' => $value,
						'term'  => get_term( $term_id, 'product_cat' ),
					);
				}
			}
		}
		return null;
	}

	/**
	 * Strip tags/entities and collapse whitespace.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function plain( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		return trim( preg_replace( '/[ \t]+/', ' ', $text ) );
	}

	private static function shorten( $html, $max_chars ) {
		$text = self::plain( strip_shortcodes( (string) $html ) );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max_chars ) {
			$text = mb_substr( $text, 0, $max_chars );
			$text = preg_replace( '/\s+\S*$/u', '', $text ) . '…';
		}
		return $text;
	}

	/**
	 * Store language name, written in that language ("English", "български", "Deutsch"),
	 * so it reads naturally inside a template translated into the same language.
	 *
	 * @return string
	 */
	private static function language_name() {
		$locale = determine_locale();
		$name   = '';
		if ( function_exists( 'locale_get_display_language' ) ) {
			$name = (string) locale_get_display_language( $locale, $locale );
		}
		if ( '' === $name || $name === $locale ) {
			$map  = array(
				'bg' => 'български',
				'de' => 'Deutsch',
				'el' => 'Ελληνικά',
				'en' => 'English',
				'es' => 'español',
				'fr' => 'français',
				'it' => 'italiano',
				'ro' => 'română',
				'tr' => 'Türkçe',
			);
			$code = strtolower( substr( $locale, 0, 2 ) );
			$name = isset( $map[ $code ] ) ? $map[ $code ] : $locale;
		}
		/** Filter the {language} value, e.g. to force "English" regardless of the store language. */
		return (string) apply_filters( 'aipc_language_name', $name, $locale );
	}

}
