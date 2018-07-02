<?php
/*
 * Plugin Name: Tornevall Networks DNSBL and Fraud Blacklist implementation
 * Plugin URI: https://docs.tornevall.net/x/AoA_/
 * Project URI: https://tracker.tornevall.net/projects/DNSBLWP/
 * Description: Implements functions related to Tornevall Networks DNS Blacklist. Adds options to comment functions that will disable comments if an ip is blacklisted etc
 * Version: 1.1.1
 * Author: Tomas Tornevall
 * Author URI: http://tornevalls.se/blog/
 * Text Domain: tornevall_dnsbl
 * Domain Path: /language
 */

define('TORNEVALL_DNSBL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TORNEVALL_DNSBL_VERSION', '2.0.0');
define('TORNEVALL_DNSBL_DATA_VERSION', '2.0.0');

require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/bits.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/network.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/helpers.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/dnsbl.php');

load_plugin_textdomain('tornevall_dnsbl', false, dirname(plugin_basename(__FILE__)) . '/language');

if (is_admin()) {
    //require_once( TORNEVALL_DNSBL_PLUGIN_DIR . 'admin.php' );
    //add_action( 'admin_menu', 'tornevall_wp_dnsbl_admin' );
    register_activation_hook(__FILE__, 'tornevall_wp_dnsbl_activate_db');
    register_deactivation_hook(__FILE__, 'tornevall_wp_dnsbl_deactivate_db');
    register_uninstall_hook(__FILE__, 'tornevall_wp_dnsbl_uninstall_db');
}
