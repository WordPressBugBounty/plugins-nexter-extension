<?php
/**
 * Nexter Blocks (Pro) Dynamic Fields compatibility for NE Fields.
 *
 * Exposes Content Fields group fields as Dynamic Field options inside Nexter Blocks, and resolves
 * the selected option back to a value.
 *
 * WHY THIS LIVES HERE AND NOT IN NEXTER BLOCKS PRO
 * Nexter Blocks already publishes the two hooks this needs — `tpgb_add_dynamic_option` to append
 * options and `tpgb_get_dynamic_value` to resolve one. Everything specific to NE (its meta-key
 * layout, its formatting, its conditional logic) is knowledge this plugin owns, so keeping the
 * integration on this side means Nexter Blocks needs no change and neither plugin has to track the
 * other's internals.
 *
 * WHY THE OPTION VALUE IS PREFIXED
 * Nexter Blocks' built-in resolver reads a bare meta key: `get_post_meta( $id, $selected )`. That
 * works for ACF because ACF stores on the bare field name. NE stores at
 * `_nxt_gf_{group}_{field}`, so registering bare names would silently yield nothing at all.
 *
 * Registering the FULL meta key would appear to work and is the trap this avoids: it returns the
 * raw stored value, so an image yields an attachment ID instead of a URL, a link yields an array,
 * a date yields the stored form rather than the display form, and a repeater yields a serialized
 * blob. Prefixing with `nefields|` guarantees the built-in lookup finds nothing, which hands the
 * value to this class, which resolves it through nxt_field_value() and gets NE's formatting,
 * relational resolution and return_format handling for free.
 *
 * That prefix approach mirrors what Nexter Blocks already does for Pods (`pod:id:field`) and for
 * ACF/JetEngine repeaters (`acf|`, `jetengine|`).
 *
 * CONDITIONAL LOGIC IS DELIBERATELY HONOURED
 * nxt_field_value() returns null for a field whose conditional logic is unmet, even when a value is
 * stored. That is preserved rather than bypassed: reading raw meta instead would publish values the
 * editor deliberately hid. No other Dynamic Fields source has this concept.
 *
 * @package Nexter_Extension
 * @since 4.8.1
 */

defined( 'ABSPATH' ) || exit;

// Both halves must be present: Nexter Blocks Pro provides the hooks, and the Content Fields module
// provides the data. The Fields module does not ship in every build, so this cannot be assumed.
if ( ! class_exists( 'Nxt_Content_Types_Definitions_Repository' ) || ! function_exists( 'nxt_field_value' ) ) {
	return;
}

if ( ! class_exists( 'Nexter_Blocks_Dynamic_Fields' ) ) {

	/**
	 * Class Nexter_Blocks_Dynamic_Fields
	 */
	class Nexter_Blocks_Dynamic_Fields {

		/** Marks an option as belonging to this integration. */
		const PREFIX = 'nefields|';

		/**
		 * Instance.
		 *
		 * @var Nexter_Blocks_Dynamic_Fields|null
		 */
		private static $instance = null;

		/**
		 * Initiator.
		 *
		 * @return Nexter_Blocks_Dynamic_Fields
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_filter( 'tpgb_add_dynamic_option', array( $this, 'add_options' ) );
			// Priority 20: the built-in resolution runs first, so a genuine ACF/Pods value is never
			// overwritten by this filter on a key that is not ours.
			add_filter( 'tpgb_get_dynamic_value', array( $this, 'resolve' ), 20, 2 );

			// --- Repeaters. Three entry points, because Nexter Blocks has three.
			//
			// 1. The editor asks over REST. That route's callback is registered directly, with no
			// filter of its own, so it is intercepted at the REST layer instead. This is the
			// path that populates the sub-field dropdown, and it is the one that matters most.
			add_filter( 'rest_pre_dispatch', array( $this, 'rest_intercept' ), 10, 3 );

			// 2. A pre-filter we have asked the Nexter Blocks team to add (REQ-NB-1). Hooked now so
			// that the moment it exists this becomes the clean path and the shim below stops
			// being reached.
			add_filter( 'tpgb_repeater_data_pre', array( $this, 'repeater_pre' ), 10, 2 );

			// 3. Until then, a shim for the server-side render path. Nexter Blocks' own handler
			// only knows `acf|` and `jetengine|` and returns WP_Error('invalid_prefix') for
			// anything else, and the filter hands on its RETURN value rather than the original
			// request — so the request is remembered before it runs and used after it refuses.
			// Deliberately narrow: it acts only on that one error code, for keys that are ours.
			add_filter( 'tp_get_repeater_data', array( $this, 'remember_repeater_request' ), 9 );
			add_filter( 'tp_get_repeater_data', array( $this, 'repeater_fallback' ), 11 );
		}

		/**
		 * NE field type => the Dynamic Field bucket that can render it.
		 *
		 * Buckets are Nexter Blocks' own: `text`, `url`, `image`, `color`. A type absent from this
		 * map is not offered at all, which is the correct outcome for anything whose value has no
		 * scalar rendering in a block control.
		 *
		 * `password` is deliberately absent. The type was removed from NE under VULN-001 because it
		 * stored cleartext, and legacy definitions still carry it on some sites; surfacing those as
		 * dynamic content would print the secret into a page.
		 *
		 * @return array<string, string>
		 */
		public static function bucket_map() {
			return array(
				'text'       => 'text',
				'textarea'   => 'text',
				'wysiwyg'    => 'text',
				'number'     => 'text',
				'range'      => 'text',
				'email'      => 'text',
				'select'     => 'text',
				'radio'      => 'text',
				'checkbox'   => 'text',
				'true_false' => 'text',
				'date'       => 'text',
				'time'       => 'text',
				'datetime'   => 'text',
				'url'        => 'url',
				'page_link'  => 'url',
				'link'       => 'url',
				'image'      => 'image',
				'file'       => 'image',
				'color'      => 'color',
			);
		}

		/**
		 * Append NE Fields groups to the Dynamic Field option list.
		 *
		 * @param array $option_list Nexter Blocks option list, bucketed.
		 * @return array
		 */
		public function add_options( $option_list ) {
			if ( ! is_array( $option_list ) ) {
				return $option_list;
			}

			$map = self::bucket_map();

			foreach ( $this->groups() as $group ) {
				$key   = isset( $group['key'] ) ? (string) $group['key'] : '';
				$title = isset( $group['title'] ) && '' !== $group['title'] ? (string) $group['title'] : $key;
				if ( '' === $key ) {
					continue;
				}

				$buckets = array();
				foreach ( (array) ( isset( $group['fields'] ) ? $group['fields'] : array() ) as $field ) {
					$name = isset( $field['name'] ) ? (string) $field['name'] : '';
					$type = isset( $field['type'] ) ? (string) $field['type'] : '';
					// `repeater` is intentionally not in bucket_map() — it has no value bucket — so
					// it has to pass this guard explicitly.
					if ( '' === $name || ( ! isset( $map[ $type ] ) && 'repeater' !== $type ) ) {
						continue;
					}
					$label = isset( $field['label'] ) && '' !== $field['label'] ? (string) $field['label'] : $name;

					// A repeater has no scalar rendering of its own; it goes in the repeater bucket
					// and its rows are fetched separately. Registered here rather than through
					// bucket_map() because it is the one type whose bucket is not a value bucket.
					if ( 'repeater' === $type ) {
						$buckets['repeater'][] = array( self::PREFIX . $key . '|' . $name, $label );
						continue;
					}

					$buckets[ $map[ $type ] ][] = array(
						self::PREFIX . $key . '|' . $name,
						$label,
					);

					// A url-ish field is also useful as text (to print the address), and an image
					// field as text (to print the ID). Offering both costs nothing and saves the
					// editor from having to pick a differently-typed control.
					if ( 'text' !== $map[ $type ] ) {
						$buckets['text'][] = array( self::PREFIX . $key . '|' . $name, $label );
					}
				}

				// A bucket only exists because a field was appended to it, so there is no empty case.
				foreach ( $buckets as $bucket => $options ) {
					$option_list[ $bucket ][] = array(
						array(
							/* translators: %s: field group title, e.g. "Booking Details". */
							'label'   => sprintf( esc_html__( 'Content Fields (%s)', 'nexter-extension' ), $title ),
							'options' => $options,
						),
					);
				}
			}

			return $option_list;
		}

		/**
		 * Resolve a selected option back to a value.
		 *
		 * @param mixed  $output   Value Nexter Blocks resolved already (empty for our keys).
		 * @param string $selected The selected option value.
		 * @return mixed
		 */
		public function resolve( $output, $selected ) {
			$parsed = self::parse( $selected );
			if ( ! $parsed ) {
				// Not ours — hand back untouched. Returning anything else here would clobber the
				// value another source legitimately resolved.
				return $output;
			}

			$value = nxt_field_value( $parsed['field'], $this->context() );
			if ( null === $value || '' === $value ) {
				return $output;
			}

			$definition = $this->find_field( $parsed['group'], $parsed['field'] );
			$type       = $definition && isset( $definition['type'] ) ? (string) $definition['type'] : '';

			return $this->shape( $value, $type );
		}

		/**
		 * Turn a media value into the { id, url } array a block's image control can render.
		 *
		 * Necessary because NE's image and file fields resolve to an attachment ID under their
		 * default return format, while Nexter Blocks reads `$dynamicVal['url']` and — when it is
		 * handed a scalar instead — uses that scalar as the URL directly. The image would then be
		 * requested as `src="2962"`. Returning the array closes that gap, and the id is kept
		 * alongside so a block that wants to re-derive a size still can.
		 *
		 * @param mixed $value Formatted NE value.
		 * @return array|mixed The media array, or the value unchanged when it is not resolvable.
		 */
		private function media_shape( $value ) {
			if ( is_array( $value ) ) {
				if ( ! empty( $value['url'] ) ) {
					return $value;
				}
				if ( ! empty( $value['id'] ) && is_numeric( $value['id'] ) ) {
					$value = (int) $value['id'];
				} elseif ( 1 === count( $value ) ) {
					// A single-item list is how a non-multiple relational field can come back.
					$only  = reset( $value );
					$value = is_numeric( $only ) ? (int) $only : $value;
				}
			}
			if ( is_numeric( $value ) ) {
				$url = wp_get_attachment_url( (int) $value );
				if ( $url ) {
					return array(
						'id'  => (int) $value,
						'url' => $url,
					);
				}
				// An id that no longer resolves to an attachment: better to render nothing than a
				// broken image whose src is a number.
				return '';
			}
			if ( is_string( $value ) && '' !== $value ) {
				// Already a URL under a different return format.
				return array( 'url' => $value );
			}
			return $value;
		}

		// -------------------------------------------------------------
		// Repeaters
		// -------------------------------------------------------------

		/**
		 * The request Nexter Blocks' own repeater handler was given, kept so the shim below can
		 * still see it after that handler has replaced it with an error.
		 *
		 * @var mixed
		 */
		private $last_repeater_request = null;

		/**
		 * Serve the editor's repeater request when the field belongs to NE.
		 *
		 * Nexter Blocks registers `/tpgb/v1/repeater-data` with its method as the callback, so
		 * there is no filter inside it to hook. Intercepting at rest_pre_dispatch is the supported
		 * WordPress way to answer a route another plugin owns, and it touches nothing else: any
		 * route or field that is not ours is returned untouched, which leaves the original callback
		 * to run exactly as before.
		 *
		 * @param mixed           $result  Response to replace the request with, or null to proceed.
		 * @param WP_REST_Server  $server  Server instance.
		 * @param WP_REST_Request $request Request.
		 * @return mixed
		 */
		public function rest_intercept( $result, $server, $request ) {
			unset( $server );
			if ( null !== $result || ! $request instanceof WP_REST_Request ) {
				return $result;
			}
			if ( '/tpgb/v1/repeater-data' !== $request->get_route() ) {
				return $result;
			}

			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = $request->get_params();
			}
			$field_name = isset( $params['field_name'] ) ? $params['field_name'] : '';
			$parsed     = self::parse( $field_name );
			if ( ! $parsed ) {
				return $result;
			}

			$object  = isset( $params['post_id'] ) && '' !== $params['post_id'] ? $params['post_id'] : $this->context();
			$payload = $this->repeater_payload( $parsed['group'], $parsed['field'], $object );

			return $payload ? rest_ensure_response( $payload ) : $result;
		}

		/**
		 * Answer the pre-filter Nexter Blocks may add (REQ-NB-1).
		 *
		 * @param mixed $pre     Null when nobody has claimed the request.
		 * @param mixed $request Whatever Nexter Blocks was handed.
		 * @return mixed
		 */
		public function repeater_pre( $pre, $request ) {
			if ( null !== $pre ) {
				return $pre;
			}
			$payload = $this->payload_from_request( $request );
			return null === $payload ? $pre : $payload;
		}

		/**
		 * Remember the untouched request before Nexter Blocks' handler runs.
		 *
		 * @param mixed $request Request.
		 * @return mixed The request, unchanged.
		 */
		public function remember_repeater_request( $request ) {
			$this->last_repeater_request = $request;
			return $request;
		}

		/**
		 * Substitute NE rows when Nexter Blocks has refused the prefix.
		 *
		 * Narrow on purpose. It fires only when the handler returned its `invalid_prefix` error —
		 * meaning it has positively declined the key — and only when the remembered request turns
		 * out to be one of ours. Any other error, or any success, is passed straight through.
		 *
		 * @param mixed $result Whatever Nexter Blocks' handler returned.
		 * @return mixed
		 */
		public function repeater_fallback( $result ) {
			$request                     = $this->last_repeater_request;
			$this->last_repeater_request = null;

			if ( ! is_wp_error( $result ) || 'invalid_prefix' !== $result->get_error_code() ) {
				return $result;
			}
			$payload = $this->payload_from_request( $request );
			return null === $payload ? $result : $payload;
		}

		/**
		 * Find an NE repeater key inside whatever shape the request arrived in, and build its rows.
		 *
		 * The server-side call site explodes a composite string into five or seven parts whose
		 * index layout is not documented and is assembled in the block's JavaScript. Rather than
		 * hard-code positions that a future version could reorder, the parts are SCANNED for our
		 * own prefix — which is unambiguous, since no other source uses it.
		 *
		 * @param mixed $request Request array, object, or composite parts.
		 * @return array|null Payload, or null when the request is not ours.
		 */
		private function payload_from_request( $request ) {
			$candidates = array();

			if ( is_object( $request ) && method_exists( $request, 'get_json_params' ) ) {
				$params = $request->get_json_params();
				if ( is_array( $params ) && isset( $params['field_name'] ) ) {
					$candidates[] = $params['field_name'];
				}
			}
			if ( is_array( $request ) ) {
				if ( isset( $request['field_name'] ) ) {
					$candidates[] = $request['field_name'];
				}
				foreach ( $request as $part ) {
					if ( is_string( $part ) ) {
						$candidates[] = $part;
					}
				}
				// The composite splits our own prefix apart, so re-join neighbours to find it:
				// [… 'nefields', 'group', 'field' …] becomes 'nefields|group|field'.
				$values = array_values( array_filter( $request, 'is_string' ) );
				$count  = count( $values );
				for ( $i = 0; $i < $count - 2; $i++ ) {
					if ( self::PREFIX === $values[ $i ] . '|' ) {
						$candidates[] = $values[ $i ] . '|' . $values[ $i + 1 ] . '|' . $values[ $i + 2 ];
					}
				}
			}
			if ( is_string( $request ) ) {
				$candidates[] = $request;
			}

			foreach ( $candidates as $candidate ) {
				$parsed = self::parse( $candidate );
				if ( ! $parsed ) {
					continue;
				}
				$object  = $this->object_from_request( $request );
				$payload = $this->repeater_payload( $parsed['group'], $parsed['field'], $object );
				if ( $payload ) {
					return $payload;
				}
			}

			return null;
		}

		/**
		 * Best available object for a repeater request.
		 *
		 * @param mixed $request Request.
		 * @return int|string|false
		 */
		private function object_from_request( $request ) {
			if ( is_array( $request ) ) {
				if ( isset( $request['post_id'] ) && is_numeric( $request['post_id'] ) ) {
					return (int) $request['post_id'];
				}
				// Nexter Blocks uses the sentinel 'nxt-repeater-all' to mean "the current post";
				// anything numeric in the composite is a real id.
				foreach ( $request as $part ) {
					if ( is_numeric( $part ) && (int) $part > 0 ) {
						return (int) $part;
					}
				}
			}
			return $this->context();
		}

		/**
		 * Build the `{ repeater_data, sub_fields_data }` payload Nexter Blocks expects.
		 *
		 * Rows need no conversion: nxt_field_value() already returns a repeater as a list of
		 * associative rows, which is the same shape as `repeater_data`. Only the sub-field
		 * descriptors have to be assembled.
		 *
		 * @param string     $group_key   Group key.
		 * @param string     $field       Repeater field name.
		 * @param int|string $object_ctx  Object context.
		 * @return array|null Null when the field is not a repeater, or has no rows.
		 */
		private function repeater_payload( $group_key, $field, $object_ctx ) {
			// AUTHORISATION. This is the one place in the integration where the object is chosen by
			// the CALLER rather than by the page being rendered: the editor posts a post_id to
			// /tpgb/v1/repeater-data, and Nexter Blocks registers that route with
			// `permission_callback => return true`, so the request can be entirely unauthenticated.
			//
			// Without this check an anonymous visitor could name any post id and read field values
			// off drafts and private posts. Verified as a real leak before the check existed; the
			// module's own rule is that anything accepting an arbitrary object id must carry a
			// per-object capability check, not rely on a module-wide gate.
			if ( ! self::can_read_object( $object_ctx ) ) {
				return null;
			}

			$definition = $this->find_field( $group_key, $field );
			if ( ! $definition || ( isset( $definition['type'] ) && 'repeater' !== $definition['type'] ) ) {
				return null;
			}

			$rows = nxt_field_value( $field, $object_ctx );
			if ( ! is_array( $rows ) || ! $rows ) {
				return null;
			}

			$map      = self::bucket_map();
			$subs     = array();
			$declared = isset( $definition['fields'] ) ? (array) $definition['fields'] : array();
			foreach ( $declared as $sub ) {
				$sub_name = isset( $sub['name'] ) ? (string) $sub['name'] : '';
				$sub_type = isset( $sub['type'] ) ? (string) $sub['type'] : '';
				if ( '' === $sub_name || ! isset( $map[ $sub_type ] ) ) {
					// A nested repeater has no scalar control to bind to, and password never gets
					// offered anywhere — both fall out here.
					continue;
				}
				$subs[] = array(
					// TWO segments, not three. The editor derives the sub-field name with
					// `dynamicField.split('|')[1]`, so a third segment would make it read the group
					// key instead of the field.
					'name'  => self::PREFIX . $sub_name,
					'label' => isset( $sub['label'] ) && '' !== $sub['label'] ? (string) $sub['label'] : $sub_name,
					'type'  => $map[ $sub_type ],
				);
			}

			// Rows are narrowed to the sub-fields actually offered, so a control can never be
			// handed a nested array it cannot render.
			$allowed = array();
			foreach ( $subs as $s ) {
				$allowed[ substr( $s['name'], strlen( self::PREFIX ) ) ] = true;
			}
			// Sub-field types, so each cell is shaped the way its own type needs.
			$sub_types = array();
			foreach ( $declared as $sub ) {
				if ( ! empty( $sub['name'] ) ) {
					$sub_types[ $sub['name'] ] = isset( $sub['type'] ) ? (string) $sub['type'] : '';
				}
			}

			$clean = array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$out = array();
				foreach ( $row as $k => $v ) {
					if ( ! isset( $allowed[ $k ] ) ) {
						continue;
					}
					$sub_type = isset( $sub_types[ $k ] ) ? $sub_types[ $k ] : '';
					$shaped   = $this->shape( $v, $sub_type );
					// Inside a repeater row Nexter Blocks expects media as a bare URL string — its
					// own ACF branch returns wp_get_attachment_image_src()[0] — whereas a top-level
					// image attribute expects the { id, url } array. Same value, two shapes.
					if ( is_array( $shaped ) && isset( $shaped['url'] ) ) {
						$shaped = $shaped['url'];
					}
					$out[ $k ] = $shaped;
				}
				$clean[] = $out;
			}

			return array(
				'repeater_data'   => $clean,
				'sub_fields_data' => $subs,
			);
		}

		/**
		 * Whether the current user may read the object a caller has named.
		 *
		 * Publicly visible content is allowed outright — a published post of a public type is
		 * already readable by any visitor, and dynamic fields on a public page are the entire point
		 * of the feature. It is checked by status and post type rather than by capability, because
		 * `current_user_can( 'read_post', … )` maps to the `read` capability for published posts and
		 * a logged-out visitor holds no capabilities at all: using it alone would authorise nobody
		 * and silently break the feature for the exact audience it exists for.
		 *
		 * Everything else — drafts, private posts, non-public post types — falls through to the real
		 * capability check. Password-protected posts are excluded as well: their content is not
		 * readable until the password is given, and a field value is part of that content.
		 *
		 * Non-post contexts (`term_1`, `user_2`) are only ever produced by this class from the
		 * queried object of the page being rendered, never from caller input, so they carry no IDOR
		 * risk and are allowed through.
		 *
		 * @param mixed $object_ctx Object context.
		 * @return bool
		 */
		private static function can_read_object( $object_ctx ) {
			if ( ! is_numeric( $object_ctx ) ) {
				return true;
			}
			$post = get_post( (int) $object_ctx );
			if ( ! $post ) {
				return false;
			}
			if ( '' !== $post->post_password && ! current_user_can( 'edit_post', $post->ID ) ) {
				return false;
			}

			$status = get_post_status_object( $post->post_status );
			$type   = get_post_type_object( $post->post_type );
			if ( $status && ! empty( $status->public ) && $type && ! empty( $type->public ) ) {
				return true;
			}

			return current_user_can( 'read_post', $post->ID );
		}

		/**
		 * One field definition by group key and field name.
		 *
		 * @param string $group_key Group key.
		 * @param string $field     Field name.
		 * @return array|null
		 */
		private function find_field( $group_key, $field ) {
			foreach ( $this->groups() as $group ) {
				if ( ! isset( $group['key'] ) || $group['key'] !== $group_key ) {
					continue;
				}
				foreach ( (array) $group['fields'] as $candidate ) {
					if ( isset( $candidate['name'] ) && $candidate['name'] === $field ) {
						return $candidate;
					}
				}
			}
			return null;
		}

		/**
		 * Split a `nefields|group|field` option value.
		 *
		 * @param mixed $selected Selected option value.
		 * @return array{group:string, field:string}|null Null when the value is not ours.
		 */
		public static function parse( $selected ) {
			if ( ! is_string( $selected ) || 0 !== strpos( $selected, self::PREFIX ) ) {
				return null;
			}
			$rest  = substr( $selected, strlen( self::PREFIX ) );
			$parts = explode( '|', $rest );
			if ( count( $parts ) < 2 ) {
				return null;
			}
			$group = sanitize_key( $parts[0] );
			$field = sanitize_key( $parts[1] );
			if ( '' === $group || '' === $field ) {
				return null;
			}
			return array(
				'group' => $group,
				'field' => $field,
			);
		}

		/**
		 * The object a value should be read from, in the form nxt_field_value() expects.
		 *
		 * On a taxonomy archive the queried object is a term, and NE addresses term values as
		 * `term_{id}` — without this a block on a category template would silently read the wrong
		 * object (or nothing) rather than the term's own field values.
		 *
		 * @return int|string|false
		 */
		private function context() {
			if ( is_tax() || is_category() || is_tag() ) {
				$term = get_queried_object();
				if ( $term instanceof WP_Term ) {
					return 'term_' . (int) $term->term_id;
				}
			}
			if ( is_author() ) {
				$author = get_queried_object();
				if ( $author instanceof WP_User ) {
					return 'user_' . (int) $author->ID;
				}
			}
			// false = "the current post", which nxt_field_value() resolves itself.
			return get_the_ID() ? (int) get_the_ID() : false;
		}

		/**
		 * Reduce a formatted NE value to what a Dynamic Field control can render.
		 *
		 * Values arrive already formatted per type from nxt_field_value(), but a few types
		 * legitimately return a structure (a link is { url, title, target }; a multi-select is a
		 * list). A block control
		 * expects a scalar or an image array, so the structure is narrowed here rather than being
		 * echoed as "Array".
		 *
		 * @param mixed  $value Formatted NE value.
		 * @param string $type  NE field type, when known.
		 * @return mixed
		 */
		private function shape( $value, $type = '' ) {
			// Media has to become { id, url } whatever it arrived as — see media_shape().
			if ( 'image' === $type || 'file' === $type ) {
				return $this->media_shape( $value );
			}
			if ( is_scalar( $value ) ) {
				return $value;
			}
			if ( ! is_array( $value ) ) {
				return '';
			}
			// An image field formatted by NE already carries a url; pass that shape straight
			// through, since Nexter Blocks' image bucket reads exactly this.
			if ( isset( $value['url'] ) ) {
				return $value;
			}
			// Link fields.
			foreach ( array( 'link', 'title', 'name', 'label' ) as $k ) {
				if ( isset( $value[ $k ] ) && is_scalar( $value[ $k ] ) ) {
					return $value[ $k ];
				}
			}
			// A list of scalars (multi-select, checkbox) reads best as a comma list.
			$scalars = array_filter( $value, 'is_scalar' );
			if ( count( $scalars ) === count( $value ) && $scalars ) {
				return implode( ', ', $scalars );
			}
			return '';
		}

		/**
		 * Active, published field groups with their fields.
		 *
		 * Read from the definitions repository rather than by querying posts, so this shares the
		 * module's cache instead of adding queries of its own.
		 *
		 * @return array<int, array{key:string, title:string, fields:array}>
		 */
		private function groups() {
			$out  = array();
			$rows = Nxt_Content_Types_Definitions_Repository::get_instance()->by_kind();
			if ( empty( $rows['group_fields'] ) ) {
				return $out;
			}

			foreach ( $rows['group_fields'] as $row ) {
				if ( 'publish' !== $row['status'] ) {
					continue;
				}
				$meta = $row['meta'];
				// Absent `active` means active — see the module's own convention.
				if ( isset( $meta['active'] ) && ! $meta['active'] ) {
					continue;
				}
				$out[] = array(
					'key'    => isset( $meta['key'] ) ? $meta['key'] : 'group_' . $row['id'],
					'title'  => isset( $meta['title'] ) && '' !== $meta['title'] ? $meta['title'] : $row['title'],
					'fields' => isset( $meta['fields'] ) ? (array) $meta['fields'] : array(),
				);
			}

			return $out;
		}
	}

	Nexter_Blocks_Dynamic_Fields::get_instance();
}
