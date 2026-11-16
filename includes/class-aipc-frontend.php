<?php
/**
 * Front-end output: the "Ask AI" box, shortcode and WooCommerce placement.
 *
 * @package Ask_AI_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class AIPC_Frontend {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_shortcode( 'aipc_buttons', array( __CLASS__, 'shortcode' ) );
		// Settings contain translated defaults, so read them on `init` (not earlier).
		add_action( 'init', array( __CLASS__, 'register_placement' ) );
	}

	public static function register_placement() {
		if ( ! AIPC_Settings::get( 'enabled' ) ) {
			return;
		}

		switch ( AIPC_Settings::get( 'position' ) ) {
			case 'after_add_to_cart':
				add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'output_current' ), 35 );
				break;
			case 'summary_end':
				add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'output_current' ), 65 );
				break;
			case 'after_summary':
				add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'output_current' ), 5 );
				break;
			case 'tab':
				add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'add_tab' ) );
				break;
		}
	}

	public static function register_assets() {
		wp_register_style( 'aipc', AIPC_URL . 'assets/css/aipc.css', array(), AIPC_VERSION );
		wp_register_script( 'aipc', AIPC_URL . 'assets/js/aipc.js', array(), AIPC_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	public static function maybe_enqueue() {
		if ( function_exists( 'is_product' ) && is_product() && AIPC_Settings::get( 'enabled' ) ) {
			wp_enqueue_style( 'aipc' );
			wp_enqueue_script( 'aipc' );
		}
	}

	public static function output_current() {
		echo self::render( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
	}

	public static function add_tab( $tabs ) {
		$post_id = get_the_ID();
		if ( ! self::is_available( $post_id ) ) {
			return $tabs;
		}
		$tabs['aipc'] = array(
			'title'    => apply_filters( 'aipc_tab_title', __( 'Ask AI', 'ask-ai-for-woocommerce' ) ),
			'priority' => 25,
			'callback' => array( __CLASS__, 'output_current' ),
		);
		return $tabs;
	}

	/**
	 * [aipc_buttons id="123" heading="…" subheading="…" providers="chatgpt,claude" display="icons_labels"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'         => 0,
				'heading'    => null,
				'subheading' => null,
				'providers'  => '',
				'display'    => '',
			),
			$atts,
			'aipc_buttons'
		);

		$post_id = $atts['id'] ? absint( $atts['id'] ) : get_the_ID();
		return self::render( $post_id, $atts, true );
	}

	/**
	 * Whether the box should show for a product.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_available( $post_id ) {
		if ( ! $post_id || 'yes' === get_post_meta( $post_id, AIPC_Settings::META_DISABLED, true ) ) {
			return false;
		}
		return (bool) apply_filters( 'aipc_is_available', true, $post_id );
	}

	/**
	 * Render the box.
	 *
	 * @param int   $post_id   Product ID.
	 * @param array $args      Optional overrides (heading, subheading, providers, display).
	 * @param bool  $shortcode Whether called from the shortcode (ignores the global "enabled" toggle).
	 * @return string
	 */
	public static function render( $post_id, $args = array(), $shortcode = false ) {
		if ( ( ! $shortcode && ! AIPC_Settings::get( 'enabled' ) ) || ! self::is_available( $post_id ) ) {
			return '';
		}

		$providers = array_filter(
			AIPC_Providers::all( true ),
			static function ( $p ) {
				return ! empty( $p['url'] ) && AIPC_Providers::is_valid_url_template( $p['url'] );
			}
		);
		if ( ! empty( $args['providers'] ) ) {
			$only      = array_map( 'trim', explode( ',', strtolower( $args['providers'] ) ) );
			$providers = array_intersect_key( $providers, array_flip( $only ) );
		}
		if ( ! $providers ) {
			return '';
		}

		$prompt = AIPC_Prompt::fit( AIPC_Prompt::build( $post_id ), (int) AIPC_Settings::get( 'max_url_length' ) );
		if ( '' === $prompt ) {
			return '';
		}

		wp_enqueue_style( 'aipc' );
		wp_enqueue_script( 'aipc' );

		$heading     = isset( $args['heading'] ) ? $args['heading'] : AIPC_Prompt::resolve_text( $post_id, 'heading' );
		$subheading  = isset( $args['subheading'] ) ? $args['subheading'] : AIPC_Prompt::resolve_text( $post_id, 'subheading' );
		$display     = ! empty( $args['display'] ) ? $args['display'] : AIPC_Settings::get( 'display' );
		$new_tab     = (bool) AIPC_Settings::get( 'new_tab' );
		$product     = AIPC_Prompt::values( $post_id );
		$with_labels = 'icons_labels' === $display;

		ob_start();
		?>
		<div class="aipc-box<?php echo $with_labels ? ' aipc-box--labels' : ''; ?>" data-aipc-product="<?php echo esc_attr( $post_id ); ?>">
			<?php if ( '' !== $heading ) : ?>
				<p class="aipc-heading"><?php echo esc_html( $heading ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $subheading ) : ?>
				<p class="aipc-subheading"><?php echo esc_html( $subheading ); ?></p>
			<?php endif; ?>
			<ul class="aipc-providers">
				<?php foreach ( $providers as $provider ) : ?>
					<?php
					$aria = $new_tab
						/* translators: 1: AI provider name, 2: product name */
						? sprintf( __( 'Ask %1$s about %2$s (opens in a new tab)', 'ask-ai-for-woocommerce' ), $provider['label'], $product['product_name'] )
						/* translators: 1: AI provider name, 2: product name */
						: sprintf( __( 'Ask %1$s about %2$s', 'ask-ai-for-woocommerce' ), $provider['label'], $product['product_name'] );
					?>
					<li>
						<a class="aipc-btn aipc-btn--<?php echo esc_attr( $provider['id'] ); ?>"
							<?php // Not esc_url(): it strips %0A, which would remove the prompt's line breaks. The template is validated as https:// in render(). ?>
							href="<?php echo esc_attr( AIPC_Providers::build_url( $provider, $prompt ) ); ?>"
							<?php echo $new_tab ? 'target="_blank"' : ''; ?>
							rel="nofollow noopener noreferrer"
							title="<?php echo esc_attr( $provider['label'] ); ?>"
							aria-label="<?php echo esc_attr( $aria ); ?>"
							data-aipc-provider="<?php echo esc_attr( $provider['id'] ); ?>">
							<span class="aipc-icon"><?php echo self::kses_svg( AIPC_Providers::icon( $provider ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<?php if ( $with_labels ) : ?>
								<span class="aipc-label"><?php echo esc_html( $provider['label'] ); ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return (string) apply_filters( 'aipc_box_html', ob_get_clean(), $post_id, $prompt );
	}

	private static function kses_svg( $svg ) {
		static $allowed = null;
		if ( null === $allowed ) {
			$common  = array(
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'transform'       => true,
				'opacity'         => true,
				'fill-rule'       => true,
				'clip-rule'       => true,
			);
			$allowed = array(
				'svg'    => array(
					'viewbox'     => true,
					'xmlns'       => true,
					'aria-hidden' => true,
					'focusable'   => true,
					'width'       => true,
					'height'      => true,
					'fill'        => true,
				),
				'path'   => $common + array( 'd' => true ),
				'circle' => $common + array(
					'cx' => true,
					'cy' => true,
					'r'  => true,
				),
				'rect'   => $common + array(
					'x'      => true,
					'y'      => true,
					'width'  => true,
					'height' => true,
					'rx'     => true,
				),
				'g'      => $common,
			);
		}
		return wp_kses( $svg, $allowed );
	}
}
