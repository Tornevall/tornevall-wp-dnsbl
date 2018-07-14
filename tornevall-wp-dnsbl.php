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


// deleted
//$_SERVER['REMOTE_ADDR'] = "194.9.167.205";

// torexit not deleted
//$_SERVER['REMOTE_ADDR'] = "103.250.73.13";

require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/api.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/bits.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/network.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/helpers.php');

load_plugin_textdomain('tornevall_dnsbl', false, dirname(plugin_basename(__FILE__)) . '/language');

$dnsbl_blacklist_status         = false;
$dnsbl_blacklist_control_status = "unchecked";

$dnsblPermissionArray = array();
$dnsblClientData      = @unserialize(get_option('tornevall_dnsbl_clientdata'));
$permissions          = array(
    'global_delist'   => __('Global delisting permission (can use as delisting service for visitors)',
        'tornevall_dnsbl'),
    'local_delist'    => __('Local delisting permission (server can delist self)', 'tornevall_dnsbl'),
    'dnsbl_update'    => __('Standard DNSBL ability to update data in the DNSBL (dnsbl.tornevall.org and bl.fraudbl.org)',
        'tornevall_dnsbl'),
    'fraudbl_update'  => __('Extended ability to handle fraudbl-commerce (this is not the regular bl.fraudbl.org resolver)',
        'tornevall_dnsbl'),
    'can_purge'       => __('Special ability to purge hosts instead of marking them deleted in the database',
        'tornevall_dnsbl'),
    'allow_cidr'      => __('The usage of CIDR-blocks are normally not permitted by the DNSBL API, in more functions than listing them. This permission also opens up for usage in DELETE/UPDATE cases (for CIDR-block removals this would help a lot). Adding data with CIDR and different flags is however still a problem.',
        'tornevall_dnsbl'),
    'overwrite_flags' => __('When sending new or updated data to DNSBL, clients can only add more flags to the host. This feature makes it possible to overwrite old flags',
        'tornevall_dnsbl'),
);
$tornevallDnsblFlags  = array();
if (is_object($dnsblClientData)) {
    if (isset($dnsblClientData->API_EXTENDED_PERMISSIONS)) {
        foreach ($dnsblClientData->API_EXTENDED_PERMISSIONS as $index => $eData) {
            $permission             = $eData->permission;
            $tornevallDnsblFlags[]  = $eData->permission;
            $dnsblPermissionArray[] = $permissions[$permission];
        }
    }
}


function tornevall_dnsbl_enqueue()
{
    global $dnsblNonce;
    $dnsblNonceId = 'tornevall_dnsbl_n';
    if (is_admin()) {
        $dnsblNonceId = 'tornevall_dnsbl_a';
    }
    $dnsblNonce   = wp_create_nonce($dnsblNonceId);
    $tapi_spinner = plugin_dir_url(__FILE__) . "images/spinner-1s-32px.gif";
    $tapi_delete  = plugin_dir_url(__FILE__) . "images/d.png";
    $tapi_q       = plugin_dir_url(__FILE__) . "images/q.png";

    $adminUrl = admin_url('admin-ajax.php');
    $vars     = array(
        'ajax_url'         => $adminUrl,
        'spinner'          => $tapi_spinner,
        'delete'           => $tapi_delete,
        'q'                => $tapi_q,
        'dnsbln'           => wp_create_nonce($dnsblNonce),
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

function tornevall_dnsbl_checkpoint()
{
    global $dnsbl_blacklist_status, $dnsbl_blacklist_control_status;
    $dnsbl_blacklist_status         = dnsbl_check_blacklist($_SERVER['REMOTE_ADDR']);
    $dnsbl_blacklist_control_status = "checked";
}

add_action('admin_enqueue_scripts', 'tornevall_dnsbl_enqueue');
add_action('wp_enqueue_scripts', 'tornevall_dnsbl_enqueue');
add_action('wp_ajax_tornednsbl', 'tornevall_dnsbl_api');
add_action('wp_ajax_nopriv_tornednsbl', 'tornevall_dnsbl_api');
add_action('plugins_loaded', 'tornevall_dnsbl_checkpoint');
add_filter('the_content', 'tornevall_dnsbl_content_handler');
add_filter('comments_open', 'dnsbl_blacklist_disable_comments');
