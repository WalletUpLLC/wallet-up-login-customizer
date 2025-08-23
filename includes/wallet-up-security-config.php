<?php
/**
 * Security Configuration Summary
 * 
 * This file documents the security improvements implemented to prevent
 * username enumeration, email spam, and brute force attacks.
 *
 * @package WalletUpLogin
 * @since 2.3.6
 */

// Security Features Implemented:

/**
 * 1. USERNAME ENUMERATION PROTECTION
 * - Removed public AJAX username validation endpoint
 * - Disabled author archives (?author=1)
 * - Removed author info from OEmbed responses
 * - Protected REST API user endpoints
 * - Removed usernames from RSS feeds
 * 
 * 2. EMAIL RATE LIMITING
 * - Maximum 1 email per hour per IP/username combination
 * - Optional daily digest mode
 * - Obfuscated usernames in email alerts
 * 
 * 3. INTELLIGENT BOT DETECTION
 * - Automatic blocking of cloud provider IPs (Google Cloud, AWS, Azure)
 * - User agent pattern matching for known bots
 * - Rapid-fire attempt detection (>10 attempts per minute)
 * - 24-hour automatic IP blocking
 * 
 * 4. ENHANCED LOGGING
 * - Usernames are hashed in logs (not stored in plain text)
 * - Only username prefix (first 2 chars) + hash stored
 * - Prevents exposure of actual usernames in logs
 * 
 * 5. ADDITIONAL PROTECTIONS
 * - .htaccess automatic IP blocking
 * - Honeypot field detection
 * - Session security improvements
 * - CSP headers with nonce support
 */

// To disable specific features, use these constants in wp-config.php:
// define('WALLET_UP_DISABLE_ENUMERATION_PROTECTION', true);
// define('WALLET_UP_DISABLE_EMAIL_RATE_LIMIT', true);
// define('WALLET_UP_DISABLE_BOT_DETECTION', true);

// For emergency disable of all security features:
// define('WALLET_UP_EMERGENCY_DISABLE', true);