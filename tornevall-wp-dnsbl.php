<?php
/*
 * Plugin Name: Tornevall Networks DNSBL and Fraud Blacklist implementation
 * Plugin URI: https://wordpress.org/plugins/tornevall-networks-dnsbl-implementation/
 * Project URI: https://github.com/Tornevall/tornevall-wp-dnsbl
 * Description: Implements DNSBL/FraudBL checks for comments and visitor protection.
 * Version: 3.0.0
 * Author: Tomas Tornevall
 * Author URI: https://www.tornevalls.se/
 * Text Domain: tornevall-networks-dnsbl-implementation
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TORNEVALL_DNSBL_PLUGIN_FILE', __FILE__);
define('TORNEVALL_DNSBL_PLUGIN_DIR', plugin_dir_path(TORNEVALL_DNSBL_PLUGIN_FILE));
define('TORNEVALL_DNSBL_PLUGIN_URL', plugin_dir_url(TORNEVALL_DNSBL_PLUGIN_FILE));

foreach ([
    'includes/dnsbl-utils.php',
    'includes/dnsbl-migrations.php',
    'includes/dnsbl-bootstrap.php',
] as $tornevallDnsblInclude) {
    require_once TORNEVALL_DNSBL_PLUGIN_DIR . $tornevallDnsblInclude;
}

if (is_admin()) {
    require_once TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/dnsbl-admin.php';
    add_action('admin_menu', 'tornevall_wp_dnsbl_admin');
}

register_activation_hook(TORNEVALL_DNSBL_PLUGIN_FILE, 'tornevall_wp_dnsbl_activate_db');
register_deactivation_hook(TORNEVALL_DNSBL_PLUGIN_FILE, 'tornevall_wp_dnsbl_deactivate_db');
register_uninstall_hook(TORNEVALL_DNSBL_PLUGIN_FILE, 'tornevall_wp_dnsbl_uninstall_db');

tornevall_dnsbl_register_hooks();

