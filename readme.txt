=== Wallet Up Login Customizer ===
Contributors: walletup
Tags: QR login, security, authentication, customization, enterprise wp login
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.3.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise-grade login security and customization for WordPress with beautiful UI, advanced protection, and seamless Wallet Up integration.

== Description ==

**Wallet Up Premium Login Customizer** transforms your WordPress login page into a modern, secure, and beautiful authentication portal. Built with enterprise security standards and featuring a stunning interactive design, this plugin provides comprehensive protection while delivering an exceptional user experience.

= 🎨 Beautiful Login Interface =
* Modern gradient design with customizable colors
* Smooth animations and interactive elements
* AJAX-powered login without page refresh
* Custom logo support with smart sizing
* Mobile-responsive design
* Loading animations with customizable messages

= 🔒 Enterprise Security Features =
* **Rate Limiting**: Intelligent brute-force protection with memory-efficient implementation
* **Login Attempt Monitoring**: Track and block suspicious activity
* **Custom Login URL**: Hide wp-login.php from attackers
* **Security Headers**: XSS, clickjacking, and MIME-type protection
* **Session Management**: Automatic timeout via WordPress auth cookies
* **Honeypot Protection**: Invisible bot detection
* **Force Login**: Require authentication for entire site
* **Account Lockout**: Temporary lockout after failed attempts

= 🚀 Advanced Features =
* **Dashboard Replacement**: Redirect users to Wallet Up dashboard
* **Role-Based Exemptions**: Exclude administrators from restrictions
* **Conflict Detection**: Automatic detection of incompatible plugins
* **Emergency Recovery**: Built-in recovery mode for troubleshooting
* **Admin Synchronization**: Real-time settings sync across components
* **Safe Activation**: Gradual feature enablement with rollback

= 🛡️ Security Enhancements =
* Prevention of user enumeration
* Protection against timing attacks
* Sanitization of all error messages
* CSRF protection on all forms
* Secure AJAX communication
* Input validation and sanitization (60-character username limit)
* Memory-optimized rate limiting
* Global rate limiting to prevent botnet attacks

= 🔧 Developer Features =
* Extensive hook system for customization
* Clean, documented codebase
* PSR-4 compliant architecture
* Comprehensive error handling
* Debug mode for development
* Translation-ready with full i18n support

== Installation ==

= Automatic Installation =
1. Navigate to **Plugins > Add New** in your WordPress admin
2. Search for "Wallet Up Premium Login"
3. Click **Install Now** and then **Activate**
4. Configure settings at **Settings > Wallet Up Login**

= Manual Installation =
1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/` directory
3. Extract the ZIP file
4. Activate through the **Plugins** menu in WordPress
5. Configure at **Settings > Wallet Up Login**

= Post-Installation =
1. Visit **Settings > Wallet Up Login** to customize appearance
2. Enable security features gradually for testing
3. Configure logo and branding options
4. Test login functionality before enabling forced login

== Frequently Asked Questions ==

= How do I recover if I'm locked out? =

**Method 1: Emergency Disable**
Add this to wp-config.php:
`define('WALLET_UP_EMERGENCY_DISABLE', true);`

**Method 2: URL Parameter**
Add `?disable_wallet_up_redirect=1` to any admin URL (requires admin capability)

**Method 3: Safe Mode**
Access `/wp-admin/?show_wp_dashboard=1` to bypass redirects

= Can I customize the login page appearance? =

Yes! The plugin offers extensive customization:
* Custom logo upload or URL
* Color scheme selection
* Gradient customization
* Loading message configuration
* Animation speed controls

= Is this compatible with other security plugins? =

The plugin includes automatic conflict detection and will notify you of incompatibilities. It works well with most security plugins but may conflict with other login customizers.

= How do I hide wp-login.php? =

1. Go to **Settings > Wallet Up Login > Security**
2. Enable "Hide wp-login.php"
3. Set your custom login slug (e.g., "secure-login")
4. Your login URL becomes: `yoursite.com/?secure-login=1`

= Can I exempt certain user roles? =

Yes, administrators are automatically exempted from:
* Dashboard replacement
* Forced login requirements
* Login redirects

This is configured by default for safety.

= Does this work with multisite? =

Yes, with considerations:
* Network activate for all sites
* Configure per-site settings individually
* Super admins are automatically exempted

== Screenshots ==

1. Beautiful modern login interface with gradient design
2. Customizable loading animations during authentication
3. Comprehensive admin settings panel with live preview
4. Security dashboard showing login attempts and monitoring
5. Mobile-responsive login experience
6. Custom branding with logo management
7. Advanced security configuration options
8. Conflict detection and resolution interface

== Changelog ==

= 2.3.8 (August 21, 2025) =
* **New**: Email notification system with configurable alerts and daily digest option
* **New**: Test email functionality for notification verification
* **Enhancement**: Improved settings export/import functionality
* **Enhancement**: Better compatibility with caching plugins (CloudFlare, LiteSpeed)
* **Fix**: Improved settings persistence and database handling
* **Fix**: Enhanced compatibility between custom URLs and security headers
* **UI**: Refined settings interface and user experience

= 2.3.7 (August 20, 2025) =
* **Security**: Enhanced login protection with intelligent rate limiting
* **Security**: Improved user privacy protection across all endpoints
* **Security**: Strengthened input validation and sanitization
* **Security**: Added Content Security Policy (CSP) headers
* **Enhancement**: Better detection of proxy and CDN configurations
* **Enhancement**: Improved logging system with privacy controls
* **Performance**: Optimized security checks for better performance

= 2.3.6 (August 19, 2025) =
* **Security**: Enhanced privacy protection for user data
* **New**: Daily digest option for security notifications
* **New**: Automated bot detection and prevention
* **Enhancement**: Improved REST API security
* **Enhancement**: Better handling of security events
* **Performance**: Optimized email notification system

= 2.3.5 (August 13, 2025) =
* **New**: Multi-language support for 11 languages (Spanish, French, German, Chinese, Hebrew, Arabic, Japanese, Korean, Italian, Portuguese)
* **Enhancement**: Complete internationalization (i18n) support
* **Enhancement**: Improved form protection and validation
* **Performance**: Optimized rate limiting system
* **Fix**: Enhanced translation string handling

= 2.3.0 (July 28, 2025) =
* **New**: Enterprise Security Module
* **New**: Force login functionality for site protection
* **New**: Custom login URL feature
* **New**: Session management system
* **Enhancement**: Improved plugin conflict detection
* **Fix**: Resolved redirect loop issues

= 2.2.5 (July 15, 2025) =
* **New**: Security headers for enhanced protection
* **New**: Honeypot bot detection
* **Enhancement**: Better caching plugin compatibility
* **Performance**: 40% reduction in database queries

= 2.2.0 (June 30, 2025) =
* **New**: Dashboard replacement feature with Wallet Up integration
* **New**: Role-based exemption system
* **New**: Emergency recovery mode
* **Enhancement**: Improved AJAX login reliability
* **Enhancement**: Better mobile responsive design
* **Fix**: Resolved conflicts with popular security plugins

= 2.1.0 (June 1, 2025) =
* **New**: Advanced admin synchronization system
* **New**: Safe activation with gradual feature rollout
* **New**: Comprehensive settings import/export
* **Enhancement**: Improved logo management with smart sizing
* **Fix**: Better handling of custom logo URLs
* **Update**: Refreshed UI with modern design patterns

= 2.0.0 (May 1, 2025) =
* **Major Update**: Complete codebase refactor
* **New**: Modular PSR-4 architecture
* **New**: Conflict detection system
* **New**: Debugging tools
* **Performance**: 50% speed improvement

= 1.5.0 (April 1, 2025) =
* **New**: AJAX-powered login without page refresh
* **New**: Customizable loading messages
* **New**: Animation speed controls
* **Enhancement**: Improved form validation
* **Fix**: Better error handling and display

= 1.4.0 (March 15, 2025) =
* **New**: Custom CSS injection support
* **New**: Advanced color customization
* **Enhancement**: Better gradient rendering
* **Fix**: Improved cross-browser compatibility

= 1.3.0 (March 1, 2025) =
* **New**: Login protection features
* **New**: Login attempt monitoring
* **Enhancement**: Improved password reset flow

= 1.2.0 (February 15, 2025) =
* **New**: Mobile-first responsive design
* **Enhancement**: Improved accessibility (WCAG 2.1 AA)
* **Fix**: Better RTL language support
* **Update**: Modernized JavaScript framework

= 1.1.0 (January 15, 2025) =
* **New**: Custom logo support
* **New**: Remember me option
* **Enhancement**: Improved animations
* **Enhancement**: Better plugin compatibility

= 1.0.0 (January 1, 2025) =
* **Initial Release**
* Login page customization
* Security features
* Wallet Up integration
* Admin settings panel

== Upgrade Notice ==

= 2.3.8 =
Enhanced email notifications and improved compatibility with caching plugins. Recommended update for all users.

= 2.3.7 =
Security enhancements and performance improvements. Recommended update for improved protection.

= 2.3.0 =
Enterprise features and security enhancements. Backup recommended.

= 2.0.0 =
Major architectural improvements. Please review settings after upgrade.

== System Requirements ==

* WordPress 5.6 or higher
* PHP 7.4 or higher
* MySQL 5.7 or higher / MariaDB 10.2 or higher
* HTTPS recommended for security features
* 10MB available disk space
* Modern browser for admin interface

== Privacy Policy ==

This plugin:
* Does not collect personal data
* Does not communicate with external services
* Stores login attempts locally in database
* Does not use browser localStorage or sessionStorage
* All data remains on your server

== Support ==

* **Documentation**: https://walletup.app/docs/login-plugin
* **Support Forum**: WordPress.org Support | https://wordpress.org/support/plugin/wallet-up-login-customizer/
* **GitHub**: https://github.com/WalletUpLLC/wallet-up-login-customizer
* **Email**: help@walletup.app

== Credits ==

Developed by the Wallet Up team.

Special thanks to:
* Security agents for vulnerability reports
* Beta testers for extensive testing
* Translators for internationalization
* Inputs contributors

== License ==

This plugin is licensed under GPL v2 or later.

Copyright (C) 2025 Wallet Up