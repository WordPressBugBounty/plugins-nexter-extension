/**
 * Cookie Consent — scanner collector.
 *
 * Admin-only (PHP only ever enqueues this for a logged-in manage_options user — see
 * maybe_enqueue_scanner() in class-nxt-cookie-consent.php). Never loads for a visitor.
 *
 * Two modes, same collector logic (02-DEVELOPER-BRIEF.md PART 3.1):
 *   - Passive: a normal page view by an admin — reports straight to the server.
 *   - Active-scan collector: the page was loaded inside a hidden iframe by the
 *     "Scan Now" admin orchestrator (nexter-consent-scanner-admin.js), identified by the
 *     `?nxt_consent_scan=1` query param it appends — postMessages the parent instead of
 *     reporting itself, so the parent can aggregate all pages into ONE server request.
 *
 * Nothing collected here is ever sent anywhere except this site's own admin-ajax.php.
 */
( function ( window, document ) {
	'use strict';

	var cfg = window.nexterConsentScannerConfig || {};
	var THROTTLE_KEY = 'nexterConsentScanLastReport';
	var THROTTLE_MS = 60 * 60 * 1000; // Once per hour of passive browsing is plenty.

	function isActiveScanFrame() {
		try {
			return /[?&]nxt_consent_scan=1(&|$)/.test( window.location.search );
		} catch ( e ) {
			return false;
		}
	}

	function collectCookieNames() {
		if ( ! document.cookie ) {
			return [];
		}
		return document.cookie.split( ';' )
			.map( function ( pair ) {
				return pair.split( '=' )[ 0 ].trim();
			} )
			.filter( Boolean );
	}

	function collectStorageKeys( storage ) {
		var keys = [];
		try {
			for ( var i = 0; i < storage.length; i++ ) {
				keys.push( storage.key( i ) );
			}
		} catch ( e ) {
			// Storage can throw in a locked-down iframe/incognito context — fail silent,
			// a scan should never break the page it's scanning.
		}
		return keys;
	}

	function collectThirdPartyDomains() {
		var own = window.location.hostname;
		var domains = {};
		try {
			( window.performance && window.performance.getEntriesByType( 'resource' ) || [] ).forEach( function ( entry ) {
				try {
					var host = new URL( entry.name ).hostname;
					if ( host && host !== own ) {
						domains[ host ] = true;
					}
				} catch ( e ) {}
			} );
		} catch ( e ) {}
		return Object.keys( domains );
	}

	function collect() {
		return {
			cookies: collectCookieNames(),
			localStorage: collectStorageKeys( window.localStorage ),
			sessionStorage: collectStorageKeys( window.sessionStorage ),
			thirdPartyDomains: collectThirdPartyDomains(),
		};
	}

	function report( data, source ) {
		if ( ! cfg.ajaxUrl ) {
			return;
		}
		var body = new URLSearchParams();
		body.set( 'action', 'nexter_consent_scan_report' );
		body.set( 'nonce', cfg.nonce || '' );
		body.set( 'source', source );
		[ [ 'cookies', data.cookies ], [ 'localStorage', data.localStorage ], [ 'sessionStorage', data.sessionStorage ], [ 'domains', data.thirdPartyDomains ] ].forEach( function ( pair ) {
			pair[ 1 ].forEach( function ( value ) {
				body.append( pair[ 0 ] + '[]', value );
			} );
		} );
		fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).catch( function () {} );
	}

	if ( isActiveScanFrame() ) {
		// Collector inside a hidden iframe — hand data to the parent, never AJAX directly,
		// so "Scan Now" produces one aggregated request instead of one per scanned page.
		var data = collect();
		try {
			window.parent.postMessage( { type: 'nexterConsentScanResult', payload: data }, window.location.origin );
		} catch ( e ) {}
		return;
	}

	// Passive mode — throttled so browsing the admin's own site doesn't fire a request
	// on every single page view.
	var last = 0;
	try {
		last = parseInt( window.sessionStorage.getItem( THROTTLE_KEY ) || '0', 10 );
	} catch ( e ) {}
	if ( Date.now() - last < THROTTLE_MS ) {
		return;
	}
	try {
		window.sessionStorage.setItem( THROTTLE_KEY, String( Date.now() ) );
	} catch ( e ) {}
	report( collect(), 'passive' );
} )( window, document );
