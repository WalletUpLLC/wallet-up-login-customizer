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
     * Cache for rate limiting to reduce database load
     * @var array
     */
    private static $rate_limit_cache = [];

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

        $value = $_SERVER[$key];

        // Sanitize based on the variable type
        switch ($key) {
            case 'REQUEST_URI':
            case 'HTTP_REFERER':
            case 'SCRIPT_NAME':
                return esc_url_raw($value);

            case 'HTTP_HOST':
            case 'SERVER_NAME':
                $value = strtolower($value);
                if (preg_match('/^[a-z0-9.-]+$/', $value)) {
                    return $value;
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
        // Default options
        $default_options = [
            'force_login_enabled' => false,
            'hide_wp_login' => false,
            'custom_login_slug' => 'secure-login',
            'max_login_attempts' => 5,
            'lockout_duration' => 900,
            'exempt_roles' => ['administrator'],
            'security_headers' => false,
            'session_timeout' => 3600,
            'two_factor_required' => false,
        ];

        // Get and validate stored options
        $stored_options = get_option('wallet_up_security_options', $default_options);
        if (!is_array($stored_options)) {
            self::secure_log('Security options corrupted, using defaults');
            $stored_options = $default_options;
            update_option('wallet_up_security_options', $default_options);
        }

        self::$options = wp_parse_args($stored_options, $default_options);

        // Initialize security hooks
        self::init_hooks();

        if (!empty(self::$options['force_login_enabled'])) {
            self::secure_log('Force login is ENABLED - initializing');
            self::init_forced_login();
        }

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
        add_action('admin_menu', [__CLASS__, 'add_security_menu']);
        add_action('admin_init', [__CLASS__, 'register_security_settings']);
    }

    /**
     * Initialize forced login system
     */
    private static function init_forced_login() {
        if (self::has_login_conflicts()) {
            self::secure_log('Login conflicts detected, disabling forced login');
            return;
        }

        add_action('template_redirect', [__CLASS__, 'enforce_authentication'], 10);
        add_filter('rest_authentication_errors', [__CLASS__, 'restrict_rest_api'], 10);
        add_action('admin_init', [__CLASS__, 'restrict_admin_access'], 10);
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
     * Enforce authentication for all site access
     */
    public static function enforce_authentication() {
        static $redirect_in_progress = false;
        if ($redirect_in_progress) {
            return;
        }

        if (is_user_logged_in() || headers_sent() || is_admin() || self::is_login_request() || self::is_registration_request() || self::is_password_reset_request() || wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI) || self::is_public_rest_endpoint()) {
            return;
        }

        $redirect_in_progress = true;
        $login_url = self::get_secure_login_url();
        $return_url = self::get_current_url();
        $redirect_url = add_query_arg('redirect_to', urlencode($return_url), $login_url);

        self::secure_log('Force login redirect initiated');
        wp_safe_redirect($redirect_url, 302);
        exit;
    }

    /**
     * Check if current request is login-related
     */
    private static function is_login_request() {
        global $pagenow;

        if ($pagenow === 'wp-login.php') {
            return true;
        }

        $request_uri = self::get_server_var('REQUEST_URI', '');
        if (strpos($request_uri, 'wp-login.php') !== false) {
            return true;
        }

        $custom_slug = self::$options['custom_login_slug'] ?? 'secure-login';
        if (isset($_GET[$custom_slug])) {
            return true;
        }

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

        $parsed_url = parse_url($request_uri);
        if (isset($parsed_url['path']) && trim($parsed_url['path'], '/') === $custom_slug) {
            return true;
        }

        if (wp_doing_ajax() && isset($_POST['action']) && in_array($_POST['action'], ['login', 'heartbeat'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if current request is registration-related
     */
    private static function is_registration_request() {
        global $pagenow;

        if ($pagenow === 'wp-login.php' && isset($_GET['action']) && $_GET['action'] === 'register') {
            return true;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'register') {
            return true;
        }

        return false;
    }

    /**
     * Check if current request is password reset related
     */
    private static function is_password_reset_request() {
        global $pagenow;

        if ($pagenow === 'wp-login.php' && isset($_GET['action']) && in_array($_GET['action'], ['lostpassword', 'resetpass', 'rp'])) {
            return true;
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

        if (is_user_logged_in() || isset($_GET[self::$options['custom_login_slug']]) || get_query_var(self::$options['custom_login_slug']) || isset($_GET['action']) || strpos(self::get_server_var('HTTP_REFERER', ''), '/wp-admin/') !== false || wp_doing_ajax() || self::get_server_var('REQUEST_METHOD') === 'POST') {
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

                $custom_login_url = home_url('/?' . self::$options['custom_login_slug'] . '=1');
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
        if (get_query_var($custom_slug)) {
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
                $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'login';
            }
            if (!isset($rp_key)) {
                $rp_key = '';
            }
            if (!isset($rp_cookie)) {
                $rp_cookie = '';
            }
            
            do_action('login_init');
            require_once(ABSPATH . 'wp-login.php');
            exit;
        }
    }

    /**
     * Add security fields to login form
     */
    public static function add_security_fields() {
        wp_nonce_field('wallet_up_login_security', 'wallet_up_security_nonce');
        echo '<input type="text" name="wallet_up_honeypot" style="display:none !important;" tabindex="-1" autocomplete="off">';
        echo '<input type="hidden" name="wallet_up_timestamp" value="' . time() . '">';
        echo '<input type="hidden" name="wallet_up_fingerprint" value="' . self::generate_client_fingerprint() . '">';
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
        $action = $action ?? (isset($_REQUEST['action']) ? $_REQUEST['action'] : 'login');
        $redirect_to = $redirect_to ?? (isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : '');
        $requested_redirect_to = $requested_redirect_to ?? (isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : '');

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

        $user_login = $user_login ?? (isset($_POST['log']) ? esc_attr(wp_unslash($_POST['log'])) : '');
        $error = $error ?? (isset($errors) && is_wp_error($errors) && $errors->has_errors());
        $errors = $errors ?? new WP_Error();

        $nonce = wp_create_nonce('wallet_up_login_security');
        echo '<meta name="wallet-up-nonce" content="' . esc_attr($nonce) . '">';
        echo '<meta name="wallet-up-timestamp" content="' . time() . '">';
        echo '<meta name="wallet-up-fingerprint" content="' . self::generate_client_fingerprint() . '">';
        ?>
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
     * Validate login attempt
     */
    public static function validate_login_attempt($user, $username, $password) {
        if (is_wp_error($user)) {
            return $user;
        }

        // Truncate username to match WordPress's limit (60 characters)
        $username = substr(sanitize_user($username, true), 0, 60);

        $nonce_valid = false;
        if (!wp_doing_ajax()) {
            if (isset($_POST['wallet_up_security_nonce']) && wp_verify_nonce($_POST['wallet_up_security_nonce'], 'wallet_up_login_security')) {
                $nonce_valid = true;
            }
        } else {
            if (isset($_POST['security']) && wp_verify_nonce($_POST['security'], 'wallet-up-login-nonce')) {
                $nonce_valid = true;
            }
        }

        if (!$nonce_valid) {
            // Skip logging for missing nonces to avoid false positives from caching
            if (!empty($_POST['wallet_up_security_nonce'])) {
                self::log_security_event('invalid_nonce', ['username' => $username]);
            }
            return new WP_Error('invalid_security_token', __('Security validation failed. Please refresh the page and try again.', 'wallet-up-login'));
        }

        if (!empty($_POST['wallet_up_honeypot'])) {
            $honeypot_value = $_POST['wallet_up_honeypot'];
            $pm_patterns = ['on', '1', 'true', $username, $password];

            if (!in_array($honeypot_value, $pm_patterns, true)) {
                if (isset($_POST['wallet_up_timestamp']) && (time() - intval($_POST['wallet_up_timestamp']) < 2)) {
                    self::log_security_event('honeypot_triggered', [
                        'ip' => self::get_client_ip(),
                        'username' => $username,
                        'time_taken' => time() - intval($_POST['wallet_up_timestamp']),
                    ]);
                    return new WP_Error('bot_detected', __('Automated login attempts are not allowed.', 'wallet-up-login'));
                }

                self::log_security_event('honeypot_filled_allowed', [
                    'ip' => self::get_client_ip(),
                    'username' => $username,
                    'reason' => 'Timing suggests human user',
                ]);
            }
        }

        if (self::is_rate_limited()) {
            return new WP_Error('rate_limited', __('Too many login attempts. Please try again later.', 'wallet-up-login'));
        }

        if (self::is_brute_force_attempt($username)) {
            return new WP_Error('account_locked', __('Account temporarily locked due to multiple failed attempts.', 'wallet-up-login'));
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

        self::log_security_event('failed_login', [
            'username' => $username,
            'ip' => $ip,
            'attempts' => count($recent_attempts),
        ]);

        if (count($recent_attempts) >= self::$options['max_login_attempts']) {
            self::send_security_alert('Multiple failed login attempts detected', [
                'username' => $username,
                'ip' => $ip,
                'attempts' => count($recent_attempts),
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
            'username' => $user_login,
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

        if (!isset(self::$rate_limit_cache[$key])) {
            self::$rate_limit_cache[$key] = get_transient($key) ?: [];
        }

        self::$rate_limit_cache[$key][] = time();
        $recent_attempts = array_filter(self::$rate_limit_cache[$key], function($timestamp) {
            return ($timestamp > (time() - 300));
        });

        self::$rate_limit_cache[$key] = $recent_attempts;
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

        if (!isset(self::$rate_limit_cache[$key])) {
            self::$rate_limit_cache[$key] = get_transient($key) ?: [];
        }

        $recent_attempts = array_filter(self::$rate_limit_cache[$key], function($timestamp) {
            return ($timestamp > (time() - 300));
        });

        return count($recent_attempts) >= 10;
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
     * Add security headers with improved CSP
     */
    public static function add_security_headers() {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        if (is_ssl()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // Improved CSP: Remove 'unsafe-inline' and 'unsafe-eval', use nonce
        $nonce = wp_create_nonce('csp_nonce');
        $csp = "default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' data: https:; font-src 'self' data:;";
        header('Content-Security-Policy: ' . $csp);
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
        if (defined('WALLET_UP_TRUSTED_PROXY') && WALLET_UP_TRUSTED_PROXY === 'cloudflare') {
            $cf_ip = self::get_server_var('HTTP_CF_CONNECTING_IP', '');
            $remote_addr = self::get_server_var('REMOTE_ADDR', '');
            if ($cf_ip && self::is_cloudflare_ip($remote_addr)) {
                return $cf_ip;
            }
        }

        return self::get_server_var('REMOTE_ADDR', '127.0.0.1');
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
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
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
     * Send security alerts
     */
    private static function send_security_alert($subject, $data) {
        $admin_email = get_option('admin_email');
        $site_name = get_option('blogname');

        $message = "Security Alert for $site_name\n\n";
        $message .= "Event: $subject\n";
        $message .= "Time: " . current_time('mysql') . "\n";
        $safe_data = [];
        foreach ($data as $key => $value) {
            $safe_data[$key] = is_string($value) || is_numeric($value) ? substr(sanitize_text_field($value), 0, 100) : '[REDACTED]';
        }
        $message .= "Details: " . wp_json_encode($safe_data);

        wp_mail($admin_email, '[Wallet Up Security Alert] ' . $site_name, $message);
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
            return new WP_Error('rest_not_logged_in', __('You must be logged in to access the REST API.', 'wallet-up-login'), ['status' => 401]);
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
     * Add security menu
     */
    public static function add_security_menu() {
        // Integrated into main settings
    }

    /**
     * Register security settings
     */
    public static function register_security_settings() {
        register_setting('wallet_up_security_options', 'wallet_up_security_options', [
            'sanitize_callback' => [__CLASS__, 'sanitize_security_options'],
        ]);
    }

    /**
     * Sanitize security options
     */
    public static function sanitize_security_options($options) {
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
            <h1><?php _e('Wallet Up Enterprise Security', 'wallet-up-login'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('wallet_up_security_options'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="force_login_enabled"><?php _e('Force Login', 'wallet-up-login'); ?></label></th>
                        <td>
                            <input type="checkbox" id="force_login_enabled" name="wallet_up_security_options[force_login_enabled]" value="1" <?php checked($options['force_login_enabled']); ?>>
                            <label for="force_login_enabled"><?php _e('Require authentication for all site access', 'wallet-up-login'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hide_wp_login"><?php _e('Hide wp-login.php', 'wallet-up-login'); ?></label></th>
                        <td>
                            <input type="checkbox" id="hide_wp_login" name="wallet_up_security_options[hide_wp_login]" value="1" <?php checked($options['hide_wp_login']); ?>>
                            <label for="hide_wp_login"><?php _e('Hide and protect wp-login.php', 'wallet-up-login'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="custom_login_slug"><?php _e('Custom Login Slug', 'wallet-up-login'); ?></label></th>
                        <td>
                            <input type="text" id="custom_login_slug" name="wallet_up_security_options[custom_login_slug]" value="<?php echo esc_attr($options['custom_login_slug']); ?>" class="regular-text">
                            <p class="description"><?php _e('Custom URL slug for the login page', 'wallet-up-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_login_attempts"><?php _e('Max Login Attempts', 'wallet-up-login'); ?></label></th>
                        <td>
                            <input type="number" id="max_login_attempts" name="wallet_up_security_options[max_login_attempts]" value="<?php echo esc_attr($options['max_login_attempts']); ?>" min="3" max="10">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lockout_duration"><?php _e('Lockout Duration (seconds)', 'wallet-up-login'); ?></label></th>
                        <td>
                            <input type="number" id="lockout_duration" name="wallet_up_security_options[lockout_duration]" value="<?php echo esc_attr($options['lockout_duration']); ?>" min="300" max="3600">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="session_timeout"><?php _e('Session Timeout (seconds)', 'wallet-up-login'); ?></label></th>
                        <td>
                            <input type="number" id="session_timeout" name="wallet_up_security_options[session_timeout]" value="<?php echo esc_attr($options['session_timeout']); ?>" min="900" max="7200">
                            <p class="description"><?php _e('Time before user sessions expire', 'wallet-up-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="security_headers"><?php _e('Security Headers', 'wallet-up-login'); ?></label></th>
                        <td>
                            <input type="checkbox" id="security_headers" name="wallet_up_security_options[security_headers]" value="1" <?php checked($options['security_headers']); ?>>
                            <label for="security_headers"><?php _e('Enable security headers', 'wallet-up-login'); ?></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2><?php _e('Security Logs', 'wallet-up-login'); ?></h2>
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
                echo '<p>' . __('No security events logged yet.', 'wallet-up-login') . '</p>';
            }
            ?>
        </div>
        <?php
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