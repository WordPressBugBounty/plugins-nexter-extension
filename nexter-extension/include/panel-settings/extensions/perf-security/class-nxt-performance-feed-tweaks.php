<?php
/**
 * Performance Feed Tweaks
 *
 * @package Nexter Extension
 * @since 4.6.3
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Performance_Feed_Tweaks {
	/**
	 * Block feed requests and redirect visitors to canonical homepage URL.
	 *
	 * @return void
	 */
	public function disable_rss_feeds() {
		if ( ! is_feed() || is_404() ) {
			return;
		}

		$get_feed = isset( $_GET['feed'] ) ? sanitize_text_field( wp_unslash( $_GET['feed'] ) ) : '';
		if ( ! empty( $get_feed ) ) {
			$redirect_url = remove_query_arg( 'feed' );
			wp_safe_redirect( esc_url_raw( $redirect_url ), 301 );
			exit;
		}

		if ( get_query_var( 'feed' ) !== 'old' ) {
			set_query_var( 'feed', '' );
		}

		redirect_canonical();

		wp_die(
			sprintf(
				esc_html__( 'No feed available, please visit the %s!', 'nexter-extension' ),
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( home_url( '/' ) ),
					esc_html__( 'Home Page', 'nexter-extension' )
				)
			)
		);
	}

	/**
	 * Remove self-referencing pingback URLs.
	 *
	 * @param array $links Pingback links array (by reference).
	 * @return void
	 */
	public function disable_self_pingbacks( &$links ) {
		$home = home_url();
		foreach ( $links as $l => $link ) {
			if ( strpos( $link, $home ) === 0 ) {
				unset( $links[ $l ] );
			}
		}
	}
}
