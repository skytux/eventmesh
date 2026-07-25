/**
 * Click-to-play for provider embeds. Enqueued (deferred, in the footer) only
 * when the facade strategy is on and the page actually rendered one, so it
 * never blocks the page.
 *
 * ProviderEmbedMarkup ships each embed as a button plus a hidden iframe whose
 * src is parked in data-src. Nothing third-party is requested until a visitor
 * clicks; this promotes data-src to src, reveals the frame, and drops the
 * button. A <noscript> copy handles the no-JavaScript case, so nothing here
 * can leave the page blank.
 *
 * One delegated listener on the document handles every embed on the page - a
 * list of 20 events costs one listener, not 20 - and it keeps working for
 * embeds added after load (a "load more" or a filtered re-render), which
 * per-element listeners bound at startup would miss.
 */
( function () {
	function play( button ) {
		var frame = button.parentNode.querySelector( '.eventmesh-embed-frame' );

		if ( ! frame ) {
			return;
		}

		var iframe = frame.querySelector( 'iframe[data-src]' );

		if ( iframe ) {
			iframe.setAttribute( 'src', iframe.getAttribute( 'data-src' ) );
			iframe.removeAttribute( 'data-src' );
		}

		frame.removeAttribute( 'hidden' );
		button.parentNode.removeChild( button );

		// Removing the button drops focus to the body, which would strand a
		// keyboard visitor at the top of the page. Move it to the player that
		// just replaced it instead.
		frame.setAttribute( 'tabindex', '-1' );
		frame.focus();
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest
			? event.target.closest( '.eventmesh-embed-facade' )
			: null;

		if ( button ) {
			play( button );
		}
	} );
} )();
