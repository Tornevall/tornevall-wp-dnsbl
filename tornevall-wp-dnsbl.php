<?php
/*
 * Plugin Name: Tornevall Networks DNSBL Implementation
 * Plugin URI: https://wordpress.org/plugins/tornevall-networks-dnsbl-implementation/
 * Project URI: https://github.com/Tornevall/tornevall-wp-dnsbl
 * Description: DNSBL and FraudBL protection for comments and WordPress registrations, with Cloudflare Turnstile support, whitelist-based dry runs, and admin-safe blocking controls.
 * Version: 3.1.6
 * Author: Thomas Tornevall
 * Author URI: https://www.tornevalls.se/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tornevall-networks-dnsbl-implementation
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TORNEVALL_DNSBL_PLUGIN_FILE', __FILE__);
define('TORNEVALL_DNSBL_PLUGIN_DIR', plugin_dir_path(TORNEVALL_DNSBL_PLUGIN_FILE));
define('TORNEVALL_DNSBL_PLUGIN_URL', plugin_dir_url(TORNEVALL_DNSBL_PLUGIN_FILE));
define('TORNEVALL_DNSBL_PLUGIN_VERSION', '3.1.6');
define('TORNEVALL_DNSBL_PUBLIC_DOCS_URL', 'https://tools.tornevall.net/docs/dnsbl-api');
define('TORNEVALL_DNSBL_CHANGELOG_URL', 'https://github.com/Tornevall/tornevall-wp-dnsbl/blob/master/CHANGELOG.md');
define('TORNEVALL_DNSBL_HISTORY_URL', 'https://github.com/Tornevall/tornevall-wp-dnsbl/commits/master');
define('TORNEVALL_DNSBL_ISSUES_URL', 'https://github.com/Tornevall/tornevall-wp-dnsbl/issues');

foreach ([
    'includes/class-dnsbl-plugin.php',
    'includes/class-dnsbl-migrations.php',
    'includes/class-dnsbl-admin.php',
    'includes/class-dnsbl-api-client.php',
    'includes/class-dnsbl-write-queue.php',
    'includes/class-dnsbl-integration.php',
    'includes/class-dnsbl-managed-tools-token.php',
    'includes/class-dnsbl-tools-pairing.php',
    'includes/class-dnsbl-telemetry.php',
    'includes/class-dnsbl-woocommerce.php',
    'includes/class-dnsbl-deprecated-flag-guard.php',
    'includes/dnsbl-utils.php',
    'includes/dnsbl-migrations.php',
    'includes/dnsbl-bootstrap.php',
] as $tornevallDnsblInclude) {
    require_once TORNEVALL_DNSBL_PLUGIN_DIR . $tornevallDnsblInclude;
}

if (is_admin()) {
    require_once TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/dnsbl-admin.php';
    add_action('admin_menu', 'tornevall_wp_dnsbl_admin');
    add_action('admin_init', 'register_dnsbl_settings');
}

register_activation_hook(TORNEVALL_DNSBL_PLUGIN_FILE, 'tornevall_wp_dnsbl_activate_db');
register_deactivation_hook(TORNEVALL_DNSBL_PLUGIN_FILE, 'tornevall_wp_dnsbl_deactivate_db');
register_uninstall_hook(TORNEVALL_DNSBL_PLUGIN_FILE, 'tornevall_wp_dnsbl_uninstall_db');

tornevall_dnsbl_register_hooks();
\Tornevall\Networks\DNSBL\Integration::registerHooks();
\Tornevall\Networks\DNSBL\ManagedToolsToken::registerHooks();
\Tornevall\Networks\DNSBL\ToolsPairing::registerHooks();
\Tornevall\Networks\DNSBL\Telemetry::registerHooks();
\Tornevall\Networks\DNSBL\WooCommerce::registerHooks();
\Tornevall\Networks\DNSBL\DeprecatedFlagGuard::registerHooks();
