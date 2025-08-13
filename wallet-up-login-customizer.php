<?php
/**
 * Plugin Name: Wallet Up Premium Login Customizer
 * Description: Create a beautiful interactive login experience for Wallet Up and Wordpress users
 * Version: 2.3.5
 * Author: Wallet Up
 * Author URI: https://walletup.app
 * Text Domain: wallet-up-login-customizer
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit; 
}

add_action('init', function() {
    if (defined('DOING_AJAX') && DOING_AJAX && 
        isset($_REQUEST['action']) && 
        ($_REQUEST['action'] === 'wallet_up_ajax_login' || $_REQUEST['action'] === 'wallet_up_validate_username')) {

        if (class_exists('WalletUpPro\\Core\\Security\\EnhancedSecurityManager')) {
            remove_all_filters('authenticate', 10);
            
            add_filter('authenticate', 'wp_authenticate_username_password', 20, 3);
            add_filter('authenticate', 'wp_authenticate_cookie', 30, 3);
            add_filter('authenticate', 'wp_authenticate_spam_check', 99, 3);
        }
    }
}, 1);

class WalletUpLogin {

    const VERSION = '2.3.5';

    private static $instance = null;

    private $login_customizer = null;

    private function __construct() {
        try {
            
            $this->define_constants();

            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));

            add_action('plugins_loaded', array($this, 'init'), 10);

            add_action('admin_notices', array($this, 'security_status_notice'));

            if (is_multisite()) {
                add_action('wp_initialize_site', array($this, 'new_site_activation'), 10, 2);
            }
            
        } catch (Exception $e) {
            
            error_log('Wallet Up Login Constructor Error: ' . $e->getMessage());
        }
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    private function define_constants() {
        define('WALLET_UP_LOGIN_CUSTOMIZER_VERSION', self::VERSION);
        define('WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL', plugin_dir_url(__FILE__));
        define('WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_FILE', __FILE__);
        define('WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_BASENAME', plugin_basename(__FILE__));
    }

    private function init_session_management() {

    }

private function is_wallet_up_available() {
    
    if (!function_exists('is_plugin_active')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }

    return is_plugin_active('wallet-up/wallet-up.php') || 
           is_plugin_active('wallet-up-pro/wallet-up.php') ||
           is_plugin_active('walletup/walletup.php');
}

public function init() {
    
    $this->init_session_management();

    load_plugin_textdomain('wallet-up-login-customizer', false, dirname(plugin_basename(__FILE__)) . '/languages');

    $this->includes();

    if (class_exists('WalletUpEnterpriseSecurity') && 
        (!defined('WALLET_UP_EMERGENCY_DISABLE') || !WALLET_UP_EMERGENCY_DISABLE)) {
        WalletUpEnterpriseSecurity::init();
    }

    if (class_exists('WalletUpSecuritySanitizer')) {
        WalletUpSecuritySanitizer::init();
    }

    if (class_exists('WalletUpAdminSync')) {
        WalletUpAdminSync::init();
    }

    if (class_exists('WalletUpConflictDetector')) {
        WalletUpConflictDetector::init();
    }

    if (class_exists('WalletUpSafeActivation')) {
        WalletUpSafeActivation::init();
    }

    if (class_exists('WalletUpLoginLogo')) {
        WalletUpLoginLogo::init();
    }

    if (class_exists('WalletUpLoginCustomizer')) {
        $this->init_login_customizer();
    } else {
        error_log('WalletUpLoginCustomizer class not found. Please use the reinstall option in settings if needed.');
    }

    $wallet_up_available = $this->is_wallet_up_available();
    if (class_exists('WalletUpHardRedirect')) {
        if ($wallet_up_available) {
            WalletUpHardRedirect::init();
        } else {
            
            $options = get_option('wallet_up_login_customizer_options', []);
            if (!empty($options['redirect_to_wallet_up']) || !empty($options['force_dashboard_replacement'])) {
                $options['redirect_to_wallet_up'] = false;
                $options['force_dashboard_replacement'] = false;
                update_option('wallet_up_login_customizer_options', $options);

                add_action('admin_notices', function() {
                    echo '<div class="notice notice-warning is-dismissible">';
                    echo '<p>' . __('Wallet Up dashboard redirect has been disabled because the Wallet Up plugin is not active.', 'wallet-up-login-customizer') . '</p>';
                    echo '</div>';
                });
            }
        }
    }

    $options = get_option('wallet_up_login_customizer_options');
    if (!empty($options['force_dashboard_replacement']) && $wallet_up_available) {
        $this->setup_dashboard_replacement();
    }

    if (is_admin()) {
        $this->init_admin();
    }
}

private function setup_dashboard_replacement() {
    
    if (!$this->is_wallet_up_available()) {
        return;
    }

    $options = get_option('wallet_up_login_customizer_options', []);

    $force_replacement = !empty($options['force_dashboard_replacement']);
    
    if (!$force_replacement) {
        return; 
    }

    if ($this->is_user_exempt_from_dashboard_replacement()) {
        return;
    }

    add_action('load-index.php', array($this, 'secure_redirect_dashboard'), 5);

    add_action('wp_dashboard_setup', array($this, 'replace_dashboard_widgets'), 999);

    add_action('admin_menu', array($this, 'modify_dashboard_menu'), 999);

    add_action('admin_bar_menu', array($this, 'modify_admin_bar_dashboard'), 999);
}

public function secure_redirect_dashboard() {

    $show_dashboard = isset($_GET['show_wp_dashboard']) ? sanitize_text_field($_GET['show_wp_dashboard']) : '';
    if ($show_dashboard === '1' && current_user_can('manage_options')) {
        return;
    }

    if (wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
        return;
    }

    if (!is_user_logged_in()) {
        return;
    }

    if (!$this->is_wallet_up_available()) {
        return;
    }

    if ($this->is_user_exempt_from_dashboard_replacement()) {
        return;
    }

    $wallet_up_url = admin_url('admin.php?page=wallet-up');

    if (filter_var($wallet_up_url, FILTER_VALIDATE_URL)) {
        wp_safe_redirect($wallet_up_url);
        exit;
    } else {
        
        $options = get_option('wallet_up_login_customizer_options', []);
        $options['redirect_to_wallet_up'] = false;
        update_option('wallet_up_login_customizer_options', $options);

        return;
    }
}

public function replace_dashboard_widgets() {
    global $wp_meta_boxes;

    if ($this->is_user_exempt_from_dashboard_replacement()) {
        return;
    }

    if (!$this->is_wallet_up_available()) {
        return;
    }

    if (isset($wp_meta_boxes['dashboard'])) {
        $wp_meta_boxes['dashboard'] = [];
    }

    wp_add_dashboard_widget(
        'wallet_up_dashboard_widget',
        __('Wallet Up Dashboard', 'wallet-up-login-customizer'),
        array($this, 'render_wallet_up_dashboard_widget')
    );
}

public function render_wallet_up_dashboard_widget() {
    $wallet_up_url = admin_url('admin.php?page=wallet-up');
    $options = get_option('wallet_up_login_customizer_options', []);
    $embed_mode = !empty($options['embed_wallet_up']) && !empty($options['force_dashboard_replacement']);
    
    echo '<div class="wallet-up-dashboard-replacement">';
    
    if ($embed_mode) {
        
        echo '<h3>' . esc_html__('Wallet Up Dashboard', 'wallet-up-login-customizer') . '</h3>';
        echo '<div class="wallet-up-embed-container">';
        echo '<iframe src="' . esc_url($wallet_up_url) . '" width="100%" height="600" frameborder="0" sandbox="allow-same-origin allow-scripts allow-forms"></iframe>';
        echo '</div>';
    } else {
        
        echo '<h3>' . esc_html__('Welcome to Wallet Up!', 'wallet-up-login-customizer') . '</h3>';
        echo '<p>' . esc_html__('Your WordPress dashboard has been enhanced with Wallet Up. Click below to access your Wallet Up dashboard.', 'wallet-up-login-customizer') . '</p>';
        echo '<p>';
        echo '<a href="' . esc_url($wallet_up_url) . '" class="button button-primary button-large">';
        echo esc_html__('Open Wallet Up Dashboard', 'wallet-up-login-customizer');
        echo '</a>';
        echo '</p>';
    }

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

public function modify_dashboard_menu() {
    global $menu;

    if ($this->is_user_exempt_from_dashboard_replacement()) {
        return;
    }

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

public function modify_admin_bar_dashboard($wp_admin_bar) {
    
    if ($this->is_user_exempt_from_dashboard_replacement()) {
        return;
    }
    
    $wallet_up_url = admin_url('admin.php?page=wallet-up');

    $dashboard_node = $wp_admin_bar->get_node('dashboard');
    if ($dashboard_node) {
        $dashboard_node->href = $wallet_up_url;
        $wp_admin_bar->add_node($dashboard_node);
    }

    $site_name_node = $wp_admin_bar->get_node('site-name');
    if ($site_name_node) {
        $site_name_node->href = $wallet_up_url;
        $wp_admin_bar->add_node($site_name_node);
    }
}

public function maybe_redirect_dashboard() {

    $show_dashboard = isset($_GET['show_wp_dashboard']) ? sanitize_text_field($_GET['show_wp_dashboard']) : '';
    if (is_admin() && !defined('DOING_AJAX') && $GLOBALS['pagenow'] == 'index.php' && $show_dashboard !== '1') {
        wp_redirect(admin_url('admin.php?page=wallet-up'));
        exit;
    }
}

private function init_admin() {
    
    add_filter('plugin_action_links_' . WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_BASENAME, array($this, 'add_settings_link'));

    add_action('admin_menu', array($this, 'register_settings_page'));

    if (is_multisite()) {
        add_action('network_admin_menu', array($this, 'register_network_settings_page'));
    }

    add_action('admin_init', array($this, 'register_settings'));

    add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

    add_action('admin_notices', array($this, 'language_availability_notice'));

    add_action('wp_ajax_wallet_up_dismiss_language_notice', array($this, 'dismiss_language_notice'));
}

private function includes() {
    
    $customizer_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-login-customizer.php';
    
    if (file_exists($customizer_file)) {
        require_once $customizer_file;
    }

    $security_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-enterprise-security.php';
    
    if (file_exists($security_file)) {
        require_once $security_file;
    }

    $sanitizer_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-security-sanitizer.php';
    
    if (file_exists($sanitizer_file)) {
        require_once $sanitizer_file;
    }

    $logo_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-login-customizer-logo.php';
    
    if (file_exists($logo_file)) {
        require_once $logo_file;
    }

    $admin_sync_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-admin-sync.php';
    
    if (file_exists($admin_sync_file)) {
        require_once $admin_sync_file;
    }

    $conflict_detector_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-conflict-detector.php';
    
    if (file_exists($conflict_detector_file)) {
        require_once $conflict_detector_file;
    }

    $safe_activation_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-safe-activation.php';
    
    if (file_exists($safe_activation_file)) {
        require_once $safe_activation_file;
    }

    $redirect_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-hard-redirect.php';
    
    if (file_exists($redirect_file)) {
        require_once $redirect_file;
    }
}
    
    private function init_login_customizer() {
        
        if (class_exists('WalletUpLoginCustomizer')) {
            $this->login_customizer = WalletUpLoginCustomizer::get_instance();
        }
    }

public function login_redirect($redirect_to, $request, $user) {
    
    if (!is_wp_error($user) && $user instanceof WP_User) {
        
        $options = get_option('wallet_up_login_customizer_options');

        $redirect_to_wallet_up = !empty($options['redirect_to_wallet_up']);

        $force_replacement = !empty($options['force_dashboard_replacement']);
        $is_exempt = false;

        if (!empty($options['exempt_admin_roles']) && current_user_can('administrator')) {
            $is_exempt = true;
        }

        if ($redirect_to_wallet_up && !($force_replacement && $is_exempt)) {
            
            return admin_url('admin.php?page=wallet-up');
        }

        if (!empty($options['dashboard_redirect'])) {
            
            return admin_url();
        }
    }

    return $redirect_to;
}

public function register_login_redirect() {
    
    add_filter('login_redirect', array($this, 'login_redirect'), 999, 3);
}

public function admin_enqueue_scripts($hook) {
    
    if ($hook != 'settings_page_wallet-up-login-customizer') {
        return;
    }

    wp_enqueue_media();

    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');

    $admin_js_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'js/wallet-up-login-customizer-admin.js';

    if (!file_exists($admin_js_file)) {
        
        error_log('Admin JS file not found: ' . $admin_js_file);

        wp_enqueue_script(
            'wallet-up-login-customizer-admin-fallback',
            WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL . 'js/wallet-up-login-customizer-admin-fallback.js',
            array('jquery', 'wp-color-picker', 'media-upload', 'media-views'),
            WALLET_UP_LOGIN_CUSTOMIZER_VERSION,
            true
        );
    } else {
        
        wp_enqueue_script(
            'wallet-up-login-customizer-admin',
            WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL . 'js/wallet-up-login-customizer-admin.js',
            array('jquery', 'wp-color-picker', 'media-upload', 'media-views'),
            WALLET_UP_LOGIN_CUSTOMIZER_VERSION . '.' . filemtime($admin_js_file), 
            true
        );

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
                'confirmReset' => __('Are you sure you want to reset all settings to default values?', 'wallet-up-login-customizer'),
                'considerEnabling' => __('Consider enabling "Exempt Administrator Roles" for easier management.', 'wallet-up-login-customizer'),
                'recommendedKeeps' => __('✅ Recommended: Keeps admin access for troubleshooting and configuration.', 'wallet-up-login-customizer')
            )
        ));
    }

    $admin_css_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'css/wallet-up-login-customizer-admin.css';

    wp_enqueue_style(
        'wallet-up-login-customizer-admin',
        WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL . 'css/wallet-up-login-customizer-admin.css',
        array(),
        WALLET_UP_LOGIN_CUSTOMIZER_VERSION . '.' . time() 
    );
}   

public function security_status_notice() {
    
    if (!current_user_can('manage_options')) {
        return;
    }

    $options = get_option('wallet_up_login_customizer_options', []);
    if (!empty($options['redirect_to_wallet_up']) && !$this->is_wallet_up_available()) {
        echo '<div class="notice notice-error">';
        echo '<p><strong>' . __('Wallet Up Login Customizer:', 'wallet-up-login-customizer') . '</strong> ';
        echo __('Dashboard redirect is enabled but Wallet Up plugin is not active. Please install Wallet Up or disable the redirect feature.', 'wallet-up-login-customizer');
        echo '</p>';
        echo '</div>';
    }
}

public function activate($network_wide = false) {
    
    require_once WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-safe-activation.php';

    if (is_multisite() && $network_wide) {
        
        $sites = get_sites();
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            WalletUpSafeActivation::activate();
            restore_current_blog();
        }
    } else {
        
        WalletUpSafeActivation::activate();
    }

    $includes_dir = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes';
    
    if (!file_exists($includes_dir)) {
        if (!wp_mkdir_p($includes_dir)) {
            error_log('Wallet Up Login: Failed to create includes directory: ' . $includes_dir);
        }
    }

    $assets_dirs = array('css', 'js', 'img');
    
    foreach ($assets_dirs as $dir) {
        $asset_dir = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . $dir;
        
        if (!file_exists($asset_dir)) {
            if (!wp_mkdir_p($asset_dir)) {
                error_log('Wallet Up Login: Failed to create asset directory: ' . $asset_dir);
            }
        }
    }

    $first_install = !get_option('wallet_up_files_installed');

    if ($first_install) {
        $this->install_files(false); 
    }

    if ($first_install) {
        $this->set_safe_default_options();
    }

    flush_rewrite_rules();
}

private function set_safe_default_options() {
    $default_options = array(
        'redirect_to_wallet_up' => false,  
        'force_dashboard_replacement' => false,  
        'custom_login_message' => __('Welcome to Wallet Up', 'wallet-up-login-customizer'),
        'enable_ajax_login' => true,
        'primary_color' => '#6200fC',
        'gradient_start' => '#6200fC',
        'gradient_end' => '#8B3DFF',
        'exempt_admin_roles' => true  
    );

    if (!get_option('wallet_up_login_customizer_options')) {
        add_option('wallet_up_login_customizer_options', $default_options);
    }

    update_option('wallet_up_files_installed', true);
}

public function new_site_activation($new_site, $args) {
    
    if (!is_plugin_active_for_network(plugin_basename(__FILE__))) {
        return;
    }

    switch_to_blog($new_site->blog_id);
    require_once WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-safe-activation.php';
    WalletUpSafeActivation::activate();
    restore_current_blog();
}

public function deactivate() {
    
    if (class_exists('WalletUpSafeActivation')) {
        WalletUpSafeActivation::deactivate();
    }

    flush_rewrite_rules();
}

public function install_files($force = false) {
    $success = true;
    $errors = array();

    $last_sync = get_option('wallet_up_files_last_sync', 0);
    $sync_interval = 3.5 * DAY_IN_SECONDS; 
    $now = time();

    if (!$force && $last_sync && ($now - $last_sync) < $sync_interval) {
        return true;
    }

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
            'critical' => false 
        )
    );

    $updates_log = array();

    foreach ($file_mappings as $file) {
        $needs_copy = false;
        $reason = '';

        if (!file_exists($file['source'])) {
            
            if (file_exists($file['dest'])) {
                
                if (!file_exists(dirname($file['source']))) {
                    wp_mkdir_p(dirname($file['source']));
                }
                @copy($file['dest'], $file['source']);
                $updates_log[] = array(
                    'file' => basename($file['source']),
                    'reason' => 'restored_to_assets',
                    'timestamp' => $now
                );
                continue; 
            } else if ($file['critical']) {
                
                $this->create_default_file($file['dest']);
                
                if ($this->create_default_file($file['dest'])) {
                    @copy($file['dest'], $file['source']);
                }
            }
            continue;
        }

        if (!file_exists($file['dest'])) {
            
            $needs_copy = true;
            $reason = 'missing';
        } else if ($force) {
            
            $needs_copy = true;
            $reason = 'forced';
        } else {
            
            $source_mtime = filemtime($file['source']);
            $dest_mtime = filemtime($file['dest']);

            if ($source_mtime > ($dest_mtime + 60)) {
                $needs_copy = true;
                $reason = 'source_newer';

                $dest_hash = md5_file($file['dest']);
                update_option('wallet_up_backup_hash_' . basename($file['dest']), $dest_hash);
            }
        }

        if ($needs_copy) {
            
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

    if (!empty($updates_log)) {
        $existing_log = get_option('wallet_up_sync_log', array());
        $existing_log[] = array(
            'date' => current_time('mysql'),
            'updates' => $updates_log,
            'forced' => $force
        );
        
        $existing_log = array_slice($existing_log, -10);
        update_option('wallet_up_sync_log', $existing_log);

        if (!$force && current_user_can('manage_options')) {
            set_transient('wallet_up_files_auto_updated', $updates_log, 60);
        }
    }

    update_option('wallet_up_files_last_sync', $now);

    if ($success) {
        update_option('wallet_up_files_installed', $now);
    }
    
    return $success;
}

private function safe_file_copy($source, $destination) {
    
    if (!file_exists($source) || !is_readable($source)) {
        return false;
    }

    $plugin_dir = realpath(WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR);
    $real_source = realpath($source);
    $dest_dir = dirname($destination);
    
    if (strpos($real_source, $plugin_dir) !== 0) {
        error_log('Wallet Up Login: Security - Source file outside plugin directory');
        return false;
    }

    if (!is_dir($dest_dir)) {
        wp_mkdir_p($dest_dir);
    }

    if (file_exists($destination) && !is_writable($destination)) {
        @unlink($destination);
    }

    $result = @copy($source, $destination);

    if ($result) {
        @chmod($destination, 0644);
    }
    
    return $result;
}

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

    private function create_default_css() {
        $css_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'css/wallet-up-login-customizer.css';
        $css_content = "/* Wallet Up Premium Login CSS */\n\n";

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

    private function create_default_js() {
        $js_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'js/wallet-up-login-customizer.js';
        $js_content = "/**\n * Wallet Up Premium Login JavaScript\n */\n\n";

        $js_content .= "(function($) {\n\t\"use strict\";\n\n\t$(document).ready(function() {\n\t\tconsole.log('Wallet Up Login JS initialized');\n\t});\n})(jQuery);\n";
        
        return file_put_contents($js_file, $js_content) !== false;
    }

    private function create_admin_js() {
        $js_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'js/wallet-up-login-customizer-admin.js';
        $js_content = "/**\n * Wallet Up Login Admin JavaScript\n */\n\n";

        $js_content .= "(function($) {\n\t\"use strict\";\n\n\t$(document).ready(function() {\n\t\t// Initialize color pickers\n\t\t$('.wallet-up-color-picker').wpColorPicker();\n\t\t\n\t\t// Handle dynamic message fields\n\t\tvar messageContainer = $('#loading-messages-container');\n\t\tvar messageTemplate = $('#loading-message-template').html();\n\t\tvar messageCount = messageContainer.find('.loading-message').length;\n\t\t\n\t\t// Add new message field\n\t\t$('#add-loading-message').on('click', function(e) {\n\t\t\te.preventDefault();\n\t\t\tvar newMessage = messageTemplate.replace(/\\[index\\]/g, messageCount);\n\t\t\tmessageContainer.append(newMessage);\n\t\t\tmessageCount++;\n\t\t});\n\t\t\n\t\t// Remove message field\n\t\t$(document).on('click', '.remove-loading-message', function(e) {\n\t\t\te.preventDefault();\n\t\t\t$(this).closest('.loading-message').remove();\n\t\t});\n\t});\n})(jQuery);\n";
        
        return file_put_contents($js_file, $js_content) !== false;
    }

    private function create_admin_css() {
        $css_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'css/wallet-up-login-customizer-admin.css';
        $css_content = "/**\n * Wallet Up Login Admin CSS\n */\n\n";

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

    private function create_default_class() {
        $class_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'includes/class-wallet-up-login-customizer.php';
        $class_content = "<?php\n/**\n * WalletUpLoginCustomizer Class\n */\n\n";

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

private function set_default_options() {
    
    $existing_options = get_option('wallet_up_login_customizer_options', false);
    
    if ($existing_options !== false) {
        
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
        'redirect_to_wallet_up' => false, 
        'force_dashboard_replacement' => false, 
        'exempt_admin_roles' => true, 
    );
    
    update_option('wallet_up_login_customizer_options', $defaults);
}

private function create_admin_js_fallback() {
    $js_file = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'js/wallet-up-login-customizer-admin-fallback.js';
    $js_content = "/**\n * Wallet Up Login Admin JavaScript - Fallback Version\n */\n\n";

    $js_content .= "(function($) {\n\t\"use strict\";\n\n\t$(document).ready(function() {\n";

    $js_content .= "\t\t// Initialize color pickers\n";
    $js_content .= "\t\tif ($.fn.wpColorPicker) {\n";
    $js_content .= "\t\t\t$('.wallet-up-color-picker').wpColorPicker();\n";
    $js_content .= "\t\t}\n\n";

    $js_content .= "\t\t// Setup tabs\n";
    $js_content .= "\t\t$('.settings-tab').on('click', function(e) {\n";
    $js_content .= "\t\t\te.preventDefault();\n";
    $js_content .= "\t\t\tvar tabId = $(this).attr('href');\n";
    $js_content .= "\t\t\t$('.settings-tab').removeClass('active');\n";
    $js_content .= "\t\t\t$('.settings-panel').hide();\n";
    $js_content .= "\t\t\t$(this).addClass('active');\n";
    $js_content .= "\t\t\t$(tabId).show();\n";
    $js_content .= "\t\t});\n\n";

    $js_content .= "\t\t// Show first tab\n";
    $js_content .= "\t\t$('.settings-tab:first').click();\n";

    $js_content .= "\t});\n})(jQuery);\n";

    return file_put_contents($js_file, $js_content) !== false;
}

private function get_asset_url($path) {
    
    $file_path = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . $path;
    
    if (file_exists($file_path)) {
        return WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL . $path;
    } else {
        
        error_log('Asset file not found: ' . $file_path);

        if (strpos($path, 'css/') === 0) {
            return 'data:text/css;base64,LyogRmFsbGJhY2sgQ1NTICovCg=='; 
        } elseif (strpos($path, 'js/') === 0) {
            return 'data:application/javascript;base64,Ly8gRmFsbGJhY2sgSlMK'; 
        } elseif (strpos($path, 'img/') === 0) {
            return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9IiM2NzRGQkYiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIj48cGF0aCBkPSJNNSA4VjVjMC0xIDEtMiAyLTJoMTBjMSAwIDIgMSAyIDJ2MyIvPjxwYXRoIGQ9Ik0xOSAxNnYzYzAgMS0xIDItMiAySDdjLTEgMC0yLTEtMi0ydi0zIi8+PGxpbmUgeDE9IjEyIiB4Mj0iMTIiIHkxPSI0IiB5Mj0iMjAiLz48L3N2Zz4='; 
        }
        
        return ''; 
    }
}
    
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=wallet-up-login-customizer') . '">' . __('Settings', 'wallet-up-login-customizer') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

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

    public function register_settings_page() {
        add_options_page(
            __('Wallet Up Login', 'wallet-up-login-customizer'),
            __('Wallet Up Login', 'wallet-up-login-customizer'),
            'manage_options',
            'wallet-up-login-customizer',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('wallet_up_login_customizer_options', 'wallet_up_login_customizer_options', array($this, 'sanitize_options'));

        register_setting('wallet_up_login_customizer_options', 'wallet_up_security_options', array($this, 'sanitize_security_options'));

    add_action('admin_notices', function() {
        
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true' && isset($_GET['page']) && $_GET['page'] === 'wallet-up-login-customizer') {
            
            echo '<div class="notice notice-success is-dismissible wallet-up-auto-dismiss-notice">';
            echo '<p><strong>' . esc_html__('Settings successfully saved!', 'wallet-up-login-customizer') . '</strong></p>';
            echo '</div>';

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

            delete_transient('wallet_up_files_auto_updated');
        }
    });

        add_settings_section(
            'wallet_up_login_customizer_section',
            __('Login Page Settings', 'wallet-up-login-customizer'),
            array($this, 'render_settings_section'),
            'wallet-up-login-customizer'
        );

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

    public function sanitize_options($input) {
        $sanitized = array();

        $sanitized['enable_ajax_login'] = isset($input['enable_ajax_login']) ? (bool) $input['enable_ajax_login'] : false;
        $sanitized['enable_sounds'] = isset($input['enable_sounds']) ? (bool) $input['enable_sounds'] : false;
        $sanitized['dashboard_redirect'] = isset($input['dashboard_redirect']) ? (bool) $input['dashboard_redirect'] : false;
        $sanitized['show_remember_me'] = isset($input['show_remember_me']) ? (bool) $input['show_remember_me'] : false;
        $sanitized['redirect_to_wallet_up'] = isset($input['redirect_to_wallet_up']) ? (bool) $input['redirect_to_wallet_up'] : false;
        $sanitized['force_dashboard_replacement'] = isset($input['force_dashboard_replacement']) ? (bool) $input['force_dashboard_replacement'] : false;
        $sanitized['exempt_admin_roles'] = isset($input['exempt_admin_roles']) ? (bool) $input['exempt_admin_roles'] : false;

        $sanitized['custom_logo_url'] = !empty($input['custom_logo_url']) 
            ? filter_var(esc_url_raw($input['custom_logo_url']), FILTER_VALIDATE_URL) 
            : '';

        if ($sanitized['custom_logo_url'] === false) {
            $sanitized['custom_logo_url'] = '';
        }

        $sanitized['primary_color'] = isset($input['primary_color']) ? sanitize_hex_color($input['primary_color']) : '#674FBF';
        $sanitized['gradient_start'] = isset($input['gradient_start']) ? sanitize_hex_color($input['gradient_start']) : '#674FBF';
        $sanitized['gradient_end'] = isset($input['gradient_end']) ? sanitize_hex_color($input['gradient_end']) : '#7B68D4';

        $sanitized['redirect_delay'] = isset($input['redirect_delay']) ? absint($input['redirect_delay']) : 1500;

        $sanitized['loading_messages'] = array();
        if (isset($input['loading_messages']) && is_array($input['loading_messages'])) {
            
            $reverse_map = array(
                __('Verifying your credentials...', 'wallet-up-login-customizer') => 'Verifying your credentials...',
                __('Preparing your dashboard...', 'wallet-up-login-customizer') => 'Preparing your dashboard...',
                __('Almost there...', 'wallet-up-login-customizer') => 'Almost there...'
            );
            
            foreach ($input['loading_messages'] as $message) {
                if (!empty($message)) {
                    $clean_message = sanitize_text_field($message);
                    
                    if (isset($reverse_map[$clean_message])) {
                        $sanitized['loading_messages'][] = $reverse_map[$clean_message];
                    } else {
                        
                        $sanitized['loading_messages'][] = $clean_message;
                    }
                }
            }
        }

        if (empty($sanitized['loading_messages'])) {
            $sanitized['loading_messages'] = array('Verifying your credentials...');
        }
        
        return $sanitized;
    }

    public function sanitize_security_options($input) {
        $sanitized = array();

        $sanitized['force_login_enabled'] = isset($input['force_login_enabled']) ? (bool) $input['force_login_enabled'] : false;
        $sanitized['hide_wp_login'] = isset($input['hide_wp_login']) ? (bool) $input['hide_wp_login'] : false;

        $sanitized['custom_login_slug'] = isset($input['custom_login_slug']) ? 
            sanitize_title($input['custom_login_slug']) : 'secure-login';

        if (empty($sanitized['custom_login_slug'])) {
            $sanitized['custom_login_slug'] = 'secure-login';
        }

        $sanitized['max_login_attempts'] = isset($input['max_login_attempts']) ? 
            max(1, min(20, absint($input['max_login_attempts']))) : 5;
        $sanitized['lockout_duration'] = isset($input['lockout_duration']) ? 
            max(60, min(86400, absint($input['lockout_duration']))) : 900;

        $sanitized['exempt_roles'] = ['administrator'];

        $sanitized['whitelist_ips'] = [];
        $sanitized['security_headers'] = false;
        $sanitized['session_timeout'] = 3600;

        $old_options = get_option('wallet_up_security_options', []);
        if (isset($old_options['custom_login_slug']) && 
            $old_options['custom_login_slug'] !== $sanitized['custom_login_slug']) {
            flush_rewrite_rules();
        }
        
        return $sanitized;
    }

    public function render_settings_section() {
        echo '<p>' . __('Customize the Wallet Up login experience with these settings.', 'wallet-up-login-customizer') . '</p>';
    }

    public function render_checkbox_field($args) {
        $options = get_option('wallet_up_login_customizer_options');
        $name = $args['name'];
        $checked = isset($options[$name]) ? $options[$name] : false;

        $wallet_up_required = array('redirect_to_wallet_up', 'force_dashboard_replacement');
        if (in_array($name, $wallet_up_required)) {
            
            $wallet_up_available = false;
            if (!function_exists('is_plugin_active')) {
                include_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }
            $wallet_up_available = is_plugin_active('wallet-up/wallet-up.php') || 
                                 is_plugin_active('wallet-up-pro/wallet-up.php') ||
                                 is_plugin_active('walletup/walletup.php');
            
            if (!$wallet_up_available) {
                
                echo '<input type="checkbox" id="' . esc_attr($name) . '" name="wallet_up_login_customizer_options[' . esc_attr($name) . ']" value="1" disabled />';
                $message = ($name === 'redirect_to_wallet_up') 
                    ? __('Wallet Up Pro plugin is not active. Please install and activate it to redirect users to Wallet Up after login.', 'wallet-up-login-customizer')
                    : __('Wallet Up Pro plugin is not active. Please install and activate it to replace the WordPress dashboard.', 'wallet-up-login-customizer');
                echo '<span class="description" style="color: #d63638;">' . $message . '</span>';
                return;
            }
        }

        echo '<input type="checkbox" id="' . esc_attr($name) . '" name="wallet_up_login_customizer_options[' . esc_attr($name) . ']" ' . checked($checked, true, false) . ' value="1" />';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function render_text_field($args) {
        $options = get_option('wallet_up_login_customizer_options');
        $name = $args['name'];
        $value = isset($options[$name]) ? $options[$name] : '';
        
        echo '<input type="text" class="regular-text" id="' . esc_attr($name) . '" name="wallet_up_login_customizer_options[' . esc_attr($name) . ']" value="' . esc_attr($value) . '" />';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

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

    public function render_color_field($args) {
        $options = get_option('wallet_up_login_customizer_options');
        $name = $args['name'];
        $value = isset($options[$name]) ? $options[$name] : '#674FBF';
        
        echo '<input type="text" class="wallet-up-color-picker" id="' . esc_attr($name) . '" name="wallet_up_login_customizer_options[' . esc_attr($name) . ']" value="' . esc_attr($value) . '" data-default-color="#674FBF" />';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

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
        
        echo '<button id="add-loading-message" class="button button-secondary">' . __('Add Message', 'wallet-up-login-customizer') . '</button>';

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

    public function render_network_settings_page() {
        if (!current_user_can('manage_network_options')) {
            return;
        }

        if (isset($_POST['submit']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'wallet-up-network-settings')) {
            $this->save_network_settings();
        }

        if (isset($_POST['export_network']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wallet-up-export-network')) {
            $this->export_network_settings();
        }

        if (isset($_POST['import_network']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wallet-up-import-network')) {
            $this->import_network_settings();
        }

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

    private function export_network_settings() {
        if (!current_user_can('manage_network_options')) {
            return;
        }

        $export_data = array(
            'version' => WALLET_UP_LOGIN_CUSTOMIZER_VERSION,
            'type' => 'network_settings',
            'multisite' => true,
            'timestamp' => current_time('mysql'),
            'site_url' => network_site_url(),
            'network_settings' => get_site_option('wallet_up_network_settings', array()),
            'network_security' => get_site_option('wallet_up_network_security', array())
        );

        if (isset($_POST['include_all_sites'])) {
            $sites = get_sites();
            $export_data['sites'] = array();
            
            foreach ($sites as $site) {
                switch_to_blog($site->blog_id);
                $export_data['sites'][$site->blog_id] = array(
                    'domain' => $site->domain,
                    'path' => $site->path,
                    'login_options' => get_option('wallet_up_login_customizer_options', array()),
                    'security_options' => get_option('wallet_up_security_options', array()),
                    'logo_settings' => get_option('wallet_up_logo_settings', array())
                );
                restore_current_blog();
            }
        }

        $filename = 'wallet-up-network-settings-' . date('Y-m-d-His') . '.json';

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        echo wp_json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }

    private function import_network_settings() {
        if (!current_user_can('manage_network_options')) {
            return;
        }
        
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Please select a valid file to import.', 'wallet-up-login-customizer') . '</p></div>';
            return;
        }
        
        $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
        $import_data = json_decode($file_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Invalid JSON file.', 'wallet-up-login-customizer') . '</p></div>';
            return;
        }

        if (!isset($import_data['type']) || $import_data['type'] !== 'network_settings') {
            echo '<div class="notice notice-error"><p>' . esc_html__('This is not a valid network settings export file.', 'wallet-up-login-customizer') . '</p></div>';
            return;
        }

        if (isset($import_data['network_settings'])) {
            update_site_option('wallet_up_network_settings', $import_data['network_settings']);
        }
        
        if (isset($import_data['network_security'])) {
            update_site_option('wallet_up_network_security', $import_data['network_security']);
        }

        if (isset($import_data['sites']) && isset($_POST['import_all_sites'])) {
            foreach ($import_data['sites'] as $blog_id => $site_settings) {
                if (get_blog_details($blog_id)) {
                    switch_to_blog($blog_id);
                    
                    if (isset($site_settings['login_options'])) {
                        update_option('wallet_up_login_customizer_options', $site_settings['login_options']);
                    }
                    if (isset($site_settings['security_options'])) {
                        update_option('wallet_up_security_options', $site_settings['security_options']);
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
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        if (isset($_POST['wallet_up_reinstall']) && current_user_can('manage_options')) {
            check_admin_referer('wallet_up_reinstall_nonce');

            $result = $this->install_files(true);
            
            if ($result) {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Enhanced login files have been installed successfully!', 'wallet-up-login-customizer') . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . __('Failed to install enhanced login files. Please check file permissions.', 'wallet-up-login-customizer') . '</p></div>';
            }
        }

            $options = get_option('wallet_up_login_customizer_options');
            $custom_logo_url = isset($options['custom_logo_url']) ? $options['custom_logo_url'] : '';

            if (empty($custom_logo_url)) {
                $custom_logo_url = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_URL . 'img/walletup-icon.png';
            }

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
                                
                                $security_options = get_option('wallet_up_security_options', [
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
                                        <input type="checkbox" id="force_login_enabled" name="wallet_up_security_options[force_login_enabled]" value="1" <?php checked($security_options['force_login_enabled']); ?> />
                                        <label for="force_login_enabled"><?php echo esc_html__('Require users to login before accessing the website', 'wallet-up-login-customizer'); ?></label>
                                        <p class="description"><?php echo esc_html__('Visitors must authenticate before viewing any content. Registration and password reset still work normally.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="hide_wp_login"><?php echo esc_html__('Hide wp-login.php', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <input type="checkbox" id="hide_wp_login" name="wallet_up_security_options[hide_wp_login]" value="1" <?php checked($security_options['hide_wp_login']); ?> />
                                        <label for="hide_wp_login"><?php echo esc_html__('Hide the default WordPress login page', 'wallet-up-login-customizer'); ?></label>
                                        <p class="description"><?php echo esc_html__('Direct access to wp-login.php will be redirected to your custom login URL.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="custom_login_slug"><?php echo esc_html__('Custom Login URL', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="custom_login_slug" name="wallet_up_security_options[custom_login_slug]" value="<?php echo esc_attr($security_options['custom_login_slug']); ?>" class="regular-text" />
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
                                        <input type="number" id="max_login_attempts" name="wallet_up_security_options[max_login_attempts]" value="<?php echo esc_attr($security_options['max_login_attempts']); ?>" min="1" max="20" />
                                        <p class="description"><?php echo esc_html__('Maximum failed login attempts before temporary lockout.', 'wallet-up-login-customizer'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="lockout_duration"><?php echo esc_html__('Lockout Duration', 'wallet-up-login-customizer'); ?></label>
                                    </th>
                                    <td>
                                        <select id="lockout_duration" name="wallet_up_security_options[lockout_duration]">
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
                <p><?php echo esc_html__('Create a beautiful interactive login experience for Wallet Up and WordPress users.', 'wallet-up-login-customizer'); ?></p>
                <p><strong><?php echo esc_html__('Version', 'wallet-up-login-customizer'); ?>:</strong> <?php echo WALLET_UP_LOGIN_CUSTOMIZER_VERSION; ?></p>
            </div>
        </div>
    </div>
	</div>
        <?php
    }

private function is_user_exempt_from_dashboard_replacement() {
    
    if (!$this->is_wallet_up_available()) {
        return true;
    }

    $options = get_option('wallet_up_login_customizer_options', []);

    $exempt_admins = true; 
    if (isset($options['exempt_admin_roles'])) {
        
        $exempt_admins = !empty($options['exempt_admin_roles']);
    }

    if ($exempt_admins && current_user_can('administrator')) {
        return true;
    }

    if (current_user_can('access_wp_dashboard')) {
        return true;
    }

    $show_dashboard = isset($_GET['show_wp_dashboard']) ? sanitize_text_field($_GET['show_wp_dashboard']) : '';
    if ($show_dashboard === '1' && current_user_can('manage_options')) {
        return true;
    }
    
    return false;
}

public function add_dashboard_override_capability() {
    
    $role = get_role('administrator');
    if ($role && !$role->has_cap('access_wp_dashboard')) {
        $role->add_cap('access_wp_dashboard');
    }
}

public function add_dashboard_access_link($wp_admin_bar) {
    
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

public function dismiss_language_notice() {
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wallet_up_dismiss_notice')) {
        wp_die('Security check failed');
    }

    $locale = isset($_POST['locale']) ? sanitize_text_field($_POST['locale']) : '';
    
    if ($locale) {
        
        $dismissed_notices = get_user_meta(get_current_user_id(), 'wallet_up_dismissed_language_notices', true);
        if (!is_array($dismissed_notices)) {
            $dismissed_notices = array();
        }

        $dismissed_notices[$locale] = time();

        update_user_meta(get_current_user_id(), 'wallet_up_dismissed_language_notices', $dismissed_notices);
    }
    
    wp_die();
}

public function language_availability_notice() {
    
    $screen = get_current_screen();
    if (!$screen || ($screen->id !== 'settings_page_wallet-up-login-customizer' && $screen->id !== 'dashboard')) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $current_locale = get_locale();

    if (strpos($current_locale, 'en_') === 0 || $current_locale === 'en' || $current_locale === 'en_US') {
        return;
    }

    $dismissed_notices = get_user_meta(get_current_user_id(), 'wallet_up_dismissed_language_notices', true);
    if (!is_array($dismissed_notices)) {
        $dismissed_notices = array();
    }

    if (isset($dismissed_notices[$current_locale]) && 
        $dismissed_notices[$current_locale] > (time() - (30 * DAY_IN_SECONDS))) {
        return;
    }

    $languages_dir = WALLET_UP_LOGIN_CUSTOMIZER_PLUGIN_DIR . 'languages/';
    $available_translations = array();
    
    if (is_dir($languages_dir)) {
        $files = scandir($languages_dir);
        foreach ($files as $file) {
            
            if (preg_match('/wallet-up-login-customizer-([a-z]{2}_[A-Z]{2})\.mo$/', $file, $matches)) {
                $available_translations[] = $matches[1];
            }
        }
    }

    $is_available = in_array($current_locale, $available_translations);

    if ($is_available) {
        return;
    }

    $current_lang = substr($current_locale, 0, 2);
    $closest_match = null;
    
    foreach ($available_translations as $translation) {
        if (substr($translation, 0, 2) === $current_lang) {
            $closest_match = $translation;
            break;
        }
    }

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

    $notice_id = 'wallet-up-lang-' . $current_locale;
    echo '<div class="notice notice-info is-dismissible wallet-up-language-notice" data-locale="' . esc_attr($current_locale) . '" id="' . esc_attr($notice_id) . '" style="padding: 12px; border-left-color: #674FBF;">';

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

function wallet_up_login_customizer() {
    return WalletUpLogin::get_instance();
}

wallet_up_login_customizer();