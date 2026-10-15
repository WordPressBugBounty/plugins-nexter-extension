<?php
// Edits the WP_DEBUG constants in wp-config.php for the WP Debug Mode module.
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxt_debug_mode_config_path' ) ) {
	/**
	 * Locate wp-config.php the way WordPress does: in ABSPATH, or one level up when that folder is not a WordPress root.
	 *
	 * @return string Path, or '' when there is none.
	 */
	function nxt_debug_mode_config_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$parent = dirname( untrailingslashit( ABSPATH ) );
		if ( file_exists( $parent . '/wp-config.php' ) && ! file_exists( $parent . '/wp-settings.php' ) ) {
			return $parent . '/wp-config.php';
		}
		return '';
	}
}

if ( ! function_exists( 'nxt_debug_mode_update_config' ) ) {
	/**
	 * Switch debugging on or off in wp-config.php.
	 *
	 * Every edit is marked, so switching off puts back exactly what the site had before.
	 *
	 * @param bool $enable True to switch debugging on.
	 * @throws \Exception When the file cannot be found, read, written or would become invalid PHP.
	 */
	function nxt_debug_mode_update_config( $enable ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;

		if ( ! WP_Filesystem() ) {
			throw new \Exception( 'Could not initialize WP_Filesystem.' );
		}
		$config = nxt_debug_mode_config_path();
		if ( '' === $config || ! $wp_filesystem->exists( $config ) ) {
			throw new \Exception( 'Config file not found.' );
		}
		if ( ! $wp_filesystem->is_writable( $config ) ) {
			throw new \Exception( 'wp-config.php is not writable. Please check file permissions.' );
		}
		$content = $wp_filesystem->get_contents( $config );
		if ( false === $content ) {
			throw new \Exception( 'Failed to read wp-config.php.' );
		}

		// Undo an earlier edit first, so on and off always start from the site's own file.
		$content = preg_replace( '/^[^\n]*\/\/ nexter-debug-mode\r?\n/m', '', $content );
		$content = preg_replace_callback(
			'/define\( \'WP_DEBUG\', true \); \/\* nexter-debug-was: (.*?) \*\//s',
			function ( $m ) {
				return $m[1];
			},
			$content
		);

		if ( $enable ) {
			$add    = '';
			$regexp = '/define\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(true|false|0|1|!0|!1)\s*\);/';
			if ( preg_match( $regexp, $content, $found ) ) {
				$content = preg_replace_callback(
					$regexp,
					function ( $m ) {
						return "define( 'WP_DEBUG', true ); /* nexter-debug-was: " . $m[0] . ' */';
					},
					$content,
					1
				);
			} else {
				$add .= "define( 'WP_DEBUG', true ); // nexter-debug-mode\n";
			}
			// Log errors and keep them off visitors' screens, unless the site already decided.
			if ( false === strpos( $content, 'WP_DEBUG_LOG' ) ) {
				$add .= "define( 'WP_DEBUG_LOG', true ); // nexter-debug-mode\n";
			}
			if ( false === strpos( $content, 'WP_DEBUG_DISPLAY' ) ) {
				$add .= "define( 'WP_DEBUG_DISPLAY', false ); // nexter-debug-mode\n";
			}
			if ( '' !== $add ) {
				$pos = strpos( $content, "\n" );
				if ( false === $pos ) {
					throw new \Exception( 'Something went wrong while processing the file.' );
				}
				$content = substr_replace( $content, $add, $pos + 1, 0 );
			}
		}

		// Never write a wp-config.php that no longer parses: that would take the whole site down.
		try {
			token_get_all( $content, TOKEN_PARSE );
		} catch ( \Throwable $e ) {
			throw new \Exception( 'Refusing to write an invalid wp-config.php.' );
		}

		if ( ! $wp_filesystem->put_contents( $config, $content, FS_CHMOD_FILE ) ) {
			throw new \Exception( 'Failed to write wp-config.php.' );
		}
	}
}
