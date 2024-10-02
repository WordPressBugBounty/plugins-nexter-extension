<?php
/*
 * Nexter Import
 *
 * @package Nexter Extensions
 * @since 1.0.3
 */

class Nexter_Import_Settings {
	
	/*
	 * Nexter Import Local
	 */
	public function __construct() {
		add_action( 'wp_ajax_nexter_import_data_step_4', [ $this, 'nexter_import_data_step_4' ] );
		add_action( 'wp_ajax_nexter_import_data_template', [ $this, 'nexter_import_data_template_ajax' ] );
	}
	
	public function nexter_import_data_template_ajax(){
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		
		$builder = ( isset( $_POST['builder'] ) ) ? sanitize_text_field( wp_unslash( $_POST['builder'] ) ) : '';
		$tag = ( isset( $_POST['tag'] ) ) ? sanitize_text_field( wp_unslash( $_POST['tag'] ) ) : '';
		$template = ( isset( $_POST['template'] ) ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : '';
		
		if( current_user_can( 'install_plugins' ) && !empty($template) && !empty($builder) && !empty($tag) && $tag=='Free'){
			$template_file = $builder.'-'.$template.'.xml';
			
			if( file_exists( NEXTER_EXT_DIR . 'include/nexter-template/template/'.$template_file ) ){
				$template_path = NEXTER_EXT_DIR . 'include/nexter-template/template/'.$template_file;
				
				if(class_exists('Nexter_Builder_Import_Export')){
				
					$import_template = new Nexter_Builder_Import_Export();
					if ( method_exists( $import_template, 'import_template_data' ) ) {
					
						$imported = $import_template->import_template_data( $template_file, $template_path );
						
						if ( is_wp_error( $imported ) ) {
							wp_send_json_error( [
								'success'	=> false,
								'message'	=> $imported->get_error_message(),
							] );
						}
						wp_send_json_success( [
								'success'	=> true,
								'content'	=> __( 'Imported', 'nexter-ext' ),
							] );
					}
					
				}
				
			}
		}else{
			wp_send_json_error( [
				'success'	=> false,
				'message'	=> __( 'oops.. Something Wrong..', 'nexter-ext' ),
			] );
		}
	}

	public function nexter_import_data_step_4(){
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		
		$output = '<div class="nxt-import-steps import-step-4 active" data-step="step-4">
						<div class="nxt-import-sucess-icon"><img src="'.esc_url(NXT_THEME_URI.'assets/images/panel-icon/import-success-icon.svg').'" /></div>
						<div class="nxt-import-success-message">
							<div class="nxt-import-heading">'.esc_html__('Successfully Completed','nexter-ext').'</div>
							<div class="nxt-any-trouble">'.esc_html__('Congratulations! All set. You\'re site is now ready. Any Questions? ','nexter-ext').'<a href="'.esc_url('https://docs.posimyth.com/nexterwp/').'" class="nxt-read-article">'.esc_html__('Read Documentations.','nexter-ext').'</a></div>
						</div>
				  </div>';
		wp_send_json_success( [ 'success' => true, 'content' => $output ] );
	}
}

$Nexter_Import_Settings = new Nexter_Import_Settings();