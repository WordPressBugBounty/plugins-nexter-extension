<?php
/**
 * Nexter Rollback Manager Menu
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Sanitize and validate input parameters
$type        = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
$theme_file  = isset( $_GET['theme_file'] ) ? sanitize_text_field( $_GET['theme_file'] ) : '';
$plugin_file = isset( $_GET['plugin_file'] ) ? sanitize_text_field( $_GET['plugin_file'] ) : '';
$current_version = isset( $_GET['current_version'] ) ? sanitize_text_field( $_GET['current_version'] ) : '';
$rollback_name   = isset( $_GET['rollback_name'] ) ? sanitize_text_field( $_GET['rollback_name'] ) : '';

// Validate required parameters
if ( empty( $type ) || ( 'theme' === $type && empty( $theme_file ) ) || ( 'plugin' === $type && empty( $plugin_file ) ) ) {
    wp_die( esc_html__( 'Nexter Extension Rollback is missing necessary parameters to continue. Please contact support.', 'nexter-extension' ) );
}

$theme_rollback  = ( 'theme' === $type );
$plugin_rollback = ( 'plugin' === $type );
$plugins         = get_plugins();

?>

<div class="nxt_rb_header">
    <h4 class="nxt_rb_header_title"><?php esc_html_e( 'Rollback Manager', 'nexter-extension' ); ?></h4>
</div>

<div class="nxt-rollback-wrap">
   <div class="nxt-rb-subhead">
      <?php
         if ( ! empty( $type ) ) {
            printf(
               // Translators: %s is the capitalized rollback type (e.g., Plugin or Theme).
               esc_html__( '%s Rollback', 'nexter-extension' ),
               esc_html( ucfirst( $type ) )
            );
         }
      ?>
   </div>

   <?php
      $rollback_manager = new Nexter_Ext_RollBack_Manager();

      if ( $plugin_rollback && array_key_exists( $plugin_file, $plugins ) ) {
         $versions = $rollback_manager->versions_select( 'plugin' );
      } elseif ( $theme_rollback && ! empty( $theme_file ) ) {
         $svn_tags = $rollback_manager->rb_svn_tags( 'theme', $theme_file );
         $rollback_manager->set_svn_versions_data( $svn_tags );
         $versions = $rollback_manager->versions_select( 'theme' );
      } else {
         wp_die( esc_html__( 'Required rollback parameters are missing or invalid. Please contact support.', 'nexter-extension' ) );
      }
   ?>

   <form name="nxt_rb_form" class="nxt-rb-form" action="<?php echo esc_url( admin_url( '/admin.php' ) ); ?>">
      <?php if ( ! empty( $versions ) ) : ?>
            <div class="nxt-versions-wrap">
               <?php
               do_action( 'nxt_ext_pre_versions' );
               echo apply_filters( 'nxt_ext_versions_output', $versions );
               do_action( 'nxt_ext_post_version' );
               ?>
            </div>
            <div class="nxt-rb-desc">
               <label class="label label-default">
                  <?php
                  echo apply_filters(
                     'nxt_ext_rollback_description',
                     sprintf(
                        // Translators: %1$s is the current version, %2$s is the rollback item name.
                        esc_html__(
                           'You currently have version %1$s installed of %2$s. We strongly recommend you create a complete backup before proceeding.',
                           'nexter-extension'
                        ),
                        '<span class="current-version">' . esc_html( $current_version ) . '</span>',
                        '<span class="rollback-name">' . esc_html( $rollback_name ) . '</span>'
                     )
                  );
                  ?>
               </label>
            </div>
      <?php endif; ?>

      <?php do_action( 'nxt_ext_hidden_fields' ); ?>

      <input type="hidden" name="page" value="nxt-rollback">
      <?php if ( $plugin_rollback ) : ?>
            <input type="hidden" name="plugin_file" value="<?php echo esc_attr( $plugin_file ); ?>">
            <input type="hidden" name="plugin_slug" value="<?php echo esc_attr( sanitize_title( $rollback_name ) ); ?>">
      <?php else : ?>
            <input type="hidden" name="theme_file" value="<?php echo esc_attr( $theme_file ); ?>">
      <?php endif; ?>
      <input type="hidden" name="rollback_name" value="<?php echo esc_attr( $rollback_name ); ?>">
      <input type="hidden" name="installed_version" value="<?php echo esc_attr( $current_version ); ?>">
      <?php wp_nonce_field( 'nxt_ext_rollback_nonce' ); ?>

      <div class="nxt-ext-rb-submit-wrap">
            <button type="button" class="nxt-ext-rb-popup"><?php esc_html_e('Rollback', 'nexter-extension'); ?></button>
            <a href="#" class="nxt-ext-how-works" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read How it Works', 'nexter-extension' ); ?></a>
      </div>

      <!-- popup start -->
         <div class="nxt-rb-confirm-popup">
            <!-- Rollback Popup layout -->
            <div class="nxt-br-modal-confirm">
               <svg class="nxtext_close_icon" draggable="false" xmlns="http://www.w3.org/2000/svg" width="36" height="17" fill="none" viewBox="0 0 36 17">
                     <path fill="#414B5A" d="M22.2 4.30665c-.26-.26-.68-.26-.94 0L18 7.55998l-3.26-3.26c-.26-.26-.68-.26-.94 0-.26.26-.26.68 0 .94l3.26 3.26L13.8 11.76c-.26.26-.26.68 0 .94.26.26.68.26.94 0L18 9.43998 21.26 12.7c.26.26.68.26.94 0 .26-.26.26-.68 0-.94l-3.26-3.26002 3.26-3.26c.2533-.25333.2533-.68 0-.93333Z"></path>
               </svg>
               <div class="nxt_rb_heading">
                     <h4 class="nxt_rb_title">
                        <?php esc_html_e( 'Are you sure you want to perform the Rollback?', 'nexter-extension' ); ?>
                     </h4>
               </div>
               <div class="nxt-br-modal-inner">
                     <div class="rollback-details">
                        <table class="table">
                           <tbody>
                                 <tr>
                                    <td>
                                       <label>
                                             <?php
                                             if ( $plugin_rollback ) {
                                                esc_html_e( 'Plugin Name :', 'nexter-extension' );
                                             } else {
                                                esc_html_e( 'Theme Name :', 'nexter-extension' );
                                             }
                                             ?>
                                       </label>
                                    </td>
                                    <td><span class="nxt-br-plugin-name"><?php echo esc_html( $rollback_name ); ?></span></td>
                                 </tr>
                                 <tr>
                                    <td>
                                       <label><?php esc_html_e( 'Current Installed Version :', 'nexter-extension' ); ?></label>
                                    </td>
                                    <td><span class="nxt-br-current-version"><?php echo esc_html( $current_version ); ?></span></td>
                                 </tr>
                                 <tr>
                                    <td>
                                       <label><?php esc_html_e( 'New Version :', 'nexter-extension' ); ?></label>
                                    </td>
                                    <td><span class="nxt-br-new-version"></span></td>
                                 </tr>
                           </tbody>
                        </table>
                     </div>
                     <div class="nxt-br-error"><?php esc_html_e( 'We strongly recommend you create a complete backup before proceeding.', 'nexter-extension' ); ?></div>
                     <div class="nxt-rb-pop-btn-wrap">
                        <?php do_action( 'nxt_ext_pre_rollback_buttons' ); ?>
                        <a href="#" class="nxt-br-close"><?php esc_html_e( 'Cancel', 'nexter-extension' ); ?></a>
                        <input type="submit" value="<?php esc_attr_e( 'Continue', 'nexter-extension' ); ?>" class="nxt-br-submit" />
                        <?php do_action( 'nxt_ext_post_rollback_buttons' ); ?>
                     </div>
               </div>
            </div>
            <!-- Rollback popup layout -->
         </div>
      <!-- popup End here -->
   </form>
</div>
