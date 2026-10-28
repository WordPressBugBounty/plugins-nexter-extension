/**
 * Cookie Consent — "Scan Now" admin orchestrator.
 *
 * Registered (not enqueued) by class-nxt-cookie-consent.php; the Cookie Consent admin
 * screen enqueues this by handle (`nxt-cookie-consent-scanner-admin`) wherever it renders
 * the "Scan Now" button. Loads each target page in a hidden same-origin iframe — the
 * page's own nexter-consent-scanner.js (recognising `?nxt_consent_scan=1`) collects and
 * postMessages the results back here, which are aggregated into ONE report request
 * (02-DEVELOPER-BRIEF.md PART 3.1 "Active mode").
 *
 * Exposes `window.NexterConsentScanner.scanNow(onProgress)` for the admin UI to call.
 */
( function ( window, document ) {
	'use strict';

	var cfg = window.nexterConsentScannerAdminConfig || {};

	function fetchTargets() {
		var body = new URLSearchParams();
		body.set( 'action', 'nexter_consent_scan_targets' );
		body.set( 'nonce', cfg.nonce || '' );
		return fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'nexter_consent_scan_targets: HTTP ' + r.status );
				}
				return r.json();
			} )
			.then( function ( json ) { return ( json && json.success && json.data && json.data.targets ) || []; } );
	}

	function scanOnePage( target ) {
		return new Promise( function ( resolve ) {
			var separator = target.url.indexOf( '?' ) === -1 ? '?' : '&';
			var iframe = document.createElement( 'iframe' );
			iframe.style.display = 'none';
			iframe.setAttribute( 'aria-hidden', 'true' );

			var settled = false;
			var timeout = window.setTimeout( function () {
				finish( { cookies: [], localStorage: [], sessionStorage: [], thirdPartyDomains: [] } );
			}, 8000 ); // A page that never loads (dead link, blocked by the host) must not hang the whole scan.

			function onMessage( event ) {
				if ( event.origin !== window.location.origin || ! event.data || 'nexterConsentScanResult' !== event.data.type ) {
					return;
				}
				finish( event.data.payload );
			}

			function finish( payload ) {
				if ( settled ) {
					return;
				}
				settled = true;
				window.clearTimeout( timeout );
				window.removeEventListener( 'message', onMessage );
				iframe.remove();
				resolve( payload );
			}

			window.addEventListener( 'message', onMessage );
			iframe.src = target.url + separator + 'nxt_consent_scan=1';
			document.body.appendChild( iframe );
		} );
	}

	function mergePayloads( payloads ) {
		var merged = { cookies: [], localStorage: [], sessionStorage: [], thirdPartyDomains: [] };
		payloads.forEach( function ( p ) {
			Object.keys( merged ).forEach( function ( key ) {
				merged[ key ] = merged[ key ].concat( ( p && p[ key ] ) || [] );
			} );
		} );
		Object.keys( merged ).forEach( function ( key ) {
			merged[ key ] = Array.from( new Set( merged[ key ] ) );
		} );
		return merged;
	}

	function reportAggregate( merged ) {
		var body = new URLSearchParams();
		body.set( 'action', 'nexter_consent_scan_report' );
		body.set( 'nonce', cfg.nonce || '' );
		body.set( 'source', 'active' );
		[ [ 'cookies', merged.cookies ], [ 'localStorage', merged.localStorage ], [ 'sessionStorage', merged.sessionStorage ], [ 'domains', merged.thirdPartyDomains ] ].forEach( function ( pair ) {
			pair[ 1 ].forEach( function ( value ) {
				body.append( pair[ 0 ] + '[]', value );
			} );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'nexter_consent_scan_report: HTTP ' + r.status );
			}
			return r.json();
		} );
	}

	/**
	 * @param {function(number,number)} [onProgress] Called after each page as (done, total).
	 * @return {Promise<object>} Resolves with the server's aggregate report response.
	 */
	function scanNow( onProgress ) {
		return fetchTargets().then( function ( targets ) {
			var results = [];
			var done = 0;
			return targets
				.reduce( function ( chain, target ) {
					return chain.then( function () {
						return scanOnePage( target ).then( function ( payload ) {
							results.push( payload );
							done++;
							if ( typeof onProgress === 'function' ) {
								onProgress( done, targets.length );
							}
						} );
					} );
				}, Promise.resolve() )
				.then( function () {
					return reportAggregate( mergePayloads( results ) );
				} );
		} );
	}

	window.NexterConsentScanner = { scanNow: scanNow };
} )( window, document );
