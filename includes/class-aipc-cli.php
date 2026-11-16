<?php
/**
 * WP-CLI commands for managing prompts at scale.
 *
 * @package Ask_AI_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage Ask AI for WooCommerce prompts.
 */
class AIPC_CLI {

	/**
	 * Show the final prompt and links for a product.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Product ID.
	 *
	 * [--links]
	 * : Also print the provider links.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aipc preview 135913 --links
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function preview( $args, $assoc_args ) {
		$id = absint( $args[0] );
		if ( ! get_post( $id ) ) {
			WP_CLI::error( "Product {$id} not found." );
		}
		$resolved = AIPC_Prompt::resolve_template( $id );
		$prompt   = AIPC_Prompt::fit( AIPC_Prompt::build( $id ), (int) AIPC_Settings::get( 'max_url_length' ) );

		$source = $resolved['source'] . ( $resolved['term'] ? ' (' . $resolved['term']->name . ')' : '' );
		WP_CLI::log( "Source: {$source}" );
		WP_CLI::log( 'Encoded length: ' . strlen( rawurlencode( $prompt ) ) );
		WP_CLI::log( str_repeat( '-', 60 ) );
		WP_CLI::log( $prompt );

		if ( isset( $assoc_args['links'] ) ) {
			WP_CLI::log( str_repeat( '-', 60 ) );
			foreach ( AIPC_Providers::all( true ) as $provider ) {
				WP_CLI::log( $provider['label'] . ': ' . AIPC_Providers::build_url( $provider, $prompt ) );
			}
		}
	}

	/**
	 * Set a custom prompt (template text, placeholders allowed) on products.
	 *
	 * ## OPTIONS
	 *
	 * [<ids>...]
	 * : Product IDs.
	 *
	 * [--category=<slug>]
	 * : All products in this category (instead of IDs).
	 *
	 * [--prompt=<text>]
	 * : Prompt text. Use --file to read it from a file.
	 *
	 * [--file=<path>]
	 * : Read the prompt from a file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aipc set 12 34 --file=prompt.txt
	 *     wp aipc set --category=laptops --prompt="Is {product_name} good for gaming? {product_url}"
	 *
	 * Tip: for a whole category it is usually better to set the template on the category itself.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function set( $args, $assoc_args ) {
		if ( isset( $assoc_args['file'] ) ) {
			if ( ! is_readable( $assoc_args['file'] ) ) {
				WP_CLI::error( 'File not readable.' );
			}
			$prompt = file_get_contents( $assoc_args['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} elseif ( isset( $assoc_args['prompt'] ) ) {
			$prompt = $assoc_args['prompt'];
		} else {
			WP_CLI::error( 'Pass --prompt or --file.' );
		}

		$prompt = sanitize_textarea_field( $prompt );
		$ids    = $this->ids( $args, $assoc_args );

		foreach ( $ids as $id ) {
			update_post_meta( $id, AIPC_Settings::META_PROMPT, $prompt );
		}
		WP_CLI::success( sprintf( 'Custom prompt set on %d product(s).', count( $ids ) ) );
	}

	/**
	 * Remove custom prompts so products fall back to category / global templates.
	 *
	 * ## OPTIONS
	 *
	 * [<ids>...]
	 * : Product IDs.
	 *
	 * [--category=<slug>]
	 * : All products in this category.
	 *
	 * [--all]
	 * : All products.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aipc clear --all
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function clear( $args, $assoc_args ) {
		if ( isset( $assoc_args['all'] ) ) {
			delete_post_meta_by_key( AIPC_Settings::META_PROMPT );
			WP_CLI::success( 'All custom prompts removed.' );
			return;
		}
		$ids = $this->ids( $args, $assoc_args );
		foreach ( $ids as $id ) {
			delete_post_meta( $id, AIPC_Settings::META_PROMPT );
		}
		WP_CLI::success( sprintf( 'Custom prompt removed from %d product(s).', count( $ids ) ) );
	}

	/**
	 * Report how many products use each prompt source.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aipc stats
	 */
	public function stats() {
		$ids    = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$counts = array();
		foreach ( $ids as $id ) {
			if ( 'yes' === get_post_meta( $id, AIPC_Settings::META_DISABLED, true ) ) {
				$key = 'hidden';
			} else {
				$r   = AIPC_Prompt::resolve_template( $id );
				$key = $r['source'] . ( $r['term'] ? ': ' . $r['term']->name : '' );
			}
			$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
		}
		arsort( $counts );
		$rows = array();
		foreach ( $counts as $source => $count ) {
			$rows[] = array(
				'source'   => $source,
				'products' => $count,
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'source', 'products' ) );
	}

	private function ids( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['category'] ) ) {
			return get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'slug',
							'terms'    => $assoc_args['category'],
						),
					),
				)
			);
		}
		$ids = array_filter( array_map( 'absint', $args ) );
		if ( ! $ids ) {
			WP_CLI::error( 'Pass product IDs or --category.' );
		}
		return $ids;
	}
}
