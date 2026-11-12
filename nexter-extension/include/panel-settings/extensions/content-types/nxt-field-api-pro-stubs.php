<?php
/**
 * Placeholder repeater template functions for when Nexter Pro (which defines the real ones) is inactive.
 *
 * @package Nexter_Extension
 * @subpackage Content_Types
 * @since 4.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nxt_repeater_rows' ) ) {

	/**
	 * Repeater rows (Nexter Pro).
	 *
	 * @param string $selector  Repeater field name or key.
	 * @param mixed  $object_id Object ID.
	 * @param bool   $format    Whether to format subfields.
	 * @return array Always empty without Pro.
	 */
	function nxt_repeater_rows( $selector, $object_id = false, $format = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature matches Pro.
		return array();
	}

	/**
	 * Whether a repeater loop has another row (Nexter Pro).
	 *
	 * @param string $selector  Repeater field name or key.
	 * @param mixed  $object_id Object ID.
	 * @return bool Always false without Pro.
	 */
	function nxt_repeater_has_next( $selector, $object_id = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature matches Pro.
		return false;
	}

	/**
	 * Advance the repeater loop (Nexter Pro).
	 *
	 * @param bool $format Unused.
	 * @return bool Always false without Pro.
	 */
	function nxt_repeater_advance( $format = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature matches Pro.
		return false;
	}

	/**
	 * Current repeater row (Nexter Pro).
	 *
	 * @param bool $format Whether to format subfields.
	 * @return null Always null without Pro.
	 */
	function nxt_repeater_row( $format = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature matches Pro.
		return null;
	}

	/**
	 * Current repeater row index (Nexter Pro).
	 *
	 * @return int Always 0 without Pro.
	 */
	function nxt_repeater_index() {
		return 0;
	}

	/**
	 * Reset the repeater loop (Nexter Pro).
	 *
	 * @param bool $hard Clear every frame.
	 * @return void
	 */
	function nxt_repeater_rewind( $hard = false ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature matches Pro.

	/**
	 * Sub value of the current repeater row (Nexter Pro).
	 *
	 * @param string $selector Subfield name or key.
	 * @param bool   $format   Whether to format.
	 * @return null Always null without Pro.
	 */
	function nxt_repeater_value( $selector, $format = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature matches Pro.
		return null;
	}

	/**
	 * Type of a sub field in the current repeater row (Nexter Pro).
	 *
	 * @param string $selector Subfield name or key.
	 * @return string Always '' without Pro.
	 */
	function nxt_repeater_value_type( $selector ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature matches Pro.
		return '';
	}

	/**
	 * Echo a sub value of the current repeater row (Nexter Pro).
	 *
	 * @param string $selector Subfield name or key.
	 * @return void Prints nothing without Pro.
	 */
	function nxt_print_repeater_value( $selector ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature matches Pro.
}
