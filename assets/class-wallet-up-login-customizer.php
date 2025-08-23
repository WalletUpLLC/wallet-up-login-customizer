<?php
/**
 * Enhanced WalletUpLoginCustomizer Class
 * 
 * A streamlined and robust implementation for customizing the WordPress login experience.
 * Version: 2.2.0
 */

if (!defined('ABSPATH')) {
    exit; 
}

class WalletUpLoginCustomizer {

    private $version = '2.2.1';

    private static $instance = null;

    private $settings = array();

    public function __construct() {
        
        $this->load_settings();

        $this->init_hooks();
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    private function load_settings() {
        $options = get_option('wallet_up_login_customizer_options', array());

        $defaults = array(
            'enable_ajax_login' => true,
            'custom_logo_url' => '',
            'primary_color' => '#674FBF',
            'gradient_start' => '#674FBF',
            'gradient_end' => '#7B68D4',
            'enable_sounds' => true,
            'loading_messages' => array(
                'Verifying your credentials...',
                'Preparing your dashboard...',
                'Almost there...'
            ),
            'redirect_delay' => 1500,
            'dashboard_redirect' => true,
            'show_remember_me' => true
        );

        $this->settings = array_merge($defaults, $options);

        if (empty($options['loading_messages'])) {
            $this->settings['loading_messages'] = $defaults['loading_messages'];
        }
    }

    private function init_hooks() {

        add_action('login_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_filter('login_headerurl', array($this, 'login_logo_url'));
        add_filter('login_headertext', array($this, 'login_logo_title'));

        add_action('login_header', array($this, 'ensure_login_globals'), 1);

        add_action('login_init', array($this, 'ensure_login_globals'), 1);

        if (!empty($this->settings['enable_ajax_login'])) {
            add_action('init', function() {
                if (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php') {
                    $this->ensure_login_globals();
                }
            }, 1);
        }
        add_action('login_header', array($this, 'login_form_structure'), 10);
        
        add_action('login_footer', array($this, 'login_footer'));
        add_filter('login_form_submit_button', array($this, 'enhance_login_button'), 10, 2);

        add_action('wp_ajax_nopriv_wallet_up_ajax_login', array($this, 'ajax_login'), 5);
        add_action('wp_ajax_wallet_up_ajax_login', array($this, 'ajax_login'), 5);
        add_action('wp_ajax_nopriv_wallet_up_validate_username', array($this, 'ajax_validate_username'));
        add_action('wp_ajax_wallet_up_validate_username', array($this, 'ajax_validate_username'));

        add_filter('login_body_class', array($this, 'add_login_body_class'));
        add_filter('login_errors', array($this, 'enhance_login_errors'), 20); 
        add_filter('login_form_top', array($this, 'add_login_form_accessibility'));
        add_action('login_footer', array($this, 'add_action_screen'), 20);
        add_filter('login_redirect', array($this, 'custom_login_redirect'), 10, 3);

        add_action('login_head', array($this, 'add_dynamic_styles'));
        add_filter('login_message', array($this, 'welcome_back_message'));
    }

    public function ensure_login_globals() {
        global $user_login, $error, $interim_login, $redirect_to, $rp_key, $rp_cookie, $wp_version;

        if (!isset($user_login)) {
            $user_login = '';

            if (!empty($_POST['log'])) {
                $user_login = sanitize_user($_POST['log']);
            } elseif (!empty($_GET['user_login'])) {
                $user_login = sanitize_user($_GET['user_login']);
            } elseif (is_user_logged_in()) {
                // Use WordPress authentication instead of direct cookie access
                $current_user = wp_get_current_user();
                if ($current_user && $current_user->user_login) {
                    $user_login = $current_user->user_login;
                }
            }
        }

        if (!isset($error)) {
            $error = '';
        }
        
        if (!isset($interim_login)) {
            $interim_login = isset($_REQUEST['interim-login']);
        }
        
        if (!isset($redirect_to)) {
            $redirect_to = admin_url();
            if (!empty($_REQUEST['redirect_to'])) {
                $redirect_to = wp_sanitize_redirect($_REQUEST['redirect_to']);
            }
        }
    }

    public function enqueue_scripts() {
        
        $this->create_directories();

        wp_deregister_style('login');

        $css_url = $this->get_asset_url('css/wallet-up-login-customizer.css');
        $css_version = $this->get_file_version('css/wallet-up-login-customizer.css') . '-' . time();
        
        wp_enqueue_style(
            'wallet-up-login-customizer-style', 
            $css_url, 
            array(), 
            $css_version
        );

        if (!wp_style_is('google-fonts-inter', 'enqueued')) {
            wp_enqueue_style(
                'wallet-up-google-fonts', 
                'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap', 
                array(), 
                null
            );
        }

        // WULCA (Wallet Up Login Customizer Animation) with cache buster
        $wulca_file = dirname(dirname(__FILE__)) . '/js/libs/wulca.js';
        $wulca_version = file_exists($wulca_file) ? '2.0.0.' . filemtime($wulca_file) : '2.0.0';
        
        wp_enqueue_script(
            'wulca', 
            plugin_dir_url(dirname(__FILE__)) . 'js/libs/wulca.js', 
            array(), 
            $wulca_version, 
            true
        );

        wp_enqueue_script('jquery');

        $js_url = $this->get_asset_url('js/wallet-up-login-customizer.js');
        $js_version = $this->get_file_version('js/wallet-up-login-customizer.js') . '-' . time();
        
        wp_enqueue_script(
            'wallet-up-login-customizer-script', 
            $js_url, 
            array('jquery', 'wulca'), 
            $js_version, 
            true
        );

        wp_localize_script('wallet-up-login-customizer-script', 'walletUpLogin', $this->get_script_data());

        $translated_messages = array();
        foreach ($this->settings['loading_messages'] as $message) {
            
            if ($message === 'Verifying your credentials...') {
                $translated_messages[] = __('Verifying your credentials...', 'wallet-up-login-customizer');
            } elseif ($message === 'Preparing your dashboard...') {
                $translated_messages[] = __('Preparing your dashboard...', 'wallet-up-login-customizer');
            } elseif ($message === 'Almost there...') {
                $translated_messages[] = __('Almost there...', 'wallet-up-login-customizer');
            } else {
                
                $translated_messages[] = $message;
            }
        }
        
        wp_localize_script('wallet-up-login-customizer-script', 'walletUpLoginConfig', array(
            'enableAjaxLogin' => (bool) $this->settings['enable_ajax_login'],
            'animationSpeed' => 0.4,
            'validateOnType' => true,
            'redirectDelay' => (int) $this->settings['redirect_delay'],
            'enableSounds' => (bool) $this->settings['enable_sounds'],
            'loadingMessages' => $translated_messages
        ));
    }

    private function get_script_data() {
        static $script_data = null;
        
        if ($script_data === null) {
            $script_data = array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'homeUrl' => home_url(),
                'adminUrl' => admin_url(),
                'logoUrl' => $this->get_logo_url(),
                'siteName' => get_bloginfo('name'),
                'nonce' => wp_create_nonce('wallet-up-login-customizer-nonce'),
                'version' => $this->version,
                
                'strings' => array(
                    'verifyingCredentials' => __('Verifying your credentials...', 'wallet-up-login-customizer'),
                    'preparingDashboard' => __('Preparing your dashboard...', 'wallet-up-login-customizer'),
                    'almostThere' => __('Almost there...', 'wallet-up-login-customizer'),
                    'loginFailed' => __('Login failed. Please check your credentials and try again.', 'wallet-up-login-customizer'),
                    'loggedOut' => __('You have been logged out.', 'wallet-up-login-customizer'),
                    'passwordReset' => __('Your password has been reset successfully.', 'wallet-up-login-customizer'),
                    'welcomeBack' => __('Welcome back! Please sign in to continue.', 'wallet-up-login-customizer'),
                    'welcomeBackSuccess' => __('Welcome back! You have successfully signed in.', 'wallet-up-login-customizer'),
                    'confirmReset' => __('Are you sure you want to reset all settings to default values?', 'wallet-up-login-customizer'),
                    'processing' => __('Processing...', 'wallet-up-login-customizer'),
                    'loading' => __('Loading...', 'wallet-up-login-customizer'),
                    'signInSecurely' => __('Sign In Securely', 'wallet-up-login-customizer'),
                    'signingIn' => __('Signing In', 'wallet-up-login-customizer'),
                    'continueToDashboard' => __('Continue to Dashboard', 'wallet-up-login-customizer'),
                    'tryAgain' => __('Try Again', 'wallet-up-login-customizer'),
                    'considerStrongerPassword' => __('Consider using a stronger password', 'wallet-up-login-customizer'),
					'welcomeTitle' => __('Welcome to the Next \'Up', 'wallet-up-login-customizer'),
                    'username' => __('Username', 'wallet-up-login-customizer'),
                    'password' => __('Password', 'wallet-up-login-customizer'),
                    'showPassword' => __('Show password', 'wallet-up-login-customizer'),
                    'hidePassword' => __('Hide password', 'wallet-up-login-customizer'),
                    'invalidCredentials' => __('Invalid username or password. Please try again.', 'wallet-up-login-customizer'),
                    'serverError' => __('Server error. Please try again later.', 'wallet-up-login-customizer'),
                    'usernameRequired' => __('Username is required', 'wallet-up-login-customizer'),
                    'passwordRequired' => __('Password is required', 'wallet-up-login-customizer'),
                    'usernameTooShort' => __('Username must be at least 3 characters', 'wallet-up-login-customizer'),
                    'correctErrors' => __('Please correct the errors before signing in.', 'wallet-up-login-customizer'),
                    'welcomeBackSuccess' => __('Welcome back! You have successfully signed in.', 'wallet-up-login-customizer'),
                    'success' => __('Success!', 'wallet-up-login-customizer'),
                    'oops' => __('Oops!', 'wallet-up-login-customizer'),
                    
                    'colorSchemeApplied' => __('Color scheme applied!', 'wallet-up-login-customizer'),
                    'settingsExported' => __('Settings exported successfully!', 'wallet-up-login-customizer'),
                    'settingsImported' => __('Settings imported successfully', 'wallet-up-login-customizer'),
                    'settingsReset' => __('Settings reset to defaults!', 'wallet-up-login-customizer'),
                    'selectValidFile' => __('Please select a valid JSON file', 'wallet-up-login-customizer'),
                    'errorImporting' => __('Error importing settings', 'wallet-up-login-customizer'),
                    'errorReadingFile' => __('Error reading file', 'wallet-up-login-customizer'),
                    'errorImageUrl' => __('Error: Could not get image URL', 'wallet-up-login-customizer'),
                    'errorInvalidUrl' => __('Error: Invalid image URL format', 'wallet-up-login-customizer'),
					'resetPassword' => __('Reset Password', 'wallet-up-login-customizer'),
                    'backToLogin' => __('Back to Login', 'wallet-up-login-customizer'),
                    'forgotPassword' => __('Forgot Password?', 'wallet-up-login-customizer'),
                    'registerNewAccount' => __('Register New Account', 'wallet-up-login-customizer'),
                    'backToSite' => sprintf(__('Back to %s', 'wallet-up-login-customizer'), get_bloginfo('name')),
                    'welcomeTo' => __('Welcome to', 'wallet-up-login-customizer'),
                    'rememberMe' => __('Remember Me', 'wallet-up-login-customizer')
                )
            );
        }
        
        return $script_data;
    }

    public function add_dynamic_styles() {
        
        $primary_color = $this->sanitize_hex_color($this->settings['primary_color']);
        $gradient_start = $this->sanitize_hex_color($this->settings['gradient_start']);
        $gradient_end = $this->sanitize_hex_color($this->settings['gradient_end']);

        $custom_logo_url = $this->get_logo_url();

        $css = '
            :root {
                --wallet-up-primary: ' . esc_attr($primary_color) . ';
                --wallet-up-primary-dark: ' . esc_attr($this->adjust_brightness($primary_color, -20)) . ';
                --wallet-up-primary-light: ' . esc_attr($this->adjust_brightness($primary_color, 45)) . ';
                --wallet-up-primary-gradient: linear-gradient(135deg, ' . esc_attr($gradient_start) . ' 0%, ' . esc_attr($gradient_end) . ' 100%);
            }
            
            /* Dynamic logo from Custom Logo URL - ' . esc_url($custom_logo_url) . ' */
            .wallet-up-login-customizer-logo {
                background-image: url("' . esc_url($custom_logo_url) . '") !important;
                background-repeat: no-repeat !important;
                background-position: center !important;
                background-size: contain !important;
                height: 50px !important;
                max-height: 50px !important;
                margin-bottom: 30px !important;
            }';

        $css .= '
            #wp-submit,
            .login .button-primary,
            .wallet-up-login-customizer-button {
                background: var(--wallet-up-primary-gradient);
                box-shadow: 0 8px 16px rgba(' . $this->hex2rgb($primary_color) . ', 0.2);
            }
            
            #wp-submit:hover,
            .login .button-primary:hover {
                box-shadow: 0 8px 20px rgba(' . $this->hex2rgb($primary_color) . ', 0.3),
                            0 2px 8px rgba(' . $this->hex2rgb($primary_color) . ', 0.2);
            }
            
            .login #nav a:hover,
            .login #backtoblog a:hover {
                color: var(--wallet-up-primary);
            }
            
            .user-login-wrap.is-focused::after,
            .user-pass-wrap.is-focused::after {
                background: var(--wallet-up-primary-gradient);
            }
            
            .user-login-wrap.is-focused .wallet-up-animated-label,
            .user-login-wrap.has-value .wallet-up-animated-label,
            .user-pass-wrap.is-focused .wallet-up-animated-label,
            .user-pass-wrap.has-value .wallet-up-animated-label {
                color: var(--wallet-up-primary);
            }
            
            .login input[type=text]:focus,
            .login input[type=password]:focus {
                border-color: var(--wallet-up-primary);
                box-shadow: 0 0 0 3px rgba(' . $this->hex2rgb($primary_color) . ', 0.15);
            }
            
            .forgetmenot input[type="checkbox"]:checked {
                background-color: var(--wallet-up-primary);
                border-color: var(--wallet-up-primary);
            }
            
            .login h1::after {
                background: var(--wallet-up-primary-gradient);
            }
            
            .wallet-up-form-title::after {
                background: var(--wallet-up-primary-gradient);
            }';

        if (empty($this->settings['show_remember_me'])) {
            $css .= '
            /* Hide remember me checkbox when disabled */
            .forgetmenot {
                display: none !important;
            }';
        }

        echo '<style type="text/css">' . $css . '</style>';
    }

    private function sanitize_hex_color($color) {
        if (empty($color)) {
            return '#674FBF'; 
        }

        $color = trim($color);

        if (strpos($color, '#') !== 0) {
            $color = '#' . $color;
        }

        if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
            return $color;
        }
        
        return '#674FBF'; 
    }

    private function hex2rgb($hex) {
        $hex = str_replace('#', '', $hex);
        
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        
        return $r . ',' . $g . ',' . $b;
    }

    private function adjust_brightness($hex, $steps) {
        
        $hex = str_replace('#', '', $hex);

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = max(0, min(255, $r + $steps));
        $g = max(0, min(255, $g + $steps));
        $b = max(0, min(255, $b + $steps));

        return '#' . sprintf('%02x%02x%02x', $r, $g, $b);
    }

    private function get_asset_url($path) {
        return plugin_dir_url(dirname(__FILE__)) . $path;
    }

    private function get_logo_url() {
        if (!empty($this->settings['custom_logo_url'])) {
            return esc_url($this->settings['custom_logo_url']);
        }

        $site_icon_id = get_option('site_icon');
        if ($site_icon_id) {
            $site_icon_url = wp_get_attachment_image_url($site_icon_id, 'full');
            if ($site_icon_url) {
                return $site_icon_url;
            }
        }

        return $this->get_asset_url('img/walletup-icon.png');
    }

    private function create_directories() {
        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        $directories = array('css', 'js', 'img');
        
        foreach ($directories as $dir) {
            $dir_path = $plugin_dir . $dir;
            
            if (!file_exists($dir_path)) {
                $created = wp_mkdir_p($dir_path);
                
                if (!$created) {
                    
                    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                        error_log("WalletUp Login: Failed to create directory: {$dir_path}");
                    }
                }
            }
        }
    }

    private function get_file_version($file) {
        $file_path = plugin_dir_path(dirname(__FILE__)) . $file;
        
        if (file_exists($file_path)) {
            return filemtime($file_path);
        }
        
        return $this->version;
    }

    public function login_logo_url() {
        return home_url();
    }

    public function login_logo_title() {
        return __('Wallet Up - Advanced URL & QR Tools', 'wallet-up-login-customizer');
    }

    public function login_form_structure() {
        echo '<div id="wallet-up-interactive-bg">
                <div class="animated-shape shape-1"></div>
                <div class="animated-shape shape-2"></div>
                <div class="animated-shape shape-3"></div>
              </div>';
        
        echo '<div id="wallet-up-floating-shapes">
                <div class="floating-shape"></div>
                <div class="floating-shape"></div>
                <div class="floating-shape"></div>
                <div class="floating-shape"></div>
                <div class="floating-shape"></div>
              </div>';

        $logo_url = $this->get_logo_url();
        echo '<link rel="preload" href="' . esc_url($logo_url) . '" as="image">';
    }

    public function login_footer() {
        $current_year = date('Y');
        $logo_url = $this->get_logo_url();
        
        echo '<div id="wallet-up-login-customizer-footer">
                <div class="footer-content">
                  <div class="footer-logo">
                    <img src="' . esc_url($logo_url) . '" alt="Wallet Up" width="18" height="18" />
                  </div>
                  <div class="footer-text">
                    <p>' . esc_html__('Wallet Up — Advanced URL & QR Tools', 'wallet-up-login-customizer') . '</p>
                    <p class="copyright">© ' . esc_html($current_year) . ' ' . esc_html__('All Rights Reserved', 'wallet-up-login-customizer') . '</p>
                  </div>
                </div>
              </div>';

        echo '<script>
            if (window.history.replaceState) {
                // Clean up URL after processing parameters
                var cleanURL = window.location.protocol + "//" + window.location.host + window.location.pathname;
                window.history.replaceState({path: cleanURL}, "", cleanURL);
            }
        </script>';
    }

    public function enhance_login_button($button, $args) {
        $button = str_replace('button ', 'button wallet-up-login-customizer-button ', $button);
        $button = str_replace(__('Log In'), esc_html__('Sign In Securely', 'wallet-up-login-customizer'), $button);
        return $button;
    }

    public function ajax_login() {
        try {
            
            @header('Content-Type: application/json; charset=utf-8');

            $nonce_check = check_ajax_referer('wallet-up-login-customizer-nonce', 'security', false);
            
            if (!$nonce_check) {
                wp_send_json_error(array(
                    'message' => esc_html__('Security verification failed. Please refresh the page and try again.', 'wallet-up-login-customizer'),
                    'code' => 'invalid_nonce'
                ));
                return;
            }

            // Rate limiting check
            if ($this->is_rate_limited()) {
                wp_send_json_error(array(
                    'message' => __('Too many login attempts. Please try again in a few minutes.', 'wallet-up-login-customizer'),
                    'code' => 'rate_limited'
                ));
                return;
            }

            if (class_exists('WalletUpEnterpriseSecurity')) {
                
                if (!empty($_POST['wallet_up_honeypot'])) {
                    wp_send_json_error(array(
                        'message' => esc_html__('Automated login attempts are not allowed.', 'wallet-up-login-customizer'),
                        'code' => 'bot_detected'
                    ));
                    return;
                }

                if (isset($_POST['wallet_up_security_nonce'])) {
                    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wallet_up_security_nonce'])), 'wallet_up_login_customizer_security')) {
                        wp_send_json_error(array(
                            'message' => esc_html__('Security validation failed.', 'wallet-up-login-customizer'),
                            'code' => 'security_validation_failed'
                        ));
                        return;
                    }
                }
            }

            $credentials = array(
                'user_login' => isset($_POST['username']) ? sanitize_user($_POST['username']) : '',
                'user_password' => isset($_POST['password']) ? wp_unslash($_POST['password']) : '',
                'remember' => isset($_POST['remember']) && $_POST['remember'] === 'true'
            );

            // Ensure HTTPS for password transmission
            if (!is_ssl() && !defined('FORCE_SSL_ADMIN')) {
                // Log security warning if debug is enabled
                if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    error_log('WalletUp Security Warning: Login attempt over non-HTTPS connection');
                }
            }

            $redirect_to = isset($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : '';

            if (empty($credentials['user_login']) || empty($credentials['user_password'])) {
                wp_send_json_error(array(
                    'message' => __('Username and password are required', 'wallet-up-login-customizer'),
                    'code' => 'empty_fields'
                ));
                return;
            }

            usleep(500000); 

            if (ob_get_level()) {
                ob_clean();
            }

            global $user_login, $error;
            if (!isset($user_login)) {
                $user_login = $credentials['user_login'];
            }
            if (!isset($error)) {
                $error = '';
            }

            $user = wp_signon($credentials, is_ssl());

            if (is_wp_error($user)) {
                // Track failed login attempt for rate limiting
                $this->track_failed_attempt($credentials['user_login']);
                
                wp_send_json_error(array(
                    'message' => $this->enhance_login_errors($user->get_error_message()),
                    'code' => $user->get_error_code()
                ));
            } else {
                
                $user_info = get_userdata($user->ID);
                $display_name = $user_info ? $user_info->display_name : 'User';

                $final_redirect = $this->get_redirect_url($redirect_to, $user);

                wp_send_json_success(array(
                    'redirect' => $final_redirect,
                    'message' => sprintf(__('Welcome back, %s!', 'wallet-up-login-customizer'), esc_html($display_name))
                ));
            }
        } catch (Throwable $t) {
            
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('WalletUp AJAX Login Error: ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine());
            }
            
            wp_send_json_error(array(
                'message' => esc_html__('Server error. Please try again later.', 'wallet-up-login-customizer'),
                'code' => 'server_error'
            ));
        }
    }

    private function get_redirect_url($redirect_to, $user) {
        
        if (!empty($redirect_to) && $redirect_to !== admin_url() && $redirect_to !== admin_url('profile.php')) {
            return $redirect_to;
        }

        $is_admin = isset($user->roles) && is_array($user->roles) && in_array('administrator', $user->roles);
        if ($is_admin && !empty($this->settings['exempt_admin_roles'])) {
            return admin_url(); 
        }

        if (!empty($this->settings['redirect_to_wallet_up'])) {
            
            if ($this->is_wallet_up_available()) {
                
                $wallet_up_page = $this->find_wallet_up_page();
                if ($wallet_up_page) {
                    return $wallet_up_page;
                }
            }
        }

        if (!empty($this->settings['dashboard_redirect'])) {
            if (isset($user->roles) && is_array($user->roles)) {
                
                if (in_array('administrator', $user->roles)) {
                    return admin_url();
                }
                
                else if (in_array('editor', $user->roles) || in_array('author', $user->roles)) {
                    return admin_url('edit.php');
                }
                
                else if (in_array('shop_manager', $user->roles)) {
                    if (class_exists('WooCommerce')) {
                        return admin_url('edit.php?post_type=shop_order');
                    }
                    return admin_url();
                }
                
                else if (in_array('customer', $user->roles) || in_array('subscriber', $user->roles)) {
                    
                    if (function_exists('wc_get_page_permalink')) {
                        $account_page = wc_get_page_permalink('myaccount');
                        if ($account_page) {
                            return $account_page;
                        }
                    }
                    
                    $possible_pages = array('/my-account/', '/account/', '/profile/');
                    foreach ($possible_pages as $page) {
                        if (get_page_by_path(trim($page, '/'))) {
                            return home_url($page);
                        }
                    }
                    
                    return home_url('/');
                }
                
                else {
                    return admin_url();
                }
            }
        }

        if (empty($redirect_to)) {
            return admin_url();
        }
        
        return $redirect_to;
    }

    private function is_wallet_up_available() {
        
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        return is_plugin_active('wallet-up/wallet-up.php') || 
               is_plugin_active('wallet-up-pro/wallet-up.php') ||
               is_plugin_active('walletup/walletup.php');
    }

    private function find_wallet_up_page() {
        
        $admin_page = admin_url('admin.php?page=wallet-up');

        global $submenu, $menu;
        if (isset($submenu['wallet-up']) || isset($menu['wallet-up'])) {
            return $admin_page;
        }

        $possible_slugs = array('wallet-up', 'walletup', 'wallet-up-pro');
        foreach ($possible_slugs as $slug) {
            if (isset($submenu[$slug]) || isset($menu[$slug])) {
                return admin_url('admin.php?page=' . $slug);
            }
        }

        $pages = array('wallet-up-app', 'my-wallet', 'wallet');
        foreach ($pages as $page_slug) {
            $page = get_page_by_path($page_slug);
            if ($page && $page->post_status === 'publish') {
                return get_permalink($page);
            }
        }
        
        return false;
    }

    public function ajax_validate_username() {

        check_ajax_referer('wallet-up-login-customizer-nonce', 'security');

        // Rate limiting for username validation to prevent enumeration
        if ($this->is_rate_limited('username_check')) {
            wp_send_json_error(array(
                'exists' => false,
                'message' => __('Too many requests. Please slow down.', 'wallet-up-login-customizer')
            ));
            return;
        }

        $username = isset($_POST['username']) ? sanitize_user($_POST['username']) : '';
        
        if (empty($username)) {
            wp_send_json_error(array(
                'exists' => false,
                'message' => __('Username is required', 'wallet-up-login-customizer')
            ));
            return;
        }

        $user = username_exists($username);
        
        // Prevent username enumeration - always return generic response
        if ($user) {
            // Add slight delay to prevent timing attacks
            usleep(rand(100000, 300000)); // 0.1-0.3 seconds
        }
        
        // Always return a generic response to prevent enumeration
        wp_send_json_success(array(
            'exists' => null, // Don't reveal if username exists
            'message' => __('Username validation complete', 'wallet-up-login-customizer'),
            'display_name' => '' // Don't reveal display names
        ));
    }

    public function add_login_body_class($classes) {
        $classes[] = 'wallet-up-enhanced-login';

        global $pagenow;
        if ($pagenow === 'wp-login.php') {
            if (isset($_GET['action'])) {
                $classes[] = 'wallet-up-' . sanitize_html_class($_GET['action']);
            } else {
                $classes[] = 'wallet-up-login-customizer-form';
            }
        }
        
        return $classes;
    }

    public function enhance_login_errors($error) {
        
        if (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true') {
            return ''; 
        }

        if (strpos($error, 'incorrect password') !== false) {
            return __('Oops! The password you entered is incorrect. Please try again or use the password reset link below.', 'wallet-up-login-customizer');
        } elseif (strpos($error, 'Invalid username') !== false) {
            return __('The username you entered doesn\'t appear to exist. Double-check it or create a new account.', 'wallet-up-login-customizer');
        } elseif (strpos($error, 'empty password') !== false) {
            return __('Please enter your password to log in.', 'wallet-up-login-customizer');
        } elseif (strpos($error, 'empty username') !== false) {
            return __('Please enter your username to log in.', 'wallet-up-login-customizer');
        } elseif (strpos($error, 'account has been marked as a spammer') !== false) {
            return __('This account has been suspended. Please contact support for assistance.', 'wallet-up-login-customizer');
        }
        
        return $error;
    }

    public function welcome_back_message($message) {
        
        if (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true') {
            $message .= '<div class="message wallet-up-logout-success" style="border-left: 4px solid #46b450; background: #f7fcf7; color: #2d4f3e; margin: 16px 0; padding: 12px; border-radius: 3px; font-weight: 500;">' . __('✓ You have been successfully logged out. Please sign in again to continue.', 'wallet-up-login-customizer') . '</div>';
            return $message;
        }

        if (isset($_GET['welcome_back']) && $_GET['welcome_back'] === 'true') {
            $message .= '<div class="message">' . __('Welcome back! Please sign in to continue.', 'wallet-up-login-customizer') . '</div>';
        }
        
        return $message;
    }

    public function add_login_form_accessibility($content) {
        
        $accessibility_attrs = ' aria-labelledby="login-form-title" aria-describedby="login-instructions"';

        $screen_reader_text = '<div id="login-instructions" class="screen-reader-text">' . esc_html__('Please enter your username and password to access the dashboard.', 'wallet-up-login-customizer') . '</div>';
        
        return $content . $screen_reader_text;
    }

    public function add_action_screen() {
        
        echo '<div class="wallet-up-action-screen" role="dialog" aria-modal="true" aria-labelledby="action-screen-title" aria-describedby="action-screen-message">
                <div class="action-screen-content">
                    <div class="action-screen-icon">
                        <div class="action-loading-spinner"></div>
                    </div>
                    <h2 id="action-screen-title" class="action-screen-title">' . esc_html__('Signing In', 'wallet-up-login-customizer') . '</h2>
                    <p id="action-screen-message" class="action-screen-message">' . esc_html__('Verifying your credentials...', 'wallet-up-login-customizer') . '</p>
                    <div class="action-progress">
                        <div class="action-progress-bar"></div>
                    </div>
                    <button type="button" class="action-screen-button">' . esc_html__('Continue', 'wallet-up-login-customizer') . '</button>
                </div>
            </div>';
    }

    public function custom_login_redirect($redirect_to, $request, $user) {
        
        if (is_wp_error($user)) {
            return $redirect_to;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            return $redirect_to;
        }

        return $this->get_redirect_url($redirect_to, $user);
    }

    /**
     * Check if the current request is rate limited
     *
     * @param string $action The action being rate limited
     * @return bool True if rate limited, false otherwise
     */
    private function is_rate_limited($action = 'login') {
        $ip = $this->get_client_ip();
        $key = 'wallet_up_rate_limit_' . $action . '_' . md5($ip);
        
        $attempts = get_transient($key);
        if (!$attempts) {
            $attempts = 0;
        }
        
        // Get configurable limits from unified options
        $main_options = get_option('wallet_up_login_customizer_options', array());
        $legacy_options = get_option('wallet_up_login_customizer_security_options', array());
        
        // Use main options first, fall back to legacy
        $max_attempts = isset($main_options['max_login_attempts']) ? 
                       intval($main_options['max_login_attempts']) : 
                       (!empty($legacy_options['max_login_attempts']) ? 
                        intval($legacy_options['max_login_attempts']) : 5);
        
        // Use configurable limits if available, otherwise use defaults
        $limits = array(
            'login' => $max_attempts,
            'username_check' => 10, // Keep this hardcoded for enumeration protection
            'default' => 20         // Keep this hardcoded for general rate limiting
        );
        
        $limit = isset($limits[$action]) ? $limits[$action] : $limits['default'];
        
        if ($attempts >= $limit) {
            return true;
        }
        
        // Use configurable lockout duration for login, fixed for others
        $lockout_duration = isset($main_options['lockout_duration']) ? 
                           intval($main_options['lockout_duration']) : 
                           (!empty($legacy_options['lockout_duration']) ? 
                            intval($legacy_options['lockout_duration']) : 900);
        
        if ($action === 'login') {
            $timeout = $lockout_duration;
        } else {
            $timeout = ($action === 'username_check') ? 60 : 300; // 1 minute or 5 minutes
        }
        
        set_transient($key, $attempts + 1, $timeout);
        
        return false;
    }

    /**
     * Track failed login attempt
     *
     * @param string $username The username that failed to login
     */
    private function track_failed_attempt($username) {
        $ip = $this->get_client_ip();
        $key = 'wallet_up_failed_' . md5($ip . $username);
        
        $attempts = get_transient($key);
        if (!$attempts) {
            $attempts = 0;
        }
        
        // Get lockout duration from unified options
        $main_options = get_option('wallet_up_login_customizer_options', array());
        $legacy_options = get_option('wallet_up_login_customizer_security_options', array());
        
        $lockout_duration = isset($main_options['lockout_duration']) ? 
                           intval($main_options['lockout_duration']) : 
                           (!empty($legacy_options['lockout_duration']) ? 
                            intval($legacy_options['lockout_duration']) : 900); // Default 15 minutes
        
        set_transient($key, $attempts + 1, $lockout_duration);
        
        // Also trigger the Enterprise Security tracking if available
        if (class_exists('WalletUpEnterpriseSecurity') && method_exists('WalletUpEnterpriseSecurity', 'handle_failed_login')) {
            // This ensures both systems track the failure
            do_action('wp_login_failed', $username);
        }
        
        // Log if debug is enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                'WalletUp: Failed login attempt #%d for user "%s" from IP %s (lockout: %d seconds)',
                $attempts + 1,
                sanitize_user($username),
                $ip,
                $lockout_duration
            ));
        }
    }

    /**
     * Get client IP address
     *
     * @return string The client IP address
     */
    private function get_client_ip() {
        // Check for CloudFlare IP
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        
        // Check for forwarded IP
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']));
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        
        // Check for real IP
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = sanitize_text_field($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        
        // Default to REMOTE_ADDR
        return !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '127.0.0.1';
    }
}