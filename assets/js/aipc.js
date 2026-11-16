/**
 * Ask AI for WooCommerce – click tracking.
 * Pushes an `aipc_click` event to the GTM/GA4 dataLayer and fires a DOM event
 * (`aipc:click`) so other scripts can react.
 */
( function () {
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( '[data-aipc-provider]' ) : null;
		if ( ! link ) {
			return;
		}

		var box = link.closest( '[data-aipc-product]' );
		var detail = {
			provider: link.getAttribute( 'data-aipc-provider' ),
			productId: box ? box.getAttribute( 'data-aipc-product' ) : null
		};

		// GTM: create a Custom Event trigger for "aipc_click".
		if ( window.dataLayer && typeof window.dataLayer.push === 'function' ) {
			window.dataLayer.push( {
				event: 'aipc_click',
				aipc_provider: detail.provider,
				aipc_product_id: detail.productId
			} );
		}
		// gtag.js / Site Kit: sent straight to GA4.
		if ( typeof window.gtag === 'function' ) {
			window.gtag( 'event', 'aipc_click', {
				aipc_provider: detail.provider,
				aipc_product_id: detail.productId
			} );
		}

		try {
			link.dispatchEvent( new CustomEvent( 'aipc:click', { bubbles: true, detail: detail } ) );
		} catch ( err ) {}
	} );
} )();
