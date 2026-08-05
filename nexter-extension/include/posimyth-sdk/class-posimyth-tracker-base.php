<?php
/**
 * POSIMYTH Analytics — shared client tracker base.
 *
 * Ship this file UNCHANGED inside every POSIMYTH plugin's `include/posimyth-sdk/`
 * folder. Each plugin provides a tiny subclass (Posimyth_Tracker_NE / _TPAE / _TPGB)
 * that only declares plugin-specific keys. All collection, scanning, consent-gating
 * and transport lives here so the three plugins never drift apart.
 *
 * Privacy: this payload carries no email, no IP, no user names and no post content.
 *
 * It DOES carry the site URL, and the hub stores that URL in plaintext so support can tell which
 * install a report came from. Earlier revisions of this comment said the hub only kept a one-way
 * SHA-256 hash of it; that was wrong — the hub's hash is a row-dedup key that sits alongside the
 * raw value, not a substitute for it. Do not describe this data as anonymous.
 *
 * The one exception to "no email" is the deactivation dialog's "I agree to be contacted" opt-in,
 * which attaches the admin email — and only when the user ticks that box. See
 * Posimyth_Deactivation_Survey::handle_ajax().
 *
 * @package POSIMYTH\Analytics\SDK
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Posimyth_Tracker_Base' ) ) {

	/**
	 * Shared analytics tracker. Owns the hooks, the payload, the environment/content scanners and
	 * the transport; each product subclasses it to supply its own identity and feature toggles.
	 */
	abstract class Posimyth_Tracker_Base {

		/**
		 * Guard so each concrete subclass boots its hooks only once.
		 *
		 * @var array<string,bool>
		 */
		protected static $booted = array();

		// Per-plugin configuration — implemented by each subclass.

		/** Short unique id, used for cron + option names. e.g. 'ne', 'tpae', 'tpgb'. */
		abstract protected static function id(): string;

		/** WordPress plugin folder slug, e.g. 'nexter-extension'. */
		abstract protected static function slug(): string;

		/** Option key that stores the user's opt-in (true = consent given). */
		abstract protected static function opt_in_option(): string;

		/** Current plugin version string. */
		abstract protected static function version(): string;

		/** Whether the Pro/premium build is active. */
		abstract protected static function is_pro(): bool;

		/**
		 * Map of feature/widget/block slug => bool, representing which features
		 * are ENABLED in settings (toggled on). Counts of actual usage come from
		 * used_features().
		 *
		 * @return array<string,bool>
		 */
		abstract protected static function enabled_features(): array;

		/* ----- Optional config: subclasses override only when relevant ----- */

		/**
		 * Map of feature slug => integer usage count, scanned from real content.
		 * Default empty (e.g. Nexter Extension: extensions are toggle-only).
		 *
		 * @return array<string,int>
		 */
		protected static function used_features(): array {
			return array();
		}

		/** License status + plan. Default empty for free-only products. */
		protected static function license(): array {
			return array(
				'status' => '',
				'plan'   => '',
			);
		}

		/** Onboarding state string ('' when the plugin has no onboarding flow). */
		protected static function onboarding_status(): string {
			return '';
		}

		/** Human-readable product name, used in the privacy-policy suggestion. */
		protected static function display_name(): string {
			return static::slug();
		}

		// Bootstrap + WordPress hooks.

		/**
		 * Registers this product's hooks. Safe to call more than once.
		 */
		public static function init(): void {
			$class = static::class;
			if ( ! empty( self::$booted[ $class ] ) ) {
				return;
			}
			self::$booted[ $class ] = true;

			add_action( 'activated_plugin', array( static::class, 'on_activate' ), 10, 2 );
			add_action( 'deactivated_plugin', array( static::class, 'on_deactivate' ), 10, 2 );
			add_action( static::cron_hook(), array( static::class, 'heartbeat' ) );
			add_action( 'admin_init', array( static::class, 'register_privacy_policy_content' ) );

			if ( ! wp_next_scheduled( static::cron_hook() ) ) {
				wp_schedule_event( time(), 'weekly', static::cron_hook() );
			}
		}

		/**
		 * Feeds suggested text into WordPress's own Privacy Policy generator (Tools → Privacy), so a
		 * site owner writing their policy is told about this plugin's data sharing instead of having to
		 * discover it. Registered from init(), which is already behind the white-label gate — a
		 * rebranded install must not surface POSIMYTH's name here either.
		 */
		public static function register_privacy_policy_content(): void {
			if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
				return;
			}

			$name = static::display_name();

			$content = '<p>' . sprintf(
				/* translators: %s: product name */
				esc_html__( '%s can send non-sensitive information about this website to POSIMYTH Innovation (api.posimyth.com) to help diagnose conflicts and prioritise fixes. This is off by default and nothing is sent unless an administrator turns on "Share Non-Sensitive Details".', 'nexter-extension' ),
				esc_html( $name )
			) . '</p>';

			$content .= '<p>' . esc_html__( 'When enabled, the information sent is: the site URL, the plugin version, PHP / MySQL / WordPress versions, server software, timezone, locale, memory limit, maximum upload size, permalink structure, whether HTTPS / multisite / debug mode are on, the environment type, the number of registered users, the active theme and its version, the detected page builder, the list of active plugin folders and their count, which of the plugin\'s features are enabled and how often they are used, Pro/Free and licence status, and the install date. It is sent when the plugin is activated, when it is deactivated, and once a week in the background.', 'nexter-extension' ) . '</p>';

			$content .= '<p>' . esc_html__( 'No post or page content, visitor data, user names or email addresses are included. The one exception is the deactivation feedback form: if an administrator ticks "I agree to be contacted via email" there, the site administration email address is sent with that feedback so POSIMYTH can reply. That box is off by default.', 'nexter-extension' ) . '</p>';

			wp_add_privacy_policy_content( $name, wp_kses_post( $content ) );
		}

		/**
		 * Name of this product's heartbeat cron hook.
		 *
		 * @return string
		 */
		protected static function cron_hook(): string {
			return 'posimyth_heartbeat_' . static::id();
		}

		/**
		 * Reports an activation, but only for THIS product and only with consent.
		 *
		 * @param string $plugin       Plugin file that was activated.
		 * @param bool   $network_wide Unused; part of the activated_plugin hook signature.
		 */
		public static function on_activate( string $plugin, bool $network_wide ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by the hook signature.
			if ( false === strpos( $plugin, static::slug() ) ) {
				return;
			}
			static::record_install_time();
			if ( ! static::has_consent() ) {
				return;
			}
			static::do_request( 'activate' );
		}

		/**
		 * Reports a deactivation, but only for THIS product and only with consent.
		 *
		 * @param string $plugin       Plugin file that was deactivated.
		 * @param bool   $network_wide Unused; part of the deactivated_plugin hook signature.
		 */
		public static function on_deactivate( string $plugin, bool $network_wide ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by the hook signature.
			if ( false === strpos( $plugin, static::slug() ) ) {
				return;
			}
			if ( ! static::has_consent() ) {
				return;
			}
			static::do_request( 'deactivate' );
		}

		/**
		 * Weekly background check-in, so long-lived installs keep reporting current state.
		 */
		public static function heartbeat(): void {
			if ( ! static::has_consent() ) {
				return;
			}
			static::do_request( 'heartbeat' );
		}

		/**
		 * Activation ping for the plugin's OWN activation request.
		 *
		 * The `activated_plugin` hook in init() cannot help here: during a plugin's own activation
		 * request WordPress has already fired `plugins_loaded` before it includes the plugin file, so
		 * init() never runs and nothing is listening when `activated_plugin` fires. The result was
		 * that activations were never recorded, while deactivations (where the plugin IS already
		 * loaded) worked fine. Call this from register_activation_hook() instead.
		 *
		 * Consent-gated.
		 */
		public static function on_self_activate(): void {
			static::record_install_time();
			if ( ! static::has_consent() ) {
				return;
			}
			static::do_request( 'activate' );
		}

		/**
		 * Called by the consent notice and the Dashboard toggle immediately after the user opts in.
		 *
		 * Both callers write the consent option before calling, so this guard is normally satisfied —
		 * it is here so the function is safe on its own terms rather than relying on every present and
		 * future caller remembering to set consent first.
		 */
		public static function send_first_ping(): void {
			static::record_install_time();
			if ( ! static::has_consent() ) {
				return;
			}
			static::do_request( 'activate' );
		}

		/**
		 * Whether the user has opted in to sharing. Nothing is ever sent without this.
		 *
		 * @return bool
		 */
		protected static function has_consent(): bool {
			return (bool) get_option( static::opt_in_option(), false );
		}

		/**
		 * Stores the first-seen timestamp once, so install age can be reported.
		 */
		protected static function record_install_time(): void {
			$key = 'posimyth_' . static::id() . '_install_time';
			if ( ! get_option( $key ) ) {
				add_option( $key, gmdate( 'Y-m-d H:i:s' ), '', false );
			}
		}

		// Payload.

		/**
		 * Assembles the non-sensitive payload for an event. Contains no personal data.
		 *
		 * @param string $event One of activate|deactivate|heartbeat.
		 * @return array
		 */
		public static function build_payload( string $event ): array {
			global $wpdb;

			$theme        = wp_get_theme();
			$parent       = $theme->parent();
			$active_slugs = (array) get_option( 'active_plugins', array() );
			$license      = static::license();

			$install_time = get_option( 'posimyth_' . static::id() . '_install_time', '' );
			if ( empty( $install_time ) ) {
				$install_time = gmdate( 'Y-m-d H:i:s' );
			}

			return array(
				'plugin_slug'       => static::slug(),
				'site_url'          => home_url(),
				'event'             => $event,

				// Server & environment.
				'php_version'       => phpversion(),
				// Server version string; no WP API exposes it and caching a constant makes no sense.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				'mysql_version'     => $wpdb->get_var( 'SELECT VERSION()' ),
				'server_software'   => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'timezone'          => wp_timezone_string(),
				'debug_mode'        => (int) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				'max_upload_size'   => size_format( wp_max_upload_size() ),
				'memory_limit'      => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
				'permalink'         => static::permalink_label(),
				'is_ssl'            => (int) is_ssl(),
				'is_local'          => static::is_local_site(),
				'environment'       => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',

				// WordPress.
				'wp_version'        => get_bloginfo( 'version' ),
				'locale'            => get_locale(),
				'is_multisite'      => (int) is_multisite(),
				'user_count'        => static::user_count(),

				// Theme.
				'theme'             => $theme->get_stylesheet(),
				'theme_version'     => (string) $theme->get( 'Version' ),
				'parent_theme'      => $parent ? $parent->get_stylesheet() : '',

				// Plugins / ecosystem.
				'page_builder'      => static::detect_page_builder( $active_slugs ),
				'active_plugins'    => array_values( $active_slugs ),
				'plugin_count'      => count( $active_slugs ),
				'posimyth_products' => static::detect_posimyth_products( $active_slugs ),

				// Identity (Astra-style: admin email for Free + Pro, sent only under
				// consent). Raw email is PII — the opt-in notice + readme must disclose it.
				// NOTE: no admin email / no PII is sent — the consent copy promises "no personal data,
			// ever", so the raw admin_email older builds included is intentionally omitted. Do not
			// re-add email or any personal identifier here without changing that copy + the docs.

				// This product.
				'plugin_version'    => static::version(),
				'is_pro'            => (int) static::is_pro(),
				'license_status'    => (string) ( $license['status'] ?? '' ),
				'license_plan'      => (string) ( $license['plan'] ?? '' ),
				'onboarding'        => static::onboarding_status(),
				'install_time'      => $install_time,

				// Feature adoption.
				'enabled_widgets'   => static::enabled_features(),
				'widget_usage'      => static::used_features_cached( $event ),
			);
		}

		/**
		 * Heavy content scans run only on the weekly background heartbeat and are
		 * cached. Activate / deactivate (user-facing actions) read the cache so the
		 * admin never waits on a scan.
		 *
		 * @param string $event Current event; only the heartbeat refreshes the cache.
		 * @return array<string,int>
		 */
		protected static function used_features_cached( string $event ): array {
			$key = 'posimyth_' . static::id() . '_usage';

			if ( 'heartbeat' === $event ) {
				$usage = static::used_features();
				update_option( $key, $usage, false );
				return $usage;
			}

			return (array) get_option( $key, array() );
		}

		// Environment helpers.

		/**
		 * Permalink structure as a short label rather than the raw pattern.
		 *
		 * @return string
		 */
		protected static function permalink_label(): string {
			$structure = get_option( 'permalink_structure', '' );
			if ( '' === $structure ) {
				return 'plain';
			}
			if ( '/%postname%/' === $structure ) {
				return 'post-name';
			}
			if ( false !== strpos( $structure, '%postname%' ) ) {
				return 'custom-postname';
			}
			if ( false !== strpos( $structure, '%year%' ) || false !== strpos( $structure, '%post_id%' ) ) {
				return 'date-or-numeric';
			}
			return 'custom';
		}

		/**
		 * Whether this looks like a local/dev site, so hub stats can exclude it.
		 *
		 * @return int
		 */
		protected static function is_local_site(): int {
			if ( function_exists( 'wp_get_environment_type' ) ) {
				$env = wp_get_environment_type();
				if ( in_array( $env, array( 'local', 'development' ), true ) ) {
					return 1;
				}
			}

			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			if ( '' === $host ) {
				return 0;
			}
			$host = strtolower( $host );

			$exact = array( 'localhost', '127.0.0.1', '::1' );
			if ( in_array( $host, $exact, true ) ) {
				return 1;
			}

			$suffixes = array( '.local', '.test', '.localhost', '.dev', '.example', '.invalid' );
			foreach ( $suffixes as $suffix ) {
				if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
					return 1;
				}
			}
			return 0;
		}

		/**
		 * Total registered users — a size signal only, no user data.
		 *
		 * @return int
		 */
		protected static function user_count(): int {
			$counts = count_users();
			return isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0;
		}

		/**
		 * Which sibling POSIMYTH products are active alongside this one.
		 *
		 * @param array $plugins Active plugin files.
		 * @return array<int,string>
		 */
		protected static function detect_posimyth_products( array $plugins ): array {
			$known = array(
				'nexter-extension'                 => 'nexter-extension',
				'the-plus-addons-for-elementor-page-builder' => 'the-plus-addons-for-elementor',
				'the-plus-addons-for-block-editor' => 'the-plus-addons-for-block-editor',
				'nexter'                           => 'nexter-theme',
				'uichemy'                          => 'uichemy',
				'wdesignkit'                       => 'wdesignkit',
			);

			$found = array();
			foreach ( $plugins as $file ) {
				$dir = explode( '/', $file )[0];
				if ( isset( $known[ $dir ] ) ) {
					$found[ $known[ $dir ] ] = true;
				}
			}
			return array_keys( $found );
		}

		/**
		 * Page builder in use, so conflicts can be reproduced on the right stack.
		 *
		 * @param array $plugins Active plugin files.
		 * @return string
		 */
		protected static function detect_page_builder( array $plugins ): string {
			$known = array(
				'elementor'             => 'elementor',
				'beaver-builder-plugin' => 'beaver-builder',
				'brizy'                 => 'brizy',
				'bricks'                => 'bricks',
				'divi-builder'          => 'divi',
				'js_composer'           => 'wpbakery',
				'oxygen'                => 'oxygen',
				'siteorigin-panels'     => 'siteorigin',
			);

			foreach ( $plugins as $file ) {
				$dir = explode( '/', $file )[0];
				if ( isset( $known[ $dir ] ) ) {
					return $known[ $dir ];
				}
			}
			// Bricks ships as a theme, not a plugin.
			if ( 'bricks' === strtolower( (string) wp_get_theme()->get_template() ) ) {
				return 'bricks';
			}
			return 'gutenberg';
		}

		// Content scanners (reused by TPAE / TPGB subclasses).
		//
		// Bounded + batched: capped at `posimyth_scan_post_cap` rows (default
		// 2000) and run only inside the weekly cron, so large sites stay safe.

		/**
		 * Count Elementor widget usage by widgetType prefix (e.g. 'tp-').
		 *
		 * @param string $prefix Widget name prefix to match, e.g. 'tp-'.
		 * @return array<string,int>
		 */
		protected static function scan_elementor_widgets( string $prefix ): array {
			global $wpdb;

			$counts  = array();
			$cap     = (int) apply_filters( 'posimyth_scan_post_cap', 2000 );
			$batch   = 200;
			$offset  = 0;
			$scanned = 0;
			$like    = '%"widgetType":"' . $wpdb->esc_like( $prefix ) . '%';
			$regex   = '/"widgetType":"(' . preg_quote( $prefix, '/' ) . '[a-z0-9_-]+)"/';

			while ( $scanned < $cap ) {
				// Batched scan of Elementor's own meta blob; no core API can query inside it. Runs only
				// on the weekly cron and the result is cached in an option, so per-query caching would
				// add a second cache layer for a job that already runs at most once a week.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->postmeta}
					 WHERE meta_key = '_elementor_data' AND meta_value LIKE %s
					 LIMIT %d OFFSET %d",
						$like,
						$batch,
						$offset
					)
				);

				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $json ) {
					if ( preg_match_all( $regex, $json, $m ) ) {
						foreach ( $m[1] as $widget ) {
							$counts[ $widget ] = ( $counts[ $widget ] ?? 0 ) + 1;
						}
					}
				}

				$scanned += count( $rows );
				$offset  += $batch;
				if ( count( $rows ) < $batch ) {
					break;
				}
			}

			return $counts;
		}

		/**
		 * Count Gutenberg block usage by namespace (e.g. 'tpgb/').
		 *
		 * @param string $block_namespace Block namespace with trailing slash, e.g. 'tpgb/'.
		 * @return array<string,int>
		 */
		protected static function scan_gutenberg_blocks( string $block_namespace ): array {
			global $wpdb;

			$counts  = array();
			$cap     = (int) apply_filters( 'posimyth_scan_post_cap', 2000 );
			$batch   = 200;
			$offset  = 0;
			$scanned = 0;
			$like    = '%wp:' . $wpdb->esc_like( $block_namespace ) . '%';
			// Negative lookbehind skips the closing "/wp:tpgb/..." comment so each
			// block instance is counted once (opening tag only).
			$regex = '#(?<!/)wp:' . preg_quote( $block_namespace, '#' ) . '([a-z0-9-]+)#';

			while ( $scanned < $cap ) {
				// Batched LIKE over post_content; WP_Query has no equivalent and would load full post
				// objects for rows we only pattern-match. Weekly cron only, result cached in an option.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT post_content FROM {$wpdb->posts}
					 WHERE post_status = 'publish' AND post_content LIKE %s
					 LIMIT %d OFFSET %d",
						$like,
						$batch,
						$offset
					)
				);

				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $content ) {
					if ( preg_match_all( $regex, $content, $m ) ) {
						// Store the bare block slug (e.g. 'tp-accordion') — no namespace,
						// so it survives sanitize_key on the hub and matches the enabled key.
						foreach ( $m[1] as $block ) {
							$counts[ $block ] = ( $counts[ $block ] ?? 0 ) + 1;
						}
					}
				}

				$scanned += count( $rows );
				$offset  += $batch;
				if ( count( $rows ) < $batch ) {
					break;
				}
			}

			return $counts;
		}

		// Transport — fire-and-forget, never blocks the admin.

		/**
		 * Sends one event to the hub. Deferred to shutdown so the admin never waits on it.
		 *
		 * @param string $event          One of activate|deactivate|heartbeat.
		 * @param array  $extra          Extra fields merged over the payload (e.g. a deactivation reason).
		 * @param bool   $bypass_consent Only the deactivation dialog's own Submit may pass true.
		 */
		public static function do_request( string $event, array $extra = array(), bool $bypass_consent = false ): void {
			// Refuse to send without consent unless the caller explicitly says this is the one
			// legitimate exception. That exception is the deactivation dialog: pressing "Submit" there
			// IS the consent for that single submission, so it must work even when the persistent
			// sharing toggle is off. Everything else — activate, deactivate, heartbeat, first ping —
			// leaves this false and is refused if consent is missing, so a future call site cannot
			// bypass consent by simply forgetting to check it.
			if ( ! $bypass_consent && ! static::has_consent() ) {
				return;
			}

			$endpoint = defined( 'POSIMYTH_ANALYTICS_ENDPOINT' )
				? POSIMYTH_ANALYTICS_ENDPOINT
				: 'https://api.posimyth.com/wp-json/posimyth/v1/track';

			$payload = array_merge( static::build_payload( $event ), $extra );
			$body    = wp_json_encode( $payload );

			// No ingest key and no request signature.
			//
			// The hub's endpoint is intentionally open. A shared key would have to be baked
			// identically into every shipped plugin, and those plugins are publicly downloadable, so
			// the key could be read straight out of a ZIP — it authenticated nothing while making
			// rotation impossible. Abuse is bounded hub-side instead (per-IP and per-site rate
			// limits, a plugin_slug allowlist, and strict payload sanitisation).
			$headers = array(
				'Content-Type' => 'application/json',
			);

			// Send AFTER the response is flushed, with a timeout long enough to actually finish.
			//
			// This previously fired inline with 'timeout' => 0.01 and 'blocking' => false. 10ms is
			// far less than a real round trip to the hub (measured ~4s), so cURL aborted during the
			// DNS/TLS phase and the request never arrived — activate / deactivate / heartbeat all
			// silently recorded nothing, with no error anywhere because the response was discarded.
			//
			// Deferring to 'shutdown' keeps the admin request just as fast (the user never waits),
			// while giving the request the time it genuinely needs.
			//
			// An earlier version of this comment talked about an HMAC timestamp staying inside the hub's
			// replay window. There is no HMAC and no replay window — see the note above the headers.
			// Nothing here is signed or timestamped.
			$timeout = (int) apply_filters( 'posimyth_analytics_request_timeout', 15 );

			$dispatch = static function () use ( $endpoint, $headers, $body, $timeout ) {
				// Flush the response first so the send never adds latency to the page.
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}
				wp_remote_post(
					$endpoint,
					array(
						'headers'     => $headers,
						'body'        => $body,
						'timeout'     => $timeout,
						// We are past the response; let the request run to completion so the hub
						// actually receives (and can acknowledge) it.
						'blocking'    => true,
						'data_format' => 'body',
						'sslverify'   => true,
					)
				);
			};

			// If we are already inside shutdown (e.g. called from another shutdown handler), adding
			// the action would never fire — send immediately instead.
			if ( did_action( 'shutdown' ) ) {
				$dispatch();
				return;
			}
			add_action( 'shutdown', $dispatch, 99 );
		}
	}
}
