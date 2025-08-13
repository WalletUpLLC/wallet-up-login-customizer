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

if (!defined('ABSPATH')) {
    exit;
}

class WalletUpLoginLogo {

    private static $options = [];

    public static function init() {
        
        $main_settings = get_option('wallet_up_login_customizer_settings', []);
        $custom_logo_url = isset($main_settings['custom_logo']) ? $main_settings['custom_logo'] : '';

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

        if (!empty($custom_logo_url)) {
            self::$options['logo_url'] = $custom_logo_url;
            self::$options['enabled'] = true;
        }

        add_action('login_enqueue_scripts', [__CLASS__, 'custom_login_logo']);
        add_filter('login_headerurl', [__CLASS__, 'login_logo_url']);
        add_filter('login_headertext', [__CLASS__, 'login_logo_title']);

    }

    public static function custom_login_logo() {
        if (!self::$options['enabled']) {
            return;
        }
        
        $logo_url = self::$options['logo_url'];

        if (empty($logo_url)) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
                if ($logo_data) {
                    $logo_url = $logo_data[0];
                }
            }
        }

        if (empty($logo_url)) {
            return;
        }

        $max_height = intval(self::$options['max_height']);
        $width = intval(self::$options['logo_width']);
        $height = intval(self::$options['logo_height']);
        
        if (self::$options['preserve_ratio'] && $height > $max_height) {
            
            $ratio = $max_height / $height;
            $width = round($width * $ratio);
            $height = $max_height;
        } elseif ($height > $max_height) {
            
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

    public static function login_logo_url() {
        return esc_url(self::$options['link_url']);
    }

    public static function login_logo_title() {
        return esc_attr(self::$options['link_title']);
    }

    public static function add_logo_settings_page() {
        add_submenu_page(
            'wallet-up-login-customizer',
            __('Logo Settings', 'wallet-up-login-customizer'),
            __('Logo Settings', 'wallet-up-login-customizer'),
            'manage_options',
            'wallet-up-logo',
            [__CLASS__, 'render_logo_settings_page']
        );
    }

    public static function register_logo_settings() {
        register_setting('wallet_up_logo_settings', 'wallet_up_logo_settings', [
            'sanitize_callback' => [__CLASS__, 'sanitize_logo_settings']
        ]);
        
        add_settings_section(
            'wallet_up_logo_main',
            __('Login Logo Configuration', 'wallet-up-login-customizer'),
            [__CLASS__, 'logo_section_callback'],
            'wallet_up_logo_settings'
        );

        add_settings_field(
            'enabled',
            __('Enable Custom Logo', 'wallet-up-login-customizer'),
            [__CLASS__, 'render_enabled_field'],
            'wallet_up_logo_settings',
            'wallet_up_logo_main'
        );

        add_settings_field(
            'logo_url',
            __('Logo Image', 'wallet-up-login-customizer'),
            [__CLASS__, 'render_logo_url_field'],
            'wallet_up_logo_settings',
            'wallet_up_logo_main'
        );

        add_settings_field(
            'size_settings',
            __('Logo Size', 'wallet-up-login-customizer'),
            [__CLASS__, 'render_size_fields'],
            'wallet_up_logo_settings',
            'wallet_up_logo_main'
        );

        add_settings_field(
            'link_settings',
            __('Logo Link', 'wallet-up-login-customizer'),
            [__CLASS__, 'render_link_fields'],
            'wallet_up_logo_settings',
            'wallet_up_logo_main'
        );
    }

    public static function render_logo_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'wallet_up_logo_messages',
                'wallet_up_logo_message',
                __('Logo settings saved successfully.', 'wallet-up-login-customizer'),
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
                submit_button(__('Save Logo Settings', 'wallet-up-login-customizer'));
                ?>
            </form>
            
            <div class="wallet-up-logo-preview">
                <h2><?php esc_html_e('Current Logo Preview', 'wallet-up-login-customizer'); ?></h2>
                <div style="background: #f0f0f1; padding: 20px; border-radius: 5px; max-width: 300px;">
                    <?php if (!empty(self::$options['logo_url'])): ?>
                        <img src="<?php echo esc_url(self::$options['logo_url']); ?>" 
                             style="max-height: <?php echo esc_attr(self::$options['max_height']); ?>px; width: auto; display: block; margin: 0 auto;" 
                             alt="<?php esc_attr_e('Login Logo Preview', 'wallet-up-login-customizer'); ?>">
                    <?php else: ?>
                        <p><?php esc_html_e('No custom logo set. Using site logo or WordPress default.', 'wallet-up-login-customizer'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function logo_section_callback() {
        echo '<p>' . esc_html__('Configure the logo that appears on the WordPress login page. The logo will be automatically sized to fit within 50px height while preserving aspect ratio.', 'wallet-up-login-customizer') . '</p>';
    }

    public static function render_enabled_field() {
        $enabled = isset(self::$options['enabled']) ? self::$options['enabled'] : true;
        ?>
        <label>
            <input type="checkbox" name="wallet_up_logo_settings[enabled]" value="1" <?php checked($enabled, true); ?>>
            <?php esc_html_e('Display custom logo on login page', 'wallet-up-login-customizer'); ?>
        </label>
        <?php
    }

    public static function render_logo_url_field() {
        $logo_url = isset(self::$options['logo_url']) ? self::$options['logo_url'] : '';
        ?>
        <input type="url" name="wallet_up_logo_settings[logo_url]" id="wallet_up_logo_url" 
               value="<?php echo esc_url($logo_url); ?>" class="regular-text">
        <button type="button" class="button" id="wallet_up_logo_upload">
            <?php esc_html_e('Select Logo', 'wallet-up-login-customizer'); ?>
        </button>
        <p class="description">
            <?php esc_html_e('Upload or select a logo image. Recommended size: 200x50px or smaller.', 'wallet-up-login-customizer'); ?>
        </p>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wallet_up_logo_upload').click(function(e) {
                e.preventDefault();
                var image = wp.media({
                    title: '<?php echo esc_js(__('Select Logo', 'wallet-up-login-customizer')); ?>',
                    multiple: false,
                    library: {
                        type: 'image'
                    },
                    button: {
                        text: '<?php echo esc_js(__('Use as Logo', 'wallet-up-login-customizer')); ?>'
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

    public static function render_size_fields() {
        $max_height = isset(self::$options['max_height']) ? self::$options['max_height'] : 50;
        $preserve_ratio = isset(self::$options['preserve_ratio']) ? self::$options['preserve_ratio'] : true;
        ?>
        <label>
            <?php esc_html_e('Maximum Height:', 'wallet-up-login-customizer'); ?>
            <input type="number" name="wallet_up_logo_settings[max_height]" 
                   value="<?php echo esc_attr($max_height); ?>" min="20" max="100" step="1">
            <span class="description">px (<?php esc_html_e('default: 50px', 'wallet-up-login-customizer'); ?>)</span>
        </label>
        <br><br>
        <label>
            <input type="checkbox" name="wallet_up_logo_settings[preserve_ratio]" 
                   value="1" <?php checked($preserve_ratio, true); ?>>
            <?php esc_html_e('Preserve aspect ratio when resizing', 'wallet-up-login-customizer'); ?>
        </label>
        <?php
    }

    public static function render_link_fields() {
        $link_url = isset(self::$options['link_url']) ? self::$options['link_url'] : home_url();
        $link_title = isset(self::$options['link_title']) ? self::$options['link_title'] : get_bloginfo('name');
        ?>
        <label>
            <?php esc_html_e('Link URL:', 'wallet-up-login-customizer'); ?>
            <input type="url" name="wallet_up_logo_settings[link_url]" 
                   value="<?php echo esc_url($link_url); ?>" class="regular-text">
        </label>
        <br><br>
        <label>
            <?php esc_html_e('Link Title:', 'wallet-up-login-customizer'); ?>
            <input type="text" name="wallet_up_logo_settings[link_title]" 
                   value="<?php echo esc_attr($link_title); ?>" class="regular-text">
        </label>
        <p class="description">
            <?php esc_html_e('Where the logo links to and the tooltip text when hovering.', 'wallet-up-login-customizer'); ?>
        </p>
        <?php
    }

    public static function sanitize_logo_settings($input) {
        $sanitized = [];
        
        $sanitized['enabled'] = !empty($input['enabled']);
        $sanitized['logo_url'] = esc_url_raw($input['logo_url'] ?? '');
        $sanitized['max_height'] = absint($input['max_height'] ?? 50);
        $sanitized['preserve_ratio'] = !empty($input['preserve_ratio']);
        $sanitized['link_url'] = esc_url_raw($input['link_url'] ?? home_url());
        $sanitized['link_title'] = sanitize_text_field($input['link_title'] ?? get_bloginfo('name'));

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

        if ($sanitized['max_height'] < 20) {
            $sanitized['max_height'] = 20;
        } elseif ($sanitized['max_height'] > 100) {
            $sanitized['max_height'] = 100;
        }
        
        return $sanitized;
    }

    public static function logo_setup_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if (!in_array($screen->id, ['dashboard', 'toplevel_page_wallet-up-login-customizer', 'wallet-up-login_page_wallet-up-logo'])) {
            return;
        }

        $options = get_option('wallet_up_logo_settings', []);
        if (empty($options['logo_url'])) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php 
                    printf(
                        
                        esc_html__('Wallet Up Login: No custom logo configured. %s to add your brand logo to the login page.', 'wallet-up-login-customizer'),
                        '<a href="' . esc_url(admin_url('admin.php?page=wallet-up-logo')) . '">' . esc_html__('Configure logo', 'wallet-up-login-customizer') . '</a>'
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }
}

add_action('init', ['WalletUpLoginLogo', 'init']);