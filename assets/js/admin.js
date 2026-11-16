/* global jQuery, aipcAdmin, wp */
( function ( $ ) {
	'use strict';

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	var lastTextarea = null;

	$( document ).on( 'focus', 'textarea.aipc-template', function () {
		lastTextarea = this;
	} );

	// Insert a placeholder at the cursor of the nearest / last focused template textarea.
	$( document ).on( 'click', '.aipc-chip', function () {
		var token = $( this ).data( 'token' );
		var el = lastTextarea && document.body.contains( lastTextarea )
			? lastTextarea
			: $( this ).closest( 'td, .form-field, .aipc-metabox, form' ).find( 'textarea.aipc-template' ).get( 0 );
		if ( ! el ) {
			return;
		}
		var start = el.selectionStart || 0;
		var end = el.selectionEnd || 0;
		el.value = el.value.slice( 0, start ) + token + el.value.slice( end );
		el.focus();
		el.selectionStart = el.selectionEnd = start + token.length;
		$( el ).trigger( 'input' );
	} );

	// Copy the inherited template into the textarea as a starting point.
	$( document ).on( 'click', '.aipc-copy-template', function () {
		var $target = $( $( this ).data( 'target' ) );
		if ( $target.val().trim() && ! window.confirm( __( 'Replace the current text with the template?', 'ask-ai-for-woocommerce' ) ) ) {
			return;
		}
		$target.val( $( this ).attr( 'data-template' ) ).trigger( 'input' ).focus();
	} );

	// Live preview against a real product.
	$( document ).on( 'click', '.aipc-preview-btn', function () {
		var $panel = $( this ).closest( '.aipc-preview' );
		var $result = $panel.find( '.aipc-preview-result' );
		var $meta = $panel.find( '.aipc-preview-meta' );
		var template = $( $panel.data( 'template' ) ).val() || '';

		$result.prop( 'hidden', false );
		$meta.text( __( 'Building preview…', 'ask-ai-for-woocommerce' ) ).removeClass( 'aipc-warn' );
		$panel.find( '.aipc-preview-text' ).val( '' );
		$panel.find( '.aipc-preview-links' ).empty();

		$.post( aipcAdmin.ajaxUrl, {
			action: 'aipc_preview',
			nonce: aipcAdmin.nonce,
			product_id: $panel.find( '.aipc-preview-id' ).val() || 0,
			template: template
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				$meta.text( ( res && res.data && res.data.message ) || __( 'Could not build the preview.', 'ask-ai-for-woocommerce' ) );
				return;
			}
			var d = res.data;
			var info = sprintf(
				/* translators: 1: product ID, 2: product name, 3: encoded URL length, 4: maximum length */
				__( '#%1$d – %2$s · Encoded URL length: %3$d / %4$d', 'ask-ai-for-woocommerce' ),
				d.productId,
				$( '<div>' ).html( d.productName ).text(),
				d.length,
				d.max
			);
			if ( d.truncated ) {
				info += ' · ' + __( 'The prompt is too long and will be shortened.', 'ask-ai-for-woocommerce' );
			}
			$meta.text( info ).toggleClass( 'aipc-warn', d.truncated );
			$panel.find( '.aipc-preview-text' ).val( d.prompt );

			var $links = $panel.find( '.aipc-preview-links' );
			d.links.forEach( function ( l ) {
				$( '<a class="button button-small" target="_blank" rel="noopener noreferrer">' )
					.attr( 'href', l.url )
					.text( l.label + ' ↗' )
					.attr( 'aria-label', sprintf(
						/* translators: %s: AI provider name */
						__( 'Open the prompt in %s (new tab)', 'ask-ai-for-woocommerce' ),
						l.label
					) )
					.appendTo( $links );
				$links.append( ' ' );
			} );
		} ).fail( function () {
			$meta.text( __( 'Could not build the preview.', 'ask-ai-for-woocommerce' ) );
		} );
	} );
} )( jQuery );
