<?php
/**
 * Content SEO – 404 monitoring.
 *
 * Logs 404 requests from real (non-bot, non-admin) visitors into a dedicated
 * custom DB table, with referrer and hit count. Exposes REST endpoints for a
 * paginated list, per-row delete, and bulk clear. Multisite-aware: each site
 * has its own table (blog-scoped via $wpdb->prefix).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_404_Monitor
 */
class Nexter_Content_SEO_404_Monitor {

	const OPTION_ENABLED    = 'nexter_content_seo_404_enabled';
	const OPTION_SCHEMA_VER = 'nexter_content_seo_404_schema_ver';
	const SCHEMA_VERSION    = 1;
	const TABLE_SUFFIX      = 'nexter_seo_404_log';
	const MAX_ROWS          = 5000;

	/**
	 * @return string Full table name for the current blog.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	/**
	 * @param bool $on
	 */
	public static function set_enabled( $on ) {
		update_option( self::OPTION_ENABLED, (bool) $on, false );
	}

	/**
	 * Bootstrap hooks.
	 */
	public static function init() {
		self::maybe_install_table();
		add_action( 'template_redirect', array( __CLASS__, 'maybe_log_404' ), 99 );

		// Multisite: install table on new site, drop on deletion.
		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( __CLASS__, 'on_initialize_site' ), 20 );
			add_action( 'wp_uninitialize_site', array( __CLASS__, 'on_uninitialize_site' ), 20 );
		}
	}

	/**
	 * Install table on new multisite site.
	 *
	 * @param WP_Site $site Site object (WP 5.1+).
	 */
	public static function on_initialize_site( $site ) {
		if ( ! is_object( $site ) || empty( $site->blog_id ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install_table();
		restore_current_blog();
	}

	/**
	 * Drop table when a multisite site is deleted.
	 *
	 * @param WP_Site $site Site object.
	 */
	public static function on_uninitialize_site( $site ) {
		global $wpdb;
		if ( ! is_object( $site ) || empty( $site->blog_id ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::OPTION_ENABLED );
		delete_option( self::OPTION_SCHEMA_VER );
		restore_current_blog();
	}

	/**
	 * Install or upgrade table if schema has changed.
	 */
	public static function maybe_install_table() {
		$ver = (int) get_option( self::OPTION_SCHEMA_VER, 0 );
		if ( $ver >= self::SCHEMA_VERSION ) {
			return;
		}
		self::install_table();
	}

	/**
	 * Create the logging table.
	 */
	public static function install_table() {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(2083) NOT NULL,
			url_hash CHAR(40) NOT NULL,
			referrer VARCHAR(2083) NOT NULL DEFAULT '',
			hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
			first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY last_seen (last_seen)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::OPTION_SCHEMA_VER, self::SCHEMA_VERSION, false );
	}

	/**
	 * Basic bot heuristic — cheap UA match; avoids polluting the log with crawlers.
	 *
	 * @param string $ua User-agent string.
	 * @return bool
	 */
	private static function looks_like_bot( $ua ) {
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|wget|curl|headless/i', $ua );
	}

	/**
	 * Log the current request if it is a real 404.
	 */
	public static function maybe_log_404() {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! is_404() ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( self::looks_like_bot( $ua ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$uri = (string) sanitize_text_field( $uri );
		if ( '' === $uri ) {
			return;
		}

		// Rate-limit per client IP so a unique-URL 404 spray can't drive unbounded INSERT +
		// COUNT + DELETE (prune) write amplification. Checked at the entry point — the cheapest
		// place to shed load — before any DB touch.
		if ( self::log_rate_limited() ) {
			return;
		}

		$referrer_raw = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		// Store only the referring origin (scheme + host), never the full path/query — a referer
		// URL can carry personal data (e.g. an email or token in the query string). Domain-only is
		// enough to see where 404s come from without logging PII.
		$referrer = self::referer_origin( (string) esc_url_raw( $referrer_raw ) );

		self::record_hit( $uri, $referrer );
	}

	/**
	 * Whether the current client has exceeded the 404-logging rate limit. Bounds INSERT/prune
	 * write amplification from a unique-URL spray. Keyed on REMOTE_ADDR (the transport peer, not
	 * spoofable X-Forwarded-For). Default 30 logs per IP per 10 minutes; both values filterable,
	 * and a limit of 0 (or less) disables throttling.
	 *
	 * @return bool True when the request should be dropped without logging.
	 */
	private static function log_rate_limited() {
		$limit = (int) apply_filters( 'nexter_content_seo_404_log_rate_limit', 30 );
		if ( $limit <= 0 ) {
			return false;
		}
		$window = (int) apply_filters( 'nexter_content_seo_404_log_rate_window', 10 * MINUTE_IN_SECONDS );
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key    = 'nxt_404_rl_' . sha1( $ip );
		$count  = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, max( 1, $window ) );
		return false;
	}

	/**
	 * Reduce a referer URL to its origin (scheme://host), dropping the path and query string that
	 * may contain personal data. Returns '' when no host can be parsed.
	 *
	 * @param string $referrer Full referer URL.
	 * @return string
	 */
	private static function referer_origin( $referrer ) {
		$referrer = trim( (string) $referrer );
		if ( '' === $referrer ) {
			return '';
		}
		$parts = wp_parse_url( $referrer );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		return $scheme . '://' . $parts['host'];
	}

	/**
	 * Upsert a hit row. Public so tests and other modules can log directly.
	 *
	 * @param string $url      404 path (with query string).
	 * @param string $referrer Referer URL or empty string.
	 * @return int|false Rows affected, or false on failure.
	 */
	public static function record_hit( $url, $referrer = '' ) {
		global $wpdb;

		// Sanitize at write time (defense-in-depth): even though the admin UI escapes on output,
		// storing already-clean values means a future template/REST consumer that forgets to
		// escape can't turn these into a stored-XSS vector.
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return false;
		}
		if ( strlen( $url ) > 2083 ) {
			$url = substr( $url, 0, 2083 );
		}
		$referrer = esc_url_raw( (string) $referrer );
		if ( strlen( $referrer ) > 2083 ) {
			$referrer = substr( $referrer, 0, 2083 );
		}
		$hash = sha1( $url );

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		// Upsert: UNIQUE KEY on url_hash means hits increments on duplicate.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (url, url_hash, referrer, hits, first_seen, last_seen)
			 VALUES (%s, %s, %s, 1, %s, %s)
			 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = VALUES(referrer)",
			$url,
			$hash,
			$referrer,
			$now,
			$now
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$res = $wpdb->query( $sql );

		// Prune deterministically whenever a NEW row was inserted (rows-affected === 1; a repeat
		// hit updating an existing row returns 2). The old 1%-chance prune could let the table
		// overshoot the cap for a long time between prunes; checking on every growth event bounds
		// it tightly without adding a COUNT to repeat-hit updates.
		if ( 1 === (int) $res ) {
			self::prune_if_needed();
		}

		return $res;
	}

	/**
	 * Prune oldest rows if the table exceeds MAX_ROWS.
	 */
	public static function prune_if_needed() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $total <= self::MAX_ROWS ) {
			return;
		}
		$excess = $total - self::MAX_ROWS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY last_seen ASC LIMIT %d", $excess ) );
	}

	/**
	 * Paginated fetch.
	 *
	 * @param array{page:int,per_page:int,orderby:string,order:string,search:string} $args
	 * @return array{rows:array,total:int}
	 */
	public static function fetch_rows( $args ) {
		global $wpdb;
		$table = self::table_name();
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		// Allow up to the table's hard cap (MAX_ROWS) so the admin UI can load the full log in
		// one request and filter/paginate it client-side; default page size stays at 20.
		$per_page = max( 1, min( self::MAX_ROWS, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$orderby_allow = array( 'last_seen', 'first_seen', 'hits', 'url' );
		$orderby       = in_array( ( $args['orderby'] ?? '' ), $orderby_allow, true ) ? $args['orderby'] : 'last_seen';
		$order         = strtolower( (string) ( $args['order'] ?? 'desc' ) ) === 'asc' ? 'ASC' : 'DESC';

		$search = trim( (string) ( $args['search'] ?? '' ) );
		$where  = '';
		$params = array();
		if ( '' !== $search ) {
			$where    = 'WHERE url LIKE %s OR referrer LIKE %s';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = (int) ( empty( $params )
			? $wpdb->get_var( $total_sql )
			: $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) );

		$list_params   = $params;
		$list_params[] = $per_page;
		$list_params[] = $offset;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list_sql = "SELECT id, url, referrer, hits, first_seen, last_seen FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		return array(
		'rows'  => $rows,
		'total' => $total
		);
	}

	/**
	 * Delete one row by ID.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public static function delete_row( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Clear all rows.
	 *
	 * @return int|false Rows affected.
	 */
	public static function clear_all() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
	}

	/**
	 * REST: GET collection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_get( $request ) {
		$data = self::fetch_rows(
			array(
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
				'orderby'  => (string) $request->get_param( 'orderby' ),
				'order'    => (string) $request->get_param( 'order' ),
				'search'   => (string) $request->get_param( 'search' ),
			)
		);
		return rest_ensure_response(
			array(
				'data' => array(
					'enabled' => self::is_enabled(),
					'rows'    => $data['rows'],
					'total'   => $data['total'],
				),
			)
		);
	}

	/**
	 * REST: GET settings (enabled flag). Read counterpart to PATCH /seo/404-log/settings so a GET
	 * on the route returns the current config instead of WP's rest_no_route 404.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_get_settings() {
		return rest_ensure_response( array( 'data' => array( 'enabled' => self::is_enabled() ) ) );
	}

	/**
	 * REST: PATCH settings (enable/disable).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_patch_settings( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		if ( array_key_exists( 'enabled', $body ) ) {
			self::set_enabled( ! empty( $body['enabled'] ) );
		}
		return rest_ensure_response( array( 'data' => array( 'enabled' => self::is_enabled() ) ) );
	}

	/**
	 * REST: DELETE one row.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_delete( $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! self::delete_row( $id ) ) {
			return new WP_Error( 'not_found', __( '404 log row not found.', 'nexter-extension' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'data' => array( 'deleted' => true ) ) );
	}

	/**
	 * REST: DELETE all rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_clear( $request ) {
		self::clear_all();
		return rest_ensure_response( array( 'data' => array( 'cleared' => true ) ) );
	}
}
