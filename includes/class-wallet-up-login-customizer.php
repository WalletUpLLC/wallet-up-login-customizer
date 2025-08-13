<?php
/**
 * Enhanced WalletUpLoginCustomizer Class
 * 
 * A streamlined and robust implementation for customizing the WordPress login experience.
 * Version: 2.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WalletUpLoginCustomizer {
    
    /**
     * Class version for update checks
     * @var string
     */
    private $version = '2.2.1';
    
    /**
     * Instance of this class (Singleton pattern)
     * @var WalletUpLoginCustomizer
     */
    private static $instance = null;
    
    /**
     * Plugin settings
     * @var array
     */
    private $settings = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        // Load settings
        $this->load_settings();
        
        // Initialize hooks
        $this->init_hooks();
    }
    
    /**
     * Get class instance (Singleton pattern)
     * 
     * @return WalletUpLoginCustomizer
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Load settings from options
     */
    private function load_settings() {
        $options = get_option('wallet_up_login_options', array());
        
        // Set default settings (store English strings, translate later)
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
        
        // Merge with saved options
        $this->settings = array_merge($defaults, $options);
        
        // If no saved loading messages, use defaults
        if (empty($options['loading_messages'])) {
            $this->settings['loading_messages'] = $defaults['loading_messages'];
        }
    }
    
    /**
     * Initialize all hooks
     */
    private function init_hooks() {
        // Fix WordPress globals for login page
        // Commented out - this approach doesn't work as expected
        // if ($GLOBALS['pagenow'] === 'wp-login.php') {
        //     $this->ensure_login_globals();
        // }
        
        // Core login customization hooks
        add_action('login_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_filter('login_headerurl', array($this, 'login_logo_url'));
        add_filter('login_headertext', array($this, 'login_logo_title'));
        
        // Fix login variables before login_header
        add_action('login_header', array($this, 'ensure_login_globals'), 1);
        
        // Also hook to login_init for AJAX compatibility
        add_action('login_init', array($this, 'ensure_login_globals'), 1);
        
        // When AJAX is enabled, ensure globals are set very early
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
        
        // AJAX hooks - Use high priority to ensure they run
        add_action('wp_ajax_nopriv_wallet_up_ajax_login', array($this, 'ajax_login'), 5);
        add_action('wp_ajax_wallet_up_ajax_login', array($this, 'ajax_login'), 5);
        add_action('wp_ajax_nopriv_wallet_up_validate_username', array($this, 'ajax_validate_username'));
        add_action('wp_ajax_wallet_up_validate_username', array($this, 'ajax_validate_username'));
        
        // Enhanced functionality hooks
        add_filter('login_body_class', array($this, 'add_login_body_class'));
        add_filter('login_errors', array($this, 'enhance_login_errors'), 20); // Lower priority to run after security filters
        add_filter('login_form_top', array($this, 'add_login_form_accessibility'));
        add_action('login_footer', array($this, 'add_action_screen'), 20);
        add_filter('login_redirect', array($this, 'custom_login_redirect'), 10, 3);
        
        // New functionality hooks
        add_action('login_head', array($this, 'add_dynamic_styles'));
        add_filter('login_message', array($this, 'welcome_back_message'));
    }
    
    /**
     * Ensure WordPress login globals are properly set
     */
    public function ensure_login_globals() {
        global $user_login, $error, $interim_login, $redirect_to, $rp_key, $rp_cookie, $wp_version;
        
        // Initialize user_login if not set
        if (!isset($user_login)) {
            $user_login = '';
            
            // Try to get username from various sources
            if (!empty($_POST['log'])) {
                $user_login = sanitize_user($_POST['log']);
            } elseif (!empty($_GET['user_login'])) {
                $user_login = sanitize_user($_GET['user_login']);
            } elseif (!empty($_COOKIE['wordpress_logged_in_' . COOKIEHASH])) {
                $cookie_parts = explode('|', $_COOKIE['wordpress_logged_in_' . COOKIEHASH]);
                if (!empty($cookie_parts[0])) {
                    $user_login = sanitize_user($cookie_parts[0]);
                }
            }
        }
        
        // Initialize other globals if needed
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
    
    /**
     * Enqueue scripts and styles for login page
     */
    public function enqueue_scripts() {
        // Create necessary directories if they don't exist
        $this->create_directories();
        
        // Deregister the default login styles for complete customization
        wp_deregister_style('login');
        
        // Enqueue our custom styles with aggressive cache busting
        $css_url = $this->get_asset_url('css/wallet-up-login.css');
        $css_version = $this->get_file_version('css/wallet-up-login.css') . '-' . time();
        
        wp_enqueue_style(
            'wallet-up-login-style', 
            $css_url, 
            array(), 
            $css_version
        );
        
        // Add Google Fonts conditionally
        if (!wp_style_is('google-fonts-inter', 'enqueued')) {
            wp_enqueue_style(
                'wallet-up-google-fonts', 
                'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap', 
                array(), 
                null
            );
        }
        
        // Add GSAP for advanced animations
        wp_enqueue_script(
            'gsap', 
            'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.11.4/gsap.min.js', 
            array(), 
            '3.11.4', 
            true
        );
        
        // Add jQuery
        wp_enqueue_script('jquery');
        
        // Add our custom script with aggressive cache busting
        $js_url = $this->get_asset_url('js/wallet-up-login.js');
        $js_version = $this->get_file_version('js/wallet-up-login.js') . '-' . time();
        
        wp_enqueue_script(
            'wallet-up-login-script', 
            $js_url, 
            array('jquery', 'gsap'), 
            $js_version, 
            true
        );
        
        // Pass data to our script
        wp_localize_script('wallet-up-login-script', 'walletUpLogin', $this->get_script_data());
        
        // Pass configuration settings to the script
        // Translate loading messages for frontend display
        $translated_messages = array();
        foreach ($this->settings['loading_messages'] as $message) {
            // Translate known default messages
            if ($message === 'Verifying your credentials...') {
                $translated_messages[] = __('Verifying your credentials...', 'wallet-up-login');
            } elseif ($message === 'Preparing your dashboard...') {
                $translated_messages[] = __('Preparing your dashboard...', 'wallet-up-login');
            } elseif ($message === 'Almost there...') {
                $translated_messages[] = __('Almost there...', 'wallet-up-login');
            } else {
                // Custom message, use as-is
                $translated_messages[] = $message;
            }
        }
        
        wp_localize_script('wallet-up-login-script', 'walletUpLoginConfig', array(
            'enableAjaxLogin' => (bool) $this->settings['enable_ajax_login'],
            'animationSpeed' => 0.4,
            'validateOnType' => true,
            'redirectDelay' => (int) $this->settings['redirect_delay'],
            'enableSounds' => (bool) $this->settings['enable_sounds'],
            'loadingMessages' => $translated_messages
        ));
    }
    
    /**
     * Get script data for localization (cached)
     * 
     * @return array
     */
    private function get_script_data() {
        static $script_data = null;
        
        if ($script_data === null) {
            $script_data = array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'homeUrl' => home_url(),
                'adminUrl' => admin_url(),
                'logoUrl' => $this->get_logo_url(),
                'siteName' => get_bloginfo('name'),
                'nonce' => wp_create_nonce('wallet-up-login-nonce'),
                'version' => $this->version,
                // Translated strings for JavaScript
                'strings' => array(
                    'verifyingCredentials' => __('Verifying your credentials...', 'wallet-up-login'),
                    'preparingDashboard' => __('Preparing your dashboard...', 'wallet-up-login'),
                    'almostThere' => __('Almost there...', 'wallet-up-login'),
                    'loginFailed' => __('Login failed. Please check your credentials and try again.', 'wallet-up-login'),
                    'loggedOut' => __('You have been logged out.', 'wallet-up-login'),
                    'passwordReset' => __('Your password has been reset successfully.', 'wallet-up-login'),
                    'welcomeBack' => __('Welcome back! Please sign in to continue.', 'wallet-up-login'),
                    'welcomeBackSuccess' => __('Welcome back! You have successfully signed in.', 'wallet-up-login'),
                    'confirmReset' => __('Are you sure you want to reset all settings to default values?', 'wallet-up-login'),
                    'processing' => __('Processing...', 'wallet-up-login'),
                    'loading' => __('Loading...', 'wallet-up-login'),
                    'signInSecurely' => __('Sign In Securely', 'wallet-up-login'),
                    'signingIn' => __('Signing In', 'wallet-up-login'),
                    'continueToDashboard' => __('Continue to Dashboard', 'wallet-up-login'),
                    'tryAgain' => __('Try Again', 'wallet-up-login'),
                    'considerStrongerPassword' => __('Consider using a stronger password', 'wallet-up-login'),
					'welcomeTitle' => __('Welcome to the Next \'Up', 'wallet-up-login'),
                    'username' => __('Username', 'wallet-up-login'),
                    'password' => __('Password', 'wallet-up-login'),
                    'showPassword' => __('Show password', 'wallet-up-login'),
                    'hidePassword' => __('Hide password', 'wallet-up-login'),
                    'invalidCredentials' => __('Invalid username or password. Please try again.', 'wallet-up-login'),
                    'serverError' => __('Server error. Please try again later.', 'wallet-up-login'),
                    'usernameRequired' => __('Username is required', 'wallet-up-login'),
                    'passwordRequired' => __('Password is required', 'wallet-up-login'),
                    'usernameTooShort' => __('Username must be at least 3 characters', 'wallet-up-login'),
                    'correctErrors' => __('Please correct the errors before signing in.', 'wallet-up-login'),
                    'welcomeBackSuccess' => __('Welcome back! You have successfully signed in.', 'wallet-up-login'),
                    'success' => __('Success!', 'wallet-up-login'),
                    'oops' => __('Oops!', 'wallet-up-login'),
                    // Admin panel strings
                    'colorSchemeApplied' => __('Color scheme applied!', 'wallet-up-login'),
                    'settingsExported' => __('Settings exported successfully!', 'wallet-up-login'),
                    'settingsImported' => __('Settings imported successfully', 'wallet-up-login'),
                    'settingsReset' => __('Settings reset to defaults!', 'wallet-up-login'),
                    'selectValidFile' => __('Please select a valid JSON file', 'wallet-up-login'),
                    'errorImporting' => __('Error importing settings', 'wallet-up-login'),
                    'errorReadingFile' => __('Error reading file', 'wallet-up-login'),
                    'errorImageUrl' => __('Error: Could not get image URL', 'wallet-up-login'),
                    'errorInvalidUrl' => __('Error: Invalid image URL format', 'wallet-up-login'),
					'resetPassword' => __('Reset Password', 'wallet-up-login'),
                    'backToLogin' => __('Back to Login', 'wallet-up-login'),
                    'forgotPassword' => __('Forgot Password?', 'wallet-up-login'),
                    'registerNewAccount' => __('Register New Account', 'wallet-up-login'),
                    'backToSite' => sprintf(__('Back to %s', 'wallet-up-login'), get_bloginfo('name')),
                    'welcomeTo' => __('Welcome to', 'wallet-up-login'),
                    'rememberMe' => __('Remember Me', 'wallet-up-login')
                )
            );
        }
        
        return $script_data;
    }
    
    /**
     * Add dynamic styles to the login page
     */
    public function add_dynamic_styles() {
        // Get colors from settings - with proper validation
        $primary_color = $this->sanitize_hex_color($this->settings['primary_color']);
        $gradient_start = $this->sanitize_hex_color($this->settings['gradient_start']);
        $gradient_end = $this->sanitize_hex_color($this->settings['gradient_end']);
        
        // Get custom logo URL - USE THE SAME METHOD AS THE FOOTER!
        $custom_logo_url = $this->get_logo_url();
        
        // Build CSS with proper escaping
        $css = '
            :root {
                --wallet-up-primary: ' . esc_attr($primary_color) . ';
                --wallet-up-primary-dark: ' . esc_attr($this->adjust_brightness($primary_color, -20)) . ';
                --wallet-up-primary-light: ' . esc_attr($this->adjust_brightness($primary_color, 45)) . ';
                --wallet-up-primary-gradient: linear-gradient(135deg, ' . esc_attr($gradient_start) . ' 0%, ' . esc_attr($gradient_end) . ' 100%);
            }
            
            /* Dynamic logo from Custom Logo URL - ' . esc_url($custom_logo_url) . ' */
            .wallet-up-login-logo {
                background-image: url("' . esc_url($custom_logo_url) . '") !important;
                background-repeat: no-repeat !important;
                background-position: center !important;
                background-size: contain !important;
                height: 50px !important;
                max-height: 50px !important;
                margin-bottom: 30px !important;
            }';
        
        // Continue building CSS with proper escaping
        $css .= '
            #wp-submit,
            .login .button-primary,
            .wallet-up-login-button {
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
            
        // Hide remember me checkbox when disabled
        if (empty($this->settings['show_remember_me'])) {
            $css .= '
            /* Hide remember me checkbox when disabled */
            .forgetmenot {
                display: none !important;
            }';
        }
        
        // Output the complete CSS with proper escaping
        echo '<style type="text/css">' . $css . '</style>';
    }
    
    /**
     * Sanitize hex color value
     * 
     * @param string $color Hex color code
     * @return string Sanitized hex color or default
     */
    private function sanitize_hex_color($color) {
        if (empty($color)) {
            return '#674FBF'; // Default color
        }
        
        // Remove any spaces
        $color = trim($color);
        
        // Add # if missing
        if (strpos($color, '#') !== 0) {
            $color = '#' . $color;
        }
        
        // Validate hex color
        if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
            return $color;
        }
        
        return '#674FBF'; // Return default if invalid
    }
    
    /**
     * Convert hex color to RGB format
     * 
     * @param string $hex Hex color code
     * @return string Comma-separated RGB values
     */
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
    
    /**
     * Adjust the brightness of a color
     * 
     * @param string $hex Hex color code
     * @param int $steps Steps to adjust (-255 to 255)
     * @return string Adjusted hex color
     */
    private function adjust_brightness($hex, $steps) {
        // Remove # if present
        $hex = str_replace('#', '', $hex);
        
        // Convert to RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        // Adjust
        $r = max(0, min(255, $r + $steps));
        $g = max(0, min(255, $g + $steps));
        $b = max(0, min(255, $b + $steps));
        
        // Convert back to hex
        return '#' . sprintf('%02x%02x%02x', $r, $g, $b);
    }
    
    /**
     * Get asset URL
     * 
     * @param string $path Relative path to asset
     * @return string Full URL to asset
     */
    private function get_asset_url($path) {
        return plugin_dir_url(dirname(__FILE__)) . $path;
    }
    
    /**
     * Get logo URL from settings or default
     * 
     * @return string Logo URL
     */
    private function get_logo_url() {
        if (!empty($this->settings['custom_logo_url'])) {
            return esc_url($this->settings['custom_logo_url']);
        }
        
        // Try to get the site icon
        $site_icon_id = get_option('site_icon');
        if ($site_icon_id) {
            $site_icon_url = wp_get_attachment_image_url($site_icon_id, 'full');
            if ($site_icon_url) {
                return $site_icon_url;
            }
        }
        
        // Default logo
        return $this->get_asset_url('img/walletup-icon.png');
    }
    
    /**
     * Create necessary directories with proper error handling
     */
    private function create_directories() {
        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        $directories = array('css', 'js', 'img');
        
        foreach ($directories as $dir) {
            $dir_path = $plugin_dir . $dir;
            
            if (!file_exists($dir_path)) {
                $created = wp_mkdir_p($dir_path);
                
                if (!$created) {
                    // Log error if directory creation fails
                    error_log("WalletUp Login: Failed to create directory: {$dir_path}");
                }
            }
        }
    }
    
    /**
     * Get file version based on file modification time
     * 
     * @param string $file Relative path to file
     * @return string File version
     */
    private function get_file_version($file) {
        $file_path = plugin_dir_path(dirname(__FILE__)) . $file;
        
        if (file_exists($file_path)) {
            return filemtime($file_path);
        }
        
        return $this->version;
    }
    
    /**
     * Change the login logo URL to point to the site
     * 
     * @return string Site URL
     */
    public function login_logo_url() {
        return home_url();
    }
    
    /**
     * Change the login logo title
     * 
     * @return string Logo title
     */
    public function login_logo_title() {
        return __('Wallet Up - Advanced URL & QR Tools', 'wallet-up-login');
    }
    
    /**
     * Add custom HTML structure for login form
     */
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
        
        // Add preload for logo
        $logo_url = $this->get_logo_url();
        echo '<link rel="preload" href="' . esc_url($logo_url) . '" as="image">';
    }
    
    /**
     * Add custom footer
     */
    public function login_footer() {
        $current_year = date('Y');
        $logo_url = $this->get_logo_url();
        
        echo '<div id="wallet-up-login-footer">
                <div class="footer-content">
                  <div class="footer-logo">
                    <img src="' . esc_url($logo_url) . '" alt="Wallet Up" width="18" height="18" />
                  </div>
                  <div class="footer-text">
                    <p>' . esc_html__('Wallet Up — Advanced URL & QR Tools', 'wallet-up-login') . '</p>
                    <p class="copyright">© ' . esc_html($current_year) . ' ' . esc_html__('All Rights Reserved', 'wallet-up-login') . '</p>
                  </div>
                </div>
              </div>';
        
        // Add JavaScript for handling URL parameters
        echo '<script>
            if (window.history.replaceState) {
                // Clean up URL after processing parameters
                var cleanURL = window.location.protocol + "//" + window.location.host + window.location.pathname;
                window.history.replaceState({path: cleanURL}, "", cleanURL);
            }
        </script>';
    }
    
    /**
     * Enhance the login button with additional classes and styling
     * 
     * @param string $button Button HTML
     * @param array $args Button arguments
     * @return string Modified button HTML
     */
    public function enhance_login_button($button, $args) {
        $button = str_replace('button ', 'button wallet-up-login-button ', $button);
        $button = str_replace(__('Log In'), esc_html__('Sign In Securely', 'wallet-up-login'), $button);
        return $button;
    }
    
    /**
     * Handle AJAX login
     */
    public function ajax_login() {
        try {
            // Set proper headers for AJAX response
            @header('Content-Type: application/json; charset=utf-8');
            
            // Check the nonce - use false to prevent die() on failure
            $nonce_check = check_ajax_referer('wallet-up-login-nonce', 'security', false);
            
            if (!$nonce_check) {
                wp_send_json_error(array(
                    'message' => 'Security verification failed. Please refresh the page and try again.',
                    'code' => 'invalid_nonce'
                ));
                return;
            }
            
            // SECURITY: Enhanced enterprise security validation
            if (class_exists('WalletUpEnterpriseSecurity')) {
                // Always check honeypot field to prevent bots
                if (!empty($_POST['wallet_up_honeypot'])) {
                    wp_send_json_error(array(
                        'message' => 'Automated login attempts are not allowed.',
                        'code' => 'bot_detected'
                    ));
                    return;
                }
                
                // Additional security nonce validation if enterprise security is active
                if (isset($_POST['wallet_up_security_nonce'])) {
                    if (!wp_verify_nonce($_POST['wallet_up_security_nonce'], 'wallet_up_login_security')) {
                        wp_send_json_error(array(
                            'message' => 'Security validation failed.',
                            'code' => 'security_validation_failed'
                        ));
                        return;
                    }
                }
            }
            
            // Get the login credentials
            $credentials = array(
                'user_login' => isset($_POST['username']) ? sanitize_user($_POST['username']) : '',
                'user_password' => isset($_POST['password']) ? $_POST['password'] : '',
                'remember' => isset($_POST['remember']) && $_POST['remember'] === 'true'
            );
            
            // Get the redirect URL if provided
            $redirect_to = isset($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : '';
            
            // Validate required fields
            if (empty($credentials['user_login']) || empty($credentials['user_password'])) {
                wp_send_json_error(array(
                    'message' => __('Username and password are required', 'wallet-up-login'),
                    'code' => 'empty_fields'
                ));
                return;
            }
            
            // Add a slight delay for better UX
            usleep(500000); // 0.5 seconds
            
            // Ensure no output before wp_signon
            if (ob_get_level()) {
                ob_clean();
            }
            
            // Initialize login globals to prevent wallet-up-pro conflicts
            global $user_login, $error;
            if (!isset($user_login)) {
                $user_login = $credentials['user_login'];
            }
            if (!isset($error)) {
                $error = '';
            }
            
            // Attempt to sign the user in
            $user = wp_signon($credentials, is_ssl());
            
            // If login failed, return error
            if (is_wp_error($user)) {
                wp_send_json_error(array(
                    'message' => $this->enhance_login_errors($user->get_error_message()),
                    'code' => $user->get_error_code()
                ));
            } else {
                // Get user info for personalized response
                $user_info = get_userdata($user->ID);
                $display_name = $user_info ? $user_info->display_name : 'User';
                
                // Determine redirect URL
                $final_redirect = $this->get_redirect_url($redirect_to, $user);
                
                // Login successful, return success with redirect URL and personalized message
                wp_send_json_success(array(
                    'redirect' => $final_redirect,
                    'message' => sprintf(__('Welcome back, %s!', 'wallet-up-login'), esc_html($display_name))
                ));
            }
        } catch (Throwable $t) {
            // Log the actual error for debugging
            error_log('WalletUp AJAX Login Error: ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine());
            
            wp_send_json_error(array(
                'message' => 'Server error. Please try again later.',
                'code' => 'server_error'
            ));
        }
    }
    
    /**
     * Get the appropriate redirect URL for a user
     * 
     * @param string $redirect_to Requested redirect URL
     * @param WP_User $user Logged in user
     * @return string Redirect URL
     */
    private function get_redirect_url($redirect_to, $user) {
        // If a specific redirect URL was provided, use it
        if (!empty($redirect_to) && $redirect_to !== admin_url() && $redirect_to !== admin_url('profile.php')) {
            return $redirect_to;
        }
        
        // Check if user is administrator and exempt from redirects
        $is_admin = isset($user->roles) && is_array($user->roles) && in_array('administrator', $user->roles);
        if ($is_admin && !empty($this->settings['exempt_admin_roles'])) {
            return admin_url(); // Allow admins to access dashboard
        }
        
        // Check if we should redirect to Wallet Up (requires wallet-up-pro)
        if (!empty($this->settings['redirect_to_wallet_up'])) {
            // Only redirect if wallet-up-pro is actually active
            if ($this->is_wallet_up_available()) {
                // Find the Wallet Up page
                $wallet_up_page = $this->find_wallet_up_page();
                if ($wallet_up_page) {
                    return $wallet_up_page;
                }
            }
        }
        
        // Check if intelligent role-based redirect is enabled
        if (!empty($this->settings['dashboard_redirect'])) {
            if (isset($user->roles) && is_array($user->roles)) {
                // Administrator
                if (in_array('administrator', $user->roles)) {
                    return admin_url();
                }
                // Editor or Author - content creators
                else if (in_array('editor', $user->roles) || in_array('author', $user->roles)) {
                    return admin_url('edit.php');
                }
                // Shop Manager (WooCommerce)
                else if (in_array('shop_manager', $user->roles)) {
                    if (class_exists('WooCommerce')) {
                        return admin_url('edit.php?post_type=shop_order');
                    }
                    return admin_url();
                }
                // Customer or Subscriber
                else if (in_array('customer', $user->roles) || in_array('subscriber', $user->roles)) {
                    // Check for WooCommerce first
                    if (function_exists('wc_get_page_permalink')) {
                        $account_page = wc_get_page_permalink('myaccount');
                        if ($account_page) {
                            return $account_page;
                        }
                    }
                    // Check for common account page patterns
                    $possible_pages = array('/my-account/', '/account/', '/profile/');
                    foreach ($possible_pages as $page) {
                        if (get_page_by_path(trim($page, '/'))) {
                            return home_url($page);
                        }
                    }
                    // Default to home page for customers/subscribers
                    return home_url('/');
                }
                // Any other role - go to admin
                else {
                    return admin_url();
                }
            }
        }
        
        // Default WordPress behavior when no options are set
        // This respects WordPress's default login redirect
        if (empty($redirect_to)) {
            return admin_url();
        }
        
        return $redirect_to;
    }
    
    /**
     * Check if Wallet Up plugin is available
     * 
     * @return bool
     */
    private function is_wallet_up_available() {
        // Check if plugin_active function exists
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        // Check if any Wallet Up variant is active
        return is_plugin_active('wallet-up/wallet-up.php') || 
               is_plugin_active('wallet-up-pro/wallet-up.php') ||
               is_plugin_active('walletup/walletup.php');
    }
    
    /**
     * Find the Wallet Up admin page URL
     * 
     * @return string|false
     */
    private function find_wallet_up_page() {
        // First check for the admin page
        $admin_page = admin_url('admin.php?page=wallet-up');
        
        // Check if the page exists by looking for the menu
        global $submenu, $menu;
        if (isset($submenu['wallet-up']) || isset($menu['wallet-up'])) {
            return $admin_page;
        }
        
        // Alternative: Look for common Wallet Up page slugs
        $possible_slugs = array('wallet-up', 'walletup', 'wallet-up-pro');
        foreach ($possible_slugs as $slug) {
            if (isset($submenu[$slug]) || isset($menu[$slug])) {
                return admin_url('admin.php?page=' . $slug);
            }
        }
        
        // Check for frontend Wallet Up pages
        $pages = array('wallet-up-app', 'my-wallet', 'wallet');
        foreach ($pages as $page_slug) {
            $page = get_page_by_path($page_slug);
            if ($page && $page->post_status === 'publish') {
                return get_permalink($page);
            }
        }
        
        return false;
    }
    
    /**
     * AJAX handler for validating username
     */
    public function ajax_validate_username() {
        // SECURITY: Removed session handling - WordPress uses cookies
        
        // Check for nonce
        check_ajax_referer('wallet-up-login-nonce', 'security');
        
        // Get username from request
        $username = isset($_POST['username']) ? sanitize_user($_POST['username']) : '';
        
        if (empty($username)) {
            wp_send_json_error(array(
                'exists' => false,
                'message' => __('Username is required', 'wallet-up-login')
            ));
            return;
        }
        
        // Check if username exists
        $user = username_exists($username);
        
        if ($user) {
            // Get the user's display name if available
            $user_data = get_userdata($user);
            $display_name = $user_data ? $user_data->display_name : '';
            
            wp_send_json_success(array(
                'exists' => true,
                'message' => __('Username exists', 'wallet-up-login'),
                'display_name' => $display_name
            ));
        } else {
            wp_send_json_error(array(
                'exists' => false,
                'message' => __('Username does not exist', 'wallet-up-login')
            ));
        }
    }
    
    /**
     * Add custom body class to login page
     * 
     * @param array $classes Existing body classes
     * @return array Modified body classes
     */
    public function add_login_body_class($classes) {
        $classes[] = 'wallet-up-enhanced-login';
        
        // Add a class for specific login form
        global $pagenow;
        if ($pagenow === 'wp-login.php') {
            if (isset($_GET['action'])) {
                $classes[] = 'wallet-up-' . sanitize_html_class($_GET['action']);
            } else {
                $classes[] = 'wallet-up-login-form';
            }
        }
        
        return $classes;
    }
    
    /**
     * Enhance login error messages to be more user-friendly
     * 
     * @param string $error Original error message
     * @return string Enhanced error message
     */
    public function enhance_login_errors($error) {
        // CRITICAL FIX: Suppress all error messages when user just logged out
        if (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true') {
            return ''; // Return empty string to suppress any error messages after logout
        }
        
        // Make error messages more user-friendly and specific
        if (strpos($error, 'incorrect password') !== false) {
            return __('Oops! The password you entered is incorrect. Please try again or use the password reset link below.', 'wallet-up-login');
        } elseif (strpos($error, 'Invalid username') !== false) {
            return __('The username you entered doesn\'t appear to exist. Double-check it or create a new account.', 'wallet-up-login');
        } elseif (strpos($error, 'empty password') !== false) {
            return __('Please enter your password to log in.', 'wallet-up-login');
        } elseif (strpos($error, 'empty username') !== false) {
            return __('Please enter your username to log in.', 'wallet-up-login');
        } elseif (strpos($error, 'account has been marked as a spammer') !== false) {
            return __('This account has been suspended. Please contact support for assistance.', 'wallet-up-login');
        }
        
        return $error;
    }
    
    /**
     * Add welcome back message for returning users
     * 
     * @param string $message Current login message
     * @return string Modified login message
     */
    public function welcome_back_message($message) {
        // LOGOUT SUCCESS: Show positive logout message instead of error
        if (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true') {
            $message .= '<div class="message wallet-up-logout-success" style="border-left: 4px solid #46b450; background: #f7fcf7; color: #2d4f3e; margin: 16px 0; padding: 12px; border-radius: 3px; font-weight: 500;">' . __('✓ You have been successfully logged out. Please sign in again to continue.', 'wallet-up-login') . '</div>';
            return $message;
        }
        
        // Check for a specific parameter or cookie
        if (isset($_GET['welcome_back']) && $_GET['welcome_back'] === 'true') {
            $message .= '<div class="message">' . __('Welcome back! Please sign in to continue.', 'wallet-up-login') . '</div>';
        }
        
        return $message;
    }
    
    /**
     * Add accessibility attributes to login form
     * 
     * @param string $content Original form content
     * @return string Modified form content
     */
    public function add_login_form_accessibility($content) {
        // Add ARIA attributes for better accessibility
        $accessibility_attrs = ' aria-labelledby="login-form-title" aria-describedby="login-instructions"';
        
        // Add hidden instructions for screen readers
        $screen_reader_text = '<div id="login-instructions" class="screen-reader-text">' . esc_html__('Please enter your username and password to access the dashboard.', 'wallet-up-login') . '</div>';
        
        return $content . $screen_reader_text;
    }
    
    /**
     * Add action screen container for login feedback
     */
    public function add_action_screen() {
        // This screen will be controlled by JavaScript
        echo '<div class="wallet-up-action-screen" role="dialog" aria-modal="true" aria-labelledby="action-screen-title" aria-describedby="action-screen-message">
                <div class="action-screen-content">
                    <div class="action-screen-icon">
                        <div class="action-loading-spinner"></div>
                    </div>
                    <h2 id="action-screen-title" class="action-screen-title">' . esc_html__('Signing In', 'wallet-up-login') . '</h2>
                    <p id="action-screen-message" class="action-screen-message">' . esc_html__('Verifying your credentials...', 'wallet-up-login') . '</p>
                    <div class="action-progress">
                        <div class="action-progress-bar"></div>
                    </div>
                    <button type="button" class="action-screen-button">' . esc_html__('Continue', 'wallet-up-login') . '</button>
                </div>
            </div>';
    }
    
    /**
     * Customize login redirect URL
     * 
     * @param string $redirect_to Original redirect URL
     * @param string $request Request URL
     * @param WP_User $user User object
     * @return string Modified redirect URL
     */
    public function custom_login_redirect($redirect_to, $request, $user) {
        // Return early if user login failed
        if (is_wp_error($user)) {
            return $redirect_to;
        }
        
        // Check if AJAX login is handling this
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return $redirect_to;
        }
        
        // Use our helper method to get the appropriate redirect URL
        return $this->get_redirect_url($redirect_to, $user);
    }
}