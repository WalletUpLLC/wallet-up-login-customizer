<?php
/**
 * Wallet Up Security Sanitizer
 * 
 * Handles security sanitization and prevents debugging info disclosure
 * 
 * @package WalletUpLogin
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WalletUpSecuritySanitizer {

    private static function get_server_var($key, $default = '') {
        if (!isset($_SERVER[$key])) {
            return $default;
        }
        
        $value = sanitize_text_field($_SERVER[$key]);

        if (in_array($key, ['REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'])) {
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
            return $default;
        }
        
        return sanitize_text_field($value);
    }

    public static function init() {
        
        add_filter('wp_login_errors', [__CLASS__, 'sanitize_login_errors'], 10, 2);

        add_filter('authenticate', [__CLASS__, 'sanitize_authentication_response'], 40, 3);

        add_action('login_head', [__CLASS__, 'remove_version_info']);

        add_filter('wp_ajax_wallet_up_ajax_login', [__CLASS__, 'sanitize_ajax_response']);
        add_filter('wp_ajax_nopriv_wallet_up_ajax_login', [__CLASS__, 'sanitize_ajax_response']);

        if (!self::is_debug_mode()) {
            self::remove_debug_hooks();
        }

        add_filter('wp_die_handler', [__CLASS__, 'secure_error_handler']);

        add_action('wp_headers', [__CLASS__, 'sanitize_headers']);
    }

    public static function sanitize_login_errors($errors, $redirect_to) {
        if (!is_wp_error($errors)) {
            return $errors;
        }
        
        $sanitized_errors = new WP_Error();
        $generic_message = __('Invalid login credentials. Please try again.', 'wallet-up-login-customizer');

        $error_codes = $errors->get_error_codes();
        
        foreach ($error_codes as $code) {
            switch ($code) {
                case 'invalid_username':
                case 'invalid_email':
                case 'incorrect_password':
                case 'invalidcombo':
                    
                    $sanitized_errors->add('invalid_login', $generic_message);
                    break;
                
                case 'empty_username':
                case 'empty_password':
                    
                    $original_message = $errors->get_error_message($code);
                    $sanitized_errors->add($code, $original_message);
                    break;
                
                case 'too_many_retries':
                case 'account_locked':
                    
                    $original_message = $errors->get_error_message($code);
                    $sanitized_errors->add($code, $original_message);
                    break;
                
                default:
                    
                    $sanitized_errors->add('login_error', $generic_message);
                    break;
            }
        }
        
        return $sanitized_errors;
    }

    public static function sanitize_authentication_response($user, $username, $password) {
        if (is_wp_error($user)) {
            $error_codes = $user->get_error_codes();
            $sanitized_user = new WP_Error();
            $generic_message = __('Invalid login credentials. Please try again.', 'wallet-up-login-customizer');
            
            foreach ($error_codes as $code) {
                switch ($code) {
                    case 'invalid_username':
                    case 'invalid_email':
                    case 'incorrect_password':
                        
                        $sanitized_user->add('invalid_login', $generic_message);
                        break;
                    
                    case 'empty_username':
                    case 'empty_password':
                        
                        $sanitized_user->add($code, $user->get_error_message($code));
                        break;
                    
                    case 'invalid_security_token':
                    case 'bot_detected':
                    case 'rate_limited':
                    case 'account_locked':
                        
                        $sanitized_user->add($code, $user->get_error_message($code));
                        break;
                    
                    default:
                        
                        $sanitized_user->add('authentication_failed', $generic_message);
                        break;
                }
            }
            
            return $sanitized_user;
        }
        
        return $user;
    }

    public static function remove_version_info() {
        
        remove_action('wp_head', 'wp_generator');
        remove_action('login_head', 'wp_generator');

        add_filter('style_loader_src', [__CLASS__, 'remove_version_from_assets'], 10, 1);
        add_filter('script_loader_src', [__CLASS__, 'remove_version_from_assets'], 10, 1);

        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    public static function remove_version_from_assets($src) {
        if (strpos($src, 'ver=')) {
            $src = remove_query_arg('ver', $src);
        }
        return $src;
    }

    public static function sanitize_ajax_response() {
        
        add_filter('wp_ajax_wallet_up_ajax_login_response', [__CLASS__, 'sanitize_ajax_login_response']);
    }

    public static function sanitize_ajax_login_response($response) {
        
        if (isset($response['debug'])) {
            unset($response['debug']);
        }
        
        if (isset($response['error_details'])) {
            unset($response['error_details']);
        }
        
        if (isset($response['stack_trace'])) {
            unset($response['stack_trace']);
        }

        if (!$response['success'] && isset($response['data']['message'])) {
            $message = $response['data']['message'];

            $sensitive_patterns = [
                '/invalid username/i' => esc_html__('Invalid login credentials', 'wallet-up-login-customizer'),
                '/user.*not.*found/i' => esc_html__('Invalid login credentials', 'wallet-up-login-customizer'),
                '/incorrect password/i' => esc_html__('Invalid login credentials', 'wallet-up-login-customizer'),
                '/username.*exist/i' => esc_html__('Invalid login credentials', 'wallet-up-login-customizer'),
                '/email.*exist/i' => esc_html__('Invalid login credentials', 'wallet-up-login-customizer'),
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

    private static function is_debug_mode() {
        return (defined('WP_DEBUG') && WP_DEBUG) || 
               (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) ||
               (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG);
    }

    private static function remove_debug_hooks() {
        
        ini_set('display_errors', 0);
        ini_set('log_errors', 0);

        remove_action('wp_head', 'wp_print_scripts');
        remove_action('wp_head', 'wp_print_head_scripts', 9);

        add_action('wp_default_scripts', function($scripts) {
            if (!empty($scripts->registered['jquery'])) {
                $scripts->registered['jquery']->deps = array_diff(
                    $scripts->registered['jquery']->deps, 
                    ['jquery-migrate']
                );
            }
        });
    }

    public static function secure_error_handler($handler) {
        return function($message, $title, $args) use ($handler) {
            
            $filtered_message = self::filter_sensitive_info($message);

            return call_user_func($handler, $filtered_message, $title, $args);
        };
    }

    private static function filter_sensitive_info($message) {
        
        if (is_wp_error($message)) {
            return $message; 
        }

        if (!is_string($message)) {
            $message = (string) $message;
        }

        $message = preg_replace('#/[a-zA-Z0-9_\-/]+\.php#', '[file]', $message);

        $message = preg_replace('/Database error:.*/i', 'Database connection error', $message);

        $message = preg_replace('/Stack trace:.*/s', '', $message);

        $message = preg_replace('/in .* on line \d+/', '', $message);

        $message = str_replace([
            ABSPATH,
            WP_CONTENT_DIR,
            WP_PLUGIN_DIR,
            get_template_directory(),
            get_stylesheet_directory()
        ], '[path]', $message);
        
        return $message;
    }

    public static function sanitize_headers($headers) {
        
        if (isset($headers['Server'])) {
            unset($headers['Server']);
        }
        
        if (isset($headers['X-Powered-By'])) {
            unset($headers['X-Powered-By']);
        }

        if (isset($headers['X-Pingback'])) {
            unset($headers['X-Pingback']);
        }
        
        return $headers;
    }

    public static function handle_ajax_login() {
        
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'] ?? '')), 'wallet_up_login_customizer_security')) {
            wp_send_json_error([
                'message' => __('Security verification failed.', 'wallet-up-login-customizer')
            ]);
        }

        if (self::is_rate_limited()) {
            wp_send_json_error([
                'message' => __('Too many attempts. Please try again later.', 'wallet-up-login-customizer')
            ]);
        }

        if (!empty(sanitize_text_field($_POST['wallet_up_honeypot'] ?? ''))) {
            wp_send_json_error([
                'message' => __('Invalid request.', 'wallet-up-login-customizer')
            ]);
        }
        
        $username = sanitize_user($_POST['username'] ?? '');
        // Password should not be sanitized but properly handled
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $remember = !empty($_POST['remember']);
        
        if (empty($username) || empty($password)) {
            wp_send_json_error([
                'message' => __('Please enter both username and password.', 'wallet-up-login-customizer')
            ]);
        }

        $user = wp_authenticate($username, $password);
        
        if (is_wp_error($user)) {
            
            self::log_failed_attempt($username);

            wp_send_json_error([
                'message' => __('Invalid login credentials. Please try again.', 'wallet-up-login-customizer')
            ]);
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember);

        $redirect_to = !empty($_POST['redirect_to']) ? 
            esc_url_raw($_POST['redirect_to']) : 
            admin_url();
        
        wp_send_json_success([
            'message' => sprintf(__('Welcome back, %s!', 'wallet-up-login-customizer'), esc_html($user->display_name)),
            'redirect' => $redirect_to
        ]);
    }

    private static function is_rate_limited() {
        $ip = self::get_client_ip();
        $key = 'wallet_up_rate_limit_' . md5($ip);
        
        $attempts = get_transient($key) ?: 0;
        
        if ($attempts >= 10) { 
            return true;
        }

        set_transient($key, $attempts + 1, 300); 
        
        return false;
    }

    private static function log_failed_attempt($username) {
        $ip = self::get_client_ip();
        $key = 'wallet_up_failed_' . md5($ip . $username);
        
        $attempts = get_transient($key) ?: 0;
        set_transient($key, $attempts + 1, 900); 

        if (function_exists('error_log') && self::is_debug_mode()) {
            error_log(sprintf(
                'Wallet Up: Failed login attempt for user "%s" from IP %s',
                sanitize_user($username),
                $ip
            ));
        }
    }

    private static function get_client_ip() {

        return self::get_server_var('REMOTE_ADDR', '127.0.0.1');
    }

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