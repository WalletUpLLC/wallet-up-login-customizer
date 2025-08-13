<?php
/**
 * Wallet Up Dashboard Replacement
 * Handles redirecting and replacing the WordPress dashboard with Wallet Up
 * 
 * @package WalletUpLogin
 * @since 2.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dashboard replacement functionality
 */
class WalletUpHardRedirect {
    
    /**
     * Plugin options
     * @var array
     */
    private static $options = [];
    
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
        
        // Sanitize based on the variable type
        switch ($key) {
            case 'REQUEST_URI':
            case 'HTTP_REFERER':
            case 'SCRIPT_NAME':
                return esc_url_raw($value);
                
            case 'HTTP_HOST':
            case 'SERVER_NAME':
                // Validate host format
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
    
    /**
     * Initialize dashboard replacement functionality
     */
    public static function init() {
        // Defer initialization to 'admin_init' to ensure WordPress is fully loaded
        add_action('admin_init', [__CLASS__, 'delayed_init'], 5);
    }
    
    /**
     * Delayed initialization after WordPress is fully loaded
     */
    public static function delayed_init() {
        // Skip if doing AJAX, CRON, or CLI
        if (wp_doing_ajax() || wp_doing_cron() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        
        // Emergency bypass - add ?disable_wallet_up_redirect=1 to any admin URL
        // SECURITY: Validate and require admin capability
        $disable_redirect = isset($_GET['disable_wallet_up_redirect']) ? sanitize_text_field($_GET['disable_wallet_up_redirect']) : '';
        if ($disable_redirect === '1' && current_user_can('manage_options')) {
            return;
        }
        
        // Load options
        self::$options = get_option('wallet_up_login_options', []);
        
        // IMPORTANT: Only check if LOGIN redirect is enabled (Land to Wallet Up)
        // This feature works INDEPENDENTLY from Force Dashboard Replacement
        $redirect_enabled = !empty(self::$options['redirect_to_wallet_up']);
        
        if (!$redirect_enabled) {
            return; // Land to Wallet Up is disabled - do nothing
        }
        
        // Critical: Check if Wallet Up is available before activating
        if (!self::is_wallet_up_available()) {
            self::disable_wallet_up_redirect();
            return;
        }
        
        // Add hooks for login redirects ONLY
        self::add_hooks();
    }
    
    /**
     * Add hooks for login redirects only (Land to Wallet Up feature)
     */
    private static function add_hooks() {
        // ONLY handle login redirects - this is the "Land to Wallet Up" feature
        add_filter('login_redirect', [__CLASS__, 'handle_login_redirect'], 9999, 3);
        
        // REMOVED: Dashboard replacement hooks (now handled by main plugin class)
        // REMOVED: Menu modifications (now handled by main plugin class)
        // REMOVED: Admin bar modifications (now handled by main plugin class)
        // REMOVED: Dashboard widgets (now handled by main plugin class)
        
        // This class now ONLY handles login redirects
    }
    
    /**
     * Check if current user should be exempted from LOGIN redirects (Land to Wallet Up)
     * NOTE: This is separate from dashboard replacement exemption
     * 
     * @return bool True if current user should be exempted from login redirects
     */
    public static function should_exempt_current_user() {
        // IMPORTANT: This class only handles LOGIN redirects (Land to Wallet Up)
        // Dashboard replacement exemption is handled separately in the main plugin class
        
        // Get plugin options
        $exempt_admin_roles = !empty(self::$options['exempt_admin_roles']);
        
        // Check if current user is an administrator and admins are exempt from login redirects
        if ($exempt_admin_roles && current_user_can('administrator')) {
            return true;
        }
        
        // Allow custom capability to override login redirects
        if (current_user_can('access_original_dashboard')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if Wallet Up page exists and is available
     * 
     * @return bool True if Wallet Up page is available
     */
    public static function is_wallet_up_available() {
        // First, ensure plugin functions are available
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        // Method 1: Check if Wallet Up plugin is active
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
        
        // Method 2: Check if the admin page is registered
        global $_registered_pages;
        if (isset($_registered_pages['admin_page_wallet-up'])) {
            return true;
        }
        
        // Method 3: Check if the admin page hook exists
        if (function_exists('get_plugin_page_hook')) {
            $page_hook = get_plugin_page_hook('wallet-up', 'admin.php');
            if ($page_hook) {
                return true;
            }
        }
        
        // Method 4: Check admin menu for Wallet Up page (last resort)
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
        
        // Wallet Up page not found
        return false;
    }
    
    /**
     * Disable Wallet Up redirect setting if the page is not available
     */
    private static function disable_wallet_up_redirect() {
        // Log the error
        error_log('Wallet Up Login: Wallet Up page not found - Dashboard replacement disabled');
        
        // Disable the setting
        if (isset(self::$options['redirect_to_wallet_up'])) {
            self::$options['redirect_to_wallet_up'] = false;
            update_option('wallet_up_login_options', self::$options);
            
            // Add admin notice if in admin area
            if (is_admin()) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-warning is-dismissible">';
                    echo '<p>' . __('Wallet Up dashboard replacement has been disabled because the Wallet Up page was not found. Please make sure the Wallet Up plugin is active.', 'wallet-up-login') . '</p>';
                    echo '</div>';
                });
            }
        }
    }
    
    /**
     * Redirect when accessing the dashboard directly
     */
    public static function redirect_from_dashboard() {
        // Skip if explicitly requesting the original dashboard
        if (isset($_GET['show_wp_dashboard']) && $_GET['show_wp_dashboard'] == '1') {
            return;
        }
        
        // Skip for AJAX, CRON, CLI, and other special requests
        if (wp_doing_ajax() || wp_doing_cron() || 
            (defined('DOING_CRON') && DOING_CRON) || 
            (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        
        // Double-check user is logged in
        if (!is_user_logged_in()) {
            return;
        }
        
        // Double-check Wallet Up is still available before redirecting
        if (!self::is_wallet_up_available()) {
            self::disable_wallet_up_redirect();
            return;
        }
        
        // Verify the redirect URL is valid
        $wallet_up_url = self::get_wallet_up_url();
        if (empty($wallet_up_url) || !is_string($wallet_up_url)) {
            return;
        }
        
        // Redirect to Wallet Up page
        wp_safe_redirect($wallet_up_url);
        exit;
    }
    
    /**
     * Catch and redirect dashboard access
     */
    public static function catch_dashboard_access() {
        global $pagenow;
        
        // Skip for AJAX, CRON, and other special requests
        if (wp_doing_ajax() || wp_doing_cron() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }
        
        // Skip if explicitly requesting the original dashboard
        if (isset($_GET['show_wp_dashboard']) && $_GET['show_wp_dashboard'] == '1') {
            return;
        }
        
        // Check if on main dashboard page
        if ($pagenow === 'index.php' && !isset($_GET['page'])) {
            wp_safe_redirect(self::get_wallet_up_url());
            exit;
        }
        
        // Check for direct wp-admin access
        $request_uri = self::get_server_var('REQUEST_URI', '');
        if (preg_match('#^/wp-admin/?$#', $request_uri)) {
            wp_safe_redirect(self::get_wallet_up_url());
            exit;
        }
    }
    
    /**
     * Get the URL to the Wallet Up admin page
     * 
     * @return string Wallet Up admin URL
     */
    public static function get_wallet_up_url() {
        return admin_url('admin.php?page=wallet-up');
    }
    
    /**
     * Handle login redirects
     * 
     * @param string $redirect_to Default redirect URL
     * @param string $request Requested redirect URL
     * @param WP_User|WP_Error $user User object or error
     * @return string Modified redirect URL
     */
    public static function handle_login_redirect($redirect_to, $request, $user) {
        // Only redirect for successful logins
        if (!is_wp_error($user) && $user instanceof WP_User) {
            // Skip if exempt
            if (self::should_exempt_current_user()) {
                return $redirect_to;
            }
            
            // Redirect to Wallet Up
            return self::get_wallet_up_url();
        }
        
        return $redirect_to;
    }
    
    /**
     * Modify the admin menu to replace dashboard with Wallet Up
     * REFACTORED: Clean, professional implementation without duplicates
     */
    public static function modify_admin_menu() {
        global $menu;
        
        if (!is_array($menu)) {
            return;
        }
        
        // Find existing menu items
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
        
        // CLEAN APPROACH: Transform dashboard into Wallet Up, remove duplicates
        if ($dashboard_index !== null) {
            // Transform the dashboard menu item into Wallet Up
            $menu[$dashboard_index][0] = __('Wallet Up', 'wallet-up-login'); // Menu title  
            $menu[$dashboard_index][2] = 'admin.php?page=wallet-up'; // URL
            $menu[$dashboard_index][3] = __('Wallet Up', 'wallet-up-login'); // Page title
            $menu[$dashboard_index][4] = 'menu-top menu-icon-dashboard'; // Keep dashboard styling
            $menu[$dashboard_index][5] = 'wallet-up-dashboard'; // Unique ID
            $menu[$dashboard_index][6] = 'dashicons-bank'; // Keep dashboard icon
            
            // Remove ALL duplicate Wallet Up menus
            foreach ($wallet_up_indices as $index) {
                unset($menu[$index]);
            }
            
            // Clean up array gaps
            $menu = array_values($menu);
        }
        // Fallback: If no dashboard but Wallet Up exists, ensure consistent naming
        elseif (!empty($wallet_up_indices)) {
            // Keep only the first Wallet Up menu, remove others
            $keep_index = $wallet_up_indices[0];
            $menu[$keep_index][0] = __('Wallet Up', 'wallet-up-login');
            $menu[$keep_index][3] = __('Wallet Up', 'wallet-up-login');
            
            // Remove duplicate Wallet Up menus
            for ($i = 1; $i < count($wallet_up_indices); $i++) {
                unset($menu[$wallet_up_indices[$i]]);
            }
            
            $menu = array_values($menu);
        }
    }
    
    /**
     * Fix menu highlighting when on Wallet Up page
     */
    public static function fix_menu_highlighting() {
        global $parent_file, $submenu_file;
        
        // Only apply when on Wallet Up page
        if (isset($_GET['page']) && $_GET['page'] === 'wallet-up') {
            // Set dashboard as active parent
            $parent_file = 'index.php';
            
            // Add CSS to fix menu appearance
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
    
    /**
     * Modify admin bar to focus on Wallet Up
     * 
     * @param WP_Admin_Bar $wp_admin_bar Admin bar object
     */
    public static function modify_admin_bar($wp_admin_bar) {
        $wallet_up_url = self::get_wallet_up_url();
        
        // Update dashboard link
        $dashboard = $wp_admin_bar->get_node('dashboard');
        if ($dashboard) {
            $dashboard->href = $wallet_up_url;
            $wp_admin_bar->add_node($dashboard);
        } else {
            // Add dashboard node if it doesn't exist
            $wp_admin_bar->add_node([
                'id' => 'wallet-up-dashboard',
                'title' => __('Dashboard', 'wallet-up-login'),
                'href' => $wallet_up_url,
            ]);
        }
        
        // Update site name link for admins 
        $site_name = $wp_admin_bar->get_node('site-name');
        if ($site_name) {
            $site_name->href = $wallet_up_url;
            $wp_admin_bar->add_node($site_name);
        }
        
        // Add access to original dashboard for exempt users
        if (self::should_exempt_current_user()) {
            $wp_admin_bar->add_node([
                'id' => 'view-wp-dashboard',
                'title' => __('WordPress Dashboard', 'wallet-up-login'),
                'href' => add_query_arg('show_wp_dashboard', '1', admin_url()),
                'parent' => 'site-name',
            ]);
        }
    }
    
    /**
     * Customize dashboard widgets
     */
    public static function customize_dashboard_widgets() {
        global $wp_meta_boxes;
        
        // Clear all dashboard widgets
        if (isset($wp_meta_boxes['dashboard'])) {
            $wp_meta_boxes['dashboard'] = [];
        }
        
        // Add our redirect widget
        wp_add_dashboard_widget(
            'wallet_up_dashboard_widget',
            __('Wallet Up Dashboard', 'wallet-up-login'),
            [__CLASS__, 'dashboard_redirect_widget']
        );
    }
    
    /**
     * Dashboard widget content with redirect
     */
    public static function dashboard_redirect_widget() {
        $wallet_up_url = self::get_wallet_up_url();
        
        echo '<p>' . __('The WordPress dashboard has been replaced with the Wallet Up Dashboard.', 'wallet-up-login') . '</p>';
        echo '<p><a href="' . esc_url($wallet_up_url) . '" class="button button-primary">' . 
             __('Go to Wallet Up Dashboard', 'wallet-up-login') . '</a></p>';
        
        // Auto-redirect script with fade effect
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
    
    /**
     * Custom welcome panel
     */
    public static function custom_welcome_panel() {
        $wallet_up_url = self::get_wallet_up_url();
        
        ?>
        <div class="welcome-panel-content">
            <h2><?php _e('Welcome to Wallet Up!', 'wallet-up-login'); ?></h2>
            <p class="about-description"><?php _e('The WordPress dashboard has been replaced with the Wallet Up Dashboard.', 'wallet-up-login'); ?></p>
            <p><a href="<?php echo esc_url($wallet_up_url); ?>" class="button button-primary button-hero">
                <?php _e('Go to Wallet Up Dashboard', 'wallet-up-login'); ?>
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
    
    /**
     * Modify admin title
     * 
     * @param string $admin_title Admin title
     * @param string $title Original title
     * @return string Modified title
     */
    public static function modify_admin_title($admin_title, $title) {
        // Change dashboard title
        if ($title === __('Dashboard')) {
            return __('Wallet Up Dashboard', 'wallet-up-login') . ' ' . $admin_title;
        }
        
        return $admin_title;
    }
}