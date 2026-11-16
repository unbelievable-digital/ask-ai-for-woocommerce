<?php
/**
 * Per-product meta box, product list column/bulk actions and category fields.
 *
 * @package Ask_AI_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class AIPC_Product_Admin {

	public static function init() {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_product' ), 10, 2 );

		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );

		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'category_add_fields' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'category_edit_fields' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'save_category' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_category' ) );
	}

	/**
	 * Register meta so it shows up in the REST API (useful for headless / bulk tools).
	 * Hooked on `init` for every request (not only wp-admin).
	 */
	public static function register_meta() {
		$auth = static function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};
		foreach ( array( AIPC_Settings::META_PROMPT, AIPC_Settings::META_HEADING, AIPC_Settings::META_SUBHEADING, AIPC_Settings::META_DISABLED ) as $key ) {
			register_post_meta(
				'product',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => AIPC_Settings::META_PROMPT === $key ? 'sanitize_textarea_field' : 'sanitize_text_field',
					'auth_callback'     => $auth,
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Product meta box
	 * ------------------------------------------------------------------- */

	public static function add_meta_box() {
		add_meta_box( 'aipc-product', __( 'Ask AI', 'ask-ai-for-woocommerce' ), array( __CLASS__, 'render_meta_box' ), 'product', 'normal', 'default' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'aipc_save_product', 'aipc_product_nonce' );

		$prompt     = (string) get_post_meta( $post->ID, AIPC_Settings::META_PROMPT, true );
		$heading    = (string) get_post_meta( $post->ID, AIPC_Settings::META_HEADING, true );
		$subheading = (string) get_post_meta( $post->ID, AIPC_Settings::META_SUBHEADING, true );
		$disabled   = 'yes' === get_post_meta( $post->ID, AIPC_Settings::META_DISABLED, true );

		// Template that would apply if the custom prompt were empty.
		$fallback = AIPC_Prompt::category_value( $post->ID, AIPC_Settings::TERM_PROMPT );
		// Full sentences per case (no fragments), so every language can phrase them naturally.
		if ( $fallback ) {
			$fallback_text = $fallback['value'];
			$cat_name      = '<strong>' . esc_html( $fallback['term']->name ) . '</strong>';
			/* translators: %s: product category name */
			$using_text = sprintf( esc_html__( 'Using the prompt template of the category %s. Write a custom prompt below only if this product needs something different.', 'ask-ai-for-woocommerce' ), $cat_name );
			/* translators: %s: product category name */
			$button_text = sprintf( esc_html__( 'Start from the template of the category %s', 'ask-ai-for-woocommerce' ), esc_html( $fallback['term']->name ) );
		} else {
			$fallback_text = (string) AIPC_Settings::get( 'template' );
			$using_text    = esc_html__( 'Using the global prompt template. Write a custom prompt below only if this product needs something different.', 'ask-ai-for-woocommerce' );
			$button_text   = esc_html__( 'Start from the global template', 'ask-ai-for-woocommerce' );
		}
		?>
		<div class="aipc-metabox">
			<p>
				<label><input type="checkbox" name="aipc_disabled" value="yes" <?php checked( $disabled ); ?>>
				<?php esc_html_e( 'Hide the AI box for this product', 'ask-ai-for-woocommerce' ); ?></label>
			</p>

			<p class="aipc-source">
				<?php
				if ( '' !== trim( $prompt ) ) {
					esc_html_e( 'Using the custom prompt below.', 'ask-ai-for-woocommerce' );
				} else {
					echo wp_kses( $using_text, array( 'strong' => array() ) );
				}
				?>
			</p>

			<label for="aipc-product-prompt"><strong><?php esc_html_e( 'Custom prompt (optional)', 'ask-ai-for-woocommerce' ); ?></strong></label>
			<textarea id="aipc-product-prompt" name="aipc_prompt" rows="10" class="large-text code aipc-template" placeholder="<?php esc_attr_e( 'Leave empty to use the category or global template', 'ask-ai-for-woocommerce' ); ?>"><?php echo esc_textarea( $prompt ); ?></textarea>
			<p>
				<button type="button" class="button aipc-copy-template" data-target="#aipc-product-prompt" data-template="<?php echo esc_attr( $fallback_text ); ?>">
					<?php echo esc_html( $button_text ); ?>
				</button>
			</p>
			<?php AIPC_Admin::placeholder_chips(); ?>

			<div class="aipc-grid">
				<p>
					<label for="aipc-product-heading"><?php esc_html_e( 'Heading override', 'ask-ai-for-woocommerce' ); ?></label>
					<input type="text" id="aipc-product-heading" class="large-text" name="aipc_heading" value="<?php echo esc_attr( $heading ); ?>" placeholder="<?php echo esc_attr( self::inherited_text( $post->ID, 'heading' ) ); ?>">
				</p>
				<p>
					<label for="aipc-product-subheading"><?php esc_html_e( 'Subheading override', 'ask-ai-for-woocommerce' ); ?></label>
					<input type="text" id="aipc-product-subheading" class="large-text" name="aipc_subheading" value="<?php echo esc_attr( $subheading ); ?>" placeholder="<?php echo esc_attr( self::inherited_text( $post->ID, 'subheading' ) ); ?>">
				</p>
			</div>

			<?php if ( 'auto-draft' !== $post->post_status ) : ?>
				<?php AIPC_Admin::preview_panel( '#aipc-product-prompt', $post->ID ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Heading/subheading the product would get without its own override.
	 *
	 * @param int    $post_id Product ID.
	 * @param string $which   'heading' or 'subheading'.
	 * @return string
	 */
	private static function inherited_text( $post_id, $which ) {
		$from_cat = AIPC_Prompt::category_value( $post_id, 'heading' === $which ? AIPC_Settings::TERM_HEADING : AIPC_Settings::TERM_SUBHEADING );
		return $from_cat ? $from_cat['value'] : (string) AIPC_Settings::get( $which );
	}

	public static function save_product( $post_id, $post ) {
		if ( ! isset( $_POST['aipc_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['aipc_product_nonce'] ), 'aipc_save_product' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			AIPC_Settings::META_PROMPT     => isset( $_POST['aipc_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['aipc_prompt'] ) ) : '',
			AIPC_Settings::META_HEADING    => isset( $_POST['aipc_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['aipc_heading'] ) ) : '',
			AIPC_Settings::META_SUBHEADING => isset( $_POST['aipc_subheading'] ) ? sanitize_text_field( wp_unslash( $_POST['aipc_subheading'] ) ) : '',
			AIPC_Settings::META_DISABLED   => isset( $_POST['aipc_disabled'] ) ? 'yes' : '',
		);

		foreach ( $fields as $key => $value ) {
			if ( '' === trim( $value ) ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		AIPC_Prompt::flush( $post_id );
	}

	/* ---------------------------------------------------------------------
	 * Product list: column + bulk actions
	 * ------------------------------------------------------------------- */

	public static function add_column( $columns ) {
		$columns['aipc'] = __( 'AI prompt', 'ask-ai-for-woocommerce' );
		return $columns;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'aipc' !== $column ) {
			return;
		}
		if ( 'yes' === get_post_meta( $post_id, AIPC_Settings::META_DISABLED, true ) ) {
			echo '<span class="aipc-badge aipc-badge--off">' . esc_html__( 'Hidden', 'ask-ai-for-woocommerce' ) . '</span>';
			return;
		}
		$resolved = AIPC_Prompt::resolve_template( $post_id );
		switch ( $resolved['source'] ) {
			case 'product':
				echo '<span class="aipc-badge aipc-badge--custom">' . esc_html__( 'Custom', 'ask-ai-for-woocommerce' ) . '</span>';
				break;
			case 'category':
				echo '<span class="aipc-badge aipc-badge--cat" title="' . esc_attr( $resolved['term']->name ) . '">' . esc_html( $resolved['term']->name ) . '</span>';
				break;
			default:
				echo '<span class="aipc-badge">' . esc_html__( 'Global', 'ask-ai-for-woocommerce' ) . '</span>';
		}
	}

	public static function bulk_actions( $actions ) {
		$actions['aipc_hide']  = __( 'Ask AI: hide box', 'ask-ai-for-woocommerce' );
		$actions['aipc_show']  = __( 'Ask AI: show box', 'ask-ai-for-woocommerce' );
		$actions['aipc_reset'] = __( 'Ask AI: remove custom prompt', 'ask-ai-for-woocommerce' );
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'aipc_hide', 'aipc_show', 'aipc_reset' ), true ) ) {
			return $redirect;
		}

		$count = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			if ( 'aipc_hide' === $action ) {
				update_post_meta( $post_id, AIPC_Settings::META_DISABLED, 'yes' );
			} elseif ( 'aipc_show' === $action ) {
				delete_post_meta( $post_id, AIPC_Settings::META_DISABLED );
			} else {
				delete_post_meta( $post_id, AIPC_Settings::META_PROMPT );
			}
			++$count;
		}

		return add_query_arg( 'aipc_bulk', $count, remove_query_arg( 'aipc_bulk', $redirect ) );
	}

	public static function bulk_notice() {
		if ( ! isset( $_GET['aipc_bulk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$count = absint( $_GET['aipc_bulk'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = sprintf(
			/* translators: %s: number of products */
			_n( 'Ask AI: %s product updated.', 'Ask AI: %s products updated.', $count, 'ask-ai-for-woocommerce' ),
			number_format_i18n( $count )
		);
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/* ---------------------------------------------------------------------
	 * Product category fields
	 * ------------------------------------------------------------------- */

	public static function category_add_fields() {
		wp_nonce_field( 'aipc_save_term', 'aipc_term_nonce' );
		?>
		<div class="form-field aipc-term-field">
			<label for="aipc-term-heading"><?php esc_html_e( 'Ask AI heading', 'ask-ai-for-woocommerce' ); ?></label>
			<input type="text" id="aipc-term-heading" name="aipc_term_heading" placeholder="<?php echo esc_attr( AIPC_Settings::get( 'heading' ) ); ?>">
		</div>
		<div class="form-field aipc-term-field">
			<label for="aipc-term-subheading"><?php esc_html_e( 'Ask AI subheading', 'ask-ai-for-woocommerce' ); ?></label>
			<input type="text" id="aipc-term-subheading" name="aipc_term_subheading" placeholder="<?php echo esc_attr( AIPC_Settings::get( 'subheading' ) ); ?>">
		</div>
		<div class="form-field aipc-term-field">
			<label for="aipc-term-prompt"><?php esc_html_e( 'Ask AI prompt template', 'ask-ai-for-woocommerce' ); ?></label>
			<textarea id="aipc-term-prompt" name="aipc_term_prompt" rows="10" class="code aipc-template"></textarea>
			<?php AIPC_Admin::placeholder_chips(); ?>
			<p><?php esc_html_e( 'Applies to all products in this category and its sub-categories. Empty = inherit from the parent category or the global template.', 'ask-ai-for-woocommerce' ); ?></p>
		</div>
		<?php
	}

	public static function category_edit_fields( $term ) {
		$heading    = (string) get_term_meta( $term->term_id, AIPC_Settings::TERM_HEADING, true );
		$subheading = (string) get_term_meta( $term->term_id, AIPC_Settings::TERM_SUBHEADING, true );
		$prompt     = (string) get_term_meta( $term->term_id, AIPC_Settings::TERM_PROMPT, true );
		?>
		<tr class="form-field">
			<th scope="row" colspan="2"><h2><?php esc_html_e( 'Ask AI', 'ask-ai-for-woocommerce' ); ?></h2><?php wp_nonce_field( 'aipc_save_term', 'aipc_term_nonce' ); ?></th>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="aipc-term-heading"><?php esc_html_e( 'Heading', 'ask-ai-for-woocommerce' ); ?></label></th>
			<td><input type="text" id="aipc-term-heading" name="aipc_term_heading" value="<?php echo esc_attr( $heading ); ?>" placeholder="<?php echo esc_attr( AIPC_Settings::get( 'heading' ) ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="aipc-term-subheading"><?php esc_html_e( 'Subheading', 'ask-ai-for-woocommerce' ); ?></label></th>
			<td><input type="text" id="aipc-term-subheading" name="aipc_term_subheading" value="<?php echo esc_attr( $subheading ); ?>" placeholder="<?php echo esc_attr( AIPC_Settings::get( 'subheading' ) ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="aipc-term-prompt"><?php esc_html_e( 'Prompt template', 'ask-ai-for-woocommerce' ); ?></label></th>
			<td>
				<textarea id="aipc-term-prompt" name="aipc_term_prompt" rows="14" class="large-text code aipc-template"><?php echo esc_textarea( $prompt ); ?></textarea>
				<p>
					<button type="button" class="button aipc-copy-template" data-target="#aipc-term-prompt" data-template="<?php echo esc_attr( AIPC_Settings::get( 'template' ) ); ?>"><?php esc_html_e( 'Start from the global template', 'ask-ai-for-woocommerce' ); ?></button>
				</p>
				<?php AIPC_Admin::placeholder_chips(); ?>
				<p class="description"><?php esc_html_e( 'Applies to all products in this category and its sub-categories. Empty = inherit from the parent category or the global template.', 'ask-ai-for-woocommerce' ); ?></p>
				<?php AIPC_Admin::preview_panel( '#aipc-term-prompt' ); ?>
			</td>
		</tr>
		<?php
	}

	public static function save_category( $term_id ) {
		if ( ! isset( $_POST['aipc_term_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['aipc_term_nonce'] ), 'aipc_save_term' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$fields = array(
			AIPC_Settings::TERM_HEADING    => isset( $_POST['aipc_term_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['aipc_term_heading'] ) ) : '',
			AIPC_Settings::TERM_SUBHEADING => isset( $_POST['aipc_term_subheading'] ) ? sanitize_text_field( wp_unslash( $_POST['aipc_term_subheading'] ) ) : '',
			AIPC_Settings::TERM_PROMPT     => isset( $_POST['aipc_term_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['aipc_term_prompt'] ) ) : '',
		);

		foreach ( $fields as $key => $value ) {
			if ( '' === trim( $value ) ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $value );
			}
		}
	}
}
