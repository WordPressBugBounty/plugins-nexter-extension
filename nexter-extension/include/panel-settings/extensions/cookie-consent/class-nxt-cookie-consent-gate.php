<?php
/**
 * Cookie Consent — Custom Code snippet gating.
 *
 * A snippet assigned to a non-necessary category must not be present in the page
 * source at all until consent is granted, not merely inert. That is only decidable
 * by reading the visitor's own `nexter_consent` cookie on this request, which makes
 * any page containing a gated snippet cache-variant by nature — a full-page cache
 * must exclude pages that use gated snippets, same exclusion class as a cart/checkout
 * page.
 *
 * @package Nexter_Extension
 */

defined( 'ABSPATH' ) || exit;

class Nxt_Cookie_Consent_Gate {

	/**
	 * Category IDs that never require consent. 'always' is a snippet-only value (not a
	 * consent category) meaning "unrelated to cookies at all" — kept distinct from
	 * 'necessary' so Compliance Check can report on necessary-categorised snippets too.
	 *
	 * @var string[]
	 */
	private static $never_gated = array( 'always', 'necessary', '' );

	/**
	 * Whether a snippet assigned to $category is allowed to run/output on this request.
	 *
	 * @param string $category One of: always, necessary, functional, analytics, marketing.
	 * @return bool
	 */
	public static function is_allowed( $category ) {
		$category = is_string( $category ) ? $category : 'always';

		if ( in_array( $category, self::$never_gated, true ) ) {
			return true;
		}

		$granted = self::granted_categories();
		return in_array( $category, $granted, true );
	}

	/**
	 * Reads and validates the visitor's `nexter_consent` cookie for this request.
	 * Never trusts the raw cookie value past json_decode + a strict category allowlist —
	 * it is attacker-controlled input from every visitor.
	 *
	 * @return string[] Granted category ids, or empty array when no valid consent exists.
	 */
	private static function granted_categories() {
		static $granted = null;
		if ( null !== $granted ) {
			return $granted;
		}

		// The engine only loads when the module is on (or in admin); a gated snippet still needs its cookie name.
		if ( ! class_exists( 'Nxt_Cookie_Consent' ) ) {
			require_once __DIR__ . '/class-nxt-cookie-consent.php';
		}

		// Same name the JS writes under, or a gated snippet would read a different site's consent.
		$name = Nxt_Cookie_Consent::cookie_name();
		if ( empty( $_COOKIE[ $name ] ) ) {
			$granted = array();
			return $granted;
		}

		$raw = wp_unslash( $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || empty( $decoded['cat'] ) || ! is_array( $decoded['cat'] ) ) {
			$granted = array();
			return $granted;
		}

		$granted = array_values( array_intersect( Nxt_Cookie_Consent::CATEGORY_IDS, array_map( 'sanitize_key', $decoded['cat'] ) ) );
		return $granted;
	}
}
