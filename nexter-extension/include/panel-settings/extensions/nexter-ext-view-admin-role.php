<?php 
/*
 * View Admin Role Switch
 * @since 4.3.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_View_Admin_Role_Switch {
    
    /**
     * Constructor
     */
    public function __construct() {
		add_action( 'admin_bar_menu', [$this, 'view_admin_as_admin_bar_menu'], 8 );
		add_action( 'init', [$this, 'role_switcher_to_view_admin_as'] );
		add_action( 'profile_update', [$this, 'maybe_prevent_switchback_to_administrator'], 20 );
		add_action( 'admin_footer', [$this, 'add_floating_reset_button'] );
    }

	public function view_admin_as_admin_bar_menu( $admin_bar ) {
		$opts      = get_option( 'nexter_extra_ext_options', array() );
		$whitelist = isset( $opts['view-admin-role']['view_as_users'] ) ? $opts['view-admin-role']['view_as_users'] : array();
		
		$user      = wp_get_current_user();
		$roles     = array_values( $user->roles ); // Current user roles
		$username  = $user->user_login;

		// Get/set current viewed role
		$view_role = get_user_meta( $user->ID, 'nxtext_viewing_admin_switch', true );
		if ( empty( $view_role ) ) {
			$view_role = 'administrator';
			update_user_meta( $user->ID, 'nxtext_viewing_admin_switch', $view_role );
		}

		// Get translated role name
		$wp_roles = wp_roles()->roles;
		$role_name = isset( $wp_roles[ $view_role ] ) ? $wp_roles[ $view_role ]['name'] : $view_role;
		$label     = ucfirst( $role_name );

		// Add main admin bar menu item
		$can_switch = in_array( $username, $whitelist );
		$is_admin   = in_array( 'administrator', $roles );

		if ( $view_role === 'administrator' && $is_admin ) {
			$admin_bar->add_menu( array(
				'id'     => 'nxt-ext-role-switch',
				'parent' => 'top-secondary',
				'title'  => 'View as <span style="font-size:0.8125em;">&#9660;</span>',
				'href'   => '#',
				'meta'   => array(
					'title' => __('View admin as one of the roles below.','nexter-extension')
				),
			) );
		} elseif ( $can_switch ) {
			$admin_bar->add_menu( array(
				'id'     => 'nxt-ext-role-switch',
				'parent' => 'top-secondary',
				'title'  => 'Viewing as ' . $label . ' <span style="font-size:0.8125em;">&#9660;</span>',
				'href'   => '#',
			) );
		}

		// Add submenu items
		$switch_roles = $this->get_roles_to_switch_to();
		$i = 1;

		foreach ( $switch_roles as $role_key => $data ) {
			$menu_id = ( $view_role === 'administrator' ) ? "role{$i}_{$role_key}" : "role_{$role_key}";
			$title   = ( $view_role === 'administrator' )
				? $data['role_name']
				: __('Switch back to ','nexter-extension') . $data['role_name'];

			if (
				( $view_role === 'administrator' && $is_admin ) ||
				( $view_role !== 'administrator' && $can_switch )
			) {
				$admin_bar->add_menu( array(
					'id'     => $menu_id,
					'parent' => 'nxt-ext-role-switch',
					'title'  => $title,
					'href'   => esc_url( $data['nonce_url'] ),
				) );
			}

			$i++;
		}
	}

	/**
	 * Get available roles to switch to.
	 *
	 * @since 1.8.0
	 */
	private function get_roles_to_switch_to() {
		$user     = wp_get_current_user();
		$user_ids = $user->roles;
		$roles    = wp_roles()->roles;

		$view_as  = get_user_meta( $user->ID, 'nxtext_viewing_admin_switch', true );
		$switch   = [];

		if ( $view_as === 'administrator' ) {
			foreach ( $roles as $slug => $info ) {
				if ( ! in_array( $slug, $user_ids ) ) {
					$switch[ $slug ] = [
						'role_name' => $info['name'],
						'nonce_url' => wp_nonce_url(
							add_query_arg([
								'action' => 'switch_role_to',
								'role'   => $slug,
							]),
							'nxtext_view_admin_' . $slug,
							'nonce'
						)
					];
				}
			}
		} else {
			$switch['administrator'] = [
				'role_name' => __('Administrator','nexter-extension'),
				'nonce_url' => wp_nonce_url(
					add_query_arg([
						'action' => 'switch_back_to_administrator',
						'role'   => 'administrator',
					]),
					'nxtext_view_admin_administrator',
					'nonce'
				)
			];
		}

		return $switch;
	}

	/**
	 * Switch user role for admin/site view.
	 *
	 * @since 1.8.0
	 */
	public function role_switcher_to_view_admin_as() {

		$user     = wp_get_current_user();
		$roles    = $user->roles;
		$uname    = $user->user_login;
		$opts     = get_option( 'nexter_extra_ext_options', [] );
		$allowed  = $opts['view-admin-role']['view_as_users'] ?? [];

		// Security: Handle role switch with proper validation
		if ( isset($_REQUEST['action'], $_REQUEST['role'], $_REQUEST['nonce']) ) {
			$action = sanitize_text_field( wp_unslash($_REQUEST['action']) );
			$role   = sanitize_key( wp_unslash($_REQUEST['role']) );
			$nonce  = sanitize_text_field( wp_unslash($_REQUEST['nonce']) );
			
			// Security: Verify user has permission to switch roles
			/* if ( ! current_user_can( 'manage_options' ) ) {
				return;
			} */

			// --- Switch to role ---
			if ( $action === 'switch_role_to' ) {
				$valid_roles = array_keys( wp_roles()->roles );

				if ( ! wp_verify_nonce( $nonce, 'nxtext_view_admin_' . $role ) || ! in_array( $role, $valid_roles ) ) {
					return;
				}

				$original = get_user_meta( $user->ID, 'nxtext_view_admin_original_role', true );

				if ( empty( $original ) ) {
					update_user_meta( $user->ID, 'nxtext_view_admin_original_role', $roles );
				}

				if ( ! in_array( $uname, $allowed ) ) {
					$allowed[] = $uname;
				}

				$opts['view-admin-role']['view_as_users'] = $allowed;
				update_option( 'nexter_extra_ext_options', $opts, true );

				foreach ( $roles as $r ) {
					$user->remove_role( $r );
				}

				$user->add_role( $role );
				update_user_meta( $user->ID, 'nxtext_viewing_admin_switch', $role );

				wp_safe_redirect( admin_url() );
				exit;
			}

			// --- Switch back to Administrator ---
			if ( $action === 'switch_back_to_administrator' ) {
				if ( ! wp_verify_nonce( $nonce, 'nxtext_view_admin_administrator' ) || $role !== 'administrator' ) {
					return;
				}

				foreach ( $roles as $r ) {
					$user->remove_role( $r );
				}

				$original = get_user_meta( $user->ID, 'nxtext_view_admin_original_role', true );
				foreach ( (array) $original as $r ) {
					$user->add_role( $r );
				}

				$opts['view-admin-role']['view_as_users'] = array_values(array_diff($allowed, [ $uname ]));
				update_option( 'nexter_extra_ext_options', $opts, true );

				update_user_meta( $user->ID, 'nxtext_viewing_admin_switch', 'administrator' );
			}

		} elseif ( isset( $_REQUEST['reset-view'] ) ) {

			$reset_user = sanitize_user( wp_unslash( $_REQUEST['reset-view'] ) );
			if ( in_array( $reset_user, $allowed, true ) ) {
				$reset_obj = get_user_by( 'login', $reset_user );
				if ( $reset_obj ) {
					foreach ( $reset_obj->roles as $r ) {
						$reset_obj->remove_role( $r );
					}

					$original = get_user_meta( $reset_obj->ID, 'nxtext_view_admin_original_role', true );
					foreach ( (array) $original as $r ) {
						$reset_obj->add_role( $r );
					}

					update_user_meta( $reset_obj->ID, 'nxtext_viewing_admin_switch', 'administrator' );

					$opts['view-admin-role']['view_as_users'] = array_values(array_diff($allowed, [ $reset_user ]));
					update_option( 'nexter_extra_ext_options', $opts, true );

					// Redirect to Dashboard (uses custom slug if set)
					?>
					<script>window.location.href='<?php echo esc_url( admin_url() ); ?>';</script>
					<?php
				}
			}
		}
	}

	/**
	 * Prevent a user from switching back to the Administrator role
	 * after their role is changed manually in the profile edit screen.
	 *
	 * This prevents a privilege escalation vulnerability (disclosed by Pathstack).
	 *
	 * @since 7.6.3
	 */
	public function maybe_prevent_switchback_to_administrator( $user_id ) {

		$view_role = get_user_meta( $user_id, 'nxtext_viewing_admin_switch', true );

		// Only proceed if user is NOT currently viewing as administrator
		if ( $view_role !== 'administrator' ) {

			$user     = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				return; // invalid user
			}

			$uname    = $user->user_login;
			$opts     = get_option( 'nexter_extra_ext_options', [] );
			$allowed  = $opts['view-admin-role']['view_as_users'] ?? [];

			// Remove user from the list of switchable usernames
			if ( in_array( $uname, $allowed, true ) ) {
				$opts['view-admin-role']['view_as_users'] = array_values( array_diff( $allowed, [ $uname ] ) );
				update_option( 'nexter_extra_ext_options', $opts, true );
			}

			// Remove View As metadata
			delete_user_meta( $user_id, 'nxtext_viewing_admin_switch' );
			delete_user_meta( $user_id, 'nxtext_view_admin_original_role' );
		}
	}

	/**
	 * Display a floating button for non-admin users to switch back to Administrator.
	 *
	 * @since 6.1.3
	 */
	public function add_floating_reset_button() {
		$opts     = get_option( 'nexter_extra_ext_options', [] );
		$allowed  = $opts['view-admin-role']['view_as_users'] ?? [];
		$user     = wp_get_current_user();
		$uname    = $user->user_login;

		// Show only if user is impersonating and NOT an admin
		if ( ! current_user_can( 'manage_options' ) && in_array( $uname, $allowed, true ) ) {
			$reset_url = add_query_arg( 'reset-view', rawurlencode( $uname ), home_url() );
			?>
			<div id="nxt-role-view-reset" style="position:fixed;bottom:20px;right:20px;z-index:9999;">
				<a href="<?php echo esc_url( $reset_url ); ?>" class="button button-primary">
					<?php echo esc_html( __('Switch back to Administrator', 'nexter-extension') ); ?>
				</a>
			</div>
			<?php
		}
	}


}

 new Nexter_Ext_View_Admin_Role_Switch();