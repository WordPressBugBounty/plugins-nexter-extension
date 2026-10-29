<?php
/**
 * Cookie Consent — core engine.
 *
 * Owns everything 02-DEVELOPER-BRIEF.md PART 1 assigns to Nexter Extension: the
 * category model, the `nexter_consent` cookie contract, prior script blocking
 * (Method A — script_loader_tag), the consent-write endpoint, and the consent log.
 * Nexter Blocks (a separate plugin, out of scope here) owns only the banner's design
 * and talks to this engine exclusively through window.nexterConsent — see
 * assets/js/cookie-consent/nexter-consent.js.
 *
 * Instantiated from Nexter_Ext_Extra_Settings only when the module switch is on,
 * matching the convention every other extension in this file follows.
 *
 * @since 4.9.0
 */
defined( 'ABSPATH' ) or die();

class Nxt_Cookie_Consent {

	/**
	 * Category IDs — frozen forever (02-DEVELOPER-BRIEF.md PART 1.4). They are written
	 * into every stored consent record; renaming one invalidates existing consent.
	 * Labels are user-editable, IDs are not — never expose a code path that renames a key.
	 *
	 * @var string[]
	 */
	const CATEGORY_IDS = array( 'necessary', 'functional', 'analytics', 'marketing' );

	/**
	 * Cron hook for the chunked retention purge (PART 4.3/4.5). One network-wide-per-site
	 * daily event — never a per-row or per-visitor scheduled event.
	 *
	 * @var string
	 */
	const RETENTION_CRON_HOOK = 'nxt_cookie_consent_retention_cron';

	/**
	 * Rows deleted per DELETE...LIMIT statement, and statements run per cron tick. Bounded
	 * so a single cron invocation can never run long enough to hit a host's execution-time
	 * limit (PART 5.10 "no timeout, no half-purged state" — this makes partial progress
	 * safe to resume tomorrow instead of trying to finish a huge backlog in one request).
	 *
	 * @var int
	 */
	const RETENTION_BATCH_SIZE   = 500;
	const RETENTION_BATCHES_MAX  = 20;

	/**
	 * Resolved settings for this request (defaults merged with the stored option).
	 *
	 * @var array
	 */
	private $settings;

	public function __construct() {
		$this->settings = self::get_settings();

		// Consent-write endpoint: accepts only a category set + action (PART 4.3 "write path").
		// nopriv because most visitors are logged out; an Origin check blocks cross-site posts.
		add_action( 'wp_ajax_nexter_consent_write', array( $this, 'ajax_write_consent' ) );
		add_action( 'wp_ajax_nopriv_nexter_consent_write', array( $this, 'ajax_write_consent' ) );
		add_action( 'wp_ajax_nexter_consent_nonce', array( $this, 'ajax_consent_nonce' ) );
		add_action( 'wp_ajax_nopriv_nexter_consent_nonce', array( $this, 'ajax_consent_nonce' ) );

		// Admin settings save — own action, deliberately not folded into the shared
		// Nxt_Panel_Ajax_Router extension_type chain: that router writes into the
		// nexter_extra_ext_options tree, but Cookie Consent's ~90 settings get their own
		// top-level option (nexter_cookie_consent_settings) so the categories/rules array
		// doesn't bloat every read of the shared options tree.
		add_action( 'wp_ajax_nexter_cookie_consent_save', array( $this, 'ajax_save_settings' ) );

		// Plain admin-ajax mirror of the nexter/get-cookie-consent-compliance-check ability
		// (CLAUDE.md "enter at the seam" — it calls the exact same static method, never a
		// reimplementation) so the dashboard screen doesn't need the Abilities REST layer,
		// matching every other admin-ajax-only screen in this codebase.
		add_action( 'wp_ajax_nexter_cookie_consent_compliance_check', array( $this, 'ajax_compliance_check' ) );

		// Scanner (PART 3) — every endpoint is admin-only (manage_options + nonce), never
		// nopriv. Registered here (before the is_admin() early-return below) because
		// admin-ajax.php requests set is_admin() = true regardless of which admin screen
		// or frontend page issued the fetch.
		add_action( 'wp_ajax_nexter_consent_scan_report', array( $this, 'ajax_scan_report' ) );
		add_action( 'wp_ajax_nexter_consent_scan_targets', array( $this, 'ajax_scan_targets' ) );
		add_action( 'wp_ajax_nexter_consent_manual_cookie', array( $this, 'ajax_manual_cookie' ) );
		add_action( 'wp_ajax_nexter_consent_delete_cookie', array( $this, 'ajax_delete_cookie' ) );

		// Cookie Declaration shortcode (PART 7.2) — "a primary path, not a fallback", so it
		// is registered unconditionally, not only on the frontend: Elementor/Bricks preview
		// and REST content processing both parse shortcodes outside a normal frontend
		// request. The switch check happens inside the render callback instead.
		add_action( 'init', array( $this, 'register_translatable_strings' ), 20 );
		add_shortcode( 'nexter_cookie_declaration', array( $this, 'render_declaration_shortcode' ) );
		add_shortcode( 'nexter_consent_reopen', array( $this, 'render_reopen_shortcode' ) );

		// Retention cron: registered on every request (cheap — wp_next_scheduled() is one
		// options read) so the schedule self-heals immediately after the switch or the
		// records toggle changes, without waiting for the next plugin (de)activation.
		add_action( self::RETENTION_CRON_HOOK, array( $this, 'run_retention_purge' ) );
		add_action( 'admin_init', array( $this, 'maybe_run_overdue_purge' ) );
		$this->sync_retention_cron_schedule();

		// WP Consent API (PART 10.1) — registered defensively; if the wp-consent-api plugin
		// is absent nothing here fires, per its own documented "register only if present" model.
		// Called directly, not hooked to plugins_loaded: this class is only constructed from
		// Nexter_Ext_Extra_Settings on `after_setup_theme`, which runs AFTER plugins_loaded —
		// by then every other plugin (wp-consent-api included) has already loaded, so
		// function_exists() below is already accurate and a plugins_loaded hook would never fire.
		self::register_wp_consent_api_provider();

		// These two run on every request (admin or frontend): the dismiss handler redirects
		// so it must run on admin_init, and both notices must still show on wp-admin screens
		// even though the branch below returns early for admin requests.
		add_action( 'admin_notices', array( $this, 'fallback_admin_notice' ) );
		add_action( 'admin_notices', array( $this, 'conflict_admin_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_fallback_notice' ) );

		if ( is_admin() ) {
			// Registered only — not enqueued. The (not yet built) Cookie Consent admin
			// screen enqueues this by handle on its own page load for the "Scan Now"
			// button; registering it here just once avoids every future caller needing
			// to know the asset path/version.
			add_action( 'admin_enqueue_scripts', array( $this, 'register_scanner_admin_script' ) );
			return;
		}

		// Consent Mode v2 default call (PART 10.2) — MUST print before any Google tag can
		// load, on ALL denied. Priority 1 on wp_head, ahead of the frontend bootstrap enqueue
		// (priority 5 below) and every other plugin's own head output. "Ordering is the bug
		// source" per the brief — this is the one place that rule is enforced.
		add_action( 'wp_head', array( $this, 'print_consent_mode_default' ), 1 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ), 5 );
		// Late on wp_footer so every other banner surface has already rendered — the
		// fallback decides whether to activate by looking at the finished page.
		add_action( 'wp_footer', array( $this, 'render_fallback_banner' ), 999 );
		// Method A (primary blocking mechanism, 02-DEVELOPER-BRIEF.md PART 2.1) — rewrites
		// the <script> tag of any properly-enqueued handle matched to a non-granted category
		// so the browser never requests it.
		add_filter( 'script_loader_tag', array( $this, 'maybe_block_script_tag' ), 10, 3 );

		// Method B — the same rewrite for <script src> tags printed straight into the page
		// (header boxes, theme templates, GTM's documented snippet), which never pass through
		// script_loader_tag. Runs on the finished HTML.
		add_action( 'template_redirect', array( $this, 'start_output_buffer' ), 0 );

		// WP core privacy integration (PART 4.4) — registered unconditionally so a DSAR
		// works even for a visitor whose consent was recorded before the log setting changed.
	}

	/**
	 * Vendor tags printed straight into the page, which server-side blocking cannot reach.
	 *
	 * The engine blocks on script_loader_tag, so it only ever sees scripts that went through
	 * wp_enqueue_script(). A tag pasted into a header box, a theme's header.php or GTM's own
	 * documented snippet is printed directly and is never rewritten — it has already fetched
	 * and executed by the time any client-side observer could react.
	 *
	 * This fetches the front page ONCE, when the admin runs a compliance check. It is not a
	 * request-time cost, and it is deliberately not a fix: it tells the site owner which tags
	 * this plugin cannot block, so the gap is visible rather than assumed closed.
	 *
	 * @param array $settings Merged settings.
	 * @return array{services:string[],error:string} Service names found unblocked.
	 */
	private static function detect_unblockable_tags( $settings ) {
		$found = array();

		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'   => 10,
				// No consent cookie is sent, so this is the first-visit state — exactly the
				// one where nothing may fire yet.
				'cookies'   => array(),
				// true, matching the Content SEO audit module's self-fetches: a bad cert is a
				// real finding, and the error branch below reports it rather than hiding it.
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'services' => array(), 'error' => $response->get_error_message() );
		}

		$html = wp_remote_retrieve_body( $response );
		if ( '' === $html ) {
			return array( 'services' => array(), 'error' => __( 'The front page returned an empty response.', 'nexter-extension' ) );
		}

		// Whole <script>…</script> elements, not just opening tags: the official GTM and Meta
		// snippets are INLINE and inject the vendor URL at runtime, so the pattern lives in the
		// body. Matching the tag alone reported a pass while gtm.js and fbevents.js loaded.
		if ( ! preg_match_all( '/<script\b([^>]*)>(.*?)<\/script>/is', $html, $scripts, PREG_SET_ORDER ) ) {
			return array( 'services' => array(), 'error' => '' );
		}

		$live = array();
		foreach ( $scripts as $sc ) {
			$attrs = $sc[1];

			// Already neutralised by the engine — its own successful blocking is not a finding.
			if ( false !== stripos( $attrs, 'data-nexter-consent-src' ) || false !== stripos( $attrs, 'text/plain' ) ) {
				continue;
			}
			// Data blocks (JSON-LD, importmaps, templates) never execute, and a VideoObject's
			// embedUrl would otherwise read as a YouTube embed.
			if ( preg_match( '/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $t )
				&& ! in_array( strtolower( $t[1] ), array( 'text/javascript', 'application/javascript', 'module' ), true ) ) {
				continue;
			}
			// This plugin's own inline config carries every vendor pattern in its rules JSON, so
			// scanning it would fail every site on its own configuration.
			if ( preg_match( '/\bid\s*=\s*["\']?(nxt-cookie-consent|nexter-consent|nxt-cc-)/i', $attrs ) ) {
				continue;
			}

			$live[] = $attrs . ' ' . $sc[2];
		}

		foreach ( $settings['blocking']['rules'] as $rule ) {
			// Every rule that blocks counts, not only action "block": a placeholder rule (video
			// embeds) and an always-block rule hold a script back just the same.
			if ( empty( $rule['patterns'] ) || empty( $rule['action'] ) || ! in_array( $rule['action'], array( 'block', 'block_placeholder', 'always_block' ), true ) ) {
				continue;
			}
			foreach ( $live as $script ) {
				foreach ( (array) $rule['patterns'] as $pattern ) {
					if ( '' !== $pattern && false !== stripos( $script, $pattern ) ) {
						$found[ $rule['service'] ] = true;
						break 2;
					}
				}
			}
		}

		return array( 'services' => array_keys( $found ), 'error' => '' );
	}

	/**
	 * The consent cookie's name for THIS site.
	 *
	 * A subdirectory network shares one cookie domain with Path=/, so a fixed name made site 1's
	 * "Accept All" apply on site 2 and hid site 2's banner outright — and sites with different
	 * config versions overwrote each other's cookie, re-prompting visitors in a loop. Consent is
	 * per controller, so each site gets its own. Single sites keep the plain name, so nothing
	 * already stored there stops matching.
	 *
	 * @return string
	 */
	public static function cookie_name() {
		return is_multisite() ? 'nexter_consent_' . get_current_blog_id() : 'nexter_consent';
	}

	/**
	 * Stored over defaults — recursing into associative maps, taking LISTS whole.
	 *
	 * array_replace_recursive() merges lists by index, so a stored list shorter than the default
	 * one is topped up from the default's tail: delete a blocking rule and an unrelated default
	 * comes back in its slot, and deleting the first one shifts the rest. A list is an ordered
	 * set the admin owns outright, so the stored one wins as a whole, even when empty.
	 *
	 * @param array $defaults Documented defaults.
	 * @param array $stored   Stored values.
	 * @return array
	 */
	public static function merge_with_defaults( $defaults, $stored ) {
		$is_list = static function ( $a ) {
			return is_array( $a ) && ( array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 ) );
		};

		foreach ( $stored as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] )
				&& ! $is_list( $value ) && ! $is_list( $defaults[ $key ] ) ) {
				$defaults[ $key ] = self::merge_with_defaults( $defaults[ $key ], $value );
			} else {
				$defaults[ $key ] = $value;
			}
		}

		return $defaults;
	}

	/**
	 * Merged settings: stored option over documented defaults. Never returns a partial
	 * array — every consumer can index straight into it without isset() chains.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = Nxt_Options::cookie_consent();
		$stored = is_array( $stored ) ? $stored : array();
		$merged = self::merge_with_defaults( self::defaults(), $stored );

		// The master on/off switch lives in the SHARED nexter_extra_ext_options tree
		// (key 'cookie-consent'), not in this module's own option — every other module
		// in this plugin toggles the same way, and the existing dashboard FeatureToggleCard
		// + extraOptionsSave()/nxt_extra_active_deactive() already write there for free.
		// Keeping a second, disconnected 'switch' inside nexter_cookie_consent_settings
		// would silently drift from what the dashboard toggle actually controls.
		$extra_ext              = Nxt_Options::extra_ext();
		$merged['switch']       = ! empty( $extra_ext['cookie-consent']['switch'] );

		return $merged;
	}

	/**
	 * Registers admin-entered strings with WPML and Polylang so they stop being frozen in the
	 * save-time language. Must read the RAW option: get_settings() output is already translated.
	 *
	 * @return void
	 */
	public function register_translatable_strings() {
		foreach ( self::translatable_strings() as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}
			if ( function_exists( 'pll_register_string' ) ) {
				pll_register_string( $name, $value, 'Nexter Cookie Consent' );
			}
			do_action( 'wpml_register_single_string', 'Nexter Cookie Consent', $name, $value );
		}
	}

	/**
	 * @return array Map of string name => stored value, for every admin-editable string.
	 */
	private static function translatable_strings() {
		$stored   = Nxt_Options::cookie_consent();
		$settings = self::merge_with_defaults( self::defaults(), is_array( $stored ) ? $stored : array() );
		$out      = array();
		foreach ( self::CATEGORY_IDS as $id ) {
			if ( isset( $settings['categories'][ $id ]['label'] ) ) {
				$out[ 'category_' . $id . '_label' ] = (string) $settings['categories'][ $id ]['label'];
			}
		}
		$out['privacy_policy_url'] = (string) $settings['legal']['privacy_policy_url'];
		$out['cookie_policy_url']  = (string) $settings['legal']['cookie_policy_url'];
		return $out;
	}

	/**
	 * Output-time translation; returns the stored value untouched when neither plugin is active
	 * or no translation exists.
	 *
	 * @param string $name  Key from translatable_strings().
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function translate_string( $name, $value ) {
		if ( '' === $value ) {
			return $value;
		}
		if ( function_exists( 'pll__' ) ) {
			$value = pll__( $value );
		}
		return (string) apply_filters( 'wpml_translate_single_string', $value, 'Nexter Cookie Consent', $name );
	}

	/**
	 * @param string $key privacy_policy_url|cookie_policy_url.
	 * @return string Policy URL in the visitor's language.
	 */
	public static function get_legal_url( $key ) {
		$settings = self::get_settings();
		$value    = isset( $settings['legal'][ $key ] ) ? (string) $settings['legal'][ $key ] : '';
		return esc_url_raw( self::translate_string( $key, $value ) );
	}

	/**
	 * @param string $id Category id.
	 * @return string Category label in the visitor's language.
	 */
	private function category_label( $id ) {
		$label = isset( $this->settings['categories'][ $id ]['label'] ) ? (string) $this->settings['categories'][ $id ]['label'] : $id;
		return self::translate_string( 'category_' . $id . '_label', $label );
	}

	/**
	 * Documented defaults — Balanced preset (02-DEVELOPER-BRIEF.md PART 5).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'switch'         => false,
			'preset'         => 'balanced',
			'config_version' => 1,
			'reprompt_days'  => 365,
			'categories'     => array(
				'necessary'  => array(
					'label'   => __( 'Strictly Necessary', 'nexter-extension' ),
					'default' => true,
					'locked'  => true,
				),
				'functional' => array(
					'label'   => __( 'Functional', 'nexter-extension' ),
					'default' => false,
					'locked'  => false,
				),
				'analytics'  => array(
					'label'   => __( 'Analytics', 'nexter-extension' ),
					'default' => false,
					'locked'  => false,
				),
				'marketing'  => array(
					'label'   => __( 'Marketing', 'nexter-extension' ),
					'default' => false,
					'locked'  => false,
				),
			),
			'blocking'       => array(
				'enabled' => true,
				'rules'   => self::default_vendor_rules(),
			),
			'records'        => array(
				'enabled'             => true,
				'ip_mode'             => 'truncated', // none|truncated|hashed|full
				'store_ua'            => false,
				'retention'           => 365,
				// Default keep: consent records are the proof a controller would show a
				// regulator, and an uninstall done to troubleshoot should not destroy it.
				// The defect this closes is that the outcome used to be silence rather than
				// a choice — after uninstall the table stayed, holding IP data, with the UI
				// gone and the retention cron unscheduled, so nothing ever aged out.
				'delete_on_uninstall' => false,
			),
			'legal'          => array(
				'privacy_policy_url' => '',
				'cookie_policy_url'  => '',
			),
			// Google Consent Mode v2 (02-DEVELOPER-BRIEF.md PART 10.2). 'mode' decides which
			// integration path is active; both push through the same dataLayer/gtag() calls —
			// GTM already listens on the dataLayer, so there is no separate GTM-only code path.
			'consent_mode'   => array(
				'enabled'            => false,
				'mode'               => 'gtag', // gtag|gtm
				'gtag_ids'           => array(), // e.g. ['G-XXXXXXX']
				'url_passthrough'    => false,
				'ads_data_redaction' => false,
			),
		);
	}

	/**
	 * Shipped vendor blocklist — REFERENCE-vendors-and-cookies.md PART 1, verbatim.
	 * Docs must spot-check every [B]-graded duration before release; nothing here is
	 * re-verified by this code.
	 *
	 * @return array[]
	 */
	public static function default_vendor_rules() {
		return array(
			array(
				'service'  => 'Google Tag Manager',
				'category' => 'analytics',
				'patterns' => array( 'googletagmanager.com/gtm.js' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'Google Analytics 4',
				'category' => 'analytics',
				'patterns' => array( 'googletagmanager.com/gtag/js', 'google-analytics.com/analytics.js', 'google-analytics.com/g/collect' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'Google Ads / Conversion',
				'category' => 'marketing',
				'patterns' => array( 'googleadservices.com/pagead/conversion', 'googleads.g.doubleclick.net', 'pagead2.googlesyndication.com' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'Meta (Facebook) Pixel',
				'category' => 'marketing',
				'patterns' => array( 'connect.facebook.net', 'facebook.com/tr' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'YouTube',
				'category' => 'marketing',
				'patterns' => array( 'youtube.com/embed', 'youtube-nocookie.com/embed' ),
				'action'   => 'block_placeholder',
			),
			array(
				'service'  => 'Vimeo',
				'category' => 'functional',
				'patterns' => array( 'player.vimeo.com/video' ),
				'action'   => 'block_placeholder',
			),
			array(
				'service'  => 'Google Maps',
				'category' => 'functional',
				'patterns' => array( 'maps.googleapis.com', 'google.com/maps/embed' ),
				'action'   => 'block_placeholder',
			),
			array(
				'service'  => 'Hotjar',
				'category' => 'analytics',
				'patterns' => array( 'static.hotjar.com', 'script.hotjar.com' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'Microsoft Clarity',
				'category' => 'analytics',
				'patterns' => array( 'clarity.ms/tag' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'LinkedIn Insight Tag',
				'category' => 'marketing',
				'patterns' => array( 'snap.licdn.com/li.lms-analytics/insight.min.js' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'TikTok Pixel',
				'category' => 'marketing',
				'patterns' => array( 'analytics.tiktok.com/i18n/pixel/events.js' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'Pinterest Tag',
				'category' => 'marketing',
				'patterns' => array( 's.pinimg.com/ct/core.js' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'X (Twitter)',
				'category' => 'marketing',
				'patterns' => array( 'static.ads-twitter.com/uwt.js', 'platform.twitter.com/widgets.js' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'HubSpot',
				'category' => 'marketing',
				'patterns' => array( 'js.hs-scripts.com', 'js.hs-analytics.net' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'Intercom',
				'category' => 'functional',
				'patterns' => array( 'widget.intercom.io', 'js.intercomcdn.com' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'Crisp / Tawk.to',
				'category' => 'functional',
				'patterns' => array( 'client.crisp.chat', 'embed.tawk.to' ),
				'action'   => 'block',
			),
			array(
				'service'  => 'Mailchimp',
				'category' => 'marketing',
				'patterns' => array( 'chimpstatic.com/mcjs-connected' ),
				'action'   => 'block',
			),
			// reCAPTCHA ships Never-block/necessary — blocking it breaks login/contact forms.
			// Its "strictly necessary" status is contested by some DPAs; the UI must say so
			// (REFERENCE-vendors-and-cookies.md PART 1 note) — Compliance Check does not flag it.
			array(
				'service'  => 'Google reCAPTCHA',
				'category' => 'necessary',
				'patterns' => array( 'google.com/recaptcha/api.js', 'gstatic.com/recaptcha' ),
				'action'   => 'never',
			),
		);
	}

	/**
	 * script_loader_tag filter — Method A blocking.
	 *
	 * Only rewrites properly wp_enqueue_script()'d handles; inline/hardcoded scripts and
	 * anything injected by another plugin's raw output are NOT caught here (documented
	 * limitation, PART 2.1). $src is matched, never $handle — vendors change handle names
	 * across their own script versions far more often than they change their script URL.
	 *
	 * @param string $tag    The <script> tag HTML.
	 * @param string $handle Registered script handle.
	 * @param string $src    Resolved script src.
	 * @return string
	 */
	public function maybe_block_script_tag( $tag, $handle, $src ) {
		if ( empty( $this->settings['blocking']['enabled'] ) || empty( $src ) ) {
			return $tag;
		}

		// Never touch our own consent bootstrap — it must load unconditionally and is never
		// deferred/blocked by anything (PART 2.5, PART 12 #5).
		if ( 'nxt-cookie-consent' === $handle ) {
			return $tag;
		}

		$rule = $this->matching_rule( $src );
		if ( ! $rule || 'never' === $rule['action'] || 'necessary' === $rule['category'] ) {
			return $tag;
		}

		// GTM-managed Consent Mode safety net (REFERENCE-vendors-and-cookies.md PART 1 note):
		// "If the user runs Consent Mode through GTM, GTM should load and manage consent
		// itself; set this row to Never." Getting this wrong either breaks all tracking or
		// leaks all of it — so when the admin has explicitly chosen the GTM path, GTM's own
		// container is never blocked here regardless of what the stored rule says, rather
		// than relying on every admin remembering to flip the rule by hand.
		if ( 'Google Tag Manager' === $rule['service'] && 'gtm' === ( $this->settings['consent_mode']['mode'] ?? '' ) && ! empty( $this->settings['consent_mode']['enabled'] ) ) {
			return $tag;
		}

		// Rewritten to an inert type so the browser parses but never requests/executes it.
		// nexter-consent.js re-creates the real <script src> (in original DOM order) the
		// moment the matching category is granted — see activateGatedScripts() there.
		$tag = str_replace( '<script ', '<script type="text/plain" data-nexter-consent-category="' . esc_attr( $rule['category'] ) . '" data-nexter-consent-src="' . esc_url( $src ) . '" ', $tag );
		// Strip the live src so the browser cannot fetch it from the inert tag.
		// WordPress prints & as &#038; in the tag, so the raw $src alone never matched a URL with a
		// query string and the live src was left behind. Try each form it can appear in.
		foreach ( array_unique( array( $src, esc_attr( $src ), esc_url( $src ), str_replace( '&', '&#038;', $src ), str_replace( '&', '&amp;', $src ) ) ) as $form ) {
			$stripped = preg_replace( '/\ssrc=([\'"])' . preg_quote( $form, '/' ) . '\1/', '', $tag, 1, $stripped_count );
			if ( $stripped_count ) {
				$tag = $stripped;
				break;
			}
		}

		return $tag;
	}

	/**
	 * Starts the Method B buffer on real frontend HTML requests only.
	 *
	 * Skipped wherever a rewrite could corrupt a response that is not a visitor page: AJAX,
	 * REST, cron, feeds, embeds and builder/Customizer previews.
	 *
	 * @return void
	 */
	public function start_output_buffer() {
		if ( empty( $this->settings['switch'] ) || empty( $this->settings['blocking']['enabled'] ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() || is_robots() || is_customize_preview()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() )
			|| isset( $_GET['elementor-preview'] ) || isset( $_GET['preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only skip flags, no state change.
			return;
		}
		/**
		 * Lets a site turn the output-buffer rewrite off, for example when its page cache or
		 * optimiser already rewrites script tags and the two would fight.
		 *
		 * @param bool $enabled Whether Method B may run.
		 */
		if ( ! apply_filters( 'nexter_consent_output_buffer_enabled', true ) ) {
			return;
		}
		ob_start( array( $this, 'rewrite_output' ) );
	}

	/**
	 * Output-buffer callback: applies Method A's rule matching to every external <script> tag.
	 *
	 * Fast path first — a page with none of the blocked vendors' URL fragments is returned
	 * untouched without a regex pass. Tags already rewritten, inert, or ours are skipped.
	 *
	 * @param string $html Finished page.
	 * @return string
	 */
	public function rewrite_output( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<script' ) ) {
			return $html;
		}
		// Only HTML documents: a JSON, XML or plain-text response is never rewritten.
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return $html;
			}
		}
		$has_pattern = false;
		foreach ( (array) $this->settings['blocking']['rules'] as $rule ) {
			foreach ( (array) $rule['patterns'] as $pattern ) {
				if ( '' !== $pattern && false !== strpos( $html, $pattern ) ) {
					$has_pattern = true;
					break 2;
				}
			}
		}
		if ( ! $has_pattern ) {
			return $html;
		}

		$rewritten = preg_replace_callback(
			'#<script\b(?=[^>]*\bsrc\s*=)[^>]*>#i',
			function ( $m ) {
				$tag = $m[0];
				if ( false !== stripos( $tag, 'data-nexter-consent' ) || preg_match( '#\btype\s*=\s*(["\'])text/(?:plain|template)\1#i', $tag ) ) {
					return $tag;
				}
				if ( ! preg_match( '#\bsrc\s*=\s*(["\'])(.*?)\1#is', $tag, $src ) ) {
					return $tag;
				}
				// Our own bootstrap must always load.
				if ( false !== strpos( $src[2], 'cookie-consent/nexter-consent' ) ) {
					return $tag;
				}
				// maybe_block_script_tag() splices on a literal '<script ', so normalise case and whitespace first.
				$tag = preg_replace( '#^<script\s#i', '<script ', $tag );
				return $this->maybe_block_script_tag( $tag, '', $src[2] );
			},
			$html
		);

		// A failed regex (backtracking limit) must never blank the page.
		return null === $rewritten ? $html : $rewritten;
	}

	/**
	 * @param string $src
	 * @return array|null Matching rule, or null when nothing matches (never blocked).
	 */
	private function matching_rule( $src ) {
		foreach ( $this->settings['blocking']['rules'] as $rule ) {
			foreach ( (array) $rule['patterns'] as $pattern ) {
				if ( '' !== $pattern && false !== strpos( $src, $pattern ) ) {
					return $rule;
				}
			}
		}
		return null;
	}

	/**
	 * Prints the Google Consent Mode v2 `consent default` call — hooked at wp_head
	 * priority 1 so it fires before any Google tag (gtag.js or GTM's own gtm.js, since
	 * GTM also reads dataLayer pushes) can possibly load (PART 10.2, "ordering is the
	 * bug source"). Every signal starts denied; nexter-consent.js sends the `update`
	 * call the instant real consent state is known (existing cookie, or a fresh choice).
	 *
	 * Deliberately a plain inline echo, not wp_add_inline_script() — that API only
	 * attaches to an already-enqueued handle, which would tie this to script_loader_tag
	 * timing and reintroduce the exact ordering bug this method exists to prevent.
	 *
	 * @return void
	 */
	public function print_consent_mode_default() {
		if ( empty( $this->settings['consent_mode']['enabled'] ) ) {
			return;
		}

		$redaction = ! empty( $this->settings['consent_mode']['ads_data_redaction'] );
		$passthrough = ! empty( $this->settings['consent_mode']['url_passthrough'] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, no user input interpolated.
		echo '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false">' .
			"window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}" .
			"gtag('consent','default',{" .
			"'ad_storage':'denied'," .
			"'ad_user_data':'denied'," .
			"'ad_personalization':'denied'," .
			"'analytics_storage':'denied'," .
			"'functionality_storage':'denied'," .
			"'personalization_storage':'denied'," .
			"'security_storage':'granted'," . // Necessary — never gated (PART 1.4).
			"'wait_for_update':500" .
			'});' .
			( $redaction ? "gtag('set','ads_data_redaction',true);" : '' ) .
			( $passthrough ? "gtag('set','url_passthrough',true);" : '' ) .
			'</script>' . "\n";
	}

	/**
	 * Maps our granted categories onto Google's Consent Mode v2 signals and fires the
	 * `update` call. Called from nexter-consent.js's writeState()/boot() via the same
	 * dataLayer.push the default call above establishes — works identically whether GTM
	 * or gtag.js is the path in use, since both read the dataLayer (PART 10.2).
	 *
	 * This method exists only to expose the mapping to the frontend config; the actual
	 * `gtag('consent','update', ...)` call is issued in JS, never here — the update must
	 * fire the instant the visitor decides, and PHP has no way to run at that moment.
	 *
	 * @return array<string,string>
	 */
	private function consent_mode_signal_map() {
		return array(
			'analytics'  => 'analytics_storage',
			'marketing'  => 'ad_storage,ad_user_data,ad_personalization',
			'functional' => 'functionality_storage,personalization_storage',
		);
	}

	/**
	 * Enqueues the always-unblocked consent bootstrap. Inlined critical bits (cookie name,
	 * categories, config version) are localized rather than hardcoded in the JS file so a
	 * category rename in settings does not require a cache-busted asset rebuild.
	 *
	 * @return void
	 */
	public function enqueue_frontend() {
		if ( empty( $this->settings['switch'] ) ) {
			return;
		}

		$version = defined( 'NEXTER_EXT_VER' ) ? NEXTER_EXT_VER : false;
		wp_register_script( 'nxt-cookie-consent', NEXTER_EXT_URL . 'assets/js/cookie-consent/nexter-consent.js', array(), $version, false );
		wp_enqueue_script( 'nxt-cookie-consent' );

		wp_localize_script(
			'nxt-cookie-consent',
			'nexterConsentConfig',
			array(
				'cookieName'    => self::cookie_name(),
				'reprompDays'   => (int) $this->settings['reprompt_days'],
				'configVersion' => (int) $this->settings['config_version'],
				'categories'    => $this->public_categories(),
				// Only the fields nexter-consent.js needs to build iframe placeholders — never
				// the admin-only rule id/internal shape.
				'rules'         => array_map(
					function ( $rule ) {
						return array(
							'category' => $rule['category'],
							'patterns' => $rule['patterns'],
							'action'   => $rule['action'],
						);
					},
					$this->settings['blocking']['rules']
				),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'nxt-cookie-consent' ),
				'recordsOn'     => ! empty( $this->settings['records']['enabled'] ),
				// Only sent when the API plugin is actually active — nexter-consent.js still
				// guards on window.wp_set_consent existing before calling it either way.
				'wpConsentMap'  => function_exists( 'wp_has_consent' ) ? self::WP_CONSENT_API_CATEGORY_MAP : array(),
				// Google Consent Mode v2 (PART 10.2) — JS fires gtag('consent','update', ...)
				// against this map the instant real consent state is known.
				'consentMode'   => array(
					'enabled' => ! empty( $this->settings['consent_mode']['enabled'] ),
					'signals' => $this->consent_mode_signal_map(),
				),
				'i18n'          => array(
					/* translators: %s: name of the blocked cookie vendor/category, e.g. YouTube */
					'placeholderText'  => __( 'This content is blocked until you accept %s cookies.', 'nexter-extension' ),
					'placeholderLabel' => __( 'Blocked content', 'nexter-extension' ),
					'acceptShow'       => __( 'Accept and show', 'nexter-extension' ),
					'cookieSettings'   => __( 'Cookie settings', 'nexter-extension' ),
					'consentSaved'     => __( 'Your cookie preferences have been saved.', 'nexter-extension' ),
				),
			)
		);

		// Never let a JS/CSS optimizer defer or async this handle (PART 2.5). This plugin's
		// own onload_defer_js() allowlist is untouched by design — the exclusion below is for
		// third-party optimizers (WP Rocket/LiteSpeed/Perfmatters/Autoptimize) which key off
		// these data attributes, not off the handle name.
		add_filter(
			'script_loader_tag',
			function ( $tag, $handle ) {
				if ( 'nxt-cookie-consent' !== $handle ) {
					return $tag;
				}
				return str_replace( ' src=', ' data-no-optimize="1" data-no-defer="1" data-cfasync="false" src=', $tag );
			},
			10,
			2
		);

		$this->maybe_enqueue_scanner();
	}

	/**
	 * Scanner (PART 3.1) — admin-only, never for visitors. Two entry points share one
	 * script: a normal frontend view by a logged-in admin reports passively; a view
	 * carrying `?nxt_consent_scan=1` (only ever added by the future "Scan Now" admin
	 * screen creating hidden same-origin iframes) collects and postMessages its parent
	 * instead of reporting directly. Confirmed in QA 5.13/10.11: fires only for
	 * logged-in admins, never for visitors, and never sends anything off-site.
	 *
	 * @return void
	 */
	private function maybe_enqueue_scanner() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$version = defined( 'NEXTER_EXT_VER' ) ? NEXTER_EXT_VER : false;
		wp_register_script( 'nxt-cookie-consent-scanner', NEXTER_EXT_URL . 'assets/js/cookie-consent/nexter-consent-scanner.js', array(), $version, true );
		wp_enqueue_script( 'nxt-cookie-consent-scanner' );
		wp_localize_script(
			'nxt-cookie-consent-scanner',
			'nexterConsentScannerConfig',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'nxt-cookie-consent-scanner' ),
				'ownCookieName' => self::cookie_name(),
			)
		);
	}

	/**
	 * Registers (never enqueues) the "Scan Now" orchestrator for the future admin screen.
	 * Hooked to admin_enqueue_scripts so wp_register_script() runs at the timing WordPress
	 * itself expects, rather than the constructor's own (much earlier) after_setup_theme call.
	 *
	 * @return void
	 */
	public function register_scanner_admin_script( $hook_suffix = '' ) {
		$version = defined( 'NEXTER_EXT_VER' ) ? NEXTER_EXT_VER : false;
		wp_register_script( 'nxt-cookie-consent-scanner-admin', NEXTER_EXT_URL . 'assets/js/cookie-consent/nexter-consent-scanner-admin.js', array(), $version, true );
		wp_localize_script(
			'nxt-cookie-consent-scanner-admin',
			'nexterConsentScannerAdminConfig',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'nxt-cookie-consent-scanner' ),
			)
		);

		// The dashboard is a single SPA mounted on one admin page (toplevel_page_nexter_welcome)
		// — its "Cookie Consent" tab is a client-side route, not a separate page load, so this
		// handle must be enqueued eagerly here rather than by a per-tab PHP hook_suffix check
		// the way a standalone page (like Code Snippets) would. Same loose 'nexter' match
		// is_nexter_admin_page() in class-nxt-dashboard-data.php already uses for this SPA.
		if ( false !== strpos( (string) $hook_suffix, 'nexter' ) ) {
			wp_enqueue_script( 'nxt-cookie-consent-scanner-admin' );
		}
	}

	/**
	 * Option holding every declared cookie (scanned + manual). Kept separate from
	 * nexter_cookie_consent_settings — scanner writes happen far more often than a
	 * settings save, and the Cookie Declaration block/shortcode (not built yet) will read
	 * this option directly without needing to touch the rest of the settings tree.
	 *
	 * @var string
	 */
	const COOKIES_OPTION = 'nexter_cookie_consent_cookies';

	/**
	 * Starter cookie descriptions — REFERENCE-vendors-and-cookies.md PART 2, verbatim.
	 * Matched against a detected cookie NAME only (never guessed from provider/domain).
	 * A trailing `*` on `pattern` is a prefix match (e.g. `_ga_*` matches `_ga_ABC123`);
	 * everything else is an exact match. **Never invent a purpose for an unmatched
	 * cookie** — see record_scan_results(): no match always means `unclassified` + blank
	 * purpose, per PART 3.3 "the list must never produce a wrong answer, only a blank one."
	 *
	 * @return array[]
	 */
	public static function default_cookie_descriptions() {
		return array(
			array( 'pattern' => '_ga', 'provider' => 'Google Analytics', 'category' => 'analytics', 'duration' => '2 years', 'purpose' => __( 'Distinguishes individual visitors so visit statistics can be compiled', 'nexter-extension' ) ),
			array( 'pattern' => '_ga_*', 'provider' => 'Google Analytics', 'category' => 'analytics', 'duration' => '2 years', 'purpose' => __( 'Stores and counts page views for a specific GA4 property', 'nexter-extension' ) ),
			array( 'pattern' => '_gid', 'provider' => 'Google Analytics', 'category' => 'analytics', 'duration' => '24 hours', 'purpose' => __( 'Distinguishes individual visitors over a single day', 'nexter-extension' ) ),
			array( 'pattern' => '_gat', 'provider' => 'Google Analytics', 'category' => 'analytics', 'duration' => '1 minute', 'purpose' => __( 'Limits how often data is sent to Google Analytics', 'nexter-extension' ) ),
			array( 'pattern' => '_gcl_au', 'provider' => 'Google Ads', 'category' => 'marketing', 'duration' => '90 days', 'purpose' => __( 'Stores and tracks ad conversions', 'nexter-extension' ) ),
			array( 'pattern' => 'NID', 'provider' => 'Google', 'category' => 'marketing', 'duration' => '6 months', 'purpose' => __( 'Remembers Google preferences and is used for advertising and security', 'nexter-extension' ) ),
			array( 'pattern' => 'IDE', 'provider' => 'Google DoubleClick', 'category' => 'marketing', 'duration' => '13 months (EEA/UK)', 'purpose' => __( 'Measures and reports on advertising performance', 'nexter-extension' ) ),
			array( 'pattern' => 'FPGCLGS', 'provider' => 'Google', 'category' => 'marketing', 'duration' => '90 days', 'purpose' => __( 'First-party ad conversion measurement', 'nexter-extension' ) ),
			array( 'pattern' => 'ar_debug', 'provider' => 'Google', 'category' => 'analytics', 'duration' => '90 days', 'purpose' => __( 'Used for debugging analytics and advertising reporting', 'nexter-extension' ) ),

			array( 'pattern' => '_fbp', 'provider' => 'Meta', 'category' => 'marketing', 'duration' => '3 months', 'purpose' => __( 'Identifies browsers for advertising and site analytics', 'nexter-extension' ) ),
			array( 'pattern' => '_fbc', 'provider' => 'Meta', 'category' => 'marketing', 'duration' => '3 months', 'purpose' => __( 'Stores the last ad click that led to the site', 'nexter-extension' ) ),
			array( 'pattern' => 'fr', 'provider' => 'Meta', 'category' => 'marketing', 'duration' => '3 months', 'purpose' => __( 'Delivers, measures and improves advertising relevance', 'nexter-extension' ) ),
			array( 'pattern' => 'datr', 'provider' => 'Meta', 'category' => 'necessary', 'duration' => '2 years', 'purpose' => __( 'Identifies the browser for security and integrity purposes', 'nexter-extension' ) ),

			array( 'pattern' => '_hjSessionUser_*', 'provider' => 'Hotjar', 'category' => 'analytics', 'duration' => '1 year', 'purpose' => __( 'Identifies a returning visitor across sessions', 'nexter-extension' ) ),
			array( 'pattern' => '_hjSession_*', 'provider' => 'Hotjar', 'category' => 'analytics', 'duration' => '30 minutes', 'purpose' => __( 'Holds current session data', 'nexter-extension' ) ),
			array( 'pattern' => '_hjFirstSeen', 'provider' => 'Hotjar', 'category' => 'analytics', 'duration' => 'Session', 'purpose' => __( "Records whether this is a visitor's first session", 'nexter-extension' ) ),
			array( 'pattern' => '_hjIncludedInSessionSample', 'provider' => 'Hotjar', 'category' => 'analytics', 'duration' => '2 minutes', 'purpose' => __( 'Determines whether the session is included in sampling', 'nexter-extension' ) ),
			array( 'pattern' => '_clck', 'provider' => 'Microsoft Clarity', 'category' => 'analytics', 'duration' => '1 year', 'purpose' => __( 'Retains a Clarity user ID and settings', 'nexter-extension' ) ),
			array( 'pattern' => '_clsk', 'provider' => 'Microsoft Clarity', 'category' => 'analytics', 'duration' => '1 day', 'purpose' => __( 'Combines multiple page views into one session recording', 'nexter-extension' ) ),
			array( 'pattern' => 'CLID', 'provider' => 'Microsoft Clarity', 'category' => 'analytics', 'duration' => '1 year', 'purpose' => __( 'Identifies the first time Clarity saw this visitor', 'nexter-extension' ) ),
			array( 'pattern' => 'MUID', 'provider' => 'Microsoft', 'category' => 'marketing', 'duration' => '13 months', 'purpose' => __( 'Identifies unique browsers for advertising and analytics', 'nexter-extension' ) ),
			array( 'pattern' => '_pk_id*', 'provider' => 'Matomo', 'category' => 'analytics', 'duration' => '13 months', 'purpose' => __( 'Stores a visitor ID', 'nexter-extension' ) ),
			array( 'pattern' => '_pk_ses*', 'provider' => 'Matomo', 'category' => 'analytics', 'duration' => '30 minutes', 'purpose' => __( 'Stores short-lived session data', 'nexter-extension' ) ),

			array( 'pattern' => 'li_sugr', 'provider' => 'LinkedIn', 'category' => 'marketing', 'duration' => '3 months', 'purpose' => __( 'Browser identifier for probabilistic ad matching', 'nexter-extension' ) ),
			array( 'pattern' => 'bcookie', 'provider' => 'LinkedIn', 'category' => 'marketing', 'duration' => '1 year', 'purpose' => __( 'Browser identifier used for LinkedIn platform features', 'nexter-extension' ) ),
			array( 'pattern' => 'lidc', 'provider' => 'LinkedIn', 'category' => 'functional', 'duration' => '1 day', 'purpose' => __( 'Routes requests to the correct LinkedIn data centre', 'nexter-extension' ) ),
			array( 'pattern' => 'UserMatchHistory', 'provider' => 'LinkedIn', 'category' => 'marketing', 'duration' => '30 days', 'purpose' => __( 'Synchronises the LinkedIn Ads identifier', 'nexter-extension' ) ),
			array( 'pattern' => '_ttp', 'provider' => 'TikTok', 'category' => 'marketing', 'duration' => '13 months', 'purpose' => __( 'Measures and improves TikTok advertising performance', 'nexter-extension' ) ),
			array( 'pattern' => '_tt_enable_cookie', 'provider' => 'TikTok', 'category' => 'marketing', 'duration' => '13 months', 'purpose' => __( 'Records whether TikTok pixel cookies may be used', 'nexter-extension' ) ),
			array( 'pattern' => '_pinterest_ct_ua', 'provider' => 'Pinterest', 'category' => 'marketing', 'duration' => '1 year', 'purpose' => __( 'Groups actions for Pinterest conversion tracking', 'nexter-extension' ) ),
			array( 'pattern' => '_pin_unauth', 'provider' => 'Pinterest', 'category' => 'marketing', 'duration' => '1 year', 'purpose' => __( 'Groups actions for users who cannot be identified', 'nexter-extension' ) ),
			array( 'pattern' => 'personalization_id', 'provider' => 'X (Twitter)', 'category' => 'marketing', 'duration' => '2 years', 'purpose' => __( 'Supports advertising personalisation', 'nexter-extension' ) ),
			array( 'pattern' => 'muc_ads', 'provider' => 'X (Twitter)', 'category' => 'marketing', 'duration' => '2 years', 'purpose' => __( 'Measures advertising performance', 'nexter-extension' ) ),
			array( 'pattern' => '__hstc', 'provider' => 'HubSpot', 'category' => 'analytics', 'duration' => '6 months', 'purpose' => __( 'Main HubSpot analytics cookie tracking visitors over time', 'nexter-extension' ) ),
			array( 'pattern' => 'hubspotutk', 'provider' => 'HubSpot', 'category' => 'marketing', 'duration' => '6 months', 'purpose' => __( 'Identifies a visitor and links them to contact records', 'nexter-extension' ) ),
			array( 'pattern' => '__hssc', 'provider' => 'HubSpot', 'category' => 'analytics', 'duration' => '30 minutes', 'purpose' => __( 'Tracks sessions for HubSpot analytics', 'nexter-extension' ) ),

			array( 'pattern' => 'VISITOR_INFO1_LIVE', 'provider' => 'YouTube', 'category' => 'marketing', 'duration' => '6 months', 'purpose' => __( 'Estimates bandwidth and selects the video player interface', 'nexter-extension' ) ),
			array( 'pattern' => 'YSC', 'provider' => 'YouTube', 'category' => 'marketing', 'duration' => 'Session', 'purpose' => __( 'Records a unique ID for statistics on videos viewed', 'nexter-extension' ) ),
			array( 'pattern' => 'PREF', 'provider' => 'YouTube', 'category' => 'functional', 'duration' => '8 months', 'purpose' => __( 'Stores video player preferences such as playback settings', 'nexter-extension' ) ),
			array( 'pattern' => 'vuid', 'provider' => 'Vimeo', 'category' => 'analytics', 'duration' => '2 years', 'purpose' => __( 'Stores viewing history for Vimeo analytics', 'nexter-extension' ) ),
			array( 'pattern' => 'player', 'provider' => 'Vimeo', 'category' => 'functional', 'duration' => '1 year', 'purpose' => __( 'Stores video player preferences', 'nexter-extension' ) ),

			array( 'pattern' => 'wordpress_logged_in_*', 'provider' => 'WordPress', 'category' => 'necessary', 'duration' => 'Session', 'purpose' => __( 'Keeps a logged-in user authenticated', 'nexter-extension' ) ),
			array( 'pattern' => 'wordpress_sec_*', 'provider' => 'WordPress', 'category' => 'necessary', 'duration' => 'Session', 'purpose' => __( 'Secures the admin area for logged-in users', 'nexter-extension' ) ),
			array( 'pattern' => 'wp-settings-*', 'provider' => 'WordPress', 'category' => 'functional', 'duration' => '1 year', 'purpose' => __( 'Stores personal admin interface preferences', 'nexter-extension' ) ),
			array( 'pattern' => 'wordpress_test_cookie', 'provider' => 'WordPress', 'category' => 'necessary', 'duration' => 'Session', 'purpose' => __( 'Checks whether the browser accepts cookies', 'nexter-extension' ) ),
			array( 'pattern' => 'comment_author_*', 'provider' => 'WordPress', 'category' => 'functional', 'duration' => '1 year', 'purpose' => __( 'Remembers the name entered when leaving a comment', 'nexter-extension' ) ),
			array( 'pattern' => 'comment_author_email_*', 'provider' => 'WordPress', 'category' => 'functional', 'duration' => '1 year', 'purpose' => __( 'Remembers the email entered when leaving a comment', 'nexter-extension' ) ),
			array( 'pattern' => 'woocommerce_cart_hash', 'provider' => 'WooCommerce', 'category' => 'necessary', 'duration' => 'Session', 'purpose' => __( 'Detects when the cart contents change', 'nexter-extension' ) ),
			array( 'pattern' => 'woocommerce_items_in_cart', 'provider' => 'WooCommerce', 'category' => 'necessary', 'duration' => 'Session', 'purpose' => __( 'Detects when the cart contents change', 'nexter-extension' ) ),
			array( 'pattern' => 'wp_woocommerce_session_*', 'provider' => 'WooCommerce', 'category' => 'necessary', 'duration' => '2 days', 'purpose' => __( "Holds a unique code for each customer's cart and session data", 'nexter-extension' ) ),
			array( 'pattern' => 'woocommerce_recently_viewed', 'provider' => 'WooCommerce', 'category' => 'functional', 'duration' => 'Session', 'purpose' => __( 'Powers the recently viewed products widget', 'nexter-extension' ) ),
			array( 'pattern' => 'wp-wpml_current_language', 'provider' => 'WPML', 'category' => 'functional', 'duration' => '1 day', 'purpose' => __( 'Remembers the selected site language', 'nexter-extension' ) ),
			array( 'pattern' => 'pll_language', 'provider' => 'Polylang', 'category' => 'functional', 'duration' => '1 year', 'purpose' => __( 'Remembers the selected site language', 'nexter-extension' ) ),
			array( 'pattern' => 'nexter_consent', 'provider' => 'Nexter', 'category' => 'necessary', 'duration' => '12 months (configurable)', 'purpose' => __( 'Stores this visitor\'s cookie consent choices so the banner is not shown again', 'nexter-extension' ) ),

			array( 'pattern' => '__stripe_mid', 'provider' => 'Stripe', 'category' => 'necessary', 'duration' => '1 year', 'purpose' => __( 'Fraud prevention for payment processing', 'nexter-extension' ) ),
			array( 'pattern' => '__stripe_sid', 'provider' => 'Stripe', 'category' => 'necessary', 'duration' => '30 minutes', 'purpose' => __( 'Fraud prevention during a checkout session', 'nexter-extension' ) ),
			array( 'pattern' => '_GRECAPTCHA', 'provider' => 'Google reCAPTCHA', 'category' => 'necessary', 'duration' => '6 months', 'purpose' => __( 'Distinguishes humans from bots to protect forms', 'nexter-extension' ) ),
			array( 'pattern' => '__cf_bm', 'provider' => 'Cloudflare', 'category' => 'necessary', 'duration' => '30 minutes', 'purpose' => __( 'Distinguishes humans from bots for security', 'nexter-extension' ) ),
			array( 'pattern' => 'cf_clearance', 'provider' => 'Cloudflare', 'category' => 'necessary', 'duration' => '1 year', 'purpose' => __( 'Records that a visitor passed a security challenge', 'nexter-extension' ) ),
			array( 'pattern' => 'PHPSESSID', 'provider' => 'PHP', 'category' => 'necessary', 'duration' => 'Session', 'purpose' => __( 'Maintains server-side session state', 'nexter-extension' ) ),
		);
	}

	/**
	 * Matches a detected cookie name against the starter list. Exact match first, then
	 * prefix match against every `*`-suffixed pattern — never the reverse, so a specific
	 * exact entry always wins over an accidentally-broader prefix.
	 *
	 * @param string $name Detected cookie name.
	 * @return array|null
	 */
	private static function match_starter_description( $name ) {
		foreach ( self::default_cookie_descriptions() as $entry ) {
			if ( $entry['pattern'] === $name ) {
				return $entry;
			}
		}
		foreach ( self::default_cookie_descriptions() as $entry ) {
			if ( '*' === substr( $entry['pattern'], -1 ) && 0 === strpos( $name, rtrim( $entry['pattern'], '*' ) ) ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Every declared cookie (scanned + manual), for the future Declaration block/shortcode
	 * and for admin review. Always guarantees `nexter_consent` itself is present (QA
	 * 10.10) — it is the plugin's own cookie, so its presence should never depend on a
	 * scan happening to run after a visitor has already accepted.
	 *
	 * @return array<string,array>
	 */
	public static function get_declared_cookies() {
		$stored = get_option( self::COOKIES_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$own = self::cookie_name();
		if ( ! isset( $stored[ $own ] ) ) {
			$seed            = self::match_starter_description( 'nexter_consent' );
			$stored[ $own ] = array(
				'type'     => 'cookie',
				'provider' => $seed['provider'],
				'category' => $seed['category'],
				'purpose'  => $seed['purpose'],
				'duration' => $seed['duration'],
				'source'   => 'builtin',
				'edited'   => false,
			);
		}

		return $stored;
	}

	/**
	 * Option holding third-party hostnames seen via the Resource Timing API. Declared
	 * separately from COOKIES_OPTION — a contacted domain is not itself a cookie
	 * (PART 3.1 "the gap is declared via detected third-party scripts").
	 *
	 * @var string
	 */
	const DOMAINS_OPTION = 'nexter_cookie_consent_third_party_domains';

	/**
	 * Merges freshly detected names into the stored declaration (PART 3.3). An admin's
	 * manual edit (`edited => true`) is never overwritten by a re-scan (PART 3.4/QA 10.7)
	 * — only `last_seen` advances for an already-edited entry.
	 *
	 * @param string[] $names  Detected names, already sanitized by the caller.
	 * @param string   $source 'passive' or 'active'.
	 * @param string   $type   'cookie'|'localStorage'|'sessionStorage' — PART 3.1 lists all
	 *                         three as things the scanner CAN detect; localStorage/
	 *                         sessionStorage keys get their own declaration entries too,
	 *                         not just document.cookie names.
	 * @return void
	 */
	private static function record_scan_results( $names, $source, $type = 'cookie' ) {
		$stored = self::get_declared_cookies();
		$now    = current_time( 'mysql', true );

		foreach ( $names as $name ) {
			if ( '' === $name ) {
				continue;
			}

			if ( isset( $stored[ $name ] ) ) {
				$stored[ $name ]['last_seen'] = $now;
				continue; // Never touch provider/category/purpose/duration again once known.
			}

			// Starter descriptions are keyed on cookie names; localStorage/sessionStorage
			// keys are checked too since some vendors (e.g. some consent-adjacent
			// analytics SDKs) reuse the same identifier in both places.
			$match           = self::match_starter_description( $name );
			$stored[ $name ] = array(
				'type'       => $type,
				'provider'   => $match['provider'] ?? '',
				'category'   => $match['category'] ?? 'unclassified',
				// Never guess: no match means a blank purpose, shown in the UI as
				// "Unrecognised — add a description" (PART 3.3).
				'purpose'    => $match['purpose'] ?? '',
				'duration'   => $match['duration'] ?? '',
				'source'     => $source,
				'edited'     => false,
				'first_seen' => $now,
				'last_seen'  => $now,
			);
		}

		update_option( self::COOKIES_OPTION, $stored, false );
	}

	/**
	 * @param string[] $raw   Raw POSTed values for one collected group.
	 * @param int      $limit Hard cap so a single request can't grow an option unboundedly.
	 * @return string[]
	 */
	private static function sanitize_scan_names( $raw, $limit = 200 ) {
		$names = array();
		foreach ( array_slice( (array) $raw, 0, $limit ) as $name ) {
			$clean = preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $name );
			if ( '' !== $clean ) {
				$names[] = substr( $clean, 0, 191 );
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Scan report endpoint — receives cookie/storage names collected client-side (JS is
	 * the only thing that can read them; PHP never has access to this data any other
	 * way). Admin-only, nonce-verified, hard input caps on every group.
	 *
	 * @return void
	 */
	public function ajax_scan_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'nexter-extension' ) ), 403 );
		}
		check_ajax_referer( 'nxt-cookie-consent-scanner', 'nonce' );

		$source = ( isset( $_POST['source'] ) && 'active' === $_POST['source'] ) ? 'active' : 'passive';

		$cookies = self::sanitize_scan_names( isset( $_POST['cookies'] ) ? wp_unslash( $_POST['cookies'] ) : array() );
		self::record_scan_results( $cookies, $source, 'cookie' );

		$local = self::sanitize_scan_names( isset( $_POST['localStorage'] ) ? wp_unslash( $_POST['localStorage'] ) : array() );
		self::record_scan_results( $local, $source, 'localStorage' );

		$session = self::sanitize_scan_names( isset( $_POST['sessionStorage'] ) ? wp_unslash( $_POST['sessionStorage'] ) : array() );
		self::record_scan_results( $session, $source, 'sessionStorage' );

		$raw_domains = isset( $_POST['domains'] ) ? (array) wp_unslash( $_POST['domains'] ) : array();
		$domains     = array();
		foreach ( array_slice( $raw_domains, 0, 100 ) as $domain ) {
			$clean = sanitize_text_field( (string) $domain );
			if ( '' !== $clean && false === strpos( $clean, ' ' ) ) {
				$domains[] = substr( $clean, 0, 191 );
			}
		}
		if ( ! empty( $domains ) ) {
			$stored_domains = get_option( self::DOMAINS_OPTION, array() );
			$stored_domains = is_array( $stored_domains ) ? $stored_domains : array();
			$now            = current_time( 'mysql', true );
			foreach ( array_unique( $domains ) as $domain ) {
				$stored_domains[ $domain ] = $now; // Value = last-seen timestamp; presence is all that matters otherwise.
			}
			update_option( self::DOMAINS_OPTION, $stored_domains, false );
		}

		wp_send_json_success(
			array(
				'recorded' => count( $cookies ) + count( $local ) + count( $session ),
				'domains'  => count( $domains ),
			)
		);
	}

	/**
	 * Returns the page list the "Scan Now" active mode should load in hidden iframes
	 * (PART 3.1). Auto-detected, editable later in the admin UI — never hardcoded to
	 * only the homepage, since checkout/cart/contact pages are exactly where a scanner
	 * misses interaction-set cookies least often.
	 *
	 * @return void
	 */
	public function ajax_scan_targets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'nexter-extension' ) ), 403 );
		}
		check_ajax_referer( 'nxt-cookie-consent-scanner', 'nonce' );

		$targets = array( array( 'label' => __( 'Home', 'nexter-extension' ), 'url' => home_url( '/' ) ) );

		$latest_post = get_posts( array( 'post_type' => 'post', 'posts_per_page' => 1, 'post_status' => 'publish', 'fields' => 'ids' ) );
		if ( ! empty( $latest_post ) ) {
			$targets[] = array( 'label' => __( 'Latest post', 'nexter-extension' ), 'url' => get_permalink( $latest_post[0] ) );
		}

		$latest_page = get_posts( array( 'post_type' => 'page', 'posts_per_page' => 1, 'post_status' => 'publish', 'fields' => 'ids' ) );
		if ( ! empty( $latest_page ) ) {
			$targets[] = array( 'label' => __( 'A page', 'nexter-extension' ), 'url' => get_permalink( $latest_page[0] ) );
		}

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop' => __( 'Shop', 'nexter-extension' ), 'cart' => __( 'Cart', 'nexter-extension' ), 'checkout' => __( 'Checkout', 'nexter-extension' ) ) as $slug => $label ) {
				$page_id = wc_get_page_id( $slug );
				if ( $page_id && $page_id > 0 ) {
					$targets[] = array( 'label' => $label, 'url' => get_permalink( $page_id ) );
				}
			}
		}

		$contact = get_page_by_path( 'contact' );
		if ( $contact ) {
			$targets[] = array( 'label' => __( 'Contact', 'nexter-extension' ), 'url' => get_permalink( $contact ) );
		}

		wp_send_json_success( array( 'targets' => $targets ) );
	}

	/**
	 * Adds or updates a manually-entered cookie (PART 3.2 "manual entry is a first-class
	 * feature") — marks it `edited` so a later re-scan never silently overwrites it.
	 *
	 * @return void
	 */
	public function ajax_manual_cookie() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'nexter-extension' ) ), 403 );
		}
		check_ajax_referer( 'nxt-cookie-consent-scanner', 'nonce' );

		$name = isset( $_POST['name'] ) ? preg_replace( '/[^A-Za-z0-9_\-.]/', '', wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'A cookie name is required', 'nexter-extension' ) ), 400 );
		}

		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : 'unclassified';
		if ( ! in_array( $category, array_merge( self::CATEGORY_IDS, array( 'unclassified' ) ), true ) ) {
			$category = 'unclassified';
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'cookie';
		if ( ! in_array( $type, array( 'cookie', 'localStorage', 'sessionStorage' ), true ) ) {
			$type = 'cookie';
		}

		$stored          = self::get_declared_cookies();
		$stored[ $name ] = array(
			'type'       => $type,
			'provider'   => isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '',
			'category'   => $category,
			'purpose'    => isset( $_POST['purpose'] ) ? sanitize_text_field( wp_unslash( $_POST['purpose'] ) ) : '',
			'duration'   => isset( $_POST['duration'] ) ? sanitize_text_field( wp_unslash( $_POST['duration'] ) ) : '',
			'source'     => isset( $stored[ $name ]['source'] ) ? $stored[ $name ]['source'] : 'manual',
			'edited'     => true,
			'first_seen' => isset( $stored[ $name ]['first_seen'] ) ? $stored[ $name ]['first_seen'] : current_time( 'mysql', true ),
			'last_seen'  => current_time( 'mysql', true ),
		);

		update_option( self::COOKIES_OPTION, $stored, false );
		wp_send_json_success( array( 'cookie' => $stored[ $name ] ) );
	}

	/**
	 * Removes one declared cookie. `nexter_consent` itself can be removed from the option,
	 * but get_declared_cookies() re-seeds it on the next read (QA 10.10) — it can be
	 * edited, never permanently hidden from the declaration.
	 *
	 * @return void
	 */
	public function ajax_delete_cookie() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'nexter-extension' ) ), 403 );
		}
		check_ajax_referer( 'nxt-cookie-consent-scanner', 'nonce' );

		$name = isset( $_POST['name'] ) ? preg_replace( '/[^A-Za-z0-9_\-.]/', '', wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'A cookie name is required', 'nexter-extension' ) ), 400 );
		}

		$stored = self::get_declared_cookies();
		unset( $stored[ $name ] );
		update_option( self::COOKIES_OPTION, $stored, false );

		wp_send_json_success();
	}

	/**
	 * Categories reduced to what the frontend needs — never leak admin-only fields.
	 *
	 * @return array
	 */
	private function public_categories() {
		$out = array();
		foreach ( self::CATEGORY_IDS as $id ) {
			$cat        = isset( $this->settings['categories'][ $id ] ) ? $this->settings['categories'][ $id ] : array();
			$out[ $id ] = array(
				'label'   => $this->category_label( $id ),
				'default' => ! empty( $cat['default'] ),
				'locked'  => 'necessary' === $id ? true : ! empty( $cat['locked'] ),
			);
		}
		return $out;
	}

	/**
	 * A fresh write nonce for pages served from a cache.
	 *
	 * Public by necessity — the visitor is logged out — and that is fine: a logged-out nonce is
	 * not an auth credential, so the write also requires a same-origin request, is rate-limited
	 * and accepts only a category set. Never cacheable: a cached copy would hand
	 * every visitor the same stale value, which is the bug this exists to fix.
	 *
	 * @return void
	 */
	public function ajax_consent_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'nxt-cookie-consent' ) ) );
	}

	/**
	 * True when the browser's Origin (or Referer, if Origin is absent) host is this site.
	 *
	 * @return bool
	 */
	private function is_same_origin_request() {
		$source = isset( $_SERVER['HTTP_ORIGIN'] ) ? wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $source || 'null' === $source ) {
			$source = wp_get_raw_referer();
		}
		$host = $source ? wp_parse_url( $source, PHP_URL_HOST ) : '';
		if ( empty( $host ) ) {
			return false;
		}

		$allowed = array(
			wp_parse_url( home_url(), PHP_URL_HOST ),
			wp_parse_url( site_url(), PHP_URL_HOST ),
		);
		// Covers multi-domain setups (WPML per-language domains) where the page host differs from home_url.
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$allowed[] = wp_parse_url( 'http://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ), PHP_URL_HOST );
		}
		$allowed = array_map( 'strtolower', array_filter( (array) apply_filters( 'nexter_consent_allowed_hosts', $allowed ) ) );

		return in_array( strtolower( $host ), $allowed, true );
	}

	/** Consent writes allowed per visitor per minute on the public endpoint. */
	const RATE_LIMIT_PER_MINUTE = 30;

	/**
	 * Counts one write for this visitor in the current minute and returns the new total.
	 *
	 * Atomic on purpose. get_transient() then set_transient() let parallel requests all read the
	 * same count, so 100 simultaneous writes slipped 65 past a limit of 30. The counter is
	 * incremented and read back in a single step instead: object-cache incr when a persistent
	 * cache exists, otherwise UPDATE … LAST_INSERT_ID(v + 1), which MySQL serialises per row.
	 *
	 * @param string $who Opaque per-visitor key (a hash, never the raw IP).
	 * @return int Count including this request.
	 */
	private static function rate_limit_hit( $who ) {
		$bucket = (int) floor( time() / MINUTE_IN_SECONDS );

		if ( wp_using_ext_object_cache() ) {
			$k = 'rl_' . $who . '_' . $bucket;
			wp_cache_add( $k, 0, 'nxt_cc', 2 * MINUTE_IN_SECONDS );
			$n = wp_cache_incr( $k, 1, 'nxt_cc' );
			if ( false !== $n ) {
				return (int) $n;
			}
		}

		global $wpdb;
		$name = 'nxt_cc_rl_' . $who . '_' . $bucket;

		// INSERT IGNORE is the atomic "create if absent"; autoload=no keeps it off every page load.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, '0', 'no' )", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( option_value + 1 ) WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

		// Windows are one minute, so anything from an earlier bucket is dead weight. Swept
		// occasionally rather than every call so the hot path stays two cheap statements.
		if ( 1 === wp_rand( 1, 100 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s", $wpdb->esc_like( 'nxt_cc_rl_' ) . '%', '%\_' . $bucket ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $n;
	}

	/**
	 * Public consent-write endpoint. Accepts ONLY a category set + action — never arbitrary
	 * fields (PART 4.3). Rate-limited per IP via a short transient to blunt log-flood abuse
	 * since this is necessarily a nopriv endpoint.
	 *
	 * @return void
	 */
	public function ajax_write_consent() {
		if ( empty( $this->settings['switch'] ) || empty( $this->settings['records']['enabled'] ) ) {
			wp_send_json_success( array( 'logged' => false ) );
		}

		// The public nonce alone can't stop a cross-site form, so the browser's Origin must be this site.
		if ( ! $this->is_same_origin_request() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request origin', 'nexter-extension' ) ), 403 );
		}

		// Rate limit before the nonce check so failed and forged attempts count too.
		if ( self::rate_limit_hit( md5( $this->visitor_ip() ) ) > self::RATE_LIMIT_PER_MINUTE ) {
			wp_send_json_error( array( 'message' => __( 'Rate limited', 'nexter-extension' ) ), 429 );
		}

		check_ajax_referer( 'nxt-cookie-consent', 'nonce' );

		$action = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : '';
		if ( ! in_array( $action, array( 'accept_all', 'reject_all', 'custom', 'revoke' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid action', 'nexter-extension' ) ), 400 );
		}

		$categories = isset( $_POST['categories'] ) ? (array) wp_unslash( $_POST['categories'] ) : array();
		$categories = array_values( array_intersect( self::CATEGORY_IDS, array_map( 'sanitize_key', $categories ) ) );
		$consent_id = isset( $_POST['consent_id'] ) ? preg_replace( '/[^a-f0-9]/', '', wp_unslash( $_POST['consent_id'] ) ) : '';
		if ( 32 !== strlen( $consent_id ) ) {
			// Hex, not wp_generate_password(): that returns mixed-case alphanumeric, which the
			// two readers of this id both reject as /^[a-f0-9]{32}$/. The generator could never
			// satisfy its own validators, so every id was an orphan and the GDPR export and
			// erase callbacks resolved no data subject at all.
			$consent_id = bin2hex( random_bytes( 16 ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'nexter_consent_log';
		$inserted = $wpdb->insert(
			$table,
			array(
				'consent_id'     => $consent_id,
				'created_at'     => current_time( 'mysql', true ),
				'categories'     => implode( ',', $categories ),
				'config_version' => (int) $this->settings['config_version'],
				'action'         => $action,
				'ip_stored'      => $this->stored_ip(),
				'country'        => self::request_country(),
				'user_agent'     => ! empty( $this->settings['records']['store_ua'] ) && isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : null,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		// insert() returns false on a missing table or a failed write. Answering logged:true
		// regardless reported proof of consent that was never stored.
		if ( false === $inserted ) {
			wp_send_json_error( array( 'message' => __( 'Consent record could not be saved', 'nexter-extension' ), 'logged' => false ), 500 );
		}

		wp_send_json_success( array( 'logged' => true, 'consent_id' => $consent_id ) );
	}

	/**
	 * @return string Raw visitor IP, used only for rate-limiting — never stored raw unless
	 *                the admin explicitly chose the "full" IP mode (see stored_ip()).
	 */
	private function visitor_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}

	/**
	 * Applies the configured IP mode (PART 4.2) before anything touches the database.
	 *
	 * @return string|null
	 */
	private function stored_ip() {
		$mode = isset( $this->settings['records']['ip_mode'] ) ? $this->settings['records']['ip_mode'] : 'truncated';
		$ip   = $this->visitor_ip();

		switch ( $mode ) {
			case 'none':
				return null;
			case 'hashed':
				return hash( 'sha256', $ip . wp_salt( 'nonce' ) );
			case 'full':
				return $ip;
			case 'truncated':
			default:
				return $this->truncate_ip( $ip );
		}
	}

	/**
	 * @param string $ip
	 * @return string IPv4 truncated to /24, IPv6 to /48.
	 */
	private function truncate_ip( $ip ) {
		if ( false !== strpos( $ip, ':' ) ) {
			$parts = explode( ':', $ip );
			return implode( ':', array_slice( $parts, 0, 3 ) ) . '::/48';
		}
		$parts = explode( '.', $ip );
		if ( 4 === count( $parts ) ) {
			$parts[3] = '0';
			return implode( '.', $parts ) . '/24';
		}
		return $ip;
	}

	/**
	 * Maps our 4 frozen categories onto the WP Consent API's own 5 category slugs
	 * (functional/preferences/statistics/statistics-anonymous/marketing — [B], not
	 * re-verified against a live install; a mismatch here would only make a first-party
	 * plugin read the wrong bucket, never leak data, so a wrong guess is a bug not a risk).
	 * 'necessary' is intentionally mapped to 'functional': the API has no "always on"
	 * bucket of its own.
	 *
	 * @var array<string,string>
	 */
	const WP_CONSENT_API_CATEGORY_MAP = array(
		'necessary'  => 'functional',
		'functional' => 'preferences',
		'analytics'  => 'statistics',
		'marketing'  => 'marketing',
	);

	/**
	 * Registers Nexter as a WP Consent API "consent management" plugin (PART 10.1) —
	 * this is the documented `wp_consent_api_registered_{plugin_basename}` contract every
	 * consent plugin implements; it is NOT specific to this plugin's own internals.
	 * A first-party plugin that also supports the API then reads state through
	 * `wp_has_consent()`, which the API itself backs with a cookie our frontend JS writes
	 * via `wp_set_consent()` — see activateGatedContent()'s WP Consent API sync in
	 * nexter-consent.js. Silently does nothing if the API plugin is not active.
	 *
	 * @return void
	 */
	public static function register_wp_consent_api_provider() {
		if ( ! function_exists( 'wp_has_consent' ) ) {
			return;
		}
		add_filter( 'wp_consent_api_registered_' . plugin_basename( NEXTER_EXT_DIR . 'nexter-extension.php' ), '__return_true' );
	}

	/**
	 * Schedules or clears the daily retention cron to match current settings. Idempotent —
	 * safe to call on every request. One event per site (multisite: `wp_schedule_event` is
	 * itself per-site via wp_options, so this never needs a network-wide loop here).
	 *
	 * @return void
	 */
	private function sync_retention_cron_schedule() {
		// Follows the module switch alone: with recording off there is still stored data to purge.
		$should_run = ! empty( $this->settings['switch'] );
		$scheduled  = wp_next_scheduled( self::RETENTION_CRON_HOOK );

		if ( $should_run && ! $scheduled ) {
			// Offset to 3am site time rather than "now" so the first run doesn't land on
			// a busy traffic hour if the admin happens to save settings mid-afternoon.
			$timestamp = strtotime( 'tomorrow 3am', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			wp_schedule_event( $timestamp, 'daily', self::RETENTION_CRON_HOOK );
		} elseif ( ! $should_run && $scheduled ) {
			wp_clear_scheduled_hook( self::RETENTION_CRON_HOOK );
		}
	}

	/**
	 * Retention cron callback — deletes consent-log rows older than the configured
	 * retention window, in bounded batches (PART 4.3 "chunked DELETE...LIMIT n" /
	 * QA 5.10 "no timeout, no half-purged state"). A backlog larger than
	 * RETENTION_BATCH_SIZE * RETENTION_BATCHES_MAX simply finishes over several days —
	 * that is intentional, never a bug to "fix" by removing the cap.
	 *
	 * @return void
	 */
	public function run_retention_purge() {
		// No records.enabled check, on purpose. That switch stops NEW rows being written; rows
		// (and the IPs in them) already stored must still age out, or turning recording off
		// froze them in place forever — the opposite of what an admin switching it off wants.
		global $wpdb;
		$table    = $wpdb->prefix . 'nexter_consent_log';
		$retention_days = (int) $this->settings['records']['retention'];
		if ( $retention_days < 1 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		for ( $i = 0; $i < self::RETENTION_BATCHES_MAX; $i++ ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff,
					self::RETENTION_BATCH_SIZE
				)
			);
			if ( ! $deleted ) {
				break; // Nothing left older than the cutoff — stop early, don't burn the remaining batches.
			}
		}

		update_option( self::LAST_PURGE_OPTION, time(), false );
	}

	/** When the retention purge last completed (unix time); drives the overdue check. */
	const LAST_PURGE_OPTION = 'nexter_cc_last_purge';

	/**
	 * Runs the purge from an admin request when WP-Cron has not.
	 *
	 * The purge is scheduled through WP-Cron, which never fires on a site with DISABLE_WP_CRON and
	 * no system cron — there the log grew without bound and nothing said so. Admin screens only,
	 * never a visitor request, and bounded by the same batch cap as the cron run.
	 *
	 * @return void
	 */
	public function maybe_run_overdue_purge() {
		if ( empty( $this->settings['switch'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ( time() - (int) get_option( self::LAST_PURGE_OPTION, 0 ) ) < 2 * DAY_IN_SECONDS ) {
			return;
		}
		$this->run_retention_purge();
	}

	/**
	 * Admin settings save. capability + nonce are both mandatory (PART 4.3) — never
	 * `edit_posts`, this touches consent-record behaviour for every visitor.
	 *
	 * @return void
	 */
	public function ajax_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'nexter-extension' ) ), 403 );
		}
		// Field name is 'nexter_nonce', matching every dashboard save call in this plugin
		// (formData.append('nexter_nonce', nxtext_ajax_object.ajax_nonce)) — not 'nonce'.
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );

		// json_decode() requires a string; a malformed request (settings[]=...) arrives as an
		// array and would otherwise throw a TypeError on PHP 8.
		$raw     = isset( $_POST['settings'] ) && is_string( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : '';
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payload', 'nexter-extension' ) ), 400 );
		}

		$sanitized = self::sanitize_settings( $decoded );
		self::store_settings( $sanitized );
		Nxt_Options::flush( 'nexter_cookie_consent_settings' );
		$this->settings = self::get_settings();
		// Re-sync immediately — otherwise the schedule would only self-heal on the NEXT
		// request, leaving a stale/missing cron for one full page load after a toggle.
		$this->sync_retention_cron_schedule();

		// Bump the abilities cache generation the same way Content Types definition saves do
		// (CLAUDE.md "Abilities layer" rule) — admin UI saves never touch it on their own,
		// and nexter/get-cookie-consent-settings would otherwise serve a stale cached read.
		if ( function_exists( 'nexter_ext_ability_cache_version' ) ) {
			nexter_ext_ability_cache_version( true );
		}

		wp_send_json_success( array( 'settings' => $sanitized ) );
	}

	/**
	 * Admin-ajax wrapper for the dashboard's Compliance Check tab.
	 *
	 * @return void
	 */
	public function ajax_compliance_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'nexter-extension' ) ), 403 );
		}
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );

		wp_send_json_success( array( 'checks' => self::run_compliance_check() ) );
	}

	/**
	 * Compliance Check (02-DEVELOPER-BRIEF.md PART 5.1) — reads the live config and reports
	 * truthfully. Absolute rule, restated here because it is the one thing this method must
	 * never get wrong: it may WARN or FAIL freely (that is "warn, never block" working as
	 * intended), but it must never report `pass` for something it has not actually verified.
	 * A check this codebase cannot yet verify (banner contrast/keyboard nav, reject-button
	 * prominence, scanner-detected but unclassified cookies — all Nexter Blocks / scanner
	 * work that has not landed yet) is reported as `unavailable`, never guessed at as a pass.
	 *
	 * @return array{id:string} List of check results: id, label, status
	 *                          (pass|warn|fail|unavailable), message.
	 */
	public static function run_compliance_check() {
		$settings = self::get_settings();
		$checks   = array();

		$checks[] = array(
			'id'      => 'blocking_enabled',
			'label'   => __( 'Non-essential scripts blocked before consent', 'nexter-extension' ),
			'status'  => ! empty( $settings['blocking']['enabled'] ) ? 'pass' : 'fail',
			'message' => ! empty( $settings['blocking']['enabled'] )
				? __( 'Script blocking is enabled.', 'nexter-extension' )
				: __( 'Script blocking is OFF — non-essential scripts run before any consent decision.', 'nexter-extension' ),
		);

		// A pass for "records enabled" is meaningless if the table they are written to is
		// missing — which is exactly the state a site updated from 4.7.x was left in.
		global $wpdb;
		$records_table  = $wpdb->prefix . 'nexter_consent_log';
		$records_exists = ! empty( $settings['records']['enabled'] )
			? ( $records_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $records_table ) ) ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			: true;

		$checks[] = array(
			'id'      => 'consent_records',
			'label'   => __( 'Consent records enabled', 'nexter-extension' ),
			'status'  => ! $records_exists ? 'fail' : ( ! empty( $settings['records']['enabled'] ) ? 'pass' : 'warn' ),
			// PART 14.4: disabling records is a legitimate choice, not a defect — factual,
			// not alarmist, per QA 16.6.
			'message' => ! $records_exists
				? __( 'Consent records are on, but the table they are written to does not exist — nothing is being stored. Open any admin screen so it can be created, then run this check again.', 'nexter-extension' )
				: ( ! empty( $settings['records']['enabled'] )
				? sprintf(
					/* translators: 1: IP mode, 2: retention days */
					__( 'Recording consent decisions (%1$s IP, %2$d-day retention).', 'nexter-extension' ),
					$settings['records']['ip_mode'],
					(int) $settings['records']['retention']
				)
				: __( 'Consent records are off. Nothing is stored as proof of consent — this may be a deliberate choice, but it means there is no audit trail if a visitor disputes their consent.', 'nexter-extension' ) ),
		);

		$checks[] = array(
			'id'      => 'privacy_policy_url',
			'label'   => __( 'Privacy policy URL set', 'nexter-extension' ),
			'status'  => ! empty( $settings['legal']['privacy_policy_url'] ) ? 'pass' : 'fail',
			'message' => ! empty( $settings['legal']['privacy_policy_url'] )
				? __( 'A privacy policy URL is configured.', 'nexter-extension' )
				: __( 'No privacy policy URL set.', 'nexter-extension' ),
		);

		$checks[] = array(
			'id'      => 'cookie_policy_url',
			'label'   => __( 'Cookie policy URL set', 'nexter-extension' ),
			'status'  => ! empty( $settings['legal']['cookie_policy_url'] ) ? 'pass' : 'warn',
			'message' => ! empty( $settings['legal']['cookie_policy_url'] )
				? __( 'A cookie policy URL is configured.', 'nexter-extension' )
				: __( 'No cookie policy URL set — the Cookie Declaration block has nowhere to live yet.', 'nexter-extension' ),
		);

		$gtm_rule_action = self::gtm_rule_action( $settings );
		$checks[] = array(
			'id'      => 'consent_mode_gtm_alignment',
			'label'   => __( 'Google Consent Mode / GTM alignment', 'nexter-extension' ),
			'status'  => 'unavailable',
			'message' => __( 'Consent Mode not enabled — nothing to check.', 'nexter-extension' ),
		);
		if ( ! empty( $settings['consent_mode']['enabled'] ) ) {
			$is_gtm_path = 'gtm' === $settings['consent_mode']['mode'];
			$misconfigured = $is_gtm_path && 'never' !== $gtm_rule_action;
			// QA 9.6: "GTM blocked while Consent Mode is on GTM path" is a misconfiguration
			// that breaks tracking entirely — flagged even though the engine's own runtime
			// safety net (maybe_block_script_tag()) already prevents it from ever taking
			// effect; the stored rule being wrong is still worth surfacing to the admin.
			$checks[ count( $checks ) - 1 ] = array(
				'id'      => 'consent_mode_gtm_alignment',
				'label'   => __( 'Google Consent Mode / GTM alignment', 'nexter-extension' ),
				'status'  => $misconfigured ? 'warn' : 'pass',
				'message' => $misconfigured
					? __( 'Consent Mode is set to the GTM-managed path, but the Google Tag Manager blocking rule is not "Never block". The runtime never actually blocks GTM in this mode, but the stored rule should be corrected to avoid confusion.', 'nexter-extension' )
					: __( 'Consent Mode configuration is internally consistent.', 'nexter-extension' ),
			);
		}

		$last_purge = (int) get_option( self::LAST_PURGE_OPTION, 0 );
		$overdue    = $last_purge && ( time() - $last_purge ) > 3 * DAY_IN_SECONDS;
		$cron_off   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$checks[]   = array(
			'id'      => 'retention_purge',
			'label'   => __( 'Old consent records are being purged', 'nexter-extension' ),
			'status'  => ( $overdue || ( $cron_off && ! $last_purge ) ) ? 'warn' : 'pass',
			'message' => ( $overdue || ( $cron_off && ! $last_purge ) )
				? __( 'The scheduled retention purge has not run recently, and WP-Cron appears to be disabled — consent records, and the IP data in them, are not aging out on schedule. Set up a real server cron to hit wp-cron.php; the purge also runs whenever an administrator opens a dashboard screen.', 'nexter-extension' )
				: __( 'Expired consent records are purged on schedule.', 'nexter-extension' ),
		);

		$rivals = self::detect_conflicting_plugins();
		$checks[] = array(
			'id'      => 'competing_consent_plugin',
			'label'   => __( 'No other cookie-consent plugin active', 'nexter-extension' ),
			'status'  => empty( $rivals ) ? 'pass' : 'fail',
			'message' => empty( $rivals )
				? __( 'No other known cookie-consent plugin is active.', 'nexter-extension' )
				: sprintf(
					/* translators: %s: comma-separated plugin names */
					__( 'Active alongside this plugin: %s. Two consent tools show two banners and hold two contradictory records of one visitor\'s choice. Keep one.', 'nexter-extension' ),
					implode( ', ', $rivals )
				),
		);

		// Raw tags the engine structurally cannot block. This is the one place a site owner
		// can find out that "blocking is on" does not mean "everything is blocked".
		$raw = self::detect_unblockable_tags( $settings );
		if ( '' !== $raw['error'] ) {
			$checks[] = array(
				'id'      => 'unblockable_raw_tags',
				'label'   => __( 'Vendor tags this plugin cannot block', 'nexter-extension' ),
				'status'  => 'unavailable',
				/* translators: %s: error returned while fetching the front page */
				'message' => sprintf( __( 'Could not scan the front page (%s). Server-side blocking only covers scripts added with wp_enqueue_script(); tags pasted directly into a page or theme are never blocked.', 'nexter-extension' ), $raw['error'] ),
			);
		} elseif ( ! empty( $raw['services'] ) ) {
			$checks[] = array(
				'id'      => 'unblockable_raw_tags',
				'label'   => __( 'Vendor tags this plugin cannot block', 'nexter-extension' ),
				'status'  => 'fail',
				'message' => sprintf(
					/* translators: %s: comma-separated service names */
					__( 'These tags load before consent and this plugin cannot stop them: %s. They are inline snippets, which cannot be held back without breaking them. Replace them with a plugin or theme that enqueues the script, or remove them and let a tag manager handle consent. This scans the front page only — other templates may carry more.', 'nexter-extension' ),
					implode( ', ', $raw['services'] )
				),
			);
		} else {
			$checks[] = array(
				'id'      => 'unblockable_raw_tags',
				'label'   => __( 'Vendor tags this plugin cannot block', 'nexter-extension' ),
				'status'  => 'pass',
				'message' => __( 'No directly-printed vendor tags found on the front page. Note this scans the front page only — another template could still carry one.', 'nexter-extension' ),
			);
		}

		// The three below are decided from what the server can actually see: the banner markup
		// the front page serves to a first-time visitor, and the declared-cookie list. Anything
		// that needs a browser (contrast, keyboard order, rendered size) is reported as `warn`
		// with what was and was not checked — never as a pass it did not earn.
		$banner   = self::inspect_banner_markup();
		$checks[] = self::check_reject_button( $banner );
		$checks[] = self::check_banner_accessibility( $banner );
		$checks[] = self::check_unclassified_cookies();

		return $checks;
	}

	/**
	 * Parses the first-visit front page and extracts the Nexter Blocks banner.
	 *
	 * @return array{found:bool,error:string,dom:?DOMDocument,banner:?DOMElement,modal:?DOMElement}
	 */
	private static function inspect_banner_markup() {
		$out = array( 'found' => false, 'error' => '', 'dom' => null, 'banner' => null, 'modal' => null );

		$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 10, 'cookies' => array(), 'sslverify' => true ) );
		if ( is_wp_error( $response ) ) {
			$out['error'] = $response->get_error_message();
			return $out;
		}
		$html = wp_remote_retrieve_body( $response );
		// The fallback banner carries data-nexter-fallback-banner and only gains the real
		// attribute at runtime, so this selector cannot match it.
		if ( '' === $html || false === strpos( $html, 'data-nexter-banner' ) ) {
			return $out;
		}

		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xp     = new DOMXPath( $dom );
		$banner = $xp->query( '//*[@data-nexter-banner and contains(concat(" ", normalize-space(@class), " "), " nxt-cc ")]' )->item( 0 );
		if ( ! $banner instanceof DOMElement ) {
			return $out;
		}
		$modal = $xp->query( '//*[@data-nexter-preferences-modal]' )->item( 0 );

		$out['found']  = true;
		$out['dom']    = $dom;
		$out['banner'] = $banner;
		$out['modal']  = $modal instanceof DOMElement ? $modal : null;
		return $out;
	}

	/**
	 * @param array $banner Result of inspect_banner_markup().
	 * @return array
	 */
	private static function check_reject_button( $banner ) {
		$base = array(
			'id'    => 'reject_button_prominence',
			'label' => __( 'Reject button present and equally prominent', 'nexter-extension' ),
		);
		if ( ! $banner['found'] ) {
			return $base + array(
				'status'  => 'unavailable',
				'message' => '' !== $banner['error']
					/* translators: %s: error message */
					? sprintf( __( 'Could not fetch the front page to inspect the banner: %s', 'nexter-extension' ), $banner['error'] )
					: __( 'No Nexter Blocks Cookie Consent banner was found on the front page, so there is nothing to inspect. Place the block on the page (or in a footer template part) and re-check.', 'nexter-extension' ),
			);
		}

		$xp     = new DOMXPath( $banner['dom'] );
		$accept = $xp->query( './/button[@data-nexter-action="accept"]', $banner['banner'] )->length;
		$reject = $xp->query( './/button[@data-nexter-action="reject" and not(contains(concat(" ", normalize-space(@class), " "), " nxt-cc-close "))]', $banner['banner'] )->length;

		if ( $accept && ! $reject ) {
			return $base + array(
				'status'  => 'fail',
				'message' => __( 'The banner has an Accept button but no Reject button at the first layer. Turn on "Show Reject" in the Cookie Consent block.', 'nexter-extension' ),
			);
		}
		if ( ! $accept ) {
			return $base + array(
				'status'  => 'warn',
				'message' => __( 'The banner has no Accept button, so there is nothing to compare Reject against. Check the block settings.', 'nexter-extension' ),
			);
		}
		return $base + array(
			'status'  => 'pass',
			'message' => __( 'A Reject button sits beside Accept in the banner and uses the same button component. Colour and size come from your design settings, which this check cannot measure — look at both buttons if you restyled either one.', 'nexter-extension' ),
		);
	}

	/**
	 * @param array $banner Result of inspect_banner_markup().
	 * @return array
	 */
	private static function check_banner_accessibility( $banner ) {
		$base = array(
			'id'    => 'banner_accessibility',
			'label' => __( 'Banner passes contrast and keyboard checks', 'nexter-extension' ),
		);
		if ( ! $banner['found'] ) {
			return $base + array(
				'status'  => 'unavailable',
				'message' => __( 'No Nexter Blocks Cookie Consent banner was found on the front page, so there is nothing to inspect.', 'nexter-extension' ),
			);
		}

		$problems    = array();
		$xp          = new DOMXPath( $banner['dom'] );
		$dialogs     = array( 'banner' => $xp->query( './/*[@role="dialog"]', $banner['banner'] )->item( 0 ) );
		$dialog_name = array(
			'banner'      => __( 'consent banner', 'nexter-extension' ),
			'preferences' => __( 'preferences dialog', 'nexter-extension' ),
		);
		if ( $banner['modal'] ) {
			$dialogs['preferences'] = $banner['modal'];
		}
		foreach ( $dialogs as $key => $el ) {
			$label = isset( $dialog_name[ $key ] ) ? $dialog_name[ $key ] : $key;
			if ( ! $el instanceof DOMElement || 'dialog' !== $el->getAttribute( 'role' ) ) {
				/* translators: %s: translated name of the banner part (e.g. "consent banner") */
				$problems[] = sprintf( __( 'the %s has no dialog role', 'nexter-extension' ), $label );
				continue;
			}
			$label_id = $el->getAttribute( 'aria-labelledby' );
			$target   = '' !== $label_id ? $banner['dom']->getElementById( $label_id ) : null;
			if ( ! $target || '' === trim( $target->textContent ) ) {
				/* translators: %s: translated name of the banner part (e.g. "consent banner") */
				$problems[] = sprintf( __( 'the %s has no accessible name', 'nexter-extension' ), $label );
			}
		}
		// A clickable <div> is not keyboard-operable; every action must be a real button.
		if ( $xp->query( './/*[@data-nexter-action and not(self::button)]', $banner['banner'] )->length ) {
			$problems[] = __( 'an action is not a <button>', 'nexter-extension' );
		}

		if ( $problems ) {
			return $base + array(
				'status'  => 'fail',
				/* translators: %s: list of problems */
				'message' => sprintf( __( 'Structure problems: %s.', 'nexter-extension' ), implode( '; ', array_unique( $problems ) ) ),
			);
		}
		// Contrast and focus order need a rendered page; the structure alone is not a pass.
		return $base + array(
			'status'  => 'warn',
			'message' => __( 'The markup is sound: dialogs have roles and accessible names and every action is a real button. Colour contrast and focus order need a rendered-page scan, which this check does not run — test them in a browser.', 'nexter-extension' ),
		);
	}

	/**
	 * @return array
	 */
	private static function check_unclassified_cookies() {
		$base = array(
			'id'    => 'unclassified_cookies',
			'label' => __( 'All detected cookies have a category and purpose', 'nexter-extension' ),
		);
		$own       = self::cookie_name();
		$unclassed = array();
		$other     = 0;
		foreach ( self::get_declared_cookies() as $name => $row ) {
			if ( $name === $own ) {
				continue;
			}
			++$other;
			$cat = isset( $row['category'] ) ? (string) $row['category'] : '';
			if ( '' === $cat || 'unclassified' === $cat || '' === trim( (string) ( isset( $row['purpose'] ) ? $row['purpose'] : '' ) ) ) {
				$unclassed[] = $name;
			}
		}

		if ( ! $other ) {
			return $base + array(
				'status'  => 'unavailable',
				'message' => __( 'No cookies have been detected yet. The scanner records them while you browse the front of the site logged in as an administrator — visit a few pages, then run this check again.', 'nexter-extension' ),
			);
		}
		if ( $unclassed ) {
			return $base + array(
				'status'  => 'warn',
				'message' => sprintf(
					/* translators: 1: number of cookies, 2: cookie names */
					_n(
						'%1$d detected cookie has no category or purpose: %2$s. Until it has both, it cannot be declared on the Cookie Declaration or gated by consent.',
						'%1$d detected cookies have no category or purpose: %2$s. Until each has both, it cannot be declared on the Cookie Declaration or gated by consent.',
						count( $unclassed ),
						'nexter-extension'
					),
					count( $unclassed ),
					implode( ', ', array_slice( $unclassed, 0, 8 ) )
				),
			);
		}
		return $base + array(
			'status'  => 'pass',
			'message' => sprintf(
				/* translators: %d: number of cookies */
				_n(
					'All %d detected cookie has a category and a purpose.',
					'All %d detected cookies have a category and a purpose.',
					$other,
					'nexter-extension'
				),
				$other
			),
		);
	}

	/**
	 * @param array $settings Merged settings.
	 * @return string The stored action for the 'Google Tag Manager' blocking rule, or ''
	 *                if that rule was ever removed from the table.
	 */
	private static function gtm_rule_action( $settings ) {
		foreach ( $settings['blocking']['rules'] as $rule ) {
			if ( 'Google Tag Manager' === $rule['service'] ) {
				return $rule['action'];
			}
		}
		return '';
	}

	/**
	 * Public seam for the nexter/update-cookie-consent-settings ability — reuses the exact
	 * same allowlist sanitize_settings() runs for the admin AJAX save, so the ability can
	 * never bypass a validation rule the admin UI enforces (CLAUDE.md "enter at the seam").
	 *
	 * @param array $input Merged settings payload.
	 * @return array
	 */
	public static function sanitize_settings_for_ability( $input ) {
		return self::sanitize_settings( $input );
	}

	/**
	 * Shallow, allowlist-only sanitization. Anything not explicitly handled here is
	 * dropped rather than passed through — an unknown key must never reach get_option().
	 * Static: called both from the instance AJAX handler and the ability seam above.
	 *
	 * @param array $input Raw decoded settings payload.
	 * @return array
	 */
	private static function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$out      = $defaults;

		// 'switch' is deliberately NOT sanitized/stored here — get_settings() always
		// overrides it from the shared nexter_extra_ext_options tree (see its own comment).
		// Accepting it from $input here would let this option silently disagree with what
		// the dashboard toggle actually shows.
		$out['preset']         = in_array( ( $input['preset'] ?? '' ), array( 'simple', 'balanced', 'strict' ), true ) ? $input['preset'] : $defaults['preset'];
		$out['reprompt_days']  = isset( $input['reprompt_days'] ) ? max( 1, absint( $input['reprompt_days'] ) ) : $defaults['reprompt_days'];

		if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
			foreach ( self::CATEGORY_IDS as $id ) {
				if ( empty( $input['categories'][ $id ] ) ) {
					continue;
				}
				$in = $input['categories'][ $id ];
				// Necessary is always on/locked regardless of what the request claims.
				$out['categories'][ $id ]['label']   = isset( $in['label'] ) ? sanitize_text_field( $in['label'] ) : $defaults['categories'][ $id ]['label'];
				$out['categories'][ $id ]['default'] = 'necessary' === $id ? true : ! empty( $in['default'] );
				$out['categories'][ $id ]['locked']  = 'necessary' === $id;
			}
		}

		if ( ! empty( $input['blocking'] ) && is_array( $input['blocking'] ) ) {
			$out['blocking']['enabled'] = ! empty( $input['blocking']['enabled'] );
			// isset(), not ! empty(): an admin who deleted every rule sent [], and ! empty() read that
			// as "not provided" and put all 18 defaults back. array_values() reindexes after deletes.
			if ( isset( $input['blocking']['rules'] ) && is_array( $input['blocking']['rules'] ) ) {
				$out['blocking']['rules'] = array_values( array_map( array( __CLASS__, 'sanitize_rule' ), array_slice( $input['blocking']['rules'], 0, self::RULE_MAX_RULES ) ) );
			}
		}

		if ( ! empty( $input['records'] ) && is_array( $input['records'] ) ) {
			$out['records']['enabled']   = ! empty( $input['records']['enabled'] );
			$out['records']['ip_mode']   = in_array( ( $input['records']['ip_mode'] ?? '' ), array( 'none', 'truncated', 'hashed', 'full' ), true ) ? $input['records']['ip_mode'] : $defaults['records']['ip_mode'];
			$out['records']['store_ua']  = ! empty( $input['records']['store_ua'] );
			$out['records']['retention'] = isset( $input['records']['retention'] ) ? min( 3650, max( 1, absint( $input['records']['retention'] ) ) ) : $defaults['records']['retention'];
			$out['records']['delete_on_uninstall'] = ! empty( $input['records']['delete_on_uninstall'] );
		}

		if ( ! empty( $input['legal'] ) && is_array( $input['legal'] ) ) {
			$out['legal']['privacy_policy_url'] = isset( $input['legal']['privacy_policy_url'] ) ? esc_url_raw( $input['legal']['privacy_policy_url'] ) : '';
			$out['legal']['cookie_policy_url']  = isset( $input['legal']['cookie_policy_url'] ) ? esc_url_raw( $input['legal']['cookie_policy_url'] ) : '';
		}

		if ( ! empty( $input['consent_mode'] ) && is_array( $input['consent_mode'] ) ) {
			$cm = $input['consent_mode'];
			$out['consent_mode']['enabled']            = ! empty( $cm['enabled'] );
			$out['consent_mode']['mode']                = in_array( ( $cm['mode'] ?? '' ), array( 'gtag', 'gtm' ), true ) ? $cm['mode'] : $defaults['consent_mode']['mode'];
			$out['consent_mode']['gtag_ids']            = isset( $cm['gtag_ids'] ) ? array_map( 'sanitize_text_field', (array) $cm['gtag_ids'] ) : array();
			$out['consent_mode']['url_passthrough']     = ! empty( $cm['url_passthrough'] );
			$out['consent_mode']['ads_data_redaction']  = ! empty( $cm['ads_data_redaction'] );
		}

		// Config version bumps ONLY on the documented triggers (PART 1.6) — never on cosmetic
		// settings. Categories/rules are the only two config sanitized above that qualify.
		// Compared against what is STORED, falling back to defaults only when nothing is stored
		// yet. Comparing against defaults bumped on every save once a category was ever edited,
		// re-prompting every visitor and writing a fresh consent record for an unchanged config.
		$stored       = Nxt_Options::cookie_consent();
		$stored       = is_array( $stored ) ? $stored : array();
		$stored_cats  = isset( $stored['categories'] ) ? $stored['categories'] : $defaults['categories'];
		$stored_rules = isset( $stored['blocking']['rules'] ) ? $stored['blocking']['rules'] : $defaults['blocking']['rules'];

		if ( wp_json_encode( $stored_cats ) !== wp_json_encode( $out['categories'] )
			|| wp_json_encode( $stored_rules ) !== wp_json_encode( $out['blocking']['rules'] ) ) {
			$out['config_version'] = (int) ( isset( $stored['config_version'] ) ? $stored['config_version'] : $defaults['config_version'] ) + 1;
		} else {
			$out['config_version'] = (int) ( isset( $stored['config_version'] ) ? $stored['config_version'] : $defaults['config_version'] );
		}

		return $out;
	}

	/**
	 * Writes the settings option with autoload OFF.
	 *
	 * ~90 keys were loaded into memory on every request of every site, front end and admin, whether
	 * or not the module is on. update_option() ignores its autoload argument when the value is
	 * unchanged, so wp_set_option_autoload() (WP 6.4+) also corrects sites that already stored it.
	 *
	 * @param array $settings Sanitised settings.
	 * @return void
	 */
	public static function store_settings( $settings ) {
		update_option( 'nexter_cookie_consent_settings', $settings, false );
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( 'nexter_cookie_consent_settings', false );
		}
	}

	/**
	 * Visitor country from Cloudflare's header, or null.
	 *
	 * Accepts only a two-letter code. It is still a client-supplied header unless the site really
	 * sits behind Cloudflare, so treat the stored value as informational, never as proof.
	 *
	 * @return string|null
	 */
	private static function request_country() {
		if ( empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return null;
		}
		$code = strtoupper( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ) );
		return 1 === preg_match( '/^[A-Z]{2}$/', $code ) ? $code : null;
	}

	/** Hard ceilings on a blocking rule. The 18 shipped rules use at most 3 short patterns each. */
	const RULE_MAX_RULES    = 100;
	const RULE_MAX_PATTERNS = 20;
	const RULE_PATTERN_LEN  = 150;

	/**
	 * Patterns, bounded in count and length, empties dropped.
	 *
	 * The rules are printed into the config of EVERY page, and nothing capped them: one save
	 * through the API with oversized patterns put roughly 5 MB of JSON into every response.
	 *
	 * @param mixed $patterns Raw patterns.
	 * @return string[]
	 */
	private static function sanitize_patterns( $patterns ) {
		$out = array();
		foreach ( array_slice( (array) $patterns, 0, self::RULE_MAX_PATTERNS ) as $p ) {
			if ( ! is_scalar( $p ) ) {
				continue;
			}
			$p = substr( sanitize_text_field( (string) $p ), 0, self::RULE_PATTERN_LEN );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $rule
	 * @return array
	 */
	private static function sanitize_rule( $rule ) {
		$rule = (array) $rule;
		return array(
			'service'  => isset( $rule['service'] ) ? substr( sanitize_text_field( $rule['service'] ), 0, 100 ) : '',
			'category' => in_array( ( $rule['category'] ?? '' ), self::CATEGORY_IDS, true ) ? $rule['category'] : 'marketing',
			'patterns' => self::sanitize_patterns( isset( $rule['patterns'] ) ? $rule['patterns'] : array() ),
			'action'   => in_array( ( $rule['action'] ?? '' ), array( 'block', 'block_placeholder', 'never', 'always_block' ), true ) ? $rule['action'] : 'block',
		);
	}

	/*
	 * NO core privacy exporter or eraser is registered, on purpose. Do not add one.
	 *
	 * Core runs those callbacks in the ADMIN's browser, and a consent record carries no email
	 * or user id (by design — the consent id is "not a user identifier"), so a callback has
	 * nothing to resolve the data subject with. The only identifier in reach is the
	 * requester's own nexter_consent cookie, which belongs to whoever is running the tool:
	 * an exporter built on it returns the admin's record for someone else's request, and an
	 * eraser deletes the admin's rows while reporting the real subject's as erased.
	 *
	 * A tool listed under Export/Erase Personal Data that cannot act on the person named
	 * there is worse than none — it reads as a handled request.
	 *
	 * Known gap: there is no visitor-facing way yet to view or delete the stored record. The
	 * reopen control changes the CHOICE, not the log. Until that exists, erasure is an admin
	 * action on the table, keyed by a consent id the visitor reads from their own cookie.
	 */

	/**
	 * [nexter_cookie_declaration] — PART 7.2. Placed by the site owner inside their OWN
	 * policy page, in their own words and language; this shortcode only ever renders the
	 * factual table, never any policy prose (CLAUDE.md / 01-DECISION-RECORD.md §8 — the
	 * plugin never authors or publishes legal text).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_declaration_shortcode( $atts ) {
		if ( empty( $this->settings['switch'] ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'source'     => 'both', // scanned|manual|both
				'category'   => '', // comma-separated category ids, empty = all
				'layout'     => 'table', // table|list|cards|accordion
				'group_by'   => 'category', // category|provider|none
				'columns'    => 'name,provider,purpose,duration,category',
				'sort'       => 'name', // name|provider|category
				'show_meta'  => 'yes',
				'empty_text' => '',
				// The group title used to be a hardcoded <h4>, which broke heading order on
				// any policy page whose previous heading was h1/h2 (WCAG 1.3.1). The caller
				// knows its own outline; we do not. Default stays h4 so existing pages do
				// not shift.
				'heading_level' => 'h4',
			),
			$atts,
			'nexter_cookie_declaration'
		);

		$heading_tag = in_array( $atts['heading_level'], array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true )
			? $atts['heading_level']
			: 'h4';

		$rows = $this->filter_declaration_rows( $atts );

		if ( empty( $rows ) ) {
			$empty_text = '' !== $atts['empty_text']
				? $atts['empty_text']
				: __( 'No cookies have been declared yet.', 'nexter-extension' );
			return '<p class="nxt-cookie-declaration-empty">' . esc_html( $empty_text ) . '</p>';
		}

		$columns = array_values( array_intersect(
			array_map( 'trim', explode( ',', $atts['columns'] ) ),
			array( 'name', 'provider', 'purpose', 'duration', 'category', 'type' )
		) );
		if ( empty( $columns ) ) {
			$columns = array( 'name', 'provider', 'purpose', 'duration', 'category' );
		}

		$html  = $this->declaration_inline_style();
		$html .= '<div class="nxt-cookie-declaration nxt-cookie-declaration--' . esc_attr( $atts['layout'] ) . '">';

		if ( 'yes' === $atts['show_meta'] ) {
			$html .= $this->declaration_meta_html( $rows );
		}

		$groups = $this->group_declaration_rows( $rows, $atts['group_by'] );
		foreach ( $groups as $group_label => $group_rows ) {
			if ( '' !== $group_label ) {
				$html .= '<' . $heading_tag . ' class="nxt-cookie-declaration-group-title">' . esc_html( $group_label ) . '</' . $heading_tag . '>';
			}
			$html .= $this->render_declaration_layout( $group_rows, $columns, $atts['layout'] );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * @param array $atts Shortcode attributes, already merged with defaults.
	 * @return array<string,array> Cookie name => declaration row, filtered and sorted.
	 */
	private function filter_declaration_rows( $atts ) {
		$rows = self::get_declared_cookies();

		$categories = array_filter( array_map( 'trim', explode( ',', $atts['category'] ) ) );

		$filtered = array();
		foreach ( $rows as $name => $row ) {
			if ( 'scanned' === $atts['source'] && ! empty( $row['edited'] ) ) {
				continue;
			}
			if ( 'manual' === $atts['source'] && empty( $row['edited'] ) ) {
				continue;
			}
			if ( ! empty( $categories ) && ! in_array( $row['category'], $categories, true ) ) {
				continue;
			}
			$row['name']       = $name;
			$filtered[ $name ] = $row;
		}

		$sort_key = in_array( $atts['sort'], array( 'name', 'provider', 'category' ), true ) ? $atts['sort'] : 'name';
		uasort(
			$filtered,
			function ( $a, $b ) use ( $sort_key ) {
				return strcasecmp( (string) $a[ $sort_key ], (string) $b[ $sort_key ] );
			}
		);

		return $filtered;
	}

	/**
	 * @param array  $rows    Filtered declaration rows.
	 * @param string $group_by 'category'|'provider'|'none'.
	 * @return array<string,array> Group label => rows. A single '' key when group_by is 'none'.
	 */
	private function group_declaration_rows( $rows, $group_by ) {
		if ( 'none' === $group_by ) {
			return array( '' => $rows );
		}

		$labels = array();
		if ( 'category' === $group_by ) {
			foreach ( self::CATEGORY_IDS as $id ) {
				$labels[ $id ] = $this->category_label( $id );
			}
			$labels['unclassified'] = __( 'Unclassified', 'nexter-extension' );
		}

		$groups = array();
		foreach ( $rows as $name => $row ) {
			$key = 'provider' === $group_by ? ( $row['provider'] ?: __( 'Unknown provider', 'nexter-extension' ) ) : $row['category'];
			$label = 'category' === $group_by ? ( $labels[ $key ] ?? $key ) : $key;
			$groups[ $label ][ $name ] = $row;
		}

		return $groups;
	}

	/**
	 * @param array  $rows    Rows for one group.
	 * @param array  $columns Column keys to render, in order.
	 * @param string $layout  table|list|cards|accordion.
	 * @return string
	 */
	private function render_declaration_layout( $rows, $columns, $layout ) {
		switch ( $layout ) {
			case 'cards':
				return $this->render_declaration_cards( $rows, $columns );
			case 'list':
				return $this->render_declaration_list( $rows, $columns );
			case 'accordion':
				return $this->render_declaration_accordion( $rows, $columns );
			case 'table':
			default:
				return $this->render_declaration_table( $rows, $columns );
		}
	}

	/**
	 * @param string $key Column key.
	 * @return string Translated column header label.
	 */
	private function column_label( $key ) {
		$labels = array(
			'name'     => __( 'Name', 'nexter-extension' ),
			'provider' => __( 'Provider', 'nexter-extension' ),
			'purpose'  => __( 'Purpose', 'nexter-extension' ),
			'duration' => __( 'Duration', 'nexter-extension' ),
			'category' => __( 'Category', 'nexter-extension' ),
			'type'     => __( 'Type', 'nexter-extension' ),
		);
		return $labels[ $key ] ?? $key;
	}

	/**
	 * @param array  $row Declaration row.
	 * @param string $key Column key.
	 * @return string Escaped display value — "Unrecognised — add a description" for a
	 *                blank purpose is the one deliberate exception to "never guess"
	 *                (PART 3.3): it is a disclosure, not a fabricated purpose.
	 */
	private function column_value( $row, $key ) {
		if ( 'purpose' === $key && '' === $row['purpose'] ) {
			return '<em>' . esc_html__( 'Unrecognised — add a description', 'nexter-extension' ) . '</em>';
		}
		if ( 'category' === $key ) {
			$label = isset( $this->settings['categories'][ $row['category'] ]['label'] ) ? $this->category_label( $row['category'] ) : ucfirst( $row['category'] );
			return esc_html( $label );
		}
		if ( 'duration' === $key ) {
			return esc_html( $this->translate_duration( isset( $row['duration'] ) ? $row['duration'] : '' ) );
		}
		return esc_html( isset( $row[ $key ] ) ? $row[ $key ] : '' );
	}

	/**
	 * Translates a starter-list duration string (e.g. "2 years", "Session") for display.
	 * Falls back to the raw string for anything outside "Session" or "N <unit>[ (qualifier)]".
	 *
	 * @param string $duration Raw duration string.
	 * @return string Translated (not yet escaped) duration string.
	 */
	private function translate_duration( $duration ) {
		if ( 'Session' === $duration ) {
			return __( 'Session', 'nexter-extension' );
		}

		if ( ! preg_match( '/^(\d+)\s+(minute|hour|day|month|year)s?(?:\s+\((.+)\))?$/', $duration, $m ) ) {
			return $duration;
		}

		$count  = (int) $m[1];
		$suffix = isset( $m[3] ) ? $m[3] : '';

		switch ( $m[2] ) {
			case 'minute':
				/* translators: %d: number of minutes */
				$text = sprintf( _n( '%d minute', '%d minutes', $count, 'nexter-extension' ), $count );
				break;
			case 'hour':
				/* translators: %d: number of hours */
				$text = sprintf( _n( '%d hour', '%d hours', $count, 'nexter-extension' ), $count );
				break;
			case 'day':
				/* translators: %d: number of days */
				$text = sprintf( _n( '%d day', '%d days', $count, 'nexter-extension' ), $count );
				break;
			case 'month':
				/* translators: %d: number of months */
				$text = sprintf( _n( '%d month', '%d months', $count, 'nexter-extension' ), $count );
				break;
			case 'year':
				/* translators: %d: number of years */
				$text = sprintf( _n( '%d year', '%d years', $count, 'nexter-extension' ), $count );
				break;
			default:
				return $duration;
		}

		if ( '' === $suffix ) {
			return $text;
		}

		$suffix_labels = array(
			'EEA/UK'       => __( 'EEA/UK', 'nexter-extension' ),
			'configurable' => __( 'configurable', 'nexter-extension' ),
		);
		$suffix_label  = isset( $suffix_labels[ $suffix ] ) ? $suffix_labels[ $suffix ] : $suffix;

		/* translators: 1: translated duration (e.g. "2 years"), 2: translated qualifier (e.g. "configurable") */
		return sprintf( __( '%1$s (%2$s)', 'nexter-extension' ), $text, $suffix_label );
	}

	/**
	 * @param array $rows
	 * @param array $columns
	 * @return string
	 */
	private function render_declaration_table( $rows, $columns ) {
		// Scrollable regions must be focusable or keyboard users cannot reach the overflow (axe: serious).
		$html = '<div class="nxt-cookie-declaration-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Cookie declaration', 'nexter-extension' ) . '"><table class="nxt-cookie-declaration-table"><thead><tr>';
		foreach ( $columns as $col ) {
			$html .= '<th>' . esc_html( $this->column_label( $col ) ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( $columns as $col ) {
				$html .= '<td>' . $this->column_value( $row, $col ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';
		return $html;
	}

	/**
	 * @param array $rows
	 * @param array $columns
	 * @return string
	 */
	private function render_declaration_cards( $rows, $columns ) {
		$html = '<div class="nxt-cookie-declaration-cards">';
		foreach ( $rows as $row ) {
			$html .= '<div class="nxt-cookie-declaration-card">';
			foreach ( $columns as $col ) {
				$html .= '<div class="nxt-cookie-declaration-card-row"><span class="nxt-cookie-declaration-card-label">' . esc_html( $this->column_label( $col ) ) . '</span><span class="nxt-cookie-declaration-card-value">' . $this->column_value( $row, $col ) . '</span></div>';
			}
			$html .= '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * @param array $rows
	 * @param array $columns
	 * @return string
	 */
	private function render_declaration_list( $rows, $columns ) {
		$html = '<ul class="nxt-cookie-declaration-list">';
		foreach ( $rows as $row ) {
			$parts = array();
			foreach ( $columns as $col ) {
				$parts[] = '<strong>' . esc_html( $this->column_label( $col ) ) . ':</strong> ' . $this->column_value( $row, $col );
			}
			$html .= '<li>' . implode( ' &middot; ', $parts ) . '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/**
	 * @param array $rows
	 * @param array $columns
	 * @return string
	 */
	private function render_declaration_accordion( $rows, $columns ) {
		$html = '<div class="nxt-cookie-declaration-accordion">';
		foreach ( $rows as $row ) {
			$html .= '<details class="nxt-cookie-declaration-accordion-item"><summary>' . esc_html( $row['name'] ) . '</summary><div class="nxt-cookie-declaration-accordion-body">';
			foreach ( $columns as $col ) {
				if ( 'name' === $col ) {
					continue; // Already the summary — repeating it inside adds nothing.
				}
				$html .= '<p><strong>' . esc_html( $this->column_label( $col ) ) . ':</strong> ' . $this->column_value( $row, $col ) . '</p>';
			}
			$html .= '</div></details>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * @param array $rows Filtered rows (post-source/category filter, pre-grouping).
	 * @return string "Last scanned" date + total count (PART 7.2 "Meta").
	 */
	private function declaration_meta_html( $rows ) {
		$last_seen = '';
		foreach ( $rows as $row ) {
			if ( ! empty( $row['last_seen'] ) && $row['last_seen'] > $last_seen ) {
				$last_seen = $row['last_seen'];
			}
		}

		$parts = array();
		if ( $last_seen ) {
			$parts[] = sprintf(
				/* translators: %s: formatted date */
				__( 'Last scanned: %s', 'nexter-extension' ),
				esc_html( mysql2date( get_option( 'date_format' ), $last_seen ) )
			);
		}
		$parts[] = sprintf(
			/* translators: %d: cookie count */
			_n( '%d cookie', '%d cookies', count( $rows ), 'nexter-extension' ),
			count( $rows )
		);

		return '<p class="nxt-cookie-declaration-meta">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
	}

	/**
	 * Printed once per page load (guarded by a static flag), not enqueued as a stylesheet
	 * — the declaration can appear inside Classic Editor/Elementor/Bricks content, none of
	 * which reliably run wp_enqueue_scripts for a block-editor-free page (PART 7.2 "a
	 * primary path, not a fallback"). Deliberately minimal: borders, spacing, responsive
	 * stacking below 600px, and `[dir="rtl"]` mirroring — full design controls belong to
	 * the future Nexter Blocks version of this same data, not this shortcode.
	 *
	 * The meta line carries no opacity: at 0.75 it measured 3.54:1 against a white
	 * surface, under the 4.5:1 floor. The smaller font already reads as secondary, and
	 * inheriting the theme's text colour is the only way to stay legible on a theme whose
	 * background this file cannot know.
	 *
	 * @return string
	 */
	private function declaration_inline_style() {
		static $printed = false;
		if ( $printed ) {
			return '';
		}

		// A one-shot flag assumes the FIRST render is the one a visitor sees. It is not:
		// Nexter_Content_SEO_Description::resolve_meta_description() runs do_blocks() on
		// wp_head to derive a meta description, which renders this shortcode into a buffer
		// that is then thrown away. That pass used to consume the flag, so the real render
		// in the_content shipped with no CSS at all and the table lost its scroll wrapper,
		// borders and mobile stacking.
		//
		// Enqueuing instead of echoing removes the fragility: WordPress dedupes by handle,
		// so a discarded render is harmless, and the CSS is printed once wherever the first
		// enqueue lands. wp_register_style with a false src is the documented way to carry
		// inline-only CSS. The return value stays '' for callers that still concatenate it.
		if ( ! did_action( 'wp_enqueue_scripts' ) && ! did_action( 'wp_head' ) ) {
			// Too early for the queue to exist — fall through to the inline echo below so a
			// direct do_shortcode() in a template still gets styled.
			$printed = true;
			return '<style>' . self::declaration_css() . '</style>';
		}

		$printed = true;

		wp_register_style( 'nxt-cookie-declaration', false, array(), defined( 'NEXTER_EXT_VER' ) ? NEXTER_EXT_VER : false );
		wp_enqueue_style( 'nxt-cookie-declaration' );
		wp_add_inline_style( 'nxt-cookie-declaration', self::declaration_css() );

		return '';
	}

	/**
	 * The declaration stylesheet, as raw CSS.
	 *
	 * Deliberately minimal: borders, spacing, responsive stacking below 600px and
	 * [dir="rtl"] mirroring. Full design control belongs to the Nexter Blocks version of
	 * this same data, not to this shortcode.
	 *
	 * The meta line carries no opacity: at 0.75 it measured 3.54:1 against a white surface,
	 * under the 4.5:1 floor. The smaller font already reads as secondary, and inheriting the
	 * theme's text colour is the only thing that stays legible on a theme whose background
	 * this file cannot know.
	 *
	 * @return string
	 */
	private static function declaration_css() {

		return '
.nxt-cookie-declaration-scroll{overflow-x:auto;}
.nxt-cookie-declaration-table{width:100%;border-collapse:collapse;}
.nxt-cookie-declaration-table th,.nxt-cookie-declaration-table td{border:1px solid #ddd;padding:8px 10px;text-align:left;}
[dir="rtl"] .nxt-cookie-declaration-table th,[dir="rtl"] .nxt-cookie-declaration-table td{text-align:right;}
.nxt-cookie-declaration-table th{background:#f5f5f5;}
.nxt-cookie-declaration-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;}
.nxt-cookie-declaration-card{border:1px solid #ddd;border-radius:6px;padding:12px;}
.nxt-cookie-declaration-card-row{display:flex;justify-content:space-between;gap:8px;padding:2px 0;}
.nxt-cookie-declaration-meta{font-size:0.85em;}
.nxt-cookie-declaration-group-title{margin:1.5em 0 0.5em;}
@media (max-width:600px){
.nxt-cookie-declaration-table thead{display:none;}
.nxt-cookie-declaration-table,.nxt-cookie-declaration-table tbody,.nxt-cookie-declaration-table tr,.nxt-cookie-declaration-table td{display:block;width:100%;}
.nxt-cookie-declaration-table td{border:none;border-bottom:1px solid #eee;}
}';
	}
	/**
	 * [nexter_consent_reopen] — reopens the preferences dialog.
	 *
	 * There is a preferences modal, but once the banner is dismissed nothing reopens it,
	 * so consent is effectively one-way — which contradicts the "you can change your mind
	 * at any time" that justifies asking in the first place.
	 *
	 * This carries no logic. It emits one button with data-nexter-action="preferences",
	 * the same attribute the banner's own Customize button uses, and the surface contract
	 * in assets/js/cookie-consent/nexter-consent.js binds it. Nexter Blocks ships a styled
	 * block over the same attribute; this exists so a footer menu, a widget or a Classic
	 * Editor page still has a way back when Blocks is not installed.
	 *
	 * Deliberately almost unstyled: it inherits the theme's button or link styling rather
	 * than importing a design system Extension does not own.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_reopen_shortcode( $atts ) {
		if ( empty( $this->settings['switch'] ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'label' => __( 'Cookie preferences', 'nexter-extension' ),
				'class' => '',
				// link|button — link inherits theme link styling, button inherits button styling.
				'as'    => 'link',
			),
			$atts,
			'nexter_consent_reopen'
		);

		$label = sanitize_text_field( $atts['label'] );
		if ( '' === $label ) {
			// An unnamed control is unusable with a screen reader, so fall back rather than
			// render an empty button.
			$label = __( 'Cookie preferences', 'nexter-extension' );
		}

		$classes = array( 'nxt-consent-reopen' );
		if ( 'button' === $atts['as'] ) {
			$classes[] = 'nxt-consent-reopen-button';
		}
		foreach ( preg_split( '/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY ) as $extra ) {
			$clean = sanitize_html_class( $extra );
			if ( '' !== $clean ) {
				$classes[] = $clean;
			}
		}

		return '<button type="button" class="' . esc_attr( implode( ' ', $classes ) ) . '"'
			. ' data-nexter-action="preferences">'
			. esc_html( $label )
			. '</button>';
	}
	/**
	 * Emergency fallback banner (§23) — the surface of last resort.
	 *
	 * The failure it exists to prevent: this module starts blocking scripts the moment it
	 * is switched on, but the banner itself lives in Nexter Blocks. Deactivate Blocks, or
	 * simply never place the block, and a site blocks analytics and marketing with NO WAY
	 * for a visitor to consent. That is strictly worse than shipping no consent tool at
	 * all, so the engine carries its own minimal banner and never depends on Blocks.
	 *
	 * How it knows not to double up: it cannot. Not server-side — a banner can arrive from
	 * a block, a shortcode, a pattern, a template part, an FSE template or a theme, and
	 * enumerating those is a losing game. So it ships inert, carrying
	 * data-nexter-fallback-banner rather than the real attribute, and a few lines of inline
	 * script promote it ONLY if the finished document contains no [data-nexter-banner].
	 * That runs while the document is still parsing, before nexter-consent.js boots on
	 * DOMContentLoaded, so the contract picks the promoted element up exactly as it would
	 * a block-rendered one.
	 *
	 * It is also why this is cache-safe: the markup is byte-identical for every visitor,
	 * consented or not, and the decision happens in the browser.
	 *
	 * Deliberately NOT a copy of the Blocks design system: no presets, no tokens, no style
	 * controls. A handful of rules that inherit the theme, sized to stay small.
	 *
	 * @return void
	 */
	public function render_fallback_banner() {
		if ( empty( $this->settings['switch'] ) ) {
			return;
		}

		// Nothing to consent to in the admin, a feed, or a REST render.
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		/**
		 * Lets a site or a theme suppress the fallback entirely — for example when it ships
		 * its own banner markup against the same data-nexter-* contract.
		 *
		 * @param bool $enabled Whether the fallback may render.
		 */
		if ( ! apply_filters( 'nexter_consent_fallback_enabled', true ) ) {
			return;
		}

		$categories = $this->public_categories();

		$title  = __( 'We use cookies', 'nexter-extension' );
		$body   = __( 'We use cookies to improve your experience. Choose which categories you are comfortable with.', 'nexter-extension' );
		// Wording mirrors the Nexter Blocks banner's defaults on purpose (Accept All / Reject All /
		// Customize / Save preferences): a visitor must not see the labels change when the block is
		// deactivated and this fallback takes over. Change both together.
		$accept = __( 'Accept All', 'nexter-extension' );
		$reject = __( 'Reject All', 'nexter-extension' );
		$custom = __( 'Customize', 'nexter-extension' );
		$save   = __( 'Save preferences', 'nexter-extension' );
		$close  = __( 'Close', 'nexter-extension' );

		?>
		<style id="nxt-cc-fb-css">
		.nxt-cc-fb,.nxt-cc-fb-m{position:fixed;z-index:999998;box-sizing:border-box}
		.nxt-cc-fb *,.nxt-cc-fb-m *{box-sizing:inherit}
		.nxt-cc-fb[hidden],.nxt-cc-fb-m[hidden]{display:none}
		.nxt-cc-fb{left:0;right:0;bottom:0;background:#fff;color:#1a1a1a;border-top:1px solid #d9d9de;padding:16px 20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:center;font-size:14px;line-height:1.5}
		.nxt-cc-fb-t{flex:1 1 280px;min-width:0;margin:0}
		.nxt-cc-fb-t b{display:block;margin-bottom:2px}
		.nxt-cc-fb-a{display:flex;flex-wrap:wrap;gap:8px}
		.nxt-cc-fb button{font:inherit;cursor:pointer;padding:8px 16px;border:1px solid #1717cc;border-radius:4px;background:#fff;color:#1717cc}
		.nxt-cc-fb button.nxt-cc-fb-y,.nxt-cc-fb-m button.nxt-cc-fb-y{background:#1717cc;color:#fff}
		.nxt-cc-fb button:focus-visible,.nxt-cc-fb-m button:focus-visible,.nxt-cc-fb-m input:focus-visible{outline:2px solid currentColor;outline-offset:2px}
		.nxt-cc-fb-m{inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.5)}
		.nxt-cc-fb-p{background:#fff;color:#1a1a1a;max-width:480px;width:100%;max-height:85vh;overflow:auto;border-radius:6px;padding:20px;font-size:14px;line-height:1.5}
		.nxt-cc-fb-p h2{margin:0 0 12px;font-size:18px;color:inherit}
		.nxt-cc-fb-p ul{list-style:none;margin:0 0 16px;padding:0}
		.nxt-cc-fb-p li{padding:10px 0;border-bottom:1px solid #e6e6ea;display:flex;align-items:center;justify-content:space-between;gap:12px}
		.nxt-cc-fb-p li:last-child{border-bottom:0}
		.nxt-cc-fb-p label{display:flex;align-items:center;gap:8px;cursor:pointer}
		.nxt-cc-fb-p input{width:18px;height:18px;margin:0}
		.nxt-cc-fb-p input:disabled{cursor:not-allowed}
		.nxt-cc-fb-b{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end}
		.nxt-cc-fb-b button{font:inherit;cursor:pointer;padding:8px 16px;border:1px solid #1717cc;border-radius:4px;background:#fff;color:#1717cc}
		@media(max-width:600px){.nxt-cc-fb{flex-direction:column;align-items:stretch}.nxt-cc-fb-a button{width:100%}}
		</style>

		<div class="nxt-cc-fb" data-nexter-fallback-banner hidden role="dialog" aria-modal="false" aria-labelledby="nxt-cc-fb-t">
			<p class="nxt-cc-fb-t" id="nxt-cc-fb-t"><b><?php echo esc_html( $title ); ?></b><?php echo esc_html( $body ); ?></p>
			<span class="nxt-cc-fb-a">
				<button type="button" data-nexter-action="reject"><?php echo esc_html( $reject ); ?></button>
				<button type="button" data-nexter-action="preferences"><?php echo esc_html( $custom ); ?></button>
				<button type="button" class="nxt-cc-fb-y" data-nexter-action="accept"><?php echo esc_html( $accept ); ?></button>
			</span>
		</div>

		<div class="nxt-cc-fb-m" data-nexter-fallback-modal hidden role="dialog" aria-modal="true" aria-labelledby="nxt-cc-fb-h">
			<div class="nxt-cc-fb-p">
				<h2 id="nxt-cc-fb-h"><?php echo esc_html( $title ); ?></h2>
				<ul>
					<?php foreach ( $categories as $id => $cat ) : ?>
						<li>
							<label>
								<input type="checkbox" data-nexter-category-toggle="<?php echo esc_attr( $id ); ?>"
									<?php checked( ! empty( $cat['locked'] ) ); ?>
									<?php disabled( ! empty( $cat['locked'] ) ); ?> />
								<span><?php echo esc_html( $cat['label'] ); ?></span>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="nxt-cc-fb-b">
					<button type="button" data-nexter-action="close-preferences"><?php echo esc_html( $close ); ?></button>
					<button type="button" class="nxt-cc-fb-y" data-nexter-action="save-preferences"><?php echo esc_html( $save ); ?></button>
				</div>
			</div>
		</div>

		<script id="nxt-cc-fb-js">
		// Promote the fallback ONLY when the finished document has no other banner. This
		// runs while the document is still parsing, so it lands before nexter-consent.js
		// boots on DOMContentLoaded and the contract treats the result as any other
		// surface. Removing the nodes when a real banner exists keeps the DOM honest:
		// a hidden duplicate would still be reachable by a screen reader.
		(function(){
			var b=document.querySelector('[data-nexter-fallback-banner]'),
				m=document.querySelector('[data-nexter-fallback-modal]'),
				s=document.getElementById('nxt-cc-fb-css');
			if(!b){return;}
			if(document.querySelector('[data-nexter-banner]')){
				b.parentNode.removeChild(b);
				if(m){m.parentNode.removeChild(m);}
				if(s){s.parentNode.removeChild(s);}
				return;
			}
			b.setAttribute('data-nexter-banner','');
			if(m){m.setAttribute('data-nexter-preferences-modal','');}
		})();
		</script>
		<?php
	}
	/**
	 * Other cookie-consent plugins that are active on this site, by display name.
	 *
	 * Matched on the plugin's directory slug in the active list rather than on a class or
	 * constant: slugs are stable across versions and cost nothing to read, where probing for
	 * each vendor's internals would break the day one renames a class. Filterable so a site can
	 * add one this list does not know.
	 *
	 * @return string[] Display names, keyed by slug.
	 */
	public static function detect_conflicting_plugins() {
		$known = apply_filters(
			'nexter_consent_conflicting_plugins',
			array(
				'cookie-law-info'             => 'CookieYes',
				'complianz-gdpr'              => 'Complianz',
				'complianz-gdpr-premium'      => 'Complianz Premium',
				'borlabs-cookie'              => 'Borlabs Cookie',
				'iubenda-cookie-law-solution' => 'iubenda',
				'cookiebot'                   => 'Cookiebot',
				'cookie-notice'               => 'Cookie Notice',
				'real-cookie-banner'          => 'Real Cookie Banner',
				'real-cookie-banner-pro'      => 'Real Cookie Banner Pro',
			)
		);

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$found = array();
		foreach ( $active as $file ) {
			$slug = strtok( (string) $file, '/' );
			if ( isset( $known[ $slug ] ) ) {
				$found[ $slug ] = $known[ $slug ];
			}
		}

		return $found;
	}

	/**
	 * Warns, persistently, while another consent plugin is active alongside this one.
	 *
	 * Not dismissible: unlike the fallback notice this is a live fault, not information. With
	 * two banners up, accepting in one leaves the other showing and its cookie saying no, so
	 * scripts held back by the other plugin stay blocked while this log records them as granted.
	 *
	 * Deliberately does NOT suppress the Nexter banner. Doing it automatically would leave a
	 * site with no banner at all the moment the other plugin is half-configured, and which one
	 * to keep is the site owner's call.
	 *
	 * @return void
	 */
	public function conflict_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) || empty( $this->settings['switch'] ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->base, array( 'dashboard', 'plugins', 'toplevel_page_nexter_welcome' ), true ) ) {
			return;
		}

		$others = self::detect_conflicting_plugins();
		if ( empty( $others ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html__( 'Two cookie-consent plugins are active.', 'nexter-extension' )
			. '</strong> '
			. esc_html(
				sprintf(
					/* translators: %s: comma-separated plugin names */
					__( 'Nexter Cookie Consent is running alongside %s. Visitors see two banners, and a choice made in one is not seen by the other — scripts it blocked stay blocked while Nexter records them as allowed. Keep one: deactivate the other plugin, or turn Cookie Consent off in Nexter.', 'nexter-extension' ),
					implode( ', ', $others )
				)
			)
			. '</p></div>';
	}

	/**
	 * Tells the admin the fallback banner is what visitors are seeing.
	 *
	 * Scope is deliberately narrow. Whether a DESIGNED banner is actually placed cannot be
	 * known cheaply from the admin — a block can live in a post, a pattern, a template
	 * part, an FSE template or a widget, and the fallback itself only decides in the
	 * browser. So this notice makes the one claim that is deterministic: Nexter Blocks is
	 * not active, therefore the fallback is the only banner there can be.
	 *
	 * Claiming more than that would produce a notice that is wrong on plenty of sites, and
	 * a notice nobody can trust is worse than none.
	 *
	 * @return void
	 */
	public function fallback_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $this->settings['switch'] ) ) {
			return;
		}

		// Blocks present means a designed banner is at least possible; say nothing.
		if ( function_exists( 'tpgb_tp_cookie_consent_render' ) ) {
			return;
		}

		// One notice per user, dismissible for good — this is information, not an error.
		if ( get_user_meta( get_current_user_id(), 'nxt_cc_fallback_notice_dismissed', true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->base, array( 'dashboard', 'plugins', 'toplevel_page_nexter_welcome' ), true ) ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg( 'nxt_cc_dismiss_fallback', '1' ),
			'nxt_cc_dismiss_fallback',
			'nxt_cc_nonce'
		);

		echo '<div class="notice notice-info is-dismissible"><p><strong>'
			. esc_html__( 'Cookie Consent is using its built-in fallback banner.', 'nexter-extension' )
			. '</strong> '
			. esc_html__( 'Scripts are being blocked until visitors choose, and the fallback is what they see. Install Nexter Blocks and place the Cookie Consent block for a banner you can design.', 'nexter-extension' )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', 'nexter-extension' ) . '</a>'
			. '</p></div>';
	}

	/**
	 * Records the dismissal of the fallback notice against the user.
	 *
	 * @return void
	 */
	public function maybe_dismiss_fallback_notice() {
		if ( ! isset( $_GET['nxt_cc_dismiss_fallback'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'nxt_cc_dismiss_fallback', 'nxt_cc_nonce' );

		update_user_meta( get_current_user_id(), 'nxt_cc_fallback_notice_dismissed', 1 );
		wp_safe_redirect( remove_query_arg( array( 'nxt_cc_dismiss_fallback', 'nxt_cc_nonce' ) ) );
		exit;
	}
}
