<?php
/**
 * Wallet Up Security Manager
 * 
 * Comprehensive security improvements to prevent username enumeration,
 * email spam, and brute force attacks
 *
 * @package WalletUpLogin
 * @since 2.3.6
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WalletUpSecurityManager {
    
    /**
     * Email rate limiting storage
     */
    private static $email_rate_limit = [];
    
    /**
     * Initialize security patches
     */
    public static function init() {
        // Remove vulnerable endpoints
        self::remove_vulnerable_endpoints();
        
        // Add username enumeration protection
        self::add_enumeration_protection();
        
        // Implement email rate limiting
        self::add_email_rate_limiting();
        
        // Add bot detection
        self::add_bot_detection();
        
        // Protect WordPress core endpoints
        self::protect_core_endpoints();
    }
    
    /**
     * Remove vulnerable AJAX endpoints
     */
    private static function remove_vulnerable_endpoints() {
        // Remove public username validation
        remove_action('wp_ajax_nopriv_wallet_up_validate_username', array('WalletUpLoginCustomizer', 'ajax_validate_username'));
        
        // Override the function if it exists
        add_action('wp_ajax_nopriv_wallet_up_validate_username', function() {
            wp_send_json_error(array(
                'message' => __('This feature has been disabled for security reasons', 'wallet-up-login-customizer')
            ));
        }, 1);
    }
    
    /**
     * Add comprehensive username enumeration protection
     */
    private static function add_enumeration_protection() {
        // Disable author archives
        add_action('template_redirect', function() {
            if (is_author()) {
                wp_redirect(home_url(), 301);
                exit;
            }
        });
        
        // Remove author from OEmbed
        add_filter('oembed_response_data', function($data) {
            unset($data['author_name']);
            unset($data['author_url']);
            return $data;
        });
        
        // Disable user REST API endpoints for non-authenticated users
        add_filter('rest_authentication_errors', function($result) {
            if (!is_user_logged_in() && 
                strpos(sanitize_text_field($_SERVER['REQUEST_URI'] ?? ''), '/wp-json/wp/v2/users') !== false) {
                return new WP_Error(
                    'rest_not_logged_in',
                    __('You must be logged in to access user data.', 'wallet-up-login-customizer'),
                    array('status' => 401)
                );
            }
            return $result;
        });
        
        // Remove usernames from feeds
        add_filter('the_author', function($author) {
            if (is_feed()) {
                return get_bloginfo('name');
            }
            return $author;
        });
        
        // Disable ?author= query
        add_action('init', function() {
            if (isset($_REQUEST['author']) && !is_admin()) {
                wp_redirect(home_url(), 301);
                exit;
            }
        });
    }
    
    /**
     * Implement intelligent email rate limiting
     */
    private static function add_email_rate_limiting() {
        // Hook into the security alert function
        add_filter('wallet_up_should_send_security_alert', function($should_send, $subject, $data) {
            $ip = isset($data['ip']) ? $data['ip'] : 'unknown';
            $username = isset($data['username']) ? $data['username'] : 'unknown';
            
            // Create unique key for this alert type
            $key = md5($subject . $ip . $username);
            
            // Check if we've sent an alert for this combination recently
            $last_sent = get_transient('wallet_up_alert_' . $key);
            
            if ($last_sent) {
                // Don't send if we sent one in the last hour
                return false;
            }
            
            // Set transient for 1 hour
            set_transient('wallet_up_alert_' . $key, time(), HOUR_IN_SECONDS);
            
            // Also implement daily digest option
            $digest_enabled = get_option('wallet_up_security_digest_enabled', false);
            if ($digest_enabled) {
                self::add_to_digest($subject, $data);
                return false; // Don't send immediate email
            }
            
            return true;
        }, 10, 3);
    }
    
    /**
     * Add to daily digest
     */
    private static function add_to_digest($subject, $data) {
        $digest = get_option('wallet_up_security_digest', []);
        $today = date('Y-m-d');
        
        if (!isset($digest[$today])) {
            $digest[$today] = [];
        }
        
        $digest[$today][] = [
            'time' => current_time('mysql'),
            'subject' => $subject,
            'data' => $data
        ];
        
        // Keep only last 7 days
        $digest = array_slice($digest, -7, 7, true);
        
        update_option('wallet_up_security_digest', $digest);
        
        // Schedule digest email if not already scheduled
        if (!wp_next_scheduled('wallet_up_send_security_digest')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'wallet_up_send_security_digest');
        }
    }
    
    /**
     * Add intelligent bot detection
     */
    private static function add_bot_detection() {
        add_action('wp_login_failed', function($username) {
            $ip = self::get_client_ip();
            
            // Check if IP is from known cloud providers
            if (self::is_cloud_ip($ip)) {
                // Immediately block cloud IPs
                self::block_ip($ip, 'cloud_provider');
            }
            
            // Check for bot patterns
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
            if (self::is_bot_user_agent($user_agent)) {
                self::block_ip($ip, 'bot_pattern');
            }
            
            // Check for rapid-fire attempts
            $attempt_key = 'login_attempt_' . md5($ip);
            $attempts = get_transient($attempt_key) ?: [];
            $attempts[] = time();
            
            // Keep only attempts from last 60 seconds
            $attempts = array_filter($attempts, function($time) {
                return $time > (time() - 60);
            });
            
            if (count($attempts) > 10) { // More than 10 attempts per minute
                self::block_ip($ip, 'rapid_fire');
            }
            
            set_transient($attempt_key, $attempts, 300);
        }, 5); // High priority to run before other handlers
    }
    
    /**
     * Check if IP is from cloud provider
     */
    private static function is_cloud_ip($ip) {
        // Common cloud provider IP ranges (simplified)
        $cloud_ranges = [
            '34.64.0.0/10',     // Google Cloud
            '35.192.0.0/12',    // Google Cloud
            '52.0.0.0/6',       // AWS
            '13.0.0.0/8',       // AWS
            '40.0.0.0/7',       // Azure
            '20.0.0.0/6',       // Azure
        ];
        
        foreach ($cloud_ranges as $range) {
            if (self::ip_in_cidr($ip, $range)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user agent matches bot patterns
     */
    private static function is_bot_user_agent($user_agent) {
        $bot_patterns = [
            '/bot/i',
            '/crawler/i',
            '/spider/i',
            '/scraper/i',
            '/curl/i',
            '/wget/i',
            '/python/i',
            '/libwww/i',
            '/scan/i',
        ];
        
        foreach ($bot_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Block IP address
     */
    private static function block_ip($ip, $reason) {
        $blocked_ips = get_option('wallet_up_blocked_ips', []);
        
        $blocked_ips[$ip] = [
            'time' => current_time('mysql'),
            'reason' => $reason,
            'expires' => time() + DAY_IN_SECONDS // 24 hour block
        ];
        
        update_option('wallet_up_blocked_ips', $blocked_ips);
        
        // Also add to .htaccess if possible
        self::update_htaccess_blocks($blocked_ips);
    }
    
    /**
     * Update .htaccess with blocked IPs
     */
    private static function update_htaccess_blocks($blocked_ips) {
        if (!function_exists('insert_with_markers')) {
            require_once(ABSPATH . 'wp-admin/includes/misc.php');
        }
        
        $rules = [];
        foreach ($blocked_ips as $ip => $data) {
            if ($data['expires'] > time()) {
                $rules[] = 'Deny from ' . $ip;
            }
        }
        
        if (!empty($rules)) {
            array_unshift($rules, 'Order Allow,Deny');
            array_push($rules, 'Allow from all');
            insert_with_markers(ABSPATH . '.htaccess', 'WalletUpSecurity', $rules);
        }
    }
    
    /**
     * Check if IP is in CIDR range
     */
    private static function ip_in_cidr($ip, $cidr) {
        list($subnet, $bits) = explode('/', $cidr);
        $ip_binary = sprintf("%032b", ip2long($ip));
        $subnet_binary = sprintf("%032b", ip2long($subnet));
        return substr($ip_binary, 0, $bits) === substr($subnet_binary, 0, $bits);
    }
    
    /**
     * Get client IP
     */
    private static function get_client_ip() {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                return sanitize_text_field($_SERVER[$key]);
            }
        }
        return '0.0.0.0';
    }
    
    /**
     * Protect WordPress core endpoints
     */
    private static function protect_core_endpoints() {
        // Already implemented in add_enumeration_protection
    }
    
    /**
     * Add security settings to admin
     */
    public static function add_admin_settings() {
        add_action('admin_menu', function() {
            add_submenu_page(
                'wallet-up-login-customizer',
                __('Security Settings', 'wallet-up-login-customizer'),
                __('Security', 'wallet-up-login-customizer'),
                'manage_options',
                'wallet-up-security',
                [__CLASS__, 'render_security_page']
            );
        });
    }
    
    /**
     * Render security settings page
     */
    public static function render_security_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wallet Up Security Settings', 'wallet-up-login-customizer'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('wallet_up_security_manager'); ?>
                
                <h2><?php esc_html_e('Email Alert Settings', 'wallet-up-login-customizer'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wallet_up_security_digest_enabled">
                                <?php esc_html_e('Enable Daily Digest', 'wallet-up-login-customizer'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="checkbox" id="wallet_up_security_digest_enabled" 
                                   name="wallet_up_security_digest_enabled" value="1" 
                                   <?php checked(get_option('wallet_up_security_digest_enabled')); ?>>
                            <p class="description">
                                <?php esc_html_e('Receive one daily email with all security events instead of individual alerts', 'wallet-up-login-customizer'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php esc_html_e('Blocked IPs', 'wallet-up-login-customizer'); ?></h2>
                <?php
                $blocked_ips = get_option('wallet_up_blocked_ips', []);
                if (!empty($blocked_ips)) {
                    echo '<table class="wp-list-table widefat">';
                    echo '<thead><tr><th>IP</th><th>Reason</th><th>Blocked At</th><th>Action</th></tr></thead>';
                    echo '<tbody>';
                    foreach ($blocked_ips as $ip => $data) {
                        if ($data['expires'] > time()) {
                            echo '<tr>';
                            echo '<td>' . esc_html($ip) . '</td>';
                            echo '<td>' . esc_html($data['reason']) . '</td>';
                            echo '<td>' . esc_html($data['time']) . '</td>';
                            echo '<td><a href="#" class="unblock-ip" data-ip="' . esc_attr($ip) . '">Unblock</a></td>';
                            echo '</tr>';
                        }
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p>' . esc_html__('No IPs are currently blocked.', 'wallet-up-login-customizer') . '</p>';
                }
                ?>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

// Initialize security manager
add_action('init', ['WalletUpSecurityManager', 'init'], 1);
add_action('admin_init', ['WalletUpSecurityManager', 'add_admin_settings']);

// Add cron job for daily digest
add_action('wallet_up_send_security_digest', function() {
    $digest = get_option('wallet_up_security_digest', []);
    $today = date('Y-m-d');
    
    if (isset($digest[$today]) && !empty($digest[$today])) {
        $admin_email = get_option('admin_email');
        $site_name = get_option('blogname');
        
        $message = "Daily Security Digest for $site_name\n\n";
        $message .= "Date: $today\n";
        $message .= "Total Events: " . count($digest[$today]) . "\n\n";
        
        $event_summary = [];
        foreach ($digest[$today] as $event) {
            $key = $event['subject'];
            if (!isset($event_summary[$key])) {
                $event_summary[$key] = 0;
            }
            $event_summary[$key]++;
        }
        
        $message .= "Summary:\n";
        foreach ($event_summary as $event_type => $count) {
            $message .= "- $event_type: $count\n";
        }
        
        $message .= "\nDetailed Events:\n";
        foreach ($digest[$today] as $event) {
            $message .= "\n" . $event['time'] . " - " . $event['subject'] . "\n";
            if (isset($event['data']['ip'])) {
                $message .= "IP: " . $event['data']['ip'] . "\n";
            }
            if (isset($event['data']['username'])) {
                $message .= "Username: [REDACTED]\n"; 
            }
        }
        
        wp_mail($admin_email, '[Wallet Up Security] Daily Digest - ' . $site_name, $message);
    }
});