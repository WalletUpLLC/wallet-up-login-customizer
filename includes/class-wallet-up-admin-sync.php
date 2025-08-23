<?php
/**
 * Wallet Up Admin Synchronization
 * 
 * Handles timing synchronization for admin updates and settings
 * 
 * @package WalletUpLogin
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WalletUpAdminSync {

    private static $sync_queue = [];

    private static $initialized = false;

    public static function init() {
        if (self::$initialized) {
            return;
        }

        add_action('admin_init', [__CLASS__, 'process_sync_queue']);
        add_action('wp_ajax_wallet_up_sync_settings', [__CLASS__, 'handle_ajax_sync']);

        add_action('update_option_wallet_up_login_customizer_options', [__CLASS__, 'handle_options_update'], 10, 3);
        add_action('update_option_wallet_up_login_customizer_security_options', [__CLASS__, 'handle_security_options_update'], 10, 3);

        add_action('update_option_wallet_up_login_customizer_options', [__CLASS__, 'clear_related_caches']);
        add_action('update_option_wallet_up_login_customizer_security_options', [__CLASS__, 'clear_related_caches']);

        add_action('admin_notices', [__CLASS__, 'check_version_sync']);

        if (!wp_next_scheduled('wallet_up_sync_check')) {
            wp_schedule_event(time(), 'hourly', 'wallet_up_sync_check');
        }
        add_action('wallet_up_sync_check', [__CLASS__, 'scheduled_sync_check']);
        
        self::$initialized = true;
    }

    public static function handle_options_update($old_value, $value, $option) {
        
        self::add_to_sync_queue('login_options', [
            'old_value' => $old_value,
            'new_value' => $value,
            'timestamp' => current_time('timestamp')
        ]);

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

        update_option('wallet_up_last_sync', current_time('timestamp'));
    }

    public static function handle_security_options_update($old_value, $value, $option) {
        
        self::add_to_sync_queue('security_options', [
            'old_value' => $old_value,
            'new_value' => $value,
            'timestamp' => current_time('timestamp')
        ]);

        self::immediate_sync('security_options');

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                'Wallet Up: Security settings updated at %s',
                current_time('mysql')
            ));
        }
    }

    private static function add_to_sync_queue($type, $data) {
        $queue_key = 'wallet_up_sync_queue';
        $queue = get_option($queue_key, []);
        
        $queue[$type . '_' . time()] = [
            'type' => $type,
            'data' => $data,
            'status' => 'pending',
            'created_at' => current_time('timestamp')
        ];

        if (count($queue) > 50) {
            $queue = array_slice($queue, -50, 50, true);
        }
        
        update_option($queue_key, $queue);
    }

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
                    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                        error_log('Wallet Up Sync Error: ' . $e->getMessage());
                    }
                }
            }
        }
        
        if (!empty($processed)) {
            update_option('wallet_up_sync_queue', $queue);
        }
    }

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

    private static function sync_login_options($data) {
        $new_value = $data['new_value'];
        $old_value = $data['old_value'];

        delete_transient('wallet_up_login_customizer_config');
        delete_transient('wallet_up_custom_css');
        delete_transient('wallet_up_custom_js');

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

        if (isset($new_value['redirect_to_wallet_up']) && 
            $new_value['redirect_to_wallet_up'] !== ($old_value['redirect_to_wallet_up'] ?? false)) {

            if (class_exists('WalletUpHardRedirect')) {
                
                wp_cache_delete('wallet_up_redirect_active', 'wallet_up');
            }
        }

        do_action('wallet_up_login_customizer_options_synced', $new_value, $old_value);
    }

    private static function sync_security_options($data) {
        $new_value = $data['new_value'];
        $old_value = $data['old_value'];

        delete_transient('wallet_up_security_config');
        wp_cache_delete('wallet_up_security_state', 'wallet_up');

        if (isset($new_value['custom_login_slug']) && 
            $new_value['custom_login_slug'] !== ($old_value['custom_login_slug'] ?? '')) {

            add_action('shutdown', 'flush_rewrite_rules');
        }

        if (isset($new_value['force_login_enabled']) && 
            $new_value['force_login_enabled'] !== ($old_value['force_login_enabled'] ?? false)) {
            
            if (class_exists('WalletUpEnterpriseSecurity')) {
                
                set_transient('wallet_up_security_reinit', true, 60);
            }
        }

        do_action('wallet_up_login_customizer_security_options_synced', $new_value, $old_value);
    }

    private static function immediate_sync($type) {
        
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

    public static function clear_related_caches() {
        
        wp_cache_flush();

        $transients = [
            'wallet_up_login_customizer_config',
            'wallet_up_security_config',
            'wallet_up_custom_css',
            'wallet_up_custom_js',
            'wallet_up_admin_config'
        ];
        
        foreach ($transients as $transient) {
            delete_transient($transient);
            delete_site_transient($transient);
        }

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

    private static function regenerate_styles() {
        
        delete_transient('wallet_up_compiled_css');

        set_transient('wallet_up_regenerate_styles', true, 300);
    }

    public static function check_version_sync() {
        $current_version = get_option('wallet_up_version', '0.0.0');
        $plugin_version = defined('WALLET_UP_LOGIN_CUSTOMIZER_VERSION') ? WALLET_UP_LOGIN_CUSTOMIZER_VERSION : '2.3.0';
        
        if (version_compare($current_version, $plugin_version, '<')) {
            
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p>' . sprintf(
                esc_html__('Wallet Up Login has been updated to version %s. Settings synchronization in progress...', 'wallet-up-login-customizer'),
                esc_html($plugin_version)
            ) . '</p>';
            echo '</div>';

            update_option('wallet_up_version', $plugin_version);
            self::version_upgrade_sync($current_version, $plugin_version);
        }
    }

    private static function version_upgrade_sync($old_version, $new_version) {
        
        self::clear_related_caches();

        if (version_compare($old_version, '2.3.0', '<')) {
            
            self::upgrade_to_230();
        }

        set_transient('wallet_up_upgraded', true, 300);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                'Wallet Up Login upgraded from %s to %s',
                $old_version,
                $new_version
            ));
        }
    }

    private static function upgrade_to_230() {
        
        $security_options = get_option('wallet_up_login_customizer_security_options');
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
            
            update_option('wallet_up_login_customizer_security_options', $default_security_options);
        }

        flush_rewrite_rules();
    }

    public static function handle_ajax_sync() {
        
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'wallet_up_admin_sync')) {
            wp_die(__('Security check failed.', 'wallet-up-login-customizer'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wallet-up-login-customizer'));
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
                'message' => __('Synchronization completed successfully.', 'wallet-up-login-customizer')
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => sprintf(__('Sync failed: %s', 'wallet-up-login-customizer'), $e->getMessage())
            ]);
        }
    }

    public static function scheduled_sync_check() {
        
        self::process_sync_queue();

        $queue = get_option('wallet_up_sync_queue', []);
        $cutoff = current_time('timestamp') - (7 * DAY_IN_SECONDS); 
        
        $cleaned_queue = array_filter($queue, function($item) use ($cutoff) {
            return $item['created_at'] > $cutoff;
        });
        
        if (count($cleaned_queue) !== count($queue)) {
            update_option('wallet_up_sync_queue', $cleaned_queue);
        }
    }

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

    public static function admin_footer_sync_status() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $status = self::get_sync_status();
        
        if ($status['pending_count'] > 0 || $status['failed_count'] > 0) {
            echo '<div id="wallet-up-sync-status" style="position: fixed; bottom: 10px; right: 10px; background: #fff; border: 1px solid #ccd0d4; padding: 5px 10px; border-radius: 3px; font-size: 12px; z-index: 9999;">';
            echo '<strong>Wallet Up Sync:</strong> ';
            
            if ($status['pending_count'] > 0) {
                echo sprintf(_n('%d pending', '%d pending', $status['pending_count'], 'wallet-up-login-customizer'), $status['pending_count']);
            }
            
            if ($status['failed_count'] > 0) {
                if ($status['pending_count'] > 0) echo ', ';
                echo sprintf(_n('%d failed', '%d failed', $status['failed_count'], 'wallet-up-login-customizer'), $status['failed_count']);
            }
            
            echo '</div>';
        }
    }
}