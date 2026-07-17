<?php
/**
 * Content SEO – Instant Indexing (IndexNow), bulk submit, and logs.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Indexing
 */
class Nexter_Content_SEO_Indexing {

	const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

	/** Option name for log rows (separate from main SEO options). */
	const LOGS_OPTION = 'nexter_content_seo_indexnow_logs';

	const MAX_LOG_ENTRIES = 500;

	const RESPONSE_MAX_LEN = 400;

	/**
	 * Register hooks.
	 */
	/** Cron hook used to offload the auto-ping so post saves never block on the IndexNow HTTP call. */
	const AUTO_PING_HOOK = 'nxt_seo_indexnow_auto_ping';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_key_file' ), 0 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'maybe_ping_indexnow_on_save' ), 99, 3 );
		add_action( 'wp_head', array( __CLASS__, 'output_site_verification_meta' ), 1 );
		add_action( self::AUTO_PING_HOOK, array( __CLASS__, 'cron_submit_auto' ), 10, 1 );
	}

	/**
	 * Cron callback: submit a single auto-ping URL to IndexNow (runs out of the save request).
	 *
	 * @param string $url Permalink to submit.
	 */
	public static function cron_submit_auto( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}
		self::submit_urls_indexnow( array( $url ), 'auto' );
	}

	/**
	 * Print meta tags for Google / Bing / Pinterest / Facebook domain verification (tokens from Content SEO → Validation).
	 * Actual “validation” is done by each provider when they crawl the homepage and read these tags.
	 *
	 * @return void
	 */
	public static function output_site_verification_meta() {
		if ( is_admin() ) {
			return;
		}
		/**
		 * Whether to output site verification meta tags from saved options.
		 *
		 * @param bool $print Default true.
		 */
		if ( ! apply_filters( 'nexter_content_seo_output_verification_meta', true ) ) {
			return;
		}
		$opts  = Nexter_Content_SEO::get_options();
		$pairs = array(
			'google_verification'    => 'google-site-verification',
			'bing_verification'      => 'msvalidate.01',
			'pinterest_verification' => 'pinterest-site-verification',
			'facebook_verification'  => 'facebook-domain-verification',
		);
		foreach ( $pairs as $opt_key => $meta_name ) {
			$val = isset( $opts[ $opt_key ] ) ? trim( (string) $opts[ $opt_key ] ) : '';
			$val = Nexter_Content_SEO::sanitize_verification_meta_content( $val );
			if ( '' === $val ) {
				continue;
			}
			printf( '<meta name="%s" content="%s" />' . "\n", esc_attr( $meta_name ), esc_attr( $val ) );
		}
	}

	/**
	 * Read all log entries (newest first as stored).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_logs_raw() {
		$logs = get_option( self::LOGS_OPTION, array() );
		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Append one row per URL; trim list to max size.
	 *
	 * @param string[] $urls    Sanitized URLs.
	 * @param int      $code    HTTP status or 0 for transport error.
	 * @param string   $message Response / error text.
	 * @param bool     $ok      Whether the request succeeded.
	 * @param string   $source  bulk|auto.
	 */
	public static function add_log_entries( array $urls, $code, $message, $ok, $source = 'bulk' ) {
		$urls = array_values( array_filter( array_map( 'esc_url_raw', $urls ) ) );
		if ( empty( $urls ) ) {
			return;
		}
		$code    = (int) $code;
		$message = self::sanitize_log_message( $message );
		$source  = in_array( $source, array( 'bulk', 'auto' ), true ) ? $source : 'bulk';
		$logs    = self::get_logs_raw();
		$now     = time();
		foreach ( $urls as $url ) {
			try {
				$rid = substr( bin2hex( random_bytes( 8 ) ), 0, 16 );
			} catch ( Exception $e ) {
				$rid = wp_generate_password( 16, false );
			}
			array_unshift(
				$logs,
				array(
					'id'       => $rid,
					'time'     => $now,
					'url'      => $url,
					'code'     => $code,
					'response' => $message,
					'ok'       => (bool) $ok,
					'source'   => $source,
				)
			);
		}
		$logs = array_slice( $logs, 0, self::MAX_LOG_ENTRIES );
		update_option( self::LOGS_OPTION, $logs, false );
	}

	/**
	 * @param string $message Raw message.
	 * @return string
	 */
	private static function sanitize_log_message( $message ) {
		$message = wp_strip_all_tags( (string) $message );
		if ( strlen( $message ) > self::RESPONSE_MAX_LEN ) {
			$message = substr( $message, 0, self::RESPONSE_MAX_LEN ) . '…';
		}
		return $message;
	}

	/**
	 * The single source of truth for the current, valid IndexNow key. Read-only (never generates
	 * or persists — safe to call on every front-end request). Returns '' when the stored key is
	 * missing or doesn't meet the canonical hex format shared by the serve + submit paths.
	 *
	 * @return string Lowercase hex key, or '' when none is valid.
	 */
	public static function get_stored_valid_key() {
		$opts = Nexter_Content_SEO::get_options();
		$key  = isset( $opts['indexnow_api_key'] ) ? (string) $opts['indexnow_api_key'] : '';
		return preg_match( '/^[a-f0-9]{8,128}$/', $key ) ? $key : '';
	}

	/**
	 * Serve IndexNow key file at https://example.com/{key}.txt
	 */
	public static function maybe_serve_key_file() {
		// Use the SAME canonical validation as ensure_api_key()/submit so the key served at
		// /{key}.txt is byte-identical to the key submitted to the IndexNow API. Previously this
		// path accepted any 8+ char string while submit required a hex key and regenerated a
		// different one — so a non-hex stored key was served but never matched a submission.
		$key = self::get_stored_valid_key();
		if ( '' === $key ) {
			return;
		}
		$expected_path = wp_parse_url( home_url( '/' . $key . '.txt' ), PHP_URL_PATH );
		if ( ! is_string( $expected_path ) || $expected_path === '' ) {
			return;
		}
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $request_path ) ) {
			return;
		}
		$expected_norm = untrailingslashit( $expected_path );
		$request_norm  = untrailingslashit( $request_path );
		if ( $request_norm !== $expected_norm ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8', true );
		status_header( 200 );
		echo esc_html( $key );
		exit;
	}

	/**
	 * Create and persist an IndexNow API key when missing.
	 *
	 * @return string
	 */
	public static function ensure_api_key() {
		// Return the existing key when it passes canonical validation; only generate/persist a new
		// one when there isn't a valid stored key. Shared with maybe_serve_key_file() so served
		// and submitted keys are always identical.
		$existing = self::get_stored_valid_key();
		if ( '' !== $existing ) {
			return $existing;
		}
		$opts = Nexter_Content_SEO::get_options();
		$key  = '';
		if ( function_exists( 'random_bytes' ) ) {
			try {
				$key = bin2hex( random_bytes( 16 ) );
			} catch ( Exception $e ) {
				$key = '';
			}
		}
		if ( ! is_string( $key ) || strlen( $key ) < 16 ) {
			$key = strtolower( wp_generate_password( 32, false, false ) );
			$key = preg_replace( '/[^a-f0-9]/', '', $key );
		}
		if ( strlen( $key ) < 8 ) {
			$key = substr( md5( wp_generate_password( 64, true, true ) ), 0, 32 );
		}
		$opts['indexnow_api_key'] = $key;
		update_option( Nexter_Content_SEO::OPTION_NAME, $opts );
		return $key;
	}

	/**
	 * URLs skipped (invalid / off-domain) on the most recent submission.
	 *
	 * @var string[]
	 */
	private static $last_skipped = array();

	/**
	 * URLs dropped by the last submission's validation.
	 *
	 * @return string[]
	 */
	public static function get_last_skipped() {
		return self::$last_skipped;
	}

	/**
	 * Keep only well-formed http(s) URLs on the given host (deduped). IndexNow requires every
	 * URL in a batch to be on the same host, so off-domain or malformed URLs are filtered out
	 * rather than poisoning the whole submission.
	 *
	 * @param array  $urls Raw URLs.
	 * @param string $host Site host to match.
	 * @return array{valid: string[], skipped: string[]}
	 */
	private static function filter_submittable_urls( $urls, $host ) {
		$valid   = array();
		$skipped = array();
		$host    = strtolower( (string) $host );
		foreach ( (array) $urls as $raw ) {
			$u = esc_url_raw( trim( (string) $raw ) );
			if ( '' === $u ) {
				$skipped[] = (string) $raw;
				continue;
			}
			$scheme = strtolower( (string) wp_parse_url( $u, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				$skipped[] = $u;
				continue;
			}
			$uhost = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
			if ( '' === $uhost || $uhost !== $host ) {
				$skipped[] = $u;
				continue;
			}
			$valid[ $u ] = $u;
		}
		return array(
			'valid'   => array_values( $valid ),
			'skipped' => $skipped,
		);
	}

	/**
	 * Split URLs into those allowed for submission and those whose resolved post type is in the
	 * "Exclude Post Types" setting. URLs that do not resolve to a post (archives, term pages,
	 * custom routes) are always allowed — only real posts of an excluded type are dropped. Mirrors
	 * the exclusion the auto-ping-on-save path already applies, so manual/bulk submits honor it too.
	 *
	 * @param string[] $urls Absolute URLs.
	 * @return array{allowed: string[], excluded: string[]}
	 */
	public static function filter_excluded_post_type_urls( $urls ) {
		$urls    = array_values( (array) $urls );
		$opts    = class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::get_options() : array();
		$exclude = ( isset( $opts['indexnow_exclude_types'] ) && is_array( $opts['indexnow_exclude_types'] ) )
			? $opts['indexnow_exclude_types']
			: array();

		// No post type is excluded → nothing to filter.
		$has_exclusions = false;
		foreach ( $exclude as $on ) {
			if ( ! empty( $on ) ) {
				$has_exclusions = true;
				break;
			}
		}
		if ( ! $has_exclusions ) {
			return array( 'allowed' => $urls, 'excluded' => array() );
		}

		$allowed  = array();
		$excluded = array();
		foreach ( $urls as $url ) {
			$url = (string) $url;
			$pid = url_to_postid( $url );
			// url_to_postid() returns 0 for direct media file URLs (/wp-content/uploads/*),
			// so fall back to attachment_url_to_postid() to resolve the attachment post ID.
			// Without this, an excluded "attachment" post type never matches these URLs and
			// they get submitted to IndexNow anyway.
			if ( ! $pid ) {
				$pid = attachment_url_to_postid( $url );
			}
			// url_to_postid() also returns 0 for query-string (non-pretty) URLs such as
			// ?elementor_library=slug, ?e-landing-page=slug or ?p=123 — the form Elementor/theme
			// builder template URLs use. Resolve those from the query vars so an excluded template
			// post type (e.g. elementor_library / nxt_builder) is not silently submitted.
			if ( ! $pid ) {
				$pid = self::resolve_postid_from_query_string( $url );
			}
			if ( $pid > 0 ) {
				$ptype = get_post_type( $pid );
				if ( $ptype && ! empty( $exclude[ $ptype ] ) ) {
					$excluded[] = $url;
					continue;
				}
			}
			$allowed[] = $url;
		}
		return array( 'allowed' => $allowed, 'excluded' => $excluded );
	}

	/**
	 * Resolve a post ID from a query-string (non-pretty permalink) URL that WordPress core's
	 * url_to_postid() does not handle — e.g. ?p=123, ?page_id=123, or a custom-post-type query var
	 * such as ?elementor_library=slug / ?e-landing-page=slug / ?nxt_builder=slug. These are the URL
	 * forms Elementor and theme-builder templates use; without resolving them, an excluded template
	 * post type is never matched and the URL is submitted to IndexNow anyway.
	 *
	 * @param string $url Absolute or relative URL.
	 * @return int Post ID, or 0 when it cannot be resolved.
	 */
	private static function resolve_postid_from_query_string( $url ) {
		$query = wp_parse_url( (string) $url, PHP_URL_QUERY );
		if ( empty( $query ) ) {
			return 0;
		}
		$args = array();
		parse_str( $query, $args );
		if ( empty( $args ) || ! is_array( $args ) ) {
			return 0;
		}

		// ID-based query vars.
		if ( ! empty( $args['p'] ) && is_numeric( $args['p'] ) ) {
			return (int) $args['p'];
		}
		if ( ! empty( $args['page_id'] ) && is_numeric( $args['page_id'] ) ) {
			return (int) $args['page_id'];
		}

		// Custom-post-type slug query vars. Match against each registered post type's name and its
		// query_var (WordPress uses the post-type name as the query var when query_var is enabled).
		foreach ( get_post_types( array(), 'objects' ) as $pt ) {
			$keys = array( $pt->name );
			if ( ! empty( $pt->query_var ) && is_string( $pt->query_var ) ) {
				$keys[] = $pt->query_var;
			}
			foreach ( array_unique( $keys ) as $key ) {
				if ( empty( $args[ $key ] ) || is_array( $args[ $key ] ) ) {
					continue;
				}
				$slug = sanitize_title( (string) $args[ $key ] );
				if ( '' === $slug ) {
					continue;
				}
				$found = get_page_by_path( $slug, OBJECT, $pt->name );
				if ( $found instanceof WP_Post ) {
					return (int) $found->ID;
				}
			}
		}

		return 0;
	}

	/**
	 * POST urlList to IndexNow.
	 *
	 * @param string[] $urls   Absolute URLs (max 100).
	 * @param string   $source bulk|auto.
	 * @return true|WP_Error
	 */
	public static function submit_urls_indexnow( array $urls, $source = 'bulk' ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			$err = __( 'Could not determine site host.', 'nexter-extension' );
			return new WP_Error( 'no_host', $err, array( 'status' => 500 ) );
		}

		// IndexNow rejects the ENTIRE batch if any single URL is malformed or off-domain, so
		// validate each URL (http/https + same host as the site) and submit only the valid
		// remainder. Skipped URLs are recorded for reporting.
		$filtered            = self::filter_submittable_urls( $urls, $host );
		$urls                = $filtered['valid'];
		self::$last_skipped  = $filtered['skipped'];
		if ( empty( $urls ) ) {
			return new WP_Error(
				'no_urls',
				__( 'No valid same-domain URLs to submit. IndexNow only accepts http(s) URLs on this exact host.', 'nexter-extension' ),
				array(
					'status'  => 400,
					'skipped' => self::$last_skipped,
				)
			);
		}
		$key          = self::ensure_api_key();
		$key_location = home_url( '/' . $key . '.txt' );
		$body = wp_json_encode(
			array(
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => $key_location,
				'urlList'     => $urls,
			)
		);
		$response = wp_remote_post(
			self::INDEXNOW_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			self::add_log_entries( $urls, 0, $response->get_error_message(), false, $source );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			self::add_log_entries( $urls, $code, __( 'OK', 'nexter-extension' ), true, $source );
			return true;
		}
		$msg = wp_remote_retrieve_body( $response );
		$msg = $msg ? wp_strip_all_tags( $msg ) : __( 'IndexNow request failed.', 'nexter-extension' );
		self::add_log_entries( $urls, $code, $msg, false, $source );
		return new WP_Error(
			'indexnow_http',
			self::sanitize_log_message( $msg ),
			array( 'status' => $code >= 400 ? $code : 502 )
		);
	}

	/**
	 * Notify IndexNow when a public post is published or updated.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 */
	public static function maybe_ping_indexnow_on_save( $post_id, $post, $update ) {
		unset( $update );
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$opts = Nexter_Content_SEO::get_options();
		if ( empty( $opts['enable_indexnow'] ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		$exclude = isset( $opts['indexnow_exclude_types'] ) && is_array( $opts['indexnow_exclude_types'] )
			? $opts['indexnow_exclude_types']
			: array();
		if ( ! empty( $exclude[ $post->post_type ] ) ) {
			return;
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		// Don't ask search engines to index a URL we mark noindex (per-post meta wins over the
		// global post-type default) — mirrors the sitemap exclusion so the two never disagree.
		$noindex_key  = class_exists( 'Nexter_Content_SEO_Robots' ) ? Nexter_Content_SEO_Robots::META_NOINDEX : '_nxt_seo_noindex';
		$noindex_meta = get_post_meta( $post_id, $noindex_key, true );
		if ( '1' === $noindex_meta || 1 === $noindex_meta || true === $noindex_meta ) {
			return;
		}
		if ( '' === (string) $noindex_meta && ! empty( $opts['noindex_post_types'][ $post->post_type ] ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( ! $url || ! is_string( $url ) ) {
			return;
		}
		// Offload the (blocking, 20s-timeout) HTTP submit to cron so publishing/updating a
		// post never stalls on a slow or unreachable IndexNow endpoint.
		if ( ! wp_next_scheduled( self::AUTO_PING_HOOK, array( $url ) ) ) {
			wp_schedule_single_event( time() + 1, self::AUTO_PING_HOOK, array( $url ) );
		}
	}

	/**
	 * Enforce a sliding-window rate limit on bulk IndexNow submissions.
	 *
	 * Defaults: 10 requests OR 1000 URLs total per user per 5 minutes. Either
	 * ceiling breached → 429. The numbers are filter-tunable so site operators
	 * can relax or tighten them for bulk migration workflows.
	 *
	 * @param int $url_count URLs about to be submitted in this request.
	 * @return true|WP_Error True when allowed; WP_Error with status 429 otherwise.
	 */
	protected static function check_bulk_rate_limit( $url_count ) {
		$window   = (int) apply_filters( 'nexter_content_seo_indexnow_rl_window', 5 * MINUTE_IN_SECONDS );
		$max_req  = (int) apply_filters( 'nexter_content_seo_indexnow_rl_max_requests', 10 );
		$max_urls = (int) apply_filters( 'nexter_content_seo_indexnow_rl_max_urls', 1000 );
		if ( $window <= 0 || ( $max_req <= 0 && $max_urls <= 0 ) ) {
			return true;
		}

		$user_id = get_current_user_id();
		$key     = 'nxt_seo_indexnow_rl_' . ( $user_id ? (int) $user_id : 'anon' );
		$now     = time();
		$bucket  = get_transient( $key );
		// Start a fresh fixed window on first use, or once the current window has elapsed.
		// (Previously the TTL was reset to the full window on every call, so a steady client
		// pushed the expiry forward indefinitely and stayed locked out far longer than $window.)
		if ( ! is_array( $bucket ) || empty( $bucket['start'] ) || ( $now - (int) $bucket['start'] ) >= $window ) {
			$bucket = array( 'start' => $now, 'requests' => 0, 'urls' => 0 );
		}
		$requests_after = (int) $bucket['requests'] + 1;
		$urls_after     = (int) $bucket['urls'] + (int) $url_count;

		if ( ( $max_req > 0 && $requests_after > $max_req ) || ( $max_urls > 0 && $urls_after > $max_urls ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many IndexNow submissions in a short window. Please try again in a few minutes.', 'nexter-extension' ),
				array( 'status' => 429 )
			);
		}

		$bucket['requests'] = $requests_after;
		$bucket['urls']     = $urls_after;
		// TTL counts down from the window start, so the window does not slide forward on writes.
		$remaining = $window - ( $now - (int) $bucket['start'] );
		if ( $remaining < 1 ) {
			$remaining = 1;
		}
		set_transient( $key, $bucket, $remaining );
		return true;
	}

	/**
	 * REST: GET IndexNow status/config for the Instant Indexing management UI.
	 *
	 * The `/seo/indexing` route previously accepted POST only (bulk submit); a GET returned the
	 * standard WP `rest_no_route` 404. This read endpoint exposes the current IndexNow state
	 * (enabled flag, public key + key-file URL, excluded post types, and a small log summary) so a
	 * client hitting GET /nexter/v1/seo/indexing gets a 200 with useful data instead of a 404.
	 * Note: the IndexNow key is intentionally public (it is served at /{key}.txt), so returning it
	 * here is not a secret disclosure.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_get_status() {
		$opts    = class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::get_options() : array();
		$key     = self::get_stored_valid_key();
		$logs    = self::get_logs_raw();
		$enabled = ! empty( $opts['enable_indexnow'] );
		$exclude = ( isset( $opts['indexnow_exclude_types'] ) && is_array( $opts['indexnow_exclude_types'] ) )
			? $opts['indexnow_exclude_types']
			: array();

		$last_submit_at = 0;
		if ( is_array( $logs ) && ! empty( $logs ) ) {
			$first          = reset( $logs );
			$last_submit_at = ( is_array( $first ) && isset( $first['time'] ) ) ? (int) $first['time'] : 0;
		}

		return rest_ensure_response(
			array(
				'data' => array(
					'enabled'        => $enabled,
					'api_key'        => $key,
					'key_url'        => $key ? home_url( '/' . $key . '.txt' ) : '',
					'exclude_types'  => $exclude,
					'log_count'      => is_array( $logs ) ? count( $logs ) : 0,
					'last_submit_at' => $last_submit_at,
				),
			)
		);
	}

	/**
	 * REST: POST bulk submit URLs to IndexNow.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_bulk_index( $request ) {
		$urls   = $request->get_param( 'urls' );
		$action = $request->get_param( 'action' ) ?: 'update';
		unset( $action );
		if ( ! is_array( $urls ) || empty( $urls ) ) {
			return new WP_Error( 'invalid_data', __( 'URLs required.', 'nexter-extension' ), array( 'status' => 400 ) );
		}
		$urls = array_filter( array_slice( array_map( 'esc_url_raw', $urls ), 0, 100 ) );
		if ( empty( $urls ) ) {
			return new WP_Error( 'invalid_data', __( 'No valid URLs after sanitization.', 'nexter-extension' ), array( 'status' => 400 ) );
		}

		// Rate limit bulk submissions so a stuck client or malicious request cannot
		// hammer the IndexNow API. Sliding window: max 10 submissions (1,000 URLs)
		// per user per 5 minutes. Tunable via filter.
		$rl = self::check_bulk_rate_limit( count( $urls ) );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		// Honor "Exclude Post Types" for manual/bulk submissions (previously only the auto-ping
		// path checked it). URLs resolving to an excluded post type are dropped before submission
		// and reported separately so the UI can show the skip reason.
		$split         = self::filter_excluded_post_type_urls( $urls );
		$excluded_urls = $split['excluded'];
		$urls          = $split['allowed'];

		if ( empty( $urls ) ) {
			// Everything entered was an excluded post type — a successful no-op, not an error, so
			// the UI reports "0 submitted, N skipped (excluded post type)" rather than failing.
			return rest_ensure_response(
				array(
					'data' => array(
						'submitted'     => 0,
						'skipped'       => 0,
						'skipped_urls'  => array(),
						'excluded'      => count( $excluded_urls ),
						'excluded_urls' => array_slice( $excluded_urls, 0, 50 ),
						'ok'            => true,
					),
				)
			);
		}

		$result = self::submit_urls_indexnow( $urls, 'bulk' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$skipped = self::get_last_skipped();
		return rest_ensure_response(
			array(
				'data' => array(
					'submitted'     => count( $urls ) - count( $skipped ),
					'skipped'       => count( $skipped ),
					'skipped_urls'  => array_slice( $skipped, 0, 50 ),
					'excluded'      => count( $excluded_urls ),
					'excluded_urls' => array_slice( $excluded_urls, 0, 50 ),
					'ok'            => true,
				),
			)
		);
	}

	/**
	 * REST: GET paginated / sorted logs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_get_logs( $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page < 5 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}
		$orderby = $request->get_param( 'orderby' );
		if ( ! in_array( $orderby, array( 'time', 'url', 'response' ), true ) ) {
			$orderby = 'time';
		}
		$order = strtolower( (string) $request->get_param( 'order' ) ) === 'asc' ? 'asc' : 'desc';

		$logs = self::get_logs_raw();
		usort(
			$logs,
			function ( $a, $b ) use ( $orderby, $order ) {
				$va = isset( $a[ $orderby ] ) ? $a[ $orderby ] : '';
				$vb = isset( $b[ $orderby ] ) ? $b[ $orderby ] : '';
				if ( 'time' === $orderby ) {
					$cmp = ( (int) $va ) <=> ( (int) $vb );
				} else {
					$cmp = strcasecmp( (string) $va, (string) $vb );
				}
				return 'asc' === $order ? $cmp : - $cmp;
			}
		);

		$total  = count( $logs );
		$offset = ( $page - 1 ) * $per_page;
		$items  = array_slice( $logs, $offset, $per_page );

		return rest_ensure_response(
			array(
				'data' => array(
					'items'    => $items,
					'total'    => $total,
					'page'     => $page,
					'per_page' => $per_page,
				),
			)
		);
	}

	/**
	 * REST: DELETE all logs.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_clear_logs() {
		delete_option( self::LOGS_OPTION );
		return rest_ensure_response(
			array(
				'data' => array( 'cleared' => true ),
			)
		);
	}
}
