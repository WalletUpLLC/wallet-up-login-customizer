<?php
/**
 * Wallet Up Safe Activation System
 * 
 * Ensures safe plugin activation with user guidance and recovery options
 * 
 * @package WalletUpLogin
 * @since 2.3.2
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WalletUpSafeActivation {
    
    /**
     * Plugin activation hook
     */
    public static function activate() {
        // Create completely safe default settings
        $safe_defaults = [
            'force_login_enabled' => false,
            'hide_wp_login' => false,
            'custom_login_slug' => '',
            'max_login_attempts' => 5,
            'lockout_duration' => 900,
            'session_timeout' => 3600,
            'security_headers' => false,
            'whitelist_ips' => [],
            'exempt_roles' => ['administrator'],
            'activation_wizard_completed' => false
        ];
        
        // Only set if options don't exist
        if (false === get_option('wallet_up_security_options')) {
            update_option('wallet_up_security_options', $safe_defaults);
        }
        
        // Set activation flag for first-time setup
        set_transient('wallet_up_show_setup_wizard', true, 300);
        
        // Create emergency recovery page
        self::create_emergency_recovery_page();
        
        // Log safe activation
        error_log('Wallet Up: Plugin activated safely with all security features disabled');
    }
    
    /**
     * Plugin deactivation hook
     */
    public static function deactivate() {
        // Clear all transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wallet_up%'");
        
        // Remove emergency recovery page
        $recovery_page = get_page_by_path('wallet-up-emergency-recovery');
        if ($recovery_page) {
            wp_delete_post($recovery_page->ID, true);
        }
        
        // Log deactivation
        error_log('Wallet Up: Plugin deactivated safely');
    }
    
    /**
     * Create emergency recovery page
     */
    private static function create_emergency_recovery_page() {
        // Check if page already exists
        $existing_page = get_page_by_path('wallet-up-emergency-recovery');
        if ($existing_page) {
            return;
        }
        
        $recovery_content = '
        <h1>🚨 Wallet Up Emergency Recovery</h1>
        
        <div style="background: #f8d7da; color: #721c24; padding: 20px; border: 1px solid #f5c6cb; border-radius: 4px; margin: 20px 0;">
            <h3>Locked Out of Your Site?</h3>
            <p>If you\'re seeing this page, you may have been locked out by Wallet Up security settings.</p>
        </div>
        
        <h3>🔧 Emergency Recovery Options:</h3>
        
        <h4>Method 1: Disable Via wp-config.php</h4>
        <p>Add this line to your wp-config.php file:</p>
        <code style="background: #f1f1f1; padding: 10px; display: block;">
        define(\'WALLET_UP_EMERGENCY_DISABLE\', true);
        </code>
        
        <h4>Method 2: Rename Plugin Folder</h4>
        <p>Via FTP or File Manager, rename:</p>
        <ul>
            <li>From: <code>/wp-content/plugins/wallet-up-login-customizer/</code></li>
            <li>To: <code>/wp-content/plugins/wallet-up-login-customizer-disabled/</code></li>
        </ul>
        
        <h4>Method 3: Database Reset</h4>
        <p>Run this SQL query in phpMyAdmin:</p>
        <code style="background: #f1f1f1; padding: 10px; display: block;">
        DELETE FROM wp_options WHERE option_name LIKE \'wallet_up_%\';
        </code>
        
        <div style="background: #d1ecf1; color: #0c5460; padding: 15px; border: 1px solid #bee5eb; border-radius: 4px; margin: 20px 0;">
            <h4>📞 Support</h4>
            <p>If you need help, please contact support with the error details and which method you used.</p>
        </div>
        
        <hr>
        <p><small>This page was created automatically by Wallet Up Login for emergency recovery.</small></p>
        ';
        
        wp_insert_post([
            'post_title' => 'Wallet Up Emergency Recovery',
            'post_name' => 'wallet-up-emergency-recovery',
            'post_content' => $recovery_content,
            'post_status' => 'publish',
            'post_type' => 'page',
            'comment_status' => 'closed',
            'ping_status' => 'closed'
        ]);
    }
    
    /**
     * Show setup wizard on first activation
     */
    public static function show_setup_wizard() {
        if (!get_transient('wallet_up_show_setup_wizard')) {
            return;
        }
        
        // Only show to admins
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Clear the transient
        delete_transient('wallet_up_show_setup_wizard');
        
        ?>
        <div class="notice notice-info is-dismissible" style="border-left-color: #674FBF;">
            <div style="display: flex; align-items: center; padding: 10px 0;">
                <div style="font-size: 30px; margin-right: 15px;">🛡️</div>
                <div>
                    <h3 style="margin: 0 0 10px 0;">Welcome to Wallet Up Login!</h3>
                    <p style="margin: 0 0 10px 0;"><strong>All security features are currently DISABLED for safety.</strong></p>
                    <p style="margin: 0;">
                        <a href="<?php echo admin_url('options-general.php?page=wallet-up-login'); ?>" class="button button-primary">
                            🚀 Configure Security Settings
                        </a>
                    </p>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Auto-focus on security settings if user clicks configure
            $('.notice .button-primary').on('click', function() {
                $(this).text('Loading...').prop('disabled', true);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Add safety warnings to security settings page
     */
    public static function add_safety_warnings() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_wallet-up-security') {
            return;
        }
        
        ?>
        <div class="notice notice-warning">
            <h4>⚠️ Important Safety Information</h4>
            <ul>
                <li><strong>Test each feature individually</strong> before enabling multiple security options</li>
                <li><strong>Add your IP to the whitelist</strong> before enabling Force Login</li>
                <li><strong>Set up a custom login URL</strong> before hiding wp-login.php</li>
                <li><strong>Keep the emergency recovery page bookmarked:</strong> 
                    <a href="<?php echo home_url('/wallet-up-emergency-recovery/'); ?>" target="_blank">
                        <?php echo home_url('/wallet-up-emergency-recovery/'); ?>
                    </a>
                </li>
            </ul>
            <p><strong>Emergency Disable:</strong> Add <code>define('WALLET_UP_EMERGENCY_DISABLE', true);</code> to wp-config.php if locked out.</p>
        </div>
        <?php
    }
    
    /**
     * Add emergency information to plugin row
     */
    public static function add_plugin_row_meta($links, $file) {
        if (plugin_basename(WALLET_UP_LOGIN_PLUGIN_FILE) === $file) {
            $emergency_link = sprintf(
                '<a href="%s" target="_blank" style="color: #d63384; font-weight: bold;">🚨 Emergency Recovery</a>',
                home_url('/wallet-up-emergency-recovery/')
            );
            $links[] = $emergency_link;
        }
        return $links;
    }
    
    /**
     * Check for emergency disable flag
     */
    public static function is_emergency_disabled() {
        return defined('WALLET_UP_EMERGENCY_DISABLE') && WALLET_UP_EMERGENCY_DISABLE;
    }
    
    /**
     * Show emergency disabled notice
     */
    public static function show_emergency_disabled_notice() {
        if (!self::is_emergency_disabled()) {
            return;
        }
        
        ?>
        <div class="notice notice-error">
            <h4>🚨 Wallet Up Emergency Mode Active</h4>
            <p><strong>All Wallet Up security features are currently DISABLED</strong> due to the emergency flag in wp-config.php.</p>
            <p>To re-enable the plugin, remove this line from wp-config.php: <code>define('WALLET_UP_EMERGENCY_DISABLE', true);</code></p>
        </div>
        <?php
    }
    
    /**
     * Initialize safe activation system
     */
    public static function init() {
        // Show setup wizard
        add_action('admin_notices', [__CLASS__, 'show_setup_wizard']);
        
        // Add safety warnings
        add_action('admin_notices', [__CLASS__, 'add_safety_warnings']);
        
        // Show emergency disabled notice
        add_action('admin_notices', [__CLASS__, 'show_emergency_disabled_notice']);
        
        // Add emergency link to plugin page
        add_filter('plugin_row_meta', [__CLASS__, 'add_plugin_row_meta'], 10, 2);
    }
}