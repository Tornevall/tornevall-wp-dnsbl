<?php
/*
 * Plugin Name: Tornevall Networks DNSBL and Fraud Blacklist implementation
 * Plugin URI: https://docs.tornevall.net/x/AoA_/
 * Project URI: https://tracker.tornevall.net/projects/DNSBLWP/
 * Description: Implements functions related to Tornevall Networks DNS Blacklist. Adds options to comment functions that will disable comments if an ip is blacklisted etc
 * Version: 2.0.0
 * Author: Tomas Tornevall
 * Author URI: http://tornevalls.se/blog/
 * Text Domain: tornevall_dnsbl
 * Domain Path: /language
 */

define('TORNEVALL_DNSBL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TORNEVALL_DNSBL_VERSION', '2.0.0');
define('TORNEVALL_DNSBL_DATA_VERSION', '2.0.0');

require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/api.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/bits.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/network.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/helpers.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/dnsbl.php');

load_plugin_textdomain('tornevall_dnsbl', false, dirname(plugin_basename(__FILE__)) . '/language');

function tornevall_dnsbl_enqueue()
{
    $nId = 'tornevall_dnsbl_n';
    if (is_admin()) {
        $nId = 'tornevall_dnsbl_a';
    }

    $tapi_spinner = plugin_dir_url(__FILE__) . "images/spinner-1s-32px.gif";

    $adminUrl = admin_url('admin-ajax.php');
    $vars     = array(
        'ajax_url'         => $adminUrl,
        'spinner'          => $tapi_spinner,
        'dnsbln'           => wp_create_nonce($nId),
        'saveConfigNotice' => __('API data updated - If you have made any changes in this configuration, you should also save the settings.',
            'tornevall_dnsbl'),
    );

    wp_enqueue_script('tornevall_dnsbl_backend', plugin_dir_url(__FILE__) . 'js/api.js?t=' . time(), array('jquery'),
        TORNEVALL_DNSBL_VERSION);
    wp_localize_script('tornevall_dnsbl_backend', 'tornevall_dnsbl_vars', $vars);
}

if (is_admin()) {
    require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'admin.php');
    add_action('admin_menu', 'tornevall_wp_dnsbl_admin');
    register_activation_hook(__FILE__, 'tornevall_wp_dnsbl_activate_db');
    register_deactivation_hook(__FILE__, 'tornevall_wp_dnsbl_deactivate_db');
    register_uninstall_hook(__FILE__, 'tornevall_wp_dnsbl_uninstall_db');
}


add_action('admin_enqueue_scripts', 'tornevall_dnsbl_enqueue');
add_action('wp_enqueue_scripts', 'tornevall_dnsbl_enqueue');
add_action('wp_ajax_tornednsbl', 'tornevall_dnsbl_api');
add_action('wp_ajax_nopriv_tornednsbl', 'tornevall_dnsbl_api');
