<?php
/**
 * Wallet Up Enterprise Security Module
 *
 * Handles enterprise-grade security features including:
 * - Forced authentication for all site access
 * - wp-login.php protection and rewriting
 * - Session management and security
 * - Rate limiting and brute force protection
 *
 * @package WalletUpLogin
 * @since 2.3.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WalletUpEnterpriseSecurity {
    /**
     * Security options
     * @var array
     */
    private static $options = [];

    /**
     * Current user exemption status
     * @var bool
     */
    private static $user_exempt = null;

    /**
     * Failed login tracking
     * @var array
     */
    private static $failed_attempts = [];

    /**
     * Cache for rate limiting - REMOVED for security
     * Now using transients directly for persistent storage
     * @deprecated 2.3.8 Use transients directly
     */
    // private static $rate_limit_cache = []; // Removed - using transients only

    /**
     * Secure logging function with rate limiting
     *
     * @param string $message The message to log
     * @param mixed $data Optional data to log (will be sanitized)
     */
    private static function secure_log($message, $data = null) {
        if (!defined('WP_DEBUG') || !WP_DEBUG || !defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return;
        }

        // Rate limit logging to prevent DoS
        static $log_count = [];
        static $last_reset = [];
        $ip = self::get_client_ip();
        $key = md5($ip . $message);
        $log_count[$key] = isset($log_count[$key]) ? $log_count[$key] + 1 : 1;

        if ($log_count[$key] > 10) { // Max 10 logs per IP per minute
            return;
        }

        // Reset log count every minute
        if (!isset($last_reset[$key]) || (time() - $last_reset[$key]) > 60) {
            $log_count[$key] = 1;
            $last_reset[$key] = time();
        }

        if ($data !== null) {
            // Sanitize and truncate data to prevent oversized logs
            if (is_array($data) || is_object($data)) {
                $safe_data = [];
                foreach ((array)$data as $key => $value) {
                    if (is_string($value) || is_numeric($value)) {
                        $safe_data[$key] = substr(sanitize_text_field($value), 0, 100);
                    } elseif (is_bool($value)) {
                        $safe_data[$key] = $value ? 'true' : 'false';
                    } else {
                        $safe_data[$key] = '[REDACTED]';
                    }
                }
                $message .= ' - ' . wp_json_encode($safe_data);
            } else {
                $message .= ' - ' . substr(sanitize_text_field($data), 0, 100);
            }
        }

        error_log('Wallet Up Security: ' . $message);
    }

    /**
     * Safely get and sanitize server variables
     *
     * @param string $key The $_SERVER key to retrieve
     * @param string $default Default value if not set
     * @return string Sanitized value
     */
    private static function get_server_var($key, $default = '') {
        if (!isset($_SERVER[$key])) {
            return $default;
        }

        $value = sanitize_text_field($_SERVER[$key]);

        // Sanitize based on the variable type
        switch ($key) {
            case 'REQUEST_URI':
            case 'HTTP_REFERER':
            case 'SCRIPT_NAME':
                return esc_url_raw($value);

            case 'HTTP_HOST':
            case 'SERVER_NAME':
                $value = strtolower($value);
                // Stricter hostname validation: must be valid domain or IP
                if (filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || 
                    filter_var($value, FILTER_VALIDATE_IP)) {
                    // Additional check for reasonable length and no double dots
                    if (strlen($value) <= 253 && strpos($value, '..') === false) {
                        return $value;
                    }
                }
                return $default;

            case 'REQUEST_METHOD':
                $allowed_methods = ['GET', 'POST', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];
                return in_array($value, $allowed_methods, true) ? $value : 'GET';

            case 'HTTP_USER_AGENT':
                return sanitize_text_field($value);

            case 'REMOTE_ADDR':
            case 'HTTP_CF_CONNECTING_IP':
            case 'HTTP_X_FORWARDED_FOR':
                if (filter_var($value, FILTER_VALIDATE_IP)) {
                    return $value;
                }
                return $default;

            case 'HTTPS':
                return $value;

            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Initialize enterprise security features
     */
    public static function init() {
        // Prevent double initialization
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;
        
        // Default options
        $default_options = [
            'force_login_enabled' => false,
            'hide_wp_login' => false,
            'custom_login_slug' => 'secure-login',
            'max_login_attempts' => 5,
            'lockout_duration' => 900,
            'email_notifications_enabled' => true,
            'email_notification_threshold' => 3,
            'email_recipient' => get_option('admin_email'),
            'email_digest_enabled' => false,
            'email_digest_frequency' => 'daily',
            'exempt_roles' => ['administrator'],
            'security_headers' => false,
            'session_timeout' => 3600,
            'two_factor_required' => false,
        ];

        // SIMPLIFIED: Use only one option source
        $stored_options = get_option('wallet_up_login_customizer_security_options', array());
        
        if (!is_array($stored_options)) {
            self::secure_log('Security options corrupted, using defaults');
            $stored_options = $default_options;
        }

        self::$options = wp_parse_args($stored_options, $default_options);
        
        // Log the actual values being used for debugging
        self::secure_log('Security options loaded - force_login: ' . var_export(self::$options['force_login_enabled'], true) . 
                        ', hide_wp_login: ' . var_export(self::$options['hide_wp_login'], true) .
                        ', custom_slug: ' . self::$options['custom_login_slug']);

        // Initialize security hooks
        self::init_hooks();

        if (!empty(self::$options['force_login_enabled'])) {
            self::secure_log('Force login is ENABLED - initializing at priority: ' . current_action());
            self::init_forced_login();
        } else {
            self::secure_log('Force login is DISABLED - option value: ' . var_export(self::$options['force_login_enabled'], true));
        }
        
        // Register cron action for security digest
        add_action('wallet_up_send_security_digest', [__CLASS__, 'send_security_digest']);

        if (self::$options['hide_wp_login']) {
            self::init_login_protection();
        }

        // Add rewrite rules for custom login
        add_action('init', [__CLASS__, 'add_login_rewrite_rules']);
        add_filter('query_vars', [__CLASS__, 'add_login_query_vars']);
        add_action('template_redirect', [__CLASS__, 'handle_custom_login_url'], 5);

        // Initialize security headers
        if (self::$options['security_headers']) {
            self::init_security_headers();
        }
    }

    /**
     * Initialize security hooks
     */
    private static function init_hooks() {
        add_action('login_init', [__CLASS__, 'initialize_login_variables'], 1);
        add_action('login_header', [__CLASS__, 'ensure_variables_initialized'], 1);
        add_action('login_form', [__CLASS__, 'add_security_fields']);
        add_action('login_head', [__CLASS__, 'add_security_fields_to_head']);
        add_filter('authenticate', [__CLASS__, 'validate_login_attempt'], 30, 3);
        add_action('wp_login_failed', [__CLASS__, 'handle_failed_login']);
        add_action('wp_login', [__CLASS__, 'handle_successful_login'], 10, 2);
        add_action('init', [__CLASS__, 'manage_user_sessions']);
        add_action('wp_logout', [__CLASS__, 'cleanup_user_session']);
        add_filter('logout_redirect', [__CLASS__, 'custom_logout_redirect'], 10, 3);
        add_filter('logout_url', [__CLASS__, 'custom_logout_url'], 10, 2);
        add_action('wp_loaded', [__CLASS__, 'monitor_security_events']);
        // Security menu removed - now integrated in main settings page
        // add_action('admin_menu', [__CLASS__, 'add_security_menu']);
        add_action('admin_init', [__CLASS__, 'register_security_settings']);
        
        // Add AJAX handler for test email
        add_action('wp_ajax_wallet_up_test_security_email', [__CLASS__, 'handle_test_email']);
    }

    /**
     * Initialize forced login system
     */
    private static function init_forced_login() {
        if (self::has_login_conflicts()) {
            self::secure_log('Login conflicts detected, disabling forced login');
            return;
        }

        // Cache bypass for force login - comprehensive approach
        // Note: Server-level caching (CloudFlare, Varnish) may still cache
        // This is documented in the UI as a known limitation
        
        // WordPress standard cache constants
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', true);
        }
        if (!defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }
        
        // Popular cache plugin constants
        if (!defined('LSCACHE_NO_CACHE')) {
            define('LSCACHE_NO_CACHE', true); // LiteSpeed
        }
        if (!defined('WPFC_EXCLUDE_CURRENT_PAGE')) {
            define('WPFC_EXCLUDE_CURRENT_PAGE', true); // WP Fastest Cache
        }
        if (!defined('WP_ROCKET_DONOTCACHEPAGE')) {
            define('WP_ROCKET_DONOTCACHEPAGE', true); // WP Rocket
        }
        
        // Disable LiteSpeed cache for entire site when force login is enabled
        add_action('init', function() {
            // LiteSpeed Cache plugin specific
            if (defined('LITESPEED_ON')) {
                do_action('litespeed_control_set_nocache', 'Force login enabled');
            }
        }, 1);
        
        // Hook into 'plugins_loaded' with highest priority (before caching plugins)
        add_action('plugins_loaded', [__CLASS__, 'enforce_authentication'], 1);
        
        // Also add standard hooks as fallback
        add_action('send_headers', [__CLASS__, 'send_no_cache_headers_for_non_logged_in'], 1);
        
        // Use init hook with high priority to catch requests early
        add_action('init', [__CLASS__, 'enforce_authentication'], 1);
        // Also use template_redirect as a fallback
        add_action('template_redirect', [__CLASS__, 'enforce_authentication'], 1);
        add_filter('rest_authentication_errors', [__CLASS__, 'restrict_rest_api'], 10);
        add_action('admin_init', [__CLASS__, 'restrict_admin_access'], 10);
        // Prevent direct file access
        add_action('wp', [__CLASS__, 'enforce_authentication'], 1);
    }

    /**
     * Check for conflicts with other login systems
     */
    private static function has_login_conflicts() {
        $conflicting_functions = [
            'require_login_for_all_pages',
            'force_login_redirect',
            'site_wide_login_required',
        ];

        foreach ($conflicting_functions as $function) {
            if (function_exists($function)) {
                return true;
            }
        }

        $conflicting_plugins = [
            'force-login/force-login.php',
            'login-required/login-required.php',
            'wp-force-login/wp-force-login.php',
        ];

        foreach ($conflicting_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Initialize wp-login.php protection
     */
    private static function init_login_protection() {
        add_action('login_init', [__CLASS__, 'intercept_wp_login'], 10);
    }

    /**
     * Initialize security headers
     */
    private static function init_security_headers() {
        add_action('send_headers', [__CLASS__, 'add_security_headers']);
        add_filter('wp_headers', [__CLASS__, 'modify_wp_headers']);
    }

    /**
     * Check authentication in output buffer
     */
    public static function check_authentication_in_buffer($buffer) {
        if (is_user_logged_in()) {
            return $buffer;
        }

        if (is_admin() || self::is_login_request() || self::is_registration_request() || self::is_password_reset_request()) {
            return $buffer;
        }

        if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
            return $buffer;
        }

        $safe_uri = esc_url_raw($_SERVER['REQUEST_URI'] ?? '');
        self::secure_log('Unauthorized access attempt blocked', ['uri' => $safe_uri]);

        $login_url = self::get_secure_login_url();
        $return_url = self::get_current_url();
        $redirect_url = add_query_arg('redirect_to', urlencode($return_url), $login_url);

        wp_safe_redirect($redirect_url, 302);
        exit;
    }

    /**
     * Send no-cache headers for non-logged-in users
     */
    public static function send_no_cache_headers_for_non_logged_in() {
        // Only send no-cache headers if user is not logged in and force login is enabled
        if (!is_user_logged_in() && !empty(self::$options['force_login_enabled'])) {
            // Check if headers already sent
            if (headers_sent()) {
                return;
            }
            
            // Send aggressive no-cache headers
            header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0, s-maxage=0, private');
            header('Pragma: no-cache');
            header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
            
            // Tell Cloudflare not to cache (using official Cloudflare headers)
            header('Cloudflare-CDN-Cache-Control: no-store, no-cache, private');
            header('CDN-Cache-Control: no-store');
            
            // LiteSpeed specific
            header('X-LiteSpeed-Cache-Control: no-cache');
            
            // Vary on Cookie to prevent caching when auth changes
            header('Vary: Cookie');
            
            // Ensure page is not cached by setting a unique etag
            header('ETag: "' . md5(uniqid('', true)) . '"');
        }
    }
    
    /**
     * Enforce authentication for all site access
     */
    public static function enforce_authentication() {
        // First check if force login is actually enabled
        if (empty(self::$options['force_login_enabled'])) {
            return; // Force login is not enabled
        }
        
        // Prevent infinite redirects
        static $redirect_in_progress = false;
        if ($redirect_in_progress) {
            return;
        }

        // Check if headers already sent (can't redirect)
        if (headers_sent($file, $line)) {
            self::secure_log('Cannot redirect - headers already sent', ['file' => $file, 'line' => $line]);
            return;
        }

        // Check if user is already logged in
        if (is_user_logged_in()) {
            return;
        }

        // Allow access to login-related pages
        if (self::is_login_request() || self::is_registration_request() || self::is_password_reset_request()) {
            return;
        }

        // Allow AJAX, CRON, and CLI requests
        if (wp_doing_ajax() || wp_doing_cron() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        // Allow admin area (it has its own authentication)
        if (is_admin()) {
            return;
        }

        // Allow public REST API endpoints
        if (self::is_public_rest_endpoint()) {
            return;
        }

        // Check if we're on a whitelisted page
        if (self::is_whitelisted_page()) {
            return;
        }

        // If we get here, redirect to login
        $redirect_in_progress = true;
        $login_url = self::get_secure_login_url();
        $return_url = self::get_current_url();
        $redirect_url = add_query_arg('redirect_to', urlencode($return_url), $login_url);

        self::secure_log('Force login redirect initiated for URL: ' . $return_url);
        
        // Use nocache headers
        nocache_headers();
        
        wp_safe_redirect($redirect_url, 302);
        exit;
    }

    /**
     * Check if current request is login-related
     */
    private static function is_login_request() {
        global $pagenow;

        // Check if we're on wp-login.php
        if (isset($pagenow) && $pagenow === 'wp-login.php') {
            return true;
        }

        // Check REQUEST_URI for wp-login.php
        $request_uri = self::get_server_var('REQUEST_URI', '');
        if (strpos($request_uri, 'wp-login.php') !== false) {
            return true;
        }

        // Check for custom login slug
        $custom_slug = self::$options['custom_login_slug'] ?? 'secure-login';
        if (!empty($custom_slug)) {
            // Check GET parameter
            if (isset($_GET[$custom_slug])) {
                return true;
            }

            // Check various URL patterns
            $patterns_to_check = [
                '/' . $custom_slug,
                '/' . $custom_slug . '/',
                '?' . $custom_slug,
                '?' . $custom_slug . '=',
                '&' . $custom_slug,
                '&' . $custom_slug . '=',
            ];

            foreach ($patterns_to_check as $pattern) {
                if (strpos($request_uri, $pattern) !== false) {
                    return true;
                }
            }

            // Check parsed URL path
            $parsed_url = parse_url($request_uri);
            if (isset($parsed_url['path']) && trim($parsed_url['path'], '/') === $custom_slug) {
                return true;
            }
        }

        // Check for AJAX login actions
        if (wp_doing_ajax() && isset($_POST['action'])) {
            $action = sanitize_text_field($_POST['action']);
            $login_actions = ['login', 'heartbeat', 'wallet_up_ajax_login', 'wallet_up_validate_username'];
            if (in_array($action, $login_actions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if current request is registration-related
     */
    private static function is_registration_request() {
        global $pagenow;

        if (isset($_GET['action'])) {
            $action = sanitize_text_field($_GET['action']);
            if ($pagenow === 'wp-login.php' && $action === 'register') {
                return true;
            }
            if ($action === 'register') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if current request is password reset related
     */
    private static function is_password_reset_request() {
        global $pagenow;

        if ($pagenow === 'wp-login.php' && isset($_GET['action'])) {
            $action = sanitize_text_field($_GET['action']);
            if (in_array($action, ['lostpassword', 'resetpass', 'rp'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if current request is public REST API endpoint
     */
    private static function is_public_rest_endpoint() {
        $request_uri = self::get_server_var('REQUEST_URI', '');
        $public_endpoints = [
            '/wp-json/wp/v2/users/register',
            '/wp-json/jwt-auth/v1/token',
        ];

        foreach ($public_endpoints as $endpoint) {
            if (strpos($request_uri, $endpoint) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if current page is whitelisted from force login
     */
    private static function is_whitelisted_page() {
        // Allow favicon and robots.txt
        $request_uri = self::get_server_var('REQUEST_URI', '');
        $whitelisted_files = [
            '/favicon.ico',
            '/robots.txt',
            '/wp-admin/admin-ajax.php',
            '/xmlrpc.php'
        ];
        
        foreach ($whitelisted_files as $file) {
            if (strpos($request_uri, $file) !== false) {
                return true;
            }
        }
        
        // Allow specific actions
        if (isset($_GET['action'])) {
            $action = sanitize_text_field($_GET['action']);
            $allowed_actions = ['postpass', 'logout', 'lostpassword', 'rp', 'resetpass', 'register'];
            if (in_array($action, $allowed_actions, true)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if current endpoint should be publicly accessible
     */
    private static function is_public_endpoint_allowed() {
        $allowed_endpoints = [
            'wp-cron.php',
            'xmlrpc.php',
        ];

        $current_endpoint = basename(self::get_server_var('REQUEST_URI', ''));
        return in_array($current_endpoint, $allowed_endpoints);
    }

    /**
     * Get current URL for redirect purposes
     */
    private static function get_current_url() {
        $https = self::get_server_var('HTTPS', '');
        $protocol = (!empty($https) && $https !== 'off') ? 'https://' : 'http://';
        $host = self::get_server_var('HTTP_HOST', 'localhost');
        $uri = self::get_server_var('REQUEST_URI', '/');

        return $protocol . $host . $uri;
    }

    /**
     * Get secure login URL
     */
    private static function get_secure_login_url() {
        if (self::$options['hide_wp_login'] && !empty(self::$options['custom_login_slug'])) {
            return home_url('/?' . self::$options['custom_login_slug'] . '=1');
        }

        return wp_login_url();
    }

    /**
     * Intercept wp-login.php requests
     */
    public static function intercept_wp_login() {
        if (!self::$options['hide_wp_login']) {
            return;
        }

        global $pagenow;
        if ($pagenow !== 'wp-login.php') {
            return;
        }

        $custom_slug = sanitize_title(self::$options['custom_login_slug']);
        if (is_user_logged_in() || 
            (isset($_GET[$custom_slug]) && $_GET[$custom_slug] === '1') || 
            get_query_var($custom_slug) || 
            isset($_GET['action']) || 
            strpos(self::get_server_var('HTTP_REFERER', ''), '/wp-admin/') !== false || 
            wp_doing_ajax() || 
            self::get_server_var('REQUEST_METHOD') === 'POST') {
            return;
        }

        if (self::get_server_var('REQUEST_METHOD') === 'GET' && empty($_GET) && empty($_POST) && !isset($_REQUEST['action'])) {
            $script_name = self::get_server_var('SCRIPT_NAME', '');
            if (strpos($script_name, 'wp-login.php') !== false) {
                self::log_security_event('blocked_wp_login_access', [
                    'ip' => self::get_client_ip(),
                    'user_agent' => self::get_server_var('HTTP_USER_AGENT', ''),
                    'referer' => self::get_server_var('HTTP_REFERER', ''),
                    'request_method' => self::get_server_var('REQUEST_METHOD', 'GET'),
                    'script_name' => $script_name,
                ]);

                $custom_login_url = home_url('/?' . sanitize_title(self::$options['custom_login_slug']) . '=1');
                wp_safe_redirect($custom_login_url);
                exit;
            }
        }
    }

    /**
     * Add custom login URL rewrite rules
     */
    public static function add_login_rewrite_rules() {
        $login_slug = self::$options['custom_login_slug'];
        // Simplified to a single specific rule to reduce regex overhead
        add_rewrite_rule('^' . $login_slug . '/?$', 'index.php?' . $login_slug . '=1', 'top');
    }

    /**
     * Add login query vars
     */
    public static function add_login_query_vars($vars) {
        $vars[] = self::$options['custom_login_slug'];
        return $vars;
    }

    /**
     * Handle custom login URL
     */
    public static function handle_custom_login_url() {
        $custom_slug = self::$options['custom_login_slug'] ?? 'secure-login';

        $should_show_login = false;
        
        // Check multiple ways the custom login URL might be accessed
        if (isset($_GET[$custom_slug]) && $_GET[$custom_slug] === '1') {
            $should_show_login = true;
        } elseif (get_query_var($custom_slug)) {
            $should_show_login = true;
        } elseif (isset(parse_url(self::get_server_var('REQUEST_URI', ''))['path'])) {
            $path = trim(parse_url(self::get_server_var('REQUEST_URI', ''))['path'], '/');
            $path_parts = explode('/', $path);
            if (end($path_parts) === $custom_slug) {
                $should_show_login = true;
            }
        }

        if ($should_show_login) {
            global $pagenow;
            $pagenow = 'wp-login.php';

            if (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true') {
                add_filter('login_errors', function($errors) {
                    return '';
                }, 1);

                add_action('login_head', function() {
                    echo '<style>
                        #login_error, .login .message.error, .login .error, .login .notice-error, .login div.error, .login p.error {
                            display: none !important;
                            height: 0 !important;
                            margin: 0 !important;
                            padding: 0 !important;
                            border: none !important;
                            overflow: hidden !important;
                        }
                        .login form .error, .login form .message.error {
                            visibility: hidden !important;
                            position: absolute !important;
                            left: -9999px !important;
                        }
                    </style>';
                });
            }

            // Initialize global variables that wp-login.php expects
            // This fixes undefined variable warnings when WP_DEBUG is enabled
            global $user_login, $error, $interim_login, $redirect_to, $action, $rp_key, $rp_cookie;
            
            // Initialize variables if not already set
            if (!isset($user_login)) {
                $user_login = '';
            }
            if (!isset($error)) {
                $error = '';
            }
            if (!isset($interim_login)) {
                $interim_login = isset($_REQUEST['interim-login']);
            }
            if (!isset($redirect_to)) {
                $redirect_to = '';
            }
            if (!isset($action)) {
                $action = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : 'login';
            }
            if (!isset($rp_key)) {
                $rp_key = '';
            }
            if (!isset($rp_cookie)) {
                $rp_cookie = '';
            }
            
            // Set REQUEST_URI to wp-login.php so WordPress knows we're on the login page
            $_SERVER['REQUEST_URI'] = '/wp-login.php';
            if (isset($_GET['action'])) {
                $action = sanitize_text_field(wp_unslash($_GET['action']));
                $_SERVER['REQUEST_URI'] .= '?action=' . $action;
            }
            
            // IMPORTANT: Ensure the login customizer is initialized
            // This is crucial for the custom template to load
            if (class_exists('WalletUpLoginCustomizer')) {
                WalletUpLoginCustomizer::get_instance();
            }
            
            // Fire login_init action to ensure all login hooks are set up
            do_action('login_init');
            
            // Load wp-login.php which will trigger all the proper hooks
            require_once(ABSPATH . 'wp-login.php');
            exit;
        }
    }


    /**
     * Initialize login variables
     */
    public static function initialize_login_variables() {
        global $user_login, $error, $errors, $interim_login, $action, $redirect_to, $requested_redirect_to;

        $user_login = $user_login ?? (isset($_REQUEST['log']) ? sanitize_user(wp_unslash($_REQUEST['log'])) : (isset($_POST['user_login']) ? sanitize_user(wp_unslash($_POST['user_login'])) : (isset($_GET['user_login']) ? sanitize_user(wp_unslash($_GET['user_login'])) : '')));
        $error = $error ?? false;
        $errors = $errors ?? new WP_Error();
        $interim_login = $interim_login ?? isset($_REQUEST['interim-login']);
        $action = $action ?? (isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : 'login');
        $redirect_to = $redirect_to ?? (isset($_REQUEST['redirect_to']) ? esc_url_raw($_REQUEST['redirect_to']) : '');
        $requested_redirect_to = $requested_redirect_to ?? (isset($_REQUEST['redirect_to']) ? esc_url_raw($_REQUEST['redirect_to']) : '');

        $GLOBALS['user_login'] = $user_login;
        $GLOBALS['error'] = $error;
        $GLOBALS['errors'] = $errors;
        $GLOBALS['interim_login'] = $interim_login;
        $GLOBALS['action'] = $action;
        $GLOBALS['redirect_to'] = $redirect_to;
        $GLOBALS['requested_redirect_to'] = $requested_redirect_to;

        self::set_global_var('user_login', $user_login);
        self::set_global_var('error', $error);
        self::set_global_var('errors', $errors);
    }

    /**
     * Helper to set global variables safely
     */
    private static function set_global_var($name, $value) {
        global ${$name};
        ${$name} = $value;
    }

    /**
     * Ensure variables are initialized
     */
    public static function ensure_variables_initialized() {
        global $user_login, $error, $errors;

        $GLOBALS['user_login'] = $GLOBALS['user_login'] ?? '';
        $GLOBALS['error'] = $GLOBALS['error'] ?? false;
        $GLOBALS['errors'] = $GLOBALS['errors'] ?? new WP_Error();

        $user_login = $GLOBALS['user_login'];
        $error = $GLOBALS['error'];
        $errors = $GLOBALS['errors'];
    }

    /**
     * Add security fields via head with nonce refresh
     */
    public static function add_security_fields_to_head() {
        global $user_login, $error, $errors;

        $user_login = $user_login ?? (isset($_POST['log']) ? sanitize_text_field(wp_unslash($_POST['log'])) : '');
        $error = $error ?? (isset($errors) && is_wp_error($errors) && $errors->has_errors());
        $errors = $errors ?? new WP_Error();

        $nonce = wp_create_nonce('wallet_up_login_customizer_security');
        echo '<meta name="wallet-up-nonce" content="' . esc_attr($nonce) . '">';
        echo '<meta name="wallet-up-timestamp" content="' . time() . '">';
        echo '<meta name="wallet-up-fingerprint" content="' . self::generate_client_fingerprint() . '">';
        ?>
        <style type="text/css">
        /* Hide honeypot field */
        input[name="wallet_up_honeypot"],
        div[aria-hidden="true"] input[name="wallet_up_honeypot"] {
            position: absolute !important;
            left: -9999px !important;
            top: -9999px !important;
            height: 0 !important;
            width: 0 !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }
        </style>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var nonceField = document.querySelector('input[name="wallet_up_security_nonce"]');
            if (nonceField) {
                nonceField.value = '<?php echo esc_js($nonce); ?>'; // Refresh nonce
            }
        });
        </script>
        <?php
    }

    /**
     * Add security fields to login form
     */
    public static function add_security_fields() {
        // Create nonces with the correct action names
        $ajax_nonce = wp_create_nonce('wallet-up-login-customizer-nonce'); // For AJAX
        $form_nonce = wp_create_nonce('wallet_up_login_customizer_security'); // For standard form
        
        // Add field for AJAX - JS looks for #wallet-up-login-customizer-nonce
        echo '<input type="hidden" id="wallet-up-login-customizer-nonce" name="security" value="' . esc_attr($ajax_nonce) . '" />';
        
        // Also add the field with our expected name for non-AJAX submissions
        echo '<input type="hidden" name="wallet_up_security_nonce" value="' . esc_attr($form_nonce) . '" />';
        
        // Add timestamp for bot detection
        echo '<input type="hidden" name="wallet_up_timestamp" value="' . time() . '" />';
        
        // Add honeypot field (invisible to users)
        echo '<div style="position: absolute !important; left: -9999px !important; top: -9999px !important; visibility: hidden !important; height: 0 !important; width: 0 !important; overflow: hidden !important;" aria-hidden="true">';
        echo '<input type="text" name="wallet_up_honeypot" value="" tabindex="-1" autocomplete="off" style="position: absolute !important; left: -9999px !important;" />';
        echo '</div>';
        
        // Add client fingerprint if possible
        $fingerprint = self::generate_client_fingerprint();
        echo '<input type="hidden" name="wallet_up_fingerprint" value="' . esc_attr($fingerprint) . '" />';
    }

    /**
     * Validate login attempt
     */
    public static function validate_login_attempt($user, $username, $password) {
        if (is_wp_error($user)) {
            return $user;
        }

        // Truncate username to match WordPress's limit (60 characters)
        $username = substr(sanitize_user($username, true), 0, 60);

        // Check if we should validate nonces
        $should_validate_nonce = false;
        $nonce_valid = false;
        
        if (wp_doing_ajax()) {
            // For AJAX requests, always validate nonce
            $should_validate_nonce = true;
            // AJAX sends 'security' field with the nonce name 'wallet-up-login-customizer-nonce'
            if (isset($_POST['security']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'])), 'wallet-up-login-customizer-nonce')) {
                $nonce_valid = true;
            }
        } else {
            // For standard login, only validate if the nonce field was added
            if (isset($_POST['wallet_up_security_nonce'])) {
                $should_validate_nonce = true;
                $nonce_valid = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wallet_up_security_nonce'])), 'wallet_up_login_customizer_security');
            }
            // If no nonce field exists, skip validation (standard WP login compatibility)
        }

        // Only return error if validation was required and failed
        if ($should_validate_nonce && !$nonce_valid) {
            self::log_security_event('invalid_nonce', ['username_hash' => substr(md5($username), 0, 8)]);
            return new WP_Error('invalid_security_token', __('Security validation failed. Please refresh the page and try again.', 'wallet-up-login-customizer'));
        }

        if (!empty($_POST['wallet_up_honeypot'])) {
            $honeypot_value = sanitize_text_field($_POST['wallet_up_honeypot']);
            $pm_patterns = ['on', '1', 'true', $username, $password];

            if (!in_array($honeypot_value, $pm_patterns, true)) {
                if (isset($_POST['wallet_up_timestamp']) && (time() - intval(sanitize_text_field($_POST['wallet_up_timestamp'])) < 2)) {
                    self::log_security_event('honeypot_triggered', [
                        'ip' => self::get_client_ip(),
                        'username_hash' => substr(md5($username), 0, 8),
                        'time_taken' => time() - intval(sanitize_text_field($_POST['wallet_up_timestamp'])),
                    ]);
                    return new WP_Error('bot_detected', __('Automated login attempts are not allowed.', 'wallet-up-login-customizer'));
                }

                self::log_security_event('honeypot_filled_allowed', [
                    'ip' => self::get_client_ip(),
                    'username_hash' => substr(md5($username), 0, 8),
                    'reason' => 'Timing suggests human user',
                ]);
            }
        }

        // Use generic error message to prevent username enumeration
        $generic_error = __('Login failed. Please check your credentials and try again.', 'wallet-up-login-customizer');
        
        if (self::is_rate_limited()) {
            // Still rate limit but use generic message
            return new WP_Error('authentication_failed', $generic_error);
        }

        if (self::is_brute_force_attempt($username)) {
            // Still lock account but use generic message
            return new WP_Error('authentication_failed', $generic_error);
        }

        return $user;
    }

    /**
     * Handle failed login attempts
     */
    public static function handle_failed_login($username) {
        // Truncate username to match WordPress's limit (60 characters)
        $username = substr(sanitize_user($username, true), 0, 60);

        $ip = self::get_client_ip();
        $key = 'failed_login_' . md5($ip . $username);

        $attempts = get_transient($key) ?: [];
        $attempts[] = time();

        $recent_attempts = array_filter($attempts, function($timestamp) {
            return ($timestamp > (time() - self::$options['lockout_duration']));
        });

        set_transient($key, $recent_attempts, self::$options['lockout_duration']);
        self::track_rate_limit_attempt();

        // Hash username for logs to prevent exposure
        $username_hash = substr(md5($username), 0, 8);
        
        self::log_security_event('failed_login', [
            'username_hash' => $username_hash,
            'username_prefix' => substr($username, 0, 2) . '***',
            'ip' => $ip,
            'attempts' => count($recent_attempts),
        ]);

        // Check if we should send an alert based on threshold
        $threshold = self::$options['email_notification_threshold'] ?? 3;
        if (count($recent_attempts) >= $threshold && count($recent_attempts) <= $threshold + 1) {
            // Send alert only once when threshold is reached
            self::send_security_alert('Failed login attempts threshold reached', [
                'username' => $username,
                'ip' => $ip,
                'attempts' => count($recent_attempts),
                'threshold' => $threshold,
                'max_attempts' => self::$options['max_login_attempts'],
            ]);
        }
        
        // Also send alert when max attempts reached (account locked)
        if (count($recent_attempts) >= self::$options['max_login_attempts']) {
            self::send_security_alert('Account locked due to multiple failed attempts', [
                'username' => $username,
                'ip' => $ip,
                'attempts' => count($recent_attempts),
                'lockout_duration' => self::$options['lockout_duration'] . ' seconds',
            ]);
        }
    }

    /**
     * Handle successful login
     */
    public static function handle_successful_login($user_login, $user) {
        $ip = self::get_client_ip();
        $key = 'failed_login_' . md5($ip . $user_login);
        delete_transient($key);

        self::log_security_event('successful_login', [
            'username_hash' => substr(md5($user_login), 0, 8),
            'ip' => $ip,
            'user_id' => $user->ID,
        ]);

        // Set session timeout via WordPress auth cookie
        add_filter('auth_cookie_expiration', function() {
            return self::$options['session_timeout'];
        });
    }

    /**
     * Track rate limit attempt with in-memory cache
     */
    private static function track_rate_limit_attempt() {
        $ip = self::get_client_ip();
        $key = 'rate_limit_' . md5($ip);

        // Get attempts directly from transient (no in-memory cache)
        $attempts = get_transient($key) ?: [];
        
        // Add current attempt
        $attempts[] = time();
        
        // Filter to keep only recent attempts (last 5 minutes)
        $recent_attempts = array_filter($attempts, function($timestamp) {
            return ($timestamp > (time() - 300));
        });

        // Save directly to transient
        set_transient($key, $recent_attempts, 300);

        // Global rate limit to prevent DoS
        static $global_attempts = [];
        static $global_last_reset = 0;
        $global_attempts[] = time();
        $recent_global_attempts = array_filter($global_attempts, function($timestamp) {
            return ($timestamp > (time() - 300));
        });

        // Reset global attempts every 5 minutes
        if (time() - $global_last_reset > 300) {
            $global_attempts = $recent_global_attempts;
            $global_last_reset = time();
        } else {
            $global_attempts = $recent_global_attempts;
        }

        if (count($global_attempts) > 500) { // Increased to 500 for larger sites
            self::log_security_event('global_rate_limit_exceeded', ['ip' => $ip]);
        }
    }

    /**
     * Check if IP is rate limited
     */
    private static function is_rate_limited() {
        $ip = self::get_client_ip();
        $key = 'rate_limit_' . md5($ip);

        // Get attempts directly from transient (no in-memory cache)
        $attempts = get_transient($key) ?: [];

        // Use configurable lockout duration instead of hardcoded 300 seconds
        $lockout_duration = self::$options['lockout_duration'] ?? 900;
        $recent_attempts = array_filter($attempts, function($timestamp) use ($lockout_duration) {
            return ($timestamp > (time() - $lockout_duration));
        });

        // Use configurable max attempts instead of hardcoded 10
        // Double the max_login_attempts for IP-based limiting (less strict than per-user)
        $max_attempts = (self::$options['max_login_attempts'] ?? 5) * 2;
        return count($recent_attempts) >= $max_attempts;
    }

    /**
     * Check for brute force attempts
     */
    private static function is_brute_force_attempt($username) {
        // Truncate username to match WordPress's limit (60 characters)
        $username = substr(sanitize_user($username, true), 0, 60);

        $ip = self::get_client_ip();
        $key = 'failed_login_' . md5($ip . $username);

        $attempts = get_transient($key) ?: [];
        return count($attempts) >= self::$options['max_login_attempts'];
    }

    /**
     * Send no-cache headers when force login is enabled
     */
    public static function send_no_cache_headers() {
        if (!is_user_logged_in() && !headers_sent()) {
            header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }
    
    /**
     * Disable WP Rocket cache for all pages when force login is enabled
     * 
     * @param array $uris Array of URIs to exclude from cache
     * @return array Modified array of URIs
     */
    public static function disable_rocket_cache($uris) {
        if (!is_array($uris)) {
            $uris = array();
        }
        // Exclude all pages from cache when force login is enabled
        $uris[] = '.*';
        return $uris;
    }
    
    /**
     * Disable LiteSpeed cache when force login is enabled
     * 
     * @param array $excludes Array of patterns to exclude from cache
     * @return array Modified array of excludes
     */
    public static function disable_litespeed_cache($excludes) {
        if (!is_array($excludes)) {
            $excludes = array();
        }
        // Exclude all pages from cache when force login is enabled
        $excludes[] = '.*';
        return $excludes;
    }
    
    /**
     * Add security headers with intelligent context awareness
     */
    public static function add_security_headers() {
        if (headers_sent()) {
            return;
        }

        // Determine the context for appropriate header strictness
        $context = self::determine_security_context();
        
        // Apply headers based on context
        switch ($context) {
            case 'login':
                // Minimal headers for login pages
                self::apply_minimal_headers();
                break;
                
            case 'admin':
                // Moderate headers for admin area
                self::apply_admin_headers();
                break;
                
            case 'embed':
                // Special headers for embeddable content
                self::apply_embed_headers();
                break;
                
            case 'api':
                // Headers for API endpoints
                self::apply_api_headers();
                break;
                
            case 'public':
            default:
                // Strict headers for public pages
                self::apply_public_headers();
                break;
        }
    }
    
    /**
     * Determine the security context of the current request
     */
    private static function determine_security_context() {
        global $pagenow;
        $request_uri = self::get_server_var('REQUEST_URI', '');
        
        // Check for login pages
        if (self::is_login_context()) {
            return 'login';
        }
        
        // Check for admin pages
        if (is_admin() || strpos($request_uri, '/wp-admin') !== false) {
            return 'admin';
        }
        
        // Check for REST API
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'api';
        }
        
        // Check for embeddable content (iframes, etc.)
        if (isset($_GET['embed']) && sanitize_text_field($_GET['embed']) === '1') {
            return 'embed';
        }
        
        if (strpos($request_uri, '/embed/') !== false) {
            return 'embed';
        }
        
        // Check for specific page types that might need relaxed headers
        if (self::needs_relaxed_headers()) {
            return 'embed';
        }
        
        return 'public';
    }
    
    /**
     * Check if current context is login-related
     */
    private static function is_login_context() {
        global $pagenow;
        
        // Check standard login page
        if (isset($pagenow) && $pagenow === 'wp-login.php') {
            return true;
        }
        
        // Check REQUEST_URI using secure method
        $request_uri = self::get_server_var('REQUEST_URI', '');
        if (strpos($request_uri, 'wp-login.php') !== false || 
            strpos($request_uri, 'wp-register.php') !== false) {
            return true;
        }
        
        // Check for custom login slug - sanitize it first
        $custom_slug = isset(self::$options['custom_login_slug']) ? 
                      sanitize_title(self::$options['custom_login_slug']) : 
                      'secure-login';
        
        // Check GET parameter with proper sanitization
        if (isset($_GET[$custom_slug]) && $_GET[$custom_slug] === '1') {
            return true;
        }
        
        // Check in URI
        if (strpos($request_uri, $custom_slug) !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if current page needs relaxed headers
     */
    private static function needs_relaxed_headers() {
        // Check for known problematic pages or plugins
        $relaxed_pages = apply_filters('wallet_up_relaxed_header_pages', [
            'elementor',
            'beaver-builder',
            'divi',
            'wpbakery',
            'checkout',
            'cart',
            'my-account'
        ]);
        
        // Sanitize the relaxed pages array
        $relaxed_pages = array_map('sanitize_text_field', $relaxed_pages);
        
        $request_uri = self::get_server_var('REQUEST_URI', '');
        foreach ($relaxed_pages as $page) {
            if (!empty($page) && strpos($request_uri, $page) !== false) {
                return true;
            }
        }
        
        // Check if page has shortcodes that might need relaxed CSP
        if (is_singular()) {
            $post_id = get_the_ID();
            if ($post_id) {
                $post_content = get_post_field('post_content', $post_id);
                if (!is_wp_error($post_content) && has_shortcode($post_content, 'embed')) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Apply minimal security headers (login pages)
     */
    private static function apply_minimal_headers() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        // No CSP to avoid breaking login functionality
    }
    
    /**
     * Apply admin-appropriate headers
     */
    private static function apply_admin_headers() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Strict CSP for admin - WordPress admin requires nonces for inline scripts
        $nonce = esc_attr(wp_create_nonce('wallet-up-csp'));
        $csp = array(
            "default-src 'self'",
            "script-src 'self' 'nonce-" . $nonce . "'",
            "style-src 'self' 'nonce-" . $nonce . "'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "frame-ancestors 'self'"
        );
        header('Content-Security-Policy: ' . implode('; ', $csp));
    }
    
    /**
     * Apply headers for embeddable content
     */
    private static function apply_embed_headers() {
        header('X-Content-Type-Options: nosniff');
        // Allow framing from same origin and trusted sites
        $allowed_frame_ancestors = apply_filters('wallet_up_allowed_frame_ancestors', "'self'");
        
        // Validate frame ancestors to prevent header injection
        $valid_ancestors = [];
        $parts = explode(' ', $allowed_frame_ancestors);
        foreach ($parts as $part) {
            $part = trim($part);
            // Allow 'self', 'none', or valid URLs
            if ($part === "'self'" || $part === "'none'" || 
                filter_var($part, FILTER_VALIDATE_URL) || 
                preg_match('/^https?:\/\/[a-z0-9\-\.]+$/i', $part)) {
                $valid_ancestors[] = $part;
            }
        }
        $safe_ancestors = implode(' ', $valid_ancestors);
        
        if (empty($safe_ancestors)) {
            $safe_ancestors = "'self'";
        }
        
        header("Content-Security-Policy: frame-ancestors " . $safe_ancestors);
    }
    
    /**
     * Apply headers for API endpoints
     */
    private static function apply_api_headers() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');
    }
    
    /**
     * Apply strict headers for public pages
     */
    private static function apply_public_headers() {
        // Basic security headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Only add HSTS on HTTPS
        if (is_ssl()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        
        // Strict CSP with nonce support for better security
        $nonce = esc_attr(wp_create_nonce('wallet-up-csp'));
        
        // For WordPress admin, we need to be more permissive due to core requirements
        $is_admin = is_admin();
        
        if ($is_admin) {
            // WordPress admin needs unsafe-inline for compatibility with core functionality
            $csp = array(
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com", // WordPress admin needs unsafe-inline
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com", // WordPress admin needs unsafe-inline for styles
                "worker-src 'self' blob:", // For WordPress modules
                "script-src-elem 'self' 'unsafe-inline'" // For module loading
            );
        } else {
            // Stricter CSP for frontend
            $csp = array(
                "default-src 'self'",
                "script-src 'self' 'nonce-" . $nonce . "' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com", // Allow specific CDNs only
                "style-src 'self' 'nonce-" . $nonce . "' https://fonts.googleapis.com" // Allow specific style sources
            );
        }
        
        // Common CSP directives for both admin and frontend
        $common_csp = array(
            "img-src 'self' data: https:", // Allow images from HTTPS
            "font-src 'self' data: https://fonts.gstatic.com", // Allow Google fonts
            "connect-src 'self' https:", // Allow AJAX to self and HTTPS
            "media-src 'self' https:", // Allow media
            "frame-src 'self' https://www.youtube.com https://player.vimeo.com", // Allow specific iframes
            "frame-ancestors 'self'", // Prevent clickjacking
            "base-uri 'self'",
            "form-action 'self'", // Only allow forms to same origin
            "upgrade-insecure-requests" // Upgrade HTTP to HTTPS
        );
        
        // Merge CSP arrays
        $csp = array_merge($csp, $common_csp);
        
        // Allow customization via filter
        $csp = apply_filters('wallet_up_csp_directives', $csp);
        
        // Sanitize CSP directives to prevent header injection
        $safe_csp = [];
        if (is_array($csp)) {
            foreach ($csp as $directive) {
                // Remove newlines and control characters
                $directive = preg_replace('/[\r\n\t]/', ' ', $directive);
                $directive = trim($directive);
                if (!empty($directive) && strlen($directive) < 1000) {
                    $safe_csp[] = $directive;
                }
            }
        }
        
        if (!empty($safe_csp)) {
            header('Content-Security-Policy: ' . implode('; ', $safe_csp));
        }
    }

    /**
     * Modify WordPress headers
     */
    public static function modify_wp_headers($headers) {
        unset($headers['X-Pingback']);
        return $headers;
    }

    /**
     * Get client IP address
     */
    private static function get_client_ip() {
        $ip = '127.0.0.1'; // Default IP
        
        if (defined('WALLET_UP_TRUSTED_PROXY') && WALLET_UP_TRUSTED_PROXY === 'cloudflare') {
            $cf_ip = self::get_server_var('HTTP_CF_CONNECTING_IP', '');
            $remote_addr = self::get_server_var('REMOTE_ADDR', '');
            if ($cf_ip && self::is_cloudflare_ip($remote_addr)) {
                $ip = $cf_ip;
            }
        } else {
            $ip = self::get_server_var('REMOTE_ADDR', '127.0.0.1');
        }

        // Validate and sanitize IP address
        $validated_ip = filter_var($ip, FILTER_VALIDATE_IP);
        return $validated_ip ? sanitize_text_field($validated_ip) : '127.0.0.1';
    }

    /**
     * Check if IP is from Cloudflare
     */
    private static function is_cloudflare_ip($ip) {
        $cloudflare_ips = [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
        ];

        foreach ($cloudflare_ips as $range) {
            if (self::ip_in_range($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is in CIDR range
     */
    private static function ip_in_range($ip, $range) {
        list($subnet, $bits) = explode('/', $range);
        if ($bits === null) {
            $bits = 32;
        }
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet_long &= $mask;
        return ($ip_long & $mask) == $subnet_long;
    }

    /**
     * Generate client fingerprint
     */
    private static function generate_client_fingerprint() {
        $data = [
            sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            sanitize_text_field($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
            sanitize_text_field($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''),
            self::get_client_ip(),
        ];

        return hash('sha256', implode('|', $data));
    }

    /**
     * Log security events
     */
    private static function log_security_event($event, $data = []) {
        // Rate limit logging to prevent DoS
        static $event_count = [];
        static $event_last_reset = [];
        $ip = self::get_client_ip();
        $key = md5($ip . $event);
        $event_count[$key] = isset($event_count[$key]) ? $event_count[$key] + 1 : 1;

        if ($event_count[$key] > 10) { // Max 10 logs per event per IP per minute
            return;
        }

        // Reset event count every minute
        if (!isset($event_last_reset[$key]) || (time() - $event_last_reset[$key]) > 60) {
            $event_count[$key] = 1;
            $event_last_reset[$key] = time();
        }

        // Truncate data fields to prevent oversized logs
        $safe_data = [];
        foreach ($data as $k => $v) {
            if (is_string($v) || is_numeric($v)) {
                $safe_data[$k] = substr(sanitize_text_field($v), 0, 100);
            } elseif (is_bool($v)) {
                $safe_data[$k] = $v ? 'true' : 'false';
            } else {
                $safe_data[$k] = '[REDACTED]';
            }
        }

        $log_entry = [
            'timestamp' => current_time('mysql'),
            'event' => substr(sanitize_text_field($event), 0, 50),
            'data' => $safe_data,
            'ip' => $ip,
            'user_agent' => substr(self::get_server_var('HTTP_USER_AGENT', ''), 0, 200),
        ];

        $logs = get_option('wallet_up_security_logs', []);
        $logs[] = $log_entry;

        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }

        update_option('wallet_up_security_logs', $logs, false); // No autoload

        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::secure_log($event, $data);
        }
    }

    /**
     * Send security alerts with rate limiting and configurable settings
     */
    private static function send_security_alert($subject, $data) {
        // Check if email notifications are enabled
        if (empty(self::$options['email_notifications_enabled'])) {
            return; // Email notifications disabled
        }
        
        // Check if digest mode is enabled
        if (!empty(self::$options['email_digest_enabled'])) {
            self::add_to_digest($subject, $data);
            return; // Added to digest, don't send individual email
        }
        
        // Apply rate limiting filter
        $should_send = apply_filters('wallet_up_should_send_security_alert', true, $subject, $data);
        
        if (!$should_send) {
            return; // Rate limited
        }
        
        // Use configured recipient or fall back to admin email
        $recipient_email = self::$options['email_recipient'] ?? get_option('admin_email');
        $site_name = html_entity_decode(get_option('blogname'), ENT_QUOTES, 'UTF-8');

        $message = "Security Alert for $site_name\n\n";
        $message .= "Event: $subject\n";
        $message .= "Time: " . current_time('mysql') . "\n";
        $message .= "Server: " . (isset($_SERVER['SERVER_NAME']) ? sanitize_text_field($_SERVER['SERVER_NAME']) : 'Unknown') . "\n\n";
        
        $safe_data = [];
        foreach ($data as $key => $value) {
            // Obfuscate username in emails
            if ($key === 'username' && is_string($value)) {
                $safe_data[$key] = substr($value, 0, 2) . str_repeat('*', strlen($value) - 2);
            } else {
                $safe_data[$key] = is_string($value) || is_numeric($value) ? substr(sanitize_text_field($value), 0, 100) : '[REDACTED]';
            }
        }
        $message .= "Details:\n" . str_replace(['{"', '","', '"}'], ["\n", "\n", "\n"], wp_json_encode($safe_data));
        $message .= "\n\n---\nThis is an automated security notification from Wallet Up Login Customizer.\n";
        $message .= "To modify these settings, visit: " . admin_url('options-general.php?page=wallet-up-login-customizer#security-settings') . "\n";

        // Add headers for better email delivery
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'X-Priority: 1',
            'X-Mailer: Wallet Up Security System'
        );

        // Ensure subject is plain text without HTML entities
        $email_subject = '[Security Alert] ' . $site_name . ' - ' . html_entity_decode($subject, ENT_QUOTES, 'UTF-8');
        wp_mail($recipient_email, $email_subject, $message, $headers);
    }

    /**
     * Add event to digest for later sending
     */
    private static function add_to_digest($subject, $data) {
        $digest_key = 'wallet_up_security_digest';
        $digest = get_transient($digest_key) ?: [];
        
        $digest[] = [
            'time' => current_time('mysql'),
            'subject' => $subject,
            'data' => $data
        ];
        
        // Keep digest for 24 hours
        set_transient($digest_key, $digest, DAY_IN_SECONDS);
        
        // Schedule digest sending if not already scheduled
        if (!wp_next_scheduled('wallet_up_send_security_digest')) {
            $frequency = self::$options['email_digest_frequency'] ?? 'daily';
            $schedule = ($frequency === 'weekly') ? 'weekly' : 'daily';
            wp_schedule_event(time() + DAY_IN_SECONDS, $schedule, 'wallet_up_send_security_digest');
        }
    }
    
    /**
     * Send security digest email
     */
    public static function send_security_digest() {
        $digest_key = 'wallet_up_security_digest';
        $digest = get_transient($digest_key);
        
        if (empty($digest) || !is_array($digest)) {
            return; // No events to send
        }
        
        $recipient_email = self::$options['email_recipient'] ?? get_option('admin_email');
        $site_name = html_entity_decode(get_option('blogname'), ENT_QUOTES, 'UTF-8');
        
        $message = "Security Digest for $site_name\n";
        $message .= "Period: " . date('Y-m-d H:i:s', strtotime('-1 day')) . " to " . current_time('mysql') . "\n";
        $message .= "Total Events: " . count($digest) . "\n\n";
        $message .= str_repeat('=', 50) . "\n\n";
        
        // Group events by type
        $grouped = [];
        foreach ($digest as $event) {
            $grouped[$event['subject']][] = $event;
        }
        
        foreach ($grouped as $subject => $events) {
            $message .= "Event Type: $subject\n";
            $message .= "Occurrences: " . count($events) . "\n\n";
            
            foreach ($events as $event) {
                $message .= "  - Time: " . $event['time'] . "\n";
                if (!empty($event['data']['ip'])) {
                    $message .= "    IP: " . $event['data']['ip'] . "\n";
                }
                if (!empty($event['data']['username'])) {
                    $username = $event['data']['username'];
                    $message .= "    User: " . substr($username, 0, 2) . str_repeat('*', strlen($username) - 2) . "\n";
                }
                $message .= "\n";
            }
            $message .= str_repeat('-', 30) . "\n\n";
        }
        
        $message .= "\n---\nThis is an automated security digest from Wallet Up Login Customizer.\n";
        $message .= "To modify these settings, visit: " . admin_url('options-general.php?page=wallet-up-login-customizer#security-settings') . "\n";
        
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: Wallet Up Security System'
        );
        
        // Ensure subject is plain text without HTML entities
        $email_subject = '[Security Digest] ' . $site_name . ' - ' . date('Y-m-d');
        wp_mail($recipient_email, $email_subject, $message, $headers);
        
        // Clear the digest after sending
        delete_transient($digest_key);
    }
    
    /**
     * Manage user sessions
     */
    public static function manage_user_sessions() {
        // Rely on WordPress auth cookies, no custom session management
    }

    /**
     * Cleanup user session
     */
    public static function cleanup_user_session() {
        // Handled by WordPress auth cookies
    }

    /**
     * Custom logout redirect
     */
    public static function custom_logout_redirect($redirect_to, $requested_redirect_to, $user) {
        if (!empty(self::$options['hide_wp_login'])) {
            $custom_login_url = home_url('/?' . self::$options['custom_login_slug'] . '=1');
            $redirect_url = add_query_arg([
                'loggedout' => 'true',
                'wp-lang' => get_locale(),
                'redirect_to' => urlencode(home_url()),
            ], $custom_login_url);

            self::secure_log('Redirecting logout to custom URL', ['url' => $redirect_url]);
            return $redirect_url;
        }

        $logout_redirect = add_query_arg([
            'loggedout' => 'true',
            'wp-lang' => get_locale(),
        ], wp_login_url());

        self::secure_log('Adding logout success to wp-login.php', ['url' => $logout_redirect]);
        return $logout_redirect;
    }

    /**
     * Custom logout URL
     */
    public static function custom_logout_url($logout_url, $redirect) {
        if (!empty(self::$options['hide_wp_login'])) {
            $custom_login_url = add_query_arg([
                self::$options['custom_login_slug'] => '1',
                'loggedout' => 'true',
                'redirect_to' => urlencode(home_url()),
            ], home_url('/'));

            if (strpos($logout_url, 'redirect_to=') !== false) {
                $new_logout_url = preg_replace('/redirect_to=[^&]*/', 'redirect_to=' . urlencode($custom_login_url), $logout_url);
            } else {
                $separator = strpos($logout_url, '?') !== false ? '&' : '?';
                $new_logout_url = $logout_url . $separator . 'redirect_to=' . urlencode($custom_login_url);
            }

            self::secure_log('Modified logout URL', ['new_url' => $new_logout_url]);
            return $new_logout_url;
        }

        $logout_success_url = add_query_arg([
            'loggedout' => 'true',
            'wp-lang' => get_locale(),
        ], wp_login_url());

        if (strpos($logout_url, 'redirect_to=') !== false) {
            $new_logout_url = preg_replace('/redirect_to=[^&]*/', 'redirect_to=' . urlencode($logout_success_url), $logout_url);
        } else {
            $separator = strpos($logout_url, '?') !== false ? '&' : '?';
            $new_logout_url = $logout_url . $separator . 'redirect_to=' . urlencode($logout_success_url);
        }

        self::secure_log('Added logout success to standard logout URL', ['new_url' => $new_logout_url]);
        return $new_logout_url;
    }

    /**
     * Monitor security events
     */
    public static function monitor_security_events() {
        $suspicious_patterns = [
            '/wp-config.php',
            '/wp-admin/install.php',
            '/wp-admin/upgrade.php',
            '/.env',
            '/phpinfo.php',
        ];

        $request_uri = self::get_server_var('REQUEST_URI', '');
        foreach ($suspicious_patterns as $pattern) {
            if (strpos($request_uri, $pattern) !== false) {
                self::log_security_event('suspicious_request', [
                    'pattern' => $pattern,
                    'uri' => $request_uri,
                    'ip' => self::get_client_ip(),
                ]);

                status_header(404);
                exit;
            }
        }
    }

    /**
     * Restrict REST API access
     */
    public static function restrict_rest_api($result) {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_not_logged_in', __('You must be logged in to access the REST API.', 'wallet-up-login-customizer'), ['status' => 401]);
        }

        return $result;
    }

    /**
     * Restrict admin access
     */
    public static function restrict_admin_access() {
        if (!is_user_logged_in() && !wp_doing_ajax()) {
            wp_safe_redirect(self::get_secure_login_url());
            exit;
        }
    }

    /**
     * Add security menu - DEPRECATED
     * Security settings are now integrated into the main settings page Security tab
     */
    public static function add_security_menu() {
        // This function is deprecated and no longer used
        // Security settings are now in options-general.php?page=wallet-up-login-customizer#security-settings
        return;
    }

    /**
     * Register security settings
     * Security settings are now part of main plugin options but we keep this for backwards compatibility
     */
    public static function register_security_settings() {
        // Register the legacy option for backwards compatibility
        register_setting('wallet_up_login_customizer_security_options', 'wallet_up_login_customizer_security_options', [
            'sanitize_callback' => [__CLASS__, 'sanitize_security_options'],
        ]);
        
        // Security settings are now handled within the main plugin options
        // The main plugin's sanitization will handle these fields
    }

    /**
     * Sanitize security options
     */
    public static function sanitize_security_options($options) {
        // Handle null or non-array input
        if (!is_array($options)) {
            $options = [];
        }
        
        $clean_options = [];

        $clean_options['force_login_enabled'] = !empty($options['force_login_enabled']);
        $clean_options['hide_wp_login'] = !empty($options['hide_wp_login']);

        $custom_slug = isset($options['custom_login_slug']) ? strtolower(trim($options['custom_login_slug'])) : 'secure-login';
        $custom_slug = sanitize_title($custom_slug);

        if (!preg_match('/^[a-z0-9\-]+$/', $custom_slug) || 
            strlen($custom_slug) < 3 || 
            strlen($custom_slug) > 30 ||
            strpos($custom_slug, '--') !== false ||
            strpos($custom_slug, '..') !== false ||
            substr($custom_slug, 0, 1) === '-' ||
            substr($custom_slug, -1) === '-' ||
            preg_match('/[^a-z0-9\-]/', $custom_slug)) {
            $custom_slug = 'secure-login';
        }

        $reserved_terms = ['wp-admin', 'wp-login', 'admin', 'login', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'rss', 'sitemap', 'robots', 'xmlrpc', 'trackback'];
        if (in_array($custom_slug, $reserved_terms)) {
            $custom_slug = 'secure-login';
        }

        if ($clean_options['hide_wp_login'] && empty($custom_slug)) {
            $clean_options['hide_wp_login'] = false;
            $custom_slug = 'secure-login';
        }

        $clean_options['custom_login_slug'] = $custom_slug;
        $clean_options['max_login_attempts'] = max(3, min(10, absint($options['max_login_attempts'] ?? 5)));
        $clean_options['lockout_duration'] = max(300, min(3600, absint($options['lockout_duration'] ?? 900)));
        
        // Email notification settings
        $clean_options['email_notifications_enabled'] = !empty($options['email_notifications_enabled']);
        $clean_options['email_notification_threshold'] = max(1, min(10, absint($options['email_notification_threshold'] ?? 3)));
        $clean_options['email_recipient'] = sanitize_email($options['email_recipient'] ?? get_option('admin_email'));
        if (empty($clean_options['email_recipient'])) {
            $clean_options['email_recipient'] = get_option('admin_email');
        }
        $clean_options['email_digest_enabled'] = !empty($options['email_digest_enabled']);
        $clean_options['email_digest_frequency'] = (isset($options['email_digest_frequency']) && in_array($options['email_digest_frequency'], ['daily', 'weekly'])) ? 
                                                   $options['email_digest_frequency'] : 'daily';
        
        $clean_options['session_timeout'] = max(900, min(7200, absint($options['session_timeout'] ?? 3600)));
        $clean_options['security_headers'] = !empty($options['security_headers']);
        $clean_options['exempt_roles'] = ['administrator'];

        return $clean_options;
    }

    /**
     * Security settings page
     */
    public static function security_settings_page() {
        $options = self::$options;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wallet Up Enterprise Security', 'wallet-up-login-customizer'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('wallet_up_login_customizer_security_options'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="force_login_enabled"><?php esc_html_e('Force Login', 'wallet-up-login-customizer'); ?></label></th>
                        <td>
                            <input type="checkbox" id="force_login_enabled" name="wallet_up_login_customizer_security_options[force_login_enabled]" value="1" <?php checked($options['force_login_enabled']); ?>>
                            <label for="force_login_enabled"><?php esc_html_e('Require authentication for all site access', 'wallet-up-login-customizer'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hide_wp_login"><?php esc_html_e('Hide wp-login.php', 'wallet-up-login-customizer'); ?></label></th>
                        <td>
                            <input type="checkbox" id="hide_wp_login" name="wallet_up_login_customizer_security_options[hide_wp_login]" value="1" <?php checked($options['hide_wp_login']); ?>>
                            <label for="hide_wp_login"><?php esc_html_e('Hide and protect wp-login.php', 'wallet-up-login-customizer'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="custom_login_slug"><?php esc_html_e('Custom Login Slug', 'wallet-up-login-customizer'); ?></label></th>
                        <td>
                            <input type="text" id="custom_login_slug" name="wallet_up_login_customizer_security_options[custom_login_slug]" value="<?php echo esc_attr($options['custom_login_slug']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Custom URL slug for the login page', 'wallet-up-login-customizer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_login_attempts"><?php esc_html_e('Max Login Attempts', 'wallet-up-login-customizer'); ?></label></th>
                        <td>
                            <input type="number" id="max_login_attempts" name="wallet_up_login_customizer_security_options[max_login_attempts]" value="<?php echo esc_attr($options['max_login_attempts']); ?>" min="3" max="10">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lockout_duration"><?php esc_html_e('Lockout Duration (seconds)', 'wallet-up-login-customizer'); ?></label></th>
                        <td>
                            <input type="number" id="lockout_duration" name="wallet_up_login_customizer_security_options[lockout_duration]" value="<?php echo esc_attr($options['lockout_duration']); ?>" min="300" max="3600">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_notifications_enabled"><?php esc_html_e('Email Notifications', 'wallet-up-login-customizer'); ?></label></th>
                        <td>
                            <input type="checkbox" id="email_notifications_enabled" name="wallet_up_login_customizer_security_options[email_notifications_enabled]" value="1" <?php checked(!empty($options['email_notifications_enabled'])); ?>>
                            <label for="email_notifications_enabled"><?php esc_html_e('Send email alerts for security events', 'wallet-up-login-customizer'); ?></label>
                        </td>
                    </tr>
                    <tr class="email-notification-settings" <?php echo empty($options['email_notifications_enabled']) ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><label for="email_notification_threshold"><?php esc_html_e('Alert Threshold', 'wallet-up-login-customizer'); ?></label></th>
                        <td>
                            <input type="number" id="email_notification_threshold" name="wallet_up_login_customizer_security_options[email_notification_threshold]" value="<?php echo esc_attr($options['email_notification_threshold'] ?? 3); ?>" min="1" max="10">
                            <p class="description"><?php esc_html_e('Send alert after this many failed attempts', 'wallet-up-login-customizer'); ?></p>
                        </td>
                    </tr>
                    <tr class="email-notification-settings" <?php echo empty($options['email_notifications_enabled']) ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><label for="email_recipient"><?php esc_html_e('Notification Email', 'wallet-up-login-customizer'); ?></label></th>
                        <td>
                            <input type="email" id="email_recipient" name="wallet_up_login_customizer_security_options[email_recipient]" value="<?php echo esc_attr($options['email_recipient'] ?? get_option('admin_email')); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Email address to receive security alerts', 'wallet-up-login-customizer'); ?></p>
                        </td>
                    </tr>
                    <tr class="email-notification-settings" <?php echo empty($options['email_notifications_enabled']) ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><label for="email_digest_enabled"><?php esc_html_e('Digest Mode', 'wallet-up-login-customizer'); ?></label></th>
                        <td>
                            <input type="checkbox" id="email_digest_enabled" name="wallet_up_login_customizer_security_options[email_digest_enabled]" value="1" <?php checked(!empty($options['email_digest_enabled'])); ?>>
                            <label for="email_digest_enabled"><?php esc_html_e('Send daily digest instead of individual alerts', 'wallet-up-login-customizer'); ?></label>
                            <p class="description"><?php esc_html_e('Reduces email frequency by combining alerts into a single daily summary', 'wallet-up-login-customizer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="session_timeout"><?php esc_html_e('Session Timeout (seconds)', 'wallet-up-login-customizer'); ?></label></th>
                        <td>
                            <input type="number" id="session_timeout" name="wallet_up_login_customizer_security_options[session_timeout]" value="<?php echo esc_attr($options['session_timeout']); ?>" min="900" max="7200">
                            <p class="description"><?php esc_html_e('Time before user sessions expire', 'wallet-up-login-customizer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="security_headers"><?php esc_html_e('Security Headers', 'wallet-up-login-customizer'); ?></label></th>
                        <td>
                            <input type="checkbox" id="security_headers" name="wallet_up_login_customizer_security_options[security_headers]" value="1" <?php checked($options['security_headers']); ?>>
                            <label for="security_headers"><?php esc_html_e('Enable security headers', 'wallet-up-login-customizer'); ?></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Toggle email notification settings visibility
                $('#email_notifications_enabled').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('.email-notification-settings').fadeIn();
                    } else {
                        $('.email-notification-settings').fadeOut();
                    }
                });
                
                // Validate email on change
                $('#email_recipient').on('blur', function() {
                    var email = $(this).val();
                    var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (email && !emailRegex.test(email)) {
                        $(this).css('border-color', '#dc3232');
                        if (!$(this).next('.error-message').length) {
                            $(this).after('<span class="error-message" style="color:#dc3232;display:block;margin-top:5px;"><?php esc_html_e('Please enter a valid email address', 'wallet-up-login-customizer'); ?></span>');
                        }
                    } else {
                        $(this).css('border-color', '');
                        $(this).next('.error-message').remove();
                    }
                });
                
                // Add test email button
                var testButton = '<button type="button" id="test-email" class="button" style="margin-left:10px;"><?php esc_html_e('Send Test Email', 'wallet-up-login-customizer'); ?></button>';
                $('#email_recipient').after(testButton);
                
                $('#test-email').on('click', function() {
                    var button = $(this);
                    var email = $('#email_recipient').val();
                    
                    if (!email) {
                        alert('<?php esc_html_e('Please enter an email address', 'wallet-up-login-customizer'); ?>');
                        return;
                    }
                    
                    button.prop('disabled', true).text('<?php esc_html_e('Sending...', 'wallet-up-login-customizer'); ?>');
                    
                    $.post(ajaxurl, {
                        action: 'wallet_up_test_security_email',
                        email: email,
                        nonce: '<?php echo esc_js(wp_create_nonce('wallet_up_test_email')); ?>'
                    }, function(response) {
                        if (response.success) {
                            button.text('<?php esc_html_e('Sent!', 'wallet-up-login-customizer'); ?>').css('background', '#46b450').css('color', 'white');
                            setTimeout(function() {
                                button.prop('disabled', false).text('<?php esc_html_e('Send Test Email', 'wallet-up-login-customizer'); ?>').css('background', '').css('color', '');
                            }, 3000);
                        } else {
                            button.prop('disabled', false).text('<?php esc_html_e('Failed', 'wallet-up-login-customizer'); ?>').css('background', '#dc3232').css('color', 'white');
                            alert(response.data || '<?php esc_html_e('Failed to send test email', 'wallet-up-login-customizer'); ?>');
                            setTimeout(function() {
                                button.text('<?php esc_html_e('Send Test Email', 'wallet-up-login-customizer'); ?>').css('background', '').css('color', '');
                            }, 3000);
                        }
                    });
                });
            });
            </script>
            
            <h2><?php esc_html_e('Security Logs', 'wallet-up-login-customizer'); ?></h2>
            <?php
            $logs = get_option('wallet_up_security_logs', []);
            if (!empty($logs)) {
                echo '<div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">';
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<thead><tr><th>Time</th><th>Event</th><th>IP</th><th>Details</th></tr></thead><tbody>';

                foreach (array_reverse(array_slice($logs, -50)) as $log) {
                    echo '<tr>';
                    echo '<td>' . esc_html($log['timestamp']) . '</td>';
                    echo '<td>' . esc_html($log['event']) . '</td>';
                    echo '<td>' . esc_html($log['ip']) . '</td>';
                    echo '<td>' . esc_html(json_encode($log['data'])) . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table></div>';
            } else {
                echo '<p>' . esc_html__('No security events logged yet.', 'wallet-up-login-customizer') . '</p>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * Handle test email AJAX request
     */
    public static function handle_test_email() {
        // Verify nonce - properly sanitize as per WordPress standards
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wallet_up_test_email')) {
            wp_send_json_error(__('Security check failed', 'wallet-up-login-customizer'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wallet-up-login-customizer'));
        }
        
        $email = sanitize_email($_POST['email'] ?? '');
        if (empty($email) || !is_email($email)) {
            wp_send_json_error(__('Invalid email address', 'wallet-up-login-customizer'));
        }
        
        $site_name = get_option('blogname');
        $subject = '[Test] Security Alert System - ' . $site_name;
        
        $message = "This is a test email from the Wallet Up Login Customizer security system.\n\n";
        $message .= "If you received this email, your security notifications are working correctly.\n\n";
        $message .= "Current Settings:\n";
        $message .= "- Email Notifications: " . (self::$options['email_notifications_enabled'] ? 'Enabled' : 'Disabled') . "\n";
        $message .= "- Alert Threshold: " . (self::$options['email_notification_threshold'] ?? 3) . " failed attempts\n";
        $message .= "- Digest Mode: " . (self::$options['email_digest_enabled'] ? 'Enabled' : 'Disabled') . "\n";
        $message .= "- Max Login Attempts: " . self::$options['max_login_attempts'] . "\n";
        $message .= "- Lockout Duration: " . self::$options['lockout_duration'] . " seconds\n\n";
        $message .= "---\n";
        $message .= "Sent from: " . home_url() . "\n";
        $message .= "Time: " . current_time('mysql') . "\n";
        
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: Wallet Up Security System'
        );
        
        $sent = wp_mail($email, $subject, $message, $headers);
        
        if ($sent) {
            wp_send_json_success(__('Test email sent successfully', 'wallet-up-login-customizer'));
        } else {
            wp_send_json_error(__('Failed to send test email. Please check your email configuration.', 'wallet-up-login-customizer'));
        }
    }
    
    /**
     * Get security statistics
     */
    public static function get_security_stats() {
        $logs = get_option('wallet_up_security_logs', []);
        $stats = [
            'total_events' => count($logs),
            'failed_logins' => 0,
            'blocked_attempts' => 0,
            'successful_logins' => 0,
        ];

        foreach ($logs as $log) {
            switch ($log['event']) {
                case 'failed_login':
                    $stats['failed_logins']++;
                    break;
                case 'blocked_wp_login_access':
                case 'blocked_discovery_attempt':
                    $stats['blocked_attempts']++;
                    break;
                case 'successful_login':
                    $stats['successful_logins']++;
                    break;
            }
        }

        return $stats;
    }
}
