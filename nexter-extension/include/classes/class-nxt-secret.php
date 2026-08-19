<?php
/*
 * At-rest protection for stored SMTP credentials and OAuth tokens.
 * @since 4.7.7
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Secret {

	/**
	 * Envelope marker. A stored value without it is legacy cleartext and is returned untouched,
	 * so credentials saved by earlier versions keep working.
	 */
	const PREFIX = 'nxtenc1:';

	/**
	 * Option holding the SMTP settings tree.
	 */
	const OPTION = 'nexter_extra_ext_options';

	/**
	 * Option holding this site's encryption key. It travels inside the same database dump as the
	 * secrets it protects, so a staging-to-live restore keeps working regardless of wp-config salts.
	 */
	const KEY_OPTION = 'nexter_secret_key';

	/**
	 * Keys under smtp-email.values that must never be stored in the clear.
	 *
	 * @var array
	 */
	private static $secret_keys = array( 'password', 'access_token', 'refresh_token' );

	/**
	 * Encrypt every secret key on the way into the database, whichever screen saved it.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'pre_update_option_' . self::OPTION, array( __CLASS__, 'encrypt_option_secrets' ), 99, 2 );
	}

	/**
	 * @param mixed $value Incoming option value.
	 * @param mixed $old   Previous option value.
	 * @return mixed
	 */
	public static function encrypt_option_secrets( $value, $old ) {
		if ( ! is_array( $value ) || ! isset( $value['smtp-email'] ) ) {
			return $value;
		}

		$node = is_object( $value['smtp-email'] ) ? (array) $value['smtp-email'] : $value['smtp-email'];
		if ( ! is_array( $node ) || ! isset( $node['values'] ) ) {
			return $value;
		}

		$values = is_object( $node['values'] ) ? (array) $node['values'] : $node['values'];
		if ( ! is_array( $values ) ) {
			return $value;
		}

		$values = self::encrypt_keys( $values );

		// The custom-SMTP password lives one level deeper than the OAuth tokens.
		if ( isset( $values['custom'] ) ) {
			$custom = is_object( $values['custom'] ) ? (array) $values['custom'] : $values['custom'];
			if ( is_array( $custom ) ) {
				$values['custom'] = self::encrypt_keys( $custom );
			}
		}

		$node['values']       = $values;
		$value['smtp-email']  = $node;

		return $value;
	}

	/**
	 * @param array $bag Associative array possibly holding secret keys.
	 * @return array
	 */
	private static function encrypt_keys( $bag ) {
		foreach ( self::$secret_keys as $key ) {
			if ( isset( $bag[ $key ] ) && is_string( $bag[ $key ] ) && '' !== $bag[ $key ] ) {
				$bag[ $key ] = self::encrypt( $bag[ $key ] );
			}
		}

		return $bag;
	}
	/**
	 * @param string $plain Cleartext secret.
	 * @return string Envelope, or the input unchanged when encryption is unavailable.
	 */
	public static function encrypt( $plain ) {
		if ( ! is_string( $plain ) || '' === $plain || self::is_encrypted( $plain ) ) {
			return $plain;
		}

		if ( ! self::available() ) {
			return $plain;
		}

		$iv     = openssl_random_pseudo_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $cipher ) {
			return $plain;
		}

		return self::PREFIX . base64_encode( $iv . $tag . $cipher );
	}

	/**
	 * @param string $stored Stored value, encrypted or legacy cleartext.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		if ( ! is_string( $stored ) || ! self::is_encrypted( $stored ) ) {
			return is_string( $stored ) ? $stored : '';
		}

		if ( ! self::available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );

		foreach ( array_merge( array( self::key() ), self::legacy_keys() ) as $key ) {
			$plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $plain ) {
				return $plain;
			}
		}

		return '';
	}

	/**
	 * Read an SMTP secret, honouring a wp-config override for the password.
	 *
	 * @param array  $values SMTP values array.
	 * @param string $key    Secret key.
	 * @return string
	 */
	public static function smtp( $values, $key ) {
		if ( 'password' === $key && defined( 'NEXTER_SMTP_PASSWORD' ) && '' !== NEXTER_SMTP_PASSWORD ) {
			return (string) NEXTER_SMTP_PASSWORD;
		}

		$values = is_object( $values ) ? (array) $values : $values;

		return is_array( $values ) && isset( $values[ $key ] ) ? self::decrypt( $values[ $key ] ) : '';
	}

	/**
	 * @param string $value Candidate value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * @return bool
	 */
	private static function available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Key material. NEXTER_SECRET_KEY lets an operator keep decryption working across a salt
	 * rotation; otherwise the site's own auth salt is used.
	 *
	 * @return string
	 */
	private static function key() {
		return self::derive( self::seed() );
	}

	/**
	 * Key material, in order of preference. Deliberately not derived from wp-config salts: those
	 * differ between staging and live, which would break a restored backup.
	 *
	 * @return string
	 */
	private static function seed() {
		if ( defined( 'NEXTER_SECRET_KEY' ) && '' !== NEXTER_SECRET_KEY ) {
			return (string) NEXTER_SECRET_KEY;
		}

		$stored = get_option( self::KEY_OPTION );
		if ( is_string( $stored ) && 64 === strlen( $stored ) ) {
			return $stored;
		}

		$generated = bin2hex( self::random( 32 ) );
		add_option( self::KEY_OPTION, $generated, '', false );

		// add_option() is a no-op if another request created it first; re-read so both agree.
		$stored = get_option( self::KEY_OPTION );

		return ( is_string( $stored ) && 64 === strlen( $stored ) ) ? $stored : $generated;
	}

	/**
	 * Keys that older builds may have used, tried only if the current key fails.
	 *
	 * @return array
	 */
	private static function legacy_keys() {
		return array( self::derive( wp_salt( 'auth' ) ) );
	}

	/**
	 * @param string $seed Key material.
	 * @return string
	 */
	private static function derive( $seed ) {
		return hash( 'sha256', 'nexter-smtp|' . $seed, true );
	}

	/**
	 * @param int $bytes Length.
	 * @return string
	 */
	private static function random( $bytes ) {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return random_bytes( $bytes );
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			}
		}

		return openssl_random_pseudo_bytes( $bytes );
	}
}

Nxt_Secret::init();
