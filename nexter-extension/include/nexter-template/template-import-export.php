<?php
/*
 * Nexter Builder Import/Export
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */
class Nexter_Builder_Import_Export {
	
	/**
	 * Member Variable
	 */
	private static $instance;

	const NXT_NONCE_KEY = 'nxt_ajax';
	
	const ADMIN_SCREEN_ID = 'edit-'.NEXTER_EXT_CPT;
	
	/**
	 *  Initiator
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
			}
		return self::$instance;
	}
	
	/*
	 * Nexter Builder Local
	 */
	public function __construct() {
		$this->add_actions();
	}
	
	/**
	 * Add Nexter Builder Actions
	 *
	 * @access private
	 */
	private function add_actions() {
		if( is_admin() ){
			add_filter( 'admin_footer', [ $this, 'nxt_import_template_form' ] );
			add_filter( 'post_row_actions', [ $this, 'post_row_actions_builder_export_link' ], 10, 2 );
		}
		
		add_action( 'wp_ajax_nxt_builder_export_actions', [ $this, 'builder_import_export_actions' ] );
		add_action( 'wp_ajax_nxt_builder_import_actions', [ $this, 'builder_import_export_actions' ] );
	}
	
	/** Action Error Handle
	 *
	 * @since 1.0.0
	 */
	private function handle_direct_action_error( $message ) {
		_default_wp_die_handler( $message, 'Nexter Builder' );
	}
	
	/**
	 * Check edit Page templates current screen.
	 *
	 * @since 1.0.0
	 */
	public static function check_current_screen_templates() {
		global $current_screen;

		if ( ! $current_screen ) {
			return false;
		}

		return $current_screen->base === 'edit' && $current_screen->post_type === NEXTER_EXT_CPT;
	}
	
	/**
	 * Add link export builder Post row actions
	 */
	public function post_row_actions_builder_export_link( $actions, \WP_Post $post ) {
		if ( self::check_current_screen_templates() ) {
			$actions['nxt-export-template'] = sprintf( '<a href="%1$s">%2$s</a>', $this->get_export_link( $post->ID ), __( 'Export Template', 'nexter-extension' ) );
		}

		return $actions;
	}
	
	/**
	 * check current user can edit post type.
	 */
	public function check_current_user_edit_post_type( $post_type ) {
		
		if ( ! $this->check_post_type_exits( $post_type ) ) {
			return false;
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( ! current_user_can( $post_type_object->cap->edit_posts ) ) {
			return false;
		}

		return true;
	}
	
	public function check_post_type_exits( $post_type ) {
		if ( ! post_type_exists( $post_type ) ) {
			return false;
		}
		return true;
	}
	
	/** Builder Import/Export Actions
	 *
	 * @since 1.0.0
	 */
	public function builder_import_export_actions() {
	
		if ( ! $this->check_current_user_edit_post_type( NEXTER_EXT_CPT ) ) {
			return;
		}

		if ( ! $this->verify_request_nonce() ) {
			$this->handle_direct_action_error( 'Access Denied' );
		}

		$nxt_action = (isset($_REQUEST['nxt_action'])) ? sanitize_text_field( wp_unslash($_REQUEST['nxt_action'])) : '';

		$result = $this->$nxt_action( $_REQUEST );

		if ( is_wp_error( $result ) ) {
			/** @var \WP_Error $result */
			$this->handle_direct_action_error( $result->get_error_message() . '.' );
		}

		$callback = "successful_{$nxt_action}_redirect";

		if ( method_exists( $this, $callback ) ) {
			$this->$callback( $result );
		}

		die;
	}
	
	/**
	 * Get template export link
	 *
	 * @since 1.0.0
	 */
	private function get_export_link( $template_id ) {
		
		return add_query_arg(
			[
				'action' => 'nxt_builder_export_actions',
				'nxt_action' => 'export_template',
				'source' => 'nxt',
				'_nonce' => wp_create_nonce( 'nxt_ajax' ),
				'post_id' => $template_id,
			],
			admin_url( 'admin-ajax.php' )
		);
	}
	
	public function verify_request_nonce() {
		return ! empty( $_REQUEST['_nonce'] ) && wp_verify_nonce( sanitize_key(wp_unslash($_REQUEST['_nonce'])), self::NXT_NONCE_KEY );
	}
	
	/**
	 * Builder Export template call data.
	 */
	public function export_template( array $args ) {
	
		$validate_args = $this->check_args_specified( [ 'source', 'post_id' ], $args );

		if ( is_wp_error( $validate_args ) ) {
			return $validate_args;
		}

		return $this->export_template_data( $args['post_id'] );
	}
	
	
	
	/**
	 * Export Nexter builder data.
	 */
	public function export_template_data( $post_id ) {
		$prepare_file_data = $this->prepare_template_export( $post_id );

		if ( is_wp_error( $prepare_file_data ) ) {
			return $prepare_file_data;
		}

		die;
	}
	
	/**
	 * Data Prepare template to export_wp
	 */
	private function prepare_template_export( $template_id ) {
		
		require_once ABSPATH . 'wp-admin/includes/export.php';
		add_filter( 'query', array( $this, 'filter_query' ) );
		export_wp( array('content' => NEXTER_EXT_CPT) );
	}
	
	/**
	 * Filter query for exporting a single post
	 *
	 * @since 1.0.0
	 */
	public function filter_query( $query ) {
		global $wpdb;
		if ( stripos( $query, NEXTER_EXT_CPT ) ) {
			remove_filter( 'query', array( $this, 'filter_query' ) );
			$post_id = ( isset($_GET['post_id']) ) ? (int) sanitize_key(wp_unslash($_GET['post_id'])) : '';
			$post_ids = array( $post_id );
			// Now get fields			
			if ( ! empty( $post_ids ) ) {
				$post_ids = implode( ',', array_map( 'intval', $post_ids ) );
				$query = preg_replace( "#post_type.*=.*('|\").*?('|\")#i", "ID in ({$post_ids}) ", $query );
			}
		}
		return $query;
	}
	
	/**
	 * Ensure specified arguments exist.
	 */
	private function check_args_specified( array $required_args, array $specified_args ) {
		$not_specified_args = array_diff( $required_args, array_keys( array_filter( $specified_args ) ) );

		if ( $not_specified_args ) {
			return new \WP_Error( 'arguments_not_specified', sprintf( 'The required argument(s) "%s" not specified.', implode( ', ', $not_specified_args ) ) );
		}

		return true;
	}
	
	/**
	 * Admin Import Template Form.
	 */
	public function nxt_import_template_form() {
		if ( ! self::check_current_screen_templates() ) {
			return;
		}
		?>
		<div id="nxt-hidden-area">
			<a id="nxt-import-template-button" class="page-title-action nxt-btn-action"><?php echo esc_html__( 'Import Templates', 'nexter-extension' ); ?></a>
			<div id="nxt-import-template-form" style="display:none;">
				<div id="nxt-import-title"><?php echo esc_html__( 'Choose an Nexter Builder template XML file of Builder templates, and add them to the list of templates available in your builder.', 'nexter-extension' ); ?></div>
				<form id="nxt-import-temp-form" method="post" action="<?php echo esc_url(admin_url( 'admin-ajax.php' )); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="nxt_builder_import_actions">
					<input type="hidden" name="nxt_action" value="import_template">
					<input type="hidden" name="_nonce" value="<?php echo esc_attr(wp_create_nonce( 'nxt_ajax' )); ?>">
					<fieldset id="nxt-import-template-inputs">
						<input type="file" name="file" required>
						<input type="submit" class="button" value="<?php echo esc_attr__( 'Import Now', 'nexter-extension' ); ?>">
					</fieldset>
				</form>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Import template actions
	 */
	public function import_template( array $args ) {
		$file_name = (isset($_FILES['file']['name'])) ? $_FILES['file']['name'] : '';	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$tmp_name = (isset($_FILES['file']['tmp_name'])) ? $_FILES['file']['tmp_name'] : '';	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		return $this->import_template_data( $file_name,$tmp_name );
	}
	
	/**
	 * Import Builder Data .xml
	 */
	public function  import_template_data( $name, $path ) {
		if ( empty( $path ) ) {
			return new \WP_Error( 'file_error', 'Please upload a file to import' );
		}
		
		$file_extension = pathinfo( $name, PATHINFO_EXTENSION );
		if($file_extension == 'xml'){	
		
			ob_start();
			$wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
			
			if ( file_exists( $wp_importer ) ){			
				require $wp_importer;
			}
			
			require_once NEXTER_EXT_DIR .'include/nexter-template/importer/class.wordpress-importer.php';
			
			$nxt_import = new WP_Import();
			set_time_limit(0);
			
			$nxt_import->fetch_attachments = true;
			$nxt_import->allow_fetch_attachments();
			$data_value = $nxt_import->import( $path );
			
			if(is_wp_error($data_value)){
				return $data_value;
			}
			
			ob_get_clean();
		
		}else{
			return new \WP_Error( 'file_error', 'Please upload a file .xml Extension' );
		}
	}
	
	/**
	 * Successful template import redirect url.
	 */
	private function successful_import_template_redirect() {
		wp_safe_redirect( admin_url( 'edit.php?post_type='.NEXTER_EXT_CPT ) );
	}
}

Nexter_Builder_Import_Export::get_instance();