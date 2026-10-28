/**
 * Cookie Consent — frontend engine (window.nexterConsent).
 *
 * This is the ONLY script every consent surface talks to: the Nexter Blocks banner,
 * the [nexter_cookie_declaration] shortcode, the emergency fallback, and (later) a
 * TPAE Elementor widget. Nexter Blocks contains zero consent logic by design
 * (02-DEVELOPER-BRIEF.md PART 1.1) — it only calls the methods below and listens for
 * the two DOM events.
 *
 * Consent state is resolved ENTIRELY here, client-side, on every load — never on the
 * server — so a fully cached HTML response is always correct for every visitor
 * (PART 2.6). Nothing in this file may be deferred/async'd by an optimizer; the PHP
 * side marks its own <script> tag data-no-optimize/data-no-defer/data-cfasync.
 */
( function ( window, document ) {
	'use strict';

	var cfg = window.nexterConsentConfig || {};
	var COOKIE_NAME = cfg.cookieName || 'nexter_consent';
	var CATEGORY_IDS = [ 'necessary', 'functional', 'analytics', 'marketing' ];

	// ---------------------------------------------------------------------
	// Cookie helpers
	// ---------------------------------------------------------------------

	function readCookie( name ) {
		var match = document.cookie.match( '(?:^|; )' + name.replace( /([.$?*|{}()[\]\\/+^])/g, '\\$1' ) + '=([^;]*)' );
		return match ? decodeURIComponent( match[ 1 ] ) : null;
	}

	function writeCookie( name, value, days ) {
		var expires = '';
		if ( days ) {
			var date = new Date();
			date.setTime( date.getTime() + days * 24 * 60 * 60 * 1000 );
			expires = '; expires=' + date.toUTCString();
		}
		// Secure only over https — a plain http site must not silently lose the cookie.
		var secure = 'https:' === window.location.protocol ? '; Secure' : '';
		// Intentionally NOT HttpOnly: this file must read it back (PART 1.5).
		document.cookie = name + '=' + encodeURIComponent( value ) + expires + '; Path=/; SameSite=Lax' + secure;
	}

	function randomId() {
		if ( window.crypto && 'function' === typeof window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		// Older browsers (no crypto.randomUUID): still prefer crypto.getRandomValues over Math.random().
		if ( window.crypto && 'function' === typeof window.crypto.getRandomValues ) {
			var bytes = new Uint8Array( 16 );
			window.crypto.getRandomValues( bytes );
			var hex = '';
			for ( var i = 0; i < bytes.length; i++ ) {
				hex += ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 );
			}
			return hex;
		}
		var chars = 'abcdef0123456789';
		var out   = '';
		for ( var j = 0; j < 32; j++ ) {
			out += chars.charAt( Math.floor( Math.random() * chars.length ) );
		}
		return out;
	}

	// ---------------------------------------------------------------------
	// State
	// ---------------------------------------------------------------------

	function readState() {
		var raw = readCookie( COOKIE_NAME );
		if ( ! raw ) {
			return null;
		}
		try {
			var parsed = JSON.parse( raw );
			if ( ! parsed || ! parsed.cat || ! parsed.id ) {
				return null;
			}
			// A config-version mismatch means a category/purpose/vendor changed since this
			// visitor last decided — re-prompt (PART 1.6). A cosmetic-only settings change
			// never bumps configVersion server-side, so this never over-fires.
			if ( cfg.configVersion && parsed.cv !== cfg.configVersion ) {
				return null;
			}
			return parsed;
		} catch ( e ) {
			return null;
		}
	}

	var state = readState();

	function writeState( categories, action ) {
		var payload = {
			v: 1,
			cv: cfg.configVersion || 1,
			cat: categories,
			ts: new Date().toISOString(),
			id: ( state && state.id ) || randomId(),
		};
		writeCookie( COOKIE_NAME, JSON.stringify( payload ), cfg.reprompDays || 365 );
		state = payload;

		logToServer( categories, action, payload.id );
		syncWpConsentApi( categories );
		syncConsentMode( categories );
		activateGatedContent( categories );
		document.dispatchEvent( new CustomEvent( 'nexter:consent-change', { detail: getState() } ) );
	}

	// -----------------------------------------------------------------
	// WP Consent API sync (PART 10.1) — only runs when the wp-consent-api plugin's own
	// JS (which defines window.wp_set_consent) is present; otherwise a silent no-op.
	// -----------------------------------------------------------------

	function syncWpConsentApi( granted ) {
		if ( typeof window.wp_set_consent !== 'function' || ! cfg.wpConsentMap ) {
			return;
		}
		Object.keys( cfg.wpConsentMap ).forEach( function ( ourCategory ) {
			var apiCategory = cfg.wpConsentMap[ ourCategory ];
			window.wp_set_consent( apiCategory, granted.indexOf( ourCategory ) !== -1 ? 'allow' : 'deny' );
		} );
	}

	// -----------------------------------------------------------------
	// Google Consent Mode v2 (PART 10.2) — fires gtag('consent','update', ...) against
	// the same dataLayer the PHP-side default call (print_consent_mode_default()) already
	// initialized in <head>. Works identically for the GTM-managed and direct-gtag paths:
	// both read dataLayer pushes, so there is no separate branch for either.
	// -----------------------------------------------------------------

	function syncConsentMode( granted ) {
		if ( ! cfg.consentMode || ! cfg.consentMode.enabled ) {
			return;
		}
		window.dataLayer = window.dataLayer || [];
		if ( typeof window.gtag !== 'function' ) {
			window.gtag = function () {
				window.dataLayer.push( arguments );
			};
		}
		var update = {
			security_storage: 'granted', // Necessary — never gated (PART 1.4).
		};
		Object.keys( cfg.consentMode.signals ).forEach( function ( ourCategory ) {
			var signalState = granted.indexOf( ourCategory ) !== -1 ? 'granted' : 'denied';
			cfg.consentMode.signals[ ourCategory ].split( ',' ).forEach( function ( signal ) {
				update[ signal ] = signalState;
			} );
		} );
		window.gtag( 'consent', 'update', update );
	}

	// The page, and so cfg.nonce, can be served from a full-page cache for days; a nonce is only
	// good for ~24h. A stale one got a 403 that sendBeacon could not even see, so the cookie was
	// set but no record existed, with no sign anything had gone wrong.
	function freshNonce() {
		return fetch( cfg.ajaxUrl + ( cfg.ajaxUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'action=nexter_consent_nonce&_=' + Date.now(), {
			credentials: 'same-origin',
			cache: 'no-store',
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				return j && j.success && j.data && j.data.nonce ? j.data.nonce : '';
			} );
	}

	function reportLogFailure( status ) {
		try {
			window.dispatchEvent( new CustomEvent( 'nexter:consent-log-failed', { detail: { status: status } } ) );
		} catch ( e ) {}
		if ( window.console && console.warn ) {
			console.warn( 'Nexter consent: the decision was applied but could not be recorded (HTTP ' + status + ').' );
		}
	}

	function logToServer( categories, action, consentId ) {
		if ( ! cfg.recordsOn || ! cfg.ajaxUrl ) {
			return;
		}
		function send( nonce ) {
			var body = new URLSearchParams();
			body.set( 'action', 'nexter_consent_write' );
			body.set( 'nonce', nonce || '' );
			body.set( 'action_type', action );
			body.set( 'consent_id', consentId );
			categories.forEach( function ( c ) {
				body.append( 'categories[]', c );
			} );
			// fetch + keepalive rather than sendBeacon: both survive the page unloading, but only
			// fetch lets us see the status. Still fire-and-forget — nothing waits on this.
			return fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true } );
		}
		send( cfg.nonce )
			.then( function ( res ) {
				if ( res.ok ) {
					return;
				}
				if ( 403 !== res.status ) {
					reportLogFailure( res.status );
					return;
				}
				// Stale nonce: fetch a fresh one and retry exactly once.
				return freshNonce()
					.then( function ( n ) {
						if ( ! n ) {
							reportLogFailure( 403 );
							return;
						}
						cfg.nonce = n;
						return send( n ).then( function ( retry ) {
							if ( ! retry.ok ) {
								reportLogFailure( retry.status );
							}
						} );
					} );
			} )
			.catch( function () {
				reportLogFailure( 0 );
			} );
	}

	function grantedCategories() {
		return state && Array.isArray( state.cat ) ? state.cat : [];
	}

	// ---------------------------------------------------------------------
	// Method A companion — re-activate script_loader_tag-blocked <script> tags
	// ---------------------------------------------------------------------

	function activateGatedScripts( categories ) {
		var nodes = document.querySelectorAll( 'script[data-nexter-consent-category]' );
		// Re-inserted in original DOM order (PART 2.1) by walking the NodeList in document order,
		// which querySelectorAll already guarantees.
		nodes.forEach( function ( node ) {
			var category = node.getAttribute( 'data-nexter-consent-category' );
			if ( categories.indexOf( category ) === -1 ) {
				return;
			}
			var src = node.getAttribute( 'data-nexter-consent-src' );
			var real = document.createElement( 'script' );
			for ( var i = 0; i < node.attributes.length; i++ ) {
				var attr = node.attributes[ i ];
				if ( attr.name.indexOf( 'data-nexter-consent' ) === 0 || 'type' === attr.name ) {
					continue;
				}
				real.setAttribute( attr.name, attr.value );
			}
			if ( src ) {
				real.src = src;
			} else {
				real.text = node.text;
			}
			node.parentNode.replaceChild( real, node );
		} );
	}

	// ---------------------------------------------------------------------
	// Method C — MutationObserver for scripts injected after load (e.g. by GTM once it
	// itself has been granted). Best-effort only: a script inserted via appendChild starts
	// fetching the instant it enters the DOM, so this cannot guarantee interception before
	// the request fires — it only removes nodes matched to a non-granted category as fast
	// as the observer callback runs. Documented limitation, not a silent gap (PART 2.1/QA 2.10).
	// ---------------------------------------------------------------------

	function matchingRule( src ) {
		var rules = cfg.rules || [];
		for ( var r = 0; r < rules.length; r++ ) {
			var patterns = rules[ r ].patterns || [];
			for ( var p = 0; p < patterns.length; p++ ) {
				if ( patterns[ p ] && src.indexOf( patterns[ p ] ) !== -1 ) {
					return rules[ r ];
				}
			}
		}
		return null;
	}

	function startObserver() {
		if ( ! window.MutationObserver ) {
			return;
		}
		var observer = new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				mutation.addedNodes && mutation.addedNodes.forEach( function ( node ) {
					if ( node.nodeType !== 1 || 'SCRIPT' !== node.tagName || ! node.src ) {
						return;
					}
					var rule = matchingRule( node.src );
					if ( rule && 'never' !== rule.action && grantedCategories().indexOf( rule.category ) === -1 ) {
						node.parentNode && node.parentNode.removeChild( node );
					}
				} );
			} );
		} );
		observer.observe( document.documentElement, { childList: true, subtree: true } );
	}

	// ---------------------------------------------------------------------
	// Blocked-embed placeholders (iframes) — PART 2.4
	// ---------------------------------------------------------------------

	function categoryLabel( categoryId ) {
		var categories = cfg.categories || {};
		if ( categories[ categoryId ] && categories[ categoryId ].label ) {
			return categories[ categoryId ].label;
		}
		return categoryId;
	}

	function buildPlaceholder( iframe, rule ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'nxt-consent-placeholder';
		wrap.setAttribute( 'data-nexter-consent-category', rule.category );
		wrap.setAttribute( 'role', 'group' );
		wrap.setAttribute( 'aria-label', ( cfg.i18n && cfg.i18n.placeholderLabel ) || 'Blocked content' );
		wrap.nxtIframe = iframe; // kept so a grant made anywhere can put the real embed back

		var text = document.createElement( 'p' );
		text.textContent = ( cfg.i18n && cfg.i18n.placeholderText || 'This content is blocked until you accept %s cookies.' ).replace( '%s', categoryLabel( rule.category ) );
		wrap.appendChild( text );

		var acceptBtn = document.createElement( 'button' );
		acceptBtn.type = 'button';
		acceptBtn.className = 'nxt-consent-placeholder-accept';
		acceptBtn.textContent = ( cfg.i18n && cfg.i18n.acceptShow ) || 'Accept and show';
		acceptBtn.addEventListener( 'click', function () {
			// Grants ONLY this one category, not the full set (PART 2.4) — never call accept().
			var next = grantedCategories().slice();
			if ( next.indexOf( rule.category ) === -1 ) {
				next.push( rule.category );
			}
			if ( next.indexOf( 'necessary' ) === -1 ) {
				next.push( 'necessary' );
			}
			writeState( next, 'custom' );
			wrap.parentNode.replaceChild( iframe, wrap );
			iframe.removeAttribute( 'data-nexter-consent-pending' );
			// The clicked button is removed along with wrap — an iframe is itself focusable,
			// so land there rather than letting focus silently fall back to <body>.
			if ( iframe.focus ) {
				iframe.setAttribute( 'tabindex', iframe.hasAttribute( 'tabindex' ) ? iframe.getAttribute( 'tabindex' ) : '-1' );
				iframe.focus();
			}
		} );
		wrap.appendChild( acceptBtn );

		var settingsBtn = document.createElement( 'button' );
		settingsBtn.type = 'button';
		settingsBtn.className = 'nxt-consent-placeholder-settings';
		settingsBtn.textContent = ( cfg.i18n && cfg.i18n.cookieSettings ) || 'Cookie settings';
		settingsBtn.addEventListener( 'click', function () {
			api.openPreferences();
		} );
		wrap.appendChild( settingsBtn );

		return wrap;
	}

	function applyPlaceholders() {
		var iframes = document.querySelectorAll( 'iframe[src]:not([data-nexter-consent-checked])' );
		iframes.forEach( function ( iframe ) {
			iframe.setAttribute( 'data-nexter-consent-checked', '1' );
			var rule = matchingRule( iframe.src );
			if ( ! rule || 'block_placeholder' !== rule.action ) {
				return;
			}
			if ( grantedCategories().indexOf( rule.category ) !== -1 ) {
				return;
			}
			var placeholder = buildPlaceholder( iframe, rule );
			iframe.setAttribute( 'data-nexter-consent-pending', rule.category );
			iframe.parentNode.replaceChild( placeholder, iframe );
		} );
	}

	// ---------------------------------------------------------------------
	// Public API — window.nexterConsent (PART 1.2)
	// ---------------------------------------------------------------------

	function getState() {
		return {
			categories: grantedCategories(),
			version: cfg.configVersion || 1,
			timestamp: state ? state.ts : null,
			id: state ? state.id : null,
		};
	}

	function allCategoryIds() {
		return Object.keys( cfg.categories || {} ).length ? Object.keys( cfg.categories ) : CATEGORY_IDS;
	}

	// Puts back every blocked embed whose category has since been granted, whichever control did
	// the granting. applyPlaceholders() only ever BUILDS placeholders for iframes it has not seen,
	// and the original iframe was reachable solely from the placeholder's own "Accept and show"
	// button — so the banner's Accept All left every embed blocked until the page was reloaded.
	function restorePlaceholders( categories ) {
		document.querySelectorAll( '.nxt-consent-placeholder' ).forEach( function ( wrap ) {
			var iframe = wrap.nxtIframe;
			if ( ! iframe || ! wrap.parentNode || categories.indexOf( wrap.getAttribute( 'data-nexter-consent-category' ) ) === -1 ) {
				return;
			}
			wrap.parentNode.replaceChild( iframe, wrap );
			iframe.removeAttribute( 'data-nexter-consent-pending' );
		} );
	}

	function activateGatedContent( categories ) {
		activateGatedScripts( categories );
		applyPlaceholders();
		restorePlaceholders( categories );
	}

	var api = {
		accept: function () {
			writeState( allCategoryIds(), 'accept_all' );
		},
		rejectAll: function () {
			// Necessary can never be turned off (PART 1.4/QA 4.10) — always included.
			writeState( [ 'necessary' ], 'reject_all' );
		},
		acceptCategories: function ( ids ) {
			var next = ( ids || [] ).slice();
			if ( next.indexOf( 'necessary' ) === -1 ) {
				next.push( 'necessary' );
			}
			writeState( next, 'custom' );
		},
		openPreferences: function () {
			document.dispatchEvent( new CustomEvent( 'nexter:open-preferences' ) );
		},
		closePreferences: function () {
			// Closing/Escape must NEVER record consent (QA 4.3/4.5) — this fires no state change.
			document.dispatchEvent( new CustomEvent( 'nexter:close-preferences' ) );
		},
		getState: getState,
		// Alias only. getState() is and remains the real name; getConsent() exists because
		// it was published in the architecture document before anyone checked it against
		// the code, so integrators may have written it. Keeping both costs one line and
		// turns a TypeError in someone else's site into a working call.
		getConsent: getState,
		hasConsent: function ( categoryId ) {
			return grantedCategories().indexOf( categoryId ) !== -1;
		},
		revoke: function () {
			var previousId = state && state.id;
			writeCookie( COOKIE_NAME, '', -1 );
			state = null;
			if ( previousId ) {
				logToServer( [], 'revoke', previousId );
			}
			document.dispatchEvent( new CustomEvent( 'nexter:consent-change', { detail: getState() } ) );
		},
		reconsent: function () {
			api.revoke();
			document.dispatchEvent( new CustomEvent( 'nexter:consent-ready' ) );
		},
	};

	window.nexterConsent = api;

	// ---------------------------------------------------------------------
	// Generic banner surface contract — the ONLY thing any banner markup (Nexter
	// Blocks' block, the emergency fallback, a future Elementor widget) has to do is
	// use these data attributes; none of them may contain their own consent logic
	// (PART 1.1). This is the single shared implementation every surface relies on.
	//
	//   [data-nexter-banner]              shown when no valid consent exists, hidden after
	//   [data-nexter-preferences-modal]   shown on openPreferences(), hidden on close/decide
	//   [data-nexter-action="accept"]              -> api.accept()
	//   [data-nexter-action="reject"]              -> api.rejectAll()
	//   [data-nexter-action="preferences"]         -> api.openPreferences()
	//   [data-nexter-action="close-preferences"]   -> api.closePreferences() (never consents)
	//   [data-nexter-action="save-preferences"]    -> reads every checked
	//                                                 [data-nexter-category-toggle] on the
	//                                                 page and calls acceptCategories()
	//   [data-nexter-category-toggle="analytics"]  a checkbox reflecting/setting one category
	//
	// While a preferences modal is open this also owns its keyboard behaviour: focus is
	// moved into the dialog, Tab is cycled inside it, Escape closes it without consenting,
	// and focus returns to whatever opened it. A surface gets that for free by using the
	// attributes above — it must not implement its own, or the two fight over focus.
	// ---------------------------------------------------------------------

	function toggleHidden( selector, shouldShow ) {
		document.querySelectorAll( selector ).forEach( function ( el ) {
			el.hidden = ! shouldShow;
		} );
	}

	function refreshBannerVisibility() {
		toggleHidden( '[data-nexter-banner]', ! state );
	}

	function syncCategoryToggles() {
		var granted = grantedCategories();
		document.querySelectorAll( '[data-nexter-category-toggle]' ).forEach( function ( el ) {
			var id = el.getAttribute( 'data-nexter-category-toggle' );
			if ( 'necessary' === id ) {
				el.checked = true;
				el.disabled = true;
				return;
			}
			el.checked = granted.indexOf( id ) !== -1;
		} );
	}

	var FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
	var lastFocused = null;
	var liveRegion = null;

	// A single persistent visually-hidden live region, reused for every announcement, so a
	// screen reader isn't asked to track a node that keeps appearing/disappearing.
	function announce( text ) {
		if ( ! text ) {
			return;
		}
		if ( ! liveRegion ) {
			liveRegion = document.createElement( 'div' );
			liveRegion.className = 'screen-reader-text';
			liveRegion.setAttribute( 'role', 'status' );
			liveRegion.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( liveRegion );
		}
		liveRegion.textContent = '';
		// Clearing and re-setting on the next tick forces a re-announcement even when the text
		// (e.g. "Preferences saved.") is identical to the last one.
		window.setTimeout( function () {
			liveRegion.textContent = text;
		}, 50 );
	}

	// Background inert/aria-hidden while a preferences modal is open (WCAG 1.3.2/4.1.2) — a
	// screen-reader user tabbing past the modal must not land in page content behind it.
	var inertedSiblings = [];

	function setBackgroundInert( modal, shouldHide ) {
		if ( shouldHide ) {
			inertedSiblings = Array.prototype.filter.call( document.body.children, function ( el ) {
				return el !== modal && ! el.contains( modal ) && ! el.hasAttribute( 'inert' );
			} );
			inertedSiblings.forEach( function ( el ) {
				el.setAttribute( 'inert', '' );
				el.setAttribute( 'aria-hidden', 'true' );
			} );
		} else {
			inertedSiblings.forEach( function ( el ) {
				el.removeAttribute( 'inert' );
				el.removeAttribute( 'aria-hidden' );
			} );
			inertedSiblings = [];
		}
	}

	function restoreFocus() {
		// The decision just made can hide/replace the control that opened the dialog (e.g. the
		// banner's own Accept/Reject button gets hidden once consent exists), so a stale
		// reference must not be focused — fall back to <body> rather than silently dropping focus.
		var canRefocus =
			lastFocused &&
			lastFocused.focus &&
			document.contains( lastFocused ) &&
			null !== lastFocused.offsetParent &&
			! lastFocused.disabled;
		if ( canRefocus ) {
			lastFocused.focus();
		} else if ( document.body ) {
			document.body.setAttribute( 'tabindex', '-1' );
			document.body.focus();
			document.body.removeAttribute( 'tabindex' );
		}
		lastFocused = null;
	}

	function openModals() {
		return Array.prototype.filter.call(
			document.querySelectorAll( '[data-nexter-preferences-modal]' ),
			function ( el ) {
				return ! el.hidden;
			}
		);
	}

	// A modal dialog that cannot be dismissed from the keyboard is a keyboard trap
	// (WCAG 2.1.2), and the visitor is trapped in a dialog asking them for consent —
	// which is exactly where being stuck is least acceptable. Escape closes it and
	// never records a decision, same as the close button and the backdrop.
	function trapFocus( event ) {
		var modal = openModals()[ 0 ];
		if ( ! modal ) {
			return;
		}

		if ( 'Escape' === event.key || 'Esc' === event.key ) {
			event.preventDefault();
			api.closePreferences();
			return;
		}

		if ( 'Tab' !== event.key ) {
			return;
		}

		var items = Array.prototype.filter.call( modal.querySelectorAll( FOCUSABLE ), function ( el ) {
			return null !== el.offsetParent;
		} );
		if ( ! items.length ) {
			return;
		}

		var first = items[ 0 ];
		var last = items[ items.length - 1 ];

		if ( event.shiftKey && ( document.activeElement === first || ! modal.contains( document.activeElement ) ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		} else if ( ! modal.contains( document.activeElement ) ) {
			event.preventDefault();
			first.focus();
		}
	}

	function initBannerContract() {
		document.addEventListener( 'nexter:open-preferences', function () {
			lastFocused = document.activeElement;
			toggleHidden( '[data-nexter-preferences-modal]', true );
			syncCategoryToggles();

			// Focus has to land inside the dialog or a screen-reader user is never told
			// it opened; the close button is the safest landing spot because reaching it
			// can only ever dismiss, never consent.
			var modal = openModals()[ 0 ];
			if ( modal ) {
				setBackgroundInert( modal, true );
				// Must be button[...], not [...]: the backdrop div carries the same action
				// attribute and comes first in DOM order, and calling focus() on a plain
				// div silently does nothing — focus stayed on <body> and the dialog was
				// never announced.
				var target =
					modal.querySelector( 'button[data-nexter-action="close-preferences"]' ) ||
					modal.querySelector( FOCUSABLE );
				if ( target && target.focus ) {
					target.focus();
				}
			}
		} );
		document.addEventListener( 'nexter:close-preferences', function () {
			toggleHidden( '[data-nexter-preferences-modal]', false );
			setBackgroundInert( null, false );
			restoreFocus();
		} );
		document.addEventListener( 'nexter:consent-change', function () {
			refreshBannerVisibility();
			toggleHidden( '[data-nexter-preferences-modal]', false );
			setBackgroundInert( null, false );
			restoreFocus();
			announce( ( cfg.i18n && cfg.i18n.consentSaved ) || 'Your cookie preferences have been saved.' );
		} );

		document.addEventListener( 'keydown', trapFocus );

		document.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest( '[data-nexter-action]' );
			if ( ! trigger ) {
				return;
			}
			var action = trigger.getAttribute( 'data-nexter-action' );
			if ( 'accept' === action ) {
				api.accept();
			} else if ( 'reject' === action ) {
				api.rejectAll();
			} else if ( 'preferences' === action ) {
				api.openPreferences();
			} else if ( 'close-preferences' === action ) {
				// Escape/close/backdrop must never record consent (QA 4.3/4.5).
				api.closePreferences();
			} else if ( 'save-preferences' === action ) {
				var chosen = [];
				document.querySelectorAll( '[data-nexter-category-toggle]:checked' ).forEach( function ( el ) {
					chosen.push( el.getAttribute( 'data-nexter-category-toggle' ) );
				} );
				api.acceptCategories( chosen );
			}
		} );
	}

	// ---------------------------------------------------------------------
	// Boot
	// ---------------------------------------------------------------------

	function boot() {
		if ( state ) {
			syncWpConsentApi( grantedCategories() );
			syncConsentMode( grantedCategories() );
			activateGatedContent( grantedCategories() );
		} else {
			// No valid consent yet — necessary-only scripts (if any use the same gating
			// attribute for consistency in the admin UI) still activate immediately.
			activateGatedScripts( [ 'necessary' ] );
			applyPlaceholders();
		}
		initBannerContract();
		refreshBannerVisibility();
		syncCategoryToggles();
		startObserver();
		document.dispatchEvent( new CustomEvent( 'nexter:consent-ready', { detail: getState() } ) );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )( window, document );
