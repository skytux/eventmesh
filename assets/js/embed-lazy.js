/**
 * Loads deferred provider-embed iframes only when they scroll near the
 * viewport. Enqueued (deferred, in the footer) only when the "Load embedded
 * players only when scrolled into view" setting is on, so it never blocks the
 * page. Each iframe ships with data-src instead of src; this sets src when it
 * approaches view. A <noscript> copy handles the no-JavaScript case, so nothing
 * here can leave the page blank.
 *
 * Crucially, promotion is held until AFTER the window "load" event (and then
 * an idle callback). Setting an iframe's src before load makes it a subresource
 * that "load" waits for - which would delay any load-gated theme animations
 * until the players finished. Waiting until load has already fired lets the
 * page settle and its animations run first; the players then load without
 * blocking or contending with them.
 */
( function () {
	function loadEmbed( iframe ) {
		var src = iframe.getAttribute( 'data-src' );

		if ( src && ! iframe.getAttribute( 'src' ) ) {
			iframe.setAttribute( 'src', src );
		}

		iframe.removeAttribute( 'data-src' );
	}

	function init() {
		var iframes = document.querySelectorAll(
			'.eventmesh-provider-embed iframe[data-src]'
		);

		if ( ! iframes.length ) {
			return;
		}

		// Older browsers without IntersectionObserver: just load them all
		// rather than leave them blank.
		if ( ! ( 'IntersectionObserver' in window ) ) {
			Array.prototype.forEach.call( iframes, loadEmbed );
			return;
		}

		var observer = new IntersectionObserver(
			function ( entries, obs ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						loadEmbed( entry.target );
						obs.unobserve( entry.target );
					}
				} );
			},
			// Start loading a little before the player is actually on screen.
			{ rootMargin: '200px 0px' }
		);

		Array.prototype.forEach.call( iframes, function ( iframe ) {
			observer.observe( iframe );
		} );
	}

	// Run during idle time after load, so promoting the iframes never competes
	// with the page's own load-time work.
	function schedule() {
		if ( 'requestIdleCallback' in window ) {
			window.requestIdleCallback( init, { timeout: 2000 } );
		} else {
			window.setTimeout( init, 200 );
		}
	}

	// Hold until the window "load" event has fired, so setting src can't delay
	// load (and any animations gated on it). If load already fired, go now.
	if ( 'complete' === document.readyState ) {
		schedule();
	} else {
		window.addEventListener( 'load', schedule );
	}
} )();
