<?php
/**
 * POSIMYTH deactivation survey — asks "why are you leaving?" before deactivation and
 * sends the reason to the analytics hub (non-blocking). Config-driven: instantiate once
 * per plugin with that plugin's slug + a unique ajax action. Never blocks deactivation.
 *
 * Usage (in the plugin's main file):
 *   new Posimyth_Deactivation_Survey( array(
 *       'plugin_name' => 'The Plus Addons for Elementor',
 *       'plugin_slug' => 'the-plus-addons-for-elementor-page-builder',
 *       'ajax_action' => 'posimyth_tpae_deact',
 *   ) );
 *
 * @package POSIMYTH\Analytics\SDK
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Posimyth_Deactivation_Survey' ) ) {

	/**
	 * Renders the pre-deactivation dialog and forwards the chosen reason to the analytics hub.
	 */
	class Posimyth_Deactivation_Survey {

		/**
		 * Resolved configuration for this product.
		 *
		 * @var array
		 */
		private array $config;

		/**
		 * The dialog stylesheet is identical for every product, so it is emitted once per page even
		 * when several plugins each render their own (namespaced) dialog.
		 *
		 * @var bool
		 */
		private static bool $styles_printed = false;

		/**
		 * Hooks the dialog into the plugins screen and registers its AJAX handler.
		 *
		 * @param array $config Product configuration; see the wp_parse_args() defaults below.
		 */
		public function __construct( array $config ) {
			$this->config = wp_parse_args(
				$config,
				array(
					'plugin_name'   => '',
					'plugin_slug'   => '',
					// The plugin's file path relative to the plugins dir ('slug/main-file.php'). Used to
					// recognise this plugin's own Deactivate link by its href — the row's data-slug is
					// NOT reliable: core falls back to sanitize_title of the (translated) plugin name,
					// so on translated locales the old data-slug selector matched nothing and the
					// dialog never opened (E3). Defaults to 'slug/slug.php'.
					'plugin_file'   => '',
					'ajax_action'   => '',
					// Deprecated for the submit gate: submitting the form is itself the consent, so a
					// full submit always sends. Retained only so callers passing it don't break.
					'opt_in_option' => '',
					// Callable used for a FULL submit to send "reason + non-sensitive setup info" via the
					// tracker's do_request( $event, $extra ) — e.g. array( 'Posimyth_Tracker_NE', 'do_request' ).
					// When absent, a full submit falls back to a minimal reason payload built here.
					'tracker_cb'    => null,
				)
			);

			if ( empty( $this->config['plugin_slug'] ) || empty( $this->config['ajax_action'] ) ) {
				return;
			}

			add_action( 'admin_footer-plugins.php', array( $this, 'render_modal' ) );
			add_action( 'wp_ajax_' . $this->config['ajax_action'], array( $this, 'handle_ajax' ) );
		}

		/**
		 * The dialog's free-text limit. Matches the hub column (other_text varchar(500)) so nothing a
		 * user can type is ever silently cut down elsewhere — the boundary is visible in the UI.
		 */
		const OTHER_TEXT_MAX = 500;

		/**
		 * The selectable reasons — single source for BOTH the rendered radio list and the AJAX
		 * handler's allowlist, so the two can never drift apart.
		 *
		 * @return array<string,string> Slug => label.
		 */
		private static function reasons(): array {
			return array(
				'no-longer-needed' => __( 'I no longer need the plugin', 'nexter-extension' ),
				'found-better'     => __( 'I found a better plugin', 'nexter-extension' ),
				'not-working'      => __( "It's not working", 'nexter-extension' ),
				'temporary'        => __( "It's a temporary deactivation", 'nexter-extension' ),
				'plugin-conflict'  => __( 'Plugin conflict', 'nexter-extension' ),
				'site-speed'       => __( 'It slowed down my site', 'nexter-extension' ),
				'other'            => __( 'Other', 'nexter-extension' ),
			);
		}

		/**
		 * Prints this product's dialog markup, plus the shared stylesheet and the scoped behaviour.
		 */
		public function render_modal(): void {
			// Not in the network admin (A6): network deactivation is a super admin's bulk operation
			// across the whole install — intercepting it with a per-site "why are you leaving?" dialog
			// is wrong there, and the tracker's own deactivated_plugin listener still records the
			// network deactivation as a single event.
			if ( is_multisite() && is_network_admin() ) {
				return;
			}

			$slug   = $this->config['plugin_slug'];
			$action = $this->config['ajax_action'];
			$name   = empty( $this->config['plugin_name'] ) ? $slug : $this->config['plugin_name'];
			$nonce  = wp_create_nonce( $action );

			// See the config note: recognise our Deactivate link by the plugin file in its href, not
			// by the row's locale-dependent data-slug.
			$plugin_file = ! empty( $this->config['plugin_file'] ) ? $this->config['plugin_file'] : $slug . '/' . $slug . '.php';

			// Every participating product renders its OWN dialog on this screen (the reason has to be
			// attributed to the right plugin_slug, and each has a different deactivation URL), so the
			// markup must be namespaced per plugin. Sharing one id/class set meant two dialogs with
			// duplicate ids in the DOM, and selectors matching both — which is why jQuery's .text()
			// returned the label twice ("Submit & DeactivateSubmit & Deactivate").
			$uid = 'posi-deact-' . sanitize_html_class( $slug );

			$reasons = self::reasons();
			?>
			<?php
			/*
			 * UX notes on this layout:
			 *  - ONE primary action ("Submit & Deactivate"). The previous version offered three
			 *    competing buttons of equal weight, which made the user choose between actions
			 *    instead of just answering the question.
			 *  - "I agree to be contacted" is a CHECKBOX, not a button: it modifies what the
			 *    submission carries, it is not a separate destination.
			 *  - "Skip & Deactivate" is a low-emphasis text button, so opting out is always available
			 *    but never competes with the primary action.
			 *  - Submit stays disabled until a reason is picked, so it cannot be pressed with nothing
			 *    to send.
			 */
			?>
			<div id="<?php echo esc_attr( $uid ); ?>" class="posi-deact-modal">
				<div class="posi-deact-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $uid ); ?>-title" tabindex="-1">
					<h3 id="<?php echo esc_attr( $uid ); ?>-title">
						<?php
						/*
						 * The Nexter mark. Deliberately without the nxt-normal-theme-logo class it carries
						 * elsewhere: this SDK file ships inside several plugins and must not depend on any
						 * host stylesheet, so the sizing lives in .posi-deact-icon below instead.
						 */
						?>
						<span class="posi-deact-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 28 28"><rect width="28" height="28" fill="#1717cc" rx="5.833"></rect><path fill="#fff" d="M12.834 5.32h2.917v11.958a.875.875 0 0 1-.875.875h-2.042zM12.834 19.903h2.042c.483 0 .875.391.875.875v2.041h-2.917z"></path></svg>
						</span>
						<?php esc_html_e( 'Quick Feedback', 'nexter-extension' ); ?>
					</h3>
					<p class="posi-deact-sub">
						<?php
						/* translators: %s: product name */
						echo esc_html( sprintf( __( 'Help us improve %s. Why are you deactivating?', 'nexter-extension' ), $name ) );
						?>
					</p>
					<form id="<?php echo esc_attr( $uid ); ?>-form">
						<ul class="posi-deact-reasons">
							<?php foreach ( $reasons as $val => $label ) : ?>
								<li>
									<label>
										<input type="radio" name="posi_reason" value="<?php echo esc_attr( $val ); ?>">
										<span><?php echo esc_html( $label ); ?></span>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>

						<?php // maxlength mirrors the hub's column width, so long feedback is trimmed visibly here rather than lost later. ?>
						<textarea name="posi_other_text" rows="3" maxlength="<?php echo (int) self::OTHER_TEXT_MAX; ?>" placeholder="<?php esc_attr_e( 'Please share more details…', 'nexter-extension' ); ?>"></textarea>

						<div class="posi-deact-opts">
							<?php
							/*
							 * There is deliberately NO "Submit anonymously" option here.
							 *
							 * It used to exist and it could not keep its promise. WordPress fires
							 * `deactivated_plugin` on every deactivation, and the tracker's listener is gated
							 * on the persistent sharing consent alone — it has no idea which button was
							 * pressed in this dialog. So whenever sharing was already ON, an "anonymous"
							 * submission was immediately followed by a full environment ping carrying the real
							 * site URL and the same reason: the anonymous row was deanonymised seconds after it
							 * was written. Offering a privacy option that the surrounding system overrides is
							 * worse than not offering one. Do not reinstate it without first making the
							 * deactivation ping aware of the user's choice.
							 */
							?>

							<?php
							/*
							 * Opt-in to being contacted. This is the ONLY thing in this dialog that shares a
							 * personal detail (the site's admin email), so it is off by default. What it shares
							 * is disclosed by the consent line below, which JS rewrites when this is ticked so
							 * the "no personal data" wording is never shown while an email is actually being
							 * sent.
							 */
							?>
							<label class="posi-deact-opt">
								<input type="checkbox" class="posi-deact-contact" name="posi_contact_ok" value="1">
								<span><?php esc_html_e( 'I agree to be contacted via email for support with this plugin.', 'nexter-extension' ); ?></span>
							</label>
						</div>

						<div class="posi-deact-footer">
							<?php // Plain '&' — esc_html_e() does the encoding. Passing '&amp;' here would be escaped again and render literally as "&amp;". ?>
							<button type="button" class="posi-deact-skip"><?php esc_html_e( 'Skip & Deactivate', 'nexter-extension' ); ?></button>
							<button type="submit" class="posi-deact-submit" disabled><?php esc_html_e( 'Submit & Deactivate', 'nexter-extension' ); ?></button>
						</div>

						<?php
						/* translators: %s: product name */
						$consent_default = sprintf( __( 'Submitting shares non-sensitive info so we can improve %s. No personal data.', 'nexter-extension' ), $name );
						/* translators: %s: product name */
						$consent_contact = sprintf( __( 'Submitting shares non-sensitive info so we can improve %s, plus your admin email so we can reply.', 'nexter-extension' ), $name );
						?>
						<p class="posi-deact-consent"
							data-default="<?php echo esc_attr( $consent_default ); ?>"
							data-contact="<?php echo esc_attr( $consent_contact ); ?>">
							<?php
							// Starts on the default wording; JS swaps in data-contact when the contact box is ticked.
							echo esc_html( $consent_default );
							?>
						</p>
					</form>
				</div>
			</div>

			<?php
			// The stylesheet is shared by every product's dialog, so print it only once per page even
			// when several plugins each render their own modal.
			if ( ! self::$styles_printed ) :
				self::$styles_printed = true;
				?>
			<style>
			.posi-deact-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center;}
			.posi-deact-dialog{background:#fff;border-radius:8px;padding:28px 32px;max-width:520px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.18);max-height:90vh;overflow:auto;box-sizing:border-box;}
			/* Focused programmatically for screen readers; no visible ring on the container itself. */
			.posi-deact-dialog:focus{outline:none;}
			.posi-deact-dialog h3{margin:0 0 6px;font-size:18px;line-height:1.3;color:#1c1c1c;display:flex;align-items:center;gap:8px;}
			/* flex:none so the mark never squashes if the heading wraps. */
			.posi-deact-icon{display:inline-flex;flex:none;}
			.posi-deact-icon svg{display:block;}
			.posi-deact-dialog .posi-deact-sub{color:#5e5e5e;margin:0 0 18px;font-size:13px;}
			.posi-deact-reasons{margin:0;padding:0;list-style:none;}
			.posi-deact-reasons li{margin:0 0 2px;}
			/* Whole row is the hit target, not just the 16px radio. */
			.posi-deact-reasons label{display:flex;align-items:center;gap:8px;cursor:pointer;padding:7px 8px;margin:0 -8px;border-radius:4px;font-size:13px;color:#1c1c1c;}
			.posi-deact-reasons label:hover{background:#f5f7fe;}
			.posi-deact-reasons input[type=radio]{margin:0;flex:none;}
			.posi-deact-dialog textarea{display:none;width:100%;margin-top:10px;box-sizing:border-box;font-size:13px;}
			.posi-deact-opts{margin:16px 0 0;padding-top:14px;border-top:1px solid #e5e5e5;display:flex;flex-direction:column;gap:8px;}
			/* Each option is its own bordered box so it reads as a distinct, tappable choice. */
			.posi-deact-opt{display:flex;align-items:center;gap:9px;cursor:pointer;font-size:13px;color:#1c1c1c;border:1px solid #dcdcde;border-radius:4px;padding:11px 13px;}
			.posi-deact-opt:hover{border-color:#1717cc;}
			.posi-deact-opt input[type=checkbox]{margin:0;flex:none;}
			/* A disabled option must look disabled, not just refuse the click. */
			.posi-deact-opt.is-disabled{opacity:.5;cursor:not-allowed;}
			.posi-deact-opt.is-disabled:hover{border-color:#dcdcde;}
			/* Skip sits on the left, the primary action on the right. */
			.posi-deact-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:18px;flex-wrap:wrap;}
			.posi-deact-submit{background:#1717cc;border:1px solid #1717cc;color:#fff;padding:8px 18px;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;line-height:1.4;}
			.posi-deact-submit:disabled{background:#c7c7d6;border-color:#c7c7d6;cursor:not-allowed;}
			/* Text button: available, but visually subordinate to the primary action. */
			.posi-deact-skip{background:none;border:0;padding:4px 2px;color:#5e5e5e;font-size:13px;cursor:pointer;text-decoration:underline;font-family:inherit;}
			.posi-deact-skip:hover{color:#1717cc;}
			.posi-deact-consent{color:#787878;font-size:12px;margin:14px 0 0;line-height:1.5;}
			</style>
			<?php endif; ?>

			<script>
			jQuery(function($){
				// EVERYTHING below is scoped to this product's own dialog ($modal). Several products
				// can render a dialog on this screen, so unscoped selectors previously matched all of
				// them: clicking Deactivate on Nexter Blocks opened Nexter Extension's dialog (the
				// first match), and .text() on the shared button class returned every label
				// concatenated ("Submit & DeactivateSubmit & Deactivate").
				var $modal = $('#<?php echo esc_js( $uid ); ?>');
				if ( ! $modal.length ) { return; }

				var $form    = $modal.find('form');
				var $submit  = $modal.find('.posi-deact-submit');
				var $other   = $modal.find('textarea[name="posi_other_text"]');
				var $contact = $modal.find('.posi-deact-contact');
				var $consent = $modal.find('.posi-deact-consent');
				var nonce    = '<?php echo esc_js( $nonce ); ?>';

				// The consent line must never claim "No personal data" while an email is about to be
				// sent, so it is rewritten from the markup's data attributes when the box is ticked.
				function syncOptions(){
					$consent.text( $contact.is(':checked') ? $consent.data('contact') : $consent.data('default') );
				}
				$contact.on('change', syncOptions);
				// Read the label from the markup rather than re-printing it: esc_js() HTML-encodes '&'
				// to '&amp;' and jQuery .text() writes that literally.
				var submitLabel = $submit.text();

				function closeModal(){
					$modal.hide();
				}

				/*
				 * Delegated + matched by href, not a direct bind on a data-slug row (E3):
				 *  - direct handlers died when wp.updates re-rendered the row (after an update or a
				 *    bulk action), so the next Deactivate click sailed straight past the dialog;
				 *  - data-slug falls back to sanitize_title of the TRANSLATED plugin name, so on
				 *    non-English locales the selector matched nothing and the dialog never opened.
				 * The plugin=<file> pair in the href is locale-proof and survives re-renders.
				 */
				var pluginParam = 'plugin=' + encodeURIComponent('<?php echo esc_js( $plugin_file ); ?>');
				$(document).on('click.<?php echo esc_js( $uid ); ?>', 'span.deactivate a', function(e){
					var href = $(this).attr('href') || '';
					if ( href.indexOf( pluginParam ) === -1 ) { return; }
					e.preventDefault();
					var deactUrl = href;

					// Reset so reopening never shows a stale selection or a stuck button.
					if ( $form.length && $form[0] ) { $form[0].reset(); }
					$other.hide();
					$submit.prop('disabled', true).text(submitLabel);
					syncOptions();   // form.reset() clears the boxes; re-apply the derived UI state
					$modal.css('display','flex');
					// Focus the dialog, NOT the first radio — a focus ring on a radio reads as though
					// it were already selected while Submit is (correctly) still disabled.
					$modal.find('.posi-deact-dialog').trigger('focus');

					$modal.find('input[name="posi_reason"]').off('change.posi').on('change.posi', function(){
						$other.toggle( $(this).val() === 'other' );
						$submit.prop('disabled', false);   // something to submit now
					});

					function send(){
						var reason = $modal.find('input[name="posi_reason"]:checked').val() || 'no-reason';
						var other  = $other.val() || '';
						$submit.prop('disabled', true).text(<?php echo wp_json_encode( __( 'Submitting…', 'nexter-extension' ) ); ?>);
						$.post(ajaxurl, {
							action: '<?php echo esc_js( $action ); ?>',
							nonce:  nonce,
							reason: reason,
							other_text: other,
							// Only ever 1 when the user ticked the contact box; the server reads the email
							// from the site itself, so no address is posted from the browser.
							contact_ok: $contact.is(':checked') ? 1 : 0
						}).always(function(){ window.location.href = deactUrl; });
					}

					// One primary action. Submitting IS the consent: it sends the reason plus the
					// non-sensitive setup payload, and the admin email only if the contact box is ticked.
					$form.off('submit.posi').on('submit.posi', function(ev){
						ev.preventDefault();
						send();
					});

					// Skip → send nothing, just deactivate.
					$modal.find('.posi-deact-skip').off('click.posi').on('click.posi', function(){
						window.location.href = deactUrl;
					});
				});

				// Backdrop click (only the overlay itself, not the dialog) and Esc simply back out:
				// the dialog closes and the plugin stays ACTIVE. That is deliberately different from
				// "Skip & Deactivate", which does deactivate. Neither sends anything.
				$modal.on('click', function(e){
					if ( e.target === this ) { closeModal(); }
				});

				$(document).on('keydown', function(e){
					if ( ( e.key === 'Escape' || e.keyCode === 27 ) && $modal.is(':visible') ) {
						closeModal();
					}
				});
			});
			</script>
			<?php
		}

		/**
		 * Receives a submission from the dialog and forwards it. Skip / Esc never reach this handler.
		 */
		public function handle_ajax(): void {
			// Nonce AND capability. The dialog only renders on plugins.php, which WordPress already
			// gates on activate_plugins, so the same capability is required here — a handler should not
			// depend on its callers for authorisation. Checked before the nonce so an unprivileged
			// caller gets a clear 403 rather than a nonce failure.
			if ( ! current_user_can( 'activate_plugins' ) ) {
				wp_send_json_error( array( 'message' => 'unauthorized' ), 403 );
			}
			check_ajax_referer( $this->config['ajax_action'], 'nonce' );

			// Submitting is itself the consent: it sends the reason plus the non-sensitive setup
			// payload. Skip / Esc / backdrop never reach this handler, so nothing is sent for them.
			//
			// There is no longer an 'anon' mode. It wrote a row under a placeholder site_url, but
			// WordPress then fired `deactivated_plugin`, whose listener is gated on the persistent
			// sharing consent and cannot see what was clicked here — so a full ping with the real site
			// URL and the same reason followed moments later and undid it.
			// Allowlisted against the same map the dialog renders (A7): the radio list is the only
			// legitimate source, so anything else — a tampered request inventing its own "reason" —
			// collapses to no-reason instead of flowing into the hub as free text.
			$reason = sanitize_key( $_POST['reason'] ?? 'no-reason' );
			if ( 'no-reason' !== $reason && ! array_key_exists( $reason, self::reasons() ) ) {
				$reason = 'no-reason';
			}

			// Capped to the dialog's visible maxlength (A7): without this, a hand-crafted request
			// could push a multi-megabyte body through to the hub.
			$other_text = substr( sanitize_textarea_field( wp_unslash( $_POST['other_text'] ?? '' ) ), 0, self::OTHER_TEXT_MAX );

			// The regular payload carries no personal data. The admin email is attached ONLY when the
			// user ticked "I agree to be contacted" — and it is read from the site, never from the
			// posted form, so the browser cannot inject an arbitrary address.
			$contact_ok = ! empty( $_POST['contact_ok'] );
			$extra      = array();

			if ( $contact_ok ) {
				$email = sanitize_email( (string) get_option( 'admin_email', '' ) );
				if ( is_email( $email ) ) {
					$extra['contact_email'] = $email;
					$extra['contact_ok']    = 1;
				}
			}

			/*
			 * Tell the tracker this deactivation is already accounted for.
			 *
			 * WordPress fires `deactivated_plugin` moments after this, and that listener has no way of
			 * knowing the dialog just reported the same deactivation with a reason attached — so every
			 * opted-in deactivation was sent twice, once with a reason and once without, double-counting
			 * churn for exactly the opted-in cohort. The marker is short-lived and consumed by the
			 * listener.
			 */
			if ( is_callable( $this->config['tracker_cb'] ) && is_array( $this->config['tracker_cb'] ) ) {
				$tracker_class = $this->config['tracker_cb'][0];
				if ( is_string( $tracker_class ) && method_exists( $tracker_class, 'mark_deactivation_reported' ) ) {
					call_user_func( array( $tracker_class, 'mark_deactivation_reported' ) );
				}
			}

			// The click itself is consent, so this sends regardless of the persistent
			// Share-Non-Sensitive-Details toggle. Prefer the tracker's do_request(), which attaches the
			// full non-sensitive environment payload plus the reason.
			if ( is_callable( $this->config['tracker_cb'] ) ) {
				call_user_func(
					$this->config['tracker_cb'],
					'deactivate',
					array_merge(
						array(
							'reason'         => $reason,
							'other_text'     => $other_text,
							'consent_source' => 'deactivation_submit',
						),
						$extra
					),
					// The third argument is do_request()'s $bypass_consent. This is the ONE place that
					// may pass true: the user pressing Submit in this dialog is the consent for this
					// single submission, so it must send even when the persistent toggle is off.
					true
				);
				wp_send_json_success();
			}

			// Fallback (no tracker wired): minimal reason payload with site context.
			$this->send_raw(
				array_merge(
					array(
						'plugin_slug' => $this->config['plugin_slug'],
						'site_url'    => home_url(),
						'event'       => 'deactivate',
						'reason'      => $reason,
						'other_text'  => $other_text,
					),
					$extra
				)
			);

			wp_send_json_success();
		}

		/**
		 * Fire-and-forget POST of a raw body to the analytics hub. Never blocks deactivation.
		 *
		 * @param array $body Payload to send.
		 */
		private function send_raw( array $body ): void {
			$endpoint = defined( 'POSIMYTH_ANALYTICS_ENDPOINT' )
				? POSIMYTH_ANALYTICS_ENDPOINT
				: 'https://api.posimyth.com/wp-json/posimyth/v1/track';

			$json = wp_json_encode( $body );

			// No ingest key / signature — the hub endpoint is open. See the note in
			// Posimyth_Tracker_Base::do_request() for why a baked shared key authenticated nothing.
			$headers = array(
				'Content-Type' => 'application/json',
			);

			// Same fix as Posimyth_Tracker_Base::do_request(): a 0.01s timeout aborted long before a
			// real round trip to the hub could finish (~4s measured), so submitted feedback was
			// silently lost. Send on shutdown — after wp_send_json_success() has already returned the
			// response and the browser has been redirected to complete the deactivation — with a
			// timeout that lets the request actually land. Deactivation is never delayed or blocked.
			$timeout = (int) apply_filters( 'posimyth_analytics_request_timeout', 15 );

			$dispatch = static function () use ( $endpoint, $headers, $json, $timeout ) {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}
				wp_remote_post(
					$endpoint,
					array(
						'headers'     => $headers,
						'body'        => $json,
						'timeout'     => $timeout,
						'blocking'    => true,
						'data_format' => 'body',
						'sslverify'   => true,
					)
				);
			};

			if ( did_action( 'shutdown' ) ) {
				$dispatch();
				return;
			}
			add_action( 'shutdown', $dispatch, 99 );
		}
	}
}
