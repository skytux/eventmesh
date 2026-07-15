/**
 * Loads deferred provider-embed iframes only when they scroll near the
 * viewport. Enqueued (deferred, in the footer) only when the "Load embedded
 * players only when scrolled into view" setting is on, so it never blocks the
 * page. Each iframe ships with data-src instead of src; this sets src when it
 * approaches view. A <noscript> copy handles the no-JavaScript case, so nothing
 * here can leave the page blank.
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

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
