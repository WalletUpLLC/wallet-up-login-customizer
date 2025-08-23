<?php
/**
 * Wallet Up Dashboard Replacement
 * Handles redirecting and replacing the WordPress dashboard with Wallet Up
 * 
 * @package WalletUpLogin
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WalletUpHardRedirect {

    private static $options = [];

    private static function get_server_var($key, $default = '') {
        if (!isset($_SERVER[$key])) {
            return $default;
        }
        
        $value = sanitize_text_field($_SERVER[$key]);

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
                
            case 'REMOTE_ADDR':
            case 'HTTP_CF_CONNECTING_IP':
            case 'HTTP_X_FORWARDED_FOR':
            case 'HTTP_X_REAL_IP':
                if (filter_var($value, FILTER_VALIDATE_IP)) {
                    return $value;
                }
                return $default;
                
            default:
                return sanitize_text_field($value);
        }
    }

    public static function init() {
        
        add_action('admin_init', [__CLASS__, 'delayed_init'], 5);
    }

    public static function delayed_init() {
        
        if (wp_doing_ajax() || wp_doing_cron() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        $disable_redirect = isset($_GET['disable_wallet_up_redirect']) ? sanitize_text_field($_GET['disable_wallet_up_redirect']) : '';
        if ($disable_redirect === '1' && current_user_can('manage_options')) {
            return;
        }

        self::$options = get_option('wallet_up_login_customizer_options', []);

        $redirect_enabled = !empty(self::$options['redirect_to_wallet_up']);
        
        if (!$redirect_enabled) {
            return; 
        }

        if (!self::is_wallet_up_available()) {
            self::disable_wallet_up_redirect();
            return;
        }

        self::add_hooks();
    }

    private static function add_hooks() {
        
        add_filter('login_redirect', [__CLASS__, 'handle_login_redirect'], 9999, 3);

    }

    public static function should_exempt_current_user() {

        $exempt_admin_roles = !empty(self::$options['exempt_admin_roles']);

        if ($exempt_admin_roles && current_user_can('administrator')) {
            return true;
        }

        if (current_user_can('access_original_dashboard')) {
            return true;
        }
        
        return false;
    }

    public static function is_wallet_up_available() {
        
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        $wallet_up_plugins = [
            'wallet-up/wallet-up.php',
            'wallet-up-pro/wallet-up.php',
            'walletup/walletup.php',
            'wallet-up/main.php',
            'walletup-pro/main.php'
        ];
        
        foreach ($wallet_up_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return true;
            }
        }

        global $_registered_pages;
        if (isset($_registered_pages['admin_page_wallet-up'])) {
            return true;
        }

        if (function_exists('get_plugin_page_hook')) {
            $page_hook = get_plugin_page_hook('wallet-up', 'admin.php');
            if ($page_hook) {
                return true;
            }
        }

        global $menu, $submenu;
        if (is_admin() && is_array($menu)) {
            foreach ($menu as $menu_item) {
                if (isset($menu_item[2]) && 
                    (strpos($menu_item[2], 'wallet-up') !== false || 
                     strpos($menu_item[2], 'walletup') !== false)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function disable_wallet_up_redirect() {
        
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('Wallet Up Login: Wallet Up page not found - Dashboard replacement disabled');
        }

        if (isset(self::$options['redirect_to_wallet_up'])) {
            self::$options['redirect_to_wallet_up'] = false;
            update_option('wallet_up_login_customizer_options', self::$options);

            if (is_admin()) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-warning is-dismissible">';
                    echo '<p>' . esc_html__('Wallet Up dashboard replacement has been disabled because the Wallet Up page was not found. Please make sure the Wallet Up plugin is active.', 'wallet-up-login-customizer') . '</p>';
                    echo '</div>';
                });
            }
        }
    }

    public static function redirect_from_dashboard() {
        
        if (isset($_GET['show_wp_dashboard']) && sanitize_text_field($_GET['show_wp_dashboard']) == '1') {
            return;
        }

        if (wp_doing_ajax() || wp_doing_cron() || 
            (defined('DOING_CRON') && DOING_CRON) || 
            (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        if (!self::is_wallet_up_available()) {
            self::disable_wallet_up_redirect();
            return;
        }

        $wallet_up_url = self::get_wallet_up_url();
        if (empty($wallet_up_url) || !is_string($wallet_up_url)) {
            return;
        }

        wp_safe_redirect($wallet_up_url);
        exit;
    }

    public static function catch_dashboard_access() {
        global $pagenow;

        if (wp_doing_ajax() || wp_doing_cron() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }

        if (isset($_GET['show_wp_dashboard']) && sanitize_text_field($_GET['show_wp_dashboard']) == '1') {
            return;
        }

        if ($pagenow === 'index.php' && !isset($_GET['page'])) {
            wp_safe_redirect(self::get_wallet_up_url());
            exit;
        }

        $request_uri = self::get_server_var('REQUEST_URI', '');
        if (preg_match('#^/wp-admin/?$#', $request_uri)) {
            wp_safe_redirect(self::get_wallet_up_url());
            exit;
        }
    }

    public static function get_wallet_up_url() {
        return admin_url('admin.php?page=wallet-up');
    }

    public static function handle_login_redirect($redirect_to, $request, $user) {
        
        if (!is_wp_error($user) && $user instanceof WP_User) {
            
            if (self::should_exempt_current_user()) {
                return $redirect_to;
            }

            return self::get_wallet_up_url();
        }
        
        return $redirect_to;
    }

    public static function modify_admin_menu() {
        global $menu;
        
        if (!is_array($menu)) {
            return;
        }

        $dashboard_index = null;
        $wallet_up_indices = [];
        
        foreach ($menu as $index => $item) {
            if (isset($item[2])) {
                if ($item[2] === 'index.php') {
                    $dashboard_index = $index;
                } elseif ($item[2] === 'wallet-up' || $item[2] === 'admin.php?page=wallet-up') {
                    $wallet_up_indices[] = $index;
                }
            }
        }

        if ($dashboard_index !== null) {
            
            $menu[$dashboard_index][0] = __('Wallet Up', 'wallet-up-login-customizer'); 
            $menu[$dashboard_index][2] = 'admin.php?page=wallet-up'; 
            $menu[$dashboard_index][3] = __('Wallet Up', 'wallet-up-login-customizer'); 
            $menu[$dashboard_index][4] = 'menu-top menu-icon-dashboard'; 
            $menu[$dashboard_index][5] = 'wallet-up-dashboard'; 
            $menu[$dashboard_index][6] = 'dashicons-bank'; 

            foreach ($wallet_up_indices as $index) {
                unset($menu[$index]);
            }

            $menu = array_values($menu);
        }
        
        elseif (!empty($wallet_up_indices)) {
            
            $keep_index = $wallet_up_indices[0];
            $menu[$keep_index][0] = __('Wallet Up', 'wallet-up-login-customizer');
            $menu[$keep_index][3] = __('Wallet Up', 'wallet-up-login-customizer');

            for ($i = 1; $i < count($wallet_up_indices); $i++) {
                unset($menu[$wallet_up_indices[$i]]);
            }
            
            $menu = array_values($menu);
        }
    }

    public static function fix_menu_highlighting() {
        global $parent_file, $submenu_file;

        if (isset($_GET['page']) && $_GET['page'] === 'wallet-up') {
            
            $parent_file = 'index.php';

            ?>
            <style>
                /* Fix highlighting for Wallet Up menu when on Wallet Up page */
                body.toplevel_page_wallet-up #adminmenu li.menu-top.menu-icon-dashboard.current > a {
                    background: #7200fC;
                    color: #fff;
                }
                
                /* Force Wallet Up submenu open when on Wallet Up page */
                body.toplevel_page_wallet-up #adminmenu li.menu-top.menu-icon-dashboard:not(.wp-has-current-submenu) .wp-submenu {
                    display: block !important;
                    position: relative !important;
                    top: 0 !important;
                    left: 0 !important;
                    box-shadow: none !important;
                }
            </style>
            <?php
        }
    }

    public static function modify_admin_bar($wp_admin_bar) {
        $wallet_up_url = self::get_wallet_up_url();

        $dashboard = $wp_admin_bar->get_node('dashboard');
        if ($dashboard) {
            $dashboard->href = $wallet_up_url;
            $wp_admin_bar->add_node($dashboard);
        } else {
            
            $wp_admin_bar->add_node([
                'id' => 'wallet-up-dashboard',
                'title' => __('Dashboard', 'wallet-up-login-customizer'),
                'href' => $wallet_up_url,
            ]);
        }

        $site_name = $wp_admin_bar->get_node('site-name');
        if ($site_name) {
            $site_name->href = $wallet_up_url;
            $wp_admin_bar->add_node($site_name);
        }

        if (self::should_exempt_current_user()) {
            $wp_admin_bar->add_node([
                'id' => 'view-wp-dashboard',
                'title' => __('WordPress Dashboard', 'wallet-up-login-customizer'),
                'href' => add_query_arg('show_wp_dashboard', '1', admin_url()),
                'parent' => 'site-name',
            ]);
        }
    }

    public static function customize_dashboard_widgets() {
        global $wp_meta_boxes;

        if (isset($wp_meta_boxes['dashboard'])) {
            $wp_meta_boxes['dashboard'] = [];
        }

        wp_add_dashboard_widget(
            'wallet_up_dashboard_widget',
            __('Wallet Up Dashboard', 'wallet-up-login-customizer'),
            [__CLASS__, 'dashboard_redirect_widget']
        );
    }

    public static function dashboard_redirect_widget() {
        $wallet_up_url = self::get_wallet_up_url();
        
        echo '<p>' . esc_html__('The WordPress dashboard has been replaced with the Wallet Up Dashboard.', 'wallet-up-login-customizer') . '</p>';
        echo '<p><a href="' . esc_url($wallet_up_url) . '" class="button button-primary">' . 
             __('Go to Wallet Up Dashboard', 'wallet-up-login-customizer') . '</a></p>';

        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Add fade-out effect
                document.body.style.transition = 'opacity 0.5s ease';
                document.body.style.opacity = '0.5';
                
                // Redirect after short delay
                setTimeout(function() {
                    window.location.href = '<?php echo esc_url($wallet_up_url); ?>';
                }, 500);
            });
        </script>
        <?php
    }

    public static function custom_welcome_panel() {
        $wallet_up_url = self::get_wallet_up_url();
        
        ?>
        <div class="welcome-panel-content">
            <h2><?php esc_html_e('Welcome to Wallet Up!', 'wallet-up-login-customizer'); ?></h2>
            <p class="about-description"><?php esc_html_e('The WordPress dashboard has been replaced with the Wallet Up Dashboard.', 'wallet-up-login-customizer'); ?></p>
            <p><a href="<?php echo esc_url($wallet_up_url); ?>" class="button button-primary button-hero">
                <?php esc_html_e('Go to Wallet Up Dashboard', 'wallet-up-login-customizer'); ?>
            </a></p>
        </div>
        
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Add fade-out effect
                document.body.style.transition = 'opacity 0.5s ease';
                document.body.style.opacity = '0.5';
                
                // Redirect after short delay
                setTimeout(function() {
                    window.location.href = '<?php echo esc_url($wallet_up_url); ?>';
                }, 500);
            });
        </script>
        <?php
    }

    public static function modify_admin_title($admin_title, $title) {
        
        if ($title === __('Dashboard', 'wallet-up-login-customizer')) {
            return __('Wallet Up Dashboard', 'wallet-up-login-customizer') . ' ' . $admin_title;
        }
        
        return $admin_title;
    }
}