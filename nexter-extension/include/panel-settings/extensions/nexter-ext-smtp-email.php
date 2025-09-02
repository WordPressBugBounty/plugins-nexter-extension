<?php
/*
 * SMTP Email Extension
 * @since 4.2.0
 */
defined('ABSPATH') || exit;

class Nexter_Ext_SMTP_Email {

    public static $smtp_opt = [];

    public function __construct() {
        $this->nxt_get_data_settings();
        if(!empty(self::$smtp_opt) && self::$smtp_opt['type'] =='gmail'){
            require_once __DIR__ . '/custom-fields/nexter-ext-smtp-gmail-auth.php';
        }else if(!empty(self::$smtp_opt) && self::$smtp_opt['type'] =='custom'){
            require_once __DIR__ . '/custom-fields/nexter-ext-smtp-custom-auth.php';
        }

        add_action('wp_ajax_nxt_smtp_custom_auth', [$this, 'nxt_smtp_custom_auth_data']);

        add_action('wp_ajax_nxt_smtp_gmail_auth', [$this, 'nxt_smtp_gmail_auth_data']);
        add_action('wp_ajax_nxt_smtp_gmail_auth_status', [$this, 'nxt_smtp_gmail_auth_status']);
        add_action('wp_ajax_handle_oauth_callback', [$this, 'handle_oauth_callback']);
        add_action('wp_ajax_nxt_smtp_send_test_email', [$this, 'nxt_smtp_send_test_email']);

        if (isset($_GET['code'], $_GET['state'])) {
            add_menu_page(
                esc_html__('Gmail SMTP Settings', 'nexter-extension'),
                esc_html__('Gmail SMTP', 'nexter-extension'),
                'manage_options',
                'nexter-smtp-settings',
                [$this, 'nexter_smtp_settings_page'],
                'dashicons-email',
                99
            );
        }

    }

    public function nexter_smtp_settings_page() {
        if (isset($_GET['code'], $_GET['state'])) {
            echo '<style>body{display:none}</style>';
            ?>
            <script>
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('code') && urlParams.has('state') && window.opener) {
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'handle_oauth_callback',
                        code: urlParams.get('code'),
                        state: urlParams.get('state'),
                        oauth_nonce: '<?php echo esc_js(wp_create_nonce("gmail_oauth_response")); ?>'
                    })
                })
                .then(res => res.json())
                .then(response => {
                    const message = response.success
                        ? { type: 'gmail_auth_success' }
                        : { type: 'gmail_auth_error', message: response.data?.oauth_error };
                    window.opener.postMessage(message, '*');
                    window.close();
                })
                .catch(() => {
                    window.opener.postMessage({ type: 'gmail_auth_error', message: '<?php echo esc_js(__('Failed to process authorization', 'nexter-extension')); ?>' }, '*');
                    window.close();
                });
            }
            </script>
            <?php
        }
    }

    private function nxt_get_data_settings() {
        if (!empty(self::$smtp_opt)) {
            return self::$smtp_opt;
        }

        $option = get_option('nexter_extra_ext_options');
        if (!empty($option['smtp-email']['switch']) && !empty($option['smtp-email']['values'])) {
            self::$smtp_opt = (array) $option['smtp-email']['values'];
        }
    }

    public function get_oauth_url() {
        $client_id = self::$smtp_opt['gclient_id'] ?? '';
        $redirect_uri = admin_url('admin.php?page=nexter-smtp-settings');
        $state = wp_create_nonce('gmail_oauth');

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://mail.google.com/ email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function nxt_smtp_gmail_auth_data() {
        check_ajax_referer('nexter_admin_nonce', 'smtp_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized access', 'nexter-extension'));
        }

        $smtp_type    = isset($_POST['smtp_type']) ? sanitize_text_field(wp_unslash($_POST['smtp_type'])) : '';
        $smtp_gclient = isset($_POST['smtp_gclient']) ? sanitize_text_field(wp_unslash($_POST['smtp_gclient'])) : '';
        $smtp_gsecret = isset($_POST['smtp_gsecret']) ? sanitize_text_field(wp_unslash($_POST['smtp_gsecret'])) : '';
        $smtp_from_name = isset($_POST['smtp_from_name']) ? sanitize_text_field(wp_unslash($_POST['smtp_from_name'])) : get_bloginfo('name');
        $smtp_from_email = isset($_POST['smtp_from_email']) ? sanitize_email(wp_unslash($_POST['smtp_from_email'])) : '';

        $option = get_option('nexter_extra_ext_options', []);
        $option['smtp-email']['values'] = array_merge(
            $option['smtp-email']['values'] ?? [],
            [
                'type'        => $smtp_type,
                'gclient_id'  => $smtp_gclient,
                'gsecret_key' => $smtp_gsecret,
                'name' => $smtp_from_name,
                'access_token' => '',
                'email' => $smtp_from_email
            ]
        );

        update_option('nexter_extra_ext_options', $option);
        self::$smtp_opt = $option['smtp-email']['values'];

        $site_url = parse_url(get_site_url(), PHP_URL_HOST);
        if (in_array($site_url, ['localhost', '127.0.0.1']) || str_ends_with($site_url, '.local')) {
            wp_send_json_error(__('OAuth redirect cannot run on localhost. Please use a live or staging domain.', 'nexter-extension'));
        }

        if (version_compare(PHP_VERSION, '8.0.2', '<')) {
            wp_send_json_error(sprintf(
                /* translators: 1: Required PHP version, 2: Current version */
                __('The Nexter Extension SMTP integration requires PHP version %1$s or higher. You are using %2$s.', 'nexter-extension'),
                '8.0.2',
                PHP_VERSION
            ));
        }

        wp_send_json_success(['oauth_url' => $this->get_oauth_url()]);
    }

    public function handle_oauth_callback() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['oauth_error' => __('Unauthorized access', 'nexter-extension')]);
        }

        $state = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
        $nonce = isset( $_POST['oauth_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['oauth_nonce'] ) ) : '';
        $code  = sanitize_text_field($_POST['code'] ?? '');


        if (!wp_verify_nonce($state, 'gmail_oauth')) {
            wp_send_json_error(['oauth_error' => __('Invalid OAuth state', 'nexter-extension')]);
        }

        if (!wp_verify_nonce($nonce, 'gmail_oauth_response')) {
            wp_send_json_error(['oauth_error' => __('Invalid OAuth nonce', 'nexter-extension')]);
        }

        if (empty($code)) {
            wp_send_json_error(['oauth_error' => __('Missing authorization code', 'nexter-extension')]);
        }

        $options      = get_option('nexter_extra_ext_options', []);
        $smtp_values  = $options['smtp-email']['values'] ?? [];
        $client_id    = $smtp_values['gclient_id'] ?? '';
        $client_secret = $smtp_values['gsecret_key'] ?? '';

        if (empty($client_id) || empty($client_secret)) {
            wp_send_json_error(['oauth_error' => __('Missing Client ID or Secret', 'nexter-extension')]);
        }

        $redirect_uri = admin_url('admin.php?page=nexter-smtp-settings');
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['oauth_error' => __('Token request failed: ', 'nexter-extension') . $response->get_error_message()]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            wp_send_json_error(['oauth_error' => __('Access token not found in response', 'nexter-extension')]);
        }

        $options['smtp-email']['values']['access_token'] = $body['access_token'];
        if (!empty($body['refresh_token'])) {
            $options['smtp-email']['values']['refresh_token'] = $body['refresh_token'];
        }

        // Optional: Get the user's email
        $userinfo = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', [
            'headers' => ['Authorization' => 'Bearer ' . $body['access_token']],
        ]);

        if (!is_wp_error($userinfo)) {
            $user_data = json_decode(wp_remote_retrieve_body($userinfo), true);
            if ( !empty($user_data['email']) && (!isset($smtp_values['email']) || empty($smtp_values['email'])) ) {
                $options['smtp-email']['values']['email'] = sanitize_email($user_data['email']);
            }
        }

        update_option('nexter_extra_ext_options', $options);
        wp_send_json_success(['oauth_success' => __('Successfully authorized with Gmail!', 'nexter-extension')]);
    }

    public function nxt_smtp_gmail_auth_status() {
        check_ajax_referer('gmail_auth_check', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized access', 'nexter-extension'));
        }
        if (version_compare(PHP_VERSION, '8.0.2', '<')) {
            wp_send_json_error(sprintf(
                /* translators: 1: Required PHP version, 2: Current version */
                __('The Nexter Extension SMTP integration requires PHP version %1$s or higher. You are using %2$s.', 'nexter-extension'),
                '8.0.2',
                PHP_VERSION
            ));
        }

        $smtp_data = get_option('nexter_extra_ext_options');
        $access_token = $smtp_data['smtp-email']['values']['access_token'] ?? '';
        $email = $smtp_data['smtp-email']['values']['email'] ?? '';

        if (!empty($access_token)) {
            wp_send_json_success(['access_token' => $access_token, 'email_id' => $email, 'message' => __('Successfully authorized with Gmail!', 'nexter-extension') ]);
        } else {
            wp_send_json_error(__('Authorization failed: Unable to verify token.', 'nexter-extension'));
        }
    }

    public function nxt_smtp_send_test_email() {
        check_ajax_referer('nexter_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized access', 'nexter-extension'));
        }
        
        $to = isset($_POST['send_to']) && !empty($_POST['send_to']) ? sanitize_email( wp_unslash($_POST['send_to']) ) : '';
        if( empty( $to ) ){
            wp_send_json_error(__('Please Enter a valid Email ID to send the test mail.', 'nexter-extension'));
        }
        $subject = __('Gmail SMTP Test Email', 'nexter-extension');
        $body = '<b>This is a test email sent via using SMTP.</b>';
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $result = wp_mail($to, $subject, $body, $headers);
        if ($result === true) {
            wp_send_json_success(__('Test email sent successfully!', 'nexter-extension'));
        } else {
            wp_send_json_error(__('Failed to send test email: ', 'nexter-extension') . $result);
        }
    }

    public function nxt_smtp_custom_auth_data(){
        check_ajax_referer('nexter_admin_nonce', 'smtp_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized access', 'nexter-extension'));
        }

        $smtp_type    = isset($_POST['smtp_type']) ? sanitize_text_field(wp_unslash($_POST['smtp_type'])) : '';
        $smtp_custom_json = isset($_POST['smtp_custom']) ? wp_unslash($_POST['smtp_custom']) : '';
        $smtp_custom = json_decode($smtp_custom_json, true);

        $option = get_option('nexter_extra_ext_options', []);
        $option['smtp-email']['values'] = array_merge(
            $option['smtp-email']['values'] ?? [],
            [
                'type'        => $smtp_type,
            ],
        );
        $option['smtp-email']['values']['custom'] = array_merge(
            $option['smtp-email']['values']['custom'] ?? [],
            [
                'host'      => sanitize_text_field($smtp_custom['host']),
                'port'      => !empty($smtp_custom['port']) ? intval($smtp_custom['port']) : 587,
                'encryption'=> sanitize_text_field($smtp_custom['encryption']),
                'autoTLS'   => (isset($smtp_custom['autoTLS']) && $smtp_custom['autoTLS'] == true) ? true : false,
                'auth'      => (isset($smtp_custom['auth']) && $smtp_custom['auth'] == true) ? true : false,
                'username'  => sanitize_text_field($smtp_custom['username']),
                'password'  => sanitize_text_field($smtp_custom['password']),
                'connect'   => false,
                'from_email' => sanitize_email($smtp_custom['from_email']),
                'from_name' => sanitize_text_field($smtp_custom['from_name']),
            ],
        );

        update_option('nexter_extra_ext_options', $option);
        self::$smtp_opt = $option['smtp-email']['values'];

        // Load PHPMailer if not already loaded
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }

        if($smtp_type!='custom'){
            wp_send_json_error(__( 'Please Select SMTP Type Custom.', 'nexter-extension' ));
        }

        $settings = self::$smtp_opt['custom'];
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $from_email = !empty($settings['from_email']) ? sanitize_email($settings['from_email']) : '';
            $from_name  = !empty($settings['from_name'])  ? sanitize_text_field($settings['from_name']) : '';

            if ($from_email && !is_email($from_email)) {
                wp_send_json_error(__( 'Invalid From Email address.', 'nexter-extension' ));
                
            }

            $mail->isSMTP();
            $mail->SMTPDebug = 0; // Set to 2 for detailed debug
            $mail->Host = $settings['host'];
            $mail->Port = $settings['port'];
            $mail->SMTPAuth = $settings['auth'] == 'true';
            $mail->SMTPSecure = $settings['encryption'] !== 'none' ? $settings['encryption'] : '';
            $mail->SMTPAutoTLS = $settings['autoTLS'] == 'true';
            $mail->Username = $settings['username'];
            $mail->Password = $settings['password'];

            // Apply From values only if provided
            if ($from_email) {
                $mail->setFrom($from_email, $from_name);
            }

            // Try to connect only (no send)
            if (!$mail->smtpConnect()) {
                wp_send_json_error( __( 'SMTP Connection failed.', 'nexter-extension' ) );
            }

            $mail->smtpClose();

            $option['smtp-email']['values']['custom'] = array_merge(
                $option['smtp-email']['values']['custom'] ?? [],
                [
                    'connect'   => true,
                ],
            );
            update_option('nexter_extra_ext_options', $option);
            self::$smtp_opt = $option['smtp-email']['values'];
            
            wp_send_json_success(['connect' => true, 'message' => __( 'SMTP Connection successful.', 'nexter-extension' )]);
        } catch (Exception $e) {
            wp_send_json_error( __( 'SMTP Connection failed.', 'nexter-extension' ) );
        }
    }
}

new Nexter_Ext_SMTP_Email();