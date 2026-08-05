<?php
/**
 * POSIMYTH consent notice — the admin notice that asks for permission to share non-sensitive
 * setup information. One notice for the whole Nexter suite: the opt-in option, the dismissal
 * flag and the notice itself are shared, so answering it once answers it for every product.
 *
 * @package POSIMYTH\Analytics\SDK
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Posimyth_Consent_Notice' ) ) {

	/**
	 * Renders and handles the suite-wide analytics consent notice.
	 */
	class Posimyth_Consent_Notice {

		const VERSION = '1.1.0';

		/**
		 * Shared across every plugin instance in this suite: only one notice renders per page load.
		 *
		 * @var bool
		 */
		private static bool $rendered_this_request = false;

		/**
		 * Every product that shares this consent, keyed by plugin slug => plugin name.
		 *
		 * Populated in the constructor, so it is an exact list of the products actually participating —
		 * no guessing from version constants. That matters because sibling POSIMYTH software can be
		 * present without sharing this consent (the Nexter *theme*, for example, defines NXT_VERSION but
		 * ships no tracker), and counting those would wrongly make a single-plugin site look like a
		 * multi-product one. A product suppressed by white-label never reaches the constructor, so it
		 * correctly does not count either.
		 *
		 * @var array<string,string>
		 */
		private static array $registered_products = array();

		/**
		 * Resolved configuration for this product.
		 *
		 * @var array
		 */
		private array $config;

		/**
		 * Registers this product as a consent participant and wires the notice up.
		 *
		 * @param array $config Product configuration; see the wp_parse_args() defaults below.
		 */
		public function __construct( array $config ) {
			$this->config = wp_parse_args(
				$config,
				array(
					'plugin_name'      => '',
					'plugin_slug'      => '',
					'opt_in_option'    => '',
					'ajax_action'      => '',
					'installed_option' => '',
					'tracker_cb'       => null,
					'logo_url'         => '',
					// Fallback only — each product passes its own placement-tagged URL.
					'docs_url'         => 'https://nexterwp.com/docs/data-sharing/?utm_source=wpbackend&utm_medium=admin&utm_campaign=datasharingnotice',
					// Shown instead of plugin_name when more than one Nexter product is active, because the
					// consent is suite-wide: naming a single plugin would imply the choice only covers that
					// one. See suite_display_name().
					'suite_name'       => 'Nexter',
					// Suite-wide dedupe: same key across all Nexter plugins so only ONE notice
					// ever shows, and dismissing it counts for the whole suite, not one plugin.
					'suite_key'        => 'nexter_suite',
				)
			);

			// Record this product as sharing the consent (see $registered_products).
			if ( ! empty( $this->config['plugin_slug'] ) ) {
				self::$registered_products[ $this->config['plugin_slug'] ] = (string) $this->config['plugin_name'];
			}

			$this->hooks();
		}

		/**
		 * Registers the notice, its AJAX handler and its inline assets.
		 */
		private function hooks(): void {
			add_action( 'admin_notices', array( $this, 'render' ) );
			add_action( 'wp_ajax_' . $this->config['ajax_action'], array( $this, 'handle_ajax' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		}

		/**
		 * Prints the notice's CSS/JS inline, only on screens where the notice itself will render.
		 */
		public function enqueue(): void {
			if ( ! $this->should_show() ) {
				return;
			}
			wp_add_inline_style( 'wp-admin', $this->inline_css() );
			wp_add_inline_script( 'jquery', $this->inline_js() );
		}

		/**
		 * Outputs the notice. No-ops when consent is already answered or another suite product
		 * already rendered it this request.
		 */
		public function render(): void {
			if ( ! $this->should_show() ) {
				return;
			}
			// Suite-wide dedupe: if TPAE/Nexter Extension/Nexter Blocks are all active and each
			// instantiates this class, only the first one to run on this page actually renders.
			if ( self::$rendered_this_request ) {
				return;
			}
			self::$rendered_this_request = true;

			// Kept raw here and escaped at each point of output — pre-escaping made it impossible to
			// tell (for a reader or for PHPCS) whether a given echo was safe.
			$slug  = $this->config['plugin_slug'];
			$name  = $this->suite_display_name();
			$docs  = $this->config['docs_url'];
			$nonce = wp_create_nonce( $this->config['ajax_action'] );
			// Deliberately NOT 'is-dismissible': the notice has its own explicit Dismiss button, and
			// core's injected "x" would be a second, redundant control sitting right beside it.
			?>
		<div class="notice notice-info nxt-notice-wrap posi-consent-notice" id="posi-consent-<?php echo esc_attr( $slug ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-action="<?php echo esc_attr( $this->config['ajax_action'] ); ?>">
			<span class="posi-notice-icon" aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24"><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 20V12M12 20V4m7 16v-6"/></svg>
			</span>
			<span class="posi-notice-text">
				<?php
				printf(
					/* translators: %s: product name */
					esc_html__( 'Help make %s faster and more stable. Share basic, non-sensitive info so we can catch conflicts and ship fixes quicker — no personal data, ever.', 'nexter-extension' ),
					'<strong>' . esc_html( $name ) . '</strong>'
				);
				?>
				<a href="<?php echo esc_url( $docs ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( "See what's shared", 'nexter-extension' ); ?> &rarr;</a>
			</span>
			<span class="posi-notice-actions">
				<button type="button" class="nxt-nobtn-primary posi-consent-allow" data-choice="allow"><?php esc_html_e( 'Allow', 'nexter-extension' ); ?></button>
				<button type="button" class="nxt-nobtn-secondary posi-consent-skip" data-choice="skip"><?php esc_html_e( 'Dismiss', 'nexter-extension' ); ?></button>
			</span>
		</div>
			<?php
		}

		/**
		 * How long "Dismiss" hides the notice for, in seconds.
		 *
		 * Dismiss is a SNOOZE, not a permanent no. The ask has to survive being ignored, because a
		 * notice that disappears forever on the first stray click means most sites are never asked and
		 * sharing stays off by default with no decision ever made. Only "Allow", or turning the setting
		 * on from Onboarding or Dashboard → Settings, stops it for good.
		 *
		 * It is a snooze rather than "show on every page load" on purpose: re-asking an administrator
		 * on every single screen is nagging, and WordPress's own guidance is that admin notices must be
		 * dismissible. 30 days keeps the ask alive without becoming that.
		 */
		const SNOOZE_SECONDS = 30 * DAY_IN_SECONDS;

		/**
		 * Stores the Allow/Dismiss choice, suite-wide.
		 */
		public function handle_ajax(): void {
			// Nonce AND capability, not nonce alone. Today the nonce is only ever printed inside markup
			// that render() already gates on manage_options, so this is not exploitable — but that makes
			// the handler's safety a property of its callers rather than of itself. This flips consent
			// for the whole site, so it checks for the capability directly.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'unauthorized' ), 403 );
			}
			check_ajax_referer( $this->config['ajax_action'], 'nonce' );
			$choice = sanitize_key( $_POST['choice'] ?? 'skip' );

			// Both flags are keyed on suite_key, not on the plugin slug, so Nexter Extension and Nexter
			// Blocks share one answer and the user is never asked twice.
			if ( 'allow' === $choice ) {
				update_option( $this->config['opt_in_option'], 1 );
				// An explicit yes is final: never ask again, even if sharing is later turned back off.
				update_option( 'posi_consent_dismissed_' . $this->config['suite_key'], 1 );
				if ( is_callable( $this->config['tracker_cb'] ) ) {
					call_user_func( $this->config['tracker_cb'], 'activate' );
				}
			} else {
				update_option( $this->config['opt_in_option'], 0 );
				// Dismiss only snoozes — see SNOOZE_SECONDS.
				update_option( 'posi_consent_snoozed_until_' . $this->config['suite_key'], time() + self::SNOOZE_SECONDS );
			}

			wp_send_json_success();
		}

		/**
		 * Whether the notice should render for the current user, screen and consent state.
		 *
		 * @return bool
		 */
		private function should_show(): bool {
			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}
			// Answered for good: "Allow" was clicked (see handle_ajax). Never ask again.
			if ( get_option( 'posi_consent_dismissed_' . $this->config['suite_key'] ) ) {
				return false;
			}

			// This notice, the Dashboard toggle and the Onboarding checkbox are three surfaces for ONE
			// suite-wide setting — not independent prompts. So if sharing is already enabled there is
			// nothing left to ask, and showing an "Allow / Dismiss" notice would be asking for
			// permission the user has already granted (and could even read as though it were off).
			// This is also what stops the notice when the user says yes from Onboarding or from
			// Dashboard → Settings rather than from the notice itself.
			if ( ! empty( $this->config['opt_in_option'] ) && ! empty( get_option( $this->config['opt_in_option'] ) ) ) {
				return false;
			}

			// Snoozed by a previous "Dismiss" — comes back when the snooze expires.
			$snoozed_until = (int) get_option( 'posi_consent_snoozed_until_' . $this->config['suite_key'], 0 );
			if ( $snoozed_until > time() ) {
				return false;
			}

			// NOTE: there is deliberately no "first successful use" gate any more.
			//
			// It used to require a saved setting before asking, so a fresh install was never asked at
			// all until the user happened to save something — and plenty of sites never do. The ask now
			// starts from activation and keeps coming back (snoozed, not silenced) until the user
			// actually decides, either here or from Onboarding / Dashboard → Settings.
			//
			// installed_option is still accepted in the config for backwards compatibility, but it is
			// no longer consulted; mark_first_use() is now a no-op for gating purposes.
			return true;
		}

		/**
		 * Product name to show in the copy.
		 *
		 * One notice is rendered for the whole suite and the consent it stores is suite-wide, so when
		 * more than one Nexter product is active naming just one of them ("Help make Nexter Blocks…")
		 * would misrepresent what the user is agreeing to — and which notice happened to render first
		 * is arbitrary. In that case fall back to the suite name ("Nexter"). With a single product
		 * active, its own name is the clearer, more specific label.
		 *
		 * @return string
		 */
		private function suite_display_name(): string {
			if ( count( self::$registered_products ) > 1 && ! empty( $this->config['suite_name'] ) ) {
				return (string) $this->config['suite_name'];
			}

			return (string) $this->config['plugin_name'];
		}

		/**
		 * Call this from the moment that counts as "first successful use" for this plugin
		 * (not activation) — e.g. first CPT/taxonomy/field-group created for Nexter Extension,
		 * first block inserted for Nexter Blocks, first widget dropped for TPAE.
		 */
		public function mark_first_use(): void {
			if ( ! get_option( $this->config['installed_option'], 0 ) ) {
				update_option( $this->config['installed_option'], time(), false );
			}
		}

		/**
		 * Compact inline notice, styled with Nexter Extension's visual language.
		 *
		 * Deliberately a single row rather than the tall `nexter-license-activate` block used by
		 * promo/licence notices: this is an optional, low-priority ask that appears on ordinary admin
		 * screens, so it should not push the page down. It still reuses Extension's accent colour
		 * (#1717cc), border treatment (nxt-notice-wrap) and button classes (nxt-nobtn-primary /
		 * nxt-nobtn-secondary) so it clearly belongs to the suite.
		 *
		 * Those button/border rules live in Nexter Extension's admin stylesheet, but this SDK also
		 * ships inside products that can run WITHOUT Nexter Extension (e.g. Nexter Blocks on its own),
		 * so the same values are repeated here. When Extension's CSS is present the values are
		 * identical, so nothing conflicts.
		 */
		private function inline_css(): string {
			return '
        /* Compact single-row layout: this is an optional ask, so it should not occupy a tall
           block at the top of every admin screen. Nexter Extension\'s colours and button styles
           are reused so it still reads as part of the suite.
           The two-class selector is required: Extension\'s stylesheet sets
           `.nxt-notice-wrap{padding-left:0}`, which has the same specificity as a single class and
           would otherwise win on load order, leaving the icon jammed against the left border. */
        .posi-consent-notice.nxt-notice-wrap {
            border-left-color: #1717cc;
            display: flex;
            align-items: center;
            flex-wrap: nowrap;
            gap: 12px;
            padding: 12px 16px;
        }
        .posi-consent-notice .posi-notice-icon {
            flex: none;
            line-height: 0;
            display: inline-flex;
        }
        .posi-consent-notice .posi-notice-text {
            flex: 1 1 auto;
            min-width: 0;
            font-size: 13px;
            line-height: 1.5;
            color: #1c1c1c;
        }
        .posi-consent-notice .posi-notice-text a { margin-left: 4px; white-space: nowrap; }
        .posi-consent-notice .posi-notice-actions {
            flex: none;
            display: flex;
            align-items: center;
            white-space: nowrap;
        }
        .posi-consent-notice .nxt-nobtn-primary,
        .posi-consent-notice .nxt-nobtn-secondary {
            padding: 7px 16px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 12px;
            line-height: 18px;
            text-decoration: none;
            display: inline-flex;
            cursor: pointer;
            box-shadow: none;
            font-family: inherit;
        }
        .posi-consent-notice .nxt-nobtn-primary,
        .posi-consent-notice .nxt-nobtn-primary:hover,
        .posi-consent-notice .nxt-nobtn-primary:focus {
            background-color: #1717cc;
            border: 1px solid #1717cc;
            color: #fff;
            outline: 0;
        }
        .posi-consent-notice .nxt-nobtn-secondary,
        .posi-consent-notice .nxt-nobtn-secondary:hover,
        .posi-consent-notice .nxt-nobtn-secondary:focus {
            background-color: #fff;
            color: #1717cc;
            border: 1px solid #1717cc;
            margin-left: 8px;
            padding: 8px 16px;
            outline: 0;
        }
        @media (max-width: 782px) {
            .posi-consent-notice.nxt-notice-wrap { flex-wrap: wrap; }
            .posi-consent-notice .posi-notice-actions { margin-top: 8px; }
        }
        ';
		}

		/**
		 * Notice behaviour: removes the notice the instant a choice is clicked, then stores that choice
		 * in the background.
		 *
		 * The request deliberately does NOT gate the hide. Waiting for it meant the notice sat there
		 * for the length of a round trip after the user had already answered, which reads as an ignored
		 * click. The click is the answer, so the UI acts on it immediately; if the request somehow
		 * fails, nothing was persisted and the notice simply appears again on the next page load —
		 * which is the correct outcome, not a lost choice.
		 *
		 * @return string
		 */
		private function inline_js(): string {
			return "
        jQuery(function($){
            function posiSend(notice, choice){
                if (!notice.length || notice.data('posiSent')) { return; }
                notice.data('posiSent', true);

                // Read the request details BEFORE touching the DOM: jQuery's .remove() also discards
                // the element's data, so reading data('action') / data('nonce') afterwards would send
                // action=undefined and the choice would never be stored.
                var payload = {
                    action: notice.data('action'),
                    choice: choice,
                    nonce:  notice.data('nonce')
                };

                // Gone immediately — before the request, not after it.
                notice.remove();

                $.post(ajaxurl, payload);
            }

            // The notice is not 'is-dismissible' — its own Dismiss button is the only close control,
            // so there is no core 'x' to bind (and no risk of a close that silently fails to store
            // the choice).
            $(document).on('click', '.posi-consent-allow, .posi-consent-skip', function(){
                var btn = $(this);
                posiSend(btn.closest('.posi-consent-notice'), btn.data('choice'));
            });
        });
        ";
		}
	}
}
