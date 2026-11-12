<?php
/**
 * Location rules evaluator for Field Groups.
 * Matches field groups to current screen (post type, taxonomy, etc.).
 *
 * @package Nexter_Extension
 * @subpackage Content_Types
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nxt_Content_Types_Field_Groups_Location
 */
class Nxt_Content_Types_Field_Groups_Location {

	/**
	 * Check if a field group's location rules match the current screen.
	 *
	 * @param array $location_rules Array of rule groups. Each group is array of rules.
	 *                              Rules match with AND within group; groups match with OR.
	 * @param array $context       Current context: post_type, post_id, taxonomy, term_id, user_id, screen.
	 * @return bool True if any rule group matches.
	 */
	public static function match( $location_rules, $context = array() ) {
		if ( empty( $location_rules ) || ! is_array( $location_rules ) ) {
			return false;
		}

		$first       = isset( $location_rules[0] ) ? $location_rules[0] : null;
		$is_nested   = is_array( $first ) && ! isset( $first['param'] ) && ! isset( $first['operator'] );
		$rule_groups = $is_nested ? $location_rules : array( $location_rules );

		foreach ( $rule_groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$group_matches = true;
			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				if ( ! self::rule_matches( $rule, $context ) ) {
					$group_matches = false;
					break;
				}
			}
			if ( $group_matches ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a single rule matches the context.
	 *
	 * @param array $rule    Rule: param, operator, value.
	 * @param array $context Context array.
	 * @return bool
	 */
	private static function rule_matches( $rule, $context ) {

		$param    = isset( $rule['param'] ) ? sanitize_key( $rule['param'] ) : '';
		$operator = self::sanitize_rule_operator( isset( $rule['operator'] ) ? $rule['operator'] : '' );
		$value    = isset( $rule['value'] ) ? $rule['value'] : '';

		$actual = self::get_param_value( $param, $context );

		// "All" means match any (for several params) — but only where the param exists at all.
		if ( (string) 'all' === $value || (string) '' === $value ) {
			if ( in_array( $param, array( 'taxonomy', 'post_taxonomy', 'comment', 'attachment', 'nav_menu', 'nav_menu_item', 'widget', 'user_form' ), true ) ) {
				$has_context = is_array( $actual ) ? ! empty( $actual ) : ( '' !== (string) $actual );
				if ( '==' === $operator || '=' === $operator ) {
					return $has_context;
				}
				if ( '!=' === $operator ) {
					return ! $has_context;
				}
			}
		}
		// Array actual (e.g. post_category terms): "equal" means value is in list.
		if ( is_array( $actual ) ) {
			if ( in_array( '==', array( $operator ), true ) || in_array( '=', array( $operator ), true ) ) {
				return in_array( (string) $value, array_map( 'strval', $actual ), true );
			}
			if ( '!=' === $operator ) {
				return ! in_array( (string) $value, array_map( 'strval', $actual ), true );
			}
			return false;
		}

		// Special handling for user_form "add_edit" convenience value.
		if ( 'user_form' === $param && 'add_edit' === (string) $value ) {
			if ( '==' === $operator || '=' === $operator ) {
				return in_array( (string) $actual, array( 'add', 'edit' ), true );
			}
			if ( '!=' === $operator ) {
				return ! in_array( (string) $actual, array( 'add', 'edit' ), true );
			}
		}

		switch ( $operator ) {
			case '==':
				return self::rule_values_equal( $actual, $value );
			case '!=':
				return ! self::rule_values_equal( $actual, $value );
			case '>':
				return $actual > $value;
			case '<':
				return $actual < $value;
			case 'contains':
				return is_string( $actual ) && is_string( $value ) && strpos( $actual, $value ) !== false;
			case 'empty':
				return empty( $actual );
			case '!empty':
				return ! empty( $actual );
			default:
				return self::rule_values_equal( $actual, $value );
		}
	}

	/**
	 * Type-safe equality for rule matching.
	 *
	 * @param mixed $actual Actual value.
	 * @param mixed $value  Rule value.
	 * @return bool
	 */
	private static function rule_values_equal( $actual, $value ) {
		if ( is_scalar( $actual ) && is_scalar( $value ) ) {
			return (string) $actual === (string) $value;
		}
		return $actual === $value;
	}

	/**
	 * The operators a rule may carry. Both matchers share this list.
	 *
	 * @return string[]
	 */
	private static function rule_operators() {
		return array( '==', '=', '!=', '>', '<', 'contains', 'empty', '!empty', 'all_set', 'range' );
	}

	/**
	 * Reduce a stored operator to one of the known operators.
	 *
	 * @param mixed $operator Raw stored operator.
	 * @return string One of rule_operators(), or '==' when unrecognised.
	 */
	private static function sanitize_rule_operator( $operator ) {
		if ( ! is_string( $operator ) || '' === $operator ) {
			return '==';
		}
		if ( in_array( $operator, self::rule_operators(), true ) ) {
			return $operator;
		}
		// Tolerate a value that has already been entity-encoded on its way through storage or an
		// older save, so a rule written by the previous code keeps working.
		$decoded = html_entity_decode( $operator, ENT_QUOTES, 'UTF-8' );

		return in_array( $decoded, self::rule_operators(), true ) ? $decoded : '==';
	}

	/**
	 * Numeric comparison for a field-conditional '>' or '<' rule.
	 *
	 * @param mixed  $actual   Stored field value.
	 * @param mixed  $value    Rule value.
	 * @param string $operator '>' or '<'.
	 * @return bool
	 */
	private static function rule_numeric_compare( $actual, $value, $operator ) {
		if ( is_array( $actual ) || is_array( $value ) ) {
			return false;
		}
		if ( ! is_numeric( $actual ) || ! is_numeric( $value ) ) {
			return false;
		}
		$a = (float) $actual;
		$b = (float) $value;

		return ( '>' === $operator ) ? $a > $b : $a < $b;
	}

	/**
	 * Evaluate a field-conditional 'contains' rule.
	 *
	 * @param mixed $actual Stored field value.
	 * @param mixed $value  Needle.
	 * @return bool
	 */
	private static function rule_contains( $actual, $value ) {
		if ( is_array( $value ) || null === $value ) {
			return false;
		}
		$needle = (string) $value;

		// An empty needle would otherwise match everything: strpos( 'abc', '' ) is 0, which is not
		// false. A rule with no value is an unfinished rule, not a rule that always passes.
		if ( '' === $needle ) {
			return false;
		}

		if ( is_array( $actual ) ) {
			foreach ( $actual as $entry ) {
				if ( is_scalar( $entry ) && false !== strpos( (string) $entry, $needle ) ) {
					return true;
				}
			}
			return false;
		}

		return is_scalar( $actual ) && false !== strpos( (string) $actual, $needle );
	}

	/**
	 * Evaluate a field-conditional 'all_set' rule.
	 *
	 * @param mixed $actual Stored field value.
	 * @return bool
	 */
	private static function rule_all_set( $actual ) {
		if ( is_array( $actual ) ) {
			if ( ! $actual ) {
				return false;
			}
			foreach ( $actual as $entry ) {
				if ( is_array( $entry ) ? ! $entry : ( '' === $entry || null === $entry ) ) {
					return false;
				}
			}
			return true;
		}

		return '' !== $actual && null !== $actual;
	}

	/**
	 * Evaluate a field-conditional 'range' rule against a "min,max" rule value.
	 *
	 * @param mixed $actual Stored field value.
	 * @param mixed $value  Rule value as "min,max".
	 * @return bool
	 */
	private static function rule_in_range( $actual, $value ) {
		if ( is_array( $actual ) || ! is_numeric( $actual ) ) {
			return false;
		}
		$parts = explode( ',', (string) $value );
		$min   = isset( $parts[0] ) ? trim( $parts[0] ) : '';
		$max   = isset( $parts[1] ) ? trim( $parts[1] ) : '';

		// Neither bound given is not a range at all; treating it as "always true" would silently
		// show the field for every value.
		if ( '' === $min && '' === $max ) {
			return false;
		}
		$a = (float) $actual;
		if ( '' !== $min && is_numeric( $min ) && $a < (float) $min ) {
			return false;
		}
		if ( '' !== $max && is_numeric( $max ) && $a > (float) $max ) {
			return false;
		}

		return true;
	}

	/**
	 * Evaluate per-field conditional logic rules against current field values.
	 * Rules structure mirrors location_rules but uses 'field', 'operator', 'value'.
	 *
	 * @param array $conditional_logic Array of groups of rules.
	 * @param array $values            Map of field_name => current value.
	 * @return bool True if conditions pass or no conditions defined.
	 */
	public static function match_field_conditional( $conditional_logic, $values ) {
		if ( empty( $conditional_logic ) || ! is_array( $conditional_logic ) ) {
			return true;
		}

		$groups = $conditional_logic;
		if ( isset( $conditional_logic['field'] ) || isset( $conditional_logic[0]['field'] ) ) {
			$groups = array( (array) $conditional_logic );
		}

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$group_matches = true;
			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$field    = isset( $rule['field'] ) ? sanitize_key( $rule['field'] ) : '';
				$operator = self::sanitize_rule_operator( isset( $rule['operator'] ) ? $rule['operator'] : '' );
				$value    = isset( $rule['value'] ) ? $rule['value'] : '';
				$actual   = isset( $values[ $field ] ) ? $values[ $field ] : '';

				switch ( $operator ) {
					case '==':
						$ok = self::rule_values_equal( $actual, $value );
						break;
					case '!=':
						$ok = ! self::rule_values_equal( $actual, $value );
						break;
					case 'contains':
						$ok = self::rule_contains( $actual, $value );
						break;
					case 'empty':
						$ok = empty( $actual );
						break;
					case '!empty':
						$ok = ! empty( $actual );
						break;
					case '>':
						$ok = self::rule_numeric_compare( $actual, $value, '>' );
						break;
					case '<':
						$ok = self::rule_numeric_compare( $actual, $value, '<' );
						break;
					case 'all_set':
						$ok = self::rule_all_set( $actual );
						break;
					case 'range':
						$ok = self::rule_in_range( $actual, $value );
						break;
					default:
						$ok = self::rule_values_equal( $actual, $value );
						break;
				}

				if ( ! $ok ) {
					$group_matches = false;
					break;
				}
			}

			if ( $group_matches ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the current value for a location param.
	 *
	 * @param string $param   Param key (e.g. post_type, taxonomy).
	 * @param array  $context Context array.
	 * @return mixed
	 */
	private static function get_param_value( $param, $context ) {
		$post_type = isset( $context['post_type'] ) ? $context['post_type'] : '';
		$post_id   = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
		$post      = isset( $context['post'] ) ? $context['post'] : null;
		$screen    = isset( $context['screen'] ) ? $context['screen'] : '';

		if ( empty( $post_type ) && function_exists( 'get_current_screen' ) ) {
			$current = get_current_screen();
			if ( $current && ! empty( $current->post_type ) ) {
				$post_type = $current->post_type;
			}
		}

		switch ( $param ) {
			case 'post_type':
				// Term / user / comment / option screens carry no post object, so defaulting to 'post' leaked post_type rules there.
				if ( isset( $context['screen'] ) && in_array( $context['screen'], array( 'term', 'user', 'comment', 'option' ), true ) ) {
					return '';
				}
				return $post_type ? $post_type : 'post';

			case 'post':
				// Match a specific post ID regardless of post type.
				return $post_id ? (string) $post_id : '';

			case 'page':
				return ( 'page' === $post_type && $post_id ) ? (string) $post_id : '';

			case 'post_template':
			case 'page_template':
				return isset( $context['page_template'] ) ? $context['page_template'] : ( isset( $context['post_template'] ) ? $context['post_template'] : 'default' );

			case 'post_status':
				return isset( $context['post_status'] ) ? $context['post_status'] : '';

			case 'post_format':
				return isset( $context['post_format'] ) ? $context['post_format'] : 'standard';

			case 'post_category':
				if ( ! $post_id || ! $post_type ) {
					return array();
				}
				$terms = get_the_terms( $post_id, 'category' );
				if ( ! $terms || is_wp_error( $terms ) ) {
					return array();
				}
				return array_values(
					array_map(
						function ( $t ) {
							return (string) $t->term_id;
						},
						$terms
					)
				);

			case 'taxonomy':
				// Term add/edit screens only.
				if ( isset( $context['screen'] ) && 'term' === $context['screen'] ) {
					return isset( $context['taxonomy'] ) ? $context['taxonomy'] : '';
				}
				return '';

			case 'post_taxonomy':
				// Match specific taxonomy terms assigned to the post.
				if ( ! $post_id || ! $post_type ) {
					return array();
				}
				$taxonomies = get_object_taxonomies( $post_type, 'names' );
				$have       = array();
				foreach ( $taxonomies as $tax ) {
					$terms = get_the_terms( $post_id, $tax );
					if ( $terms && ! is_wp_error( $terms ) ) {
						foreach ( $terms as $t ) {
							$have[] = $tax . ':' . (string) $t->term_id;
						}
					}
				}
				return $have;

			case 'page_type':
				return isset( $context['page_type'] ) ? $context['page_type'] : '';

			case 'page_parent':
				return isset( $context['page_parent'] ) ? $context['page_parent'] : '';

			case 'user_role':
				// The role of the user object being edited (user forms).
				return isset( $context['user_role'] ) ? $context['user_role'] : '';

			case 'current_user_role':
				// The role of the currently logged-in viewer — independent of the object/screen,
				// so it resolves on post/term/etc. screens (previously always '' there).
				if ( isset( $context['current_user_role'] ) && '' !== $context['current_user_role'] ) {
					return $context['current_user_role'];
				}
				$current = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
				return ( $current && ! empty( $current->roles ) ) ? (string) $current->roles[0] : '';

			case 'current_user':
				return isset( $context['current_user'] ) ? $context['current_user'] : '';

			case 'user_form':
				return isset( $context['user_form'] ) ? $context['user_form'] : '';

			case 'attachment':
				if ( 'attachment' !== $post_type || ! $post_id ) {
					return '';
				}
				$mime = get_post_mime_type( $post_id );
				if ( $mime && preg_match( '#^image/#', $mime ) ) {
					return $mime;
				}
				return $mime ? $mime : '';

			case 'comment':
				// Comment edit screens only; the rule value filters by the commented post's type (All, Post, Page, …).
				if ( ! isset( $context['screen'] ) || 'comment' !== $context['screen'] ) {
					return '';
				}
				$comment_id = isset( $context['comment_id'] ) ? (int) $context['comment_id'] : 0;
				if ( $comment_id ) {
					$comment = get_comment( $comment_id );
					$cpost   = $comment ? get_post( (int) $comment->comment_post_ID ) : null;
					if ( $cpost ) {
						return $cpost->post_type;
					}
				}
				return $post_type ? $post_type : '';

			case 'widget':
			case 'nav_menu':
			case 'nav_menu_item':
				// No widget/menu context on post edit screen.
				return '';

			default:
				return isset( $context[ $param ] ) ? $context[ $param ] : '';
		}
	}

	/**
	 * Get field groups that match the current admin screen.
	 *
	 * @param array $context Optional context override.
	 * @return array Array of field group configs (id, title, fields, etc.).
	 */
	public static function get_matching_groups( $context = array() ) {
		$groups = Nxt_Content_Types_Definitions_Repository::get_instance()->by_kind();

		$matched = array();
		foreach ( $groups['group_fields'] as $row ) {
			if ( 'publish' !== $row['status'] ) {
				continue;
			}
			$meta = $row['meta'];
			if ( isset( $meta['active'] ) && ! $meta['active'] ) {
				continue;
			}
			$rules = isset( $meta['location_rules'] ) ? $meta['location_rules'] : array();
			if ( self::match( $rules, $context ) ) {
				$matched[] = array(
					'id'                    => $row['id'],
					'title'                 => ! empty( $meta['title'] ) ? $meta['title'] : $row['title'],
					'key'                   => ! empty( $meta['key'] ) ? $meta['key'] : 'group_' . $row['id'],
					'fields'                => isset( $meta['fields'] ) ? $meta['fields'] : array(),
					'position'              => isset( $meta['position'] ) ? $meta['position'] : 'normal',
					'style'                 => isset( $meta['style'] ) ? $meta['style'] : 'default',
					'label_placement'       => isset( $meta['label_placement'] ) ? $meta['label_placement'] : 'top',
					'instruction_placement' => isset( $meta['instruction_placement'] ) ? $meta['instruction_placement'] : 'label',
					'order_no'              => isset( $meta['order_no'] ) ? (int) $meta['order_no'] : 0,
					// Carried so nxt_register_group_field_meta() can honour the group's REST opt-out and resolve which
					// object types to register its value meta for.
					'show_in_rest'          => ! array_key_exists( 'show_in_rest', $meta ) || (bool) $meta['show_in_rest'],
					'location_rules'        => $rules,
				);
			}
		}

		if ( ! empty( $matched ) ) {
			usort(
				$matched,
				function ( $a, $b ) {
					// order_no is always set by the array built above, so no fallback is reachable here.
					$ao = (int) $a['order_no'];
					$bo = (int) $b['order_no'];
					if ( $ao === $bo ) {
						return strcasecmp( (string) $a['title'], (string) $b['title'] );
					}
					return ( $ao < $bo ) ? -1 : 1;
				}
			);
		}

		return $matched;
	}

	/**
	 * Rule params grouped by the object type whose edit screen they can appear on.
	 *
	 * @return array<string, string[]>
	 */
	private static function params_by_object_type() {
		return array(
			// 'attachment' is here because an attachment is a post type.
			'post'    => array(
				'post_type',
				'post',
				'page',
				'post_template',
				'page_template',
				'post_status',
				'post_format',
				'post_category',
				'post_taxonomy',
				'page_type',
				'page_parent',
				'attachment',
			),
			'term'    => array( 'taxonomy' ),
			'user'    => array( 'user_role', 'user_form' ),
			'comment' => array( 'comment' ),
		);
	}

	/**
	 * Whether ANY active group could render on the edit screen of $object_type.
	 *
	 * @param string $object_type post|term|user|comment.
	 * @return bool
	 */
	public static function targets_object_type( $object_type ) {
		$map = self::params_by_object_type();
		if ( ! isset( $map[ $object_type ] ) ) {
			return true;
		}
		$mine       = $map[ $object_type ];
		$everywhere = array( 'current_user', 'current_user_role' );
		$known      = array_merge( $everywhere, ...array_values( $map ) );

		$groups = Nxt_Content_Types_Definitions_Repository::get_instance()->by_kind();
		foreach ( $groups['group_fields'] as $row ) {
			if ( 'publish' !== $row['status'] ) {
				continue;
			}
			$meta = $row['meta'];
			if ( isset( $meta['active'] ) && ! $meta['active'] ) {
				continue;
			}
			$rules = isset( $meta['location_rules'] ) ? $meta['location_rules'] : array();
			if ( empty( $rules ) || ! is_array( $rules ) ) {
				// match() returns false for empty rules, so such a group never renders anywhere.
				continue;
			}

			$first       = isset( $rules[0] ) ? $rules[0] : null;
			$is_nested   = is_array( $first ) && ! isset( $first['param'] ) && ! isset( $first['operator'] );
			$rule_groups = $is_nested ? $rules : array( $rules );

			foreach ( $rule_groups as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				foreach ( $group as $rule ) {
					if ( ! is_array( $rule ) || empty( $rule['param'] ) ) {
						continue;
					}
					$param = sanitize_key( $rule['param'] );
					if ( in_array( $param, $everywhere, true ) ) {
						return true;
					}
					if ( in_array( $param, $mine, true ) ) {
						return true;
					}
					if ( ! in_array( $param, $known, true ) ) {
						// An unrecognised param (a newer rule type, or one added by a filter) must
						// not silently suppress assets.
						return true;
					}
				}
			}
		}

		return false;
	}
}
