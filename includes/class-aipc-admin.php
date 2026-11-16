<?php
/**
 * Settings page and admin AJAX preview.
 *
 * @package Ask_AI_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class AIPC_Admin {

	const PAGE = 'aipc-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_aipc_preview', array( __CLASS__, 'ajax_preview' ) );
		add_filter( 'option_page_capability_aipc', array( __CLASS__, 'capability' ) );
	}

	public static function capability() {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function menu() {
		$title = __( 'Ask AI for WooCommerce', 'ask-ai-for-woocommerce' );
		$menu  = __( 'Ask AI', 'ask-ai-for-woocommerce' );
		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page( 'woocommerce', $title, $menu, self::capability(), self::PAGE, array( __CLASS__, 'page' ) );
		} else {
			add_options_page( $title, $menu, self::capability(), self::PAGE, array( __CLASS__, 'page' ) );
		}
	}

	public static function register() {
		register_setting(
			'aipc',
			AIPC_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'AIPC_Settings', 'sanitize' ),
			)
		);
	}

	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$is_ours = false !== strpos( (string) $hook, self::PAGE )
			|| ( $screen && 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'edit-tags', 'term' ), true ) );

		if ( ! $is_ours ) {
			return;
		}

		wp_enqueue_style( 'aipc-admin', AIPC_URL . 'assets/css/admin.css', array(), AIPC_VERSION );
		wp_enqueue_script( 'aipc-admin', AIPC_URL . 'assets/js/admin.js', array( 'jquery', 'wp-i18n' ), AIPC_VERSION, true );
		wp_set_script_translations( 'aipc-admin', 'ask-ai-for-woocommerce', AIPC_DIR . 'languages' );
		wp_localize_script(
			'aipc-admin',
			'aipcAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'aipc_admin' ),
			)
		);
	}

	/**
	 * Preview a (possibly unsaved) template against a real product.
	 */
	public static function ajax_preview() {
		check_ajax_referer( 'aipc_admin', 'nonce' );

		$post_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $post_id ) {
			$post_id = self::sample_product_id();
		}
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Product not found or not allowed.', 'ask-ai-for-woocommerce' ) ) );
		}

		$template = isset( $_POST['template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template'] ) ) : '';
		if ( '' === trim( $template ) ) {
			$template = AIPC_Prompt::resolve_template( $post_id )['template'];
		}

		$prompt = AIPC_Prompt::render( $template, $post_id );
		$max    = (int) AIPC_Settings::get( 'max_url_length' );
		$fitted = AIPC_Prompt::fit( $prompt, $max );

		$links = array();
		foreach ( AIPC_Providers::all( true ) as $provider ) {
			$links[] = array(
				'label' => $provider['label'],
				'url'   => AIPC_Providers::build_url( $provider, $fitted ),
			);
		}

		wp_send_json_success(
			array(
				'productId'   => $post_id,
				'productName' => get_the_title( $post_id ),
				'prompt'      => $fitted,
				'length'      => strlen( rawurlencode( $prompt ) ),
				'max'         => $max,
				'truncated'   => $fitted !== $prompt,
				'links'       => $links,
			)
		);
	}

	private static function sample_product_id() {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Placeholder chips that insert into the focused textarea.
	 */
	public static function placeholder_chips() {
		echo '<div class="aipc-chips">';
		foreach ( AIPC_Prompt::placeholder_help() as $token => $help ) {
			printf(
				'<button type="button" class="button button-small aipc-chip" data-token="%1$s" title="%2$s">%1$s</button>',
				esc_attr( $token ),
				esc_attr( $help )
			);
		}
		echo '</div>';
	}

	/**
	 * Preview panel markup.
	 *
	 * @param string $template_selector CSS selector of the template textarea.
	 * @param int    $product_id        Default product ID.
	 */
	public static function preview_panel( $template_selector, $product_id = 0 ) {
		?>
		<div class="aipc-preview" data-template="<?php echo esc_attr( $template_selector ); ?>">
			<p class="aipc-preview-controls">
				<?php if ( ! $product_id ) : ?>
					<label>
						<?php esc_html_e( 'Product ID', 'ask-ai-for-woocommerce' ); ?>
						<input type="number" min="1" class="small-text aipc-preview-id" placeholder="<?php esc_attr_e( 'latest', 'ask-ai-for-woocommerce' ); ?>">
					</label>
				<?php else : ?>
					<input type="hidden" class="aipc-preview-id" value="<?php echo esc_attr( $product_id ); ?>">
				<?php endif; ?>
				<button type="button" class="button aipc-preview-btn"><?php esc_html_e( 'Preview prompt', 'ask-ai-for-woocommerce' ); ?></button>
			</p>
			<div class="aipc-preview-result" hidden>
				<p class="aipc-preview-meta"></p>
				<textarea readonly rows="12" class="large-text code aipc-preview-text"></textarea>
				<p class="aipc-preview-links"></p>
			</div>
		</div>
		<?php
	}

	public static function page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$s         = AIPC_Settings::all();
		$opt       = AIPC_Settings::OPTION;
		$providers = AIPC_Providers::all();
		$defaults  = AIPC_Providers::defaults();
		?>
		<div class="wrap aipc-wrap">
			<h1><?php esc_html_e( 'Ask AI for WooCommerce', 'ask-ai-for-woocommerce' ); ?></h1>
			<?php settings_errors(); ?>

			<div class="aipc-columns">
				<form method="post" action="options.php" class="aipc-main">
					<?php settings_fields( 'aipc' ); ?>

					<h2><?php esc_html_e( 'Display', 'ask-ai-for-woocommerce' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable', 'ask-ai-for-woocommerce' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>>
								<?php esc_html_e( 'Show the box on product pages automatically', 'ask-ai-for-woocommerce' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aipc-position"><?php esc_html_e( 'Position', 'ask-ai-for-woocommerce' ); ?></label></th>
							<td>
								<select id="aipc-position" name="<?php echo esc_attr( $opt ); ?>[position]">
									<?php foreach ( AIPC_Settings::positions() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['position'], $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php
									printf(
										/* translators: %s: shortcode */
										esc_html__( 'For Elementor, block themes or custom layouts use the shortcode %s anywhere on the product template.', 'ask-ai-for-woocommerce' ),
										'<code>[aipc_buttons]</code>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Buttons', 'ask-ai-for-woocommerce' ); ?></th>
							<td>
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[display]" value="icons" <?php checked( $s['display'], 'icons' ); ?>> <?php esc_html_e( 'Icons only', 'ask-ai-for-woocommerce' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[display]" value="icons_labels" <?php checked( $s['display'], 'icons_labels' ); ?>> <?php esc_html_e( 'Icons with names', 'ask-ai-for-woocommerce' ); ?></label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[new_tab]" value="1" <?php checked( $s['new_tab'] ); ?>> <?php esc_html_e( 'Open in a new tab', 'ask-ai-for-woocommerce' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aipc-heading"><?php esc_html_e( 'Heading', 'ask-ai-for-woocommerce' ); ?></label></th>
							<td><input type="text" id="aipc-heading" class="large-text" name="<?php echo esc_attr( $opt ); ?>[heading]" value="<?php echo esc_attr( $s['heading'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="aipc-subheading"><?php esc_html_e( 'Subheading', 'ask-ai-for-woocommerce' ); ?></label></th>
							<td>
								<input type="text" id="aipc-subheading" class="large-text" name="<?php echo esc_attr( $opt ); ?>[subheading]" value="<?php echo esc_attr( $s['subheading'] ); ?>">
								<p class="description"><?php esc_html_e( 'Both can be overridden per category and per product, and may contain placeholders.', 'ask-ai-for-woocommerce' ); ?></p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Global prompt template', 'ask-ai-for-woocommerce' ); ?></h2>
					<p class="description">
						<?php
						printf(
							/* translators: %s: example template line, e.g. "Brand: {brand}" */
							esc_html__( 'Used for every product that has no category or product prompt. Placeholders are filled from the product. A line whose placeholders are all empty is removed automatically (e.g. %s for products without a brand).', 'ask-ai-for-woocommerce' ),
							'<code>Brand: {brand}</code>'
						);
						?>
					</p>
					<textarea id="aipc-template" class="large-text code aipc-template" rows="18" name="<?php echo esc_attr( $opt ); ?>[template]"><?php echo esc_textarea( $s['template'] ); ?></textarea>
					<?php self::placeholder_chips(); ?>
					<?php self::preview_panel( '#aipc-template' ); ?>

					<h2><?php esc_html_e( 'AI providers', 'ask-ai-for-woocommerce' ); ?></h2>
					<p class="description">
						<?php
						printf(
							/* translators: %s: the {prompt} placeholder */
							esc_html__( '%s in the URL is replaced with the encoded prompt. Change a URL only if a provider changes its link format; leave it empty to use the default.', 'ask-ai-for-woocommerce' ),
							'<code>{prompt}</code>'
						);
						?>
					</p>
					<table class="widefat striped aipc-providers-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'On', 'ask-ai-for-woocommerce' ); ?></th>
								<th></th>
								<th><?php esc_html_e( 'Name', 'ask-ai-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'URL template', 'ask-ai-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Order', 'ask-ai-for-woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $providers as $id => $p ) :
								if ( ! isset( $defaults[ $id ] ) ) {
									continue; // Providers added via filter are managed in code.
								}
								$saved = isset( $s['providers'][ $id ] ) ? $s['providers'][ $id ] : array();
								$name  = $opt . '[providers][' . $id . ']';
								?>
								<tr>
									<td><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( ! empty( $p['enabled'] ) ); ?>></td>
									<td class="aipc-provider-icon"><span class="aipc-admin-icon"><?php echo AIPC_Providers::icon( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span></td>
									<td><input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( isset( $saved['label'] ) ? $saved['label'] : '' ); ?>" placeholder="<?php echo esc_attr( $defaults[ $id ]['label'] ); ?>"></td>
									<td><input type="text" class="large-text code" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( isset( $saved['url'] ) ? $saved['url'] : '' ); ?>" placeholder="<?php echo esc_attr( $defaults[ $id ]['url'] ); ?>"></td>
									<td><input type="number" class="small-text" name="<?php echo esc_attr( $name ); ?>[order]" value="<?php echo esc_attr( (int) $p['order'] ); ?>"></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<h2><?php esc_html_e( 'Advanced', 'ask-ai-for-woocommerce' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="aipc-max"><?php esc_html_e( 'Max encoded prompt length', 'ask-ai-for-woocommerce' ); ?></label></th>
							<td>
								<input type="number" id="aipc-max" class="small-text" min="1000" max="32000" name="<?php echo esc_attr( $opt ); ?>[max_url_length]" value="<?php echo esc_attr( $s['max_url_length'] ); ?>">
								<p class="description"><?php esc_html_e( 'Very long links can be rejected by AI sites. Non-Latin text (e.g. Cyrillic) takes ~6 characters per letter once encoded. Prompts above this limit are shortened at the end.', 'ask-ai-for-woocommerce' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button(); ?>
				</form>

				<aside class="aipc-side">
					<div class="aipc-card">
						<h3><?php esc_html_e( 'Prompt priority', 'ask-ai-for-woocommerce' ); ?></h3>
						<ol>
							<li><?php esc_html_e( 'Custom prompt on the product', 'ask-ai-for-woocommerce' ); ?></li>
							<li><?php esc_html_e( 'Template on the product category (inherited by sub-categories; the Yoast / Rank Math primary category wins)', 'ask-ai-for-woocommerce' ); ?></li>
							<li><?php esc_html_e( 'Global template on this page', 'ask-ai-for-woocommerce' ); ?></li>
						</ol>
					</div>
					<div class="aipc-card">
						<h3><?php esc_html_e( 'Large catalogs', 'ask-ai-for-woocommerce' ); ?></h3>
						<ul>
							<li><?php esc_html_e( 'Write one template per category (Products → Categories) instead of per product.', 'ask-ai-for-woocommerce' ); ?></li>
							<li>
								<?php
								printf(
									/* translators: 1: {attributes} placeholder, 2: {attr:…} placeholder */
									esc_html__( 'Use %1$s or %2$s so specs are filled in automatically.', 'ask-ai-for-woocommerce' ),
									'<code>{attributes}</code>',
									'<code>{attr:pa_…}</code>'
								);
								?>
							</li>
							<li><?php esc_html_e( 'Bulk actions in the products list: hide/show the box or reset custom prompts.', 'ask-ai-for-woocommerce' ); ?></li>
							<li>
								<?php
								printf(
									/* translators: 1: CSV column name, 2: CSV column name */
									esc_html__( 'CSV import/export: use the columns %1$s and %2$s.', 'ask-ai-for-woocommerce' ),
									'<code>Meta: _aipc_prompt</code>',
									'<code>Meta: _aipc_disabled</code>'
								);
								?>
							</li>
							<li>
								<?php
								printf(
									/* translators: %s: list of WP-CLI commands */
									esc_html__( 'WP-CLI: %s', 'ask-ai-for-woocommerce' ),
									'<code>wp aipc preview &lt;id&gt;</code>, <code>wp aipc set</code>, <code>wp aipc clear</code>, <code>wp aipc stats</code>'
								);
								?>
							</li>
						</ul>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}
}
