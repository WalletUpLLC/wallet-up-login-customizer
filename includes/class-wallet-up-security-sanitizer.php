<?php
/**
 * Wallet Up Security Sanitizer
 * 
 * Handles security sanitization and prevents debugging info disclosure
 * 
 * @package WalletUpLogin
 * @since 2.3.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WalletUpSecuritySanitizer {
    
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
        
        // Validate IP addresses
        if (in_array($key, ['REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'])) {
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
            return $default;
        }
        
        return sanitize_text_field($value);
    }
    
    /**
     * Initialize security sanitizer
     */
    public static function init() {
        // Remove debugging information from login forms
        add_filter('wp_login_errors', [__CLASS__, 'sanitize_login_errors'], 10, 2);
        
        // Filter error messages to prevent information disclosure
        add_filter('authenticate', [__CLASS__, 'sanitize_authentication_response'], 40, 3);
        
        // Remove WordPress version from login page
        add_action('login_head', [__CLASS__, 'remove_version_info']);
        
        // Sanitize AJAX responses
        add_filter('wp_ajax_wallet_up_ajax_login', [__CLASS__, 'sanitize_ajax_response']);
        add_filter('wp_ajax_nopriv_wallet_up_ajax_login', [__CLASS__, 'sanitize_ajax_response']);
        
        // Remove debugging hooks on production
        if (!self::is_debug_mode()) {
            self::remove_debug_hooks();
        }
        
        // Filter error outputs
        add_filter('wp_die_handler', [__CLASS__, 'secure_error_handler']);
        
        // Prevent error disclosure in headers
        add_action('wp_headers', [__CLASS__, 'sanitize_headers']);
    }
    
    /**
     * Sanitize login error messages to prevent user enumeration
     */
    public static function sanitize_login_errors($errors, $redirect_to) {
        if (!is_wp_error($errors)) {
            return $errors;
        }
        
        $sanitized_errors = new WP_Error();
        $generic_message = __('Invalid login credentials. Please try again.', 'wallet-up-login');
        
        // Get all error codes
        $error_codes = $errors->get_error_codes();
        
        foreach ($error_codes as $code) {
            switch ($code) {
                case 'invalid_username':
                case 'invalid_email':
                case 'incorrect_password':
                case 'invalidcombo':
                    // Use generic message to prevent user enumeration
                    $sanitized_errors->add('invalid_login', $generic_message);
                    break;
                
                case 'empty_username':
                case 'empty_password':
                    // These are OK to show as they don't reveal user info
                    $original_message = $errors->get_error_message($code);
                    $sanitized_errors->add($code, $original_message);
                    break;
                
                case 'too_many_retries':
                case 'account_locked':
                    // Security-related messages are OK
                    $original_message = $errors->get_error_message($code);
                    $sanitized_errors->add($code, $original_message);
                    break;
                
                default:
                    // For any unknown error, use generic message
                    $sanitized_errors->add('login_error', $generic_message);
                    break;
            }
        }
        
        return $sanitized_errors;
    }
    
    /**
     * Sanitize authentication response
     */
    public static function sanitize_authentication_response($user, $username, $password) {
        if (is_wp_error($user)) {
            $error_codes = $user->get_error_codes();
            $sanitized_user = new WP_Error();
            $generic_message = __('Invalid login credentials. Please try again.', 'wallet-up-login');
            
            foreach ($error_codes as $code) {
                switch ($code) {
                    case 'invalid_username':
                    case 'invalid_email':
                    case 'incorrect_password':
                        // Prevent user enumeration
                        $sanitized_user->add('invalid_login', $generic_message);
                        break;
                    
                    case 'empty_username':
                    case 'empty_password':
                        // These are safe to show
                        $sanitized_user->add($code, $user->get_error_message($code));
                        break;
                    
                    case 'invalid_security_token':
                    case 'bot_detected':
                    case 'rate_limited':
                    case 'account_locked':
                        // Security messages are OK
                        $sanitized_user->add($code, $user->get_error_message($code));
                        break;
                    
                    default:
                        // Default to generic message
                        $sanitized_user->add('authentication_failed', $generic_message);
                        break;
                }
            }
            
            return $sanitized_user;
        }
        
        return $user;
    }
    
    /**
     * Remove version information from login page
     */
    public static function remove_version_info() {
        // Remove WordPress version meta tag
        remove_action('wp_head', 'wp_generator');
        remove_action('login_head', 'wp_generator');
        
        // Remove version from scripts and styles
        add_filter('style_loader_src', [__CLASS__, 'remove_version_from_assets'], 10, 1);
        add_filter('script_loader_src', [__CLASS__, 'remove_version_from_assets'], 10, 1);
        
        // Add no-cache headers for security
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    
    /**
     * Remove version from asset URLs
     */
    public static function remove_version_from_assets($src) {
        if (strpos($src, 'ver=')) {
            $src = remove_query_arg('ver', $src);
        }
        return $src;
    }
    
    /**
     * Sanitize AJAX responses
     */
    public static function sanitize_ajax_response() {
        // Hook into the AJAX handler to sanitize responses
        add_filter('wp_ajax_wallet_up_ajax_login_response', [__CLASS__, 'sanitize_ajax_login_response']);
    }
    
    /**
     * Sanitize AJAX login response
     */
    public static function sanitize_ajax_login_response($response) {
        // Remove any debugging information
        if (isset($response['debug'])) {
            unset($response['debug']);
        }
        
        if (isset($response['error_details'])) {
            unset($response['error_details']);
        }
        
        if (isset($response['stack_trace'])) {
            unset($response['stack_trace']);
        }
        
        // Sanitize error messages
        if (!$response['success'] && isset($response['data']['message'])) {
            $message = $response['data']['message'];
            
            // Replace specific error messages with generic ones
            $sensitive_patterns = [
                '/invalid username/i' => 'Invalid login credentials',
                '/user.*not.*found/i' => 'Invalid login credentials',
                '/incorrect password/i' => 'Invalid login credentials',
                '/username.*exist/i' => 'Invalid login credentials',
                '/email.*exist/i' => 'Invalid login credentials',
            ];
            
            foreach ($sensitive_patterns as $pattern => $replacement) {
                if (preg_match($pattern, $message)) {
                    $response['data']['message'] = $replacement;
                    break;
                }
            }
        }
        
        return $response;
    }
    
    /**
     * Check if debug mode is enabled
     */
    private static function is_debug_mode() {
        return (defined('WP_DEBUG') && WP_DEBUG) || 
               (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) ||
               (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG);
    }
    
    /**
     * Remove debug hooks in production
     */
    private static function remove_debug_hooks() {
        // Remove error reporting
        ini_set('display_errors', 0);
        ini_set('log_errors', 0);
        
        // Remove WordPress debug actions
        remove_action('wp_head', 'wp_print_scripts');
        remove_action('wp_head', 'wp_print_head_scripts', 9);
        
        // Remove jQuery migrate script warnings
        add_action('wp_default_scripts', function($scripts) {
            if (!empty($scripts->registered['jquery'])) {
                $scripts->registered['jquery']->deps = array_diff(
                    $scripts->registered['jquery']->deps, 
                    ['jquery-migrate']
                );
            }
        });
    }
    
    /**
     * Secure error handler
     */
    public static function secure_error_handler($handler) {
        return function($message, $title, $args) use ($handler) {
            // Filter sensitive information from error messages
            $filtered_message = self::filter_sensitive_info($message);
            
            // Call original handler with filtered message
            return call_user_func($handler, $filtered_message, $title, $args);
        };
    }
    
    /**
     * Filter sensitive information from messages
     */
    private static function filter_sensitive_info($message) {
        // Handle WP_Error objects
        if (is_wp_error($message)) {
            return $message; // Return WP_Error object as-is
        }
        
        // Convert to string if necessary
        if (!is_string($message)) {
            $message = (string) $message;
        }
        
        // Remove file paths
        $message = preg_replace('#/[a-zA-Z0-9_\-/]+\.php#', '[file]', $message);
        
        // Remove database information
        $message = preg_replace('/Database error:.*/i', 'Database connection error', $message);
        
        // Remove stack traces
        $message = preg_replace('/Stack trace:.*/s', '', $message);
        
        // Remove function names and line numbers
        $message = preg_replace('/in .* on line \d+/', '', $message);
        
        // Remove specific WordPress paths
        $message = str_replace([
            ABSPATH,
            WP_CONTENT_DIR,
            WP_PLUGIN_DIR,
            get_template_directory(),
            get_stylesheet_directory()
        ], '[path]', $message);
        
        return $message;
    }
    
    /**
     * Sanitize HTTP headers
     */
    public static function sanitize_headers($headers) {
        // Remove server information
        if (isset($headers['Server'])) {
            unset($headers['Server']);
        }
        
        if (isset($headers['X-Powered-By'])) {
            unset($headers['X-Powered-By']);
        }
        
        // Remove WordPress-specific headers
        if (isset($headers['X-Pingback'])) {
            unset($headers['X-Pingback']);
        }
        
        return $headers;
    }
    
    /**
     * Create secure AJAX login handler
     */
    public static function handle_ajax_login() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['security'] ?? '', 'wallet_up_login_security')) {
            wp_send_json_error([
                'message' => __('Security verification failed.', 'wallet-up-login')
            ]);
        }
        
        // Rate limiting check
        if (self::is_rate_limited()) {
            wp_send_json_error([
                'message' => __('Too many attempts. Please try again later.', 'wallet-up-login')
            ]);
        }
        
        // Honeypot check
        if (!empty($_POST['wallet_up_honeypot'])) {
            wp_send_json_error([
                'message' => __('Invalid request.', 'wallet-up-login')
            ]);
        }
        
        $username = sanitize_user($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);
        
        if (empty($username) || empty($password)) {
            wp_send_json_error([
                'message' => __('Please enter both username and password.', 'wallet-up-login')
            ]);
        }
        
        // Authenticate user
        $user = wp_authenticate($username, $password);
        
        if (is_wp_error($user)) {
            // Log failed attempt
            self::log_failed_attempt($username);
            
            // Return sanitized error
            wp_send_json_error([
                'message' => __('Invalid login credentials. Please try again.', 'wallet-up-login')
            ]);
        }
        
        // Set authentication cookies
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember);
        
        // Determine redirect URL
        $redirect_to = !empty($_POST['redirect_to']) ? 
            esc_url_raw($_POST['redirect_to']) : 
            admin_url();
        
        wp_send_json_success([
            'message' => sprintf(__('Welcome back, %s!', 'wallet-up-login'), esc_html($user->display_name)),
            'redirect' => $redirect_to
        ]);
    }
    
    /**
     * Check if current IP is rate limited
     */
    private static function is_rate_limited() {
        $ip = self::get_client_ip();
        $key = 'wallet_up_rate_limit_' . md5($ip);
        
        $attempts = get_transient($key) ?: 0;
        
        if ($attempts >= 10) { // Max 10 attempts per 5 minutes
            return true;
        }
        
        // Increment attempts
        set_transient($key, $attempts + 1, 300); // 5 minutes
        
        return false;
    }
    
    /**
     * Log failed login attempt
     */
    private static function log_failed_attempt($username) {
        $ip = self::get_client_ip();
        $key = 'wallet_up_failed_' . md5($ip . $username);
        
        $attempts = get_transient($key) ?: 0;
        set_transient($key, $attempts + 1, 900); // 15 minutes
        
        // Log to security log (but not WordPress debug log)
        if (function_exists('error_log') && self::is_debug_mode()) {
            error_log(sprintf(
                'Wallet Up: Failed login attempt for user "%s" from IP %s',
                sanitize_user($username),
                $ip
            ));
        }
    }
    
    /**
     * Get client IP address securely
     * SECURITY: Only trust REMOTE_ADDR to prevent IP spoofing
     */
    private static function get_client_ip() {
        // SECURITY: For security-critical operations, only use REMOTE_ADDR
        // Other headers can be easily spoofed by attackers
        return self::get_server_var('REMOTE_ADDR', '127.0.0.1');
    }
    
    /**
     * Sanitize console output in JavaScript
     */
    public static function sanitize_js_console() {
        if (!self::is_debug_mode()) {
            ?>
            <script>
            (function() {
                // Override console methods in production
                var noop = function() {};
                if (typeof console !== 'undefined') {
                    console.log = noop;
                    console.debug = noop;
                    console.info = noop;
                    console.warn = noop;
                    console.error = noop;
                    console.trace = noop;
                    console.dir = noop;
                    console.dirxml = noop;
                    console.group = noop;
                    console.groupEnd = noop;
                    console.time = noop;
                    console.timeEnd = noop;
                    console.assert = noop;
                    console.profile = noop;
                    console.profileEnd = noop;
                }
            })();
            </script>
            <?php
        }
    }
}