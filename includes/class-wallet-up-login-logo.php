<?php
/**
 * Wallet Up Login Logo Management
 * 
 * Handles custom logo display on login page with proper sizing
 * and internationalization support
 * 
 * @package WalletUpLogin
 * @since 2.3.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WalletUpLoginLogo {
    
    /**
     * Logo options
     */
    private static $options = [];
    
    /**
     * Initialize logo management
     */
    public static function init() {
        // Check the main settings first for custom logo
        $main_settings = get_option('wallet_up_login_settings', []);
        $custom_logo_url = isset($main_settings['custom_logo']) ? $main_settings['custom_logo'] : '';
        
        // Load options - use main settings custom_logo if available
        self::$options = get_option('wallet_up_logo_settings', [
            'enabled' => !empty($custom_logo_url),
            'logo_url' => $custom_logo_url,
            'logo_width' => 84,
            'logo_height' => 84,
            'max_height' => 50,
            'preserve_ratio' => true,
            'link_url' => home_url(),
            'link_title' => get_bloginfo('name')
        ]);
        
        // If custom_logo is set in main settings, use it
        if (!empty($custom_logo_url)) {
            self::$options['logo_url'] = $custom_logo_url;
            self::$options['enabled'] = true;
        }
        
        // Add hooks
        add_action('login_enqueue_scripts', [__CLASS__, 'custom_login_logo']);
        add_filter('login_headerurl', [__CLASS__, 'login_logo_url']);
        add_filter('login_headertext', [__CLASS__, 'login_logo_title']);
        // REMOVED: add_action('admin_menu', ...) to prevent duplicate menus
        // Logo settings are now integrated in the main admin page's Appearance tab
    }
    
    /**
     * Custom login logo CSS
     */
    public static function custom_login_logo() {
        if (!self::$options['enabled']) {
            return;
        }
        
        $logo_url = self::$options['logo_url'];
        
        // Use site logo if no custom logo set
        if (empty($logo_url)) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
                if ($logo_data) {
                    $logo_url = $logo_data[0];
                }
            }
        }
        
        // Still no logo? Use default WordPress logo
        if (empty($logo_url)) {
            return;
        }
        
        // Calculate dimensions preserving aspect ratio
        $max_height = intval(self::$options['max_height']);
        $width = intval(self::$options['logo_width']);
        $height = intval(self::$options['logo_height']);
        
        if (self::$options['preserve_ratio'] && $height > $max_height) {
            // Scale down proportionally
            $ratio = $max_height / $height;
            $width = round($width * $ratio);
            $height = $max_height;
        } elseif ($height > $max_height) {
            // Force max height
            $height = $max_height;
        }
        
        ?>
        <style type="text/css">
            #login h1 a, .login h1 a {
                background-image: url(<?php echo esc_url($logo_url); ?>);
                width: <?php echo esc_attr($width); ?>px;
                height: <?php echo esc_attr($height); ?>px;
                background-size: contain;
                background-repeat: no-repeat;
                background-position: center center;
                padding-bottom: 10px;
            }
        </style>
        <?php
    }
    
    /**
     * Change logo link URL
     */
    public static function login_logo_url() {
        return esc_url(self::$options['link_url']);
    }
    
    /**
     * Change logo link title
     */
    public static function login_logo_title() {
        return esc_attr(self::$options['link_title']);
    }
    
    /**
     * Add logo settings page to admin menu
     */
    public static function add_logo_settings_page() {
        add_submenu_page(
            'wallet-up-login',
            __('Logo Settings', 'wallet-up-login'),
            __('Logo Settings', 'wallet-up-login'),
            'manage_options',
            'wallet-up-logo',
            [__CLASS__, 'render_logo_settings_page']
        );
    }
    
    /**
     * Register logo settings
     */
    public static function register_logo_settings() {
        register_setting('wallet_up_logo_settings', 'wallet_up_logo_settings', [
            'sanitize_callback' => [__CLASS__, 'sanitize_logo_settings']
        ]);
        
        add_settings_section(
            'wallet_up_logo_main',
            __('Login Logo Configuration', 'wallet-up-login'),
            [__CLASS__, 'logo_section_callback'],
            'wallet_up_logo_settings'
        );
        
        // Enable/Disable
        add_settings_field(
            'enabled',
            __('Enable Custom Logo', 'wallet-up-login'),
            [__CLASS__, 'render_enabled_field'],
            'wallet_up_logo_settings',
            'wallet_up_logo_main'
        );
        
        // Logo URL
        add_settings_field(
            'logo_url',
            __('Logo Image', 'wallet-up-login'),
            [__CLASS__, 'render_logo_url_field'],
            'wallet_up_logo_settings',
            'wallet_up_logo_main'
        );
        
        // Size settings
        add_settings_field(
            'size_settings',
            __('Logo Size', 'wallet-up-login'),
            [__CLASS__, 'render_size_fields'],
            'wallet_up_logo_settings',
            'wallet_up_logo_main'
        );
        
        // Link settings
        add_settings_field(
            'link_settings',
            __('Logo Link', 'wallet-up-login'),
            [__CLASS__, 'render_link_fields'],
            'wallet_up_logo_settings',
            'wallet_up_logo_main'
        );
    }
    
    /**
     * Render logo settings page
     */
    public static function render_logo_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if settings were saved
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'wallet_up_logo_messages',
                'wallet_up_logo_message',
                __('Logo settings saved successfully.', 'wallet-up-login'),
                'updated'
            );
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('wallet_up_logo_messages'); ?>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('wallet_up_logo_settings');
                do_settings_sections('wallet_up_logo_settings');
                submit_button(__('Save Logo Settings', 'wallet-up-login'));
                ?>
            </form>
            
            <div class="wallet-up-logo-preview">
                <h2><?php esc_html_e('Current Logo Preview', 'wallet-up-login'); ?></h2>
                <div style="background: #f0f0f1; padding: 20px; border-radius: 5px; max-width: 300px;">
                    <?php if (!empty(self::$options['logo_url'])): ?>
                        <img src="<?php echo esc_url(self::$options['logo_url']); ?>" 
                             style="max-height: <?php echo esc_attr(self::$options['max_height']); ?>px; width: auto; display: block; margin: 0 auto;" 
                             alt="<?php esc_attr_e('Login Logo Preview', 'wallet-up-login'); ?>">
                    <?php else: ?>
                        <p><?php esc_html_e('No custom logo set. Using site logo or WordPress default.', 'wallet-up-login'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Section callback
     */
    public static function logo_section_callback() {
        echo '<p>' . esc_html__('Configure the logo that appears on the WordPress login page. The logo will be automatically sized to fit within 50px height while preserving aspect ratio.', 'wallet-up-login') . '</p>';
    }
    
    /**
     * Render enabled field
     */
    public static function render_enabled_field() {
        $enabled = isset(self::$options['enabled']) ? self::$options['enabled'] : true;
        ?>
        <label>
            <input type="checkbox" name="wallet_up_logo_settings[enabled]" value="1" <?php checked($enabled, true); ?>>
            <?php esc_html_e('Display custom logo on login page', 'wallet-up-login'); ?>
        </label>
        <?php
    }
    
    /**
     * Render logo URL field with media uploader
     */
    public static function render_logo_url_field() {
        $logo_url = isset(self::$options['logo_url']) ? self::$options['logo_url'] : '';
        ?>
        <input type="url" name="wallet_up_logo_settings[logo_url]" id="wallet_up_logo_url" 
               value="<?php echo esc_url($logo_url); ?>" class="regular-text">
        <button type="button" class="button" id="wallet_up_logo_upload">
            <?php esc_html_e('Select Logo', 'wallet-up-login'); ?>
        </button>
        <p class="description">
            <?php esc_html_e('Upload or select a logo image. Recommended size: 200x50px or smaller.', 'wallet-up-login'); ?>
        </p>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wallet_up_logo_upload').click(function(e) {
                e.preventDefault();
                var image = wp.media({
                    title: '<?php echo esc_js(__('Select Logo', 'wallet-up-login')); ?>',
                    multiple: false,
                    library: {
                        type: 'image'
                    },
                    button: {
                        text: '<?php echo esc_js(__('Use as Logo', 'wallet-up-login')); ?>'
                    }
                }).open().on('select', function() {
                    var uploaded_image = image.state().get('selection').first();
                    var image_url = uploaded_image.toJSON().url;
                    $('#wallet_up_logo_url').val(image_url);
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render size fields
     */
    public static function render_size_fields() {
        $max_height = isset(self::$options['max_height']) ? self::$options['max_height'] : 50;
        $preserve_ratio = isset(self::$options['preserve_ratio']) ? self::$options['preserve_ratio'] : true;
        ?>
        <label>
            <?php esc_html_e('Maximum Height:', 'wallet-up-login'); ?>
            <input type="number" name="wallet_up_logo_settings[max_height]" 
                   value="<?php echo esc_attr($max_height); ?>" min="20" max="100" step="1">
            <span class="description">px (<?php esc_html_e('default: 50px', 'wallet-up-login'); ?>)</span>
        </label>
        <br><br>
        <label>
            <input type="checkbox" name="wallet_up_logo_settings[preserve_ratio]" 
                   value="1" <?php checked($preserve_ratio, true); ?>>
            <?php esc_html_e('Preserve aspect ratio when resizing', 'wallet-up-login'); ?>
        </label>
        <?php
    }
    
    /**
     * Render link fields
     */
    public static function render_link_fields() {
        $link_url = isset(self::$options['link_url']) ? self::$options['link_url'] : home_url();
        $link_title = isset(self::$options['link_title']) ? self::$options['link_title'] : get_bloginfo('name');
        ?>
        <label>
            <?php esc_html_e('Link URL:', 'wallet-up-login'); ?>
            <input type="url" name="wallet_up_logo_settings[link_url]" 
                   value="<?php echo esc_url($link_url); ?>" class="regular-text">
        </label>
        <br><br>
        <label>
            <?php esc_html_e('Link Title:', 'wallet-up-login'); ?>
            <input type="text" name="wallet_up_logo_settings[link_title]" 
                   value="<?php echo esc_attr($link_title); ?>" class="regular-text">
        </label>
        <p class="description">
            <?php esc_html_e('Where the logo links to and the tooltip text when hovering.', 'wallet-up-login'); ?>
        </p>
        <?php
    }
    
    /**
     * Sanitize logo settings
     */
    public static function sanitize_logo_settings($input) {
        $sanitized = [];
        
        $sanitized['enabled'] = !empty($input['enabled']);
        $sanitized['logo_url'] = esc_url_raw($input['logo_url'] ?? '');
        $sanitized['max_height'] = absint($input['max_height'] ?? 50);
        $sanitized['preserve_ratio'] = !empty($input['preserve_ratio']);
        $sanitized['link_url'] = esc_url_raw($input['link_url'] ?? home_url());
        $sanitized['link_title'] = sanitize_text_field($input['link_title'] ?? get_bloginfo('name'));
        
        // Get image dimensions if URL provided
        if (!empty($sanitized['logo_url'])) {
            $attachment_id = attachment_url_to_postid($sanitized['logo_url']);
            if ($attachment_id) {
                $metadata = wp_get_attachment_metadata($attachment_id);
                if ($metadata) {
                    $sanitized['logo_width'] = $metadata['width'];
                    $sanitized['logo_height'] = $metadata['height'];
                }
            }
        }
        
        // Ensure max height is within reasonable bounds
        if ($sanitized['max_height'] < 20) {
            $sanitized['max_height'] = 20;
        } elseif ($sanitized['max_height'] > 100) {
            $sanitized['max_height'] = 100;
        }
        
        return $sanitized;
    }
    
    /**
     * Show admin notice for logo setup
     */
    public static function logo_setup_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Only show on dashboard and our plugin pages
        $screen = get_current_screen();
        if (!in_array($screen->id, ['dashboard', 'toplevel_page_wallet-up-login', 'wallet-up-login_page_wallet-up-logo'])) {
            return;
        }
        
        // Check if logo is configured
        $options = get_option('wallet_up_logo_settings', []);
        if (empty($options['logo_url'])) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php 
                    printf(
                        /* translators: %s: Logo settings page URL */
                        esc_html__('Wallet Up Login: No custom logo configured. %s to add your brand logo to the login page.', 'wallet-up-login'),
                        '<a href="' . esc_url(admin_url('admin.php?page=wallet-up-logo')) . '">' . esc_html__('Configure logo', 'wallet-up-login') . '</a>'
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }
}

// Initialize logo management
add_action('init', ['WalletUpLoginLogo', 'init']);