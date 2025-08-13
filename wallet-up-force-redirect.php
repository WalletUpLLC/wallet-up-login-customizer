<?php
/**
 * Complete Standalone Dashboard Replacement
 * 
 * Drop this file into your main plugin directory and include it from your main plugin file.
 * This is a standalone solution that handles all edge cases and requires no other changes.
 * 
 * @package WalletUpLogin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Standalone class to force redirection to Wallet Up admin page
 * This is a complete replacement solution with edge case handling
 */
class WalletUpForceRedirect {
    /**
     * Singleton instance
     * @var WalletUpForceRedirect
     */
    private static $instance = null;
    
    /**
     * Option name for storing settings
     * @var string
     */
    private $option_name = 'wallet_up_login_options';
    
    /**
     * Whether Wallet Up is available
     * @var bool
     */
    private $wallet_up_available = false;
    
    /**
     * Original redirect option value
     * @var bool
     */
    private $original_redirect_setting = false;
    
    /**
     * Private constructor to enforce singleton
     */
    private function __construct() {
        // Load options
        $options = get_option($this->option_name, array());
        $this->original_redirect_setting = isset($options['redirect_to_wallet_up']) ? (bool)$options['redirect_to_wallet_up'] : false;
        
        // Check if Wallet Up is available before doing anything
        $this->wallet_up_available = $this->check_wallet_up_available();
        
        // Initialize all hooks
        $this->init_hooks();
    }
    
    /**
     * Get singleton instance
     * 
     * @return WalletUpForceRedirect Instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize all hooks
     */
    private function init_hooks() {
        // Only activate redirection if the option is enabled AND Wallet Up is available
        if ($this->original_redirect_setting && $this->wallet_up_available) {
            // Early hooks
            add_action('init', array($this, 'prevent_dashboard_access'), 0);
            add_action('admin_init', array($this, 'force_dashboard_redirect'), 0);
            add_action('template_redirect', array($this, 'catch_admin_access'), 0);
            
            // Login redirection
            add_filter('login_redirect', array($this, 'login_redirect'), 999999, 3);
            add_action('wp_login', array($this, 'after_login_redirect'), 10, 2);
            
            // Menu and UI adjustments
            add_action('admin_menu', array($this, 'modify_admin_menu'), 999999);
            add_action('admin_bar_menu', array($this, 'modify_admin_bar'), 999);
            add_action('admin_head', array($this, 'add_admin_scripts'), 999);
            
            // Dashboard customization
            add_action('wp_dashboard_setup', array($this, 'remove_dashboard_widgets'), 999);
            remove_action('welcome_panel', 'wp_welcome_panel');
            add_action('welcome_panel', array($this, 'custom_welcome_panel'));
        } elseif ($this->original_redirect_setting && !$this->wallet_up_available) {
            // Wallet Up not available but option is enabled - disable it and show notice
            add_action('admin_init', array($this, 'disable_redirect_option'));
            add_action('admin_notices', array($this, 'wallet_up_not_available_notice'));
        }
        
        // Add option validation for the Wallet Up redirection checkbox
        add_filter('pre_update_option_' . $this->option_name, array($this, 'validate_wallet_up_option'), 10, 2);
    }
    
    /**
     * Add admin scripts and styles for menu fixing
     */
    public function add_admin_scripts() {
        // Add CSS fixes for menu
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
        
        // Add JavaScript fixes for menu items
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
                    window.location.href = "' . admin_url('admin.php?page=wallet-up') . '";
                }
            });
        </script>';
    }
    
    /**
     * Check if Wallet Up is available
     * 
     * @return bool True if available, false otherwise
     */
    private function check_wallet_up_available() {
        // First check: look for the menu item in database
        global $wpdb;
        
        // Most reliable method - check if admin menu item exists
        global $menu, $submenu;
        
        // Try direct menu item check
        if (is_array($menu)) {
            foreach ($menu as $item) {
                if (isset($item[2]) && $item[2] === 'wallet-up') {
                    return true;
                }
            }
        }
        
        // Check submenu too
        if (is_array($submenu)) {
            foreach ($submenu as $parent => $items) {
                foreach ($items as $item) {
                    if (isset($item[2]) && $item[2] === 'wallet-up') {
                        return true;
                    }
                }
            }
        }
        
        // Next check: admin page hook - does the page exist?
        if (function_exists('get_plugin_page_hook')) {
            $page_hook = get_plugin_page_hook('wallet-up', 'admin.php');
            if ($page_hook !== null) {
                return true;
            }
        }
        
        // Third check: try to see if plugin is active
        if (function_exists('is_plugin_active')) {
            if (is_plugin_active('wallet-up/wallet-up.php') || 
                is_plugin_active('wallet-up-premium/wallet-up.php')) {
                return true;
            }
        }
        
        // Optional compatibility fix - you might need to adjust this path
        // If the URL can be accessed, it's probably there
        if (file_exists(WP_PLUGIN_DIR . '/wallet-up/includes/admin/wallet-up-admin.php')) {
            return true;
        }
        
        // If we got here, Wallet Up doesn't seem to be available
        return false;
    }
    
    /**
     * Prevent direct dashboard access via init hook
     */
    public function prevent_dashboard_access() {
        global $pagenow;
        
        // Check if we're on the dashboard
        if (is_admin() && $pagenow === 'index.php' && !isset($_GET['page'])) {
            // Get Wallet Up URL
            $wallet_up_url = admin_url('admin.php?page=wallet-up');
            
            // Set no-cache headers
            nocache_headers();
            
            // Redirect
            wp_redirect($wallet_up_url);
            exit;
        }
    }
    
    /**
     * Force dashboard redirect on admin_init
     */
    public function force_dashboard_redirect() {
        // Skip for AJAX requests
        if (defined('DOING_AJAX') || defined('DOING_CRON') || wp_doing_ajax()) {
            return;
        }
        
        global $pagenow;
        
        // Only redirect from dashboard
        if ($pagenow === 'index.php' && !isset($_GET['page'])) {
            // Get Wallet Up URL
            $wallet_up_url = admin_url('admin.php?page=wallet-up');
            
            // Set no-cache headers
            nocache_headers();
            
            // Redirect
            wp_redirect($wallet_up_url);
            exit;
        }
    }
    
    /**
     * Catch direct admin access
     */
    public function catch_admin_access() {
        // Only process in admin area
        if (!is_admin()) {
            return;
        }
        
        // Skip for AJAX requests
        if (defined('DOING_AJAX') || defined('DOING_CRON') || wp_doing_ajax()) {
            return;
        }
        
        // Get current path
        // SECURITY: Sanitize REQUEST_URI to prevent injection
        $current_path = esc_url_raw($_SERVER['REQUEST_URI'] ?? '');
        
        // Check if this is the main wp-admin URL
        if (preg_match('#^/wp-admin/?$#', $current_path)) {
            // Get Wallet Up URL
            $wallet_up_url = admin_url('admin.php?page=wallet-up');
            
            // Set no-cache headers
            nocache_headers();
            
            // Redirect
            wp_redirect($wallet_up_url);
            exit;
        }
    }
    
    /**
     * Login redirect filter - ultra high priority
     * 
     * @param string $redirect_to Default redirect URL
     * @param string $request Requested redirect URL
     * @param WP_User|WP_Error $user User object or error
     * @return string New redirect URL
     */
    public function login_redirect($redirect_to, $request, $user) {
        // Only redirect if login was successful
        if (!is_wp_error($user) && $user instanceof WP_User) {
            // Get Wallet Up URL
            $wallet_up_url = admin_url('admin.php?page=wallet-up');
            
            // Override any other redirect
            return $wallet_up_url;
        }
        
        return $redirect_to;
    }
    
    /**
     * After login hook to ensure redirection
     * 
     * @param string $user_login Username
     * @param WP_User $user User object
     */
    public function after_login_redirect($user_login, $user) {
        // SECURITY: Use WordPress user meta instead of PHP sessions
        // This is more secure and prevents session fixation attacks
        if ($user && isset($user->ID)) {
            update_user_meta($user->ID, 'wallet_up_pending_redirect', true);
            
            // Set expiration for 5 minutes
            set_transient('wallet_up_redirect_' . $user->ID, true, 300);
        }
    }
    
    /**
     * Modify admin menu
     * This handles menu duplication and highlighting 
     */
    public function modify_admin_menu() {
        global $menu, $submenu;
        
        // Find the dashboard menu item
        $dashboard_index = null;
        if (is_array($menu)) {
            foreach ($menu as $index => $item) {
                if (isset($item[2]) && $item[2] === 'index.php') {
                    $dashboard_index = $index;
                    break;
                }
            }
        }
        
        // If dashboard exists, redirect it to Wallet Up
        if ($dashboard_index !== null) {
            // Replace dashboard link with Wallet Up link
            $menu[$dashboard_index][2] = 'admin.php?page=wallet-up';
            
            // Fix submenu if it exists
            if (isset($submenu['index.php']) && is_array($submenu['index.php'])) {
                // Create a fake entry in admin.php submenu
                if (!isset($submenu['admin.php'])) {
                    $submenu['admin.php'] = array();
                }
                
                // Copy Home entry but point to Wallet Up
                if (isset($submenu['index.php'][0])) {
                    $home_item = $submenu['index.php'][0];
                    $home_item[2] = 'admin.php?page=wallet-up';
                    $submenu['admin.php'][] = $home_item;
                }
            }
        }
    }
    
    /**
     * Modify admin bar
     * 
     * @param WP_Admin_Bar $wp_admin_bar Admin bar object
     */
    public function modify_admin_bar($wp_admin_bar) {
        // Get Wallet Up URL
        $wallet_up_url = admin_url('admin.php?page=wallet-up');
        
        // Remove dashboard node
        $wp_admin_bar->remove_node('dashboard');
        
        // Add dashboard node pointing to Wallet Up
        $wp_admin_bar->add_node(array(
            'id'    => 'dashboard',
            'title' => __('Dashboard'),
            'href'  => $wallet_up_url,
        ));
        
        // Modify site name node
        $site_name = $wp_admin_bar->get_node('site-name');
        if ($site_name) {
            $site_name->href = $wallet_up_url;
            $wp_admin_bar->add_node($site_name);
        }
    }
    
    /**
     * Remove dashboard widgets
     */
    public function remove_dashboard_widgets() {
        global $wp_meta_boxes;
        
        // Clear all dashboard widgets
        if (isset($wp_meta_boxes['dashboard'])) {
            $wp_meta_boxes['dashboard'] = array();
        }
        
        // Add custom widget
        wp_add_dashboard_widget(
            'wallet_up_redirect_widget',
            'Wallet Up Dashboard',
            array($this, 'wallet_up_widget_content')
        );
    }
    
    /**
     * Custom welcome panel
     */
    public function custom_welcome_panel() {
        $wallet_up_url = admin_url('admin.php?page=wallet-up');
        
        echo '<div class="welcome-panel-content">';
        echo '<h2>' . esc_html__('Welcome to Wallet Up Dashboard!', 'wallet-up-login') . '</h2>';
        echo '<p class="about-description">' . esc_html__('The WordPress dashboard has been replaced with the Wallet Up Dashboard.', 'wallet-up-login') . '</p>';
        echo '<p><a href="' . esc_url($wallet_up_url) . '" class="button button-primary button-hero">' . esc_html__('Go to Wallet Up Dashboard', 'wallet-up-login') . '</a></p>';
        echo '</div>';
        
        // Add JavaScript to redirect
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                window.location.href = "' . esc_url($wallet_up_url) . '";
            });
        </script>';
    }
    
    /**
     * Custom dashboard widget content
     */
    public function wallet_up_widget_content() {
        $wallet_up_url = admin_url('admin.php?page=wallet-up');
        
        echo '<p>' . esc_html__('The WordPress dashboard has been replaced with the Wallet Up Dashboard.', 'wallet-up-login') . '</p>';
        echo '<p><a href="' . esc_url($wallet_up_url) . '" class="button button-primary">' . esc_html__('Go to Wallet Up Dashboard', 'wallet-up-login') . '</a></p>';
        
        // Add JavaScript to redirect
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                window.location.href = "' . esc_url($wallet_up_url) . '";
            });
        </script>';
    }
    
    /**
     * Disable redirect option when Wallet Up is not available
     */
    public function disable_redirect_option() {
        // Get current options
        $options = get_option($this->option_name, array());
        
        // Only continue if the option is set
        if (isset($options['redirect_to_wallet_up']) && $options['redirect_to_wallet_up']) {
            // Disable the option
            $options['redirect_to_wallet_up'] = false;
            
            // Update the option
            update_option($this->option_name, $options);
        }
    }
    
    /**
     * Show notice when Wallet Up is not available
     */
    public function wallet_up_not_available_notice() {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>' . esc_html__('Wallet Up Login:', 'wallet-up-login') . '</strong> ' . esc_html__('The "Land to Wallet Up" option has been disabled because the Wallet Up admin page could not be found. Please make sure Wallet Up is installed and activated before enabling this feature.', 'wallet-up-login') . '</p>';
        echo '</div>';
    }
    
    /**
     * Validate the Wallet Up option before saving
     * 
     * @param array $new_value New option value
     * @param array $old_value Old option value
     * @return array Validated option value
     */
    public function validate_wallet_up_option($new_value, $old_value) {
        // Check if they're trying to enable the redirect
        if (isset($new_value['redirect_to_wallet_up']) && 
            $new_value['redirect_to_wallet_up'] && 
            (!isset($old_value['redirect_to_wallet_up']) || !$old_value['redirect_to_wallet_up'])) {
            
            // Verify that Wallet Up is available
            if (!$this->wallet_up_available) {
                // Force the option to false
                $new_value['redirect_to_wallet_up'] = false;
                
                // Add a transient to show a notice
                set_transient('wallet_up_login_wallet_up_not_available', true, 60);
            }
        }
        
        return $new_value;
    }
}

// Initialize the force redirect handler
add_action('plugins_loaded', function() {
    WalletUpForceRedirect::get_instance();
});