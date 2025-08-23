<?php
/**
 * Complete Standalone Dashboard Replacement
 * 
 * Drop this file into your main plugin directory and include it from your main plugin file.
 * This is a standalone solution that handles all edge cases and requires no other changes.
 * 
 * @package WalletUpLogin
 */

if (!defined('ABSPATH')) {
    exit; 
}

class WalletUpForceRedirect {
    
    private static $instance = null;

    private $option_name = 'wallet_up_login_customizer_options';

    private $wallet_up_available = false;

    private $original_redirect_setting = false;

    private function __construct() {
        
        $options = get_option($this->option_name, array());
        $this->original_redirect_setting = isset($options['redirect_to_wallet_up']) ? (bool)$options['redirect_to_wallet_up'] : false;

        $this->wallet_up_available = $this->check_wallet_up_available();

        $this->init_hooks();
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_hooks() {
        
        if ($this->original_redirect_setting && $this->wallet_up_available) {
            
            add_action('init', array($this, 'prevent_dashboard_access'), 0);
            add_action('admin_init', array($this, 'force_dashboard_redirect'), 0);
            add_action('template_redirect', array($this, 'catch_admin_access'), 0);

            add_filter('login_redirect', array($this, 'login_redirect'), 999999, 3);
            add_action('wp_login', array($this, 'after_login_redirect'), 10, 2);

            add_action('admin_menu', array($this, 'modify_admin_menu'), 999999);
            add_action('admin_bar_menu', array($this, 'modify_admin_bar'), 999);
            add_action('admin_head', array($this, 'add_admin_scripts'), 999);

            add_action('wp_dashboard_setup', array($this, 'remove_dashboard_widgets'), 999);
            remove_action('welcome_panel', 'wp_welcome_panel');
            add_action('welcome_panel', array($this, 'custom_welcome_panel'));
        } elseif ($this->original_redirect_setting && !$this->wallet_up_available) {
            
            add_action('admin_init', array($this, 'disable_redirect_option'));
            add_action('admin_notices', array($this, 'wallet_up_not_available_notice'));
        }

        add_filter('pre_update_option_' . $this->option_name, array($this, 'validate_wallet_up_option'), 10, 2);
    }

    public function add_admin_scripts() {
        
        echo '<style>
            /* Fix duplicate menu items */
            #adminmenu > li.wp-has-submenu:nth-child(n+3) a[href$="admin.php?page=wallet-up"] {
                display: none !important;
            }
            
            /* Fix menu highlighting when on Wallet Up page */
            body.toplevel_page_wallet-up #adminmenu li.menu-top.menu-icon-dashboard > a {
               
            }

            /* Hide Dashboard submenu item but keep Wallet Up */
            body.toplevel_page_wallet-up ul.wp-submenu a[href="index.php"] {
                display: none !important;
            }
            
            /* Hide Wallet Up in Dashboard submenu (to avoid duplicates) */
            #adminmenu li.menu-top ul.wp-submenu a[href$="admin.php?page=wallet-up"]:not(:first-child) {
                display: inherit !important;
            }
            
            /* Force Dashboard menu open when on Wallet Up page */
            body.toplevel_page_wallet-up #adminmenu li.menu-top.menu-icon-dashboard:not(.wp-has-current-submenu) .wp-submenu {
                display: block !important;
                position: relative !important;
                top: 0 !important;
                left: 0 !important;
                box-shadow: inherit !important;
            }
        </style>';

        echo '<script>
            jQuery(document).ready(function($) {
                // If on Wallet Up page
                if (window.location.href.indexOf("admin.php?page=wallet-up") > -1) {
                    // Fix Dashboard menu highlight
                    $("#adminmenu li.menu-top.menu-icon-dashboard")
                        .addClass("wp-has-current-submenu wp-menu-open current")
                        .removeClass("wp-not-current-submenu");
                    
                    // Find and remove duplicates
                    var seenMenus = {};
                    $("#adminmenu a").each(function() {
                        var href = $(this).attr("href");
                        if (href && href.indexOf("admin.php?page=wallet-up") > -1) {
                            if (seenMenus[href]) {
                                $(this).parent().hide();
                            } else {
                                seenMenus[href] = true;
                            }
                        }
                    });
                }
                
                // If on dashboard, redirect to Wallet Up
                if (window.location.pathname.endsWith("/wp-admin/index.php") && !window.location.search) {
                    window.location.href = "' . esc_js(admin_url('admin.php?page=wallet-up')) . '";
                }
            });
        </script>';
    }

    private function check_wallet_up_available() {
        
        global $wpdb;

        global $menu, $submenu;

        if (is_array($menu)) {
            foreach ($menu as $item) {
                if (isset($item[2]) && $item[2] === 'wallet-up') {
                    return true;
                }
            }
        }

        if (is_array($submenu)) {
            foreach ($submenu as $parent => $items) {
                foreach ($items as $item) {
                    if (isset($item[2]) && $item[2] === 'wallet-up') {
                        return true;
                    }
                }
            }
        }

        if (function_exists('get_plugin_page_hook')) {
            $page_hook = get_plugin_page_hook('wallet-up', 'admin.php');
            if ($page_hook !== null) {
                return true;
            }
        }

        if (function_exists('is_plugin_active')) {
            if (is_plugin_active('wallet-up/wallet-up.php') || 
                is_plugin_active('wallet-up-premium/wallet-up.php')) {
                return true;
            }
        }

        if (file_exists(WP_PLUGIN_DIR . '/wallet-up/includes/admin/wallet-up-admin.php')) {
            return true;
        }

        return false;
    }

    public function prevent_dashboard_access() {
        global $pagenow;

        if (is_admin() && $pagenow === 'index.php' && !isset($_GET['page'])) {
            
            $wallet_up_url = admin_url('admin.php?page=wallet-up');

            nocache_headers();

            wp_redirect($wallet_up_url);
            exit;
        }
    }

    public function force_dashboard_redirect() {
        
        if (defined('DOING_AJAX') || defined('DOING_CRON') || wp_doing_ajax()) {
            return;
        }
        
        global $pagenow;

        if ($pagenow === 'index.php' && !isset($_GET['page'])) {
            
            $wallet_up_url = admin_url('admin.php?page=wallet-up');

            nocache_headers();

            wp_redirect($wallet_up_url);
            exit;
        }
    }

    public function catch_admin_access() {
        
        if (!is_admin()) {
            return;
        }

        if (defined('DOING_AJAX') || defined('DOING_CRON') || wp_doing_ajax()) {
            return;
        }

        $current_path = esc_url_raw($_SERVER['REQUEST_URI'] ?? '');

        if (preg_match('#^/wp-admin/?$#', $current_path)) {
            
            $wallet_up_url = admin_url('admin.php?page=wallet-up');

            nocache_headers();

            wp_redirect($wallet_up_url);
            exit;
        }
    }

    public function login_redirect($redirect_to, $request, $user) {
        
        if (!is_wp_error($user) && $user instanceof WP_User) {
            
            $wallet_up_url = admin_url('admin.php?page=wallet-up');

            return $wallet_up_url;
        }
        
        return $redirect_to;
    }

    public function after_login_redirect($user_login, $user) {

        if ($user && isset($user->ID)) {
            update_user_meta($user->ID, 'wallet_up_pending_redirect', true);

            set_transient('wallet_up_redirect_' . $user->ID, true, 300);
        }
    }

    public function modify_admin_menu() {
        global $menu, $submenu;

        $dashboard_index = null;
        if (is_array($menu)) {
            foreach ($menu as $index => $item) {
                if (isset($item[2]) && $item[2] === 'index.php') {
                    $dashboard_index = $index;
                    break;
                }
            }
        }

        if ($dashboard_index !== null) {
            
            $menu[$dashboard_index][2] = 'admin.php?page=wallet-up';

            if (isset($submenu['index.php']) && is_array($submenu['index.php'])) {
                
                if (!isset($submenu['admin.php'])) {
                    $submenu['admin.php'] = array();
                }

                if (isset($submenu['index.php'][0])) {
                    $home_item = $submenu['index.php'][0];
                    $home_item[2] = 'admin.php?page=wallet-up';
                    $submenu['admin.php'][] = $home_item;
                }
            }
        }
    }

    public function modify_admin_bar($wp_admin_bar) {
        
        $wallet_up_url = admin_url('admin.php?page=wallet-up');

        $wp_admin_bar->remove_node('dashboard');

        $wp_admin_bar->add_node(array(
            'id'    => 'dashboard',
            'title' => __('Dashboard', 'wallet-up-login-customizer'),
            'href'  => $wallet_up_url,
        ));

        $site_name = $wp_admin_bar->get_node('site-name');
        if ($site_name) {
            $site_name->href = $wallet_up_url;
            $wp_admin_bar->add_node($site_name);
        }
    }

    public function remove_dashboard_widgets() {
        global $wp_meta_boxes;

        if (isset($wp_meta_boxes['dashboard'])) {
            $wp_meta_boxes['dashboard'] = array();
        }

        wp_add_dashboard_widget(
            'wallet_up_redirect_widget',
            'Wallet Up Dashboard',
            array($this, 'wallet_up_widget_content')
        );
    }

    public function custom_welcome_panel() {
        $wallet_up_url = admin_url('admin.php?page=wallet-up');
        
        echo '<div class="welcome-panel-content">';
        echo '<h2>' . esc_html__('Welcome to Wallet Up Dashboard!', 'wallet-up-login-customizer') . '</h2>';
        echo '<p class="about-description">' . esc_html__('The WordPress dashboard has been replaced with the Wallet Up Dashboard.', 'wallet-up-login-customizer') . '</p>';
        echo '<p><a href="' . esc_url($wallet_up_url) . '" class="button button-primary button-hero">' . esc_html__('Go to Wallet Up Dashboard', 'wallet-up-login-customizer') . '</a></p>';
        echo '</div>';

        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                window.location.href = "' . esc_url($wallet_up_url) . '";
            });
        </script>';
    }

    public function wallet_up_widget_content() {
        $wallet_up_url = admin_url('admin.php?page=wallet-up');
        
        echo '<p>' . esc_html__('The WordPress dashboard has been replaced with the Wallet Up Dashboard.', 'wallet-up-login-customizer') . '</p>';
        echo '<p><a href="' . esc_url($wallet_up_url) . '" class="button button-primary">' . esc_html__('Go to Wallet Up Dashboard', 'wallet-up-login-customizer') . '</a></p>';

        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                window.location.href = "' . esc_url($wallet_up_url) . '";
            });
        </script>';
    }

    public function disable_redirect_option() {
        
        $options = get_option($this->option_name, array());

        if (isset($options['redirect_to_wallet_up']) && $options['redirect_to_wallet_up']) {
            
            $options['redirect_to_wallet_up'] = false;

            update_option($this->option_name, $options);
        }
    }

    public function wallet_up_not_available_notice() {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>' . esc_html__('Wallet Up Login:', 'wallet-up-login-customizer') . '</strong> ' . esc_html__('The "Land to Wallet Up" option has been disabled because the Wallet Up admin page could not be found. Please make sure Wallet Up is installed and activated before enabling this feature.', 'wallet-up-login-customizer') . '</p>';
        echo '</div>';
    }

    public function validate_wallet_up_option($new_value, $old_value) {
        
        if (isset($new_value['redirect_to_wallet_up']) && 
            $new_value['redirect_to_wallet_up'] && 
            (!isset($old_value['redirect_to_wallet_up']) || !$old_value['redirect_to_wallet_up'])) {

            if (!$this->wallet_up_available) {
                
                $new_value['redirect_to_wallet_up'] = false;

                set_transient('wallet_up_login_customizer_wallet_up_not_available', true, 60);
            }
        }
        
        return $new_value;
    }
}

add_action('plugins_loaded', function() {
    WalletUpForceRedirect::get_instance();
});