<?php
/**
 * Content Fields Tag Shared
 *
 * Part of the Content Fields -> Elementor dynamic tags. Required from
 * class-nexter-fields-dynamic-tags.php inside Elementor's register hook, so Elementor's base
 * classes exist by the time this is parsed.
 *
 * @package Nexter
 * @since   4.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! trait_exists( 'Nexter_Fields_Tag_Shared' ) ) {

	/**
	 * Everything the tags have in common: the field picker, and turning a picked field into a value.
	 */
	trait Nexter_Fields_Tag_Shared {

		/**
		 * Tag group.
		 *
		 * @return string
		 */
		public function get_group() {
			return Nexter_Fields_Dynamic_Tags::GROUP;
		}

		/**
		 * The field picker, grouped by field group so a long list stays navigable.
		 *
		 * @return void
		 */
		protected function register_controls() {
			$this->add_control(
				'nxt_field',
				array(
					'label'   => __( 'Field', 'nexter-extension' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'groups'  => self::field_groups_for( $this->nexter_supported_types() ),
					'default' => '',
				)
			);
		}

		/**
		 * Optgroups of fields whose type can supply this tag's category.
		 *
		 * Keyed by field KEY, not name: a name is only unique within its group, and the key is what
		 * survives a label change. nxt_field_value() accepts either.
		 *
		 * @param string[] $types Nexter field types this tag can render.
		 * @return array
		 */
		private static function field_groups_for( $types ) {
			$out  = array();
			$rows = Nxt_Content_Types_Definitions_Repository::get_instance()->by_kind();
			$rows = isset( $rows['group_fields'] ) ? (array) $rows['group_fields'] : array();

			foreach ( $rows as $row ) {
				$meta = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array();
				// Absent means active — see the note in CLAUDE.md. Inactive groups are listed rather
				// than hidden: a field missing from this list looks like a bug, while an inactive
				// group is a state the editor can see and fix. The suffix says why it renders empty.
				$is_active = ! isset( $meta['active'] ) || $meta['active'];
				$options   = array();
				foreach ( (array) ( isset( $meta['fields'] ) ? $meta['fields'] : array() ) as $field ) {
					$type = isset( $field['type'] ) ? $field['type'] : '';
					if ( ! in_array( $type, $types, true ) ) {
						continue;
					}
					$fkey  = ! empty( $field['key'] ) ? (string) $field['key'] : '';
					$fname = isset( $field['name'] ) ? (string) $field['name'] : '';
					if ( '' === $fkey && '' === $fname ) {
						continue;
					}
					// key:name, the shape Elementor Pro's ACF integration stores. Two halves because
					// a re-import can regenerate field keys: the key resolves first, and the name is
					// the fallback that keeps an existing widget working when it changes.
					$selector                      = ( '' !== $fkey && '' !== $fname ) ? $fkey . ':' . $fname : $fkey . $fname;
					$label                         = isset( $field['label'] ) && '' !== $field['label'] ? $field['label'] : $selector;
					$options[ (string) $selector ] = $label;
				}
				if ( $options ) {
					// A select holding exactly one option never fires a change event, so the value
					// never commits and the only field in the group cannot be picked. A leading
					// placeholder gives the control something to change FROM. nexter_value()
					// treats the placeholder as nothing selected.
					if ( 1 === count( $options ) ) {
						$options = array( Nexter_Fields_Dynamic_Tags::NO_FIELD => __( 'Select a field…', 'nexter-extension' ) ) + $options;
					}
					// The group title alone, the way Elementor Pro's ACF integration labels its
					// optgroups. The enclosing tag group is already named "Content Fields", so a
					// per-optgroup prefix only repeated it.
					$title = isset( $row['title'] ) && '' !== $row['title'] ? $row['title'] : ( isset( $meta['key'] ) ? $meta['key'] : '' );
					if ( ! $is_active ) {
						$title = sprintf(
							/* translators: %s: field group name. */
							__( '%s (inactive)', 'nexter-extension' ),
							$title
						);
					}
					$out[] = array(
						'label'   => $title,
						'options' => $options,
					);
				}
			}

			return $out;
		}

		/**
		 * The picked field's value for the object Elementor is currently rendering.
		 *
		 * The object id is left false on purpose: the API resolves the current object, and the
		 * builder documents class already switches the query around dynamic-tag rendering, so
		 * passing a guessed id here would fight that.
		 *
		 * @param bool $formatted Whether to run the field's own formatting.
		 * @return mixed
		 */
		protected function nexter_value( $formatted = true ) {
			$selector = $this->get_settings( 'nxt_field' );
			if ( empty( $selector ) || Nexter_Fields_Dynamic_Tags::NO_FIELD === (string) $selector || ! function_exists( 'nxt_field_value' ) ) {
				return null;
			}

			// Stored as key:name (and, from before that format, as a bare key). The key is tried
			// first because it is unique; the name covers a key regenerated by a re-import.
			$parts = explode( ':', (string) $selector, 2 );
			foreach ( $parts as $candidate ) {
				if ( '' === $candidate ) {
					continue;
				}
				$value = nxt_field_value( $candidate, false, (bool) $formatted );
				if ( null !== $value && '' !== $value ) {
					return $value;
				}
			}
			return null;
		}
	}
}
