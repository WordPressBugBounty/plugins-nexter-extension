<?php
/**
 * Render Field Groups (Group Fields) as dynamic WP metaboxes on post edit screens.
 *
 * @package Nexter_Extension
 * @subpackage Content_Types
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nxt_Content_Types_Field_Groups_Render
 */
class Nxt_Content_Types_Field_Groups_Render {

	/**
	 * Single instance.
	 *
	 * @var Nxt_Content_Types_Field_Groups_Render|null
	 */
	private static $instance = null;

	/**
	 * Request cache: group_key => [ field_name => field_config ].
	 *
	 * @var array<string, array<string, array>>
	 */
	private static $nxt_gf_group_field_maps = array();

	/**
	 * Labels of required fields whose clearing was refused during the current save.
	 *
	 * @var string[]
	 */
	private $required_blocked = array();

	/**
	 * Per-request cache for required_errors_for().
	 *
	 * @var array<string,array<string,string>>
	 */
	private $required_errors_cache = array();

	/**
	 * Get instance.
	 *
	 * @return Nxt_Content_Types_Field_Groups_Render
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
	/**
	 * Drop the memoized group field maps.
	 *
	 * @return void
	 */
	public static function flush_group_field_maps() {
		self::$nxt_gf_group_field_maps = array();
	}

	/**
	 * Flush the memo when the definitions post meta is written directly.
	 *
	 * @param mixed  $meta_id  Unused.
	 * @param mixed  $object_id Unused.
	 * @param string $meta_key Meta key being written.
	 * @return void
	 */
	public static function maybe_flush_group_field_maps( $meta_id = null, $object_id = null, $meta_key = '' ) {
		if ( '_nxt_content_type_data' === $meta_key ) {
			self::flush_group_field_maps();
		}
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		// A definition written at any point in the request invalidates the memoized field maps.
		foreach ( array( 'nxt/definition_saved', 'nxt/definition_deleted', 'nxt/definition_deleted_permanently', 'nxt/definition_restored' ) as $nxt_def_hook ) {
			add_action( $nxt_def_hook, array( __CLASS__, 'flush_group_field_maps' ) );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $nxt_meta_hook ) {
			add_action( $nxt_meta_hook, array( __CLASS__, 'maybe_flush_group_field_maps' ), 10, 3 );
		}

		// Persistence + frontend-relevant hooks must bind in ALL contexts, not just wp-admin.
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
		add_action( 'register_form', array( $this, 'render_user_profile_fields' ) );
		add_action( 'user_register', array( $this, 'save_user_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_fields' ) );
		add_action( 'created_term', array( $this, 'save_taxonomy_fields' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'save_taxonomy_fields' ), 10, 3 );
		// Comment object type: read, meta-key and location support all exist, but nothing ever wrote a comment value.
		add_action( 'edit_comment', array( $this, 'save_comment_fields' ) );
		// Required-field enforcement, at the only points WordPress lets a save be refused.
		add_filter( 'pre_insert_term', array( $this, 'validate_required_on_term_insert' ), 10, 2 );
		add_action( 'user_profile_update_errors', array( $this, 'validate_required_on_user_save' ), 10, 1 );
		// wp_ajax_* only ever fire on admin-ajax.php regardless of is_admin(); safe to always bind.
		add_action( 'wp_ajax_nxt_gf_post_object_search', array( $this, 'ajax_post_object_search' ) );
		add_action( 'wp_ajax_nxt_gf_page_link_search', array( $this, 'ajax_page_link_search' ) );
		add_action( 'wp_ajax_nxt_gf_user_search', array( $this, 'ajax_user_search' ) );

		// Admin-only UI: metaboxes, admin asset enqueue, screen hooks, and the admin user-profile
		// render/edit screens (these action hooks only fire in wp-admin anyway).
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ), 20, 2 );
			add_action( 'add_meta_boxes_comment', array( $this, 'register_comment_meta_boxes' ), 20, 1 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ), 10 );
			add_action( 'current_screen', array( $this, 'maybe_register_taxonomy_hooks' ) );
			add_action( 'show_user_profile', array( $this, 'render_user_profile_fields' ) );
			add_action( 'edit_user_profile', array( $this, 'render_user_profile_fields' ) );
			add_action( 'user_new_form', array( $this, 'render_user_profile_fields' ) );
		}
	}

	/**
	 * Register dynamic metaboxes for matching field groups.
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post object.
	 */
	public function register_meta_boxes( $post_type, $post ) {
		// Do not show field groups on the internal definitions post type itself.
		if ( Nxt_Content_Types_CPT::POST_TYPE === $post_type ) {
			return;
		}

		// Same builder the template API uses, so this screen can't drift from the frontend read path.
		// user_form is deliberately NOT set: it belongs to the User location family, and setting it here leaked those groups onto every post editor.
		$context                = nxt_build_context_for_object(
			'post',
			$post->ID,
			array(
				'post_type' => $post_type,
			)
		);
		$context['post']        = $post;
		$context['post_status'] = $post->post_status;

		$groups = Nxt_Content_Types_Field_Groups_Location::get_matching_groups( $context );
		if ( empty( $groups ) ) {
			return;
		}

		foreach ( $groups as $group ) {
			$group_id = isset( $group['id'] ) ? (int) $group['id'] : 0;
			$title    = isset( $group['title'] ) ? $group['title'] : '';
			$position = isset( $group['position'] ) ? $group['position'] : 'normal';
			$style    = isset( $group['style'] ) ? $group['style'] : 'default';

			$box_id = 'nxt_gf_' . $group_id;

			$box_context = ( 'side' === $position ) ? 'side' : 'normal';
			$priority    = ( 'acf_after_title' === $position ) ? 'high' : 'default';

			add_meta_box(
				$box_id,
				$title,
				array( $this, 'render_meta_box' ),
				$post_type,
				$box_context,
				$priority,
				array(
					'nxt_gf_group' => $group,
					'nxt_gf_style' => $style,
				)
			);
		}
	}

	/**
	 * Whether any field group matches the post being edited, using the same context as register_meta_boxes().
	 *
	 * @param string $post_type Post type of the screen.
	 * @return bool
	 */
	private function post_screen_has_groups( $post_type ) {
		global $post;
		if ( ! $post instanceof WP_Post ) {
			return true;
		}
		$context                = nxt_build_context_for_object( 'post', $post->ID, array( 'post_type' => $post_type ) );
		$context['post']        = $post;
		$context['post_status'] = $post->post_status;
		return ! empty( Nxt_Content_Types_Field_Groups_Location::get_matching_groups( $context ) );
	}

	/**
	 * Enqueue CSS for field group metaboxes on post edit screens (admin only).
	 */
	public function enqueue_admin_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		// Load styles on all admin locations where field groups can render, mapped to the object type each screen
		// edits.
		$bases_to_object_type = array(
			'post'      => 'post',
			'post-new'  => 'post',
			'edit-tags' => 'term',
			'term'      => 'term',
			'profile'   => 'user',
			'user-edit' => 'user',
			'user-new'  => 'user',
			'register'  => 'user',
			'user'      => 'user',
			'comment'   => 'comment',
		);
		if ( ! isset( $bases_to_object_type[ $screen->base ] ) ) {
			return;
		}
		// WP_Screen declares $post_type as a string property, defaulting to '', so the old
		// isset() fallback was unreachable.
		$post_type = (string) $screen->post_type;
		if ( Nxt_Content_Types_CPT::POST_TYPE === $post_type ) {
			return;
		}

		// Nothing below is cheap: ~127 KB of plugin CSS/JS plus wp-color-picker, jquery-ui-datepicker,
		// wp-jquery-ui-dialog, editor-buttons and wplink.
		if ( ! Nxt_Content_Types_Field_Groups_Location::targets_object_type( $bases_to_object_type[ $screen->base ] ) ) {
			return;
		}
		// Post screens add boxes only for matching groups, so skip the assets when none match.
		if ( 'post' === $bases_to_object_type[ $screen->base ] && ! $this->post_screen_has_groups( $post_type ) ) {
			return;
		}
		$ver = defined( 'NEXTER_EXT_VER' ) ? NEXTER_EXT_VER : '4.6.0';
		wp_enqueue_style(
			'nxt-gf-render',
			NEXTER_EXT_URL . 'assets/css/admin/nxt-gf-style.css',
			array(),
			$ver
		);
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script( 'jquery-ui-datepicker' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );
		// WordPress Insert/edit link dialog (wplink) for Link field type.
		wp_enqueue_style( 'editor-buttons' );
		wp_enqueue_script( 'wplink' );
		// Select2 for stylized select fields (enqueued on same screens; init only when .nxt-gf-select2 exists).
		$min = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_style(
			'nexter-select-css',
			NEXTER_EXT_URL . 'assets/css/extra/select2' . $min . '.css',
			array(),
			$ver
		);
		wp_enqueue_script(
			'nexter-select-js',
			NEXTER_EXT_URL . 'assets/js/extra/select2' . $min . '.js',
			array( 'jquery', 'wplink', 'wp-color-picker' ),
			$ver,
			true
		);
		// Live show/hide for per-field conditional logic.
		wp_enqueue_script(
			'nxt-gf-conditional',
			NEXTER_EXT_URL . 'assets/js/admin/nxt-gf-conditional.js',
			array( 'jquery' ),
			$ver,
			true
		);
		wp_localize_script(
			'nexter-select-js',
			'nxtGfPostObject',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'nxt_gf_post_object' ),
			)
		);
		wp_localize_script(
			'nexter-select-js',
			'nxtGfPageLink',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'nxt_gf_page_link' ),
			)
		);
		wp_localize_script(
			'nexter-select-js',
			'nxtGfUser',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'nxt_gf_user' ),
			)
		);
		wp_add_inline_script(
			'nexter-select-js',
			'window.nxtGfInitPostObjectSelect=function($,$ctx){var $root=$ctx&&$ctx.jquery?$ctx:($ctx?$( $ctx ):$(document));' .
				'$root.find(".nxt-gf-post-object-select").each(function(){' .
					'var $el=$(this);' .
					'if($el.data("select2")){return;}' .
					'var filters={};try{filters=JSON.parse($el.attr("data-nxt-gf-po-filters")||"{}");}catch(e){}' .
					'var ajaxUrl=(typeof ajaxurl!=="undefined"&&ajaxurl)?ajaxurl:((window.nxtGfPostObject&&window.nxtGfPostObject.ajaxurl)||"");' .
					'if(!ajaxUrl){return;}' .
					'$el.select2({' .
						'width:"100%",allowClear:true,' .
						'placeholder:$el.data("placeholder")||"",' .
						'minimumInputLength:0,' .
						'ajax:{' .
							'url:ajaxUrl,type:"POST",dataType:"json",delay:250,' .
							'data:function(params){return{' .
								'action:"nxt_gf_post_object_search",' .
								'nonce:(window.nxtGfPostObject&&window.nxtGfPostObject.nonce)||"",' .
								'search:params.term||"",' .
								'page:params.page||1,' .
								'filters:JSON.stringify(filters)' .
							'};},' .
							'processResults:function(data){' .
								'if(!data||!data.success||!data.data){return{results:[]};}' .
								'var r=data.data.results||[];' .
								'return{results:r,pagination:{more:!!data.data.more}};' .
							'}' .
						'}' .
					'});' .
					'$el.on("change.nxtPo",function(){' .
						'var $t=$(this);$t.siblings(".nxt-gf-post-object-empty-flag").remove();' .
						'if($t.prop("multiple")){var v=$t.val();if(!v||!v.length){var n=$t.attr("name");if(n){$t.after("<input type=\"hidden\" class=\"nxt-gf-post-object-empty-flag\" name=\""+n.replace(/"/g,"&quot;")+"\" value=\"\" />");}}}' .
					'});' .
				'});' .
			'};' .
			'window.nxtGfInitPageLinkSelect=function($,$ctx){var $root=$ctx&&$ctx.jquery?$ctx:($ctx?$( $ctx ):$(document));' .
				'$root.find(".nxt-gf-page-link-select").each(function(){' .
					'var $el=$(this);' .
					'if($el.data("select2")){return;}' .
					'var filters={};try{filters=JSON.parse($el.attr("data-nxt-gf-pl-filters")||"{}");}catch(e){}' .
					'var ajaxUrl=(typeof ajaxurl!=="undefined"&&ajaxurl)?ajaxurl:((window.nxtGfPageLink&&window.nxtGfPageLink.ajaxurl)||((window.nxtGfPostObject&&window.nxtGfPostObject.ajaxurl)||""));' .
					'if(!ajaxUrl){return;}' .
					'$el.select2({' .
						'width:"100%",allowClear:true,' .
						'placeholder:$el.data("placeholder")||"",' .
						'minimumInputLength:0,' .
						'ajax:{' .
							'url:ajaxUrl,type:"POST",dataType:"json",delay:250,' .
							'data:function(params){return{' .
								'action:"nxt_gf_page_link_search",' .
								'nonce:(window.nxtGfPageLink&&window.nxtGfPageLink.nonce)||"",' .
								'search:params.term||"",' .
								'page:params.page||1,' .
								'filters:JSON.stringify(filters)' .
							'};},' .
							'processResults:function(data){' .
								'if(!data||!data.success||!data.data){return{results:[]};}' .
								'var r=data.data.results||[];' .
								'return{results:r,pagination:{more:!!data.data.more}};' .
							'}' .
						'}' .
					'});' .
					'$el.on("change.nxtPl",function(){' .
						'var $t=$(this);$t.siblings(".nxt-gf-page-link-empty-flag").remove();' .
						'if($t.prop("multiple")){var v=$t.val();if(!v||!v.length){var n=$t.attr("name");if(n){$t.after("<input type=\"hidden\" class=\"nxt-gf-page-link-empty-flag\" name=\""+n.replace(/"/g,"&quot;")+"\" value=\"\" />");}}}' .
					'});' .
				'});' .
			'};' .
			'window.nxtGfInitUserSelect=function($,$ctx){var $root=$ctx&&$ctx.jquery?$ctx:($ctx?$( $ctx ):$(document));' .
				'$root.find(".nxt-gf-user-select").each(function(){' .
					'var $el=$(this);' .
					'if($el.data("select2")){return;}' .
					'var filters={};try{filters=JSON.parse($el.attr("data-nxt-gf-user-filters")||"{}");}catch(e){}' .
					'var ajaxUrl=(typeof ajaxurl!=="undefined"&&ajaxurl)?ajaxurl:((window.nxtGfUser&&window.nxtGfUser.ajaxurl)||((window.nxtGfPostObject&&window.nxtGfPostObject.ajaxurl)||""));' .
					'if(!ajaxUrl){return;}' .
					'$el.select2({' .
						'width:"100%",allowClear:true,' .
						'placeholder:$el.data("placeholder")||"",' .
						'minimumInputLength:0,' .
						'ajax:{' .
							'url:ajaxUrl,type:"POST",dataType:"json",delay:250,' .
							'data:function(params){return{' .
								'action:"nxt_gf_user_search",' .
								'nonce:(window.nxtGfUser&&window.nxtGfUser.nonce)||"",' .
								'search:params.term||"",' .
								'page:params.page||1,' .
								'filters:JSON.stringify(filters)' .
							'};},' .
							'processResults:function(data){' .
								'if(!data||!data.success||!data.data){return{results:[]};}' .
								'var r=data.data.results||[];' .
								'return{results:r,pagination:{more:!!data.data.more}};' .
							'}' .
						'}' .
					'});' .
					'$el.on("change.nxtUsr",function(){' .
						'var $t=$(this);$t.siblings(".nxt-gf-user-empty-flag").remove();' .
						'if($t.prop("multiple")){var v=$t.val();if(!v||!v.length){var n=$t.attr("name");if(n){$t.after("<input type=\"hidden\" class=\"nxt-gf-user-empty-flag\" name=\""+n.replace(/"/g,"&quot;")+"\" value=\"\" />");}}}' .
					'});' .
				'});' .
			'};' .
			'window.nxtGfInitColorPickers=function($,$ctx){' .
				'var $root=$ctx&&$ctx.jquery?$ctx:($ctx?$( $ctx ):$(document));' .
				'if(!$.fn.wpColorPicker){return;}' .
				'$root.find("input.nxt-gf-color-input").each(function(){' .
					'var $in=$(this);' .
					'if($in.data("nxtGfColorInit")){return;}' .
					'var $wrap=$in.closest(".nxt-gf-color-wrap");' .
					'var raw=$in.attr("data-nxt-gf-color-opts")||"";' .
					'var o={palettes:true,defaultColor:false,showWheel:true};' .
					'try{if(raw){var p=JSON.parse(raw);if(p&&typeof p==="object"){$.extend(o,p);}}}catch(e){}' .
					'$in.data("nxtGfColorInit",true);' .
					'if(o.showWheel===false){$wrap.addClass("nxt-gf-color-no-wheel");}else{$wrap.removeClass("nxt-gf-color-no-wheel");}' .
					'var pal=o.palettes;' .
					'var dc=o.defaultColor?o.defaultColor:false;' .
					'$in.wpColorPicker({palettes:pal,defaultColor:dc,change:function(){},clear:function(){}});' .
				'});' .
			'};' .
			'jQuery(function($){' .
				'nxtGfInitPostObjectSelect($,$(document));' .
				'nxtGfInitPageLinkSelect($,$(document));' .
				'if(typeof nxtGfInitUserSelect==="function"){nxtGfInitUserSelect($,$(document));}' .
				'if(typeof nxtGfInitRelationship==="function"){nxtGfInitRelationship($,$(document));}' .
				'if(typeof nxtGfInitColorPickers==="function"){nxtGfInitColorPickers($,$(document));}' .
				'if ($(".nxt-gf-select2").length) { $(".nxt-gf-select2").select2({ width: "100%" }); }' .
				'$(document).on("change", ".nxt-gf-toggle-all", function(){' .
					'var targetName = $(this).data("target-name");' .
					'var isChecked = $(this).is(":checked");' .
					'if (!targetName) { return; }' .
					'$("input[type=\"checkbox\"][name=\"" + targetName + "\"]").prop("checked", isChecked);' .
				'});' .
				'$(document).on("focusin click", "textarea.nxt-gf-wysiwyg-delayed", function(){' .
					'var $t = $(this);' .
					'if ($t.data("nxtGfInit")) { return; }' .
					'if (typeof wp === "undefined" || !wp.editor || !wp.editor.initialize) { return; }' .
					'var raw = $t.attr("data-nxt-gf-editor-settings") || "{}";' .
					'var settings = {}; try { settings = JSON.parse(raw); } catch(e) {}' .
					'wp.editor.initialize($t.attr("id"), settings);' .
					'$t.data("nxtGfInit", true);' .
				'});' .
				'$(document).on("click", ".nxt-gf-media-button", function(e){' .
					'e.preventDefault();' .
					'if (typeof wp === "undefined" || !wp.media) { return; }' .
					'var $btn = $(this);' .
					'var targetId = $btn.data("target");' .
					'if (!targetId) { return; }' .
					'var $input = $("#" + targetId);' .
					'var library = $btn.data("library") || "all";' .
					'var allowed = ($btn.data("allowed-types") || "").toString().split(",").map(function(s){return s.trim();}).filter(function(s){return s.length;});' .
					'var previewSize = $btn.data("preview-size") || "medium";' .
					'var minSize = parseFloat($btn.data("min-size") || 0) || 0;' .
					'var maxSize = parseFloat($btn.data("max-size") || 0) || 0;' .
					'var frameArgs = {' .
						'title: $btn.text() || ' . wp_json_encode( __( 'Select Image', 'nexter-extension' ) ) . ',' .
						'button: { text: $btn.text() || ' . wp_json_encode( __( 'Select', 'nexter-extension' ) ) . ' },' .
						'multiple: false' .
					'};' .
					'if (allowed.length) {' .
						'frameArgs.library = { type: allowed };' .
					'}' .
					'if (library === "uploadedTo" && wp.media.view && wp.media.view.settings && wp.media.view.settings.post) {' .
						'frameArgs.library = frameArgs.library || {};' .
						'frameArgs.library.uploadedTo = wp.media.view.settings.post.id;' .
					'}' .
					'var frame = wp.media(frameArgs);' .
					'frame.on("select", function(){' .
						'var attachment = frame.state().get("selection").first().toJSON();' .
						'var bytes = attachment.filesizeInBytes || 0;' .
						'if (!bytes && attachment.filesize && typeof attachment.filesize === "number") { bytes = attachment.filesize; }' .
						'if (bytes && ( (minSize && bytes < minSize * 1048576) || (maxSize && bytes > maxSize * 1048576) )) {' .
							'window.alert(' . wp_json_encode( __( 'Selected file does not meet the size requirements.', 'nexter-extension' ) ) . ');' .
							'return;' .
						'}' .
						'var value = attachment.id;' .
						'$input.val(value).trigger("change");' .
						'var $wrap = $input.closest(".nxt-gf-image-wrap");' .
						'if ($wrap.length) {' .
							'var $prev = $wrap.find(".nxt-gf-image-preview");' .
							'var $img = $prev.find("img");' .
							'if (!$img.length) { $img = $("<img />").prependTo($prev); }' .
							'var url = (attachment.sizes && attachment.sizes[previewSize]) ? attachment.sizes[previewSize].url : attachment.url;' .
							'$img.attr("src", url);' .
							'$wrap.addClass("nxt-gf-image-has-value");' .
						'} else {' .
							'$wrap = $input.closest(".nxt-gf-file-wrap");' .
							'if ($wrap.length) {' .
								'var $title = $wrap.find(".nxt-gf-file-title");' .
								'var $name = $wrap.find(".nxt-gf-file-link");' .
								'var title = attachment.title || attachment.filename || "";' .
								'var filename = attachment.filename || "";' .
								'var human = attachment.filesizeHumanReadable || "";' .
								'var url = attachment.url || "";' .
								'if ($title.length) { $title.text(title); }' .
								'if ($name.length) {' .
									'if ($name.is("a")) { $name.text(filename).attr("href", url); } else { $name.text(filename); }' .
								'}' .
								'$wrap.addClass("nxt-gf-file-has-value");' .
							'}' .
						'}' .
					'});' .
					'frame.open();' .
				'});' .
				'$(document).on("click", ".nxt-gf-image-edit", function(e){' .
					'e.preventDefault();' .
					'var $wrap = $(this).closest(".nxt-gf-image-wrap");' .
					'var $btn = $wrap.find(".nxt-gf-media-button");' .
					'if ($btn.length) { $btn.trigger("click"); }' .
				'});' .
				'$(document).on("click", ".nxt-gf-image-remove", function(e){' .
					'e.preventDefault();' .
					'var $wrap = $(this).closest(".nxt-gf-image-wrap");' .
					'var $input = $wrap.find(".nxt-gf-image-input");' .
					'$input.val("").trigger("change");' .
					'$wrap.removeClass("nxt-gf-image-has-value");' .
					'$wrap.find(".nxt-gf-image-preview img").attr("src", "");' .
				'});' .
				'$(document).on("click", ".nxt-gf-file-edit", function(e){' .
					'e.preventDefault();' .
					'var $wrap = $(this).closest(".nxt-gf-file-wrap");' .
					'var $btn = $wrap.find(".nxt-gf-media-button");' .
					'if ($btn.length) { $btn.trigger("click"); }' .
				'});' .
				'$(document).on("click", ".nxt-gf-file-remove", function(e){' .
					'e.preventDefault();' .
					'var $wrap = $(this).closest(".nxt-gf-file-wrap");' .
					'var $input = $wrap.find(".nxt-gf-file-input");' .
					'$input.val("").trigger("change");' .
					'$wrap.removeClass("nxt-gf-file-has-value");' .
					'$wrap.find(".nxt-gf-file-link").text("");' .
					'$wrap.find(".nxt-gf-file-title").text("");' .
				'});' .
				'function nxtGfLinkFillPreview($wrap){' .
					'var url = ($wrap.find(".nxt-gf-link-input-url").val() || "").trim();' .
					'var title = ($wrap.find(".nxt-gf-link-input-title").val() || "").trim();' .
					'$wrap.find(".nxt-gf-link-title-text").text(title);' .
					'$wrap.find(".nxt-gf-link-url-a").attr("href", url || "#").text(url);' .
					'if (url) { $wrap.addClass("nxt-gf-link-has-value"); } else { $wrap.removeClass("nxt-gf-link-has-value"); }' .
				'}' .
				'function nxtGfLinkCommitFromDialog(){' .
					'var $wrap = window.nxtGfLinkActiveWrap;' .
					'if (!$wrap || !$wrap.length) { return; }' .
					'var url = ($("#wp-link-url").val() || "").trim();' .
					'var text = ($("#wp-link-text").val() || "").trim();' .
					'var target = $("#wp-link-target").is(":checked") ? "1" : "";' .
					'$wrap.find(".nxt-gf-link-input-url").val(url).trigger("change");' .
					'$wrap.find(".nxt-gf-link-input-title").val(text);' .
					'$wrap.find(".nxt-gf-link-input-target").val(target);' .
					'nxtGfLinkFillPreview($wrap);' .
					'window.nxtGfLinkActiveWrap = null;' .
				'}' .
				'$(document).on("click", ".nxt-gf-link-open, .nxt-gf-link-edit", function(e){' .
					'e.preventDefault();' .
					'var $wrap = $(this).closest(".nxt-gf-link-wrap");' .
					'if (typeof wpLink === "undefined" || !wpLink.open) { window.alert(' . wp_json_encode( __( 'Link dialog unavailable.', 'nexter-extension' ) ) . '); return; }' .
					'window.nxtGfLinkActiveWrap = $wrap;' .
					'var stubId = $wrap.find(".nxt-gf-link-wplink-stub").attr("id");' .
					'var url = $wrap.find(".nxt-gf-link-input-url").val() || "";' .
					'var text = $wrap.find(".nxt-gf-link-input-title").val() || "";' .
					'var tVal = $wrap.find(".nxt-gf-link-input-target").val() || "";' .
					'$("#wp-link-url").val(url);' .
					'$("#wp-link-text").val(text);' .
					'$("#wp-link-target").prop("checked", tVal === "1" || tVal === "_blank");' .
					'if (stubId) { window.wpActiveEditor = stubId; } ' .
					'wpLink.open(stubId, url, text);' .
				'});' .
				'$(document).on("mousedown", "#wp-link-submit", function(){ nxtGfLinkCommitFromDialog(); });' .
				'$(document).on("click", "#wp-link-submit", function(){ nxtGfLinkCommitFromDialog(); });' .
				'$(document).on("submit", "#wp-link", function(){ nxtGfLinkCommitFromDialog(); });' .
				'$(document).on("click", ".nxt-gf-link-remove", function(e){' .
					'e.preventDefault();' .
					'var $wrap = $(this).closest(".nxt-gf-link-wrap");' .
					'$wrap.find(".nxt-gf-link-input-url, .nxt-gf-link-input-title, .nxt-gf-link-input-target").val("");' .
					'nxtGfLinkFillPreview($wrap);' .
				'});' .
				'$(document).on("input change", ".nxt-gf-range-wrap input.nxt-gf-range-slider", function(){' .
					'var $wrap = $(this).closest(".nxt-gf-range-wrap");' .
					'$wrap.find("input.nxt-gf-range-number").val($(this).val());' .
				'});' .
				'$(document).on("input change", ".nxt-gf-range-wrap input.nxt-gf-range-number", function(){' .
					'var $wrap = $(this).closest(".nxt-gf-range-wrap");' .
					'var $range = $wrap.find("input.nxt-gf-range-slider");' .
					'var v = $(this).val();' .
					'if (v !== "") { $range.val(v); }' .
				'});' .
				'function nxtGfDatePhpToJq(f){' .
					'var m={' .
						'"d":"dd","j":"d","m":"mm","n":"m","Y":"yy","y":"y","F":"MM","M":"M","D":"D","l":"DD"' .
					'};' .
					'var out="",esc=false;' .
					'for(var i=0;i<f.length;i++){' .
						'var ch=f.charAt(i);' .
						'if(esc){out+=ch;esc=false;continue;}' .
						'if(ch==="\\\\"){esc=true;continue;}' .
						'out+=(m[ch]!==undefined?m[ch]:ch);' .
					'}' .
					'return out;' .
				'}' .
				'$(document).on("focus", ".nxt-gf-date-input", function(){' .
					'var $i=$(this);' .
					'if($i.data("nxtDateInit")){return;}' .
					'if(!$.fn.datepicker){return;}' .
					'var pf=$i.attr("data-display-format")||"d/m/Y";' .
					'var jq=nxtGfDatePhpToJq(pf);' .
					'var ws=parseInt($i.attr("data-week-start")||"1",10);' .
					'$i.datepicker({' .
						'dateFormat:jq,' .
						'firstDay:isNaN(ws)?1:ws,' .
						'changeMonth:true,' .
						'changeYear:true,' .
						'beforeShow:function(){if(typeof $!=="undefined"&&$("#ui-datepicker-div .nxt-gf-dp-time").length){$("#ui-datepicker-div .nxt-gf-dp-time").remove();}}' .
					'});' .
					'$i.data("nxtDateInit",1);' .
					'$i.datepicker("show");' .
				'});' .
				'function nxtGfDtPad2(n){n=parseInt(n,10);if(isNaN(n)||n<0){return"00";}return(n<10?"0":"")+n;}' .
				'function nxtGfDtTo24(h,ap,hour12){h=parseInt(h,10)||0;if(!hour12){return h;}ap=(ap||"am").toLowerCase();if(ap==="pm"&&h<12){h+=12;}if(ap==="am"&&h===12){h=0;}return h;}' .
				'function nxtGfDtFrom24(h){var ap=h>=12?"pm":"am";var d=h%12;if(d===0){d=12;}return{disp:d,ap:ap};}' .
				'function nxtGfDtNow(){var t=new Date();return{y:t.getFullYear(),m:t.getMonth()+1,d:t.getDate(),h:t.getHours(),i:t.getMinutes(),s:t.getSeconds()};}' .
				'function nxtGfDtParse(str,cfg){str=(str||"").trim();if(!str){return null;}var m,y,mo,d,h,i,s,ap;' .
					'if(cfg.order==="dmY"||cfg.order==="mdY"){' .
						'm=str.match(/^(\\d{1,2})\\/(\\d{1,2})\\/(\\d{4})\\s+(\\d{1,2}):(\\d{2})(?::(\\d{2}))?\\s*([AaPp][Mm])?$/);' .
						'if(!m){return null;}' .
						'if(cfg.order==="dmY"){d=+m[1];mo=+m[2];y=+m[3];}else{mo=+m[1];d=+m[2];y=+m[3];}' .
						'h=+m[4];i=+m[5];s=m[6]?+m[6]:0;ap=m[7]||"";' .
					'}else if(cfg.order==="Ymd"){' .
						'm=str.match(/^(\\d{4})-(\\d{2})-(\\d{2})\\s+(\\d{2}):(\\d{2})(?::(\\d{2}))?$/);' .
						'if(!m){return null;}y=+m[1];mo=+m[2];d=+m[3];h=+m[4];i=+m[5];s=m[6]?+m[6]:0;ap="";' .
					'}else if(cfg.order==="Fjy"){' .
						'var months=cfg.months||[];if(!months.length){return null;}' .
						'var alt=months.map(function(nm){return String(nm).replace(/[.*+?^${}()|[\\]\\\\]/g,"\\$&");}).join("|");' .
						'm=str.match(new RegExp("^("+alt+")\\s+(\\d{1,2}),\\s+(\\d{4})\\s+(\\d{1,2}):(\\d{2})(?::(\\d{2}))?\\s*([AaPp][Mm])?$","i"));' .
						'if(!m){return null;}var mi=-1,z;' .
						'for(z=0;z<months.length;z++){if(months[z].toLowerCase()===m[1].toLowerCase()){mi=z+1;break;}}' .
						'if(mi<0){return null;}mo=mi;d=+m[2];y=+m[3];h=+m[4];i=+m[5];s=m[6]?+m[6]:0;ap=m[7]||"";' .
					'}else{return null;}' .
					'h=nxtGfDtTo24(h,ap,cfg.hour12);return{y:y,m:mo,d:d,h:h,i:i,s:s};' .
				'}' .
				'function nxtGfDtFormat(p,cfg){' .
					'function pd(n,pad){return pad?nxtGfDtPad2(n):String(n);}' .
					'var y=p.y,m=p.m,d=p.d,h=p.h|0,i=p.i|0,s=p.s|0,tim,ds;' .
					'if(cfg.order==="dmY"){ds=pd(d,cfg.padDay)+"/"+pd(m,cfg.padMonth)+"/"+y;}' .
					'else if(cfg.order==="mdY"){ds=pd(m,cfg.padMonth)+"/"+pd(d,cfg.padDay)+"/"+y;}' .
					'else if(cfg.order==="Ymd"){ds=y+"-"+nxtGfDtPad2(m)+"-"+nxtGfDtPad2(d);}' .
					'else if(cfg.order==="Fjy"){var months=cfg.months||[];var mn=months[m-1]||(""+m);ds=mn+" "+(cfg.dayNoPad?d:nxtGfDtPad2(d))+", "+y;}' .
					'else{ds="";}' .
					'if(cfg.hour12){var t12=nxtGfDtFrom24(h);var hh=t12.disp;tim=(cfg.hourNoPad?String(hh):nxtGfDtPad2(hh))+":"+nxtGfDtPad2(i)+(cfg.seconds?":"+nxtGfDtPad2(s):"")+" "+t12.ap;}' .
					'else{tim=nxtGfDtPad2(h)+":"+nxtGfDtPad2(i)+(cfg.seconds?":"+nxtGfDtPad2(s):"");}' .
					'return ds+" "+tim;' .
				'}' .
				'function nxtGfDtSyncPickerSelects($dp,p,cfg){' .
					'var $h=$dp.find(".nxt-gf-dt-hour"),$m=$dp.find(".nxt-gf-dt-min"),$s=$dp.find(".nxt-gf-dt-sec"),$a=$dp.find(".nxt-gf-dt-ampm");' .
					'if($h.length){var hv;if(cfg.hour12){var t12=nxtGfDtFrom24(p.h);hv=String(t12.disp);if($a.length){$a.val(t12.ap);}}else{hv=String(p.h);}$h.val(hv);}' .
					'if($m.length){$m.val(String(p.i));}' .
					'if(cfg.seconds&&$s.length){$s.val(String(p.s));}' .
				'}' .
				'function nxtGfDtReadPickerSelects($dp,cfg,base){' .
					'base=base||{};var h,i,s,ap;' .
					'i=parseInt($dp.find(".nxt-gf-dt-min").val(),10);if(isNaN(i)){i=0;}' .
					's=cfg.seconds?parseInt($dp.find(".nxt-gf-dt-sec").val(),10):0;if(isNaN(s)){s=0;}' .
					'if(cfg.hour12){h=parseInt($dp.find(".nxt-gf-dt-hour").val(),10)||0;ap=$dp.find(".nxt-gf-dt-ampm").val()||"am";h=nxtGfDtTo24(h,ap,true);}' .
					'else{h=parseInt($dp.find(".nxt-gf-dt-hour").val(),10)||0;}' .
					'return{y:base.y|0,m:base.m|0,d:base.d|0,h:h,i:i,s:s};' .
				'}' .
				'function nxtGfDtMountPickerPanel($,$inp,inst,cfg){' .
					'var $dp=$(inst.dpDiv);' .
					'$dp.find(".nxt-gf-dp-time").remove();' .
					'var lb=cfg.i18n||{},h12=cfg.hour12,sec=cfg.seconds,html,a,z;' .
					'html="<div class=\\"nxt-gf-dp-time\\"><div class=\\"nxt-gf-dp-time-row\\">";' .
					'html+="<span class=\\"nxt-gf-dt-time-label\\">"+(lb.time||' . wp_json_encode( __( 'Time', 'nexter-extension' ) ) . ')+":</span>";' .
					'html+="<select class=\\"nxt-gf-input nxt-gf-dt-hour\\" aria-label=\\""+(lb.hour||' . wp_json_encode( __( 'Hour', 'nexter-extension' ) ) . ')+"\\">";' .
					'if(h12){for(a=1;a<=12;a++){html+="<option value=\\""+a+"\\">"+a+"</option>";}}' .
					'else{for(a=0;a<24;a++){html+="<option value=\\""+a+"\\">"+nxtGfDtPad2(a)+"</option>";}}' .
					'html+="</select><span class=\\"nxt-gf-dt-sep\\">:</span>";' .
					'html+="<select class=\\"nxt-gf-input nxt-gf-dt-min\\" aria-label=\\""+(lb.minute||' . wp_json_encode( __( 'Minute', 'nexter-extension' ) ) . ')+"\\">";' .
					'for(z=0;z<60;z++){html+="<option value=\\""+z+"\\">"+nxtGfDtPad2(z)+"</option>";}' .
					'html+="</select>";' .
					'if(sec){html+="<span class=\\"nxt-gf-dt-sep\\">:</span><select class=\\"nxt-gf-input nxt-gf-dt-sec\\" aria-label=\\""+(lb.second||' . wp_json_encode( __( 'Second', 'nexter-extension' ) ) . ')+"\\">";for(z=0;z<60;z++){html+="<option value=\\""+z+"\\">"+nxtGfDtPad2(z)+"</option>";}html+="</select>";}' .
					'if(h12){html+="<select class=\\"nxt-gf-input nxt-gf-dt-ampm\\"><option value=\\"am\\">am</option><option value=\\"pm\\">pm</option></select>";}' .
					'html+="</div><div class=\\"nxt-gf-dp-time-actions\\">";' .
					'html+="<button type=\\"button\\" class=\\"button nxt-gf-dp-now\\">"+(lb.now||' . wp_json_encode( __( 'Now', 'nexter-extension' ) ) . ')+"</button>";' .
					'html+="<button type=\\"button\\" class=\\"button button-primary nxt-gf-dp-done\\">"+(lb.done||' . wp_json_encode( __( 'Done', 'nexter-extension' ) ) . ')+"</button>";' .
					'html+="</div></div>";' .
					'$dp.append(html);' .
					'var p0=$inp.data("nxtDtParts")||nxtGfDtParse($inp.val(),cfg)||nxtGfDtNow();' .
					'$inp.data("nxtDtParts",p0);' .
					'nxtGfDtSyncPickerSelects($dp,p0,cfg);' .
					'$dp.off("change.nxtGfDt").on("change.nxtGfDt","select.nxt-gf-dt-hour,select.nxt-gf-dt-min,select.nxt-gf-dt-sec,select.nxt-gf-dt-ampm",function(){' .
						'var gd=$inp.datepicker("getDate");' .
						'var base=$inp.data("nxtDtParts")||nxtGfDtParse($inp.val(),cfg)||nxtGfDtNow();' .
						'if(gd){base.y=gd.getFullYear();base.m=gd.getMonth()+1;base.d=gd.getDate();}' .
						'var cur=nxtGfDtReadPickerSelects($dp,cfg,base);' .
						'$inp.data("nxtDtParts",cur);' .
						'$inp.val(nxtGfDtFormat(cur,cfg));' .
					'});' .
					'$dp.off("click.nxtGfDt").on("click.nxtGfDt",".nxt-gf-dp-now",function(e){' .
						'e.preventDefault();' .
						'var tn=nxtGfDtNow();' .
						'$inp.data("nxtDtParts",tn);' .
						'$inp.datepicker("setDate",new Date(tn.y,tn.m-1,tn.d));' .
						'$inp.val(nxtGfDtFormat(tn,cfg));' .
						'nxtGfDtSyncPickerSelects($dp,tn,cfg);' .
					'}).on("click.nxtGfDt",".nxt-gf-dp-done",function(e){' .
						'e.preventDefault();' .
						'var gd=$inp.datepicker("getDate");' .
						'var base=$inp.data("nxtDtParts")||nxtGfDtParse($inp.val(),cfg)||nxtGfDtNow();' .
						'if(gd){base.y=gd.getFullYear();base.m=gd.getMonth()+1;base.d=gd.getDate();}' .
						'var cur=nxtGfDtReadPickerSelects($dp,cfg,base);' .
						'$inp.data("nxtDtParts",cur);' .
						'$inp.val(nxtGfDtFormat(cur,cfg));' .
						'$inp.datepicker("hide");' .
					'});' .
				'}' .
				'function nxtGfDtEnsureUi($,$inp){' .
					'if(!$inp.length||$inp.data("nxtDtFullInit")){return;}' .
					'if(!$.fn.datepicker){return;}' .
					'var cfg;try{cfg=JSON.parse($inp.attr("data-dt-ui")||"{}");}catch(e1){cfg=null;}if(!cfg){return;}' .
					'var pf=$inp.attr("data-date-only-format")||"d/m/Y";var jq=nxtGfDatePhpToJq(pf);var ws=parseInt($inp.attr("data-week-start")||"1",10);' .
					'$inp.data("nxtDtCfg",cfg);' .
					'var p=nxtGfDtParse($inp.val(),cfg)||nxtGfDtNow();$inp.data("nxtDtParts",p);' .
					'$inp.datepicker({' .
						'dateFormat:jq,' .
						'firstDay:isNaN(ws)?1:ws,' .
						'changeMonth:true,' .
						'changeYear:true,' .
						'beforeShow:function(input,inst){var c=$(input).data("nxtDtCfg");if(!c){return;}setTimeout(function(){nxtGfDtMountPickerPanel($,$(input),inst,c);},0);},' .
						'onSelect:function(dateText,inst){' .
							'var c=$inp.data("nxtDtCfg");var $dp=$(inst.dpDiv);' .
							'var old=$inp.data("nxtDtParts")||nxtGfDtParse($inp.val(),c)||nxtGfDtNow();' .
							'var t=nxtGfDtReadPickerSelects($dp,c,old);' .
							't.y=inst.selectedYear;t.m=inst.selectedMonth+1;t.d=inst.selectedDay;' .
							'$inp.data("nxtDtParts",t);$inp.val(nxtGfDtFormat(t,c));nxtGfDtSyncPickerSelects($dp,t,c);' .
						'},' .
						'onClose:function(){$("#ui-datepicker-div .nxt-gf-dp-time").remove();}' .
					'});' .
					'$inp.data("nxtDateInit",1);$inp.data("nxtDtFullInit",1);' .
					'$inp.datepicker("show");' .
				'}' .
				'$(document).on("focus",".nxt-gf-datetime-input",function(){nxtGfDtEnsureUi($,$(this));});' .
				'function nxtGfTmParse(str,cfg){str=(str||"").trim();if(!str){return null;}var m,h,i,s,ap;' .
					'if(cfg.hour12){m=str.match(/^(\\d{1,2}):(\\d{2})(?::(\\d{2}))?\\s*([AaPp][Mm])?$/);if(!m){return null;}h=+m[1];i=+m[2];s=m[3]?+m[3]:0;ap=m[4]||"am";h=nxtGfDtTo24(h,ap,true);}' .
					'else{m=str.match(/^(\\d{1,2}):(\\d{2})(?::(\\d{2}))?$/);if(!m){return null;}h=+m[1];i=+m[2];s=m[3]?+m[3]:0;}' .
					'return{h:h,i:i,s:s};' .
				'}' .
				'function nxtGfTmFormat(p,cfg){var h=p.h|0,i=p.i|0,s=p.s|0;' .
					'if(cfg.hour12){var t12=nxtGfDtFrom24(h);var hh=t12.disp;return(cfg.hourNoPad?String(hh):nxtGfDtPad2(hh))+":"+nxtGfDtPad2(i)+(cfg.seconds?":"+nxtGfDtPad2(s):"")+" "+t12.ap;}' .
					'return nxtGfDtPad2(h)+":"+nxtGfDtPad2(i)+(cfg.seconds?":"+nxtGfDtPad2(s):"");' .
				'}' .
				'function nxtGfTmNow(){var t=new Date();return{h:t.getHours(),i:t.getMinutes(),s:t.getSeconds()};}' .
				'function nxtGfTmSyncSel($p,cfg,p){' .
					'var $h=$p.find(".nxt-gf-tm-hour"),$m=$p.find(".nxt-gf-tm-min"),$s=$p.find(".nxt-gf-tm-sec"),$a=$p.find(".nxt-gf-tm-ampm");' .
					'if($h.length){var hv;if(cfg.hour12){var t12=nxtGfDtFrom24(p.h);hv=String(t12.disp);if($a.length){$a.val(t12.ap);}}else{hv=String(p.h);}$h.val(hv);}' .
					'if($m.length){$m.val(String(p.i));}' .
					'if(cfg.seconds&&$s.length){$s.val(String(p.s));}' .
				'}' .
				'function nxtGfTmReadSel($p,cfg){' .
					'var h,i,s,ap,i0=parseInt($p.find(".nxt-gf-tm-min").val(),10);if(isNaN(i0)){i0=0;}' .
					'i=i0;s=cfg.seconds?parseInt($p.find(".nxt-gf-tm-sec").val(),10):0;if(isNaN(s)){s=0;}' .
					'if(cfg.hour12){h=parseInt($p.find(".nxt-gf-tm-hour").val(),10)||0;ap=$p.find(".nxt-gf-tm-ampm").val()||"am";h=nxtGfDtTo24(h,ap,true);}else{h=parseInt($p.find(".nxt-gf-tm-hour").val(),10)||0;}' .
					'return{h:h,i:i,s:s};' .
				'}' .
				'function nxtGfTimeBuildPanel($,$wrap,$inp,cfg){' .
					'var $p=$wrap.find(".nxt-gf-time-panel");if(!$p.length){$p=$("<div class=\\"nxt-gf-time-panel\\" />").appendTo($wrap);}' .
					'var lb=cfg.i18n||{},h12=cfg.hour12,sec=cfg.seconds,html="",a,z;' .
					'html="<div class=\\"nxt-gf-time-panel-title\\">"+(lb.chooseTime||' . wp_json_encode( __( 'Choose Time', 'nexter-extension' ) ) . ')+"</div>";' .
					'html+="<div class=\\"nxt-gf-time-panel-body\\"><span class=\\"nxt-gf-tm-lbl\\">"+(lb.time||' . wp_json_encode( __( 'Time', 'nexter-extension' ) ) . ')+":</span>";' .
					'html+="<select class=\\"nxt-gf-input nxt-gf-tm-hour\\" aria-label=\\""+(lb.hour||' . wp_json_encode( __( 'Hour', 'nexter-extension' ) ) . ')+"\\">";' .
					'if(h12){for(a=1;a<=12;a++){html+="<option value=\\""+a+"\\">"+a+"</option>";}}else{for(a=0;a<24;a++){html+="<option value=\\""+a+"\\">"+nxtGfDtPad2(a)+"</option>";}}' .
					'html+="</select><span class=\\"nxt-gf-tm-sep\\">:</span><select class=\\"nxt-gf-input nxt-gf-tm-min\\" aria-label=\\""+(lb.minute||' . wp_json_encode( __( 'Minute', 'nexter-extension' ) ) . ')+"\\">";' .
					'for(z=0;z<60;z++){html+="<option value=\\""+z+"\\">"+nxtGfDtPad2(z)+"</option>";}' .
					'html+="</select>";' .
					'if(sec){html+="<span class=\\"nxt-gf-tm-sep\\">:</span><select class=\\"nxt-gf-input nxt-gf-tm-sec\\" aria-label=\\""+(lb.second||' . wp_json_encode( __( 'Second', 'nexter-extension' ) ) . ')+"\\">";for(z=0;z<60;z++){html+="<option value=\\""+z+"\\">"+nxtGfDtPad2(z)+"</option>";}html+="</select>";}' .
					'if(h12){html+="<select class=\\"nxt-gf-input nxt-gf-tm-ampm\\"><option value=\\"am\\">am</option><option value=\\"pm\\">pm</option></select>";}' .
					'html+="</div><div class=\\"nxt-gf-time-panel-actions\\"><button type=\\"button\\" class=\\"button nxt-gf-tm-now\\">"+(lb.now||' . wp_json_encode( __( 'Now', 'nexter-extension' ) ) . ')+"</button><button type=\\"button\\" class=\\"button button-primary nxt-gf-tm-select\\">"+(lb.select||' . wp_json_encode( __( 'Select', 'nexter-extension' ) ) . ')+"</button></div>";' .
					'$p.html(html);' .
					'var p0=nxtGfTmParse($inp.val(),cfg)||nxtGfTmNow();$inp.data("nxtTmParts",p0);nxtGfTmSyncSel($p,cfg,p0);' .
					'$p.off("change.nxtGfTm").on("change.nxtGfTm","select",function(){$inp.data("nxtTmParts",nxtGfTmReadSel($p,cfg));});' .
					'$p.off("click.nxtGfTm").on("click.nxtGfTm",".nxt-gf-tm-now",function(ev){ev.preventDefault();var tn=nxtGfTmNow();$inp.data("nxtTmParts",tn);nxtGfTmSyncSel($p,cfg,tn);}).on("click.nxtGfTm",".nxt-gf-tm-select",function(ev){ev.preventDefault();var cur=nxtGfTmReadSel($p,cfg);$inp.val(nxtGfTmFormat(cur,cfg));$p.hide();$(document).off("click.nxtGfTmDoc");});' .
				'}' .
				'function nxtGfTimeShow($,$inp){' .
					'var $wrap=$inp.closest(".nxt-gf-time-wrap");if(!$wrap.length){return;}' .
					'var cfg;try{cfg=JSON.parse($inp.attr("data-time-ui")||"{}");}catch(ex){cfg=null;}if(!cfg){return;}' .
					'nxtGfTimeBuildPanel($,$wrap,$inp,cfg);var $p=$wrap.find(".nxt-gf-time-panel");$p.show();' .
					'setTimeout(function(){$(document).off("click.nxtGfTmDoc").on("click.nxtGfTmDoc",function(ev){if(!$(ev.target).closest(".nxt-gf-time-wrap").length){$p.hide();$(document).off("click.nxtGfTmDoc");}});},0);' .
				'}' .
				'$(document).on("focusin mousedown",".nxt-gf-time-input",function(e){var $t=$(this);if(e.type==="mousedown"){e.preventDefault();}nxtGfTimeShow($,$t);});' .
			'});',
			'after'
		);
	}

	/**
	 * Render a single field group metabox.
	 *
	 * @param WP_Post $post    Post object.
	 * @param array   $metabox Metabox args.
	 */
	public function render_meta_box( $post, $metabox ) {
		if ( empty( $metabox['args']['nxt_gf_group'] ) || ! is_array( $metabox['args']['nxt_gf_group'] ) ) {
			return;
		}

		$group             = $metabox['args']['nxt_gf_group'];
		$fields            = isset( $group['fields'] ) ? (array) $group['fields'] : array();
		$label_placement   = isset( $group['label_placement'] ) ? $group['label_placement'] : 'top';
		$instruction_place = isset( $group['instruction_placement'] ) ? $group['instruction_placement'] : 'label';
		$group_key         = isset( $group['key'] ) ? $group['key'] : 'group_' . ( isset( $group['id'] ) ? (int) $group['id'] : 0 );

		// Scoped to this post, not just the group.
		wp_nonce_field( $this->group_nonce_action( 'post', $group_key, $post->ID ), 'nxt_gf_nonce_' . $group_key );

		$required_errors = $this->required_errors_for( 'post', $post->ID );

		echo '<div class="nxt-gf-metabox nxt-gf-style-' . esc_attr( $group['style'] ) . '">';

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name  = isset( $field['name'] ) ? $field['name'] : '';
			$label = isset( $field['label'] ) ? $field['label'] : '';
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';

			if ( '' === $name ) {
				continue;
			}

			$meta_key = nxt_build_meta_key( 'post', $group_key, $name );
			$value    = get_post_meta( $post->ID, $meta_key, true );
			if ( 'taxonomy' === $type ) {
				if ( ! metadata_exists( 'post', $post->ID, $meta_key ) && ! empty( $field['taxonomy_load_terms'] ) ) {
					$tax = isset( $field['taxonomy_name'] ) ? sanitize_key( $field['taxonomy_name'] ) : '';
					if ( $tax && taxonomy_exists( $tax ) ) {
						$term_ids = wp_get_post_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
						if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
							$app = isset( $field['taxonomy_appearance'] ) ? sanitize_key( $field['taxonomy_appearance'] ) : 'checkbox';
							if ( in_array( $app, array( 'radio', 'select' ), true ) ) {
								$value = (int) $term_ids[0];
							} else {
								$value = array_map( 'intval', $term_ids );
							}
						}
					}
				}
			} elseif ( ! metadata_exists( 'post', $post->ID, $meta_key ) && ! in_array( $type, array( 'date', 'datetime', 'time', 'select', 'checkbox' ), true ) ) {
				// Use field default value when no value has been saved yet (e.g.
				$default_val = isset( $field['default_value'] ) ? $field['default_value'] : '';
				if ( '' !== $default_val ) {
					$value = $default_val;
				}
			}
			$input_name  = 'nxt_gf[' . $group_key . '][' . $name . ']';
			$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
			$prepend     = isset( $field['prepend'] ) ? $field['prepend'] : '';
			$append      = isset( $field['append'] ) ? $field['append'] : '';
			$wrapper_w   = isset( $field['wrapper_width'] ) ? $field['wrapper_width'] : '';
			$wrapper_cls = isset( $field['wrapper_class'] ) ? $field['wrapper_class'] : '';
			$wrapper_id  = isset( $field['wrapper_id'] ) ? $field['wrapper_id'] : '';

			$classes     = array( 'nxt-gf-field-row', 'left' === $label_placement ? 'nxt-gf-labels-left' : 'nxt-gf-labels-top' );
			$field_error = $this->required_error_html( $required_errors, $group_key, $name );
			if ( '' !== $field_error ) {
				$classes[] = 'nxt-gf-field-has-error';
			}
			if ( ! empty( $wrapper_cls ) && is_string( $wrapper_cls ) ) {
				$classes[] = sanitize_html_class( $wrapper_cls );
			}
			$style_attr = '';
			if ( '' !== $wrapper_w ) {
				$width = (int) $wrapper_w;
				if ( $width > 0 ) {
					$style_attr = ' style="flex-basis:calc(' . $width . '% - 8px);"';
				}
			}

			$row_id_attr = '';
			if ( ! empty( $wrapper_id ) && is_string( $wrapper_id ) ) {
				$row_id_attr = ' id="' . esc_attr( $wrapper_id ) . '"';
			}

			echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $style_attr . $row_id_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $style_attr and $row_id_attr are built with esc_attr() above.
				. $this->field_conditional_attrs( $field, $name ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_conditional_attrs() escapes the name and the JSON with esc_attr().

			// 'left' is a placement, not a suppression — see the note in the term renderer.
			if ( '' !== $label ) {
				if ( 'left' === $label_placement ) {
					echo '<div class="nxt-gf-label-col">';
				}
				echo '<label class="nxt-gf-label" for="' . esc_attr( $meta_key ) . '">' . $this->field_label_html( $field, $label ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_label_html() escapes the label with esc_html().
				if ( 'label' === $instruction_place && ! empty( $field['instructions'] ) ) {
					echo '<p class="description nxt-gf-instruction">' . esc_html( $field['instructions'] ) . '</p>';
				}
				if ( 'left' === $label_placement ) {
					echo '</div>';
				}
			}

			$layout_class = ! empty( $field['layout'] ) ? ' nxt-gf-wrap-' . esc_attr( $field['layout'] ) : '';

			echo '<div class="nxt-gf-input-wrap nxt-gf-wrap-' . esc_attr( $type ) . esc_attr( $layout_class ) . '">';

			$this->render_field_input_common( $field, $meta_key, $input_name, $value, metadata_exists( 'post', $post->ID, $meta_key ) );

			if ( 'field' === $instruction_place && ! empty( $field['instructions'] ) ) {
				echo '<p class="description nxt-gf-instruction">' . esc_html( $field['instructions'] ) . '</p>';
			}
			echo $field_error; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in required_error_html().

			echo '</div>'; // .nxt-gf-input-wrap
			echo '</div>'; // .nxt-gf-field-row
		}

		echo '</div>'; // .nxt-gf-metabox
	}

	/**
	 * Save metabox values.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_post( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST['nxt_gf'] ) || ! is_array( $_POST['nxt_gf'] ) ) {
			return;
		}

		foreach ( $_POST['nxt_gf'] as $group_key => $fields ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Per-group nonce is verified two lines below; values are sanitized by sanitize_value_for_field().
			$nonce_name      = 'nxt_gf_nonce_' . $group_key;
			$submitted_nonce = empty( $_POST[ $nonce_name ] ) ? '' : wp_unslash( $_POST[ $nonce_name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A nonce is compared by wp_verify_nonce(), which is the validation.
			if ( '' === $submitted_nonce
				|| ! wp_verify_nonce( $submitted_nonce, $this->group_nonce_action( 'post', $group_key, $post_id ) )
			) {
				continue;
			}

			if ( ! is_array( $fields ) ) {
				continue;
			}

			$field_map = $this->get_top_level_field_map_for_group_key( $group_key );
			foreach ( $this->filter_submitted_fields( $fields, $field_map ) as $name => $raw ) {
				// Canonical builder shared with the read paths / field API so write and read
				// keys can never diverge (sanitize_key is applied to both key parts).
				$meta_key  = nxt_build_meta_key( 'post', $group_key, $name );
				$unslashed = wp_unslash( $raw );
				if ( isset( $field_map[ $name ] ) ) {
					$value = $this->sanitize_field_value_by_type( $unslashed, $field_map[ $name ] );
				} else {
					$value = $this->sanitize_deep( $unslashed );
				}

				$is_tax_field = isset( $field_map[ $name ] ) && 'taxonomy' === ( $field_map[ $name ]['type'] ?? '' );

				// Capture the field's previous selection BEFORE overwriting so taxonomy sync
				// can remove only the terms this field controlled (never native/Woo terms).
				$old_value = ( $is_tax_field && $post instanceof WP_Post ) ? get_post_meta( $post_id, $meta_key, true ) : null;

				if ( ! $this->persist_field_value( 'post', $post_id, $meta_key, $value, isset( $field_map[ $name ] ) ? $field_map[ $name ] : array(), $group_key ) ) {
					continue; // Value skipped (masked password, or a required field left empty).
				}

				if ( $is_tax_field && $post instanceof WP_Post ) {
					$this->sync_taxonomy_field_to_post( $post_id, $post, $field_map[ $name ], $value, $old_value );
				}
			}
		}

		$this->flush_required_notice( 'post', $post_id );
	}

	/**
	 * A field's label text with the required marker appended.
	 *
	 * @param array  $field Field config.
	 * @param string $label Label text (unescaped).
	 * @return string Escaped HTML.
	 */
	private function field_label_html( $field, $label ) {
		$html = esc_html( $label );
		if ( ! empty( $field['required'] ) ) {
			$html .= ' <span class="nxt-gf-required" aria-hidden="true">*</span>';
		}
		return $html;
	}

	/**
	 * Required fields that were submitted empty on the last save of this object, as [ "group_key|field_name"
	 * => label ].
	 *
	 * @param string $object_type post|term|user|comment.
	 * @param int    $object_id   Object ID.
	 * @return array<string,string>
	 */
	private function required_errors_for( $object_type, $object_id ) {
		$cache_key = $object_type . ':' . (int) $object_id;
		if ( ! empty( $this->required_errors_cache[ $cache_key ] ) ) {
			return $this->required_errors_cache[ $cache_key ];
		}
		$key  = 'nxt_gf_required_err_' . get_current_user_id() . '_' . $object_type . '_' . (int) $object_id;
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return array();
		}
		delete_transient( $key );
		$this->required_errors_cache[ $cache_key ] = $data;
		return $data;
	}

	/**
	 * Inline "this field is required" message for one field, or '' when it has no error.
	 *
	 * @param array  $errors    Map from required_errors_for().
	 * @param string $group_key Group key.
	 * @param string $name      Field name.
	 * @return string Escaped HTML.
	 */
	private function required_error_html( $errors, $group_key, $name ) {
		if ( empty( $errors[ $group_key . '|' . $name ] ) ) {
			return '';
		}
		return '<p class="nxt-gf-field-error" role="alert">'
			. esc_html__( 'This field is required.', 'nexter-extension' )
			. '</p>';
	}

	/**
	 * The `required` attribute for a field's control, or '' when it must not be emitted.
	 *
	 * @param array $field Field config.
	 * @return string ' required="required"' or ''.
	 */
	/**
	 * Wrapper attributes that let the browser evaluate a field's conditional logic.
	 *
	 * @param array  $field Field config.
	 * @param string $name  Field name.
	 * @return string Attribute string, leading space included.
	 */
	private function field_conditional_attrs( $field, $name ) {
		$attrs = ' data-nxt-field="' . esc_attr( $name ) . '"';
		if ( empty( $field['conditional_logic_enabled'] ) || empty( $field['conditional_logic'] ) ) {
			return $attrs;
		}
		$json = wp_json_encode( $field['conditional_logic'] );
		if ( false === $json ) {
			return $attrs;
		}
		return $attrs . ' data-nxt-cond="' . esc_attr( $json ) . '"';
	}

	/**
	 * Field required attr.
	 *
	 * @param mixed $field Value.
	 */
	private function field_required_attr( $field ) {
		if ( empty( $field['required'] ) ) {
			return '';
		}
		if ( ! empty( $field['conditional_logic_enabled'] ) && ! empty( $field['conditional_logic'] ) ) {
			return '';
		}
		return ' required="required"';
	}

	/**
	 * Required group fields that were submitted empty, as [ field name => label ].
	 *
	 * Shared by every server-side required check so they all agree on what "empty" means.
	 *
	 * @param string $post_root  Top-level POST key: nxt_gf | nxt_gf_term | nxt_gf_user | nxt_gf_comment.
	 * @param string $nonce_stub Nonce field prefix matching $post_root.
	 * @param string $nonce_act  Nonce action prefix.
	 * @param int    $object_id  Object the nonce is scoped to, or 0 when the action is unscoped.
	 * @return array<string,string>
	 */
	private function collect_empty_required_fields( $post_root, $nonce_stub, $nonce_act, $object_id = 0 ) {
		$missing = array();
		if ( empty( $_POST[ $post_root ] ) || ! is_array( $_POST[ $post_root ] ) ) {
			return $missing;
		}

		foreach ( $_POST[ $post_root ] as $group_key => $fields ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Per-group nonce is verified three lines below; values are sanitized by sanitize_value_for_field().
			$nonce_name = $nonce_stub . $group_key;
			$action     = $object_id ? $nonce_act . $group_key . '_post_' . (int) $object_id : $nonce_act . $group_key;
			if ( empty( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ $nonce_name ] ), $action ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A nonce is compared by wp_verify_nonce(), which is the validation.
				continue;
			}
			if ( ! is_array( $fields ) ) {
				continue;
			}

			$field_map = $this->get_top_level_field_map_for_group_key( $group_key );
			foreach ( $field_map as $name => $field ) {
				if ( empty( $field['required'] ) || nxt_field_type_is_locked( $field['type'] ?? '' ) ) {
					continue;
				}
				// A hidden conditional field is not "missing" — it was never asked for.
				if ( ! empty( $field['conditional_logic_enabled'] ) && ! empty( $field['conditional_logic'] ) ) {
					continue;
				}
				$raw   = array_key_exists( $name, $fields ) ? wp_unslash( $fields[ $name ] ) : '';
				$value = $this->sanitize_field_value_by_type( $raw, $field );
				if ( $this->value_is_empty_for_required( $value, $field ) ) {
					$label            = ( '' !== (string) ( $field['label'] ?? '' ) ) ? (string) $field['label'] : (string) $name;
					$missing[ $name ] = $label;
				}
			}
		}
		return $missing;
	}

	/**
	 * Block creation of a term whose required group fields were left empty.
	 *
	 * @param string $term     Term name.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string|WP_Error
	 */
	public function validate_required_on_term_insert( $term, $taxonomy ) {
		if ( empty( $_POST['nxt_gf_term'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; the per-group nonce is verified in the loop that follows.
			return $term;
		}
		$missing = $this->collect_empty_required_fields( 'nxt_gf_term', 'nxt_gf_term_nonce_', 'nxt_gf_term_save_' );
		if ( empty( $missing ) ) {
			return $term;
		}
		return new WP_Error(
			'nxt_gf_required_missing',
			sprintf(
				/* translators: %s: comma-separated list of required field labels. */
				__( 'These fields are required and cannot be left empty: %s', 'nexter-extension' ),
				implode( ', ', array_values( $missing ) )
			)
		);
	}

	/**
	 * Add validation errors for required group fields on user profile / add-user saves.
	 *
	 * @param WP_Error $errors Error collector.
	 * @return void
	 */
	public function validate_required_on_user_save( $errors ) {
		if ( ! $errors instanceof WP_Error || empty( $_POST['nxt_gf_user'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; the per-group nonce is verified in the loop that follows.
			return;
		}
		$missing = $this->collect_empty_required_fields( 'nxt_gf_user', 'nxt_gf_user_nonce_', 'nxt_gf_user_save_' );
		foreach ( $missing as $name => $label ) {
			$errors->add(
				'nxt_gf_required_' . sanitize_key( $name ),
				sprintf(
					/* translators: %s: the required field's label. */
					__( '<strong>Error</strong>: %s is required.', 'nexter-extension' ),
					esc_html( $label )
				)
			);
		}
	}

	/**
	 * Nonce action for one group on one object.
	 *
	 * @param string $object_type post (others keep their legacy unscoped action for now).
	 * @param string $group_key   Field group key.
	 * @param int    $object_id   Object the form belongs to.
	 * @return string
	 */
	private function group_nonce_action( $object_type, $group_key, $object_id ) {
		return 'nxt_gf_save_' . $group_key . '_' . $object_type . '_' . (int) $object_id;
	}

	/**
	 * Drop submitted names the group does not declare.
	 *
	 * @param array $fields    Submitted name => raw value pairs.
	 * @param array $field_map Declared top-level fields, keyed by name.
	 * @return array
	 */
	private function filter_submitted_fields( array $fields, array $field_map ) {
		// An unknown or empty group declares nothing, so nothing submitted under it is saved.
		return array_intersect_key( $fields, $field_map );
	}

	/**
	 * Whether a sanitized value counts as "empty" for the purposes of the required check.
	 *
	 * @param mixed $value Sanitized value.
	 * @param array $field Field config.
	 * @return bool
	 */
	private function value_is_empty_for_required( $value, array $field ) {
		// true_false sanitizes to a bool, and false is neither '' nor an empty array, so the generic test below missed it.
		if ( 'true_false' === (string) ( $field['type'] ?? '' ) ) {
			return ! $value;
		}

		// Deliberately NOT extended to number/range: those return '' when empty and a real 0 otherwise,
		// so a falsy test here would make a required field holding 0 impossible to save.
		return ( '' === $value || ( is_array( $value ) && empty( $value ) ) );
	}

	/**
	 * Write (or delete) one sanitized field value for any object type, applying the shared empty/default and
	 * required rules.
	 *
	 * @param string $object_type post|term|user|comment.
	 * @param int    $object_id   Object ID.
	 * @param string $meta_key    Full meta key.
	 * @param mixed  $value       Sanitized value.
	 * @param array  $field       Field config (may be empty for an unresolved field).
	 * @param string $group_key   Group key, used to scope the inline required-error message.
	 * @return bool True when the value was written or deleted; false when the write was skipped.
	 */
	private function persist_field_value( $object_type, $object_id, $meta_key, $value, array $field, $group_key = '' ) {
		// A Pro type without Pro renders no input, so never write or clear its stored value.
		if ( nxt_field_type_is_locked( $field['type'] ?? '' ) ) {
			return false;
		}

		$is_empty = ( '' === $value || ( is_array( $value ) && empty( $value ) ) );

		// Scoped to the required check on purpose.
		$is_empty_for_required = $this->value_is_empty_for_required( $value, $field );

		// #5: a password input always renders blank (masked), so an empty submission means
		// "unchanged" — never a clear. Skip it so the stored value is preserved instead of wiped.
		if ( $is_empty && 'password' === (string) ( $field['type'] ?? '' ) ) {
			return false;
		}

		// Last line of defence for the object types WordPress won't let us abort (posts, and term *updates*):
		// refuse the empty write, keep the existing value, and tell the user.
		if ( $is_empty_for_required && ! empty( $field['required'] ) ) {
			$label = '' !== (string) ( $field['label'] ?? '' ) ? (string) $field['label'] : (string) ( $field['name'] ?? '' );
			// Flagged whether or not a value was already stored: the point is to tell the user the field was left
			// blank.
			$this->required_blocked[ $group_key . '|' . (string) ( $field['name'] ?? '' ) ] = $label;
			if ( metadata_exists( $object_type, (int) $object_id, $meta_key ) ) {
				return false;
			}
		}

		if ( $is_empty ) {
			// MB-23: if the field defines a default, an empty submission is an INTENTIONAL clear — persist it (empty
			// string/array) so metadata_exists() stays true and the default doesn't re-assert on the next render.
			$fdefault    = isset( $field['default_value'] ) ? $field['default_value'] : '';
			$has_default = is_array( $fdefault ) ? ! empty( $fdefault ) : ( '' !== (string) $fdefault );
			if ( $has_default ) {
				update_metadata( $object_type, (int) $object_id, $meta_key, $value );
			} else {
				delete_metadata( $object_type, (int) $object_id, $meta_key );
			}
			return true;
		}

		update_metadata( $object_type, (int) $object_id, $meta_key, $value );
		return true;
	}

	/**
	 * Stash any required-field errors collected during this save so the next render of the SAME object can
	 * show them inline, under the fields they belong to.
	 *
	 * @param string $object_type post|term|user|comment.
	 * @param int    $object_id   Object ID.
	 * @return void
	 */
	private function flush_required_notice( $object_type = '', $object_id = 0 ) {
		if ( empty( $this->required_blocked ) ) {
			return;
		}
		$errors                 = $this->required_blocked;
		$this->required_blocked = array();
		if ( '' === $object_type || ! $object_id ) {
			return;
		}
		set_transient(
			'nxt_gf_required_err_' . get_current_user_id() . '_' . $object_type . '_' . (int) $object_id,
			$errors,
			60
		);
	}

	/**
	 * Register hooks for taxonomy term add/edit screens when relevant.
	 */
	public function maybe_register_taxonomy_hooks() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'edit-tags', 'term' ), true ) ) {
			return;
		}

		// Same as $post_type above: WP_Screen declares this as a string property.
		$taxonomy = (string) $screen->taxonomy;
		if ( ! $taxonomy ) {
			return;
		}

		add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_taxonomy_add_fields' ) );
		add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_taxonomy_edit_fields' ), 10, 2 );
	}

	/**
	 * Render field groups on taxonomy "add new term" screen.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function render_taxonomy_add_fields( $taxonomy ) {
		$context = array(
			'screen'   => 'term',
			'taxonomy' => $taxonomy,
		);

		$groups = Nxt_Content_Types_Field_Groups_Location::get_matching_groups( $context );
		if ( empty( $groups ) ) {
			return;
		}

		foreach ( $groups as $group ) {
			$group_id          = isset( $group['id'] ) ? (int) $group['id'] : 0;
			$title             = isset( $group['title'] ) ? $group['title'] : '';
			$style             = isset( $group['style'] ) ? $group['style'] : 'default';
			$fields            = isset( $group['fields'] ) ? (array) $group['fields'] : array();
			$group_key         = isset( $group['key'] ) ? $group['key'] : 'group_' . $group_id;
			$label_placement   = isset( $group['label_placement'] ) ? $group['label_placement'] : 'top';
			$instruction_place = isset( $group['instruction_placement'] ) ? $group['instruction_placement'] : 'label';

			wp_nonce_field( 'nxt_gf_term_save_' . $group_key, 'nxt_gf_term_nonce_' . $group_key );

			// The add-term screen has no term to key errors against, and an empty required field aborts the insert anyway.
			$required_errors = array();

			echo '<div class="form-field term-nxt-gf-group">';
			if ( '' !== $title ) {
				echo '<h3>' . esc_html( $title ) . '</h3>';
			}
			echo '<div class="nxt-gf-metabox nxt-gf-style-' . esc_attr( $style ) . '">';

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$name  = isset( $field['name'] ) ? $field['name'] : '';
				$label = isset( $field['label'] ) ? $field['label'] : '';
				$type  = isset( $field['type'] ) ? $field['type'] : 'text';

				if ( '' === $name ) {
					continue;
				}

				$meta_key    = nxt_build_meta_key( 'term', $group_key, $name );
				$input_name  = 'nxt_gf_term[' . $group_key . '][' . $name . ']';
				$value       = ( 'taxonomy' !== $type && ! in_array( $type, array( 'date', 'datetime', 'time' ), true ) && isset( $field['default_value'] ) ) ? $field['default_value'] : '';
				$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
				$prepend     = isset( $field['prepend'] ) ? $field['prepend'] : '';
				$append      = isset( $field['append'] ) ? $field['append'] : '';

				$field_error = $this->required_error_html( $required_errors, $group_key, $name );
				echo '<div class="nxt-gf-field-row ' . esc_attr( 'left' === $label_placement ? 'nxt-gf-labels-left' : 'nxt-gf-labels-top' ) . ( '' !== $field_error ? ' nxt-gf-field-has-error' : '' ) . '"' . $this->field_conditional_attrs( $field, $name ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $field_error comes from required_error_html(), which escapes its message.

				// 'left' is a placement, not a suppression: the old `'top' === $label_placement` guard meant choosing
				// "Left aligned" rendered no label at all.
				if ( '' !== $label ) {
					if ( 'left' === $label_placement ) {
						echo '<div class="nxt-gf-label-col">';
					}
					echo '<label class="nxt-gf-label" for="' . esc_attr( $meta_key ) . '">' . $this->field_label_html( $field, $label ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_label_html() escapes the label with esc_html().
					if ( 'label' === $instruction_place && ! empty( $field['instructions'] ) ) {
						echo '<p class="description nxt-gf-instruction">' . esc_html( $field['instructions'] ) . '</p>';
					}
					if ( 'left' === $label_placement ) {
						echo '</div>';
					}
				}

				echo '<div class="nxt-gf-input-wrap">';

				$this->render_field_input_common( $field, $meta_key, $input_name, $value, false );

				if ( 'field' === $instruction_place && ! empty( $field['instructions'] ) ) {
					echo '<p class="description nxt-gf-instruction">' . esc_html( $field['instructions'] ) . '</p>';
				}
				echo $field_error; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in required_error_html().

				echo '</div>'; // .nxt-gf-input-wrap
				echo '</div>'; // .nxt-gf-field-row
			}

			echo '</div>'; // .nxt-gf-metabox
			echo '</div>'; // .form-field
		}
	}

	/**
	 * Render field groups on taxonomy "edit term" screen.
	 *
	 * @param WP_Term $term     Term object.
	 * @param string  $taxonomy Taxonomy slug.
	 */
	public function render_taxonomy_edit_fields( $term, $taxonomy ) {
		$term_id = (int) $term->term_id;
		$context = array(
			'screen'   => 'term',
			'taxonomy' => $taxonomy,
			'term_id'  => $term_id,
		);

		$groups = Nxt_Content_Types_Field_Groups_Location::get_matching_groups( $context );
		if ( empty( $groups ) ) {
			return;
		}

		foreach ( $groups as $group ) {
			$group_id          = isset( $group['id'] ) ? (int) $group['id'] : 0;
			$title             = isset( $group['title'] ) ? $group['title'] : '';
			$style             = isset( $group['style'] ) ? $group['style'] : 'default';
			$fields            = isset( $group['fields'] ) ? (array) $group['fields'] : array();
			$group_key         = isset( $group['key'] ) ? $group['key'] : 'group_' . $group_id;
			$label_placement   = isset( $group['label_placement'] ) ? $group['label_placement'] : 'top';
			$instruction_place = isset( $group['instruction_placement'] ) ? $group['instruction_placement'] : 'label';

			wp_nonce_field( 'nxt_gf_term_save_' . $group_key, 'nxt_gf_term_nonce_' . $group_key );

			$required_errors = $this->required_errors_for( 'term', $term_id );

			echo '<tr class="form-field term-nxt-gf-group">';
			echo '<th scope="row"><label>' . esc_html( $title ) . '</label></th>';
			echo '<td><div class="nxt-gf-metabox nxt-gf-style-' . esc_attr( $style ) . '">';

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$name  = isset( $field['name'] ) ? $field['name'] : '';
				$label = isset( $field['label'] ) ? $field['label'] : '';
				$type  = isset( $field['type'] ) ? $field['type'] : 'text';

				if ( '' === $name ) {
					continue;
				}

				$meta_key   = nxt_build_meta_key( 'term', $group_key, $name );
				$input_name = 'nxt_gf_term[' . $group_key . '][' . $name . ']';
				$value      = get_term_meta( $term->term_id, $meta_key, true );
				if ( 'taxonomy' !== $type && ! metadata_exists( 'term', $term->term_id, $meta_key ) ) {
					$default_val = isset( $field['default_value'] ) ? $field['default_value'] : '';
					if ( '' !== $default_val ) {
						$value = $default_val;
					}
				}
				$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
				$prepend     = isset( $field['prepend'] ) ? $field['prepend'] : '';
				$append      = isset( $field['append'] ) ? $field['append'] : '';

				$field_error = $this->required_error_html( $required_errors, $group_key, $name );
				echo '<div class="nxt-gf-field-row ' . esc_attr( 'left' === $label_placement ? 'nxt-gf-labels-left' : 'nxt-gf-labels-top' ) . ( '' !== $field_error ? ' nxt-gf-field-has-error' : '' ) . '"' . $this->field_conditional_attrs( $field, $name ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $field_error comes from required_error_html(), which escapes its message.

				// 'left' is a placement, not a suppression: the old `'top' === $label_placement` guard meant choosing
				// "Left aligned" rendered no label at all.
				if ( '' !== $label ) {
					if ( 'left' === $label_placement ) {
						echo '<div class="nxt-gf-label-col">';
					}
					echo '<label class="nxt-gf-label" for="' . esc_attr( $meta_key ) . '">' . $this->field_label_html( $field, $label ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_label_html() escapes the label with esc_html().
					if ( 'label' === $instruction_place && ! empty( $field['instructions'] ) ) {
						echo '<p class="description nxt-gf-instruction">' . esc_html( $field['instructions'] ) . '</p>';
					}
					if ( 'left' === $label_placement ) {
						echo '</div>';
					}
				}

				echo '<div class="nxt-gf-input-wrap">';

				$this->render_field_input_common( $field, $meta_key, $input_name, $value, metadata_exists( 'term', (int) $term->term_id, $meta_key ) );

				if ( 'field' === $instruction_place && ! empty( $field['instructions'] ) ) {
					echo '<p class="description nxt-gf-instruction">' . esc_html( $field['instructions'] ) . '</p>';
				}
				echo $field_error; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in required_error_html().

				echo '</div>'; // .nxt-gf-input-wrap
				echo '</div>'; // .nxt-gf-field-row
			}

			echo '</div></td></tr>'; // .nxt-gf-metabox and row.
		}
	}

	/**
	 * Save taxonomy term field group values.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function save_taxonomy_fields( $term_id, $tt_id, $taxonomy = '' ) {
		unset( $tt_id );
		unset( $taxonomy );

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		if ( empty( $_POST['nxt_gf_term'] ) || ! is_array( $_POST['nxt_gf_term'] ) ) {
			return;
		}

		foreach ( $_POST['nxt_gf_term'] as $group_key => $fields ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Per-group nonce is verified two lines below; values are sanitized by sanitize_value_for_field().
			$nonce_name = 'nxt_gf_term_nonce_' . $group_key;
			if ( empty( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ $nonce_name ] ), 'nxt_gf_term_save_' . $group_key ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A nonce is compared by wp_verify_nonce(), which is the validation.
				continue;
			}

			if ( ! is_array( $fields ) ) {
				continue;
			}

			$field_map = $this->get_top_level_field_map_for_group_key( $group_key );
			foreach ( $this->filter_submitted_fields( $fields, $field_map ) as $name => $raw ) {
				$meta_key = nxt_build_meta_key( 'term', $group_key, $name );

				$unslashed = wp_unslash( $raw );
				if ( isset( $field_map[ $name ] ) ) {
					$value = $this->sanitize_field_value_by_type( $unslashed, $field_map[ $name ] );
				} else {
					$value = $this->sanitize_deep( $unslashed );
				}

				$this->persist_field_value( 'term', $term_id, $meta_key, $value, isset( $field_map[ $name ] ) ? $field_map[ $name ] : array(), $group_key );
			}
		}

		$this->flush_required_notice( 'term', $term_id );
	}

	/**
	 * Save field values attached to a comment.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function save_comment_fields( $comment_id ) {
		$comment_id = (int) $comment_id;
		if ( ! $comment_id || ! current_user_can( 'edit_comment', $comment_id ) ) {
			return;
		}

		if ( empty( $_POST['nxt_gf_comment'] ) || ! is_array( $_POST['nxt_gf_comment'] ) ) {
			return;
		}

		foreach ( $_POST['nxt_gf_comment'] as $group_key => $fields ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Per-group nonce is verified two lines below; values are sanitized by sanitize_value_for_field().
			$nonce_name = 'nxt_gf_comment_nonce_' . $group_key;
			if ( empty( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ $nonce_name ] ), 'nxt_gf_comment_save_' . $group_key ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A nonce is compared by wp_verify_nonce(), which is the validation.
				continue;
			}
			if ( ! is_array( $fields ) ) {
				continue;
			}

			$field_map = $this->get_top_level_field_map_for_group_key( $group_key );
			foreach ( $this->filter_submitted_fields( $fields, $field_map ) as $name => $raw ) {
				$meta_key  = nxt_build_meta_key( 'comment', $group_key, $name );
				$unslashed = wp_unslash( $raw );
				if ( isset( $field_map[ $name ] ) ) {
					$value = $this->sanitize_field_value_by_type( $unslashed, $field_map[ $name ] );
				} else {
					$value = $this->sanitize_deep( $unslashed );
				}

				$this->persist_field_value( 'comment', $comment_id, $meta_key, $value, isset( $field_map[ $name ] ) ? $field_map[ $name ] : array(), $group_key );
			}
		}

		$this->flush_required_notice( 'comment', $comment_id );
	}

	/**
	 * Register metaboxes for field groups that target the comment edit screen.
	 *
	 * @param WP_Comment $comment Comment being edited.
	 */
	public function register_comment_meta_boxes( $comment ) {
		if ( ! $comment instanceof WP_Comment ) {
			return;
		}
		$post_type = get_post_type( (int) $comment->comment_post_ID );
		$groups    = Nxt_Content_Types_Field_Groups_Location::get_matching_groups(
			array(
				'screen'     => 'comment',
				'comment_id' => (int) $comment->comment_ID,
				'post_type'  => $post_type ? $post_type : '',
			)
		);
		foreach ( $groups as $group ) {
			if ( empty( $group['fields'] ) ) {
				continue;
			}
			add_meta_box(
				'nxt_gf_comment_' . $group['key'],
				$group['title'],
				array( $this, 'render_comment_meta_box' ),
				'comment',
				'normal',
				'default',
				array( 'nxt_gf_group' => $group )
			);
		}
	}

	/**
	 * Render one field group on the comment edit screen.
	 *
	 * @param WP_Comment $comment Comment being edited.
	 * @param array      $metabox Metabox args.
	 */
	public function render_comment_meta_box( $comment, $metabox ) {
		if ( empty( $metabox['args']['nxt_gf_group'] ) || ! is_array( $metabox['args']['nxt_gf_group'] ) ) {
			return;
		}
		$group     = $metabox['args']['nxt_gf_group'];
		$group_key = isset( $group['key'] ) ? $group['key'] : 'group_' . (int) ( $group['id'] ?? 0 );
		$fields    = isset( $group['fields'] ) ? (array) $group['fields'] : array();

		wp_nonce_field( 'nxt_gf_comment_save_' . $group_key, 'nxt_gf_comment_nonce_' . $group_key );

		echo '<div class="nxt-gf-metabox nxt-gf-style-' . esc_attr( isset( $group['style'] ) ? $group['style'] : 'default' ) . '">';
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}
			$name     = $field['name'];
			$meta_key = nxt_build_meta_key( 'comment', $group_key, $name );
			$value    = get_comment_meta( (int) $comment->comment_ID, $meta_key, true );
			if ( ! metadata_exists( 'comment', (int) $comment->comment_ID, $meta_key )
				&& isset( $field['default_value'] ) && '' !== $field['default_value']
			) {
				$value = $field['default_value'];
			}
			echo '<div class="nxt-gf-field-row"' . $this->field_conditional_attrs( $field, $name ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_conditional_attrs() escapes the name and the JSON with esc_attr().
			printf(
				'<label class="nxt-gf-label" for="%1$s">%2$s</label>',
				esc_attr( $meta_key ),
				$this->field_label_html( $field, isset( $field['label'] ) ? $field['label'] : $name ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_label_html() escapes the label with esc_html().
			);
			$this->render_field_input_common( $field, $meta_key, 'nxt_gf_comment[' . $group_key . '][' . $name . ']', $value, metadata_exists( 'comment', (int) $comment->comment_ID, $meta_key ) );
			if ( ! empty( $field['instructions'] ) ) {
				printf( '<p class="description">%s</p>', esc_html( $field['instructions'] ) );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render field groups on user-related screens (profile, add user, register).
	 *
	 * @param mixed $arg WP_User|int|string|null Depending on hook.
	 */
	public function render_user_profile_fields( $arg = null ) {
		$action    = current_action();
		$user_id   = 0;
		$user_role = '';
		$user_form = 'edit';

		if ( in_array( $action, array( 'show_user_profile', 'edit_user_profile' ), true ) ) {
			$user = ( $arg instanceof WP_User ) ? $arg : get_user_by( 'id', (int) $arg );
			if ( ! $user ) {
				return;
			}
			$user_id   = $user->ID;
			$roles     = is_array( $user->roles ) ? $user->roles : array();
			$user_role = ! empty( $roles ) ? reset( $roles ) : '';
			$user_form = 'edit';
		} elseif ( 'user_new_form' === $action ) {
			// Add new user screen (admin).
			$user_form = 'add';
		} elseif ( 'register_form' === $action ) {
			// Front-end register form.
			$user_form = 'register';
		} else {
			return;
		}

		// Shared builder again, so these screens agree with nxt_field_value().
		// user_role is the EDITED user's role; the viewer's own comes back as current_user_role.
		$context = nxt_build_context_for_object(
			'user',
			$user_id,
			array(
				'screen'    => 'user',
				'user_role' => $user_role,
				'user_form' => $user_form,
			)
		);

		$groups = Nxt_Content_Types_Field_Groups_Location::get_matching_groups( $context );

		if ( empty( $groups ) ) {
			return;
		}

		echo '<table class="form-table" role="presentation">';

		foreach ( $groups as $group ) {
			$group_id          = isset( $group['id'] ) ? (int) $group['id'] : 0;
			$title             = isset( $group['title'] ) ? $group['title'] : '';
			$style             = isset( $group['style'] ) ? $group['style'] : 'default';
			$fields            = isset( $group['fields'] ) ? (array) $group['fields'] : array();
			$group_key         = isset( $group['key'] ) ? $group['key'] : 'group_' . $group_id;
			$label_placement   = isset( $group['label_placement'] ) ? $group['label_placement'] : 'top';
			$instruction_place = isset( $group['instruction_placement'] ) ? $group['instruction_placement'] : 'label';

			wp_nonce_field( 'nxt_gf_user_save_' . $group_key, 'nxt_gf_user_nonce_' . $group_key );

			$required_errors = $user_id ? $this->required_errors_for( 'user', $user_id ) : array();

			echo '<tr class="nxt-gf-user-group">';
			echo '<th scope="row"><label>' . esc_html( $title ) . '</label></th>';
			echo '<td><div class="nxt-gf-metabox nxt-gf-style-' . esc_attr( $style ) . '">';

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$name  = isset( $field['name'] ) ? $field['name'] : '';
				$label = isset( $field['label'] ) ? $field['label'] : '';

				if ( '' === $name ) {
					continue;
				}

				$meta_key   = nxt_build_meta_key( 'user', $group_key, $name );
				$input_name = 'nxt_gf_user[' . $group_key . '][' . $name . ']';
				$value      = '';
				$ftype      = isset( $field['type'] ) ? $field['type'] : 'text';
				if ( $user_id ) {
					$value = get_user_meta( $user_id, $meta_key, true );
					if ( 'taxonomy' !== $ftype && ! metadata_exists( 'user', $user_id, $meta_key ) ) {
						$default_val = isset( $field['default_value'] ) ? $field['default_value'] : '';
						if ( '' !== $default_val ) {
							$value = $default_val;
						}
					}
					// No user yet (add/register forms): use default value only.
				} elseif ( 'taxonomy' !== $ftype ) {
					$default_val = isset( $field['default_value'] ) ? $field['default_value'] : '';
					if ( '' !== $default_val ) {
						$value = $default_val;
					}
				}

				$field_error = $this->required_error_html( $required_errors, $group_key, $name );
				echo '<div class="nxt-gf-field-row ' . esc_attr( 'left' === $label_placement ? 'nxt-gf-labels-left' : 'nxt-gf-labels-top' ) . ( '' !== $field_error ? ' nxt-gf-field-has-error' : '' ) . '"' . $this->field_conditional_attrs( $field, $name ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $field_error comes from required_error_html(), which escapes its message.

				// 'left' is a placement, not a suppression: the old `'top' === $label_placement` guard meant choosing
				// "Left aligned" rendered no label at all.
				if ( '' !== $label ) {
					if ( 'left' === $label_placement ) {
						echo '<div class="nxt-gf-label-col">';
					}
					echo '<label class="nxt-gf-label" for="' . esc_attr( $meta_key ) . '">' . $this->field_label_html( $field, $label ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_label_html() escapes the label with esc_html().
					if ( 'label' === $instruction_place && ! empty( $field['instructions'] ) ) {
						echo '<p class="description nxt-gf-instruction">' . esc_html( $field['instructions'] ) . '</p>';
					}
					if ( 'left' === $label_placement ) {
						echo '</div>';
					}
				}

				echo '<div class="nxt-gf-input-wrap">';

				$this->render_field_input_common( $field, $meta_key, $input_name, $value, ( $user_id && metadata_exists( 'user', $user_id, $meta_key ) ) );

				if ( 'field' === $instruction_place && ! empty( $field['instructions'] ) ) {
					echo '<p class="description nxt-gf-instruction">' . esc_html( $field['instructions'] ) . '</p>';
				}
				echo $field_error; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in required_error_html().

				echo '</div>'; // .nxt-gf-input-wrap
				echo '</div>'; // .nxt-gf-field-row
			}

			echo '</div></td></tr>'; // .nxt-gf-metabox and row.
		}

		echo '</table>';
	}

	/**
	 * Save user profile field group values.
	 *
	 * @param int $user_id User ID.
	 */
	public function save_user_profile_fields( $user_id ) {
		$action = current_action();

		// For profile / edit-user screens, enforce capability; for user_register,
		// allow saving even if the current user is not yet logged in (front-end registration).
		if ( 'user_register' !== $action && ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( empty( $_POST['nxt_gf_user'] ) || ! is_array( $_POST['nxt_gf_user'] ) ) {
			return;
		}

		// During user_register, only allow groups matching the form that rendered them: admin Add New User = add, front end = register.
		$allowed_group_keys = null;
		if ( 'user_register' === $action ) {
			$allowed_group_keys = array();
			$register_groups    = Nxt_Content_Types_Field_Groups_Location::get_matching_groups(
				array(
					'screen'    => 'user',
					'user_form' => ( is_admin() && current_user_can( 'create_users' ) ) ? 'add' : 'register',
				)
			);
			foreach ( $register_groups as $reg_group ) {
				$gk                        = isset( $reg_group['key'] ) && '' !== $reg_group['key']
					? $reg_group['key']
					: 'group_' . ( isset( $reg_group['id'] ) ? (int) $reg_group['id'] : 0 );
				$allowed_group_keys[ $gk ] = true;
			}
		}

		foreach ( $_POST['nxt_gf_user'] as $group_key => $fields ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Per-group nonce is verified six lines below; values are sanitized by sanitize_value_for_field().
			if ( null !== $allowed_group_keys && ! isset( $allowed_group_keys[ $group_key ] ) ) {
				continue;
			}

			$nonce_name = 'nxt_gf_user_nonce_' . $group_key;
			if ( empty( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ $nonce_name ] ), 'nxt_gf_user_save_' . $group_key ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A nonce is compared by wp_verify_nonce(), which is the validation.
				continue;
			}

			if ( ! is_array( $fields ) ) {
				continue;
			}

			$field_map = $this->get_top_level_field_map_for_group_key( $group_key );
			foreach ( $this->filter_submitted_fields( $fields, $field_map ) as $name => $raw ) {
				$meta_key = nxt_build_meta_key( 'user', $group_key, $name );

				$unslashed = wp_unslash( $raw );
				if ( isset( $field_map[ $name ] ) ) {
					$value = $this->sanitize_field_value_by_type( $unslashed, $field_map[ $name ] );
				} else {
					$value = $this->sanitize_deep( $unslashed );
				}

				$this->persist_field_value( 'user', $user_id, $meta_key, $value, isset( $field_map[ $name ] ) ? $field_map[ $name ] : array(), $group_key );
			}
		}

		$this->flush_required_notice( 'user', $user_id );
	}

	/**
	 * Common renderer for a single field input across post and term contexts.
	 *
	 * @param array  $field        Field configuration.
	 * @param string $meta_key     Meta key / input id.
	 * @param string $input_name   Input name attribute.
	 * @param mixed  $value        Current value.
	 * @param bool   $value_exists Whether a value is already stored for this field on the object
	 *                             being edited. The caller owns this flag because the renderer is
	 *                             shared by the post / term / comment / user / repeater-row
	 *                             contexts and cannot look the meta up itself.
	 */
	private function render_field_input_common( $field, $meta_key, $input_name, $value, $value_exists = false ) {
		$type        = isset( $field['type'] ) ? $field['type'] : 'text';
		$required    = $this->field_required_attr( $field );
		$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
		$prepend     = isset( $field['prepend'] ) ? $field['prepend'] : '';
		$append      = isset( $field['append'] ) ? $field['append'] : '';

		$has_prepend_append = ( '' !== $prepend || '' !== $append );

		// Scalar inputs can't render an array value (e.g.
		$scalar_value = is_array( $value ) ? '' : $value;

		// A type rendered elsewhere (Nexter Pro) echoes its markup and returns true.
		if ( apply_filters( 'nxt/render_field_input', false, $field, $meta_key, $input_name, $value, $value_exists, $this ) ) {
			return;
		}

		switch ( $type ) {
			case 'textarea':
				$rows = isset( $field['rows'] ) ? max( 1, (int) $field['rows'] ) : 8;
				printf(
					'<textarea id="%1$s" name="%2$s" class="nxt-gf-input" rows="%5$d" placeholder="%4$s"' . $required . '>%3$s</textarea>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $required is a literal attribute constant; the printf args are escaped below.
					esc_attr( $meta_key ),
					esc_attr( $input_name ),
					esc_textarea( $scalar_value ),
					esc_attr( $placeholder ),
					$rows // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $rows is an integer from absint().
				);
				break;

			case 'number':
				$min       = isset( $field['min'] ) && '' !== $field['min'] ? $field['min'] : '';
				$max       = isset( $field['max'] ) && '' !== $field['max'] ? $field['max'] : '';
				$step      = isset( $field['step'] ) && '' !== $field['step'] ? $field['step'] : '';
				$min_attr  = '' !== $min ? ' min="' . esc_attr( $min ) . '"' : '';
				$max_attr  = '' !== $max ? ' max="' . esc_attr( $max ) . '"' : '';
				$step_attr = '' !== $step ? ' step="' . esc_attr( $step ) . '"' : '';
				if ( $has_prepend_append ) {
					echo '<div class="nxt-gf-input-group">';
				}
				if ( '' !== $prepend ) {
					echo '<span class="nxt-gf-prepend">' . esc_html( $prepend ) . '</span>';
				}
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" class="nxt-gf-input' . ( $has_prepend_append ? ' nxt-gf-input-in-group' : '' ) . '" placeholder="%4$s"%5$s%6$s%7$s />',
					esc_attr( $meta_key ),
					esc_attr( $input_name ),
					esc_attr( $scalar_value ),
					esc_attr( $placeholder ),
					$min_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $min_attr is built with esc_attr() above.
					$max_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $max_attr is built with esc_attr() above.
					$step_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $step_attr is built with esc_attr() above.
				);
				if ( '' !== $append ) {
					echo '<span class="nxt-gf-append">' . esc_html( $append ) . '</span>';
				}
				if ( $has_prepend_append ) {
					echo '</div>';
				}
				break;

			case 'range':
				$min       = isset( $field['min'] ) && '' !== $field['min'] ? $field['min'] : '';
				$max       = isset( $field['max'] ) && '' !== $field['max'] ? $field['max'] : '';
				$step      = isset( $field['step'] ) && '' !== $field['step'] ? $field['step'] : '';
				$min_attr  = '' !== $min ? ' min="' . esc_attr( $min ) . '"' : '';
				$max_attr  = '' !== $max ? ' max="' . esc_attr( $max ) . '"' : '';
				$step_attr = '' !== $step ? ' step="' . esc_attr( $step ) . '"' : '';
				if ( $has_prepend_append ) {
					echo '<div class="nxt-gf-input-group">';
				}
				if ( '' !== $prepend ) {
					echo '<span class="nxt-gf-prepend">' . esc_html( $prepend ) . '</span>';
				}
				echo '<div class="nxt-gf-range-wrap" style="display:flex;align-items:center;gap:0.75em;flex:1;min-width:0;">';
				printf(
					'<input type="range" id="%1$s" name="%2$s" value="%3$s" class="nxt-gf-input nxt-gf-range-slider"' . ( $has_prepend_append ? ' nxt-gf-input-in-group' : '' ) . ' style="flex:1;min-width:0;"%5$s%6$s%7$s />',
					esc_attr( $meta_key ),
					esc_attr( $input_name ),
					esc_attr( $scalar_value ),
					esc_attr( $placeholder ),
					$min_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $min_attr is built with esc_attr() above.
					$max_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $max_attr is built with esc_attr() above.
					$step_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $step_attr is built with esc_attr() above.
				);
				printf(
					'<input type="number" id="%1$s_number" value="%2$s" class="nxt-gf-input nxt-gf-range-number"' . ( $has_prepend_append ? ' nxt-gf-input-in-group' : '' ) . ' placeholder="%3$s"%4$s%5$s%6$s style="width:5em;flex:0 0 auto;" aria-label="' . esc_attr__( 'Value', 'nexter-extension' ) . '" />',
					esc_attr( $meta_key ),
					esc_attr( $scalar_value ),
					esc_attr( $placeholder ),
					$min_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $min_attr is built with esc_attr() above.
					$max_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $max_attr is built with esc_attr() above.
					$step_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $step_attr is built with esc_attr() above.
				);
				echo '</div>';
				if ( '' !== $append ) {
					echo '<span class="nxt-gf-append">' . esc_html( $append ) . '</span>';
				}
				if ( $has_prepend_append ) {
					echo '</div>';
				}
				break;

			case 'date':
				$display_fmt = isset( $field['date_display_format'] ) ? (string) $field['date_display_format'] : 'd/m/Y';
				if ( 'custom' === $display_fmt ) {
					$display_fmt = isset( $field['date_custom_display_format'] ) && '' !== trim( (string) $field['date_custom_display_format'] ) ? (string) $field['date_custom_display_format'] : 'd/m/Y';
				}
				$return_fmt = isset( $field['date_return_format'] ) && '' !== trim( (string) $field['date_return_format'] ) ? (string) $field['date_return_format'] : 'd/m/Y';
				$week_start = isset( $field['date_week_starts'] ) ? max( 0, min( 6, (int) $field['date_week_starts'] ) ) : 1;
				$is_today   = ! empty( $field['date_default_today'] );
				$shown      = $this->normalize_date_value_for_display( $value, $display_fmt, $return_fmt );
				if ( '' === $shown && $is_today ) {
					$shown = wp_date( $display_fmt, current_time( 'timestamp' ) );
				}
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="nxt-gf-input nxt-gf-date-input" autocomplete="off" data-display-format="%4$s" data-return-format="%5$s" data-week-start="%6$d" />',
					esc_attr( $meta_key ),
					esc_attr( $input_name ),
					esc_attr( $shown ),
					esc_attr( $display_fmt ),
					esc_attr( $return_fmt ),
					(int) $week_start
				);
				break;

			case 'datetime':
				$display_fmt = isset( $field['datetime_display_format'] ) ? (string) $field['datetime_display_format'] : 'd/m/Y g:i a';
				if ( 'custom' === $display_fmt ) {
					$display_fmt = isset( $field['datetime_custom_display_format'] ) && '' !== trim( (string) $field['datetime_custom_display_format'] ) ? (string) $field['datetime_custom_display_format'] : 'd/m/Y g:i a';
				}
				$return_fmt = isset( $field['datetime_return_format'] ) && '' !== trim( (string) $field['datetime_return_format'] ) ? (string) $field['datetime_return_format'] : 'd/m/Y g:i a';
				$week_start = isset( $field['datetime_week_starts'] ) ? max( 0, min( 6, (int) $field['datetime_week_starts'] ) ) : 1;
				$is_today   = ! empty( $field['datetime_default_today'] );
				$shown      = $this->normalize_datetime_value_for_display( $value, $display_fmt, $return_fmt );
				if ( '' === $shown && $is_today ) {
					$shown = wp_date( $display_fmt, current_time( 'timestamp' ) );
				}
				$date_only_fmt = $this->datetime_display_date_only_format( $display_fmt );
				$dt_ui         = $this->datetime_field_client_ui_config( $display_fmt, $date_only_fmt );
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="nxt-gf-input nxt-gf-datetime-input" autocomplete="off" data-display-format="%4$s" data-date-only-format="%7$s" data-return-format="%5$s" data-week-start="%6$d" data-dt-ui="%8$s" />',
					esc_attr( $meta_key ),
					esc_attr( $input_name ),
					esc_attr( $shown ),
					esc_attr( $display_fmt ),
					esc_attr( $return_fmt ),
					(int) $week_start,
					esc_attr( $date_only_fmt ),
					esc_attr( wp_json_encode( $dt_ui ) )
				);
				break;

			case 'time':
				$display_fmt = isset( $field['time_display_format'] ) ? (string) $field['time_display_format'] : 'g:i a';
				if ( 'custom' === $display_fmt ) {
					$display_fmt = isset( $field['time_custom_display_format'] ) && '' !== trim( (string) $field['time_custom_display_format'] ) ? (string) $field['time_custom_display_format'] : 'g:i a';
				}
				$return_fmt = isset( $field['time_return_format'] ) ? (string) $field['time_return_format'] : 'g:i a';
				if ( 'custom' === $return_fmt ) {
					$return_fmt = isset( $field['time_custom_return_format'] ) && '' !== trim( (string) $field['time_custom_return_format'] ) ? (string) $field['time_custom_return_format'] : 'g:i a';
				}
				$is_now = ! empty( $field['time_default_now'] );
				$shown  = $this->normalize_time_value_for_display( $value, $display_fmt, $return_fmt );
				if ( '' === $shown && $is_now ) {
					$shown = wp_date( $display_fmt, current_time( 'timestamp' ) );
				}
				$tm_ui = $this->time_field_client_ui_config( $display_fmt );
				echo '<span class="nxt-gf-time-wrap">';
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="nxt-gf-input nxt-gf-time-input" autocomplete="off" readonly="readonly" data-display-format="%4$s" data-return-format="%5$s" data-time-ui="%6$s" />',
					esc_attr( $meta_key ),
					esc_attr( $input_name ),
					esc_attr( $shown ),
					esc_attr( $display_fmt ),
					esc_attr( $return_fmt ),
					esc_attr( wp_json_encode( $tm_ui ) )
				);
				echo '</span>';
				break;

			case 'color':
				$show_wheel  = ! isset( $field['color_show_color_wheel'] ) || ! empty( $field['color_show_color_wheel'] );
				$palettes    = $this->build_color_palettes_for_field( $field );
				$shown       = $this->normalize_color_value_for_input( $value );
				$def_raw     = isset( $field['default_value'] ) ? trim( (string) $field['default_value'] ) : '';
				$def_for_btn = ( '' !== $def_raw && $this->parse_css_color_to_rgba( $def_raw ) ) ? $def_raw : false;
				$opts        = array(
					'palettes'     => $palettes,
					'defaultColor' => $def_for_btn,
					'showWheel'    => (bool) $show_wheel,
				);
				$opts_json   = wp_json_encode( $opts );
				if ( false === $opts_json ) {
					$opts_json = '{}';
				}
				$wrap_classes = array( 'nxt-gf-color-wrap' );
				if ( ! $show_wheel ) {
					$wrap_classes[] = 'nxt-gf-color-no-wheel';
				}
				echo '<span class="' . esc_attr( implode( ' ', $wrap_classes ) ) . '">';
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="nxt-gf-input nxt-gf-color-input" autocomplete="off" data-nxt-gf-color-opts="%4$s" />',
					esc_attr( $meta_key ),
					esc_attr( $input_name ),
					esc_attr( $shown ),
					esc_attr( $opts_json )
				);
				echo '</span>';
				break;

			case 'link':
				$link_arr = array(
					'url'    => '',
					'title'  => '',
					'target' => '',
				);
				if ( is_array( $value ) ) {
					$link_arr['url']    = isset( $value['url'] ) ? (string) $value['url'] : '';
					$link_arr['title']  = isset( $value['title'] ) ? (string) $value['title'] : '';
					$link_arr['target'] = isset( $value['target'] ) ? (string) $value['target'] : '';
				} elseif ( is_string( $value ) && '' !== $value ) {
					$link_arr['url'] = $value;
				}

				$has_link = ( '' !== trim( $link_arr['url'] ) );
				$stub_id  = 'nxt_gf_wplink_' . preg_replace( '/[^a-zA-Z0-9_-]/', '_', $meta_key );

				echo '<div class="nxt-gf-link-wrap' . ( $has_link ? ' nxt-gf-link-has-value' : '' ) . '">';
				printf(
					'<input type="hidden" class="nxt-gf-link-input-url" name="%1$s[url]" value="%2$s" autocomplete="off" />',
					esc_attr( $input_name ),
					esc_attr( $link_arr['url'] )
				);
				printf(
					'<input type="hidden" class="nxt-gf-link-input-title" name="%1$s[title]" value="%2$s" autocomplete="off" />',
					esc_attr( $input_name ),
					esc_attr( $link_arr['title'] )
				);
				printf(
					'<input type="hidden" class="nxt-gf-link-input-target" name="%1$s[target]" value="%2$s" autocomplete="off" />',
					esc_attr( $input_name ),
					esc_attr( $link_arr['target'] )
				);
				echo '<textarea id="' . esc_attr( $stub_id ) . '" class="nxt-gf-link-wplink-stub" tabindex="-1" aria-hidden="true" readonly="readonly" style="clip:rect(0,0,0,0);position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;border:0;padding:0;"></textarea>';

				echo '<div class="nxt-gf-link-preview">';
				echo '<span class="nxt-gf-link-title-text">' . esc_html( $link_arr['title'] ) . '</span>';
				echo '<a class="nxt-gf-link-url-a" href="' . esc_url( $has_link ? $link_arr['url'] : '#' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $has_link ? $link_arr['url'] : '' ) . '</a>';
				echo '<div class="nxt-gf-link-preview-actions">';
				echo '<button type="button" class="button-link nxt-gf-link-edit" aria-label="' . esc_attr__( 'Edit link', 'nexter-extension' ) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span></button>';
				echo '<button type="button" class="button-link nxt-gf-link-remove" aria-label="' . esc_attr__( 'Remove link', 'nexter-extension' ) . '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>';
				echo '</div>';
				echo '</div>';
				echo '<button type="button" class="button nxt-gf-link-open">' . esc_html__( 'Select Link', 'nexter-extension' ) . '</button>';
				echo '</div>';
				break;

			case 'post_object':
				$post_types = isset( $field['post_object_post_types'] ) && is_array( $field['post_object_post_types'] ) ? array_map( 'sanitize_key', $field['post_object_post_types'] ) : array( 'post' );
				$post_types = array_values( array_filter( $post_types ) );
				if ( empty( $post_types ) ) {
					$post_types = array( 'post' );
				}
				$post_status = isset( $field['post_object_post_status'] ) && is_array( $field['post_object_post_status'] ) ? array_map( 'sanitize_key', $field['post_object_post_status'] ) : array( 'publish' );
				$post_status = array_values( array_filter( $post_status ) );
				if ( empty( $post_status ) ) {
					$post_status = array( 'publish' );
				}
				$tax_terms    = isset( $field['post_object_taxonomy_terms'] ) && is_array( $field['post_object_taxonomy_terms'] ) ? $field['post_object_taxonomy_terms'] : array();
				$tax_terms    = array_values(
					array_filter(
						array_map(
							static function ( $t ) {
								return is_string( $t ) ? sanitize_text_field( $t ) : '';
							},
							$tax_terms
						)
					)
				);
				$return_obj   = ! isset( $field['post_object_return_format'] ) || 'id' !== $field['post_object_return_format'];
				$multiple     = ! empty( $field['multiple'] );
				$filters      = array(
					'post_types'     => $post_types,
					'post_status'    => $post_status,
					'taxonomy_terms' => $tax_terms,
				);
				$selected_ids = $this->post_object_value_to_selected_ids( $value, $return_obj, $multiple );
				$name_attr    = $multiple ? $input_name . '[]' : $input_name;
				$multi_prop   = $multiple ? ' multiple="multiple"' : '';
				$ph           = __( 'Search posts…', 'nexter-extension' );
				echo '<div class="nxt-gf-post-object-wrap">';
				printf(
					'<select id="%1$s" name="%2$s" class="nxt-gf-input nxt-gf-post-object-select"%3$s data-placeholder="%4$s" data-nxt-gf-po-filters="%5$s">',
					esc_attr( $meta_key ),
					esc_attr( $name_attr ),
					$multi_prop, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $multi_prop is the literal ' multiple="multiple"' or an empty string.
					esc_attr( $ph ),
					esc_attr( wp_json_encode( $filters ) )
				);
				foreach ( $selected_ids as $pid ) {
					$p = get_post( $pid );
					if ( $p ) {
						$title = get_the_title( $p );
						if ( '' === $title ) {
							$title = '#' . (int) $pid;
						}
						printf( '<option value="%d" selected="selected">%s</option>', (int) $pid, esc_html( $title ) );
					}
				}
				echo '</select></div>';
				break;

			case 'user':
				$roles = isset( $field['user_role_filter'] ) && is_array( $field['user_role_filter'] ) ? array_map( 'sanitize_key', $field['user_role_filter'] ) : array();
				$roles = array_values( array_filter( $roles ) );
				$fmt   = isset( $field['user_return_format'] ) ? sanitize_key( $field['user_return_format'] ) : 'array';
				if ( ! in_array( $fmt, array( 'array', 'object', 'id' ), true ) ) {
					$fmt = 'array';
				}
				$multiple     = ! empty( $field['multiple'] );
				$selected_ids = $this->user_value_to_selected_ids( $value, $fmt, $multiple );
				$name_attr    = $multiple ? $input_name . '[]' : $input_name;
				$multi_prop   = $multiple ? ' multiple="multiple"' : '';
				$ph           = __( 'Search users…', 'nexter-extension' );
				$filters      = array(
					'roles' => $roles,
				);
				echo '<div class="nxt-gf-user-wrap">';
				printf(
					'<select id="%1$s" name="%2$s" class="nxt-gf-input nxt-gf-user-select"%3$s data-placeholder="%4$s" data-nxt-gf-user-filters="%5$s">',
					esc_attr( $meta_key ),
					esc_attr( $name_attr ),
					$multi_prop, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $multi_prop is the literal ' multiple="multiple"' or an empty string.
					esc_attr( $ph ),
					esc_attr( wp_json_encode( $filters ) )
				);
				foreach ( $selected_ids as $uid ) {
					$u = get_user_by( 'id', $uid );
					if ( ! $u ) {
						continue;
					}
					$label = $u->display_name;
					if ( '' === $label ) {
						$label = $u->user_login;
					}
					if ( '' !== $u->user_email && current_user_can( 'list_users' ) ) {
						$label .= ' (' . $u->user_email . ')';
					}
					printf( '<option value="%d" selected="selected">%s</option>', (int) $uid, esc_html( $label ) );
				}
				echo '</select></div>';
				break;

			case 'relationship':
			case 'repeater':
				// Reached only without Nexter Pro, which renders these types; the stored value is left untouched.
				$nxt_wl = nxt_ct_white_label();
				echo '<p class="description nxt-gf-pro-locked">' . esc_html( '' !== $nxt_wl['brand']
					/* translators: %s: white-label brand name */
					? sprintf( __( 'This field requires %s Pro. Its saved data is kept.', 'nexter-extension' ), $nxt_wl['brand'] )
					: __( 'This field requires Nexter Extension Pro. Its saved data is kept.', 'nexter-extension' ) ) . '</p>';
				break;

			case 'taxonomy':
				$tax = isset( $field['taxonomy_name'] ) ? sanitize_key( $field['taxonomy_name'] ) : '';
				if ( ! $tax || ! taxonomy_exists( $tax ) ) {
					echo '<p class="description">' . esc_html__( 'Invalid taxonomy. Update the field group settings.', 'nexter-extension' ) . '</p>';
					break;
				}
				$appearance = isset( $field['taxonomy_appearance'] ) ? sanitize_key( $field['taxonomy_appearance'] ) : 'checkbox';
				if ( ! in_array( $appearance, array( 'checkbox', 'multi_select', 'radio', 'select' ), true ) ) {
					$appearance = 'checkbox';
				}
				$return_obj   = isset( $field['taxonomy_return_format'] ) && 'object' === $field['taxonomy_return_format'];
				$multiple_ui  = in_array( $appearance, array( 'checkbox', 'multi_select' ), true );
				$selected_ids = $this->taxonomy_value_to_selected_ids( $value, $return_obj, $multiple_ui );
				$flat_terms   = $this->get_flat_terms_hierarchical( $tax );
				echo '<div class="nxt-gf-taxonomy-wrap"';
				echo ' id="' . esc_attr( $meta_key ) . '-tax-wrap"';
				echo ' data-taxonomy="' . esc_attr( $tax ) . '"';
				echo ' data-appearance="' . esc_attr( $appearance ) . '"';
				echo ' data-input-name="' . esc_attr( $input_name ) . '"';
				echo '>';

				if ( 'checkbox' === $appearance ) {
					echo '<div class="nxt-gf-taxonomy-inner">';
					echo '<ul class="nxt-gf-taxonomy-list" role="list">';
					foreach ( $flat_terms as $item ) {
						$t       = $item['term'];
						$depth   = (int) $item['depth'];
						$pad     = $depth > 0 ? str_repeat( '— ', $depth ) : '';
						$checked = in_array( (int) $t->term_id, $selected_ids, true );
						echo '<li class="nxt-gf-taxonomy-item">';
						echo '<label class="nxt-gf-taxonomy-check-label">';
						printf(
							'<input type="checkbox" class="nxt-gf-taxonomy-cb" name="%s[]" value="%d" %s /> ',
							esc_attr( $input_name ),
							(int) $t->term_id,
							checked( $checked, true, false )
						);
						echo '<span class="nxt-gf-taxonomy-term-name">' . esc_html( $pad . $t->name ) . '</span>';
						echo '</label></li>';
					}
					echo '</ul>';
					echo '</div>';
				} elseif ( 'radio' === $appearance ) {
					echo '<div class="nxt-gf-taxonomy-inner">';
					echo '<ul class="nxt-gf-taxonomy-list nxt-gf-taxonomy-list--radio" role="list">';
					$single = ! empty( $selected_ids ) ? (int) $selected_ids[0] : 0;
					foreach ( $flat_terms as $item ) {
						$t     = $item['term'];
						$depth = (int) $item['depth'];
						$pad   = $depth > 0 ? str_repeat( '— ', $depth ) : '';
						echo '<li class="nxt-gf-taxonomy-item">';
						echo '<label class="nxt-gf-taxonomy-radio-label">';
						printf(
							'<input type="radio" class="nxt-gf-taxonomy-radio" name="%s" value="%d" %s /> ',
							esc_attr( $input_name ),
							(int) $t->term_id,
							checked( $single, (int) $t->term_id, false )
						);
						echo '<span class="nxt-gf-taxonomy-term-name">' . esc_html( $pad . $t->name ) . '</span>';
						echo '</label></li>';
					}
					echo '</ul>';
					echo '</div>';
				} elseif ( 'multi_select' === $appearance ) {
					echo '<div class="nxt-gf-taxonomy-inner nxt-gf-taxonomy-inner--stack">';
					$sel_class = 'nxt-gf-input nxt-gf-taxonomy-select nxt-gf-select2';
					$size      = min( 10, max( 4, count( $flat_terms ) ) );
					printf(
						'<select id="%1$s" name="%2$s[]" class="%3$s" multiple="multiple" size="%4$d">',
						esc_attr( $meta_key ),
						esc_attr( $input_name ),
						esc_attr( $sel_class ),
						$size // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $size is an integer from min()/max()/count().
					);
					foreach ( $flat_terms as $item ) {
						$t     = $item['term'];
						$depth = (int) $item['depth'];
						$pad   = $depth > 0 ? str_repeat( '— ', $depth ) : '';
						printf(
							'<option value="%d" %s>%s</option>',
							(int) $t->term_id,
							selected( in_array( (int) $t->term_id, $selected_ids, true ), true, false ),
							esc_html( $pad . $t->name )
						);
					}
					echo '</select>';
					echo '</div>';
				} else {
					echo '<div class="nxt-gf-taxonomy-inner nxt-gf-taxonomy-inner--stack">';
					$sel_class = 'nxt-gf-input nxt-gf-taxonomy-select nxt-gf-select2';
					printf(
						'<select id="%1$s" name="%2$s" class="%3$s">',
						esc_attr( $meta_key ),
						esc_attr( $input_name ),
						esc_attr( $sel_class )
					);
					echo '<option value="">' . esc_html__( '— Select —', 'nexter-extension' ) . '</option>';
					$single = ! empty( $selected_ids ) ? (int) $selected_ids[0] : 0;
					foreach ( $flat_terms as $item ) {
						$t     = $item['term'];
						$depth = (int) $item['depth'];
						$pad   = $depth > 0 ? str_repeat( '— ', $depth ) : '';
						printf(
							'<option value="%d" %s>%s</option>',
							(int) $t->term_id,
							selected( $single, (int) $t->term_id, false ),
							esc_html( $pad . $t->name )
						);
					}
					echo '</select>';
					echo '</div>';
				}

				echo '</div>';
				break;

			case 'page_link':
				$post_types     = isset( $field['page_link_post_types'] ) && is_array( $field['page_link_post_types'] ) ? array_map( 'sanitize_key', $field['page_link_post_types'] ) : array();
				$post_types     = array_values( array_filter( $post_types ) );
				$post_status    = isset( $field['page_link_post_status'] ) && is_array( $field['page_link_post_status'] ) ? array_map( 'sanitize_key', $field['page_link_post_status'] ) : array();
				$post_status    = array_values( array_filter( $post_status ) );
				$tax_terms      = isset( $field['page_link_taxonomy_terms'] ) && is_array( $field['page_link_taxonomy_terms'] ) ? $field['page_link_taxonomy_terms'] : array();
				$tax_terms      = array_values(
					array_filter(
						array_map(
							static function ( $t ) {
								return is_string( $t ) ? sanitize_text_field( $t ) : '';
							},
							$tax_terms
						)
					)
				);
				$allow_archives = ! isset( $field['page_link_allow_archives'] ) || ! empty( $field['page_link_allow_archives'] );
				$multiple       = ! empty( $field['multiple'] );
				$filters        = array(
					'post_types'     => $post_types,
					'post_status'    => $post_status,
					'taxonomy_terms' => $tax_terms,
					'allow_archives' => $allow_archives,
				);
				$selected_urls  = $this->page_link_value_to_urls( $value, $multiple );
				$name_attr      = $multiple ? $input_name . '[]' : $input_name;
				$multi_prop     = $multiple ? ' multiple="multiple"' : '';
				$ph             = __( 'Search links…', 'nexter-extension' );
				echo '<div class="nxt-gf-page-link-wrap">';
				printf(
					'<select id="%1$s" name="%2$s" class="nxt-gf-input nxt-gf-page-link-select"%3$s data-placeholder="%4$s" data-nxt-gf-pl-filters="%5$s">',
					esc_attr( $meta_key ),
					esc_attr( $name_attr ),
					$multi_prop, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $multi_prop is the literal ' multiple="multiple"' or an empty string.
					esc_attr( $ph ),
					esc_attr( wp_json_encode( $filters ) )
				);
				foreach ( $selected_urls as $u ) {
					$u = esc_url_raw( $u );
					if ( '' === $u ) {
						continue;
					}
					$label = $this->page_link_option_label( $u );
					printf(
						'<option value="%s" selected="selected">%s</option>',
						esc_attr( $u ),
						esc_html( $label )
					);
				}
				echo '</select></div>';
				break;

			case 'email':
			case 'url':
			case 'password':
				if ( $has_prepend_append ) {
					echo '<div class="nxt-gf-input-group">';
				}
				if ( '' !== $prepend ) {
					echo '<span class="nxt-gf-prepend">' . esc_html( $prepend ) . '</span>';
				}

				// Password inputs never echo the stored value (masking); email/url use the scalar-safe
				// value so an array left in meta after a type change can't throw on esc_attr (PHP 8).
				$input_value = ( 'password' === $type ) ? '' : $scalar_value;
				printf(
					'<input type="%5$s" id="%1$s" name="%2$s" value="%3$s" class="nxt-gf-input' . ( $has_prepend_append ? ' nxt-gf-input-in-group' : '' ) . '" placeholder="%4$s" />',
					esc_attr( $meta_key ),
					esc_attr( $input_name ),
					esc_attr( $input_value ),
					esc_attr( $placeholder ),
					( 'password' === $type ? 'password' : $type ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $type here is provably 'email', 'url' or 'password' — the switch case that reached this branch.
				);
				if ( '' !== $append ) {
					echo '<span class="nxt-gf-append">' . esc_html( $append ) . '</span>';
				}
				if ( $has_prepend_append ) {
					echo '</div>';
				}
				break;

			case 'select':
				$choices = array();
				if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					$choices = $field['choices'];
				} elseif ( ! empty( $field['choices'] ) && is_string( $field['choices'] ) ) {
					$lines = preg_split( '/\r\n|\r|\n/', $field['choices'] );
					foreach ( $lines as $line ) {
						$line = trim( $line );
						if ( '' === $line ) {
							continue;
						}
						if ( preg_match( '/^\s*(.+?)\s*:\s*(.+)\s*$/', $line, $m ) ) {
							$choices[ trim( $m[1] ) ] = trim( $m[2] );
						} else {
							$choices[ $line ] = $line;
						}
					}
				}
				$choices     = $this->normalize_choices_shape( $choices );
				$is_multiple = ! empty( $field['multiple'] );
				$stored      = $value;
				// Use field default_value when no value saved (empty string or empty array).
				if ( $is_multiple ) {
					if ( ! is_array( $stored ) ) {
						$stored = ( '' !== $stored && null !== $stored ) ? array( $stored ) : array();
					}
					if ( empty( $stored ) && ! empty( $field['default_value'] ) ) {
						$defaults = array();
						if ( is_array( $field['default_value'] ) ) {
							$defaults = $field['default_value'];
						} elseif ( is_string( $field['default_value'] ) ) {
							$lines = preg_split( '/\r\n|\r|\n/', $field['default_value'] );
							foreach ( $lines as $line ) {
								$line = trim( $line );
								if ( '' !== $line ) {
									$defaults[] = $line;
								}
							}
						}
						if ( ! empty( $defaults ) ) {
							$stored = $defaults;
						}
					}
				} elseif ( ( '' === $stored || null === $stored ) && isset( $field['default_value'] ) ) {
						$def = $field['default_value'];
					if ( is_array( $def ) ) {
						$stored = isset( $def[0] ) ? $def[0] : '';
					} elseif ( is_string( $def ) ) {
						// When default_value is a newline-separated string, use the first non-empty line.
						$lines = preg_split( '/\r\n|\r|\n/', $def );
						$first = '';
						foreach ( $lines as $line ) {
							$line = trim( $line );
							if ( '' !== $line ) {
								$first = $line;
								break;
							}
						}
						$stored = $first;
					} else {
						$stored = $def;
					}
				}

				$name_attr    = $is_multiple ? esc_attr( $input_name ) . '[]' : esc_attr( $input_name );
				$multi_attr   = $is_multiple ? ' multiple="multiple"' : '';
				$select_class = 'nxt-gf-input';
				if ( ! empty( $field['stylized_ui'] ) ) {
					$select_class .= ' nxt-gf-select2';
				}
				echo '<select id="' . esc_attr( $meta_key ) . '" name="' . $name_attr . '" class="' . esc_attr( $select_class ) . '"' . $multi_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name_attr is esc_attr()-ed above and $multi_attr is a literal constant.
				if ( ! $is_multiple ) {
					echo '<option value="">' . esc_html__( 'Select…', 'nexter-extension' ) . '</option>';
				}
				foreach ( $choices as $c_value => $c_label ) {
					$stored_arr = is_array( $stored ) ? $stored : array( $stored );
					$selected   = in_array( (string) $c_value, array_map( 'strval', $stored_arr ), true );
					printf(
						'<option value="%1$s"%3$s>%2$s</option>',
						esc_attr( $c_value ),
						esc_html( $c_label ),
						$selected ? ' selected="selected"' : ''
					);
				}
				echo '</select>';
				break;

			case 'checkbox':
				$choices = array();
				if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					$choices = $field['choices'];
				} elseif ( ! empty( $field['choices'] ) && is_string( $field['choices'] ) ) {
					$lines = preg_split( '/\r\n|\r|\n/', $field['choices'] );
					foreach ( $lines as $line ) {
						$line = trim( $line );
						if ( '' === $line ) {
							continue;
						}
						if ( preg_match( '/^\s*(.+?)\s*:\s*(.+)\s*$/', $line, $m ) ) {
							$choices[ trim( $m[1] ) ] = trim( $m[2] );
						} else {
							$choices[ $line ] = $line;
						}
					}
				}
				$choices = $this->normalize_choices_shape( $choices );
				$stored  = is_array( $value ) ? $value : ( '' !== $value ? array( $value ) : array() );
				// MB-23: only fall back to the default when NOTHING has been saved yet.
				$never_saved = ! $value_exists;
				if ( $never_saved && empty( $stored ) && ! empty( $field['default_value'] ) ) {
					$defaults = array();
					if ( is_array( $field['default_value'] ) ) {
						$defaults = $field['default_value'];
					} elseif ( is_string( $field['default_value'] ) ) {
						$lines = preg_split( '/\r\n|\r|\n/', $field['default_value'] );
						foreach ( $lines as $line ) {
							$line = trim( $line );
							if ( '' !== $line ) {
								$defaults[] = $line;
							}
						}
					}
					if ( ! empty( $defaults ) ) {
						$stored = $defaults;
					}
				}
				$layout        = ! empty( $field['layout'] ) ? $field['layout'] : 'vertical';
				$is_horizontal = ( 'horizontal' === $layout );
				$layout_class  = $is_horizontal ? ' nxt-gf-checkbox-horizontal' : '';

				// Optional "toggle all" checkbox prepended to the list.
				if ( ! empty( $field['add_toggle_all'] ) && ! empty( $choices ) ) {
					printf(
						'<label class="nxt-gf-checkbox nxt-gf-checkbox-toggle-all%1$s"><input type="checkbox" class="nxt-gf-toggle-all" data-target-name="%2$s[]" /> %3$s</label>',
						esc_attr( $layout_class ),
						esc_attr( $input_name ),
						esc_html__( 'Toggle all', 'nexter-extension' )
					);
				}

				// MB-23: hidden companion so an all-unchecked group still submits, keeping the key in POST so it can be cleared.
				printf( '<input type="hidden" name="%s[]" value="" />', esc_attr( $input_name ) );

				foreach ( $choices as $c_value => $c_label ) {
					$checked = in_array( (string) $c_value, array_map( 'strval', $stored ), true );
					printf(
						'<label class="nxt-gf-checkbox%5$s"><input type="checkbox" name="%1$s[]" value="%2$s" %4$s /> %3$s</label>',
						esc_attr( $input_name ),
						esc_attr( $c_value ),
						esc_html( $c_label ),
						checked( $checked, true, false ),
						esc_attr( $layout_class )
					);
				}
				break;

			case 'radio':
				$choices = array();
				if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					$choices = $field['choices'];
				} elseif ( ! empty( $field['choices'] ) && is_string( $field['choices'] ) ) {
					$lines = preg_split( '/\r\n|\r|\n/', $field['choices'] );
					foreach ( $lines as $line ) {
						$line = trim( $line );
						if ( '' === $line ) {
							continue;
						}
						if ( preg_match( '/^\s*(.+?)\s*:\s*(.+)\s*$/', $line, $m ) ) {
							$choices[ trim( $m[1] ) ] = trim( $m[2] );
						} else {
							$choices[ $line ] = $line;
						}
					}
				}
				$choices = $this->normalize_choices_shape( $choices );

				$layout        = ! empty( $field['layout'] ) ? $field['layout'] : 'vertical';
				$is_horizontal = ( 'horizontal' === $layout );
				$layout_class  = $is_horizontal ? ' nxt-gf-radio-horizontal' : '';

				foreach ( $choices as $c_value => $c_label ) {
					printf(
						'<label class="nxt-gf-radio%5$s"><input type="radio" name="%1$s" value="%2$s" %4$s /> %3$s</label>',
						esc_attr( $input_name ),
						esc_attr( $c_value ),
						esc_html( $c_label ),
						checked( ( is_array( $value ) ? '' : (string) $value ), (string) $c_value, false ),
						esc_attr( $layout_class )
					);
				}
				break;

			case 'true_false':
				$label   = isset( $field['label'] ) ? $field['label'] : '';
				$message = isset( $field['message'] ) ? $field['message'] : '';

				$is_on       = ( '1' === $value || 1 === $value || true === $value || 'true' === $value );
				$is_stylized = ! empty( $field['stylized_ui'] );

				// MB-23: hidden companion so an UNCHECKED box still submits a value ('0').
				printf( '<input type="hidden" name="%s" value="0" />', esc_attr( $input_name ) );

				if ( $is_stylized ) {
					$text_on  = isset( $field['on_text'] ) && '' !== $field['on_text'] ? $field['on_text'] : __( 'Yes', 'nexter-extension' );
					$text_off = isset( $field['off_text'] ) && '' !== $field['off_text'] ? $field['off_text'] : __( 'No', 'nexter-extension' );
					echo '<div class="nxt-gf-boolean-toggle-wrap">';
					printf(
						'<input type="checkbox" id="%1$s" name="%2$s" value="1" class="nxt-gf-boolean-toggle-input" %3$s style="display: none;" />',
						esc_attr( $meta_key ),
						esc_attr( $input_name ),
						checked( $is_on, true, false )
					);
					printf(
						'<label class="nxt-gf-boolean-toggle" for="%1$s"><span class="nxt-gf-boolean-on">%2$s</span><span class="nxt-gf-boolean-off">%3$s</span></label>',
						esc_attr( $meta_key ),
						esc_html( $text_on ),
						esc_html( $text_off )
					);
					$display_text = '' !== $message ? $message : $label;
					if ( '' !== $display_text ) {
						echo '<div class="nxt-gf-boolean-label">' . esc_html( $display_text ) . '</div>';
					}
					echo '</div>';
				} else {
					$display_text = '' !== $message ? $message : $label;
					printf(
						'<label class="nxt-gf-boolean"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
						esc_attr( $meta_key ),
						esc_attr( $input_name ),
						checked( $is_on, true, false ),
						esc_html( $display_text )
					);
				}
				break;

			case 'wysiwyg':
				$tabs       = isset( $field['tabs'] ) ? $field['tabs'] : 'visual_text';
				$toolbar    = isset( $field['toolbar'] ) ? $field['toolbar'] : 'full';
				$show_media = array_key_exists( 'show_media_upload', $field ) ? (bool) $field['show_media_upload'] : true;
				$delay_init = ! empty( $field['delay_init'] );

				$editor_settings = array(
					'textarea_name' => $input_name,
					'textarea_rows' => 8,
					'media_buttons' => $show_media,
				);

				// Tabs: visual/text controls the Quicktags & TinyMCE toggles.
				if ( 'visual' === $tabs ) {
					$editor_settings['quicktags'] = false;
				} elseif ( 'text' === $tabs ) {
					$editor_settings['tinymce'] = false;
				}

				// Toolbar: map to TinyMCE toolbars (full/basic).
				if ( 'basic' === $toolbar ) {
					$editor_settings['tinymce']             = isset( $editor_settings['tinymce'] ) && is_array( $editor_settings['tinymce'] )
						? $editor_settings['tinymce']
						: array();
					$editor_settings['tinymce']['toolbar1'] = 'bold,italic,underline,link,unlink,bullist,numlist,undo,redo';
				}

				if ( $delay_init ) {
					// Render a plain textarea placeholder; JS can call wp.editor.initialize() on focus using these data attributes.
					printf(
						'<textarea id="%1$s" name="%2$s" class="nxt-gf-wysiwyg-delayed" data-nxt-gf-editor-settings="%4$s" rows="%3$d">%5$s</textarea>',
						esc_attr( $meta_key ),
						esc_attr( $input_name ),
						8,
						esc_attr( wp_json_encode( $editor_settings ) ),
						esc_textarea( $scalar_value )
					);
				} else {
					wp_editor( is_string( $scalar_value ) ? $scalar_value : '', $meta_key, $editor_settings );
				}
				break;

			case 'image':
				// Always store attachment ID; return_format controls template usage only.
				$image_return = isset( $field['return_format'] ) ? $field['return_format'] : 'array';
				$library      = isset( $field['library'] ) ? $field['library'] : 'all';
				$allowed      = isset( $field['allowed_types'] ) ? $field['allowed_types'] : '';
				$preview_size = isset( $field['preview_size'] ) ? $field['preview_size'] : 'medium';

				$attachment_id = is_numeric( $value ) ? (int) $value : 0;
				$preview_url   = '';
				if ( $attachment_id ) {
					$src = wp_get_attachment_image_src( $attachment_id, $preview_size );
					if ( $src && ! empty( $src[0] ) ) {
						$preview_url = $src[0];
					}
				}

				echo '<div class="nxt-gf-image-wrap' . ( $preview_url ? ' nxt-gf-image-has-value' : '' ) . '">';
				printf(
					'<input type="hidden" id="%1$s" name="%2$s" value="%3$s" class="nxt-gf-image-input" />',
					esc_attr( $meta_key ),
					esc_attr( $input_name ),
					esc_attr( $attachment_id ? $attachment_id : '' )
				);
				echo '<div class="nxt-gf-image-preview">';
				if ( $preview_url ) {
					echo '<img src="' . esc_url( $preview_url ) . '" alt="" />';
				}
				echo '<button type="button" class="nxt-gf-image-edit" aria-label="' . esc_attr__( 'Edit image', 'nexter-extension' ) . '"><span class="dashicons dashicons-edit"></span></button>';
				echo '<button type="button" class="nxt-gf-image-remove" aria-label="' . esc_attr__( 'Remove image', 'nexter-extension' ) . '"><span class="dashicons dashicons-no-alt"></span></button>';
				echo '</div>';
				echo ' <button type="button" class="button nxt-gf-media-button" data-target="' . esc_attr( $meta_key ) . '" data-return-format="' . esc_attr( $image_return ) . '" data-library="' . esc_attr( $library ) . '" data-allowed-types="' . esc_attr( $allowed ) . '" data-preview-size="' . esc_attr( $preview_size ) . '">' . esc_html__( 'Select Image', 'nexter-extension' ) . '</button>';
				echo '</div>';
				break;

			case 'file':
				$file_return = isset( $field['return_format'] ) ? $field['return_format'] : 'array';
				$library     = isset( $field['library'] ) ? $field['library'] : 'all';
				$allowed     = isset( $field['allowed_types'] ) ? $field['allowed_types'] : '';
				$min_size    = isset( $field['min_size'] ) ? $field['min_size'] : '';
				$max_size    = isset( $field['max_size'] ) ? $field['max_size'] : '';

				$attachment_id = is_numeric( $value ) ? (int) $value : 0;
				$file_title    = '';
				$file_name     = '';
				$file_url      = '';
				if ( $attachment_id ) {
					$attachment = get_post( $attachment_id );
					if ( $attachment ) {
						$file_title = $attachment->post_title ? $attachment->post_title : $attachment->post_name;
						$file_url   = wp_get_attachment_url( $attachment_id );
						$file_name  = $file_url ? wp_basename( $file_url ) : basename( get_attached_file( $attachment_id ) );

					}
				}

				echo '<div class="nxt-gf-file-wrap' . ( $attachment_id ? ' nxt-gf-file-has-value' : '' ) . '">';
				printf(
					'<input type="hidden" id="%1$s" name="%2$s" value="%3$s" class="nxt-gf-file-input" />',
					esc_attr( $meta_key ),
					esc_attr( $input_name ),
					esc_attr( $attachment_id ? $attachment_id : '' )
				);
				echo '<div class="nxt-gf-file-preview">';
				echo '<span class="dashicons dashicons-media-default"></span>';
				echo '<div class="nxt-gf-file-text">';
					echo '<div class="nxt-gf-file-title">' . esc_html( $file_title ) . '</div>';
					echo '<div class="nxt-gf-file-row">';
						echo '<span class="nxt-gf-file-label">' . esc_html__( 'File name:', 'nexter-extension' ) . '</span> ';
				if ( $file_url && $file_name ) {
					echo '<a class="nxt-gf-file-link" href="' . esc_url( $file_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $file_name ) . '</a>';
				} else {
					echo '<span class="nxt-gf-file-link">' . esc_html( $file_name ) . '</span>';
				}
					echo '</div>';
				echo '</div>';
				echo '<button type="button" class="nxt-gf-file-edit" aria-label="' . esc_attr__( 'Edit file', 'nexter-extension' ) . '"><span class="dashicons dashicons-edit"></span></button>';
				echo '<button type="button" class="nxt-gf-file-remove" aria-label="' . esc_attr__( 'Remove file', 'nexter-extension' ) . '"><span class="dashicons dashicons-no-alt"></span></button>';
				echo '</div>';
				echo ' <button type="button" class="button nxt-gf-media-button" data-target="' . esc_attr( $meta_key ) . '" data-return-format="' . esc_attr( $file_return ) . '" data-library="' . esc_attr( $library ) . '" data-allowed-types="' . esc_attr( $allowed ) . '" data-min-size="' . esc_attr( $min_size ) . '" data-max-size="' . esc_attr( $max_size ) . '">' . esc_html__( 'Select File', 'nexter-extension' ) . '</button>';
				echo '</div>';
				break;

			default:
				// A field group can reference a field type that a later plugin version removed.
				if ( 'text' !== $type && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					static $nxt_unknown_field_types = array();
					if ( ! isset( $nxt_unknown_field_types[ $type ] ) ) {
						$nxt_unknown_field_types[ $type ] = true;
						error_log( sprintf( 'Content Fields: unknown field type "%s" rendered as a plain text input — the field configuration may be stale.', $type ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				}
				if ( $has_prepend_append ) {
					echo '<div class="nxt-gf-input-group">';
				}
				if ( '' !== $prepend ) {
					echo '<span class="nxt-gf-prepend">' . esc_html( $prepend ) . '</span>';
				}
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="nxt-gf-input' . ( $has_prepend_append ? ' nxt-gf-input-in-group' : '' ) . '" placeholder="%4$s"' . $required . ' />', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The printf format holds the markup; every interpolated value is escaped below.
					esc_attr( $meta_key ),
					esc_attr( $input_name ),
					esc_attr( $scalar_value ),
					esc_attr( $placeholder )
				);
				if ( '' !== $append ) {
					echo '<span class="nxt-gf-append">' . esc_html( $append ) . '</span>';
				}
				if ( $has_prepend_append ) {
					echo '</div>';
				}
				break;
		}
	}

	/**
	 * Whether the current user may run post object / page link AJAX searches.
	 *
	 * @return bool
	 */
	private function user_can_nxt_gf_ajax_search() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( ! is_object( $taxonomy ) || empty( $taxonomy->cap->edit_terms ) ) {
				continue;
			}
			// Check the taxonomy's mapped cap; 'edit_terms' is not a meta cap that takes a taxonomy arg.
			if ( current_user_can( $taxonomy->cap->edit_terms ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read search, page, and filters from an AJAX request (GET or POST).
	 *
	 * @return array{0: string, 1: int, 2: array}
	 */
	private function nxt_gf_read_ajax_search_page_filters() {
		$search  = isset( $_REQUEST['search'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search'] ) ) : '';
		$page    = isset( $_REQUEST['page'] ) ? max( 1, (int) wp_unslash( $_REQUEST['page'] ) ) : 1; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int and floored at 1, which is the sanitization.
		$filters = array();
		if ( isset( $_REQUEST['filters'] ) ) {
			$raw = wp_unslash( $_REQUEST['filters'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Carried raw and sanitized per key at each point of use: sanitize_key(), (int) and taxonomy_exists().
			if ( is_array( $raw ) ) {
				$filters = $raw;
			} elseif ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$filters = $decoded;
				}
			}
		}
		return array( $search, $page, $filters );
	}

	/**
	 * Narrow a requested post_status set to what the current user may actually see.
	 *
	 * The `filters` payload is built server-side but round-trips through the browser, so every value
	 * in it is attacker-controlled by the time it comes back — a Contributor can ask for drafts and
	 * private posts. The row loops drop unreadable posts, but WP_Query counts them in found_posts, so
	 * the query itself has to be narrowed: a post-query filter cannot un-leak a count.
	 *
	 * @param array|string $requested Sanitized status list, or 'any'.
	 * @return array|string Unchanged for users who can see others' unpublished posts, else publish-only.
	 */
	private function limit_post_status_to_caps( $requested ) {
		if ( current_user_can( 'edit_others_posts' ) ) {
			return $requested;
		}
		return array( 'publish' );
	}

	/**
	 * Drop requested post types the user may not browse (non-public types need their edit_posts cap).
	 *
	 * @param array $types Sanitized post type names.
	 * @return array
	 */
	private function limit_post_types_to_caps( array $types ) {
		$out = array();
		foreach ( $types as $type ) {
			$pto = get_post_type_object( $type );
			if ( $pto && ( $pto->public || current_user_can( $pto->cap->edit_posts ) ) ) {
				$out[] = $type;
			}
		}
		return $out;
	}

	/**
	 * Build tax_query array from filters.taxonomy_terms (taxonomy:term_id pairs).
	 *
	 * @param array $filters Decoded filters.
	 * @return array<int|string, mixed>
	 */
	private function nxt_gf_filters_to_tax_query( array $filters ) {
		$tax_query = array();
		if ( empty( $filters['taxonomy_terms'] ) || ! is_array( $filters['taxonomy_terms'] ) ) {
			return $tax_query;
		}
		foreach ( $filters['taxonomy_terms'] as $pair ) {
			if ( ! is_string( $pair ) || strpos( $pair, ':' ) === false ) {
				continue;
			}
			list( $tax, $tid ) = explode( ':', $pair, 2 );
			$tax               = sanitize_key( $tax );
			$tid               = (int) $tid;
			if ( $tax && $tid > 0 && taxonomy_exists( $tax ) ) {
				$tax_query[] = array(
					'taxonomy' => $tax,
					'field'    => 'term_id',
					'terms'    => array( $tid ),
				);
			}
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'OR';
		}
		return $tax_query;
	}

	/**
	 * AJAX: Select2 search for Post Object field (admin post/term/user screens).
	 */
	public function ajax_post_object_search() {
		check_ajax_referer( 'nxt_gf_post_object', 'nonce' );
		if ( ! $this->user_can_nxt_gf_ajax_search() ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nexter-extension' ) ) );
		}

		list( $search, $page, $filters ) = $this->nxt_gf_read_ajax_search_page_filters();

		$post_types = isset( $filters['post_types'] ) && is_array( $filters['post_types'] ) ? array_map( 'sanitize_key', $filters['post_types'] ) : array( 'post' );
		$post_types = $this->limit_post_types_to_caps( array_values( array_filter( $post_types ) ) );
		if ( empty( $post_types ) ) {
			$post_types = get_post_types( array( 'public' => true ), 'names' );
		}

		$post_status = isset( $filters['post_status'] ) && is_array( $filters['post_status'] ) ? array_map( 'sanitize_key', $filters['post_status'] ) : array( 'publish' );
		$post_status = array_values( array_filter( $post_status ) );
		if ( ! empty( $filters['allow_any_status'] ) ) {
			$post_status = 'any';
		} elseif ( empty( $post_status ) ) {
			$post_status = array( 'publish' );
		}
		$post_status = $this->limit_post_status_to_caps( $post_status );

		$list_pt = isset( $_REQUEST['list_post_type'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['list_post_type'] ) ) : '';
		if ( '' !== $list_pt ) {
			$allowed = $post_types;
			if ( empty( $allowed ) ) {
				$allowed = get_post_types( array( 'public' => true ), 'names' );
			}
			if ( in_array( $list_pt, $allowed, true ) ) {
				$post_types = array( $list_pt );
			}
		}

		$runtime_tax = array();
		if ( isset( $_REQUEST['runtime_tax_terms'] ) ) {
			$raw_rt = wp_unslash( $_REQUEST['runtime_tax_terms'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- json_decode()d, then every element passed through sanitize_text_field() below.
			if ( is_string( $raw_rt ) && '' !== $raw_rt ) {
				$decoded = json_decode( $raw_rt, true );
				if ( is_array( $decoded ) ) {
					$runtime_tax = array_values(
						array_filter(
							array_map(
								static function ( $t ) {
									return is_string( $t ) ? sanitize_text_field( $t ) : '';
								},
								$decoded
							)
						)
					);
				}
			}
		}
		if ( ! empty( $runtime_tax ) ) {
			$filters['taxonomy_terms'] = $runtime_tax;
		}

		$tax_query = $this->nxt_gf_filters_to_tax_query( $filters );

		$with_thumb = ! empty( $_REQUEST['with_thumbnail'] );

		$per_page = 20;
		$args     = array(
			'post_type'              => $post_types,
			'post_status'            => $post_status,
			's'                      => $search,
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query   = new WP_Query( $args );
		$results = array();
		foreach ( $query->posts as $post_obj ) {
			if ( ! current_user_can( 'read_post', $post_obj->ID ) ) {
				continue;
			}
			$t = get_the_title( $post_obj );
			if ( '' === $t ) {
				$t = '#' . (int) $post_obj->ID;
			}
			$row = array(
				'id'   => (string) $post_obj->ID,
				'text' => $t,
			);
			if ( $with_thumb ) {
				$thumb        = get_the_post_thumbnail_url( $post_obj->ID, 'thumbnail' );
				$row['thumb'] = $thumb ? $thumb : '';
			}
			$results[] = $row;
		}

		// found_posts counts the rows read_post() just dropped, so it cannot drive `more`: it reports
		// how many unreadable posts matched, and pages that render empty would claim to be the last.
		$more = count( $query->posts ) === $per_page;
		wp_send_json_success(
			array(
				'results' => $results,
				'more'    => $more,
			)
		);
	}

	/**
	 * AJAX: Select2 search for User field (grouped by role).
	 */
	public function ajax_user_search() {
		check_ajax_referer( 'nxt_gf_user', 'nonce' );
		// VULN-003: user enumeration requires 'list_users', the same bar as core's user REST endpoint.
		if ( ! current_user_can( 'list_users' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nexter-extension' ) ) );
		}

		list( $search, $page, $filters ) = $this->nxt_gf_read_ajax_search_page_filters();

		$roles = isset( $filters['roles'] ) && is_array( $filters['roles'] ) ? array_map( 'sanitize_key', $filters['roles'] ) : array();
		$roles = array_values( array_filter( $roles ) );

		$per_page = 20;
		$args     = array(
			'number'      => $per_page,
			'offset'      => max( 0, ( $page - 1 ) * $per_page ),
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'count_total' => true,
			'fields'      => 'all_with_meta',
		);
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name', 'user_email' );
		}
		if ( ! empty( $roles ) ) {
			$args['role__in'] = $roles;
		}

		$query = new WP_User_Query( $args );
		$users = $query->get_results();

		$groups = array();
		foreach ( $users as $u ) {
			if ( ! ( $u instanceof WP_User ) ) {
				$u = get_userdata( (int) ( is_object( $u ) && isset( $u->ID ) ? $u->ID : 0 ) );
				if ( ! $u instanceof WP_User ) {
					continue;
				}
			}
			$role_slug = ! empty( $u->roles ) ? sanitize_key( (string) $u->roles[0] ) : 'none';
			$role_obj  = ( 'none' !== $role_slug ) ? wp_roles()->get_role( $role_slug ) : null;
			$role_lbl  = ( $role_obj && ! empty( $role_obj->name ) ) ? $role_obj->name : __( 'Users', 'nexter-extension' );
			if ( ! isset( $groups[ $role_slug ] ) ) {
				$groups[ $role_slug ] = array(
					'text'     => $role_lbl,
					'children' => array(),
				);
			}
			$txt = $u->display_name ? $u->display_name : $u->user_login;
			// The capability check stays: an email address is personal data, and this list is rendered
			// for any user who can edit the object, not only for administrators.
			if ( $u->user_email && current_user_can( 'list_users' ) ) {
				$txt .= ' (' . $u->user_email . ')';
			}
			$groups[ $role_slug ]['children'][] = array(
				'id'   => (string) $u->ID,
				'text' => $txt,
			);
		}
		$results = array_values( $groups );

		$total = (int) $query->get_total();
		$more  = ( $page * $per_page ) < $total;
		wp_send_json_success(
			array(
				'results' => $results,
				'more'    => $more,
			)
		);
	}

	/**
	 * AJAX: Select2 search for Page Link field (grouped archives / posts / media URLs).
	 */
	public function ajax_page_link_search() {
		check_ajax_referer( 'nxt_gf_page_link', 'nonce' );
		if ( ! $this->user_can_nxt_gf_ajax_search() ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nexter-extension' ) ) );
		}

		list( $search, $page, $filters ) = $this->nxt_gf_read_ajax_search_page_filters();

		$raw_types = isset( $filters['post_types'] ) && is_array( $filters['post_types'] ) ? array_map( 'sanitize_key', $filters['post_types'] ) : array();
		$raw_types = $this->limit_post_types_to_caps( array_values( array_filter( $raw_types ) ) );
		if ( empty( $raw_types ) ) {
			$raw_types = get_post_types( array( 'public' => true ), 'names' );
		}

		$post_status = isset( $filters['post_status'] ) && is_array( $filters['post_status'] ) ? array_map( 'sanitize_key', $filters['post_status'] ) : array();
		$post_status = array_values( array_filter( $post_status ) );
		if ( empty( $post_status ) ) {
			$post_status = 'any';
		}
		$post_status = $this->limit_post_status_to_caps( $post_status );

		$tax_query       = $this->nxt_gf_filters_to_tax_query( $filters );
		$allow_archives  = ! empty( $filters['allow_archives'] );
		$want_attachment = in_array( 'attachment', $raw_types, true );
		$content_types   = array_values( array_diff( $raw_types, array( 'attachment' ) ) );

		$groups = array();
		$more   = false;

		if ( 1 === $page && $allow_archives ) {
			$arch_children = $this->nxt_gf_page_link_archive_children( $search, $raw_types );
			if ( ! empty( $arch_children ) ) {
				$groups[] = array(
					'text'     => __( 'Archives', 'nexter-extension' ),
					'children' => $arch_children,
				);
			}
		}

		$per_page = 20;
		if ( ! empty( $content_types ) ) {
			$args = array(
				'post_type'              => $content_types,
				'post_status'            => $post_status,
				's'                      => $search,
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);
			if ( ! empty( $tax_query ) ) {
				$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			}
			$q_posts    = new WP_Query( $args );
			$p_children = array();
			foreach ( $q_posts->posts as $post_obj ) {
				if ( ! current_user_can( 'read_post', $post_obj->ID ) ) {
					continue;
				}
				$url = get_permalink( $post_obj );
				if ( ! $url ) {
					continue;
				}
				$t = get_the_title( $post_obj );
				if ( '' === $t ) {
					$t = $url;
				}
				$p_children[] = array(
					'id'   => $url,
					'text' => $t,
				);
			}
			if ( ! empty( $p_children ) ) {
				$groups[] = array(
					'text'     => __( 'Posts', 'nexter-extension' ),
					'children' => $p_children,
				);
			}
			// See ajax_post_object_search(): count the rows the query returned, never found_posts.
			$more = count( $q_posts->posts ) === $per_page;
		}

		if ( 1 === $page && $want_attachment ) {
			$a_args     = array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				's'                      => $search,
				'posts_per_page'         => 20,
				'paged'                  => 1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
			);
			$q_att      = new WP_Query( $a_args );
			$m_children = array();
			foreach ( $q_att->posts as $post_obj ) {
				if ( ! current_user_can( 'read_post', $post_obj->ID ) ) {
					continue;
				}
				$url = wp_get_attachment_url( $post_obj->ID );
				if ( ! $url ) {
					continue;
				}
				$t            = $post_obj->post_title ? $post_obj->post_title : wp_basename( $url );
				$m_children[] = array(
					'id'   => $url,
					'text' => $t,
				);
			}
			if ( ! empty( $m_children ) ) {
				$groups[] = array(
					'text'     => __( 'Media', 'nexter-extension' ),
					'children' => $m_children,
				);
			}
		}

		wp_send_json_success(
			array(
				'results' => $groups,
				'more'    => $more,
			)
		);
	}

	/**
	 * Archive / front URLs for page link field (filtered by search and post type selection).
	 *
	 * @param string $search        Search string.
	 * @param array  $allowed_types Post type slugs (may include attachment; archives skip it).
	 * @return array<int, array{id: string, text: string}>
	 */
	private function nxt_gf_page_link_archive_children( $search, array $allowed_types ) {
		$search_l = ( '' !== $search ) ? strtolower( $search ) : '';
		$match    = static function ( $haystack ) use ( $search_l ) {
			if ( '' === $search_l ) {
				return true;
			}
			return strpos( strtolower( (string) $haystack ), $search_l ) !== false;
		};

		$out  = array();
		$seen = array();
		$push = static function ( $url, $text ) use ( &$out, &$seen, $match ) {
			if ( ! $url || isset( $seen[ $url ] ) ) {
				return;
			}
			if ( ! $match( $text ) && ! $match( $url ) ) {
				return;
			}
			$seen[ $url ] = true;
			$out[]        = array(
				'id'   => $url,
				'text' => $text,
			);
		};

		$home = home_url( '/' );
		$push( $home, $home );

		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id ) {
			$u = get_permalink( $posts_page_id );
			if ( $u ) {
				$push( $u, get_the_title( $posts_page_id ) . ' — ' . $u );
			}
		}

		$pt_objects = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( $pt_objects as $pt => $obj ) {
			if ( 'attachment' === $pt || empty( $obj->has_archive ) ) {
				continue;
			}
			if ( ! in_array( $pt, $allowed_types, true ) ) {
				continue;
			}
			$u = get_post_type_archive_link( $pt );
			if ( ! $u ) {
				continue;
			}
			$label = isset( $obj->labels->name ) ? $obj->labels->name : $pt;
			$push( $u, $label . ' — ' . $u );
		}

		return $out;
	}

	/**
	 * Top-level field name => config for a saved field group key.
	 *
	 * @param string $group_key Group key from metabox.
	 * @return array<string, array>
	 */
	private function get_top_level_field_map_for_group_key( $group_key ) {
		if ( array_key_exists( $group_key, self::$nxt_gf_group_field_maps ) ) {
			return self::$nxt_gf_group_field_maps[ $group_key ];
		}
		// PERF: build the field map for EVERY group in a single pass and memoize each one, instead of re-loading
		// + re-scanning all groups on every distinct-key miss.
		if ( empty( self::$nxt_gf_group_field_maps ) && function_exists( 'nxt_get_all_groups_for_context' ) ) {
			$groups = nxt_get_all_groups_for_context( array( 'screen' => 'option' ) );
			foreach ( $groups as $g ) {
				$k = isset( $g['key'] ) ? $g['key'] : 'group_' . ( isset( $g['id'] ) ? (int) $g['id'] : 0 );
				if ( array_key_exists( $k, self::$nxt_gf_group_field_maps ) ) {
					continue; // First group with this key wins (matches the previous break).
				}
				$map    = array();
				$fields = isset( $g['fields'] ) ? (array) $g['fields'] : array();
				foreach ( $fields as $f ) {
					if ( is_array( $f ) && ! empty( $f['name'] ) ) {
						$map[ $f['name'] ] = $f;
					}
				}
				self::$nxt_gf_group_field_maps[ $k ] = $map;
			}
		}
		if ( ! array_key_exists( $group_key, self::$nxt_gf_group_field_maps ) ) {
			self::$nxt_gf_group_field_maps[ $group_key ] = array();
		}
		return self::$nxt_gf_group_field_maps[ $group_key ];
	}

	/**
	 * Normalize stored page_link value to URL strings for the select.
	 *
	 * @param mixed $value    Stored meta (string or list of strings).
	 * @param bool  $multiple Multiple selection.
	 * @return string[]
	 */
	private function page_link_value_to_urls( $value, $multiple ) {
		$urls = array();
		if ( $multiple ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $u ) {
					if ( is_string( $u ) && '' !== $u ) {
						$urls[] = $u;
					}
				}
			}
		} elseif ( is_string( $value ) && '' !== $value ) {
			$urls[] = $value;
		}
		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
		return $urls;
	}

	/**
	 * Label for a stored page link URL in the select.
	 *
	 * @param string $url Escaped-safe URL.
	 * @return string
	 */
	private function page_link_option_label( $url ) {
		$id = url_to_postid( $url );
		if ( $id > 0 ) {
			$t = get_the_title( $id );
			if ( '' !== $t ) {
				return $t;
			}
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $path ) && '' !== $path ) {
			$base = wp_basename( $path );
			if ( '' !== $base ) {
				return $base;
			}
		}
		return $url;
	}

	/**
	 * Restrict saved URLs to the current site host (http/https).
	 *
	 * @param string $url   URL to check.
	 * @param array  $field Field definition, for any per-field host allowances.
	 * @return bool
	 */
	private function is_allowed_page_link_url( $url, array $field = array() ) {
		if ( '' === $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		$home = wp_parse_url( home_url( '/' ) );
		if ( empty( $parts['host'] ) || empty( $home['host'] ) ) {
			return false;
		}
		if ( strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
			return false;
		}

		// Same-host was the only check, so a URL for a post type the field excludes was accepted.
		$pts = isset( $field['page_link_post_types'] ) && is_array( $field['page_link_post_types'] ) ? array_map( 'sanitize_key', $field['page_link_post_types'] ) : array();
		$pts = array_values( array_filter( $pts ) );
		if ( empty( $pts ) ) {
			return true;
		}
		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return true;
		}
		return in_array( get_post_type( $post_id ), $pts, true );
	}

	/**
	 * Map a post ID (the ACF-style input) to its permalink; other values pass through.
	 *
	 * @param mixed $v Submitted value.
	 * @return mixed
	 */
	private function page_link_id_to_url( $v ) {
		if ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ) {
			$url = get_permalink( (int) $v );
			return $url ? $url : '';
		}
		return $v;
	}

	/**
	 * Sanitize page_link POST (URL string or array of URLs).
	 *
	 * @param mixed $raw   Submitted value.
	 * @param array $field Field config.
	 * @return array<int, string>|string
	 */
	private function sanitize_page_link_submitted( $raw, $field ) {
		$multiple = ! empty( $field['multiple'] );
		$list     = array();
		if ( $multiple ) {
			if ( ! is_array( $raw ) ) {
				$raw = ( '' !== $raw && null !== $raw ) ? array( $raw ) : array();
			}
			foreach ( $raw as $v ) {
				if ( '' === $v || null === $v ) {
					continue;
				}
				// Do NOT wp_unslash() here — the save loop already unslashed the whole value.
				$u = esc_url_raw( trim( (string) $this->page_link_id_to_url( $v ) ) );
				if ( '' !== $u && $this->is_allowed_page_link_url( $u, (array) $field ) ) {
					$list[] = $u;
				}
			}
			$list = array_values( array_unique( $list ) );
		} else {
			if ( is_array( $raw ) ) {
				$raw = isset( $raw[0] ) ? $raw[0] : '';
			}
			$u = esc_url_raw( trim( (string) $this->page_link_id_to_url( $raw ) ) );
			if ( '' !== $u && $this->is_allowed_page_link_url( $u, (array) $field ) ) {
				$list[] = $u;
			}
		}
		if ( empty( $list ) ) {
			return '';
		}
		return $multiple ? $list : $list[0];
	}

	/**
	 * Sync post terms when Taxonomy field has "Save terms" enabled.
	 *
	 * @param int     $post_id          Post ID.
	 * @param WP_Post $post             Post object.
	 * @param array   $field            Field config.
	 * @param mixed   $sanitized_value  Value after sanitize_taxonomy_submitted.
	 * @param mixed   $old_value        The field's previous stored value (for scoped removal).
	 */
	private function sync_taxonomy_field_to_post( $post_id, WP_Post $post, array $field, $sanitized_value, $old_value = null ) {
		if ( empty( $field['taxonomy_save_terms'] ) ) {
			return;
		}
		$tax = isset( $field['taxonomy_name'] ) ? sanitize_key( $field['taxonomy_name'] ) : '';
		if ( ! $tax || ! taxonomy_exists( $tax ) ) {
			return;
		}
		if ( ! is_object_in_taxonomy( $post->post_type, $tax ) ) {
			return;
		}

		$new_ids = $this->taxonomy_value_to_term_ids_for_sync( $sanitized_value, $field );
		$old_ids = ( null === $old_value ) ? array() : $this->taxonomy_value_to_term_ids_for_sync( $old_value, $field );

		// Add the current selection with append=true — NEVER replace.
		if ( ! empty( $new_ids ) ) {
			wp_set_object_terms( $post_id, $new_ids, $tax, true );
		}

		// Remove only the terms this field previously set that are no longer selected, so
		// clearing the field never touches terms assigned outside it.
		$to_remove = array_values( array_diff( $old_ids, $new_ids ) );
		if ( ! empty( $to_remove ) ) {
			wp_remove_object_terms( $post_id, $to_remove, $tax );
		}
	}

	/**
	 * Term IDs from stored taxonomy field value (for wp_set_object_terms).
	 *
	 * @param mixed $value Sanitized meta value.
	 * @param array $field Field config.
	 * @return int[]
	 */
	private function taxonomy_value_to_term_ids_for_sync( $value, array $field ) {
		$as_object = isset( $field['taxonomy_return_format'] ) && 'object' === $field['taxonomy_return_format'];
		$ids       = array();
		if ( $as_object ) {
			if ( is_array( $value ) ) {
				if ( isset( $value['term_id'] ) ) {
					$value = array( $value );
				}
				foreach ( $value as $row ) {
					if ( is_array( $row ) && ! empty( $row['term_id'] ) ) {
						$ids[] = (int) $row['term_id'];
					}
				}
			}
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( is_numeric( $v ) ) {
					$ids[] = (int) $v;
				}
			}
		} elseif ( is_numeric( $value ) ) {
			$ids[] = (int) $value;
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Flatten taxonomy terms parent-first for hierarchical display.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<int, array{term: WP_Term, depth: int}>
	 */
	private function get_flat_terms_hierarchical( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		$by_parent = array();
		foreach ( $terms as $t ) {
			$parent = (int) $t->parent;
			if ( ! isset( $by_parent[ $parent ] ) ) {
				$by_parent[ $parent ] = array();
			}
			$by_parent[ $parent ][] = $t;
		}
		$flat = array();
		$walk = function ( $parent_id, $depth ) use ( &$walk, &$flat, $by_parent ) {
			if ( empty( $by_parent[ $parent_id ] ) ) {
				return;
			}
			foreach ( $by_parent[ $parent_id ] as $t ) {
				$flat[] = array(
					'term'  => $t,
					'depth' => $depth,
				);
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $flat;
	}

	/**
	 * Selected term IDs for taxonomy field UI from stored meta.
	 *
	 * @param mixed $value       Stored value.
	 * @param bool  $return_obj  True when format is Term Object.
	 * @param bool  $multiple_ui Checkbox / multi select.
	 * @return int[]
	 */
	private function taxonomy_value_to_selected_ids( $value, $return_obj, $multiple_ui ) {
		$ids = array();
		if ( $return_obj ) {
			if ( $multiple_ui ) {
				if ( ! is_array( $value ) ) {
					return array();
				}
				foreach ( $value as $row ) {
					if ( is_array( $row ) && ! empty( $row['term_id'] ) ) {
						$ids[] = (int) $row['term_id'];
					}
				}
			} elseif ( is_array( $value ) && ! empty( $value['term_id'] ) ) {
				$ids[] = (int) $value['term_id'];
			}
		} elseif ( $multiple_ui ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $v ) {
					if ( is_numeric( $v ) ) {
						$ids[] = (int) $v;
					}
				}
			}
		} elseif ( is_numeric( $value ) ) {
			$ids[] = (int) $value;
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Sanitize taxonomy field POST (term IDs; optional object return shape).
	 *
	 * @param mixed $raw   Submitted value.
	 * @param array $field Field config.
	 * @return array<int|string, mixed>|int|string
	 */
	private function sanitize_taxonomy_submitted( $raw, array $field ) {
		$tax = isset( $field['taxonomy_name'] ) ? sanitize_key( $field['taxonomy_name'] ) : '';
		if ( ! $tax || ! taxonomy_exists( $tax ) ) {
			return '';
		}
		$tax_obj = get_taxonomy( $tax );
		if ( ! $tax_obj || ! current_user_can( $tax_obj->cap->assign_terms ) ) {
			return '';
		}
		$appearance = isset( $field['taxonomy_appearance'] ) ? sanitize_key( $field['taxonomy_appearance'] ) : 'checkbox';
		if ( ! in_array( $appearance, array( 'checkbox', 'multi_select', 'radio', 'select' ), true ) ) {
			$appearance = 'checkbox';
		}
		$multiple  = in_array( $appearance, array( 'checkbox', 'multi_select' ), true );
		$as_object = isset( $field['taxonomy_return_format'] ) && 'object' === $field['taxonomy_return_format'];
		$ids       = array();
		if ( $multiple ) {
			if ( ! is_array( $raw ) ) {
				$raw = ( '' !== $raw && null !== $raw ) ? array( $raw ) : array();
			}
			foreach ( $raw as $v ) {
				if ( '' === $v || null === $v ) {
					continue;
				}
				$id = (int) $v;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			$ids = array_values( array_unique( $ids ) );
		} else {
			if ( is_array( $raw ) ) {
				$raw = isset( $raw[0] ) ? $raw[0] : '';
			}
			$id = (int) $raw;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		if ( empty( $ids ) ) {
			return '';
		}
		$valid = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, $tax );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$valid[] = $id;
		}
		if ( empty( $valid ) ) {
			return '';
		}
		if ( ! $as_object ) {
			if ( $multiple ) {
				return array_map( 'intval', $valid );
			}
			return (int) $valid[0];
		}
		$objects = array();
		foreach ( $valid as $id ) {
			$term = get_term( $id, $tax );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$objects[] = array(
				'term_id'  => (int) $term->term_id,
				'name'     => $term->name,
				'slug'     => $term->slug,
				'taxonomy' => $term->taxonomy,
			);
		}
		if ( empty( $objects ) ) {
			return '';
		}
		if ( $multiple ) {
			return $objects;
		}
		return $objects[0];
	}

	/**
	 * Normalize stored Post Object value to attachment IDs for the metabox select.
	 *
	 * @param mixed $value       Stored meta.
	 * @param bool  $return_obj  True when storage is object-shaped.
	 * @param bool  $multiple    Multiple selection.
	 * @return int[]
	 */
	private function post_object_value_to_selected_ids( $value, $return_obj, $multiple ) {
		$ids = array();
		if ( $return_obj ) {
			if ( $multiple ) {
				if ( ! is_array( $value ) ) {
					return array();
				}
				foreach ( $value as $row ) {
					if ( is_array( $row ) && ! empty( $row['ID'] ) ) {
						$ids[] = (int) $row['ID'];
					}
				}
			} elseif ( is_array( $value ) && ! empty( $value['ID'] ) ) {
				$ids[] = (int) $value['ID'];
			}
		} elseif ( $multiple ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $v ) {
					if ( is_numeric( $v ) ) {
						$ids[] = (int) $v;
					}
				}
			}
		} elseif ( is_numeric( $value ) ) {
			$ids[] = (int) $value;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		return $ids;
	}

	/**
	 * Normalize stored User field value to user IDs for the select.
	 *
	 * @param mixed  $value    Stored meta.
	 * @param string $fmt      Return format (array|object|id).
	 * @param bool   $multiple Multiple selection.
	 * @return int[]
	 */
	private function user_value_to_selected_ids( $value, $fmt, $multiple ) {
		$ids = array();
		if ( 'id' === $fmt ) {
			if ( $multiple ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $v ) {
						if ( is_numeric( $v ) ) {
							$ids[] = (int) $v;
						}
					}
				}
			} elseif ( is_numeric( $value ) ) {
				$ids[] = (int) $value;
			}
		} elseif ( $multiple ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $row ) {
					if ( is_numeric( $row ) ) {
						$ids[] = (int) $row;
					} elseif ( is_array( $row ) ) {
						if ( isset( $row['ID'] ) && is_numeric( $row['ID'] ) ) {
							$ids[] = (int) $row['ID'];
						} elseif ( isset( $row['id'] ) && is_numeric( $row['id'] ) ) {
							$ids[] = (int) $row['id'];
						}
					}
				}
			}
		} elseif ( is_numeric( $value ) ) {
			$ids[] = (int) $value;
		} elseif ( is_array( $value ) ) {
			if ( isset( $value['ID'] ) && is_numeric( $value['ID'] ) ) {
				$ids[] = (int) $value['ID'];
			} elseif ( isset( $value['id'] ) && is_numeric( $value['id'] ) ) {
				$ids[] = (int) $value['id'];
			}
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Whether a post satisfies a Post Object field's own post-type / status / taxonomy filters.
	 *
	 * @param int   $post_id Candidate post ID.
	 * @param array $field   Field config.
	 * @return bool
	 */
	private function post_object_matches_field_filters( $post_id, array $field ) {
		$p = get_post( $post_id );
		if ( ! $p ) {
			return false;
		}

		$pts = isset( $field['post_object_post_types'] ) && is_array( $field['post_object_post_types'] ) ? array_map( 'sanitize_key', $field['post_object_post_types'] ) : array( 'post' );
		$pts = array_values( array_filter( $pts ) );
		if ( empty( $pts ) ) {
			$pts = array( 'post' );
		}
		if ( ! in_array( $p->post_type, $pts, true ) ) {
			return false;
		}

		$st = isset( $field['post_object_post_status'] ) && is_array( $field['post_object_post_status'] ) ? array_map( 'sanitize_key', $field['post_object_post_status'] ) : array();
		$st = array_values( array_filter( $st ) );
		if ( ! empty( $st ) && ! in_array( $p->post_status, $st, true ) ) {
			return false;
		}

		$pairs = isset( $field['post_object_taxonomy_terms'] ) && is_array( $field['post_object_taxonomy_terms'] ) ? $field['post_object_taxonomy_terms'] : array();
		$pairs = array_values( array_filter( $pairs, 'is_string' ) );
		if ( empty( $pairs ) ) {
			return true;
		}
		foreach ( $pairs as $pair ) {
			if ( strpos( $pair, ':' ) === false ) {
				continue;
			}
			list( $tax, $tid ) = explode( ':', $pair, 2 );
			$tax               = sanitize_key( $tax );
			$tid               = (int) $tid;
			if ( $tax && $tid > 0 && taxonomy_exists( $tax ) && has_term( $tid, $tax, $post_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sanitize Post Object POST data; store IDs or object-shaped arrays from submitted IDs.
	 *
	 * @param mixed $raw   Submitted value.
	 * @param array $field Field config.
	 * @return array<int>|int|string|array<string, mixed> Empty string if none.
	 */
	private function sanitize_post_object_submitted( $raw, $field ) {
		$fmt       = isset( $field['post_object_return_format'] ) ? $field['post_object_return_format'] : 'object';
		$as_object = ( 'object' === $fmt );
		$multiple  = ! empty( $field['multiple'] );
		$ids       = array();

		if ( $multiple ) {
			if ( ! is_array( $raw ) ) {
				$raw = ( '' !== $raw && null !== $raw ) ? array( $raw ) : array();
			}
			foreach ( $raw as $v ) {
				if ( '' === $v || null === $v ) {
					continue;
				}
				$id = (int) $v;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			$ids = array_values( array_unique( $ids ) );
		} else {
			if ( is_array( $raw ) ) {
				$raw = isset( $raw[0] ) ? $raw[0] : '';
			}
			$id = (int) $raw;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		if ( empty( $ids ) ) {
			return '';
		}

		$valid = array();
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( ! $p || ! current_user_can( 'read_post', $id ) ) {
				continue;
			}
			// Enforce the field's own post-type / status / taxonomy filters, not just existence.
			if ( ! $this->post_object_matches_field_filters( $id, (array) $field ) ) {
				continue;
			}
			$valid[] = $id;
		}
		if ( empty( $valid ) ) {
			return '';
		}

		if ( ! $as_object ) {
			if ( $multiple ) {
				return array_map( 'intval', $valid );
			}
			return (int) $valid[0];
		}

		$objects = array();
		foreach ( $valid as $id ) {
			$p         = get_post( $id );
			$objects[] = array(
				'ID'         => $p->ID,
				'post_title' => $p->post_title,
				'post_type'  => $p->post_type,
				'permalink'  => get_permalink( $p ),
			);
		}
		if ( $multiple ) {
			return $objects;
		}
		return $objects[0];
	}

	/**
	 * Sanitize User field POST data; store the validated user ID(s) only.
	 *
	 * @param mixed $raw   Submitted value.
	 * @param array $field Field config.
	 * @return mixed
	 */
	private function sanitize_user_submitted( $raw, $field ) {
		$multiple = ! empty( $field['multiple'] );
		$roles    = isset( $field['user_role_filter'] ) && is_array( $field['user_role_filter'] ) ? array_map( 'sanitize_key', $field['user_role_filter'] ) : array();
		$roles    = array_values( array_filter( $roles ) );
		$ids      = array();

		if ( $multiple ) {
			if ( ! is_array( $raw ) ) {
				$raw = ( '' !== $raw && null !== $raw ) ? array( $raw ) : array();
			}
			foreach ( $raw as $v ) {
				if ( '' === $v || null === $v ) {
					continue;
				}
				$id = (int) $v;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			$ids = array_values( array_unique( $ids ) );
		} else {
			if ( is_array( $raw ) ) {
				$raw = isset( $raw[0] ) ? $raw[0] : '';
			}
			$id = (int) $raw;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		if ( empty( $ids ) ) {
			return '';
		}

		$valid_users = array();
		foreach ( $ids as $id ) {
			$u = get_user_by( 'id', $id );
			if ( ! $u instanceof WP_User ) {
				continue;
			}
			if ( ! empty( $roles ) && empty( array_intersect( $roles, (array) $u->roles ) ) ) {
				continue;
			}
			$valid_users[] = $u;
		}
		if ( empty( $valid_users ) ) {
			return '';
		}

		// Store IDs only, never a copy of another user's login/email; reads rebuild the return format.
		$id_list = array_map(
			static function ( $u ) {
				return (int) $u->ID;
			},
			$valid_users
		);
		return $multiple ? $id_list : (int) $id_list[0];
	}

	/**
	 * Strictly parse date string with a PHP date format.
	 *
	 * @param string $value  Date string.
	 * @param string $format PHP date format.
	 * @return DateTime|false
	 */
	private function parse_date_with_format( $value, $format ) {
		$value  = trim( (string) $value );
		$format = trim( (string) $format );
		if ( '' === $value || '' === $format ) {
			return false;
		}
		$dt = DateTime::createFromFormat( '!' . $format, $value );
		if ( ! $dt ) {
			return false;
		}
		$errs = DateTime::getLastErrors();
		if ( ! empty( $errs['warning_count'] ) || ! empty( $errs['error_count'] ) ) {
			return false;
		}
		if ( $dt->format( $format ) !== $value ) {
			return false;
		}
		return $dt;
	}

	/**
	 * Convert stored date value to display format for the editor input.
	 *
	 * @param mixed  $value       Stored value.
	 * @param string $display_fmt Input display format.
	 * @param string $return_fmt  Stored return format.
	 * @return string
	 */
	private function normalize_date_value_for_display( $value, $display_fmt, $return_fmt ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return '';
		}
		$dt = $this->parse_date_with_format( $raw, $return_fmt );
		if ( ! $dt && 'Y-m-d' !== $return_fmt ) {
			$dt = $this->parse_date_with_format( $raw, 'Y-m-d' );
		}
		if ( ! $dt ) {
			return $raw;
		}
		return $dt->format( $display_fmt );
	}

	/**
	 * Strip time tokens from a PHP datetime display format (datepicker is date-only).
	 *
	 * @param string $fmt Full display format.
	 * @return string
	 */
	private function datetime_display_date_only_format( $fmt ) {
		$fmt = trim( (string) $fmt );
		if ( '' === $fmt ) {
			return 'd/m/Y';
		}
		$fmt = preg_replace( '/\s+[gGhH]\s*:\s*i(\s*:\s*s)?(\s+[aA])?$/', '', $fmt );
		return trim( $fmt ) !== '' ? trim( $fmt ) : 'd/m/Y';
	}

	/**
	 * Client-side config for datetime field (date + time UI).
	 *
	 * @param string $display_fmt   Full PHP display format.
	 * @param string $date_only_fmt Date portion only.
	 * @return array
	 */
	private function datetime_field_client_ui_config( $display_fmt, $date_only_fmt ) {
		$date_only_fmt = trim( (string) $date_only_fmt );
		$order         = 'dmY';
		if ( preg_match( '/^m\/d\/Y/', $date_only_fmt ) ) {
			$order = 'mdY';
		} elseif ( preg_match( '/^Y-m-d/', $date_only_fmt ) ) {
			$order = 'Ymd';
		} elseif ( false !== strpos( $date_only_fmt, 'F' ) ) {
			$order = 'Fjy';
		}

		$has_ampm = (bool) preg_match( '/(^|[^\\\\])[aA]/', $display_fmt );
		$hour12   = $has_ampm && (bool) preg_match( '/(^|[^\\\\])[gGh]/', $display_fmt );

		$seconds = (bool) preg_match( '/(^|[^\\\\])s/', $display_fmt );

		$pad_day     = (bool) preg_match( '/(^|[^\\\\])d/', $date_only_fmt );
		$pad_month   = (bool) preg_match( '/(^|[^\\\\])m/', $date_only_fmt );
		$day_j       = (bool) preg_match( '/(^|[^\\\\])j/', $date_only_fmt );
		$hour_no_pad = (bool) preg_match( '/(^|[^\\\\])g/', $display_fmt );

		$months = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$months[] = wp_date( 'F', gmmktime( 0, 0, 0, $m, 1, 2020 ) );
		}

		return array(
			'order'       => $order,
			'hour12'      => $hour12,
			'seconds'     => $seconds,
			'padDay'      => $pad_day,
			'padMonth'    => $pad_month,
			'dayNoPad'    => $day_j && ! $pad_day,
			'hourNoPad'   => $hour_no_pad,
			'months'      => $months,
			'dateOnlyPhp' => $date_only_fmt,
			'i18n'        => array(
				'time'   => __( 'Time', 'nexter-extension' ),
				'hour'   => __( 'Hour', 'nexter-extension' ),
				'minute' => __( 'Minute', 'nexter-extension' ),
				'second' => __( 'Second', 'nexter-extension' ),
				'now'    => __( 'Now', 'nexter-extension' ),
				'done'   => __( 'Done', 'nexter-extension' ),
			),
		);
	}

	/**
	 * Client config for time-only field (picker UI).
	 *
	 * @param string $display_fmt Resolved PHP display format.
	 * @return array
	 */
	private function time_field_client_ui_config( $display_fmt ) {
		$has_ampm = (bool) preg_match( '/(^|[^\\\\])[aA]/', $display_fmt );
		$hour12   = $has_ampm && (bool) preg_match( '/(^|[^\\\\])[gGh]/', $display_fmt );
		if ( ! preg_match( '/(^|[^\\\\])[aA]/', $display_fmt ) ) {
			$hour12 = false;
		}
		$seconds     = (bool) preg_match( '/(^|[^\\\\])s/', $display_fmt );
		$hour_no_pad = (bool) preg_match( '/(^|[^\\\\])g/', $display_fmt );

		return array(
			'hour12'    => $hour12,
			'seconds'   => $seconds,
			'hourNoPad' => $hour_no_pad,
			'i18n'      => array(
				'chooseTime' => __( 'Choose Time', 'nexter-extension' ),
				'time'       => __( 'Time', 'nexter-extension' ),
				'hour'       => __( 'Hour', 'nexter-extension' ),
				'minute'     => __( 'Minute', 'nexter-extension' ),
				'second'     => __( 'Second', 'nexter-extension' ),
				'now'        => __( 'Now', 'nexter-extension' ),
				'select'     => __( 'Select', 'nexter-extension' ),
			),
		);
	}

	/**
	 * Parse a time string with a PHP time format.
	 *
	 * @param string $value  Time string.
	 * @param string $format PHP date/time format (time tokens only).
	 * @return DateTime|false
	 */
	private function parse_time_with_format( $value, $format ) {
		$value  = trim( (string) $value );
		$format = trim( (string) $format );
		if ( '' === $value || '' === $format ) {
			return false;
		}
		$dt = DateTime::createFromFormat( $format, $value );
		if ( ! $dt ) {
			return false;
		}
		$errs = DateTime::getLastErrors();
		if ( ! empty( $errs['warning_count'] ) || ! empty( $errs['error_count'] ) ) {
			return false;
		}
		return $dt;
	}

	/**
	 * Convert stored time value to display format for the editor.
	 *
	 * @param mixed  $value       Stored value.
	 * @param string $display_fmt Display format.
	 * @param string $return_fmt  Stored format.
	 * @return string
	 */
	private function normalize_time_value_for_display( $value, $display_fmt, $return_fmt ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return '';
		}
		$dt = $this->parse_time_with_format( $raw, $return_fmt );
		if ( ! $dt ) {
			$dt = $this->parse_time_with_format( $raw, 'H:i:s' );
		}
		if ( ! $dt ) {
			return $raw;
		}
		return $dt->format( $display_fmt );
	}

	/**
	 * Convert stored datetime value to display format for the editor input.
	 *
	 * @param mixed  $value       Stored value.
	 * @param string $display_fmt Input display format.
	 * @param string $return_fmt  Stored return format.
	 * @return string
	 */
	private function normalize_datetime_value_for_display( $value, $display_fmt, $return_fmt ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return '';
		}
		$dt = $this->parse_date_with_format( $raw, $return_fmt );
		if ( ! $dt ) {
			$dt = $this->parse_date_with_format( $raw, 'Y-m-d H:i:s' );
		}
		if ( ! $dt ) {
			return $raw;
		}
		return $dt->format( $display_fmt );
	}

	/**
	 * Sanitize Date field input and store using configured return format.
	 *
	 * @param mixed $raw   Submitted value.
	 * @param array $field Field config.
	 * @return string
	 */
	private function sanitize_date_submitted( $raw, array $field ) {
		$val = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $val ) {
			return '';
		}
		$display_fmt = isset( $field['date_display_format'] ) ? (string) $field['date_display_format'] : 'd/m/Y';
		if ( 'custom' === $display_fmt ) {
			$display_fmt = isset( $field['date_custom_display_format'] ) && '' !== trim( (string) $field['date_custom_display_format'] ) ? (string) $field['date_custom_display_format'] : 'd/m/Y';
		}
		$return_fmt = isset( $field['date_return_format'] ) && '' !== trim( (string) $field['date_return_format'] ) ? (string) $field['date_return_format'] : 'd/m/Y';
		$dt         = $this->parse_datetime_candidates( $val, array( $display_fmt, $return_fmt, 'Y-m-d', 'Y-m-d H:i:s' ) );
		if ( ! $dt ) {
			return '';
		}
		return $dt->format( $return_fmt );
	}

	/**
	 * Try a list of formats in order and return the first that parses strictly.
	 *
	 * @param string   $value   Submitted value.
	 * @param string[] $formats Candidate formats, most specific first.
	 * @return DateTime|false
	 */
	private function parse_datetime_candidates( $value, array $formats ) {
		$seen = array();
		foreach ( $formats as $fmt ) {
			$fmt = (string) $fmt;
			if ( '' === $fmt || isset( $seen[ $fmt ] ) ) {
				continue;
			}
			$seen[ $fmt ] = true;
			$dt           = $this->parse_date_with_format( $value, $fmt );
			if ( $dt ) {
				return $dt;
			}
		}
		return false;
	}

	/**
	 * Sanitize DateTime field input and store using configured return format.
	 *
	 * @param mixed $raw   Submitted value.
	 * @param array $field Field config.
	 * @return string
	 */
	private function sanitize_datetime_submitted( $raw, array $field ) {
		$val = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $val ) {
			return '';
		}
		$display_fmt = isset( $field['datetime_display_format'] ) ? (string) $field['datetime_display_format'] : 'd/m/Y g:i a';
		if ( 'custom' === $display_fmt ) {
			$display_fmt = isset( $field['datetime_custom_display_format'] ) && '' !== trim( (string) $field['datetime_custom_display_format'] ) ? (string) $field['datetime_custom_display_format'] : 'd/m/Y g:i a';
		}
		$return_fmt = isset( $field['datetime_return_format'] ) && '' !== trim( (string) $field['datetime_return_format'] ) ? (string) $field['datetime_return_format'] : 'd/m/Y g:i a';
		$dt         = $this->parse_datetime_candidates( $val, array( $display_fmt, $return_fmt, 'Y-m-d H:i:s', 'Y-m-d H:i' ) );
		if ( ! $dt ) {
			return '';
		}
		return $dt->format( $return_fmt );
	}

	/**
	 * Sanitize Time field input and store using configured return format.
	 *
	 * @param mixed $raw   Submitted value.
	 * @param array $field Field config.
	 * @return string
	 */
	private function sanitize_time_submitted( $raw, array $field ) {
		$val = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $val ) {
			return '';
		}
		$display_fmt = isset( $field['time_display_format'] ) ? (string) $field['time_display_format'] : 'g:i a';
		if ( 'custom' === $display_fmt ) {
			$display_fmt = isset( $field['time_custom_display_format'] ) && '' !== trim( (string) $field['time_custom_display_format'] ) ? (string) $field['time_custom_display_format'] : 'g:i a';
		}
		$return_fmt = isset( $field['time_return_format'] ) ? (string) $field['time_return_format'] : 'g:i a';
		if ( 'custom' === $return_fmt ) {
			$return_fmt = isset( $field['time_custom_return_format'] ) && '' !== trim( (string) $field['time_custom_return_format'] ) ? (string) $field['time_custom_return_format'] : 'g:i a';
		}
		$dt = false;
		foreach ( array( $display_fmt, $return_fmt, 'H:i:s', 'H:i', 'g:i a' ) as $candidate ) {
			if ( '' === (string) $candidate ) {
				continue;
			}
			$dt = $this->parse_time_with_format( $val, (string) $candidate );
			if ( $dt ) {
				break;
			}
		}
		if ( ! $dt ) {
			return '';
		}
		return $dt->format( $return_fmt );
	}

	/**
	 * Parse a CSS hex, rgb(), or rgba() color string.
	 *
	 * @param string $color Color string.
	 * @return array{r:int,g:int,b:int,a:float}|null
	 */
	private function parse_css_color_to_rgba( $color ) {
		$color = trim( (string) $color );
		if ( '' === $color ) {
			return null;
		}
		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color, $m ) ) {
			$hex = $m[1];
			if ( strlen( $hex ) === 3 ) {
				$r = hexdec( str_repeat( $hex[0], 2 ) );
				$g = hexdec( str_repeat( $hex[1], 2 ) );
				$b = hexdec( str_repeat( $hex[2], 2 ) );
			} else {
				$r = hexdec( substr( $hex, 0, 2 ) );
				$g = hexdec( substr( $hex, 2, 2 ) );
				$b = hexdec( substr( $hex, 4, 2 ) );
			}
			return array(
				'r' => (int) $r,
				'g' => (int) $g,
				'b' => (int) $b,
				'a' => 1.0,
			);
		}
		if ( preg_match( '/^rgba?\(\s*([0-9]+)\s*,\s*([0-9]+)\s*,\s*([0-9]+)\s*(?:,\s*([0-9.]+)\s*)?\)$/i', $color, $m ) ) {
			// Both halves are load-bearing: an unmatched TRAILING optional group is omitted from $m entirely on some
			// PCRE builds and captured as '' on others, and (float) '' is 0.0 — a fully transparent colour.
			$a = isset( $m[4] ) && '' !== $m[4] ? (float) $m[4] : 1.0;
			return array(
				'r' => max( 0, min( 255, (int) $m[1] ) ),
				'g' => max( 0, min( 255, (int) $m[2] ) ),
				'b' => max( 0, min( 255, (int) $m[3] ) ),
				'a' => max( 0.0, min( 1.0, $a ) ),
			);
		}
		return null;
	}

	/**
	 * Normalize stored color meta for the editor input (wp color picker).
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	private function normalize_color_value_for_input( $value ) {
		if ( is_array( $value ) ) {
			$r = isset( $value['r'] ) ? (int) $value['r'] : 0;
			$g = isset( $value['g'] ) ? (int) $value['g'] : 0;
			$b = isset( $value['b'] ) ? (int) $value['b'] : 0;
			$a = isset( $value['a'] ) ? (float) $value['a'] : 1.0;
			$r = max( 0, min( 255, $r ) );
			$g = max( 0, min( 255, $g ) );
			$b = max( 0, min( 255, $b ) );
			$a = max( 0.0, min( 1.0, $a ) );
			if ( $a >= 0.999 ) {
				return sprintf( '#%02x%02x%02x', $r, $g, $b );
			}
			$as = (string) round( $a, 4 );
			return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, $as );
		}
		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}
		return '';
	}

	/**
	 * Build Iris / wpColorPicker palettes option (true, false, or list of colors).
	 *
	 * @param array $field Field config.
	 * @return array<int,string>|bool
	 */
	private function build_color_palettes_for_field( array $field ) {
		if ( empty( $field['color_show_custom_palette'] ) ) {
			return true;
		}
		$source = isset( $field['color_palette_source'] ) ? sanitize_key( $field['color_palette_source'] ) : 'custom';
		if ( 'theme_json' === $source ) {
			$colors = $this->get_theme_json_palette_colors();
			return ! empty( $colors ) ? $colors : true;
		}
		$str   = isset( $field['color_custom_palette'] ) ? (string) $field['color_custom_palette'] : '';
		$parts = array_map( 'trim', explode( ',', $str ) );
		$out   = array();
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			if ( $this->parse_css_color_to_rgba( $p ) ) {
				$out[] = $p;
			}
		}
		return ! empty( $out ) ? $out : true;
	}

	/**
	 * Colors from merged theme.json palette (global settings).
	 *
	 * @return array<int,string>
	 */
	private function get_theme_json_palette_colors() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		$settings = wp_get_global_settings();
		if ( empty( $settings['color']['palette'] ) || ! is_array( $settings['color']['palette'] ) ) {
			return array();
		}
		$colors = array();
		foreach ( $settings['color']['palette'] as $entry ) {
			if ( ! empty( $entry['color'] ) && is_string( $entry['color'] ) ) {
				$colors[] = $entry['color'];
			}
		}
		return $colors;
	}

	/**
	 * Sanitize Color field input using return format.
	 *
	 * @param mixed $raw   Submitted value.
	 * @param array $field Field config.
	 * @return array<string,int|float>|string
	 */
	private function sanitize_color_submitted( $raw, array $field ) {
		// No wp_unslash() here — the save loop already unslashed (see sanitize_link_field_array()).
		$val = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $val ) {
			return '';
		}
		$rgba = $this->parse_css_color_to_rgba( $val );
		if ( ! $rgba ) {
			return '';
		}
		$enable_alpha = ! empty( $field['color_enable_transparency'] );
		$rf           = isset( $field['color_return_format'] ) ? (string) $field['color_return_format'] : ( $enable_alpha ? 'rgba_string' : 'hex' );
		if ( ! $enable_alpha ) {
			$rgba['a'] = 1.0;
			if ( 'rgba_string' === $rf ) {
				$rf = 'hex';
			}
		} elseif ( 'hex' === $rf ) {
				$rf = 'rgba_string';
		}
		if ( 'rgba_array' === $rf ) {
			return array(
				'r' => (int) $rgba['r'],
				'g' => (int) $rgba['g'],
				'b' => (int) $rgba['b'],
				'a' => (float) $rgba['a'],
			);
		}
		if ( 'hex' === $rf ) {
			return sprintf( '#%02x%02x%02x', $rgba['r'], $rgba['g'], $rgba['b'] );
		}
		if ( $rgba['a'] >= 0.999 ) {
			return sprintf( 'rgb(%d,%d,%d)', $rgba['r'], $rgba['g'], $rgba['b'] );
		}
		$as = (string) round( $rgba['a'], 4 );
		return sprintf( 'rgba(%d,%d,%d,%s)', $rgba['r'], $rgba['g'], $rgba['b'], $as );
	}

	/**
	 * Sanitize a value using field type (repeater, post_object, link) or generic deep sanitize.
	 *
	 * @param mixed $raw   Raw POST fragment.
	 * @param array $field Field config.
	 * @return mixed
	 */
	/**
	 * Normalize a select/checkbox/radio field's choices to a flat [value => label] map.
	 *
	 * @param mixed $choices Raw choices value.
	 * @return array<string, string>
	 */
	/**
	 * Return the valid choice VALUES declared for a select/checkbox/radio field, parsing the same
	 * array-or-"value : label"-lines shape the renderer accepts.
	 *
	 * @param array $field Field config.
	 * @return string[] Declared choice values as strings.
	 */
	private function field_choice_keys( $field ) {
		$choices = isset( $field['choices'] ) ? $field['choices'] : array();
		if ( is_string( $choices ) ) {
			$parsed = array();
			foreach ( preg_split( '/\r\n|\r|\n/', $choices ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				if ( preg_match( '/^\s*(.+?)\s*:\s*(.+)\s*$/', $line, $m ) ) {
					$parsed[ trim( $m[1] ) ] = trim( $m[2] );
				} else {
					$parsed[ $line ] = $line;
				}
			}
			$choices = $parsed;
		}
		$choices = $this->normalize_choices_shape( is_array( $choices ) ? $choices : array() );
		return array_map( 'strval', array_keys( $choices ) );
	}

	/**
	 * Normalize choices shape.
	 *
	 * @param mixed $choices Value.
	 */
	private function normalize_choices_shape( $choices ) {
		if ( ! is_array( $choices ) ) {
			return array();
		}
		$keys       = array_keys( $choices );
		$is_indexed = ( array_keys( $keys ) === $keys );
		if ( ! $is_indexed ) {
			return $choices;
		}
		$first = reset( $choices );
		if ( ! is_array( $first ) || ! array_key_exists( 'value', $first ) ) {
			// An indexed list of scalars would otherwise make the array index the option value, so a
			// reorder rewrites stored data. Keep in step with nxt_field_choice_keys().
			if ( ! is_array( $first ) ) {
				$out = array();
				foreach ( $choices as $choice ) {
					if ( is_scalar( $choice ) ) {
						$out[ (string) $choice ] = (string) $choice;
					}
				}
				return $out;
			}
			return $choices;
		}
		$out = array();
		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) || ! array_key_exists( 'value', $choice ) ) {
				continue;
			}
			$value         = (string) $choice['value'];
			$out[ $value ] = isset( $choice['label'] ) ? (string) $choice['label'] : $value;
		}
		return $out;
	}

	/**
	 * Public entry point to the per-type value sanitizer, for callers that write a field value without going
	 * through one of the save_* form handlers.
	 *
	 * @param mixed $raw   Unsanitized value, already unslashed.
	 * @param array $field Field config the value belongs to.
	 * @return mixed Sanitized value.
	 */
	public function sanitize_value_for_field( $raw, array $field ) {
		return $this->sanitize_field_value_by_type( $raw, $field );
	}

	/**
	 * Public entry to the input renderer, so a type rendered elsewhere can render its sub-fields.
	 *
	 * @param array  $field        Field config.
	 * @param string $meta_key     Meta key / element id.
	 * @param string $input_name   Form input name.
	 * @param mixed  $value        Current value.
	 * @param bool   $value_exists Whether the meta row exists.
	 */
	public function render_field_input( $field, $meta_key, $input_name, $value, $value_exists = false ) {
		$this->render_field_input_common( $field, $meta_key, $input_name, $value, $value_exists );
	}

	/**
	 * Sanitize field value by type.
	 *
	 * @param mixed $raw   Value.
	 * @param mixed $field Value.
	 */
	private function sanitize_field_value_by_type( $raw, $field ) {
		$type = isset( $field['type'] ) ? $field['type'] : 'text';
		// A type sanitized elsewhere (Nexter Pro) returns a non-null value.
		$pre = apply_filters( 'nxt/pre_sanitize_field_value', null, $raw, $field, $this );
		if ( null !== $pre ) {
			return $pre;
		}
		if ( 'taxonomy' === $type ) {
			return $this->sanitize_taxonomy_submitted( $raw, $field );
		}
		if ( 'post_object' === $type ) {
			return $this->sanitize_post_object_submitted( $raw, $field );
		}
		if ( 'user' === $type ) {
			return $this->sanitize_user_submitted( $raw, $field );
		}
		if ( 'date' === $type ) {
			return $this->sanitize_date_submitted( $raw, $field );
		}
		if ( 'datetime' === $type ) {
			return $this->sanitize_datetime_submitted( $raw, $field );
		}
		if ( 'time' === $type ) {
			return $this->sanitize_time_submitted( $raw, $field );
		}
		if ( 'color' === $type ) {
			return $this->sanitize_color_submitted( $raw, $field );
		}
		if ( 'page_link' === $type ) {
			return $this->sanitize_page_link_submitted( $raw, $field );
		}
		if ( 'link' === $type && is_array( $raw ) ) {
			// Any link array goes through the URL allowlist; an unchecked target used to skip it.
			return $this->sanitize_link_field_array( $raw );
		}
		if ( 'wysiwyg' === $type ) {
			return is_string( $raw ) ? wp_kses_post( $raw ) : '';
		}
		if ( 'true_false' === $type ) {
			// A plain (bool) cast treats the strings 'false'/'no' as truthy; nxt_to_bool() maps
			// the common falsy spellings explicitly first (see nxt-field-api.php).
			return function_exists( 'nxt_to_bool' ) ? nxt_to_bool( $raw ) : (bool) $raw;
		}
		if ( 'textarea' === $type ) {
			// Preserve line breaks — sanitize_text_field() (the sanitize_deep fallback) would collapse every newline
			// to a space, losing all multi-line formatting on save.
			return is_string( $raw ) ? sanitize_textarea_field( $raw ) : '';
		}
		if ( 'url' === $type ) {
			// esc_url_raw() enforces a protocol allowlist, so a `javascript:` (or `data:`) URI is stripped at save.
			return is_string( $raw ) ? esc_url_raw( $raw ) : '';
		}
		if ( 'email' === $type ) {
			// Validate, don't just sanitize: sanitize_email() strips bad characters but still keeps a non-address
			// like 'not-a-real-email', so require a well-formed address and otherwise store empty.
			if ( ! is_string( $raw ) ) {
				return '';
			}
			$email = sanitize_email( $raw );
			return ( '' !== $email && is_email( $email ) ) ? $email : '';
		}
		if ( 'number' === $type || 'range' === $type ) {
			// Coerce to a real number so downstream math (e.g.
			if ( is_array( $raw ) ) {
				return '';
			}
			$val = is_string( $raw ) ? trim( $raw ) : $raw;
			if ( '' === $val || null === $val || ! is_numeric( $val ) ) {
				return '';
			}
			// An integer literal too large for PHP_INT_MAX would be promoted to a float and stored in scientific
			// notation ("1.0E+24"), so input and stored value stop being the same number.
			if ( is_string( $val ) && preg_match( '/^[+-]?\d+$/', $val )
				&& ( strlen( ltrim( $val, '+-0' ) ) > strlen( (string) PHP_INT_MAX ) || ! is_int( $val + 0 ) )
			) {
				return $val;
			}
			$num = $val + 0; // int or float, preserving the original numeric type.

			// min / max / step are rendered as HTML attributes, which only the browser honours.
			$has_bound = static function ( $v ) {
				return isset( $v ) && '' !== $v && is_numeric( $v );
			};
			$min       = isset( $field['min'] ) ? $field['min'] : '';
			$max       = isset( $field['max'] ) ? $field['max'] : '';
			if ( $has_bound( $min ) && $num < ( $min + 0 ) ) {
				$num = $min + 0;
			}
			if ( $has_bound( $max ) && $num > ( $max + 0 ) ) {
				$num = $max + 0;
			}
			// Snap to the nearest valid step from the lower bound (mirrors how a browser resolves an
			// out-of-step value), rather than rejecting an otherwise usable number.
			$step = isset( $field['step'] ) ? $field['step'] : '';
			if ( $has_bound( $step ) && ( $step + 0 ) > 0 ) {
				$stepf = $step + 0;
				$base  = $has_bound( $min ) ? ( $min + 0 ) : 0;
				$snap  = $base + ( round( ( $num - $base ) / $stepf ) * $stepf );
				// Guard against float drift pushing the snapped value back outside the bounds.
				if ( $has_bound( $min ) && $snap < ( $min + 0 ) ) {
					$snap = $min + 0;
				}
				if ( $has_bound( $max ) && $snap > ( $max + 0 ) ) {
					$snap = $max + 0;
				}
				$num = ( is_int( $num ) && is_int( $stepf ) && 0.0 === fmod( $snap, 1.0 ) ) ? (int) $snap : $snap;
			}
			return $num;
		}
		if ( 'radio' === $type || 'checkbox' === $type || 'select' === $type ) {
			// Validate against the field's own declared choices so a tampered submission can't store a value never offered.
			$valid = $this->field_choice_keys( $field );
			if ( empty( $valid ) ) {
				return $this->sanitize_deep( $raw );
			}
			// Single-value shapes (radio, single select): keep the value only if it's a declared choice.
			if ( 'radio' === $type || ( 'select' === $type && ! is_array( $raw ) ) ) {
				$v = is_scalar( $raw ) ? (string) $raw : '';
				return in_array( $v, $valid, true ) ? $v : '';
			}
			// Multi-value shapes (checkbox, multi-select): keep only submitted values that are declared choices.
			$submitted = is_array( $raw ) ? $raw : ( ( '' !== $raw && null !== $raw ) ? array( $raw ) : array() );
			$out       = array();
			foreach ( $submitted as $v ) {
				if ( is_scalar( $v ) && in_array( (string) $v, $valid, true ) ) {
					$out[] = (string) $v;
				}
			}
			return array_values( array_unique( $out ) );
		}
		if ( 'image' === $type || 'file' === $type ) {
			// Store only references to real attachments; reject IDs that don't resolve to one (tampered or
			// stale-at-submit), mirroring how the relationship field validates its post references.
			if ( is_array( $raw ) ) {
				$ids = array();
				foreach ( $raw as $one ) {
					$id = absint( $one );
					if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
						$ids[] = $id;
					}
				}
				return $ids;
			}
			$id = absint( $raw );
			return ( $id > 0 && 'attachment' === get_post_type( $id ) ) ? $id : '';
		}
		return $this->sanitize_deep( $raw );
	}

	/**
	 * Recursively sanitize a value (scalar or nested array).
	 *
	 * @param mixed $value Raw value (possibly array).
	 * @return mixed Sanitized value.
	 */
	private function sanitize_deep( $value ) {
		if ( is_array( $value ) ) {
			$keys = array_keys( $value );
			sort( $keys );
			// Link field POST shape: url, title, target (also used inside repeater rows).
			if ( array( 'target', 'title', 'url' ) === $keys ) {
				return $this->sanitize_link_field_array( $value );
			}
			$clean = array();
			foreach ( $value as $k => $v ) {
				$clean[ $k ] = $this->sanitize_deep( $v );
			}
			return $clean;
		}

		if ( is_scalar( $value ) || null === $value ) {
			return sanitize_text_field( (string) $value );
		}

		return '';
	}

	/**
	 * Sanitize link field values from POST (hidden url/title/target).
	 *
	 * @param array $row Raw array with keys url, title, target.
	 * @return array|string Sanitized link array or empty string if no URL.
	 */
	private function sanitize_link_field_array( $row ) {
		// Callers already wp_unslash() the whole value, so do NOT unslash again: that stripped a level of backslashes.
		$url    = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ) ) : '';
		$title  = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
		$target = isset( $row['target'] ) ? (string) $row['target'] : '';
		$target = ( '1' === $target || '_blank' === $target ) ? '_blank' : '';

		if ( '' === $url ) {
			return '';
		}

		return array(
			'url'    => $url,
			'title'  => $title,
			'target' => $target,
		);
	}
}
