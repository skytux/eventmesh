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
 * Starting playback: a click on our button cannot press the play button
 * inside the player, because the player is a page from another site. So after
 * the click this file loads that service's official player API and asks the
 * player to start:
 *
 * - SoundCloud: the Widget API (https://w.soundcloud.com/player/api.js).
 *   SC.Widget(iframe).play() runs when the player reports it is ready.
 * - Mixcloud: the Widget API (https://widget.mixcloud.com/media/js/widgetApi.js).
 *   Mixcloud.PlayerWidget(iframe).play() runs when the player is ready.
 * - Spotify: the iFrame API (https://open.spotify.com/embed/iframe-api/v1).
 *   It puts its own player in place of our iframe, then controller.play().
 *
 * The API scripts are requested only after a visitor clicks, so a page that
 * shows players but gets no click loads none of them. If an API script does
 * not load within API_TIMEOUT_MS, fails to load, or throws an error, the
 * player is shown the normal way and the visitor presses play inside it, as
 * before 1.5.0. Whether a browser lets the player start this way (mobile
 * browsers limit sound that did not start from a touch inside the player
 * itself) can only be checked on a real phone.
 *
 * One delegated listener on the document handles every embed on the page - a
 * list of 20 events costs one listener, not 20 - and it keeps working for
 * embeds added after load (a "load more" or a filtered re-render), which
 * per-element listeners bound at startup would miss.
 */
( function () {
	var API_TIMEOUT_MS = 4000;

	var API_URLS = {
		soundcloud: 'https://w.soundcloud.com/player/api.js',
		mixcloud: 'https://widget.mixcloud.com/media/js/widgetApi.js',
		spotify: 'https://open.spotify.com/embed/iframe-api/v1',
	};

	// One promise per service, so two players of the same service on one
	// page load its API script once.
	var apiPromises = {};

	function serviceFor( src ) {
		if ( /^https:\/\/w\.soundcloud\.com\/player\//.test( src ) ) {
			return 'soundcloud';
		}

		if ( /^https:\/\/([a-z-]+\.)?mixcloud\.com\/widget\/iframe\//.test( src ) ) {
			return 'mixcloud';
		}

		if ( /^https:\/\/open\.spotify\.com\/embed\//.test( src ) ) {
			return 'spotify';
		}

		return null;
	}

	/**
	 * "https://open.spotify.com/embed/artist/ID?utm_source=oembed" becomes
	 * "spotify:artist:ID", the form the Spotify iFrame API takes.
	 */
	function spotifyUri( src ) {
		var match = /^https:\/\/open\.spotify\.com\/embed\/(?:[a-z-]+\/)?(track|album|artist|playlist|episode|show)\/([A-Za-z0-9]+)/.exec(
			src
		);

		return match ? 'spotify:' + match[ 1 ] + ':' + match[ 2 ] : null;
	}

	function apiReady( service ) {
		if ( 'soundcloud' === service ) {
			return !! ( window.SC && window.SC.Widget );
		}

		if ( 'mixcloud' === service ) {
			return !! ( window.Mixcloud && window.Mixcloud.PlayerWidget );
		}

		return !! window.EventMeshSpotifyIFrameAPI;
	}

	/**
	 * Loads the service's API script once. Resolves when the API can be
	 * used; rejects on a load error or after API_TIMEOUT_MS.
	 */
	function loadApi( service ) {
		if ( apiPromises[ service ] ) {
			return apiPromises[ service ];
		}

		apiPromises[ service ] = new Promise( function ( resolve, reject ) {
			if ( apiReady( service ) ) {
				resolve();
				return;
			}

			var timer = window.setTimeout( reject, API_TIMEOUT_MS );

			function done() {
				window.clearTimeout( timer );
				resolve();
			}

			if ( 'spotify' === service ) {
				// The Spotify API calls this global function once it is ready.
				// Keep any handler the page already set, and call it too.
				var previous = window.onSpotifyIframeApiReady;

				window.onSpotifyIframeApiReady = function ( IFrameAPI ) {
					window.EventMeshSpotifyIFrameAPI = IFrameAPI;

					if ( 'function' === typeof previous ) {
						previous( IFrameAPI );
					}

					done();
				};
			}

			var script = document.createElement( 'script' );

			script.src = API_URLS[ service ];
			script.async = true;
			script.onload = function () {
				if ( 'spotify' === service ) {
					return;
				}

				if ( apiReady( service ) ) {
					done();
				} else {
					window.clearTimeout( timer );
					reject();
				}
			};
			script.onerror = function () {
				window.clearTimeout( timer );
				reject();
			};

			document.head.appendChild( script );
		} );

		// A failed load may work on a later click (for example after the
		// connection comes back), so forget it.
		apiPromises[ service ].catch( function () {
			delete apiPromises[ service ];
		} );

		return apiPromises[ service ];
	}

	/**
	 * Lets the player start sound. Chrome and Firefox block sound in an
	 * iframe from another site unless the iframe's allow attribute lists
	 * "autoplay". It must be set before src.
	 */
	function allowAutoplay( iframe ) {
		var allow = iframe.getAttribute( 'allow' ) || '';

		if ( ! /(^|[;\s])autoplay([;\s]|$)/.test( allow ) ) {
			iframe.setAttribute(
				'allow',
				allow ? allow.replace( /;?\s*$/, '; autoplay' ) : 'autoplay'
			);
		}
	}

	function loadSrc( iframe ) {
		var src = iframe.getAttribute( 'data-src' );

		if ( src ) {
			iframe.setAttribute( 'src', src );
			iframe.removeAttribute( 'data-src' );
		}
	}

	function reveal( button, frame ) {
		frame.removeAttribute( 'hidden' );

		if ( button.parentNode ) {
			button.parentNode.removeChild( button );
		}

		// Removing the button drops focus to the body, which would strand a
		// keyboard visitor at the top of the page. Move it to the player that
		// just replaced it instead.
		frame.setAttribute( 'tabindex', '-1' );
		frame.focus();
	}

	/**
	 * Shows the player and asks it to start through the service's API.
	 * Errors thrown here are caught by the caller, which then shows the
	 * player the normal way.
	 */
	function startWithApi( service, iframe ) {
		if ( 'soundcloud' === service ) {
			loadSrc( iframe );
			var scWidget = window.SC.Widget( iframe );

			scWidget.bind( window.SC.Widget.Events.READY, function () {
				scWidget.play();
			} );
			return;
		}

		if ( 'mixcloud' === service ) {
			loadSrc( iframe );
			var mcWidget = window.Mixcloud.PlayerWidget( iframe );

			mcWidget.ready
				.then( function () {
					return mcWidget.play();
				} )
				.catch( function () {} );
			return;
		}

		var uri = spotifyUri( iframe.getAttribute( 'data-src' ) || '' );

		if ( ! uri ) {
			loadSrc( iframe );
			return;
		}

		// createController() puts Spotify's own iframe where ours is.
		window.EventMeshSpotifyIFrameAPI.createController(
			iframe,
			{
				uri: uri,
				width: '100%',
				height: iframe.getAttribute( 'height' ) || 80,
			},
			function ( controller ) {
				controller.addListener( 'ready', function () {
					controller.play();
				} );
			}
		);
	}

	function play( button ) {
		var frame = button.parentNode.querySelector( '.eventmesh-embed-frame' );

		if ( ! frame ) {
			return;
		}

		var iframe = frame.querySelector( 'iframe[data-src]' );
		var service = iframe ? serviceFor( iframe.getAttribute( 'data-src' ) ) : null;

		if ( ! iframe || ! service || 'function' !== typeof window.Promise ) {
			if ( iframe ) {
				loadSrc( iframe );
			}

			reveal( button, frame );
			return;
		}

		// A second click while the API loads must not start a second load.
		if ( button.disabled ) {
			return;
		}

		button.disabled = true;
		allowAutoplay( iframe );

		loadApi( service ).then(
			function () {
				try {
					startWithApi( service, iframe );
				} catch ( error ) {
					loadSrc( iframe );
				}

				reveal( button, frame );
			},
			function () {
				loadSrc( iframe );
				reveal( button, frame );
			}
		);
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
