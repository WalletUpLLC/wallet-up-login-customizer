<?php
/**
 * Wallet Up Conflict Detector
 * 
 * Detects and prevents conflicts with other plugins/themes
 * 
 * @package WalletUpLogin
 * @since 2.3.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WalletUpConflictDetector {
    
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
        if (in_array($key, ['REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED'])) {
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
            return $default;
        }
        
        return sanitize_text_field($value);
    }
    
    /**
     * Initialize conflict detection
     */
    public static function init() {
        // Check for conflicts on admin pages
        add_action('admin_init', [__CLASS__, 'check_conflicts']);
        
        // Add admin notices for conflicts
        add_action('admin_notices', [__CLASS__, 'display_conflict_notices']);
        
        // AJAX handler for conflict resolution
        add_action('wp_ajax_wallet_up_resolve_conflict', [__CLASS__, 'resolve_conflict']);
    }
    
    /**
     * Check for all types of conflicts
     */
    public static function check_conflicts() {
        $conflicts = [];
        
        // Check theme conflicts
        $theme_conflicts = self::check_theme_conflicts();
        if (!empty($theme_conflicts)) {
            $conflicts['theme'] = $theme_conflicts;
        }
        
        // Check plugin conflicts
        $plugin_conflicts = self::check_plugin_conflicts();
        if (!empty($plugin_conflicts)) {
            $conflicts['plugins'] = $plugin_conflicts;
        }
        
        // Check settings conflicts
        $settings_conflicts = self::check_settings_conflicts();
        if (!empty($settings_conflicts)) {
            $conflicts['settings'] = $settings_conflicts;
        }
        
        // Store conflicts for display
        if (!empty($conflicts)) {
            set_transient('wallet_up_conflicts', $conflicts, 300); // 5 minutes
        } else {
            delete_transient('wallet_up_conflicts');
        }
    }
    
    /**
     * Check for theme-based conflicts
     */
    private static function check_theme_conflicts() {
        $conflicts = [];
        
        // Check functions.php for conflicting functions
        $conflicting_functions = [
            'require_login_for_all_pages',
            'force_login_redirect',
            'site_wide_login_required',
            'redirect_to_login',
            'force_user_login'
        ];
        
        foreach ($conflicting_functions as $function) {
            if (function_exists($function)) {
                $conflicts[] = [
                    'type' => 'function',
                    'name' => $function,
                    'location' => 'Theme functions.php',
                    'severity' => 'high',
                    'description' => sprintf(__('Function %s conflicts with Wallet Up force login', 'wallet-up-login'), $function)
                ];
            }
        }
        
        // Check for hooks that might conflict
        $conflicting_hooks = [
            'template_redirect' => 'force_login',
            'init' => 'login_required',
            'wp_loaded' => 'authenticate'
        ];
        
        foreach ($conflicting_hooks as $hook => $pattern) {
            $callbacks = $GLOBALS['wp_filter'][$hook] ?? [];
            if (is_object($callbacks)) {
                foreach ($callbacks->callbacks as $priority => $functions) {
                    foreach ($functions as $function_name => $function_data) {
                        if (strpos($function_name, $pattern) !== false) {
                            $conflicts[] = [
                                'type' => 'hook',
                                'name' => $function_name,
                                'hook' => $hook,
                                'priority' => $priority,
                                'severity' => 'medium',
                                'description' => sprintf(__('Hook %s on %s may conflict with Wallet Up', 'wallet-up-login'), $function_name, $hook)
                            ];
                        }
                    }
                }
            }
        }
        
        return $conflicts;
    }
    
    /**
     * Check for plugin conflicts
     */
    private static function check_plugin_conflicts() {
        $conflicts = [];
        
        // Known conflicting plugins
        $conflicting_plugins = [
            'force-login/force-login.php' => [
                'name' => 'Force Login',
                'severity' => 'high',
                'description' => 'Direct conflict with force login functionality'
            ],
            'wp-force-login/wp-force-login.php' => [
                'name' => 'WP Force Login',
                'severity' => 'high', 
                'description' => 'Direct conflict with force login functionality'
            ],
            'login-required/login-required.php' => [
                'name' => 'Login Required',
                'severity' => 'high',
                'description' => 'Direct conflict with force login functionality'
            ],
            'jonradio-private-site/jonradio-private-site.php' => [
                'name' => 'Private Site',
                'severity' => 'medium',
                'description' => 'May conflict with site-wide login requirements'
            ],
            'members/members.php' => [
                'name' => 'Members',
                'severity' => 'low',
                'description' => 'May have permission conflicts'
            ]
        ];
        
        foreach ($conflicting_plugins as $plugin_file => $info) {
            if (is_plugin_active($plugin_file)) {
                $conflicts[] = [
                    'type' => 'plugin',
                    'plugin_file' => $plugin_file,
                    'name' => $info['name'],
                    'severity' => $info['severity'],
                    'description' => $info['description']
                ];
            }
        }
        
        return $conflicts;
    }
    
    /**
     * Check for settings conflicts
     */
    private static function check_settings_conflicts() {
        $conflicts = [];
        
        $security_options = get_option('wallet_up_security_options', []);
        
        // Check if hiding wp-login without custom slug
        if (!empty($security_options['hide_wp_login']) && 
            empty($security_options['custom_login_slug'])) {
            $conflicts[] = [
                'type' => 'settings',
                'name' => 'Missing Custom Login Slug',
                'severity' => 'critical',
                'description' => __('Hiding wp-login.php without a custom login slug will lock out all users', 'wallet-up-login'),
                'fix' => 'set_custom_slug'
            ];
        }
        
        // REMOVED: IP whitelist warning - not needed for everyday users
        // Force login works intelligently without IP restrictions
        
        return $conflicts;
    }
    
    /**
     * Display conflict notices in admin
     */
    public static function display_conflict_notices() {
        $conflicts = get_transient('wallet_up_conflicts');
        
        if (empty($conflicts)) {
            return;
        }
        
        $current_screen = get_current_screen();
        if (!$current_screen || !current_user_can('manage_options')) {
            return;
        }
        
        foreach ($conflicts as $category => $category_conflicts) {
            foreach ($category_conflicts as $conflict) {
                $class = 'notice notice-' . self::get_notice_class($conflict['severity']);
                ?>
                <div class="<?php echo esc_attr($class); ?> is-dismissible">
                    <h4><?php _e('Wallet Up Conflict Detected', 'wallet-up-login'); ?></h4>
                    <p>
                        <strong><?php echo esc_html($conflict['name']); ?></strong><br>
                        <?php echo esc_html($conflict['description']); ?>
                    </p>
                    
                    <?php if (isset($conflict['fix'])): ?>
                        <p>
                            <button type="button" class="button button-primary wallet-up-fix-conflict" 
                                    data-fix="<?php echo esc_attr($conflict['fix']); ?>"
                                    data-nonce="<?php echo wp_create_nonce('wallet_up_fix_conflict'); ?>">
                                <?php _e('Auto-Fix This Issue', 'wallet-up-login'); ?>
                            </button>
                        </p>
                    <?php endif; ?>
                    
                    <?php if ($conflict['type'] === 'function'): ?>
                        <p>
                            <strong><?php _e('Manual Fix:', 'wallet-up-login'); ?></strong>
                            <?php _e('Remove the conflicting function from your theme\'s functions.php file.', 'wallet-up-login'); ?>
                        </p>
                    <?php elseif ($conflict['type'] === 'plugin'): ?>
                        <p>
                            <strong><?php _e('Manual Fix:', 'wallet-up-login'); ?></strong>
                            <?php printf(__('Deactivate the %s plugin or disable its login features.', 'wallet-up-login'), esc_html($conflict['name'])); ?>
                        </p>
                    <?php endif; ?>
                </div>
                
                <script>
                jQuery(document).ready(function($) {
                    $('.wallet-up-fix-conflict').on('click', function() {
                        var $btn = $(this);
                        var fix = $btn.data('fix');
                        var nonce = $btn.data('nonce');
                        
                        $btn.prop('disabled', true).text('Fixing...');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wallet_up_resolve_conflict',
                                fix: fix,
                                nonce: nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    $btn.closest('.notice').fadeOut();
                                    location.reload();
                                } else {
                                    alert('Fix failed: ' + response.data.message);
                                }
                            },
                            error: function() {
                                alert('Fix failed: Network error');
                            },
                            complete: function() {
                                $btn.prop('disabled', false).text('Auto-Fix This Issue');
                            }
                        });
                    });
                });
                </script>
                <?php
            }
        }
    }
    
    /**
     * Get notice class based on severity
     */
    private static function get_notice_class($severity) {
        switch ($severity) {
            case 'critical':
                return 'error';
            case 'high':
                return 'error';
            case 'medium':
                return 'warning';
            case 'low':
                return 'info';
            default:
                return 'warning';
        }
    }
    
    /**
     * AJAX handler for conflict resolution
     */
    public static function resolve_conflict() {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wallet_up_fix_conflict') || 
            !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Security check failed', 'wallet-up-login')]);
        }
        
        $fix = sanitize_text_field($_POST['fix'] ?? '');
        
        try {
            switch ($fix) {
                case 'set_custom_slug':
                    $options = get_option('wallet_up_security_options', []);
                    $options['custom_login_slug'] = 'secure-login-' . wp_generate_password(6, false);
                    $options['hide_wp_login'] = false; // Disable until slug is tested
                    update_option('wallet_up_security_options', $options);
                    
                    wp_send_json_success([
                        'message' => __('Custom login slug created. Please test it before enabling wp-login.php hiding.', 'wallet-up-login')
                    ]);
                    break;
                    
                case 'add_current_ip':
                    $options = get_option('wallet_up_security_options', []);
                    $current_ip = self::get_client_ip();
                    if (!in_array($current_ip, $options['whitelist_ips'] ?? [])) {
                        $options['whitelist_ips'][] = $current_ip;
                        update_option('wallet_up_security_options', $options);
                    }
                    
                    wp_send_json_success([
                        'message' => sprintf(__('Your IP (%s) added to whitelist.', 'wallet-up-login'), $current_ip)
                    ]);
                    break;
                    
                default:
                    wp_send_json_error(['message' => __('Unknown fix type', 'wallet-up-login')]);
            }
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Get client IP address
     */
    private static function get_client_ip() {
        $ip_headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($ip_headers as $header) {
            $ip = self::get_server_var($header);
            if (!empty($ip)) {
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return self::get_server_var('REMOTE_ADDR', '127.0.0.1');
    }
    
    /**
     * Get conflict summary for settings page
     */
    public static function get_conflict_summary() {
        $conflicts = get_transient('wallet_up_conflicts');
        
        if (empty($conflicts)) {
            return [
                'status' => 'clean',
                'message' => __('No conflicts detected', 'wallet-up-login'),
                'count' => 0
            ];
        }
        
        $total = 0;
        $critical = 0;
        
        foreach ($conflicts as $category_conflicts) {
            foreach ($category_conflicts as $conflict) {
                $total++;
                if ($conflict['severity'] === 'critical') {
                    $critical++;
                }
            }
        }
        
        return [
            'status' => $critical > 0 ? 'critical' : 'warning',
            'message' => sprintf(_n('%d conflict detected', '%d conflicts detected', $total, 'wallet-up-login'), $total),
            'count' => $total,
            'critical' => $critical
        ];
    }
}