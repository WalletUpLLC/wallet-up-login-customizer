<?php
/**
 * Plugin Name: Wallet Up Premium Login Customizer
 * Description: Creates a beautiful interactive login experience for Wallet Up and Wordpress users
 * Version: 2.3.6
 * Author: Wallet Up
 * Author URI: https://walletup.app
 * Text Domain: wallet-up-login-customizer
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Early AJAX handler to prevent wallet-up-pro conflicts
add_action('init', function() {
    if (defined('DOING_AJAX') && DOING_AJAX && 
        isset($_REQUEST['action']) && 
        (sanitize_text_field($_REQUEST['action']) === 'wallet_up_ajax_login' || sanitize_text_field($_REQUEST['action']) === 'wallet_up_validate_username')) {
        
        // Prevent wallet-up-pro's EnhancedSecurityManager from interfering
        if (class_exists('WalletUpPro\\Core\\Security\\EnhancedSecurityManager')) {
            remove_all_filters('authenticate', 10);
            // Re-add WordPress default authentication
            add_filter('authenticate', 'wp_authenticate_username_password', 20, 3);
            add_filter('authenticate', 'wp_authenticate_cookie', 30, 3);
            add_filter('authenticate', 'wp_authenticate_spam_check', 99, 3);
        }
    }
}, 1);

/**
 * Main plugin class
 */
class WalletUpLogin {
    
    /**
     * Plugin version
     * @var string
     */
    const VERSION = '2.3.7';
    
    /**
     * Plugin singleton instance
     * @var WalletUpLogin
     */
    private static $instance = null;
    
    /**
     * Instance of the login customizer
     * @var WalletUpLoginCustomizer
     */
    private $login_customizer = null;
    
    /**
     * Constructor
     */
    private function __construct() {
        try {
            // Define plugin constants
            $this->define_constants();
            
            // Register activation, deactivation and uninstall hooks with error handling
            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));
            
            // SECURITY: Remove manual session handling
            // WordPress manages sessions through cookies, not PHP sessions
            // Manual session handling can cause security issues
            
            // Initialize the plugin
            add_action('plugins_loaded', array($this, 'init'), 10);
            
            // Add admin notices for security status
            add_action('admin_notices', array($this, 'security_status_notice'));
            
            // Multisite: Handle new site creation
            if (is_multisite()) {
                add_action('wp_initialize_site', array($this, 'new_site_activation'), 10, 2);
            }
            
        } catch (Exception $e) {
            // Log error but don't break WordPress
            error_log('Wallet Up Login Constructor Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get the singleton instance
     * 
     * @return WalletUpLogin Instance of the plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Define plugin constants
     */
    private function define_constants() {
        define('WALLET_UP_LOGIN_CUSTOMIZER_VERSION', self::VERSION);
        define('WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL', plugin_dir_url(__FILE__));
        define('WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_FILE', __FILE__);
        define('WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_BASENAME', plugin_basename(__FILE__));
    }
    
    /**
     * Initialize session management to prevent REST API interference
     */
    private function init_session_management() {
        // WordPress doesn't use PHP sessions by default
        // Only handle sessions if they exist and might interfere
        
        // SECURITY: Removed all manual PHP session handling
        // WordPress uses cookies for state management, not PHP sessions
        // For temporary data storage, use WordPress transients or user meta
    }
    
    
    
    

/**
 * Check if Wallet Up plugin is available
 * 
 * @return bool True if Wallet Up is available
 */
private function is_wallet_up_available() {
    // Ensure plugin functions are available
    if (!function_exists('is_plugin_active')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    // Check if Wallet Up plugin is active
    return is_plugin_active('wallet-up/wallet-up.php') || 
           is_plugin_active('wallet-up-pro/wallet-up.php') ||
           is_plugin_active('walletup/walletup.php');
}

/**
 * Initialize dashboard replacement
 * Add this to your init() method
 */
public function init() {
    // Ensure sessions are properly managed to prevent REST API interference
    $this->init_session_management();
    
    // Include required files
    $this->includes();
    
    // Initialize enterprise security features (with emergency disable check)
    if (class_exists('WalletUpEnterpriseSecurity') && 
        (!defined('WALLET_UP_EMERGENCY_DISABLE') || !WALLET_UP_EMERGENCY_DISABLE)) {
        WalletUpEnterpriseSecurity::init();
    }
    
    // Initialize security sanitizer
    if (class_exists('WalletUpSecuritySanitizer')) {
        WalletUpSecuritySanitizer::init();
    }
    
    // Initialize admin synchronization
    if (class_exists('WalletUpAdminSync')) {
        WalletUpAdminSync::init();
    }
    
    // Initialize conflict detection
    if (class_exists('WalletUpConflictDetector')) {
        WalletUpConflictDetector::init();
    }
    
    // Initialize safe activation system
    if (class_exists('WalletUpSafeActivation')) {
        WalletUpSafeActivation::init();
    }
    
    // Initialize logo management system
    if (class_exists('WalletUpLoginLogo')) {
        WalletUpLoginLogo::init();
    }
    
    
    // Init the login customizer if class exists
    if (class_exists('WalletUpLoginCustomizer')) {
        $this->init_login_customizer();
    } else {
        error_log('WalletUpLoginCustomizer class not found. Please use the reinstall option in settings if needed.');
    }
    
    // Initialize hard redirect system ONLY if Wallet Up is available
    $wallet_up_available = $this->is_wallet_up_available();
    if (class_exists('WalletUpHardRedirect')) {
        if ($wallet_up_available) {
            WalletUpHardRedirect::init();
        } else {
            // Disable redirect options if Wallet Up is not available
            $options = get_option('wallet_up_login_customizer_options', []);
            if (!empty($options['redirect_to_wallet_up']) || !empty($options['force_dashboard_replacement'])) {
                $options['redirect_to_wallet_up'] = false;
                $options['force_dashboard_replacement'] = false;
                update_option('wallet_up_login_customizer_options', $options);
                
                // Add admin notice
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-warning is-dismissible">';
                    echo '<p>' . esc_html__('Wallet Up dashboard redirect has been disabled because the Wallet Up plugin is not active.', 'wallet-up-login-customizer') . '</p>';
                    echo '</div>';
                });
            }
        }
    }

    // Register login redirect filter
    //$this->register_login_redirect();

    
    // Setup dashboard replacement if Force Dashboard Replacement is enabled AND Wallet Up is available
    $options = get_option('wallet_up_login_customizer_options');
    if (!empty($options['force_dashboard_replacement']) && $wallet_up_available) {
        $this->setup_dashboard_replacement();
    }
    
    // Add admin-related hooks
    if (is_admin()) {
        $this->init_admin();
    }
}

/**
 * Set up dashboard replacement hooks with role-based control
 */
private function setup_dashboard_replacement() {
    // SECURITY: Only proceed if Wallet Up is available
    if (!$this->is_wallet_up_available()) {
        return;
    }
    
    // Get options
    $options = get_option('wallet_up_login_customizer_options', []);
    
    // IMPORTANT: Force Dashboard Replacement works INDEPENDENTLY
    // Only check for 'force_dashboard_replacement', NOT 'redirect_to_wallet_up'
    $force_replacement = !empty($options['force_dashboard_replacement']);
    
    if (!$force_replacement) {
        return; // Only return if Force Dashboard Replacement is disabled
    }
    
    // SECURITY: Check if current user should be exempt
    if ($this->is_user_exempt_from_dashboard_replacement()) {
        return;
    }
    
    // SECURITY: Don't interfere with other admin pages, only dashboard
    add_action('load-index.php', array($this, 'secure_redirect_dashboard'), 5);
    
    // Replace dashboard widgets and content
    add_action('wp_dashboard_setup', array($this, 'replace_dashboard_widgets'), 999);
    
    // Modify admin menu to point to Wallet Up
    add_action('admin_menu', array($this, 'modify_dashboard_menu'), 999);
    
    // Modify admin bar dashboard link
    add_action('admin_bar_menu', array($this, 'modify_admin_bar_dashboard'), 999);
}

/**
 * Secure redirect from dashboard page
 */
public function secure_redirect_dashboard() {
    // SECURITY: Skip if override parameter is set
    // Validate and sanitize the parameter
    $show_dashboard = isset($_GET['show_wp_dashboard']) ? sanitize_text_field($_GET['show_wp_dashboard']) : '';
    if ($show_dashboard === '1' && current_user_can('manage_options')) {
        return;
    }
    
    // SECURITY: Skip for AJAX, CRON, CLI
    if (wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
        return;
    }
    
    // SECURITY: Verify user is logged in
    if (!is_user_logged_in()) {
        return;
    }
    
    // SECURITY: Double-check Wallet Up is still available
    if (!$this->is_wallet_up_available()) {
        return;
    }
    
    // SECURITY: Check if user is exempt
    if ($this->is_user_exempt_from_dashboard_replacement()) {
        return;
    }
    
    // Redirect to Wallet Up page with fallback
    $wallet_up_url = admin_url('admin.php?page=wallet-up');
    
    // SECURITY: Verify the redirect URL is valid
    if (filter_var($wallet_up_url, FILTER_VALIDATE_URL)) {
        wp_safe_redirect($wallet_up_url);
        exit;
    } else {
        // Fallback: disable redirect and show notice
        $options = get_option('wallet_up_login_customizer_options', []);
        $options['redirect_to_wallet_up'] = false;
        update_option('wallet_up_login_customizer_options', $options);
        
        // Allow normal dashboard access
        return;
    }
}

/**
 * Replace dashboard widgets with Wallet Up content
 */
public function replace_dashboard_widgets() {
    global $wp_meta_boxes;
    
    // SECURITY: Only proceed if user should see replacement
    if ($this->is_user_exempt_from_dashboard_replacement()) {
        return;
    }
    
    // SECURITY: Verify Wallet Up is available
    if (!$this->is_wallet_up_available()) {
        return;
    }
    
    // Clear existing dashboard widgets
    if (isset($wp_meta_boxes['dashboard'])) {
        $wp_meta_boxes['dashboard'] = [];
    }
    
    // Add Wallet Up dashboard widget
    wp_add_dashboard_widget(
        'wallet_up_dashboard_widget',
        __('Wallet Up Dashboard', 'wallet-up-login-customizer'),
        array($this, 'render_wallet_up_dashboard_widget')
    );
}

/**
 * Render Wallet Up dashboard widget
 */
public function render_wallet_up_dashboard_widget() {
    $wallet_up_url = admin_url('admin.php?page=wallet-up');
    $options = get_option('wallet_up_login_customizer_options', []);
    $embed_mode = !empty($options['embed_wallet_up']) && !empty($options['force_dashboard_replacement']);
    
    echo '<div class="wallet-up-dashboard-replacement">';
    
    if ($embed_mode) {
        // Embed Wallet Up content in iframe (if enabled)
        echo '<h3>' . esc_html__('Wallet Up Dashboard', 'wallet-up-login-customizer') . '</h3>';
        echo '<div class="wallet-up-embed-container">';
        echo '<iframe src="' . esc_url($wallet_up_url) . '" width="100%" height="600" frameborder="0" sandbox="allow-same-origin allow-scripts allow-forms"></iframe>';
        echo '</div>';
    } else {
        // Show link to Wallet Up (default behavior)
        echo '<h3>' . esc_html__('Welcome to Wallet Up!', 'wallet-up-login-customizer') . '</h3>';
        echo '<p>' . esc_html__('Your WordPress dashboard has been enhanced with Wallet Up. Click below to access your Wallet Up dashboard.', 'wallet-up-login-customizer') . '</p>';
        echo '<p>';
        echo '<a href="' . esc_url($wallet_up_url) . '" class="button button-primary button-large">';
        echo esc_html__('Open Wallet Up Dashboard', 'wallet-up-login-customizer');
        echo '</a>';
        echo '</p>';
    }
    
    // Add link to original dashboard for exempt users
    if (current_user_can('manage_options')) {
        echo '<div class="wallet-up-admin-controls">';
        echo '<p>';
        echo '<a href="' . esc_url(add_query_arg('show_wp_dashboard', '1', admin_url())) . '" class="button">';
        echo esc_html__('View Original WordPress Dashboard', 'wallet-up-login-customizer');
        echo '</a>';
        echo '</p>';
        echo '</div>';
    }
    
    echo '</div>';
    
    // Add styling
    echo '<style>
        .wallet-up-dashboard-replacement {
            text-align: center;
            padding: 20px;
        }
        .wallet-up-dashboard-replacement h3 {
            color: #6200fC;
            margin-bottom: 15px;
        }
        .wallet-up-dashboard-replacement p {
            margin-bottom: 15px;
        }
        .wallet-up-embed-container {
            border: 2px solid #6200fC;
            border-radius: 8px;
            overflow: hidden;
            margin: 20px 0;
        }
        .wallet-up-admin-controls {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }
    </style>';
}

/**
 * Modify admin menu dashboard item
 */
public function modify_dashboard_menu() {
    global $menu;
    
    // SECURITY: Only proceed if user should see replacement
    if ($this->is_user_exempt_from_dashboard_replacement()) {
        return;
    }
    
    // Find and modify the dashboard menu item
    if (is_array($menu)) {
        foreach ($menu as $key => $item) {
            if (isset($item[2]) && $item[2] === 'index.php') {
                $menu[$key][0] = __('Wallet Up', 'wallet-up-login-customizer');
                $menu[$key][2] = 'admin.php?page=wallet-up';
                break;
            }
        }
    }
}

/**
 * Modify admin bar dashboard link
 */
public function modify_admin_bar_dashboard($wp_admin_bar) {
    // SECURITY: Only proceed if user should see replacement
    if ($this->is_user_exempt_from_dashboard_replacement()) {
        return;
    }
    
    $wallet_up_url = admin_url('admin.php?page=wallet-up');
    
    // Update dashboard link in admin bar
    $dashboard_node = $wp_admin_bar->get_node('dashboard');
    if ($dashboard_node) {
        $dashboard_node->href = $wallet_up_url;
        $wp_admin_bar->add_node($dashboard_node);
    }
    
    // Update site name link
    $site_name_node = $wp_admin_bar->get_node('site-name');
    if ($site_name_node) {
        $site_name_node->href = $wallet_up_url;
        $wp_admin_bar->add_node($site_name_node);
    }
}

/**
 * Check if we need to redirect from dashboard
 */
public function maybe_redirect_dashboard() {
    // Only redirect from main dashboard
    // SECURITY: Validate GET parameter
    $show_dashboard = isset($_GET['show_wp_dashboard']) ? sanitize_text_field($_GET['show_wp_dashboard']) : '';
    if (is_admin() && !defined('DOING_AJAX') && $GLOBALS['pagenow'] == 'index.php' && $show_dashboard !== '1') {
        wp_redirect(admin_url('admin.php?page=wallet-up'));
        exit;
    }
}

/**
 * Replace dashboard menu with Wallet Up
 */
// REMOVED: Replaced by WalletUpHardRedirect::modify_admin_menu() for cleaner implementation

// REMOVED: Admin bar replacement moved to WalletUpHardRedirect class for better organization

/**
 * Initialize admin-related functionality
 */
private function init_admin() {
    // Add settings link to plugin page
    add_filter('plugin_action_links_' . WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_BASENAME, array($this, 'add_settings_link'));
    
    // Register settings page
    add_action('admin_menu', array($this, 'register_settings_page'));
    
    // Add network admin menu for multisite
    if (is_multisite()) {
        add_action('network_admin_menu', array($this, 'register_network_settings_page'));
    }
    
    // Register settings
    add_action('admin_init', array($this, 'register_settings'));
    
    // Add admin scripts and styles
    add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    
    // Add language availability notice
    add_action('admin_notices', array($this, 'language_availability_notice'));
    
    // AJAX handler for dismissing language notice
    add_action('wp_ajax_wallet_up_dismiss_language_notice', array($this, 'dismiss_language_notice'));
}

 /**
 * Include required files
 */
private function includes() {
    // Include the login customizer class if it exists
    $customizer_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-login-customizer.php';
    
    if (file_exists($customizer_file)) {
        require_once $customizer_file;
    }
    
    // Include the enterprise security class
    $security_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-enterprise-security.php';
    
    if (file_exists($security_file)) {
        require_once $security_file;
    }
    
    // Include the security manager class
    $security_manager_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-security-manager.php';
    
    if (file_exists($security_manager_file)) {
        require_once $security_manager_file;
    }
    
    // Include the security sanitizer class
    $sanitizer_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-security-sanitizer.php';
    
    if (file_exists($sanitizer_file)) {
        require_once $sanitizer_file;
    }
    
    // Include the logo management class
    $logo_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-login-customizer-logo.php';
    
    if (file_exists($logo_file)) {
        require_once $logo_file;
    }
    
    // Include the admin sync class
    $admin_sync_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-admin-sync.php';
    
    if (file_exists($admin_sync_file)) {
        require_once $admin_sync_file;
    }
    
    // Include the conflict detector class
    $conflict_detector_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-conflict-detector.php';
    
    if (file_exists($conflict_detector_file)) {
        require_once $conflict_detector_file;
    }
    
    // Include the safe activation class
    $safe_activation_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-safe-activation.php';
    
    if (file_exists($safe_activation_file)) {
        require_once $safe_activation_file;
    }
    
    // Include the hard redirect class
    $redirect_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-hard-redirect.php';
    
    if (file_exists($redirect_file)) {
        require_once $redirect_file;
    }
}
    /**
     * Initialize the login customizer
     */
    private function init_login_customizer() {
        // Check if the class exists before initializing
        if (class_exists('WalletUpLoginCustomizer')) {
            $this->login_customizer = WalletUpLoginCustomizer::get_instance();
        }
    }
    
    /**
 * Handle login redirect based on plugin settings
 * 
 * @param string $redirect_to The redirect destination URL
 * @param string $request The requested redirect destination URL passed as a parameter
 * @param WP_User|WP_Error $user WP_User object if login was successful, WP_Error object otherwise
 * @return string Modified redirect URL
 */
public function login_redirect($redirect_to, $request, $user) {
    // Only redirect if user logged in successfully and is an object
    if (!is_wp_error($user) && $user instanceof WP_User) {
        // Get plugin options
        $options = get_option('wallet_up_login_customizer_options');
        
        // Check if we should redirect to Wallet Up
        $redirect_to_wallet_up = !empty($options['redirect_to_wallet_up']);
        
        // Check if we're forcing dashboard replacement but exempting this user
        $force_replacement = !empty($options['force_dashboard_replacement']);
        $is_exempt = false;
        
        // Check if user should be exempt from dashboard replacement
        if (!empty($options['exempt_admin_roles']) && current_user_can('administrator')) {
            $is_exempt = true;
        }
        
        // First check if we should redirect to Wallet Up page
        if ($redirect_to_wallet_up && !($force_replacement && $is_exempt)) {
            // Override any other redirects with high priority
            return admin_url('admin.php?page=wallet-up');
        }
        
        // Otherwise check if dashboard redirect is enabled
        if (!empty($options['dashboard_redirect'])) {
            // Return admin URL for dashboard
            return admin_url();
        }
    }
    
    // Return original redirect URL if no custom redirect applies
    return $redirect_to;
}

/**
 * Register filters for login redirection with HIGHER PRIORITY
 */
public function register_login_redirect() {
    // Use a very high priority (999) to override other plugins
    add_filter('login_redirect', array($this, 'login_redirect'), 999, 3);
}

/**
 * Enqueue admin scripts and styles
 */
public function admin_enqueue_scripts($hook) {
    // Only load on our settings page
    if ($hook != 'settings_page_wallet-up-login-customizer') {
        return;
    }
    
    // Initialize WordPress media uploader
    wp_enqueue_media();
    
    // Add color picker
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    
    // Admin script file path
    $admin_js_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'js/wallet-up-login-customizer-admin.js';
    
    // Safely check for admin script without reinstalling
    if (!file_exists($admin_js_file)) {
        // Log the issue instead of automatically creating
        error_log('Admin JS file not found: ' . $admin_js_file);
        
        // Use a safe fallback with media dependencies
        wp_enqueue_script(
            'wallet-up-login-customizer-admin-fallback',
            WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL . 'js/wallet-up-login-customizer-admin-fallback.js',
            array('jquery', 'wp-color-picker', 'media-upload', 'media-views'),
            WALLET_UP_LOGIN_CUSTOMIZER_VERSION,
            true
        );
    } else {
        // Add our admin script normally with media dependencies
        wp_enqueue_script(
            'wallet-up-login-customizer-admin',
            WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL . 'js/wallet-up-login-customizer-admin.js',
            array('jquery', 'wp-color-picker', 'media-upload', 'media-views'),
            WALLET_UP_LOGIN_CUSTOMIZER_VERSION . '.' . filemtime($admin_js_file), // Cache busting
            true
        );
        
        // Localize admin script with translation strings
        wp_localize_script('wallet-up-login-customizer-admin', 'walletUpLogin', array(
            'strings' => array(
                'verifyingCredentials' => __('Verifying your credentials...', 'wallet-up-login-customizer'),
                'preparingDashboard' => __('Preparing your dashboard...', 'wallet-up-login-customizer'),
                'almostThere' => __('Almost there...', 'wallet-up-login-customizer'),
                'colorSchemeApplied' => __('Color scheme applied!', 'wallet-up-login-customizer'),
                'settingsExported' => __('Settings exported successfully!', 'wallet-up-login-customizer'),
                'settingsImported' => __('Settings imported successfully', 'wallet-up-login-customizer'),
                'settingsReset' => __('Settings reset to defaults!', 'wallet-up-login-customizer'),
                'selectValidFile' => __('Please select a valid JSON file', 'wallet-up-login-customizer'),
                'errorImporting' => __('Error importing settings', 'wallet-up-login-customizer'),
                'errorReadingFile' => __('Error reading file', 'wallet-up-login-customizer'),
                'confirmReset' => __('Are you sure you want to reset all settings to default values?', 'wallet-up-login-customizer')
            )
        ));
    }
    
    // Admin CSS file path
    $admin_css_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'css/wallet-up-login-customizer-admin.css';
    
    // Add admin styles
    wp_enqueue_style(
        'wallet-up-login-customizer-admin',
        WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL . 'css/wallet-up-login-customizer-admin.css',
        array(),
        WALLET_UP_LOGIN_CUSTOMIZER_VERSION . '.' . time() // Force cache refresh
    );
}   
   
/**
 * Display security status notice
 */
public function security_status_notice() {
    // Only show to administrators
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Check if Wallet Up is missing but redirect is enabled
    $options = get_option('wallet_up_login_customizer_options', []);
    if (!empty($options['redirect_to_wallet_up']) && !$this->is_wallet_up_available()) {
        echo '<div class="notice notice-error">';
        echo '<p><strong>' . esc_html__('Wallet Up Login Customizer:', 'wallet-up-login-customizer') . '</strong> ';
        echo esc_html__('Dashboard redirect is enabled but Wallet Up plugin is not active. Please install Wallet Up or disable the redirect feature.', 'wallet-up-login-customizer');
        echo '</p>';
        echo '</div>';
    }
}

   /**
 * Plugin activation hook
 * 
 * @param bool $network_wide Whether the plugin is being activated network-wide
 */
public function activate($network_wide = false) {
    // SAFETY FIRST: Use safe activation system
    require_once WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-safe-activation.php';
    
    // Handle multisite network activation
    if (is_multisite() && $network_wide) {
        // Get all sites in the network
        $sites = get_sites();
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            WalletUpSafeActivation::activate();
            restore_current_blog();
        }
    } else {
        // Single site activation
        WalletUpSafeActivation::activate();
    }
    
    // Make sure the includes directory exists
    $includes_dir = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes';
    
    if (!file_exists($includes_dir)) {
        if (!wp_mkdir_p($includes_dir)) {
            error_log('Wallet Up Login: Failed to create includes directory: ' . $includes_dir);
        }
    }
    
    // Make sure assets subdirectories exist
    $assets_dirs = array('css', 'js', 'img');
    
    foreach ($assets_dirs as $dir) {
        $asset_dir = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . $dir;
        
        if (!file_exists($asset_dir)) {
            if (!wp_mkdir_p($asset_dir)) {
                error_log('Wallet Up Login: Failed to create asset directory: ' . $asset_dir);
            }
        }
    }
    
    // Check if this is a first installation or if files need to be reinstalled
    $first_install = !get_option('wallet_up_files_installed');
    
    // Install/copy necessary files only if needed (safer approach)
    if ($first_install) {
        $this->install_files(false); // Don't force on first install to preserve existing files
    }
    
    // SAFETY: Only set minimal default options
    if ($first_install) {
        $this->set_safe_default_options();
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Set safe default options
 */
private function set_safe_default_options() {
    $default_options = array(
        'redirect_to_wallet_up' => false,  // Disabled by default for safety
        'force_dashboard_replacement' => false,  // Disabled by default
        'custom_login_message' => __('Welcome to Wallet Up', 'wallet-up-login-customizer'),
        'enable_ajax_login' => true,
        'primary_color' => '#6200fC',
        'gradient_start' => '#6200fC',
        'gradient_end' => '#8B3DFF',
        'exempt_admin_roles' => true  // Exempt administrators by default
    );
    
    // Only add if not exists
    if (!get_option('wallet_up_login_customizer_options')) {
        add_option('wallet_up_login_customizer_options', $default_options);
    }
    
    // Mark files as installed
    update_option('wallet_up_files_installed', true);
}

/**
 * Handle new site creation in multisite
 * 
 * @param WP_Site $new_site New site object
 * @param array $args Arguments for the new site
 */
public function new_site_activation($new_site, $args) {
    // Check if plugin is network activated
    if (!is_plugin_active_for_network(plugin_basename(__FILE__))) {
        return;
    }
    
    // Switch to new site and activate
    switch_to_blog($new_site->blog_id);
    require_once WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-safe-activation.php';
    WalletUpSafeActivation::activate();
    restore_current_blog();
}

/**
 * Plugin deactivation hook
 */
public function deactivate() {
    // SAFETY: Use safe deactivation system
    if (class_exists('WalletUpSafeActivation')) {
        WalletUpSafeActivation::deactivate();
    }
    
    // Only flush rewrite rules, no file operations
    flush_rewrite_rules();
}

    
    /**
     * Install/copy necessary files
     * 
     * @return bool True on success, false on failure
     */
    /**
 * Install/copy necessary files
 * 
 * @param bool $force Force reinstallation even if files exist
 * @return bool True on success, false on failure
 */
public function install_files($force = false) {
    $success = true;
    $errors = array();
    
    // Smart sync logic - check twice per week (every 3.5 days)
    $last_sync = get_option('wallet_up_files_last_sync', 0);
    $sync_interval = 3.5 * DAY_IN_SECONDS; // 3.5 days = twice weekly
    $now = time();
    
    // Skip if recently synced (unless forced or first install)
    if (!$force && $last_sync && ($now - $last_sync) < $sync_interval) {
        return true;
    }
    
    // File mappings for intelligent sync
    $file_mappings = array(
        array(
            'source' => WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'assets/wallet-up-login-customizer.css',
            'dest' => WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'css/wallet-up-login-customizer.css',
            'critical' => true
        ),
        array(
            'source' => WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'assets/wallet-up-login-customizer.js',
            'dest' => WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'js/wallet-up-login-customizer.js',
            'critical' => true
        ),
        array(
            'source' => WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'assets/wallet-up-login-customizer-admin.css',
            'dest' => WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'css/wallet-up-login-customizer-admin.css',
            'critical' => true
        ),
        array(
            'source' => WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'assets/wallet-up-login-customizer-admin.js',
            'dest' => WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'js/wallet-up-login-customizer-admin.js',
            'critical' => true
        ),
        array(
            'source' => WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'assets/class-wallet-up-login-customizer.php',
            'dest' => WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-login-customizer.php',
            'critical' => false // Not critical for basic operation
        )
    );
    
    // Track what gets updated
    $updates_log = array();
    
    // Process each file with intelligent logic
    foreach ($file_mappings as $file) {
        $needs_copy = false;
        $reason = '';
        
        // Check if source exists
        if (!file_exists($file['source'])) {
            // Source doesn't exist - handle missing assets intelligently
            if (file_exists($file['dest'])) {
                // Destination exists but source missing - restore source from destination
                if (!file_exists(dirname($file['source']))) {
                    wp_mkdir_p(dirname($file['source']));
                }
                @copy($file['dest'], $file['source']);
                $updates_log[] = array(
                    'file' => basename($file['source']),
                    'reason' => 'restored_to_assets',
                    'timestamp' => $now
                );
                continue; // Source restored, no need to copy back to dest
            } else if ($file['critical']) {
                // Both missing and it's critical - create default in destination
                $this->create_default_file($file['dest']);
                // Also create in source for future use
                if ($this->create_default_file($file['dest'])) {
                    @copy($file['dest'], $file['source']);
                }
            }
            continue;
        }
        
        // Determine if we need to copy
        if (!file_exists($file['dest'])) {
            // Destination doesn't exist - always copy
            $needs_copy = true;
            $reason = 'missing';
        } else if ($force) {
            // Force flag - always copy
            $needs_copy = true;
            $reason = 'forced';
        } else {
            // Both files exist - check modification times
            $source_mtime = filemtime($file['source']);
            $dest_mtime = filemtime($file['dest']);
            
            // Only copy if source is newer (with 60 second buffer for timezone issues)
            if ($source_mtime > ($dest_mtime + 60)) {
                $needs_copy = true;
                $reason = 'source_newer';
                
                // Security check: Store hash of destination before overwriting
                $dest_hash = md5_file($file['dest']);
                update_option('wallet_up_backup_hash_' . basename($file['dest']), $dest_hash);
            }
        }
        
        // Perform the copy if needed
        if ($needs_copy) {
            // Use the safe copy function
            $copy_result = $this->safe_file_copy($file['source'], $file['dest']);
            
            if ($copy_result) {
                $updates_log[] = array(
                    'file' => basename($file['dest']),
                    'reason' => $reason,
                    'timestamp' => $now
                );
            } else {
                $errors[] = 'Failed to copy ' . basename($file['source']);
                if ($file['critical']) {
                    $success = false;
                }
            }
        }
    }
    
    // Log updates if any were made
    if (!empty($updates_log)) {
        $existing_log = get_option('wallet_up_sync_log', array());
        $existing_log[] = array(
            'date' => current_time('mysql'),
            'updates' => $updates_log,
            'forced' => $force
        );
        // Keep only last 10 sync records
        $existing_log = array_slice($existing_log, -10);
        update_option('wallet_up_sync_log', $existing_log);
        
        // Set a transient to show admin notice (only if not forced by manual button)
        if (!$force && current_user_can('manage_options')) {
            set_transient('wallet_up_files_auto_updated', $updates_log, 60);
        }
    }
    
    // Update sync timestamp
    update_option('wallet_up_files_last_sync', $now);
    
    // Mark as installed if successful
    if ($success) {
        update_option('wallet_up_files_installed', $now);
    }
    
    return $success;
}

/**
 * Safely copy a file with permission handling
 */
private function safe_file_copy($source, $destination) {
    // Validate source file
    if (!file_exists($source) || !is_readable($source)) {
        return false;
    }
    
    // Security: Ensure we're within plugin directory
    $plugin_dir = realpath(WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR);
    $real_source = realpath($source);
    $dest_dir = dirname($destination);
    
    if (strpos($real_source, $plugin_dir) !== 0) {
        error_log('Wallet Up Login: Security - Source file outside plugin directory');
        return false;
    }
    
    // Create destination directory if needed
    if (!is_dir($dest_dir)) {
        wp_mkdir_p($dest_dir);
    }
    
    // Handle existing file with wrong permissions
    if (file_exists($destination) && !is_writable($destination)) {
        @unlink($destination);
    }
    
    // Copy the file
    $result = @copy($source, $destination);
    
    // Set proper permissions
    if ($result) {
        @chmod($destination, 0644);
    }
    
    return $result;
}

/**
 * Create a default file if source is missing
 */
private function create_default_file($filepath) {
    $filename = basename($filepath);
    
    switch($filename) {
        case 'wallet-up-login-customizer.css':
            return $this->create_default_css();
        case 'wallet-up-login-customizer.js':
            return $this->create_default_js();
        case 'wallet-up-login-customizer-admin.css':
            return $this->create_admin_css();
        case 'wallet-up-login-customizer-admin.js':
            return $this->create_admin_js();
        case 'class-wallet-up-login-customizer.php':
            return $this->create_default_class();
        default:
            return false;
    }
}

    
    /**
     * Create default CSS file
     * 
     * @return bool True on success, false on failure
     */
    private function create_default_css() {
        $css_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'css/wallet-up-login-customizer.css';
        $css_content = "/* Wallet Up Premium Login CSS */\n\n";
        
        // Add basic CSS styles
        $css_content .= "body.login {\n\tbackground: linear-gradient(135deg, #FCFCFF 0%, #F5F5FF 100%);\n\tfont-family: 'Inter', -apple-system, sans-serif;\n}\n\n";
        $css_content .= "#loginform {\n\tbackground: white;\n\tborder-radius: 12px;\n\tbox-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);\n}\n\n";
        $css_content .= "#wp-submit {\n\tbackground: linear-gradient(135deg, #674FBF 0%, #7B68D4 100%);\n\tborder: none;\n\tbox-shadow: 0 8px 16px rgba(103, 79, 191, 0.2);\n}\n";
        
        try {
            $result = file_put_contents($css_file, $css_content);
            if ($result === false) {
                error_log('Wallet Up Login: Failed to write CSS file: ' . $css_file);
                return false;
            }
            return true;
        } catch (Exception $e) {
            error_log('Wallet Up Login: Exception writing CSS file: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create default JS file
     * 
     * @return bool True on success, false on failure
     */
    private function create_default_js() {
        $js_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'js/wallet-up-login-customizer.js';
        $js_content = "/**\n * Wallet Up Premium Login JavaScript\n */\n\n";
        
        // Add basic JavaScript
        $js_content .= "(function($) {\n\t\"use strict\";\n\n\t$(document).ready(function() {\n\t\tconsole.log('Wallet Up Login JS initialized');\n\t});\n})(jQuery);\n";
        
        return file_put_contents($js_file, $js_content) !== false;
    }
    
    /**
     * Create admin JS file
     * 
     * @return bool True on success, false on failure
     */
    private function create_admin_js() {
        $js_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'js/wallet-up-login-customizer-admin.js';
        $js_content = "/**\n * Wallet Up Login Admin JavaScript\n */\n\n";
        
        // Add basic JavaScript for admin
        $js_content .= "(function($) {\n\t\"use strict\";\n\n\t$(document).ready(function() {\n\t\t// Initialize color pickers\n\t\t$('.wallet-up-color-picker').wpColorPicker();\n\t\t\n\t\t// Handle dynamic message fields\n\t\tvar messageContainer = $('#loading-messages-container');\n\t\tvar messageTemplate = $('#loading-message-template').html();\n\t\tvar messageCount = messageContainer.find('.loading-message').length;\n\t\t\n\t\t// Add new message field\n\t\t$('#add-loading-message').on('click', function(e) {\n\t\t\te.preventDefault();\n\t\t\tvar newMessage = messageTemplate.replace(/\\[index\\]/g, messageCount);\n\t\t\tmessageContainer.append(newMessage);\n\t\t\tmessageCount++;\n\t\t});\n\t\t\n\t\t// Remove message field\n\t\t$(document).on('click', '.remove-loading-message', function(e) {\n\t\t\te.preventDefault();\n\t\t\t$(this).closest('.loading-message').remove();\n\t\t});\n\t});\n})(jQuery);\n";
        
        return file_put_contents($js_file, $js_content) !== false;
    }
    
    /**
     * Create admin CSS file
     * 
     * @return bool True on success, false on failure
     */
    private function create_admin_css() {
        $css_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'css/wallet-up-login-customizer-admin.css';
        $css_content = "/**\n * Wallet Up Login Admin CSS\n */\n\n";
        
        // Add basic CSS for admin
        $css_content .= ".wallet-up-admin-container {\n\tdisplay: flex;\n\tgap: 20px;\n\tmargin-top: 20px;\n}\n\n";
        $css_content .= ".wallet-up-admin-content {\n\tflex: 2;\n}\n\n";
        $css_content .= ".wallet-up-admin-sidebar {\n\tflex: 1;\n\tmax-width: 300px;\n}\n\n";
        $css_content .= ".wallet-up-admin-box {\n\tbackground: #fff;\n\tborder: 1px solid #ccd0d4;\n\tborder-radius: 4px;\n\tpadding: 15px;\n\tmargin-bottom: 20px;\n}\n\n";
        $css_content .= ".wallet-up-admin-box h3 {\n\tmargin-top: 0;\n\tborder-bottom: 1px solid #eee;\n\tpadding-bottom: 10px;\n}\n\n";
        $css_content .= ".loading-message {\n\tbackground: #f9f9f9;\n\tborder: 1px solid #e5e5e5;\n\tborder-radius: 4px;\n\tpadding: 10px;\n\tmargin-bottom: 10px;\n\tposition: relative;\n}\n\n";
        $css_content .= ".remove-loading-message {\n\tposition: absolute;\n\ttop: 5px;\n\tright: 5px;\n\tcolor: #a00;\n\ttext-decoration: none;\n}\n\n";
        $css_content .= "@media screen and (max-width: 782px) {\n\t.wallet-up-admin-container {\n\t\tflex-direction: column;\n\t}\n\t.wallet-up-admin-sidebar {\n\t\tmax-width: 100%;\n\t}\n}\n";
        
        return file_put_contents($css_file, $css_content) !== false;
    }
    
    /**
     * Create default class file
     * 
     * @return bool True on success, false on failure
     */
    private function create_default_class() {
        $class_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-login-customizer.php';
        $class_content = "<?php\n/**\n * WalletUpLoginCustomizer Class\n */\n\n";
        
        // Add basic class
        $class_content .= "// Prevent direct access\nif (!defined('ABSPATH')) {\n\texit;\n}\n\n";
        $class_content .= "class WalletUpLoginCustomizer {\n\n";
        $class_content .= "\t/**\n\t * Instance of this class\n\t * @var WalletUpLoginCustomizer\n\t */\n\tprivate static \$instance = null;\n\n";
        $class_content .= "\t/**\n\t * Constructor\n\t */\n\tpublic function __construct() {\n";
        $class_content .= "\t\t// Add hooks\n\t\tadd_action('login_enqueue_scripts', array(\$this, 'enqueue_scripts'));\n";
        $class_content .= "\t}\n\n";
        $class_content .= "\t/**\n\t * Get class instance\n\t * @return WalletUpLoginCustomizer\n\t */\n\tpublic static function get_instance() {\n";
        $class_content .= "\t\tif (null === self::\$instance) {\n\t\t\tself::\$instance = new self();\n\t\t}\n\t\treturn self::\$instance;\n\t}\n\n";
        $class_content .= "\t/**\n\t * Enqueue scripts\n\t */\n\tpublic function enqueue_scripts() {\n";
        $class_content .= "\t\t// Enqueue CSS and JS\n\t\twp_enqueue_style('wallet-up-login-customizer', plugin_dir_url(dirname(__FILE__)) . 'css/wallet-up-login-customizer.css');\n";
        $class_content .= "\t\twp_enqueue_script('wallet-up-login-customizer', plugin_dir_url(dirname(__FILE__)) . 'js/wallet-up-login-customizer.js', array('jquery'), '1.0', true);\n";
        $class_content .= "\t}\n";
        $class_content .= "}\n";
        
        return file_put_contents($class_file, $class_content) !== false;
    }
    
 /**
 * Set default options
 * Only called during first activation
 */
private function set_default_options() {
    // Check if options already exist - don't overwrite
    $existing_options = get_option('wallet_up_login_customizer_options', false);
    
    if ($existing_options !== false) {
        // Options already exist, don't override user settings
        return;
    }
    
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
        'dashboard_redirect' => false,
        'show_remember_me' => true,
        'redirect_to_wallet_up' => false, // SAFER: Don't force redirect by default
        'force_dashboard_replacement' => false, // SAFER: Don't force replacement by default  
        'exempt_admin_roles' => true, // SAFER: Exempt admins by default for troubleshooting
    );
    
    update_option('wallet_up_login_customizer_options', $defaults);
}

/**
 * Create minimal fallback JS for admin
 * This creates a small placeholder file when main admin JS is missing
 */
private function create_admin_js_fallback() {
    $js_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'js/wallet-up-login-customizer-admin-fallback.js';
    $js_content = "/**\n * Wallet Up Login Admin JavaScript - Fallback Version\n */\n\n";
    
    // Add minimal essential functionality
    $js_content .= "(function($) {\n\t\"use strict\";\n\n\t$(document).ready(function() {\n";
    
    // Initialize color pickers
    $js_content .= "\t\t// Initialize color pickers\n";
    $js_content .= "\t\tif ($.fn.wpColorPicker) {\n";
    $js_content .= "\t\t\t$('.wallet-up-color-picker').wpColorPicker();\n";
    $js_content .= "\t\t}\n\n";
    
    // Tab switching (simplified)
    $js_content .= "\t\t// Setup tabs\n";
    $js_content .= "\t\t$('.settings-tab').on('click', function(e) {\n";
    $js_content .= "\t\t\te.preventDefault();\n";
    $js_content .= "\t\t\tvar tabId = $(this).attr('href');\n";
    $js_content .= "\t\t\t$('.settings-tab').removeClass('active');\n";
    $js_content .= "\t\t\t$('.settings-panel').hide();\n";
    $js_content .= "\t\t\t$(this).addClass('active');\n";
    $js_content .= "\t\t\t$(tabId).show();\n";
    $js_content .= "\t\t});\n\n";
    
    // Show first tab by default
    $js_content .= "\t\t// Show first tab\n";
    $js_content .= "\t\t$('.settings-tab:first').click();\n";
    
    // Close function
    $js_content .= "\t});\n})(jQuery);\n";
    
    // Write to file
    return file_put_contents($js_file, $js_content) !== false;
}
/**
 * Safe fallback to get asset URL, even if the file doesn't exist
 * 
 * @param string $path Relative path to asset
 * @return string Full URL to asset
 */
private function get_asset_url($path) {
    // Check if file exists first
    $file_path = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . $path;
    
    if (file_exists($file_path)) {
        return WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL . $path;
    } else {
        // Log the issue
        error_log('Asset file not found: ' . $file_path);
        
        // Return empty or default asset path
        if (strpos($path, 'css/') === 0) {
            return 'data:text/css;base64,LyogRmFsbGJhY2sgQ1NTICovCg=='; // Empty CSS
        } elseif (strpos($path, 'js/') === 0) {
            return 'data:application/javascript;base64,Ly8gRmFsbGJhY2sgSlMK'; // Empty JS
        } elseif (strpos($path, 'img/') === 0) {
            return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9IiM2NzRGQkYiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIj48cGF0aCBkPSJNNSA4VjVjMC0xIDEtMiAyLTJoMTBjMSAwIDIgMSAyIDJ2MyIvPjxwYXRoIGQ9Ik0xOSAxNnYzYzAgMS0xIDItMiAySDdjLTEgMC0yLTEtMi0ydi0zIi8+PGxpbmUgeDE9IjEyIiB4Mj0iMTIiIHkxPSI0IiB5Mj0iMjAiLz48L3N2Zz4='; // SVG icon
        }
        
        return ''; // Empty fallback
    }
}
    /**
     * Add settings link to plugin page
     * 
     * @param array $links Existing links
     * @return array Modified links
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=wallet-up-login-customizer') . '">' . __('Settings', 'wallet-up-login-customizer') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Register network settings page for multisite
     */
    public function register_network_settings_page() {
        add_menu_page(
            __('Wallet Up Network Settings', 'wallet-up-login-customizer'),
            __('Wallet Up Login', 'wallet-up-login-customizer'),
            'manage_network_options',
            'wallet-up-network-settings',
            array($this, 'render_network_settings_page'),
            'dashicons-shield',
            80
        );
    }
    
    /**
     * Register settings page
     */
    public function register_settings_page() {
        add_options_page(
            __('Wallet Up Login', 'wallet-up-login-customizer'),
            __('Wallet Up Login', 'wallet-up-login-customizer'),
            'manage_options',
            'wallet-up-login-customizer',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wallet_up_login_customizer_options', 'wallet_up_login_customizer_options', array($this, 'sanitize_options'));
        
        // Register security options  
        register_setting('wallet_up_login_customizer_options', 'wallet_up_login_customizer_security_options', array($this, 'sanitize_security_options'));
        
          // Add custom success message and auto-dismiss animation
    add_action('admin_notices', function() {
        // Check if settings were just saved (settings-updated parameter)
        if (isset($_GET['settings-updated']) && sanitize_text_field($_GET['settings-updated']) === 'true' && isset($_GET['page']) && sanitize_text_field($_GET['page']) === 'wallet-up-login-customizer') {
            // Show our custom success message
            echo '<div class="notice notice-success is-dismissible wallet-up-auto-dismiss-notice">';
            echo '<p><strong>' . esc_html__('Settings successfully saved!', 'wallet-up-login-customizer') . '</strong></p>';
            echo '</div>';
            
            // Add auto-dismiss script and hide the default WordPress notice
            echo '<script>
                jQuery(document).ready(function($) {
                    // Hide the default WordPress "Settings saved." notice
                    $(".notice-success").each(function() {
                        var $notice = $(this);
                        var noticeText = $notice.text().trim();
                        // Hide default WordPress settings saved notice
                        if (noticeText === "Settings saved." || noticeText === "Einstellungen gespeichert." || 
                            noticeText === "Ajustes guardados." || noticeText === "Réglages enregistrés.") {
                            $notice.hide();
                        }
                    });
                    
                    // Auto-dismiss our custom notice after 3 seconds
                    $(".wallet-up-auto-dismiss-notice").each(function() {
                        var $notice = $(this);
                        setTimeout(function() {
                            $notice.fadeOut(300, function() {
                                $(this).remove();
                            });
                        }, 3000);
                    });
                });
            </script>';
        }
        
        // Show auto-update notice if files were automatically updated
        $auto_updates = get_transient('wallet_up_files_auto_updated');
        if ($auto_updates && !empty($auto_updates)) {
            $file_list = array_map(function($update) {
                return $update['file'];
            }, $auto_updates);
            
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>' . esc_html__('Wallet Up Login:', 'wallet-up-login-customizer') . '</strong> ';
            echo esc_html__('The following template files were automatically updated from assets:', 'wallet-up-login-customizer');
            echo ' <code>' . esc_html(implode(', ', $file_list)) . '</code></p>';
            echo '</div>';
            
            // Delete transient after showing
            delete_transient('wallet_up_files_auto_updated');
        }
    });


        add_settings_section(
            'wallet_up_login_customizer_section',
            __('Login Page Settings', 'wallet-up-login-customizer'),
            array($this, 'render_settings_section'),
            'wallet-up-login-customizer'
        );
        
        // Add settings fields
        add_settings_field(
            'enable_ajax_login',
            __('Enable AJAX Login', 'wallet-up-login-customizer'),
            array($this, 'render_checkbox_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'enable_ajax_login',
                'description' => __('Enable AJAX login functionality for a smoother experience', 'wallet-up-login-customizer')
            )
        );
        
        add_settings_field(
            'custom_logo_url',
            __('Custom Logo URL', 'wallet-up-login-customizer'),
            array($this, 'render_text_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'custom_logo_url',
                'description' => __('Enter a URL to use a custom logo (leave empty for default)', 'wallet-up-login-customizer')
            )
        );
        
        add_settings_field(
            'primary_color',
            __('Primary Color', 'wallet-up-login-customizer'),
            array($this, 'render_color_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'primary_color',
                'description' => __('Select the primary color for the login page', 'wallet-up-login-customizer')
            )
        );
        
        add_settings_field(
            'gradient_start',
            __('Gradient Start Color', 'wallet-up-login-customizer'),
            array($this, 'render_color_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'gradient_start',
                'description' => __('Start color for gradient effects', 'wallet-up-login-customizer')
            )
        );
        
        add_settings_field(
            'gradient_end',
            __('Gradient End Color', 'wallet-up-login-customizer'),
            array($this, 'render_color_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'gradient_end',
                'description' => __('End color for gradient effects', 'wallet-up-login-customizer')
            )
        );
        
        add_settings_field(
            'enable_sounds',
            __('Enable Sound Effects', 'wallet-up-login-customizer'),
            array($this, 'render_checkbox_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'enable_sounds',
                'description' => __('Enable subtle audio feedback on login success/failure', 'wallet-up-login-customizer')
            )
        );
        
        add_settings_field(
            'loading_messages',
            __('Loading Messages', 'wallet-up-login-customizer'),
            array($this, 'render_messages_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'loading_messages',
                'description' => __('Customize messages displayed during login', 'wallet-up-login-customizer')
            )
        );
        
        add_settings_field(
            'redirect_delay',
            __('Redirect Delay (ms)', 'wallet-up-login-customizer'),
            array($this, 'render_number_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'redirect_delay',
                'description' => __('Delay before redirecting after successful login (in milliseconds)', 'wallet-up-login-customizer'),
                'min' => 0,
                'max' => 5000,
                'step' => 100
            )
        );
        
        add_settings_field(
            'dashboard_redirect',
            __('Role-Based Redirects', 'wallet-up-login-customizer'),
            array($this, 'render_checkbox_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'dashboard_redirect',
                'description' => __('Enable intelligent role-based redirects after login', 'wallet-up-login-customizer')
            )
        );

        add_settings_field(
            'redirect_to_wallet_up',
            __('Land to Wallet Up', 'wallet-up-login-customizer'),
            array($this, 'render_checkbox_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'redirect_to_wallet_up',
                'description' => __('Redirect users to the Wallet Up admin page after login', 'wallet-up-login-customizer')
            )
        );

        add_settings_field(
            'force_dashboard_replacement',
            __('Force Dashboard Replacement', 'wallet-up-login-customizer'),
            array($this, 'render_checkbox_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'force_dashboard_replacement',
                'description' => __('Replace WordPress dashboard with Wallet Up admin page for all users', 'wallet-up-login-customizer')
            )
        );
                
        add_settings_field(
            'exempt_admin_roles',
            __('Exempt Administrators', 'wallet-up-login-customizer'),
            array($this, 'render_checkbox_field'),
            'wallet-up-login-customizer',
            'wallet_up_login_customizer_section',
            array(
                'name' => 'exempt_admin_roles',
                'description' => __('Allow administrators to access the original WordPress dashboard', 'wallet-up-login-customizer')
            )
        );
    }
    
    /**
     * Sanitize options
     * 
     * @param array $input The options to sanitize
     * @return array Sanitized options
     */
    public function sanitize_options($input) {
        $sanitized = array();
        
       // Sanitize checkboxes
        $sanitized['enable_ajax_login'] = isset($input['enable_ajax_login']) ? (bool) $input['enable_ajax_login'] : false;
        $sanitized['enable_sounds'] = isset($input['enable_sounds']) ? (bool) $input['enable_sounds'] : false;
        $sanitized['dashboard_redirect'] = isset($input['dashboard_redirect']) ? (bool) $input['dashboard_redirect'] : false;
        $sanitized['show_remember_me'] = isset($input['show_remember_me']) ? (bool) $input['show_remember_me'] : false;
        $sanitized['redirect_to_wallet_up'] = isset($input['redirect_to_wallet_up']) ? (bool) $input['redirect_to_wallet_up'] : false;
        $sanitized['force_dashboard_replacement'] = isset($input['force_dashboard_replacement']) ? (bool) $input['force_dashboard_replacement'] : false;
        $sanitized['exempt_admin_roles'] = isset($input['exempt_admin_roles']) ? (bool) $input['exempt_admin_roles'] : false;

        // Sanitize text fields
        $sanitized['custom_logo_url'] = !empty($input['custom_logo_url']) 
            ? filter_var(esc_url_raw($input['custom_logo_url']), FILTER_VALIDATE_URL) 
            : '';
        
        // If URL validation fails, reset to empty
        if ($sanitized['custom_logo_url'] === false) {
            $sanitized['custom_logo_url'] = '';
        }
        
        // Sanitize colors
        $sanitized['primary_color'] = isset($input['primary_color']) ? sanitize_hex_color($input['primary_color']) : '#674FBF';
        $sanitized['gradient_start'] = isset($input['gradient_start']) ? sanitize_hex_color($input['gradient_start']) : '#674FBF';
        $sanitized['gradient_end'] = isset($input['gradient_end']) ? sanitize_hex_color($input['gradient_end']) : '#7B68D4';
        
        // Sanitize numbers
        $sanitized['redirect_delay'] = isset($input['redirect_delay']) ? absint($input['redirect_delay']) : 1500;
        
        // Sanitize arrays
        $sanitized['loading_messages'] = array();
        if (isset($input['loading_messages']) && is_array($input['loading_messages'])) {
            // Map translated messages back to English for storage
            $reverse_map = array(
                __('Verifying your credentials...', 'wallet-up-login-customizer') => 'Verifying your credentials...',
                __('Preparing your dashboard...', 'wallet-up-login-customizer') => 'Preparing your dashboard...',
                __('Almost there...', 'wallet-up-login-customizer') => 'Almost there...'
            );
            
            foreach ($input['loading_messages'] as $message) {
                if (!empty($message)) {
                    $clean_message = sanitize_text_field($message);
                    // If this is a translated default message, store English version
                    if (isset($reverse_map[$clean_message])) {
                        $sanitized['loading_messages'][] = $reverse_map[$clean_message];
                    } else {
                        // Custom message or already in English, store as-is
                        $sanitized['loading_messages'][] = $clean_message;
                    }
                }
            }
        }
        
        // Ensure we have at least one loading message
        if (empty($sanitized['loading_messages'])) {
            $sanitized['loading_messages'] = array('Verifying your credentials...');
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize security options
     */
    public function sanitize_security_options($input) {
        $sanitized = array();
        
        // Sanitize booleans
        $sanitized['force_login_enabled'] = isset($input['force_login_enabled']) ? (bool) $input['force_login_enabled'] : false;
        $sanitized['hide_wp_login'] = isset($input['hide_wp_login']) ? (bool) $input['hide_wp_login'] : false;
        
        // Sanitize custom login slug
        $sanitized['custom_login_slug'] = isset($input['custom_login_slug']) ? 
            sanitize_title($input['custom_login_slug']) : 'secure-login';
        
        // Ensure slug is not empty
        if (empty($sanitized['custom_login_slug'])) {
            $sanitized['custom_login_slug'] = 'secure-login';
        }
        
        // Sanitize numbers
        $sanitized['max_login_attempts'] = isset($input['max_login_attempts']) ? 
            max(1, min(20, absint($input['max_login_attempts']))) : 5;
        $sanitized['lockout_duration'] = isset($input['lockout_duration']) ? 
            max(60, min(86400, absint($input['lockout_duration']))) : 900;
        
        // Always include exempt roles for safety
        $sanitized['exempt_roles'] = ['administrator'];
        
        // Initialize empty arrays for other options
        $sanitized['whitelist_ips'] = [];
        $sanitized['security_headers'] = false;
        $sanitized['session_timeout'] = 3600;
        
        // Flush rewrite rules if custom login slug changed
        $old_options = get_option('wallet_up_login_customizer_security_options', []);
        if (isset($old_options['custom_login_slug']) && 
            $old_options['custom_login_slug'] !== $sanitized['custom_login_slug']) {
            flush_rewrite_rules();
        }
        
        return $sanitized;
    }
    
    /**
     * Render settings section
     */
    public function render_settings_section() {
        echo '<p>' . esc_html__('Customize the Wallet Up login experience with these settings.', 'wallet-up-login-customizer') . '</p>';
    }
    
    /**
     * Render checkbox field
     * 
     * @param array $args Field arguments
     */
    public function render_checkbox_field($args) {
        $options = get_option('wallet_up_login_customizer_options');
        $name = $args['name'];
        $checked = isset($options[$name]) ? $options[$name] : false;
        
        // Handle special case for wallet-up dependency
        $wallet_up_required = array('redirect_to_wallet_up', 'force_dashboard_replacement');
        if (in_array($name, $wallet_up_required)) {
            // Check if wallet-up-pro is available
            $wallet_up_available = false;
            if (!function_exists('is_plugin_active')) {
                include_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }
            $wallet_up_available = is_plugin_active('wallet-up/wallet-up.php') || 
                                 is_plugin_active('wallet-up-pro/wallet-up.php') ||
                                 is_plugin_active('walletup/walletup.php');
            
            if (!$wallet_up_available) {
                // Disable the checkbox and show a notice
                echo '<input type="checkbox" id="' . esc_attr($name) . '" name="wallet_up_login_customizer_options[' . esc_attr($name) . ']" value="1" disabled />';
                $message = ($name === 'redirect_to_wallet_up') 
                    ? __('Wallet Up Pro plugin is not active. Please install and activate it to redirect users to Wallet Up after login.', 'wallet-up-login-customizer')
                    : __('Wallet Up Pro plugin is not active. Please install and activate it to replace the WordPress dashboard.', 'wallet-up-login-customizer');
                echo '<span class="description" style="color: #d63638;">' . $message . '</span>';
                return;
            }
        }
        
        // Standard checkbox rendering
        echo '<input type="checkbox" id="' . esc_attr($name) . '" name="wallet_up_login_customizer_options[' . esc_attr($name) . ']" ' . checked($checked, true, false) . ' value="1" />';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    /**
     * Render text field
     * 
     * @param array $args Field arguments
     */
    public function render_text_field($args) {
        $options = get_option('wallet_up_login_customizer_options');
        $name = $args['name'];
        $value = isset($options[$name]) ? $options[$name] : '';
        
        echo '<input type="text" class="regular-text" id="' . esc_attr($name) . '" name="wallet_up_login_customizer_options[' . esc_attr($name) . ']" value="' . esc_attr($value) . '" />';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    /**
     * Render number field
     * 
     * @param array $args Field arguments
     */
    public function render_number_field($args) {
        $options = get_option('wallet_up_login_customizer_options');
        $name = $args['name'];
        $value = isset($options[$name]) ? $options[$name] : '';
        $min = isset($args['min']) ? 'min="' . esc_attr($args['min']) . '"' : '';
        $max = isset($args['max']) ? 'max="' . esc_attr($args['max']) . '"' : '';
        $step = isset($args['step']) ? 'step="' . esc_attr($args['step']) . '"' : '';
        
        echo '<input type="number" class="regular-text" id="' . esc_attr($name) . '" name="wallet_up_login_customizer_options[' . esc_attr($name) . ']" value="' . esc_attr($value) . '" ' . $min . ' ' . $max . ' ' . $step . ' />';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    /**
     * Render color field
     * 
     * @param array $args Field arguments
     */
    public function render_color_field($args) {
        $options = get_option('wallet_up_login_customizer_options');
        $name = $args['name'];
        $value = isset($options[$name]) ? $options[$name] : '#674FBF';
        
        echo '<input type="text" class="wallet-up-color-picker" id="' . esc_attr($name) . '" name="wallet_up_login_customizer_options[' . esc_attr($name) . ']" value="' . esc_attr($value) . '" data-default-color="#674FBF" />';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    /**
     * Render messages field
     * 
     * @param array $args Field arguments
     */
    public function render_messages_field($args) {
        $options = get_option('wallet_up_login_customizer_options');
        $name = $args['name'];
        $messages = isset($options[$name]) ? $options[$name] : array(__('Verifying your credentials...', 'wallet-up-login-customizer'));
        
        echo '<div id="loading-messages-container">';
        
        if (!empty($messages)) {
            foreach ($messages as $index => $message) {
                echo '<div class="loading-message">';
                echo '<input type="text" class="regular-text" name="wallet_up_login_customizer_options[' . esc_attr($name) . '][]" value="' . esc_attr($message) . '" />';
                echo '<a href="#" class="remove-loading-message">×</a>';
                echo '</div>';
            }
        }
        
        echo '</div>';
        
        echo '<button id="add-loading-message" class="button button-secondary">' . esc_html__('Add Message', 'wallet-up-login-customizer') . '</button>';
        
        // Add a template for new messages
        echo '<script type="text/html" id="loading-message-template">
            <div class="loading-message">
                <input type="text" class="regular-text" name="wallet_up_login_customizer_options[' . esc_attr($name) . '][]" value="" />
                <a href="#" class="remove-loading-message">×</a>
            </div>
        </script>';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    /**
     * Render network settings page for multisite
     */
    public function render_network_settings_page() {
        if (!current_user_can('manage_network_options')) {
            return;
        }
        
        // Handle form submission for network settings
        if (isset($_POST['submit']) && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wallet-up-network-settings')) {
            $this->save_network_settings();
        }
        
        // Handle export
        if (isset($_POST['export_network']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'wallet-up-export-network')) {
            $this->export_network_settings();
        }
        
        // Handle import
        if (isset($_POST['import_network']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'wallet-up-import-network')) {
            $this->import_network_settings();
        }
        
        // Get network options
        $network_options = get_site_option('wallet_up_network_settings', array(
            'force_on_all_sites' => false,
            'allow_site_override' => true,
            'network_wide_logo' => '',
            'network_primary_color' => '#674FBF',
            'network_gradient_start' => '#674FBF',
            'network_gradient_end' => '#7B68D4'
        ));
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Wallet Up Login Network Settings', 'wallet-up-login-customizer'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('wallet-up-network-settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Force on All Sites', 'wallet-up-login-customizer'); ?></th>
                        <td>
                            <input type="checkbox" name="force_on_all_sites" value="1" <?php checked($network_options['force_on_all_sites']); ?>>
                            <p class="description"><?php esc_html_e('Force Wallet Up Login customization on all network sites.', 'wallet-up-login-customizer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Allow Site Override', 'wallet-up-login-customizer'); ?></th>
                        <td>
                            <input type="checkbox" name="allow_site_override" value="1" <?php checked($network_options['allow_site_override']); ?>>
                            <p class="description"><?php esc_html_e('Allow individual sites to override network settings.', 'wallet-up-login-customizer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Network Logo URL', 'wallet-up-login-customizer'); ?></th>
                        <td>
                            <input type="url" name="network_wide_logo" value="<?php echo esc_attr($network_options['network_wide_logo']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Logo URL to use across all network sites.', 'wallet-up-login-customizer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Primary Color', 'wallet-up-login-customizer'); ?></th>
                        <td>
                            <input type="text" name="network_primary_color" value="<?php echo esc_attr($network_options['network_primary_color']); ?>" class="color-field">
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Network Settings', 'wallet-up-login-customizer')); ?>
            </form>
            
            <hr>
            
            <h2><?php esc_html_e('Import/Export Network Settings', 'wallet-up-login-customizer'); ?></h2>
            
            <div class="wallet-up-import-export">
                <h3><?php esc_html_e('Export Network Settings', 'wallet-up-login-customizer'); ?></h3>
                <p><?php esc_html_e('Export all network-wide settings for backup or migration.', 'wallet-up-login-customizer'); ?></p>
                <form method="post" action="">
                    <?php wp_nonce_field('wallet-up-export-network'); ?>
                    <input type="hidden" name="action" value="export_network_settings">
                    <?php submit_button(__('Export Network Settings', 'wallet-up-login-customizer'), 'secondary', 'export_network'); ?>
                </form>
                
                <h3><?php esc_html_e('Import Network Settings', 'wallet-up-login-customizer'); ?></h3>
                <p><?php esc_html_e('Import network settings from a backup file.', 'wallet-up-login-customizer'); ?></p>
                <form method="post" enctype="multipart/form-data" action="">
                    <?php wp_nonce_field('wallet-up-import-network'); ?>
                    <input type="hidden" name="action" value="import_network_settings">
                    <input type="file" name="import_file" accept=".json">
                    <?php submit_button(__('Import Network Settings', 'wallet-up-login-customizer'), 'secondary', 'import_network'); ?>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Save network settings
     */
    private function save_network_settings() {
        $network_options = array(
            'force_on_all_sites' => isset($_POST['force_on_all_sites']),
            'allow_site_override' => isset($_POST['allow_site_override']),
            'network_wide_logo' => sanitize_url($_POST['network_wide_logo'] ?? ''),
            'network_primary_color' => sanitize_hex_color($_POST['network_primary_color'] ?? '#674FBF'),
            'network_gradient_start' => sanitize_hex_color($_POST['network_gradient_start'] ?? '#674FBF'),
            'network_gradient_end' => sanitize_hex_color($_POST['network_gradient_end'] ?? '#7B68D4')
        );
        
        update_site_option('wallet_up_network_settings', $network_options);
        
        echo '<div class="notice notice-success"><p>' . esc_html__('Network settings saved successfully.', 'wallet-up-login-customizer') . '</p></div>';
    }
    
    /**
     * Export network settings
     */
    private function export_network_settings() {
        if (!current_user_can('manage_network_options')) {
            return;
        }
        
        // Get all network settings
        $export_data = array(
            'version' => WALLET_UP_LOGIN_CUSTOMIZER_VERSION,
            'type' => 'network_settings',
            'multisite' => true,
            'timestamp' => current_time('mysql'),
            'site_url' => network_site_url(),
            'network_settings' => get_site_option('wallet_up_network_settings', array()),
            'network_security' => get_site_option('wallet_up_network_security', array())
        );
        
        // Add site-specific settings if needed
        if (isset($_POST['include_all_sites'])) {
            $sites = get_sites();
            $export_data['sites'] = array();
            
            foreach ($sites as $site) {
                switch_to_blog($site->blog_id);
                $export_data['sites'][$site->blog_id] = array(
                    'domain' => $site->domain,
                    'path' => $site->path,
                    'login_options' => get_option('wallet_up_login_customizer_options', array()),
                    'security_options' => get_option('wallet_up_login_customizer_security_options', array()),
                    'logo_settings' => get_option('wallet_up_logo_settings', array())
                );
                restore_current_blog();
            }
        }
        
        // Create filename
        $filename = 'wallet-up-network-settings-' . date('Y-m-d-His') . '.json';
        
        // Send headers
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        
        // Output JSON
        echo wp_json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Import network settings
     */
    private function import_network_settings() {
        if (!current_user_can('manage_network_options')) {
            return;
        }
        
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Please select a valid file to import.', 'wallet-up-login-customizer') . '</p></div>';
            return;
        }
        
        // Check file size to prevent DoS (max 1MB)
        if ($_FILES['import_file']['size'] > 1048576) {
            echo '<div class="notice notice-error"><p>' . esc_html__('File size exceeds 1MB limit.', 'wallet-up-login-customizer') . '</p></div>';
            return;
        }
        
        // Check file type
        $file_info = wp_check_filetype($_FILES['import_file']['name']);
        if ($file_info['ext'] !== 'json') {
            echo '<div class="notice notice-error"><p>' . esc_html__('Only JSON files are allowed.', 'wallet-up-login-customizer') . '</p></div>';
            return;
        }
        
        $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
        $import_data = json_decode($file_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Invalid JSON file.', 'wallet-up-login-customizer') . '</p></div>';
            return;
        }
        
        // Verify it's a network settings export
        if (!isset($import_data['type']) || $import_data['type'] !== 'network_settings') {
            echo '<div class="notice notice-error"><p>' . esc_html__('This is not a valid network settings export file.', 'wallet-up-login-customizer') . '</p></div>';
            return;
        }
        
        // Import network settings
        if (isset($import_data['network_settings'])) {
            update_site_option('wallet_up_network_settings', $import_data['network_settings']);
        }
        
        if (isset($import_data['network_security'])) {
            update_site_option('wallet_up_network_security', $import_data['network_security']);
        }
        
        // Import site-specific settings if available
        if (isset($import_data['sites']) && isset($_POST['import_all_sites'])) {
            foreach ($import_data['sites'] as $blog_id => $site_settings) {
                if (get_blog_details($blog_id)) {
                    switch_to_blog($blog_id);
                    
                    if (isset($site_settings['login_options'])) {
                        update_option('wallet_up_login_customizer_options', $site_settings['login_options']);
                    }
                    if (isset($site_settings['security_options'])) {
                        update_option('wallet_up_login_customizer_security_options', $site_settings['security_options']);
                    }
                    if (isset($site_settings['logo_settings'])) {
                        update_option('wallet_up_logo_settings', $site_settings['logo_settings']);
                    }
                    
                    restore_current_blog();
                }
            }
        }
        
        echo '<div class="notice notice-success"><p>' . esc_html__('Network settings imported successfully.', 'wallet-up-login-customizer') . '</p></div>';
    }
    
    public function render_settings_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Check if form is submitted for reinstall
        if (isset($_POST['wallet_up_reinstall']) && current_user_can('manage_options')) {
            check_admin_referer('wallet_up_reinstall_nonce');
            
            // Try to reinstall files
            $result = $this->install_files(true);
            
            if ($result) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Enhanced login files have been installed successfully!', 'wallet-up-login-customizer') . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Failed to install enhanced login files. Please check file permissions.', 'wallet-up-login-customizer') . '</p></div>';
            }
        }

            /**
             * Updated code for the custom logo URL field
             * This sets the default to /img/walletup-icon.png from the plugin directory
             */

            // Get the custom logo URL from options
            $options = get_option('wallet_up_login_customizer_options');
            $custom_logo_url = isset($options['custom_logo_url']) ? $options['custom_logo_url'] : '';

            // If custom logo URL is empty, use the default plugin logo
            if (empty($custom_logo_url)) {
                $custom_logo_url = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL . 'img/walletup-icon.png';
            }


        
        // Display the settings form
        ?>
<div class="wallet-up-premium-header-wrapper">
    <div class="wallet-up-premium-header">
        <div class="wallet-up-header-sparkle wallet-up-sparkle-1">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2L14.09 8.26L20.5 9.27L16.5 13.14L17.45 19.5L12 16.77L6.55 19.5L7.5 13.14L3.5 9.27L9.91 8.26L12 2Z" fill="currentColor" opacity="0.3"/>
            </svg>
        </div>
        <div class="wallet-up-header-sparkle wallet-up-sparkle-2">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2L14.09 8.26L20.5 9.27L16.5 13.14L17.45 19.5L12 16.77L6.55 19.5L7.5 13.14L3.5 9.27L9.91 8.26L12 2Z" fill="currentColor" opacity="0.4"/>
            </svg>
        </div>
        <h1 class="wallet-up-premium-title">
            <svg class="wallet-up-title-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M9.73 2.5L12.5 8.5L19 9.27L14.5 13.14L15.5 19.5L9.73 16.77L4 19.5L5 13.14L0.5 9.27L7 8.5L9.73 2.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M19 9L20 6L23 7L20 8L19 11L18 8L15 7L18 6L19 9Z" fill="currentColor" opacity="0.8"/>
            </svg>
            <span class="wallet-up-title-main"><?php echo esc_html__('Premium Login Customizer', 'wallet-up-login-customizer'); ?></span>
            <span class="wallet-up-title-by"><?php echo esc_html__('by Wallet Up', 'wallet-up-login-customizer'); ?></span>
        </h1>
        <div class="wallet-up-header-badge">
            <span>v<?php echo WALLET_UP_LOGIN_CUSTOMIZER_VERSION; ?></span>
            <svg class="wallet-up-lock-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
    </div>
</div>
    <!-- Removed .wrap div to fix notice positioning -->
    <div class="wallet-up-admin-container">
        <div class="wallet-up-admin-content">
            <!-- Tabbed navigation -->
            <div id="wallet-up-settings-form">
                <div class="wallet-up-tab-nav">
                    <a href="#general-settings" class="settings-tab active">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php echo esc_html__('General', 'wallet-up-login-customizer'); ?>
                    </a>
                    <a href="#appearance-settings" class="settings-tab">
                        <span class="dashicons dashicons-admin-appearance"></span>
                        <?php echo esc_html__('Appearance', 'wallet-up-login-customizer'); ?>
                    </a>
                    <a href="#messages-settings" class="settings-tab">
                        <span class="dashicons dashicons-admin-comments"></span>
                        <?php echo esc_html__('Messages', 'wallet-up-login-customizer'); ?>
                    </a>
                    <a href="#advanced-settings" class="settings-tab">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <?php echo esc_html__('Advanced', 'wallet-up-login-customizer'); ?>
                    </a>
                </div>
                
                <div class="settings-panels">
                    <form method="post" action="options.php">
                        <?php settings_fields('wallet_up_login_customizer_options'); ?>
                        
                        <!-- General Settings Panel -->
                        <div id="general-settings" class="settings-panel active">
                            <h2><?php echo esc_html__('General Settings', 'wallet-up-login-customizer'); ?></h2>
                            <p><?php echo esc_html__('Configure basic login functionality and behavior.', 'wallet-up-login-customizer'); ?></p>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="enable_ajax_login"><?php echo esc_html__('AJAX Login', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $options = get_option('wallet_up_login_customizer_options');
                                        $enable_ajax_login = isset($options['enable_ajax_login']) ? $options['enable_ajax_login'] : true;
                                        ?>
                                        <input type="checkbox" id="enable_ajax_login" name="wallet_up_login_customizer_options[enable_ajax_login]" <?php checked($enable_ajax_login, true); ?> value="1" />
                                        <label for="enable_ajax_login"><?php echo esc_html__('Enable AJAX login functionality', 'wallet-up-login-customizer'); ?></label>
                                        <p class="description"><?php echo esc_html__('Provides a smoother login experience with visual feedback and no page reload.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="enable_sounds"><?php echo esc_html__('Sound Effects', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $enable_sounds = isset($options['enable_sounds']) ? $options['enable_sounds'] : true;
                                        ?>
                                        <input type="checkbox" id="enable_sounds" name="wallet_up_login_customizer_options[enable_sounds]" <?php checked($enable_sounds, true); ?> value="1" />
                                        <label for="enable_sounds"><?php echo esc_html__('Enable subtle audio feedback', 'wallet-up-login-customizer'); ?></label>
                                        <p class="description"><?php echo esc_html__('Play subtle sounds on login success/failure for better feedback.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="redirect_delay"><?php echo esc_html__('Redirect Delay', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $redirect_delay = isset($options['redirect_delay']) ? intval($options['redirect_delay']) : 1500;
                                        ?>
                                        <input type="number" class="regular-text" id="redirect_delay" name="wallet_up_login_customizer_options[redirect_delay]" value="<?php echo esc_attr($redirect_delay); ?>" min="0" max="5000" step="100" />
                                        <p class="description"><?php echo esc_html__('Delay in milliseconds before redirecting after successful login (0-5000).', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="dashboard_redirect"><?php echo esc_html__('Role-Based Redirects', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $dashboard_redirect = isset($options['dashboard_redirect']) ? $options['dashboard_redirect'] : true;
                                        ?>
                                        <input type="checkbox" id="dashboard_redirect" name="wallet_up_login_customizer_options[dashboard_redirect]" <?php checked($dashboard_redirect, true); ?> value="1" />
                                        <label for="dashboard_redirect"><?php echo esc_html__('Enable intelligent redirects', 'wallet-up-login-customizer'); ?></label>
                                        <p class="description"><?php echo esc_html__('Direct users to relevant areas based on their role (admins to dashboard, etc).', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>

                                <!-- settings page for controlling dashboard replacement -->

                                <tr>
                                    <th scope="row">
                                        <label for="force_dashboard_replacement"><?php echo esc_html__('Force Dashboard Replacement', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $force_dashboard_replacement = isset($options['force_dashboard_replacement']) ? $options['force_dashboard_replacement'] : false;
                                        ?>
                                        <input type="checkbox" id="force_dashboard_replacement" name="wallet_up_login_customizer_options[force_dashboard_replacement]" <?php checked($force_dashboard_replacement, true); ?> value="1" />
                                        <label for="force_dashboard_replacement"><?php echo esc_html__('Replace WordPress dashboard', 'wallet-up-login-customizer'); ?></label>
                                        <p class="description"><?php echo esc_html__('When enabled, the WordPress dashboard will be completely replaced with the Wallet Up page. This affects all users.', 'wallet-up-login-customizer'); ?></p>
                                        <p class="description" style="color: #0073aa; font-style: italic;"><?php echo esc_html__('💡 This works independently - no need to enable "Land to Wallet Up".', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="exempt_admin_roles"><?php echo esc_html__('Exempt Administrator Roles', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $exempt_admin_roles = isset($options['exempt_admin_roles']) ? $options['exempt_admin_roles'] : true;
                                        ?>
                                        <input type="checkbox" id="exempt_admin_roles" name="wallet_up_login_customizer_options[exempt_admin_roles]" <?php checked($exempt_admin_roles, true); ?> value="1" />
                                        <label for="exempt_admin_roles"><?php echo esc_html__('Allow administrators to access default dashboard', 'wallet-up-login-customizer'); ?></label>
                                        <p class="description"><?php echo esc_html__('When enabled, administrators can still access the default WordPress dashboard.', 'wallet-up-login-customizer'); ?></p>
                                        <p class="description" style="color: #059669; font-style: italic;"><?php echo esc_html__('✅ Recommended: Keeps admin access for troubleshooting and configuration.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <!-- New Land to Wallet Up option -->
                                <tr>
                                    <th scope="row">
                                        <label for="redirect_to_wallet_up"><?php echo esc_html__('Land to Wallet Up', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $redirect_to_wallet_up = isset($options['redirect_to_wallet_up']) ? $options['redirect_to_wallet_up'] : false;
                                        ?>
                                        <input type="checkbox" id="redirect_to_wallet_up" name="wallet_up_login_customizer_options[redirect_to_wallet_up]" <?php checked($redirect_to_wallet_up, true); ?> value="1" />
                                        <label for="redirect_to_wallet_up"><?php echo esc_html__('Redirect to Wallet Up admin page', 'wallet-up-login-customizer'); ?></label>
                                        <p class="description"><?php echo esc_html__('After login, redirect users directly to the Wallet Up admin page (admin.php?page=wallet-up).', 'wallet-up-login-customizer'); ?></p>
                                        <p class="description" style="color: #d63638; font-style: italic;"><?php echo esc_html__('⚠️ This is a LOGIN redirect only. For dashboard replacement, use "Force Dashboard Replacement".', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Appearance Settings Panel -->
                        <div id="appearance-settings" class="settings-panel">
                            <h2><?php echo esc_html__('Appearance Settings', 'wallet-up-login-customizer'); ?></h2>
                            <p><?php echo esc_html__('Customize the look and feel of your login page.', 'wallet-up-login-customizer'); ?></p>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="custom_logo_url"><?php echo esc_html__('Custom Logo URL', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $custom_logo_url = isset($options['custom_logo_url']) ? $options['custom_logo_url'] : '';
                                        ?>
                                        <input type="text" class="regular-text" id="custom_logo_url" name="wallet_up_login_customizer_options[custom_logo_url]" 
                                            value="<?php echo esc_attr($custom_logo_url); ?>" placeholder="https://walletup.app/up/assets/images/walletup-icon.png" />
                                        <button type="button" class="button button-secondary" id="upload_logo_button"><?php echo esc_html__('Select Image', 'wallet-up-login-customizer'); ?></button>
                                        <p class="description"><?php echo esc_html__('Enter a URL to use a custom logo. Leave empty to use the default Wallet Up icon.', 'wallet-up-login-customizer'); ?></p>

                                        <?php if (!empty($custom_logo_url)) : ?>
                                        <div class="logo-preview">
                                            <img src="<?php echo esc_url($custom_logo_url); ?>" alt="Logo Preview" style="max-width: 100px; max-height: 100px; margin-top: 10px; border: 1px solid #ddd; border-radius: 4px; padding: 5px;" />
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label><?php echo esc_html__('Color Scheme', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <div class="color-scheme-presets">
                                            <div class="color-preset" data-preset="purple" title="Purple"></div>
                                            <div class="color-preset" data-preset="blue" title="Blue"></div>
                                            <div class="color-preset" data-preset="green" title="Green"></div>
                                            <div class="color-preset" data-preset="red" title="Red"></div>
                                            <div class="color-preset" data-preset="orange" title="Orange"></div>
                                            <div class="color-preset" data-preset="dark" title="Dark"></div>
                                        </div>
                                        <p class="description"><?php echo esc_html__('Select a preset color scheme or customize colors below.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="primary_color"><?php echo esc_html__('Primary Color', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $primary_color = isset($options['primary_color']) ? $options['primary_color'] : '#674FBF';
                                        ?>
                                        <input type="text" class="wallet-up-color-picker" id="primary_color" name="wallet_up_login_customizer_options[primary_color]" value="<?php echo esc_attr($primary_color); ?>" data-default-color="#674FBF" />
                                        <p class="description"><?php echo esc_html__('The main color used throughout the login page.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="gradient_start"><?php echo esc_html__('Gradient Start', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $gradient_start = isset($options['gradient_start']) ? $options['gradient_start'] : '#674FBF';
                                        ?>
                                        <input type="text" class="wallet-up-color-picker" id="gradient_start" name="wallet_up_login_customizer_options[gradient_start]" value="<?php echo esc_attr($gradient_start); ?>" data-default-color="#674FBF" />
                                        <p class="description"><?php echo esc_html__('Start color for gradient effects.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="gradient_end"><?php echo esc_html__('Gradient End', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $gradient_end = isset($options['gradient_end']) ? $options['gradient_end'] : '#7B68D4';
                                        ?>
                                        <input type="text" class="wallet-up-color-picker" id="gradient_end" name="wallet_up_login_customizer_options[gradient_end]" value="<?php echo esc_attr($gradient_end); ?>" data-default-color="#7B68D4" />
                                        <p class="description"><?php echo esc_html__('End color for gradient effects.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label><?php echo esc_html__('Color Preview', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <div class="color-preview-container">
                                            <div class="color-preview color-preview-primary" data-label="<?php echo esc_attr__('Primary', 'wallet-up-login-customizer'); ?>"></div>
                                            <div class="color-preview color-preview-gradient" data-label="<?php echo esc_attr__('Gradient', 'wallet-up-login-customizer'); ?>"></div>
                                        </div>
                                        
                                        <div class="button-preview"><?php echo esc_html__('Sign In Securely', 'wallet-up-login-customizer'); ?></div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Messages Settings Panel -->
                        <div id="messages-settings" class="settings-panel">
                            <h2><?php echo esc_html__('Messages Settings', 'wallet-up-login-customizer'); ?></h2>
                            <p><?php echo esc_html__('Customize the messages displayed during login.', 'wallet-up-login-customizer'); ?></p>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label><?php echo esc_html__('Loading Messages', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <div id="loading-messages-container">
                                            <?php
                                            $loading_messages = isset($options['loading_messages']) ? $options['loading_messages'] : array(
                                                'Verifying your credentials...',
                                                'Preparing your dashboard...',
                                                'Almost there...'
                                            );
                                            
                                            if (!empty($loading_messages)) {
                                                foreach ($loading_messages as $index => $message) {
                                                    // Translate default messages for display
                                                    $display_message = $message;
                                                    if ($message === 'Verifying your credentials...') {
                                                        $display_message = __('Verifying your credentials...', 'wallet-up-login-customizer');
                                                    } elseif ($message === 'Preparing your dashboard...') {
                                                        $display_message = __('Preparing your dashboard...', 'wallet-up-login-customizer');
                                                    } elseif ($message === 'Almost there...') {
                                                        $display_message = __('Almost there...', 'wallet-up-login-customizer');
                                                    }
                                                    
                                                    echo '<div class="loading-message">';
                                                    echo '<input type="text" class="regular-text" name="wallet_up_login_customizer_options[loading_messages][]" value="' . esc_attr($display_message) . '" />';
                                                    echo '<a href="#" class="remove-loading-message">×</a>';
                                                    echo '</div>';
                                                }
                                            }
                                            ?>
                                        </div>
                                        
                                        <button id="add-loading-message" class="button button-secondary"><?php echo esc_html__('Add Message', 'wallet-up-login-customizer'); ?></button>
                                        
                                        <script type="text/html" id="loading-message-template">
                                            <div class="loading-message">
                                                <input type="text" class="regular-text" name="wallet_up_login_customizer_options[loading_messages][]" value="" />
                                                <a href="#" class="remove-loading-message">×</a>
                                            </div>
                                        </script>
                                        
                                        <p class="description"><?php echo esc_html__('Messages displayed during login. One will be randomly selected each time.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Advanced Settings Panel -->
                        <div id="advanced-settings" class="settings-panel">
                            <h2><?php echo esc_html__('Advanced Settings', 'wallet-up-login-customizer'); ?></h2>
                            <p><?php echo esc_html__('Configure advanced options for the login experience.', 'wallet-up-login-customizer'); ?></p>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="show_remember_me"><?php echo esc_html__('Remember Me', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        $show_remember_me = isset($options['show_remember_me']) ? $options['show_remember_me'] : true;
                                        ?>
                                        <input type="checkbox" id="show_remember_me" name="wallet_up_login_customizer_options[show_remember_me]" <?php checked($show_remember_me, true); ?> value="1" />
                                        <label for="show_remember_me"><?php echo esc_html__('Show remember me checkbox', 'wallet-up-login-customizer'); ?></label>
                                        <p class="description"><?php echo esc_html__('Display the "Remember Me" option on the login form.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <?php
                                // Get security options
                                $security_options = get_option('wallet_up_login_customizer_security_options', [
                                    'force_login_enabled' => false,
                                    'hide_wp_login' => false,
                                    'custom_login_slug' => 'secure-login',
                                    'max_login_attempts' => 5,
                                    'lockout_duration' => 900
                                ]);
                                ?>
                                
                                <tr>
                                    <th colspan="2">
                                        <h3><?php echo esc_html__('🛡️ Enterprise Security', 'wallet-up-login-customizer'); ?></h3>
                                        <p><?php echo esc_html__('Advanced security features for protecting your login system.', 'wallet-up-login-customizer'); ?></p>
                                    </th>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="force_login_enabled"><?php echo esc_html__('Force Login', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <input type="checkbox" id="force_login_enabled" name="wallet_up_login_customizer_security_options[force_login_enabled]" value="1" <?php checked($security_options['force_login_enabled']); ?> />
                                        <label for="force_login_enabled"><?php echo esc_html__('Require users to login before accessing the website', 'wallet-up-login-customizer'); ?></label>
                                        <p class="description"><?php echo esc_html__('Visitors must authenticate before viewing any content. Registration and password reset still work normally.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="hide_wp_login"><?php echo esc_html__('Hide wp-login.php', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <input type="checkbox" id="hide_wp_login" name="wallet_up_login_customizer_security_options[hide_wp_login]" value="1" <?php checked($security_options['hide_wp_login']); ?> />
                                        <label for="hide_wp_login"><?php echo esc_html__('Hide the default WordPress login page', 'wallet-up-login-customizer'); ?></label>
                                        <p class="description"><?php echo esc_html__('Direct access to wp-login.php will be redirected to your custom login URL.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="custom_login_slug"><?php echo esc_html__('Custom Login URL', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="custom_login_slug" name="wallet_up_login_customizer_security_options[custom_login_slug]" value="<?php echo esc_attr($security_options['custom_login_slug']); ?>" class="regular-text" />
                                        <p class="description">
                                            <?php 
                                            $custom_url = home_url('/?' . $security_options['custom_login_slug'] . '=1');
                                            echo esc_html__('Your custom login URL:', 'wallet-up-login-customizer') . ' ';
                                            printf('<a href="%s" target="_blank">%s</a>', esc_url($custom_url), esc_html($custom_url)); 
                                            ?>
                                        </p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="max_login_attempts"><?php echo esc_html__('Max Login Attempts', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="max_login_attempts" name="wallet_up_login_customizer_security_options[max_login_attempts]" value="<?php echo esc_attr($security_options['max_login_attempts']); ?>" min="1" max="20" />
                                        <p class="description"><?php echo esc_html__('Maximum failed login attempts before temporary lockout.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="lockout_duration"><?php echo esc_html__('Lockout Duration', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <select id="lockout_duration" name="wallet_up_login_customizer_security_options[lockout_duration]">
                                            <option value="300" <?php selected($security_options['lockout_duration'], 300); ?>>5 <?php echo esc_html__('minutes', 'wallet-up-login-customizer'); ?></option>
                                            <option value="900" <?php selected($security_options['lockout_duration'], 900); ?>>15 <?php echo esc_html__('minutes', 'wallet-up-login-customizer'); ?></option>
                                            <option value="1800" <?php selected($security_options['lockout_duration'], 1800); ?>>30 <?php echo esc_html__('minutes', 'wallet-up-login-customizer'); ?></option>
                                            <option value="3600" <?php selected($security_options['lockout_duration'], 3600); ?>>1 <?php echo esc_html__('hour', 'wallet-up-login-customizer'); ?></option>
                                        </select>
                                        <p class="description"><?php echo esc_html__('How long to lock out users after too many failed attempts.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            
                            <div class="settings-actions">
                                <a href="#" id="export-settings" class="button button-secondary">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php echo esc_html__('Export Settings', 'wallet-up-login-customizer'); ?>
                                </a>
                                
                                <a href="#" id="import-settings" class="button button-secondary">
                                    <span class="dashicons dashicons-upload"></span>
                                    <?php echo esc_html__('Import Settings', 'wallet-up-login-customizer'); ?>
                                </a>
                                <input type="file" id="import-file" accept=".json">
                                
                                <a href="#" id="reset-settings" class="button button-secondary button-reset">
                                    <span class="dashicons dashicons-image-rotate"></span>
                                    <?php echo esc_html__('Reset to Defaults', 'wallet-up-login-customizer'); ?>
                                </a>
                            </div>
                        </div>
                        
                        <?php submit_button(__('Save Settings', 'wallet-up-login-customizer')); ?>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="wallet-up-admin-sidebar">
            <div class="wallet-up-admin-box has-brand-bar">
                <h3><?php echo esc_html__('Preview Login Page', 'wallet-up-login-customizer'); ?></h3>
                <p><?php echo esc_html__('See how your login page looks with current settings.', 'wallet-up-login-customizer'); ?></p>
                <a href="<?php echo wp_login_url(); ?>?preview=true" class="preview-button" target="_blank">
                    <?php echo esc_html__('Open Preview', 'wallet-up-login-customizer'); ?>
                    <span class="preview-icon dashicons dashicons-arrow-right-alt"></span>
                </a>
            </div>
            
            <div class="wallet-up-admin-box">
                <h3><?php echo esc_html__('Reinstall Files', 'wallet-up-login-customizer'); ?></h3>
                <p><?php echo esc_html__('If you need to reset the customizations, you can reinstall the enhanced login files.', 'wallet-up-login-customizer'); ?></p>
                
                <div class="wallet-up-files-info">
                    <div class="wallet-up-files-header">
                        <svg class="wallet-up-files-icon" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M8.33334 2.5H4.16667C3.24619 2.5 2.5 3.24619 2.5 4.16667V15.8333C2.5 16.7538 3.24619 17.5 4.16667 17.5H15.8333C16.7538 17.5 17.5 16.7538 17.5 15.8333V7.5M8.33334 2.5L17.5 7.5M8.33334 2.5V7.5H17.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span class="wallet-up-files-title"><?php echo esc_html__('Required Files & Paths:', 'wallet-up-login-customizer'); ?></span>
                    </div>
                    <div class="wallet-up-files-list">
                        <div class="wallet-up-file-item">
                            <span class="wallet-up-file-path"><code title="/css/wallet-up-login-customizer.css">/css/wallet-up-login-customizer.css</code></span>
                            <span class="wallet-up-file-desc"><?php echo esc_html__('Login page styles', 'wallet-up-login-customizer'); ?></span>
                        </div>
                        <div class="wallet-up-file-item">
                            <span class="wallet-up-file-path"><code title="/css/wallet-up-login-customizer-admin.css">/css/wallet-up-login-customizer-admin.css</code></span>
                            <span class="wallet-up-file-desc"><?php echo esc_html__('Admin interface styles', 'wallet-up-login-customizer'); ?></span>
                        </div>
                        <div class="wallet-up-file-item">
                            <span class="wallet-up-file-path"><code title="/js/wallet-up-login-customizer.js">/js/wallet-up-login-customizer.js</code></span>
                            <span class="wallet-up-file-desc"><?php echo esc_html__('Login page functionality', 'wallet-up-login-customizer'); ?></span>
                        </div>
                        <div class="wallet-up-file-item">
                            <span class="wallet-up-file-path"><code title="/js/wallet-up-login-customizer-admin.js">/js/wallet-up-login-customizer-admin.js</code></span>
                            <span class="wallet-up-file-desc"><?php echo esc_html__('Admin interface scripts', 'wallet-up-login-customizer'); ?></span>
                        </div>
                        <div class="wallet-up-file-item">
                            <span class="wallet-up-file-path"><code title="/includes/class-wallet-up-login-customizer.php">/includes/class-wallet-up-login-customizer.php</code></span>
                            <span class="wallet-up-file-desc"><?php echo esc_html__('Core functionality', 'wallet-up-login-customizer'); ?></span>
                        </div>
                    </div>
                    <div class="wallet-up-files-note">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M8 4V8M8 12H8.01M14 8C14 11.3137 11.3137 14 8 14C4.68629 14 2 11.3137 2 8C2 4.68629 4.68629 2 8 2C11.3137 2 14 4.68629 14 8Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <em><?php echo esc_html__('Source files are in /assets/ directory', 'wallet-up-login-customizer'); ?></em>
                    </div>
                </div>
                
                <form method="post" action="">
                    <?php wp_nonce_field('wallet_up_reinstall_nonce'); ?>
                    <input type="submit" name="wallet_up_reinstall" class="button button-primary" value="<?php echo esc_attr__('Reinstall Files', 'wallet-up-login-customizer'); ?>">
                    <p class="description" style="color: #d63638; font-style: italic;"><?php echo esc_html__('⚠️ This will overwrite existing customizations. Use only if files are missing or corrupted.', 'wallet-up-login-customizer'); ?></p>
                </form>
            </div>
            
            <div class="wallet-up-admin-box">
                <h3><?php echo esc_html__('About', 'wallet-up-login-customizer'); ?></h3>
                <p><?php echo esc_html__('Creates a beautiful interactive login experience for Wallet Up and WordPress users.', 'wallet-up-login-customizer'); ?></p>
                <p><strong><?php echo esc_html__('Version', 'wallet-up-login-customizer'); ?>:</strong> <?php echo WALLET_UP_LOGIN_CUSTOMIZER_VERSION; ?></p>
            </div>
        </div>
    </div>
	</div>
        <?php
    }


/**
 * Check if current user should be exempt from dashboard replacement
 * 
 * @return bool True if user should be exempt, false otherwise
 */
private function is_user_exempt_from_dashboard_replacement() {
    // SECURITY: Always allow exemption if Wallet Up is not available
    if (!$this->is_wallet_up_available()) {
        return true;
    }
    
    // Get plugin options
    $options = get_option('wallet_up_login_customizer_options', []);
    
    // CRITICAL FIX: Proper handling of exempt_admin_roles option
    // Default to true (recommended setting) if not explicitly set to false
    $exempt_admins = true; // Safe default
    if (isset($options['exempt_admin_roles'])) {
        // Only change default if explicitly set
        $exempt_admins = !empty($options['exempt_admin_roles']);
    }
    
    // IMPORTANT: If administrators are exempt and current user is an admin, return true
    if ($exempt_admins && current_user_can('administrator')) {
        return true;
    }
    
    // Check for dashboard access override capability
    if (current_user_can('access_wp_dashboard')) {
        return true;
    }
    
    // SECURITY: Check for emergency override
    // SECURITY: Validate GET parameter
    $show_dashboard = isset($_GET['show_wp_dashboard']) ? sanitize_text_field($_GET['show_wp_dashboard']) : '';
    if ($show_dashboard === '1' && current_user_can('manage_options')) {
        return true;
    }
    
    return false;
}

/**
 * Add required capabilities to override dashboard replacement
 */
public function add_dashboard_override_capability() {
    // Add a capability to the administrator role to override dashboard replacement
    $role = get_role('administrator');
    if ($role && !$role->has_cap('access_wp_dashboard')) {
        $role->add_cap('access_wp_dashboard');
    }
}

/**
 * Add support link to access original dashboard
 */
public function add_dashboard_access_link($wp_admin_bar) {
    // Only show for users with appropriate capability
    if (!current_user_can('administrator') && !current_user_can('access_wp_dashboard')) {
        return;
    }
    
    $wp_admin_bar->add_node(array(
        'id'    => 'view-wp-dashboard',
        'title' => __('WordPress Dashboard', 'wallet-up-login-customizer'),
        'href'  => add_query_arg('show_wp_dashboard', '1', admin_url()),
        'meta'  => array(
            'class' => 'wp-dashboard-access-link',
        ),
    ));
}

/**
 * Handle AJAX dismissal of language notice
 */
public function dismiss_language_notice() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wallet_up_dismiss_notice')) {
        wp_die(esc_html__('Security check failed', 'wallet-up-login-customizer'));
    }
    
    // Get the locale
    $locale = isset($_POST['locale']) ? sanitize_text_field($_POST['locale']) : '';
    
    if ($locale) {
        // Get existing dismissed notices
        $dismissed_notices = get_user_meta(get_current_user_id(), 'wallet_up_dismissed_language_notices', true);
        if (!is_array($dismissed_notices)) {
            $dismissed_notices = array();
        }
        
        // Add this locale with current timestamp
        $dismissed_notices[$locale] = time();
        
        // Save back to user meta
        update_user_meta(get_current_user_id(), 'wallet_up_dismissed_language_notices', $dismissed_notices);
    }
    
    wp_die();
}

/**
 * Display language availability notice
 */
public function language_availability_notice() {
    // Only show on our plugin settings page or dashboard
    $screen = get_current_screen();
    if (!$screen || ($screen->id !== 'settings_page_wallet-up-login' && $screen->id !== 'dashboard')) {
        return;
    }
    
    // Only show to administrators
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Get current WordPress locale
    $current_locale = get_locale();
    
    // Skip if English (default language)
    if (strpos($current_locale, 'en_') === 0 || $current_locale === 'en' || $current_locale === 'en_US') {
        return;
    }
    
    // Check if user has dismissed this notice for current locale
    $dismissed_notices = get_user_meta(get_current_user_id(), 'wallet_up_dismissed_language_notices', true);
    if (!is_array($dismissed_notices)) {
        $dismissed_notices = array();
    }
    
    // If dismissed for this locale within last 30 days, don't show
    if (isset($dismissed_notices[$current_locale]) && 
        $dismissed_notices[$current_locale] > (time() - (30 * DAY_IN_SECONDS))) {
        return;
    }
    
    // Get available translations dynamically from the languages directory
    $languages_dir = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'languages/';
    $available_translations = array();
    
    if (is_dir($languages_dir)) {
        $files = scandir($languages_dir);
        foreach ($files as $file) {
            // Look for .mo files (compiled translations)
            if (preg_match('/wallet-up-login-customizer-([a-z]{2}_[A-Z]{2})\.mo$/', $file, $matches)) {
                $available_translations[] = $matches[1];
            }
        }
    }
    
    // Check if current locale is available
    $is_available = in_array($current_locale, $available_translations);
    
    // If translation is available, no need to show notice
    if ($is_available) {
        return;
    }
    
    // Find closest match (same language, different region)
    $current_lang = substr($current_locale, 0, 2);
    $closest_match = null;
    
    foreach ($available_translations as $translation) {
        if (substr($translation, 0, 2) === $current_lang) {
            $closest_match = $translation;
            break;
        }
    }
    
    // Prepare the message
    $locale_names = array(
        'es_ES' => 'Spanish (Spain)',
        'es_PE' => 'Spanish (Peru)',
        'fr_FR' => 'French (France)',
        'de_DE' => 'German (Germany)',
        'it_IT' => 'Italian (Italy)',
        'pt_BR' => 'Portuguese (Brazil)',
        'pt_PT' => 'Portuguese (Portugal)',
        'ja' => 'Japanese',
        'zh_CN' => 'Chinese (Simplified)',
        'zh_TW' => 'Chinese (Traditional)',
        'ru_RU' => 'Russian',
        'ar' => 'Arabic',
        'he_IL' => 'Hebrew (Israel)',
        'nl_NL' => 'Dutch (Netherlands)',
        'pl_PL' => 'Polish (Poland)',
        'ko_KR' => 'Korean (Korea)'
    );
    
    $current_name = isset($locale_names[$current_locale]) ? $locale_names[$current_locale] : $current_locale;
    
    // Add unique ID for dismissal tracking
    $notice_id = 'wallet-up-lang-' . $current_locale;
    echo '<div class="notice notice-info is-dismissible wallet-up-language-notice" data-locale="' . esc_attr($current_locale) . '" id="' . esc_attr($notice_id) . '" style="padding: 12px; border-left-color: #674FBF;">';
    
    // Add inline script for dismissal tracking
    echo '<script>
    jQuery(document).ready(function($) {
        $("#' . esc_js($notice_id) . '").on("click", ".notice-dismiss", function() {
            $.post(ajaxurl, {
                action: "wallet_up_dismiss_language_notice",
                locale: "' . esc_js($current_locale) . '",
                nonce: "' . wp_create_nonce('wallet_up_dismiss_notice') . '"
            });
        });
    });
    </script>';
    echo '<p style="margin: 0; font-size: 14px; display: flex; align-items: center; gap: 8px;">';
    echo '<span class="dashicons dashicons-translation" style="color: #674FBF; font-size: 20px; width: 20px; height: 20px;"></span>';
    echo '<span>';
    
    if ($closest_match) {
        $closest_name = isset($locale_names[$closest_match]) ? $locale_names[$closest_match] : $closest_match;
        echo '<strong>' . esc_html__('Wallet Up Login:', 'wallet-up-login-customizer') . '</strong> ';
        echo sprintf(
            esc_html__('Translation for %1$s is not available. Using %2$s instead.', 'wallet-up-login-customizer'),
            '<em>' . esc_html($current_name) . '</em>',
            '<em>' . esc_html($closest_name) . '</em>'
        );
    } else {
        // No translation available for this language at all
        $available_list = array();
        foreach ($available_translations as $trans) {
            if (isset($locale_names[$trans])) {
                $available_list[] = $locale_names[$trans];
            }
        }
        
        echo '<strong>' . esc_html__('Wallet Up Login:', 'wallet-up-login-customizer') . '</strong> ';
        echo sprintf(
            esc_html__('Translation for %s is not available.', 'wallet-up-login-customizer'),
            '<em>' . esc_html($current_name) . '</em>'
        );
        
        if (!empty($available_list)) {
            echo ' ' . esc_html__('Available languages:', 'wallet-up-login-customizer') . ' ';
            echo '<em>' . esc_html(implode(', ', $available_list)) . '</em>.';
        }
    }
    
    echo '</span>';
    echo '</p>';
    echo '</div>';
}


}

// Initialize the plugin
function wallet_up_login_customizer() {
    return WalletUpLogin::get_instance();
}

// Start the plugin
wallet_up_login_customizer();