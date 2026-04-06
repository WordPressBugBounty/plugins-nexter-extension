<?php
/**
 * Cloudflare Turnstile renderer helper.
 *
 * @since 4.6.4
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Cloudflare_Turnstile_Renderer {

	public static function render_widget( $site_key, $form_action = '', $callback_function = '', $button_selector = '', $css_class = '', $size = 'flexible', $disable_submit_btn = true ) {
		if ( empty( $site_key ) ) {
			return;
		}

		$theme     = 'light';
		$unique_id = '-' . uniqid();
		$widget_id = 'cf-turnstile' . esc_attr( $unique_id );

		do_action( 'nxt_turnstile_enqueue_scripts' );
		do_action( 'nxt_turnstile_before_field', esc_attr( $unique_id ) );
		?>
		<div id="<?php echo esc_attr( $widget_id ); ?>" class="cf-turnstile<?php echo $css_class ? ' ' . esc_attr( $css_class ) : ''; ?>" <?php if ( $disable_submit_btn ) : ?>data-callback="<?php echo esc_attr( $callback_function ); ?>"<?php endif; ?> data-sitekey="<?php echo esc_attr( $site_key ); ?>" data-theme="<?php echo esc_attr( $theme ); ?>" data-language="auto" data-size="<?php echo esc_attr( $size ); ?>" data-retry="auto" data-retry-interval="1500" data-action="<?php echo esc_attr( $form_action ); ?>" data-appearance="always"></div>
		<?php if ( $form_action == 'wordpress-login' ) : ?>
			<style>#login{min-width:350px!important;}</style>
		<?php endif; ?>
		<script>
			(function() {
				var widgetId = "<?php echo esc_js( $widget_id ); ?>";
				var siteKey = "<?php echo esc_js( $site_key ); ?>";
				var formAction = "<?php echo esc_js( $form_action ); ?>";
				var theme = "<?php echo esc_js( $theme ); ?>";
				var size = "<?php echo esc_js( $size ); ?>";
				var retryCount = 0, maxRetries = 50, elementRetryCount = 0, maxElementRetries = 20;
				function initTurnstile() {
					if (typeof turnstile === 'undefined') { retryCount++; if (retryCount < maxRetries) { setTimeout(initTurnstile, 100); } return; }
					var el = document.getElementById(widgetId);
					if (!el) { elementRetryCount++; if (elementRetryCount < maxElementRetries) { setTimeout(initTurnstile, 100); } return; }
					try {
						try { turnstile.remove("#" + widgetId); } catch(e) {}
						var renderOptions = { sitekey: siteKey, action: formAction, theme: theme, size: size, language: "auto", retry: "auto", "retry-interval": 1500, appearance: "always" };
						<?php if ( ! empty( $callback_function ) ) : ?>
						if (typeof window.<?php echo esc_js( $callback_function ); ?> === 'function') { renderOptions.callback = window.<?php echo esc_js( $callback_function ); ?>; }
						else if (typeof <?php echo esc_js( $callback_function ); ?> === 'function') { renderOptions.callback = <?php echo esc_js( $callback_function ); ?>; }
						<?php endif; ?>
						turnstile.render("#" + widgetId, renderOptions);
					} catch(error) {}
				}
				if (document.readyState === 'loading') { document.addEventListener("DOMContentLoaded", function() { setTimeout(initTurnstile, 100); }); }
				else { setTimeout(initTurnstile, 100); }
			})();
		</script>
		<?php if ( $disable_submit_btn ) : ?>
			<style><?php echo esc_html( $button_selector ); ?> { pointer-events:none; opacity:0.5; }</style>
		<?php endif; ?>
		<?php
		do_action( 'nxt_turnstile_after_field', esc_attr( $unique_id ), $button_selector );
		echo '<br class="cf-turnstile-br cf-turnstile-br' . esc_attr( $unique_id ) . '">';
	}
}

