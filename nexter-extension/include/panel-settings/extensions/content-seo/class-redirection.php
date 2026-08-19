<?php
/**
 * Content SEO – URL redirection rules (REST + frontend redirect).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Redirection
 */
class Nexter_Content_SEO_Redirection {

	const OPTION_RULES = 'nexter_content_seo_redirect_rules';
	const OPTION_AUTO  = 'nexter_content_seo_auto_redirect';

	/**
	 * Allowed redirect HTTP status codes. 301/302/307/308 issue a Location redirect; 410 (Gone)
	 * and 451 (Unavailable For Legal Reasons) are content-gone responses with NO Location header.
	 */
	const ALLOWED_STATUS_CODES = array( 301, 302, 307, 308, 410, 451 );

	/** Status codes that return a "content gone" response and ignore the destination URL. */
	const GONE_STATUS_CODES = array( 410, 451 );

	const DEFAULT_STATUS_CODE = 301;

	/** Maximum redirect rules that can be stored, guarding unbounded growth. Filterable. */
	const MAX_RULES = 2000;

	/** Transient holding the compiled/filtered rule set used by the front-end matcher. */
	const COMPILED_CACHE_KEY = 'nxt_content_seo_redirect_compiled';

	/**
	 * Bootstrap hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_apply_redirects' ), 1 );
		add_action( 'post_updated', array( __CLASS__, 'handle_post_url_change' ), 10, 3 );
		add_action( 'edit_term', array( __CLASS__, 'capture_old_term_url' ), 10, 2 );
		add_action( 'edited_term', array( __CLASS__, 'handle_term_url_change' ), 10, 3 );
	}

	/**
	 * Temporary storage for old term URLs during an update.
	 *
	 * @var array<int, string>
	 */
	private static $old_term_urls = array();

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_rules() {
		$raw = get_option( self::OPTION_RULES, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param array<int, array<string, mixed>> $rules Rules.
	 */
	public static function save_rules( $rules ) {
		// Autoload NO: rules are needed only on front-end template_redirect, not on every admin /
		// ajax / cron request, so keeping them out of the always-loaded options payload avoids
		// bloating it site-wide (the concern with autoloading a large rule set). The front-end
		// matcher reads a compiled transient (see get_compiled_rules) rather than this option
		// directly, so an object cache serves it with no per-request SELECT.
		update_option( self::OPTION_RULES, array_values( $rules ), false );
		delete_transient( self::COMPILED_CACHE_KEY );
	}

	/**
	 * @return bool
	 */
	public static function get_auto_redirect() {
		return (bool) get_option( self::OPTION_AUTO, false );
	}

	/**
	 * @param bool $on Auto redirect master switch.
	 */
	public static function set_auto_redirect( $on ) {
		// Autoload: consulted on every post/term update; bool is tiny.
		update_option( self::OPTION_AUTO, (bool) $on, true );
	}

	/**
	 * Normalize URL or path fragment to absolute URL string.
	 *
	 * @param string $url Raw input.
	 * @return string
	 */
	private static function normalize_url_field( $url ) {
		$url = trim( wp_unslash( (string) $url ) );
		if ( '' === $url ) {
			return '';
		}
		// Constrain to http/https only. External hosts ARE allowed by design (see
		// maybe_apply_redirects), but dangerous schemes (javascript:, data:, vbscript:) and
		// protocol-relative ("//evil.com") destinations are rejected outright.
		$protocols = array( 'http', 'https' );
		if ( preg_match( '#^//#', $url ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return esc_url_raw( $url, $protocols );
		}
		if ( preg_match( '#^/#', $url ) ) {
			return esc_url_raw( home_url( $url ), $protocols );
		}
		// A scheme that is not http/https (javascript:, data:, mailto:, tel:, ftp:, …).
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) ) {
			return '';
		}
		// Bare relative path ("old/page") — resolve against the site root.
		return esc_url_raw( home_url( '/' . ltrim( $url, '/' ) ), $protocols );
	}

	/**
	 * Sanitize one rule.
	 *
	 * @param array<string, mixed> $rule Rule.
	 * @return array<string, mixed>|null
	 */
	public static function sanitize_rule( $rule ) {
		if ( ! is_array( $rule ) ) {
			return null;
		}
		$id = isset( $rule['id'] ) ? sanitize_key( (string) $rule['id'] ) : '';
		if ( '' === $id ) {
			$id = 'r_' . wp_generate_password( 12, false, false );
		}
		$condition_whitelist = array( 'exact_match', 'contains', 'starts_with', 'ends_with' );
		$cond                = isset( $rule['condition'] ) ? sanitize_key( (string) $rule['condition'] ) : 'exact_match';
		if ( ! in_array( $cond, $condition_whitelist, true ) ) {
			$cond = 'exact_match';
		}
		$query_whitelist = array( '', 'match_any_order', 'ignore_all', 'ignore_pass' );
		$qp              = isset( $rule['query_params'] ) ? sanitize_key( (string) $rule['query_params'] ) : '';
		if ( ! in_array( $qp, $query_whitelist, true ) ) {
			$qp = '';
		}
		$status_code = isset( $rule['status_code'] ) ? (int) $rule['status_code'] : self::DEFAULT_STATUS_CODE;
		if ( ! in_array( $status_code, self::ALLOWED_STATUS_CODES, true ) ) {
			$status_code = self::DEFAULT_STATUS_CODE;
		}
		return array(
			'id'           => $id,
			'enabled'      => ! empty( $rule['enabled'] ),
			'from_url'     => self::normalize_url_field( isset( $rule['from_url'] ) ? $rule['from_url'] : '' ),
			'to_url'       => self::normalize_url_field( isset( $rule['to_url'] ) ? $rule['to_url'] : '' ),
			'condition'    => $cond,
			'query_params' => $qp,
			'status_code'  => $status_code,
		);
	}

	/**
	 * @param array<string, mixed> $rule Rule.
	 * @param string               $request_path Request path (no domain, leading slash).
	 * @return bool
	 */
	public static function rule_matches_request( $rule, $request_path, $request_query = '' ) {
		$compare_from = self::rule_compare_from( $rule );
		if ( '' === $compare_from ) {
			return false;
		}
		// Mirror rule_compare_from()'s query handling on the REQUEST side so both are comparable.
		// rule_compare_from() appends "?query" only when the rule's source URL carries a query and
		// the rule is not in an ignore-query mode (in which case it has no "?"). Match that here:
		// include the request query only when the rule's compare string has one; otherwise compare
		// path-only, so a query-less rule still matches regardless of the request's query string.
		// (Previously the request was always path-only, so any rule whose source had a query string
		// could never match.)
		$from_has_query = ( false !== strpos( $compare_from, '?' ) );
		$compare_path   = ( $from_has_query && '' !== (string) $request_query )
			? $request_path . '?' . $request_query
			: $request_path;

		// 'Exactly Match In Any Order' was falling through to the ignore-query branch, so it matched
		// any query at all. Compare the parsed parameters as a set instead.
		$qp_mode = isset( $rule['query_params'] ) ? sanitize_key( (string) $rule['query_params'] ) : '';
		if ( 'match_any_order' === $qp_mode ) {
			$rule_query = (string) wp_parse_url( (string) $rule['from_url'], PHP_URL_QUERY );
			$want = array();
			$got  = array();
			wp_parse_str( $rule_query, $want );
			wp_parse_str( (string) $request_query, $got );
			ksort( $want );
			ksort( $got );
			if ( $want != $got ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				return false;
			}
		}

		$cond = isset( $rule['condition'] ) ? $rule['condition'] : 'exact_match';
		switch ( $cond ) {
			case 'contains':
				return strpos( $compare_path, $compare_from ) !== false;
			case 'starts_with':
				return strpos( $compare_path, $compare_from ) === 0;
			case 'ends_with':
				return strlen( $compare_from ) > 0 && substr( $compare_path, -strlen( $compare_from ) ) === $compare_from;
			case 'exact_match':
			default:
				return $compare_path === $compare_from || trim( $compare_path, '/' ) === trim( $compare_from, '/' );
		}
	}

	/**
	 * The normalized "from" string a rule compares against: the source path (leading slash) plus
	 * its query string, unless the rule's query_params mode ignores queries. Shared by the matcher
	 * and the compiled exact-match index so both derive the same key.
	 *
	 * @param array $rule Rule definition.
	 * @return string Empty string when the rule has no source URL.
	 */
	private static function rule_compare_from( $rule ) {
		$from = isset( $rule['from_url'] ) ? (string) $rule['from_url'] : '';
		if ( '' === $from ) {
			return '';
		}
		$parsed = wp_parse_url( $from );
		$path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}
		$query = isset( $parsed['query'] ) ? $parsed['query'] : '';
		$qp    = isset( $rule['query_params'] ) ? sanitize_key( (string) $rule['query_params'] ) : '';
		if ( 'ignore_all' === $qp || 'ignore_pass' === $qp || 'match_any_order' === $qp ) {
			return $path;
		}
		return $path . ( $query ? '?' . $query : '' );
	}

	/**
	 * Run configured redirects on the front end (per-rule status code, 301 default).
	 * Master switch gates rule application; rules themselves can be individually disabled.
	 */
	public static function maybe_apply_redirects() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$compiled = self::get_compiled_rules();
		if ( empty( $compiled['enabled'] ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
		// sanitize_text_field to strip control chars while preserving percent-encoding.
		$uri  = (string) sanitize_text_field( $uri );
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}
		$query = wp_parse_url( $uri, PHP_URL_QUERY );
		$query = is_string( $query ) ? $query : '';

		// Fast path: when every enabled rule is an exact match, resolve via the O(1) path index
		// instead of an O(N) scan. Precedence is preserved — the index stores the first rule
		// registered for each key, mirroring first-match-wins in the linear scan.
		// A rule whose source URL includes a query string is keyed "path?query" (see
		// rule_compare_from), so try the query-specific key first, then fall back to the path-only
		// key (rules whose source had no query still match regardless of the request query).
		if ( ! empty( $compiled['all_exact'] ) ) {
			$keys = array();
			if ( '' !== $query ) {
				$keys[] = trim( $path, '/' ) . '?' . $query;
			}
			$keys[] = trim( $path, '/' );
			foreach ( $keys as $key ) {
				if ( isset( $compiled['exact_index'][ $key ] ) ) {
					self::apply_rule( $compiled['exact_index'][ $key ], $path, $query );
					return;
				}
			}
			return;
		}

		// Mixed rule set (contains / starts_with / ends_with present): scan the cached ENABLED
		// subset in order. Disabled rules were already filtered out at compile time.
		foreach ( $compiled['enabled'] as $rule ) {
			if ( ! self::rule_matches_request( $rule, $path, $query ) ) {
				continue;
			}
			// apply_rule() exits on a fired redirect / 410; it only returns here when the
			// destination is empty or unsafe, so fall through and try the next matching rule.
			self::apply_rule( $rule, $path, $query );
		}
	}

	/**
	 * Execute a single matched rule: emit a 410/451 Gone response, or issue the redirect (exits on
	 * success). Returns false — without exiting — only when the destination is empty or unsafe, so
	 * the caller can try the next matching rule.
	 *
	 * @param array  $rule Rule definition.
	 * @param string $path Matched request path.
	 * @return false
	 */
	private static function apply_rule( $rule, $path, $request_query = '' ) {
		$status = isset( $rule['status_code'] ) ? (int) $rule['status_code'] : self::DEFAULT_STATUS_CODE;
		if ( ! in_array( $status, self::ALLOWED_STATUS_CODES, true ) ) {
			$status = self::DEFAULT_STATUS_CODE;
		}

		// 410 Gone / 451 Unavailable For Legal Reasons: emit the status with no Location header.
		if ( in_array( $status, self::GONE_STATUS_CODES, true ) ) {
			do_action( 'nexter_content_seo_before_redirect', $rule, '', $status, $path );
			status_header( $status );
			nocache_headers();
			exit;
		}

		$to = isset( $rule['to_url'] ) ? esc_url_raw( (string) $rule['to_url'] ) : '';

		// 'Ignore And Pass Parameters To Target' only ignored them; the request query was never
		// carried over. Merge it in, letting the target's own parameters win on a clash.
		$qp_mode = isset( $rule['query_params'] ) ? sanitize_key( (string) $rule['query_params'] ) : '';
		if ( 'ignore_pass' === $qp_mode && '' !== $to && '' !== (string) $request_query ) {
			$incoming = array();
			wp_parse_str( (string) $request_query, $incoming );
			if ( ! empty( $incoming ) ) {
				$existing = array();
				wp_parse_str( (string) wp_parse_url( $to, PHP_URL_QUERY ), $existing );
				$to = add_query_arg( array_merge( $incoming, $existing ), $to );
			}
		}
		// Defense in depth: never honor an empty destination or a dangerous scheme even if one
		// somehow slipped past save-time sanitization. External http/https hosts are allowed.
		if ( '' === $to || ! self::is_safe_redirect_target( $to ) ) {
			return false;
		}

		/**
		 * Fires immediately before a configured redirect is issued.
		 *
		 * @param array  $rule   Rule definition.
		 * @param string $to     Destination URL.
		 * @param int    $status Status code.
		 * @param string $path   Matched request path.
		 */
		do_action( 'nexter_content_seo_before_redirect', $rule, $to, $status, $path );
		// wp_safe_redirect() (host-allowlist hardened): allow THIS admin-configured, scheme-validated
		// target host for this call only, so external redirects work while injected hosts are
		// rejected by core. Root-relative targets have no host, so same-site redirects are fine.
		$target_host = strtolower( (string) wp_parse_url( $to, PHP_URL_HOST ) );
		$allow_host  = static function ( $hosts ) use ( $target_host ) {
			if ( '' !== $target_host && ! in_array( $target_host, (array) $hosts, true ) ) {
				$hosts[] = $target_host;
			}
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );
		wp_safe_redirect( $to, $status );
		remove_filter( 'allowed_redirect_hosts', $allow_host );
		exit;
	}

	/**
	 * Compiled, cached view of the rule set for front-end matching: the ENABLED subset (in order),
	 * whether every enabled rule is an exact match, and — when so — an O(1) path→rule index. Cached
	 * in a transient invalidated by save_rules(), so a redirect no longer costs a full linear scan
	 * over all rules (including disabled ones) on every request, and an object cache serves it with
	 * no DB read.
	 *
	 * @return array{enabled: array<int,array<string,mixed>>, all_exact: bool, exact_index: array<string,array<string,mixed>>}
	 */
	private static function get_compiled_rules() {
		$cached = get_transient( self::COMPILED_CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['enabled'], $cached['all_exact'], $cached['exact_index'] ) ) {
			return $cached;
		}

		$enabled   = array();
		$all_exact = true;
		foreach ( self::get_rules() as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			$enabled[] = $rule;
			$cond      = isset( $rule['condition'] ) ? $rule['condition'] : 'exact_match';
			if ( 'exact_match' !== $cond ) {
				$all_exact = false;
			}
		}

		$exact_index = array();
		if ( $all_exact ) {
			foreach ( $enabled as $rule ) {
				$cf = self::rule_compare_from( $rule );
				if ( '' === $cf ) {
					continue; // No source URL — never matches.
				}
				$key = trim( $cf, '/' );
				if ( ! isset( $exact_index[ $key ] ) ) {
					$exact_index[ $key ] = $rule; // First rule for a key wins (first-match order).
				}
			}
		}

		$compiled = array(
			'enabled'     => $enabled,
			'all_exact'   => $all_exact,
			'exact_index' => $exact_index,
		);
		set_transient( self::COMPILED_CACHE_KEY, $compiled, DAY_IN_SECONDS );
		return $compiled;
	}

	/**
	 * Whether a redirect destination is a safe target: a site-relative path or an http/https URL.
	 * Protocol-relative and non-http(s) schemes are rejected.
	 *
	 * @param string $url Destination URL.
	 * @return bool
	 */
	private static function is_safe_redirect_target( $url ) {
		$url = (string) $url;
		if ( '' === $url || preg_match( '#^//#', $url ) ) {
			return false;
		}
		if ( preg_match( '#^/#', $url ) ) {
			return true;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true );
	}

	/**
	 * Normalized on-site path of a URL, or '' if the URL points to a different host (external).
	 *
	 * @param string $url URL or path.
	 * @return string Leading-slash path with no trailing slash (root is '/'), or '' if external/empty.
	 */
	private static function path_of( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' !== $host && $host !== $home_host ) {
			return ''; // External target — not part of an on-site redirect chain.
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = trim( $path, '/' );
		return '' === $path ? '/' : '/' . $path;
	}

	/**
	 * Find a self-redirect or multi-step loop among the given rules.
	 *
	 * @param array<int, array<string, mixed>> $rules Full prospective rule set.
	 * @return string Human-readable error, or '' when no loop exists.
	 */
	private static function find_redirect_loop( $rules, $focus = null ) {
		$enabled = array();
		foreach ( $rules as $r ) {
			if ( ! empty( $r['enabled'] ) ) {
				$enabled[] = $r;
			}
		}

		// With a $focus rule, only chains starting at that rule are reported. Checking every rule meant
		// one bad pre-existing rule rejected every later save, blaming the rule being added.
		$starts = ( null !== $focus && ! empty( $focus['enabled'] ) ) ? array( $focus ) : $enabled;

		foreach ( $starts as $start ) {
			$status = isset( $start['status_code'] ) ? (int) $start['status_code'] : self::DEFAULT_STATUS_CODE;
			if ( in_array( $status, self::GONE_STATUS_CODES, true ) ) {
				continue; // No destination to chain from.
			}
			$from = self::path_of( isset( $start['from_url'] ) ? $start['from_url'] : '' );
			$to   = self::path_of( isset( $start['to_url'] ) ? $start['to_url'] : '' );
			if ( '' === $from ) {
				continue;
			}
			if ( '' !== $to && $from === $to ) {
				return sprintf(
					/* translators: %s: redirect source */
					__( 'A rule cannot redirect a URL to itself (%s).', 'nexter-extension' ),
					$from
				);
			}

			// Walk the chain from $to; revisiting a path means a cycle.
			$seen    = array( $from );
			$current = $to;
			$depth   = 0;
			while ( '' !== $current && $depth < 25 ) {
				if ( in_array( $current, $seen, true ) ) {
					return sprintf(
						/* translators: %s: chain of paths */
						__( 'These redirect rules form a loop: %s', 'nexter-extension' ),
						implode( ' → ', array_merge( $seen, array( $current ) ) )
					);
				}
				$seen[]  = $current;
				$current = self::resolve_next_path( $current, $enabled );
				++$depth;
			}
			if ( $depth >= 25 ) {
				return __( 'Redirect chain is too long — this usually indicates a loop.', 'nexter-extension' );
			}
		}
		return '';
	}

	/**
	 * Given a path, return the on-site destination path of the first enabled rule that matches it,
	 * or '' when nothing matches or the destination leaves the site.
	 *
	 * @param string                            $path    Current path.
	 * @param array<int, array<string, mixed>>  $enabled Enabled rules.
	 * @return string
	 */
	private static function resolve_next_path( $path, $enabled ) {
		foreach ( $enabled as $rule ) {
			$status = isset( $rule['status_code'] ) ? (int) $rule['status_code'] : self::DEFAULT_STATUS_CODE;
			if ( in_array( $status, self::GONE_STATUS_CODES, true ) ) {
				continue;
			}
			$next = self::path_of( isset( $rule['to_url'] ) ? $rule['to_url'] : '' );
			// A rule pointing at its own source is broken on its own and cannot be a useful chain step.
			// Following it made every rule aimed at that path look like a loop.
			if ( '' !== $next && $next === self::path_of( isset( $rule['from_url'] ) ? $rule['from_url'] : '' ) ) {
				continue;
			}
			if ( self::rule_matches_request( $rule, $path ) ) {
				return $next;
			}
		}
		return '';
	}

	/**
	 * REST: GET collection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get( $request ) {
		return rest_ensure_response(
			array(
				'data' => array(
					'auto_redirect' => self::get_auto_redirect(),
					'rules'         => self::get_rules(),
				),
			)
		);
	}

	/**
	 * REST: GET the global redirection settings (auto_redirect). Read counterpart to
	 * PATCH /redirection/settings so a GET returns config instead of WP's rest_no_route 404.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_get_settings() {
		return rest_ensure_response(
			array(
				'data' => array(
					'auto_redirect' => self::get_auto_redirect(),
				),
			)
		);
	}

	/**
	 * REST: GET the redirect rules list. Read counterpart to POST /redirection/rules so a GET on
	 * the collection returns the rules instead of WP's rest_no_route 404.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_get_rules() {
		return rest_ensure_response(
			array(
				'data' => array(
					'rules' => self::get_rules(),
				),
			)
		);
	}

	/**
	 * REST: PATCH auto_redirect only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_patch_settings( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		if ( array_key_exists( 'auto_redirect', $body ) ) {
			self::set_auto_redirect( ! empty( $body['auto_redirect'] ) );
		}
		return rest_ensure_response(
			array(
				'data' => array(
					'auto_redirect' => self::get_auto_redirect(),
				),
			)
		);
	}

	/**
	 * Non-blocking warnings for a rule that is valid but risky: a catch-all source (Starts With "/"
	 * or Contains "/" — matches every request) and/or an external destination (sends visitors
	 * off-site). Returned in the create/update REST response so the UI can surface them; the rule
	 * still saves (soft warning, not a hard block).
	 *
	 * @param array $rule Sanitized rule.
	 * @return array<int,string>
	 */
	private static function rule_warnings( $rule ) {
		if ( ! is_array( $rule ) ) {
			return array();
		}
		$cond        = isset( $rule['condition'] ) ? (string) $rule['condition'] : 'exact_match';
		$cf          = trim( (string) self::rule_compare_from( $rule ), '/' ); // '' means the site root.
		$matches_all = in_array( $cond, array( 'starts_with', 'contains' ), true ) && '' === $cf;

		$to          = isset( $rule['to_url'] ) ? (string) $rule['to_url'] : '';
		$to_host     = strtolower( (string) wp_parse_url( $to, PHP_URL_HOST ) );
		$home_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$is_external = ( '' !== $to_host && $to_host !== $home_host );

		$warnings = array();
		if ( $matches_all && $is_external ) {
			$warnings[] = __( 'This rule matches all requests (Starts With “/”) and points to an external domain — it will send every visitor off-site.', 'nexter-extension' );
		} elseif ( $matches_all ) {
			$warnings[] = __( 'This rule matches all requests (Starts With “/”) — it will redirect every page on your site.', 'nexter-extension' );
		} elseif ( $is_external ) {
			$warnings[] = __( 'This rule redirects to an external domain — visitors will be sent off-site.', 'nexter-extension' );
		}
		return $warnings;
	}

	/**
	 * REST: POST new rule.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_post_rule( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$rule = self::sanitize_rule( $body );
		$gone = $rule && in_array( (int) $rule['status_code'], self::GONE_STATUS_CODES, true );
		if ( null === $rule || '' === $rule['from_url'] || ( ! $gone && '' === $rule['to_url'] ) ) {
			return new WP_Error( 'invalid_rule', __( 'A source URL is required, and a destination URL is required for redirect (3xx) rules.', 'nexter-extension' ), array( 'status' => 400 ) );
		}
		$rules = self::get_rules();
		// Cap the stored rule set so it cannot grow unbounded (which would bloat the option and
		// slow every front-end match). Filterable; PUT/edit of existing rules is unaffected.
		$max = (int) apply_filters( 'nexter_content_seo_max_redirect_rules', self::MAX_RULES );
		if ( $max > 0 && count( $rules ) >= $max ) {
			return new WP_Error(
				'rule_limit_reached',
				sprintf(
					/* translators: %d: maximum number of redirect rules */
					__( 'The redirect rule limit (%d) has been reached. Delete unused rules before adding more.', 'nexter-extension' ),
					$max
				),
				array( 'status' => 400 )
			);
		}
		$rules[] = $rule;
		$loop    = self::find_redirect_loop( $rules, $rule );
		if ( '' !== $loop ) {
			return new WP_Error( 'redirect_loop', $loop, array( 'status' => 400 ) );
		}
		self::save_rules( $rules );
		return rest_ensure_response(
			array(
			'data'     => $rule,
			'warnings' => self::rule_warnings( $rule )
			) 
		);
	}

	/**
	 * REST: PUT full rule.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_put_rule( $request ) {
		$id   = (string) $request->get_param( 'id' );
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$body['id'] = $id;
		$rule       = self::sanitize_rule( $body );
		$gone       = $rule && in_array( (int) $rule['status_code'], self::GONE_STATUS_CODES, true );
		if ( null === $rule || '' === $rule['from_url'] || ( ! $gone && '' === $rule['to_url'] ) ) {
			return new WP_Error( 'invalid_rule', __( 'A source URL is required, and a destination URL is required for redirect (3xx) rules.', 'nexter-extension' ), array( 'status' => 400 ) );
		}
		$rules = self::get_rules();
		$found = false;
		foreach ( $rules as $i => $r ) {
			if ( isset( $r['id'] ) && (string) $r['id'] === $id ) {
				$rules[ $i ] = $rule;
				$found       = true;
				break;
			}
		}
		if ( ! $found ) {
			return new WP_Error( 'not_found', __( 'Rule not found.', 'nexter-extension' ), array( 'status' => 404 ) );
		}
		$loop = self::find_redirect_loop( $rules, $rule );
		if ( '' !== $loop ) {
			return new WP_Error( 'redirect_loop', $loop, array( 'status' => 400 ) );
		}
		self::save_rules( $rules );
		return rest_ensure_response(
			array(
			'data'     => $rule,
			'warnings' => self::rule_warnings( $rule )
			) 
		);
	}

	/**
	 * REST: PATCH enabled (or partial).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_patch_rule( $request ) {
		$id   = (string) $request->get_param( 'id' );
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$rules   = self::get_rules();
		$found   = false;
		$updated = null;
		foreach ( $rules as $i => $r ) {
			if ( isset( $r['id'] ) && (string) $r['id'] === $id ) {
				if ( array_key_exists( 'enabled', $body ) ) {
					$rules[ $i ]['enabled'] = ! empty( $body['enabled'] );
				}
				$updated = $rules[ $i ];
				$found   = true;
				break;
			}
		}
		if ( ! $found ) {
			return new WP_Error( 'not_found', __( 'Rule not found.', 'nexter-extension' ), array( 'status' => 404 ) );
		}
		// Enabling a rule can complete a loop — re-validate before persisting.
		if ( ! empty( $updated['enabled'] ) ) {
			$loop = self::find_redirect_loop( $rules, $updated );
			if ( '' !== $loop ) {
				return new WP_Error( 'redirect_loop', $loop, array( 'status' => 400 ) );
			}
		}
		self::save_rules( $rules );
		return rest_ensure_response( array( 'data' => $updated ) );
	}

	/**
	 * REST: DELETE rule.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_delete_rule( $request ) {
		$id    = (string) $request->get_param( 'id' );
		$rules = self::get_rules();
		$next  = array();
		foreach ( $rules as $r ) {
			if ( isset( $r['id'] ) && (string) $r['id'] === $id ) {
				continue;
			}
			$next[] = $r;
		}
		if ( count( $next ) === count( $rules ) ) {
			return new WP_Error( 'not_found', __( 'Rule not found.', 'nexter-extension' ), array( 'status' => 404 ) );
		}
		self::save_rules( $next );
		return rest_ensure_response( array( 'data' => array( 'deleted' => true ) ) );
	}

	/**
	 * Detect post URL changes and create a redirect rule.
	 *
	 * @param int     $post_id      Post ID.
	 * @param WP_Post $post_after   Post object after update.
	 * @param WP_Post $post_before  Post object before update.
	 */
	public static function handle_post_url_change( $post_id, $post_after, $post_before ) {
		if ( ! self::get_auto_redirect() ) {
			return;
		}

		// Only handle published posts.
		if ( 'publish' !== $post_after->post_status || 'publish' !== $post_before->post_status ) {
			return;
		}

		// Only public post types.
		$post_types = get_post_types( array( 'public' => true ) );
		if ( ! in_array( $post_after->post_type, $post_types, true ) ) {
			return;
		}

		// Check if slug changed.
		if ( $post_after->post_name === $post_before->post_name ) {
			return;
		}

		$old_url = get_permalink( $post_before );
		$new_url = get_permalink( $post_after );

		if ( ! $old_url || ! $new_url || $old_url === $new_url ) {
			return;
		}

		$rules = self::get_rules();

		// Prevent duplicate rules.
		foreach ( $rules as $rule ) {
			if ( isset( $rule['from_url'] ) && $rule['from_url'] === $old_url ) {
				return;
			}
		}

		$new_rule = self::sanitize_rule(
			array(
				'enabled'   => true,
				'from_url'  => $old_url,
				'to_url'    => $new_url,
				'condition' => 'exact_match',
			)
		);

		if ( $new_rule ) {
			$rules[] = $new_rule;
			self::save_rules( $rules );
		}
	}

	/**
	 * Capture the term URL before it gets updated.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function capture_old_term_url( $term_id, $taxonomy ) {
		if ( ! self::get_auto_redirect() ) {
			return;
		}
		$link = get_term_link( $term_id, $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			self::$old_term_urls[ $term_id ] = $link;
		}
	}

	/**
	 * Detect term URL changes and create a redirect rule.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function handle_term_url_change( $term_id, $tt_id, $taxonomy ) {
		if ( ! self::get_auto_redirect() ) {
			return;
		}

		if ( ! isset( self::$old_term_urls[ $term_id ] ) ) {
			return;
		}

		$old_url = self::$old_term_urls[ $term_id ];
		$new_url = get_term_link( $term_id, $taxonomy );

		unset( self::$old_term_urls[ $term_id ] );

		if ( is_wp_error( $new_url ) || $old_url === $new_url ) {
			return;
		}

		$rules = self::get_rules();

		// Prevent duplicate rules.
		foreach ( $rules as $rule ) {
			if ( isset( $rule['from_url'] ) && $rule['from_url'] === $old_url ) {
				return;
			}
		}

		$new_rule = self::sanitize_rule(
			array(
				'enabled'   => true,
				'from_url'  => $old_url,
				'to_url'    => $new_url,
				'condition' => 'exact_match',
			)
		);

		if ( $new_rule ) {
			$rules[] = $new_rule;
			self::save_rules( $rules );
		}
	}
}
