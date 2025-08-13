<?php
/**
 * Wallet Up Admin Synchronization
 * 
 * Handles timing synchronization for admin updates and settings
 * 
 * @package WalletUpLogin
 * @since 2.3.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WalletUpAdminSync {
    
    /**
     * Sync queue for admin updates
     * @var array
     */
    private static $sync_queue = [];
    
    /**
     * Initialization flag
     * @var bool
     */
    private static $initialized = false;
    
    /**
     * Initialize admin synchronization
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }
        
        // Hook into admin actions
        add_action('admin_init', [__CLASS__, 'process_sync_queue']);
        add_action('wp_ajax_wallet_up_sync_settings', [__CLASS__, 'handle_ajax_sync']);
        
        // Hook into settings updates
        add_action('update_option_wallet_up_login_options', [__CLASS__, 'handle_options_update'], 10, 3);
        add_action('update_option_wallet_up_security_options', [__CLASS__, 'handle_security_options_update'], 10, 3);
        
        // Clear caches when settings change
        add_action('update_option_wallet_up_login_options', [__CLASS__, 'clear_related_caches']);
        add_action('update_option_wallet_up_security_options', [__CLASS__, 'clear_related_caches']);
        
        // Add version check for updates
        add_action('admin_notices', [__CLASS__, 'check_version_sync']);
        
        // Schedule regular sync checks
        if (!wp_next_scheduled('wallet_up_sync_check')) {
            wp_schedule_event(time(), 'hourly', 'wallet_up_sync_check');
        }
        add_action('wallet_up_sync_check', [__CLASS__, 'scheduled_sync_check']);
        
        self::$initialized = true;
    }
    
    /**
     * Handle main login options update
     */
    public static function handle_options_update($old_value, $value, $option) {
        // Add to sync queue
        self::add_to_sync_queue('login_options', [
            'old_value' => $old_value,
            'new_value' => $value,
            'timestamp' => current_time('timestamp')
        ]);
        
        // Check for critical changes that need immediate sync
        $critical_changes = [
            'redirect_to_wallet_up',
            'force_dashboard_replacement',
            'login_customization_enabled'
        ];
        
        $needs_immediate_sync = false;
        foreach ($critical_changes as $key) {
            if (isset($old_value[$key]) && isset($value[$key]) && 
                $old_value[$key] !== $value[$key]) {
                $needs_immediate_sync = true;
                break;
            }
        }
        
        if ($needs_immediate_sync) {
            self::immediate_sync('login_options');
        }
        
        // Update timestamp for last sync
        update_option('wallet_up_last_sync', current_time('timestamp'));
    }
    
    /**
     * Handle security options update
     */
    public static function handle_security_options_update($old_value, $value, $option) {
        // Add to sync queue
        self::add_to_sync_queue('security_options', [
            'old_value' => $old_value,
            'new_value' => $value,
            'timestamp' => current_time('timestamp')
        ]);
        
        // Security changes always need immediate sync
        self::immediate_sync('security_options');
        
        // Log security setting changes
        error_log(sprintf(
            'Wallet Up: Security settings updated at %s',
            current_time('mysql')
        ));
    }
    
    /**
     * Add item to sync queue
     */
    private static function add_to_sync_queue($type, $data) {
        $queue_key = 'wallet_up_sync_queue';
        $queue = get_option($queue_key, []);
        
        $queue[$type . '_' . time()] = [
            'type' => $type,
            'data' => $data,
            'status' => 'pending',
            'created_at' => current_time('timestamp')
        ];
        
        // Keep only last 50 items
        if (count($queue) > 50) {
            $queue = array_slice($queue, -50, 50, true);
        }
        
        update_option($queue_key, $queue);
    }
    
    /**
     * Process sync queue
     */
    public static function process_sync_queue() {
        $queue = get_option('wallet_up_sync_queue', []);
        
        if (empty($queue)) {
            return;
        }
        
        $processed = [];
        foreach ($queue as $key => $item) {
            if ($item['status'] === 'pending') {
                try {
                    self::process_sync_item($item);
                    $queue[$key]['status'] = 'completed';
                    $queue[$key]['completed_at'] = current_time('timestamp');
                    $processed[] = $key;
                } catch (Exception $e) {
                    $queue[$key]['status'] = 'failed';
                    $queue[$key]['error'] = $e->getMessage();
                    error_log('Wallet Up Sync Error: ' . $e->getMessage());
                }
            }
        }
        
        if (!empty($processed)) {
            update_option('wallet_up_sync_queue', $queue);
        }
    }
    
    /**
     * Process individual sync item
     */
    private static function process_sync_item($item) {
        switch ($item['type']) {
            case 'login_options':
                self::sync_login_options($item['data']);
                break;
                
            case 'security_options':
                self::sync_security_options($item['data']);
                break;
                
            default:
                throw new Exception('Unknown sync type: ' . $item['type']);
        }
    }
    
    /**
     * Sync login options
     */
    private static function sync_login_options($data) {
        $new_value = $data['new_value'];
        $old_value = $data['old_value'];
        
        // Clear related transients
        delete_transient('wallet_up_login_config');
        delete_transient('wallet_up_custom_css');
        delete_transient('wallet_up_custom_js');
        
        // Regenerate CSS if styling options changed
        $style_keys = ['custom_css', 'color_scheme', 'logo_url', 'background_image'];
        $style_changed = false;
        
        foreach ($style_keys as $key) {
            if (isset($old_value[$key]) && isset($new_value[$key]) && 
                $old_value[$key] !== $new_value[$key]) {
                $style_changed = true;
                break;
            }
        }
        
        if ($style_changed) {
            self::regenerate_styles();
        }
        
        // Update related components
        if (isset($new_value['redirect_to_wallet_up']) && 
            $new_value['redirect_to_wallet_up'] !== ($old_value['redirect_to_wallet_up'] ?? false)) {
            
            // Force redirect system re-initialization
            if (class_exists('WalletUpHardRedirect')) {
                // Clear any cached redirect states
                wp_cache_delete('wallet_up_redirect_active', 'wallet_up');
            }
        }
        
        // Trigger action for other components
        do_action('wallet_up_login_options_synced', $new_value, $old_value);
    }
    
    /**
     * Sync security options
     */
    private static function sync_security_options($data) {
        $new_value = $data['new_value'];
        $old_value = $data['old_value'];
        
        // Clear security-related caches
        delete_transient('wallet_up_security_config');
        wp_cache_delete('wallet_up_security_state', 'wallet_up');
        
        // Rewrite rules if login URL changed
        if (isset($new_value['custom_login_slug']) && 
            $new_value['custom_login_slug'] !== ($old_value['custom_login_slug'] ?? '')) {
            
            // Flush rewrite rules
            add_action('shutdown', 'flush_rewrite_rules');
        }
        
        // Re-initialize security system if force login changed
        if (isset($new_value['force_login_enabled']) && 
            $new_value['force_login_enabled'] !== ($old_value['force_login_enabled'] ?? false)) {
            
            if (class_exists('WalletUpEnterpriseSecurity')) {
                // Schedule re-init on next request
                set_transient('wallet_up_security_reinit', true, 60);
            }
        }
        
        // Trigger action for other security components
        do_action('wallet_up_security_options_synced', $new_value, $old_value);
    }
    
    /**
     * Immediate sync for critical changes
     */
    private static function immediate_sync($type) {
        // Process sync queue immediately for this type
        $queue = get_option('wallet_up_sync_queue', []);
        
        foreach ($queue as $key => $item) {
            if ($item['type'] === $type && $item['status'] === 'pending') {
                try {
                    self::process_sync_item($item);
                    $queue[$key]['status'] = 'completed';
                    $queue[$key]['completed_at'] = current_time('timestamp');
                } catch (Exception $e) {
                    $queue[$key]['status'] = 'failed';
                    $queue[$key]['error'] = $e->getMessage();
                }
            }
        }
        
        update_option('wallet_up_sync_queue', $queue);
    }
    
    /**
     * Clear related caches
     */
    public static function clear_related_caches() {
        // Clear WordPress object cache
        wp_cache_flush();
        
        // Clear specific transients
        $transients = [
            'wallet_up_login_config',
            'wallet_up_security_config',
            'wallet_up_custom_css',
            'wallet_up_custom_js',
            'wallet_up_admin_config'
        ];
        
        foreach ($transients as $transient) {
            delete_transient($transient);
            delete_site_transient($transient);
        }
        
        // Clear any external caching
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
        
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        
        if (function_exists('wp_rocket_clean_domain')) {
            wp_rocket_clean_domain();
        }
    }
    
    /**
     * Regenerate styles
     */
    private static function regenerate_styles() {
        // Clear style cache
        delete_transient('wallet_up_compiled_css');
        
        // Trigger style regeneration on next login page load
        set_transient('wallet_up_regenerate_styles', true, 300);
    }
    
    /**
     * Check version synchronization
     */
    public static function check_version_sync() {
        $current_version = get_option('wallet_up_version', '0.0.0');
        $plugin_version = defined('WALLET_UP_LOGIN_VERSION') ? WALLET_UP_LOGIN_VERSION : '2.3.0';
        
        if (version_compare($current_version, $plugin_version, '<')) {
            // Version mismatch - need sync
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p>' . sprintf(
                __('Wallet Up Login has been updated to version %s. Settings synchronization in progress...', 'wallet-up-login'),
                esc_html($plugin_version)
            ) . '</p>';
            echo '</div>';
            
            // Update version and trigger sync
            update_option('wallet_up_version', $plugin_version);
            self::version_upgrade_sync($current_version, $plugin_version);
        }
    }
    
    /**
     * Handle version upgrade sync
     */
    private static function version_upgrade_sync($old_version, $new_version) {
        // Clear all caches on version upgrade
        self::clear_related_caches();
        
        // Specific upgrade tasks
        if (version_compare($old_version, '2.3.0', '<')) {
            // Upgrade to 2.3.0 - new security features
            self::upgrade_to_230();
        }
        
        // Set upgrade flag
        set_transient('wallet_up_upgraded', true, 300);
        
        // Log upgrade
        error_log(sprintf(
            'Wallet Up Login upgraded from %s to %s',
            $old_version,
            $new_version
        ));
    }
    
    /**
     * Upgrade to version 2.3.0
     */
    private static function upgrade_to_230() {
        // Initialize security options if they don't exist
        $security_options = get_option('wallet_up_security_options');
        if (false === $security_options) {
            $default_security_options = [
                'force_login_enabled' => false,
                'hide_wp_login' => false,
                'custom_login_slug' => 'secure-login',
                'max_login_attempts' => 5,
                'lockout_duration' => 900,
                'session_timeout' => 3600,
                'security_headers' => true,
                'whitelist_ips' => []
            ];
            
            update_option('wallet_up_security_options', $default_security_options);
        }
        
        // Flush rewrite rules for new security features
        flush_rewrite_rules();
    }
    
    /**
     * Handle AJAX sync request
     */
    public static function handle_ajax_sync() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wallet_up_admin_sync')) {
            wp_die(__('Security check failed.', 'wallet-up-login'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wallet-up-login'));
        }
        
        $sync_type = sanitize_text_field($_POST['sync_type'] ?? 'all');
        
        try {
            switch ($sync_type) {
                case 'login_options':
                    self::immediate_sync('login_options');
                    break;
                    
                case 'security_options':
                    self::immediate_sync('security_options');
                    break;
                    
                case 'clear_cache':
                    self::clear_related_caches();
                    break;
                    
                case 'all':
                default:
                    self::process_sync_queue();
                    self::clear_related_caches();
                    break;
            }
            
            wp_send_json_success([
                'message' => __('Synchronization completed successfully.', 'wallet-up-login')
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => sprintf(__('Sync failed: %s', 'wallet-up-login'), $e->getMessage())
            ]);
        }
    }
    
    /**
     * Scheduled sync check
     */
    public static function scheduled_sync_check() {
        // Process any pending items in queue
        self::process_sync_queue();
        
        // Clean up old queue items
        $queue = get_option('wallet_up_sync_queue', []);
        $cutoff = current_time('timestamp') - (7 * DAY_IN_SECONDS); // 7 days
        
        $cleaned_queue = array_filter($queue, function($item) use ($cutoff) {
            return $item['created_at'] > $cutoff;
        });
        
        if (count($cleaned_queue) !== count($queue)) {
            update_option('wallet_up_sync_queue', $cleaned_queue);
        }
    }
    
    /**
     * Get sync status for admin display
     */
    public static function get_sync_status() {
        $queue = get_option('wallet_up_sync_queue', []);
        $last_sync = get_option('wallet_up_last_sync', 0);
        
        $pending = array_filter($queue, function($item) {
            return $item['status'] === 'pending';
        });
        
        $failed = array_filter($queue, function($item) {
            return $item['status'] === 'failed';
        });
        
        return [
            'pending_count' => count($pending),
            'failed_count' => count($failed),
            'last_sync' => $last_sync,
            'last_sync_human' => $last_sync ? human_time_diff($last_sync, current_time('timestamp')) . ' ago' : 'Never',
            'queue_size' => count($queue)
        ];
    }
    
    /**
     * Add sync status to admin footer
     */
    public static function admin_footer_sync_status() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $status = self::get_sync_status();
        
        if ($status['pending_count'] > 0 || $status['failed_count'] > 0) {
            echo '<div id="wallet-up-sync-status" style="position: fixed; bottom: 10px; right: 10px; background: #fff; border: 1px solid #ccd0d4; padding: 5px 10px; border-radius: 3px; font-size: 12px; z-index: 9999;">';
            echo '<strong>Wallet Up Sync:</strong> ';
            
            if ($status['pending_count'] > 0) {
                echo sprintf(_n('%d pending', '%d pending', $status['pending_count'], 'wallet-up-login'), $status['pending_count']);
            }
            
            if ($status['failed_count'] > 0) {
                if ($status['pending_count'] > 0) echo ', ';
                echo sprintf(_n('%d failed', '%d failed', $status['failed_count'], 'wallet-up-login'), $status['failed_count']);
            }
            
            echo '</div>';
        }
    }
}