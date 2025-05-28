<?php 
/*
 * Local User Avatar Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Local_User_Avatar {
    
	/**
     * Constructor
     */
    public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// For when current user edits their own profile
		add_action( 'show_user_profile', [ $this, 'render_profile_avatar_fields' ] );
		// For when admins edit user profiles
		add_action( 'edit_user_profile', [ $this, 'render_profile_avatar_fields' ] );
		
		// Update Profile Picture fields / user meta
		// For when current user edits their own profile
		add_action( 'personal_options_update', [ $this, 'update_user_avatar_meta' ] );
		// For when admins edit user profiles
		add_action( 'edit_user_profile_update', [ $this, 'update_user_avatar_meta' ] );
		
		// Delete Profile Picture user meta when attachment is deleted
		add_action( 'delete_attachment', [ $this, 'delete_avatar_meta_by_attachment_id' ] );
		
		// Override avatar with the local one uploaded
		add_filter( 'get_avatar', [ $this, 'filter_user_avatar_html' ], 5, 5 );
		add_filter( 'get_avatar_url', [ $this, 'get_local_avatar_url' ], 10, 3 );
    }

	/*
	 * Get the default WordPress avatar URL based on user email.
	 * */
	public function get_default_avatar_url_by_email( $user_email = '', $size = 94 ) {
		if ( empty( $user_email ) || ! is_email( $user_email ) ) {
			return null;
		}
	
		$sanitized_email = sanitize_email( $user_email );
		$hashed_email    = md5( strtolower( trim( $sanitized_email ) ) );
	
		$avatar_url = sprintf( 'https://secure.gravatar.com/avatar/%s', $hashed_email );
	
		$avatar_url = add_query_arg(
			[
				's' => absint( $size ),
				'd' => 'mm',
				'r' => 'g',
			],
			$avatar_url
		);
	
		return esc_url( $avatar_url );
	}


	/**
	 * Enqueue admin scripts and styles for user profile avatar management.
	 */
	public function enqueue_admin_assets() {
		global $pagenow, $current_user;

		$allowed_pages = [ 'users.php', 'profile.php', 'user-new.php', 'user-edit.php' ];

		if ( ! in_array( $pagenow, $allowed_pages, true ) ) {
			return;
		}

		// Enqueue WordPress Media Library
		wp_enqueue_media();

		wp_enqueue_script(
			'nxt-local-user-avatar',
			NEXTER_EXT_URL . 'assets/js/admin/local-user-avatar.js',
			[],
			NEXTER_EXT_VER,
			true // Load in footer for better performance
		);

		// Prepare localized script data
		$default_avatar_url = $this->get_default_avatar_url_by_email( $current_user->user_email, 94 );

		$localize_data = [
			'avatarUrl'    => $default_avatar_url,
			'avatarSrcset' => $this->get_default_avatar_url_by_email( $current_user->user_email, 192 ) . ' 2x',
			'title'       => __( 'Select Profile Picture', 'nexter-extension' ),
			'button'           => __( 'Use as Profile Picture', 'nexter-extension' ),
		];

		wp_localize_script( 'nxt-local-user-avatar', 'nxt_local_avatar', $localize_data );
	}

	/**
     * Render the custom profile avatar fields on user profile edit screen.
     */
    public function render_profile_avatar_fields( $user ) {
        $avatar_attachment_id = get_user_meta( $user->ID, 'nxt_user_avatar_attach_id', true );
		$has_custom_avatar    = ! empty( $avatar_attachment_id );
		?>
	<style>
		.nxt-attach-avatar {
			border-radius: 2px;
			cursor: pointer
		}

		.nxt-btn-container {
			margin-top: 8px
		}

		.attachment-display-settings,.media-frame-menu,.media-frame-menu-heading,.media-types-required-info,.user-profile-picture {
			display: none
		}

		img.avatar {
			object-fit: cover
		}

		#wpadminbar #wp-admin-bar-my-account.with-avatar>.ab-empty-item img,#wpadminbar #wp-admin-bar-my-account.with-avatar>a img {
			width: 16px;
			object-fit: cover
		}

		.media-frame-content,.media-frame-router,.media-frame-title,.media-frame-toolbar {
			left: 0
		}
		</style>
		<table class="form-table">
			<tbody>
				<tr id="nxt-local-user-avatar">
					<th scope="row">
						<label for="nxt-media-btn-add"><?php esc_html_e( 'Profile Picture', 'nexter-extension' ); ?></label>
					</th>
					<td>
						<?php
							echo get_avatar( $user->ID, 94, '', esc_attr( $user->display_name ), [ 'class' => 'nxt-attach-avatar' ] );
						?>
						<p class="description<?php echo $has_custom_avatar ? ' hidden' : ''; ?>" id="nxt-attach-description">
							<?php esc_html_e( "You are currently using the default profile picture.", 'nexter-extension' ); ?>
						</p>
						<div class="nxt-btn-container">
							<button type="button" class="button" id="nxt-media-btn-add">
								<?php esc_html_e( 'Change', 'nexter-extension' ); ?>
							</button>
							<button type="button" class="button<?php echo ! $has_custom_avatar ? ' hidden' : ''; ?>" id="nxt-media-remove-btn">
								<?php esc_html_e( 'Reset to Default', 'nexter-extension' ); ?>
							</button>
						</div>
					</td>
				</tr>
			</tbody>
		</table>

		<input type="hidden" name="nxt_user_avatar_attach_id" value="<?php echo esc_attr( $avatar_attachment_id ); ?>" />
		<?php
    }

	/**
	 * Update the user's avatar meta data.
	 */
	public function update_user_avatar_meta( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		delete_user_meta( $user_id, 'nxt_user_avatar_attach_id' );

		if ( isset( $_POST['nxt_user_avatar_attach_id'] ) && is_numeric( $_POST['nxt_user_avatar_attach_id'] ) ) {
			add_user_meta( $user_id, 'nxt_user_avatar_attach_id', (int) $_POST['nxt_user_avatar_attach_id'] );
		}

		return true;
	}

	/**
	 * Delete all avatar meta entries matching the attachment ID.
	 */
	public function delete_avatar_meta_by_attachment_id( $attachment_id ) {
		global $wpdb;

		$wpdb->delete(
			$wpdb->usermeta,
			[
				'meta_key'   => 'nxt_user_avatar_attach_id',
				'meta_value' => (int) $attachment_id,
			],
			[ '%s', '%d' ]
		);
	}

	/**
	 * Override the user's avatar HTML with the locally uploaded image.
	 */
	public function filter_user_avatar_html( $avatar_html, $user_identifier, $size, $default, $alt ) {
		$user_id = $this->get_user_id_from_identifier( $user_identifier );

		if ( ! $user_id ) {
			return $avatar_html;
		}

		$avatar_attachment_id = get_user_meta( $user_id, 'nxt_user_avatar_attach_id', true );

		if ( ! is_numeric( $avatar_attachment_id ) ) {
			return $avatar_html;
		}

		$avatar_src = wp_get_attachment_image_src( $avatar_attachment_id, 'medium' );

		if ( is_array( $avatar_src ) && ! empty( $avatar_src[0] ) ) {
			$avatar_html = preg_replace( '/src=("|\').*?("|\')/', "src='{$avatar_src[0]}'", $avatar_html );
		}

		$avatar_srcset = wp_get_attachment_image_srcset( $avatar_attachment_id );

		if ( $avatar_srcset ) {
			$avatar_html = preg_replace( '/srcset=("|\').*?("|\')/', "srcset='{$avatar_srcset}'", $avatar_html );
		}

		return $avatar_html;
	}

	/**
     * Get the local uploaded avatar URL for a user, if available.
     */
    public function get_local_avatar_url( $avatar_url, $user_identifier, $args ) {
		$user_id = $this->get_user_id_from_identifier( $user_identifier );
	
		if ( ! $user_id ) {
			return $avatar_url;
		}
	
		$attachment_id = get_user_meta( $user_id, 'nxt_user_avatar_attach_id', true );
	
		if ( ! is_numeric( $attachment_id ) ) {
			return $avatar_url;
		}
	
		$attachment_src = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
	
		return ( is_array( $attachment_src ) && ! empty( $attachment_src[0] ) ) ? esc_url( $attachment_src[0] ) : $avatar_url;
	}

    /**
     * Get user ID from $id_or_email
     */
    public function get_user_id_from_identifier( $identifier ) {
		if ( is_numeric( $identifier ) ) {
			return (int) $identifier;
		}
	
		if ( is_string( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
			return ( $user && isset( $user->ID ) ) ? (int) $user->ID : false;
		}
	
		if ( is_object( $identifier ) ) {
			if ( isset( $identifier->ID ) && is_numeric( $identifier->ID ) ) {
				return (int) $identifier->ID;
			}
	
			if ( isset( $identifier->comment_author_email ) ) {
				$user = get_user_by( 'email', $identifier->comment_author_email );
				return ( $user && isset( $user->ID ) ) ? (int) $user->ID : false;
			}
		}
	
		return false;
	}
}

 new Nexter_Ext_Local_User_Avatar();