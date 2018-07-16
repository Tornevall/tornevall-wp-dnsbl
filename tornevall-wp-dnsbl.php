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
define('TORNEVALL_DNSBL_NONCE_EQUALITY', true);

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
    if ( ! defined('TORNEVALL_DNSBL_NONCE_EQUALITY')) {
        if (is_admin()) {
            $dnsblNonceId = 'tornevall_dnsbl_a';
        }
    }
    $dnsblNonce   = wp_create_nonce($dnsblNonceId);
    $tapi_spinner = plugin_dir_url(__FILE__) . "images/spinner-1s-32px.gif";
    $tapi_delete  = plugin_dir_url(__FILE__) . "images/d.png";
    $tapi_q       = plugin_dir_url(__FILE__) . "images/q.png";

    //echo $dnsblNonceId . " => " . $dnsblNonce;
    //die;

    $adminUrl = admin_url('admin-ajax.php');
    $vars     = array(
        'ajax_url'                           => $adminUrl,
        'spinner'                            => $tapi_spinner,
        'd'                                  => $tapi_delete,
        'q'                                  => $tapi_q,
        'dnsbln'                             => $dnsblNonce,
        'tr_blacklisted'                     => __('Blacklisted', 'tornevall_dnsbl'),
        'tr_flags_updated'                   => __('Flags updated', 'tornevall_dnsbl'),
        'tr_request_failure'                 => __('Request failure', 'tornevall_dnsbl'),
        'tr_not_blacklisted'                 => __('Not blacklisted', 'tornevall_dnsbl'),
        'tr_no_empty_value'                  => __('Value must not be empty', 'tornevall_dnsbl'),
        'tr_removed'                         => __('Removed', 'tornevall_dnsbl'),
        'tr_delist_success'                  => __('Delist successful', 'tornevall_dnsbl'),
        'tr_captcha_image'                   => __('What does the image say (lowercase)?', 'tornevall_dnsbl'),
        'tr_delist_extended'                 => __('Removal time has been extended to ', 'tornevall_dnsbl'),
        'tr_delist_penalties'                => __('but with penalties due too high removal count in too short time.',
            'tornevall_dnsbl'),
        'tornevall_dnsbl_getlisted_resolver' => get_option('tornevall_dnsbl_getlisted_resolver'),
        'saveConfigNotice'                   => __('API data updated - If you have made any changes in this configuration, you should also save the settings.',
            'tornevall_dnsbl'),
    );

    wp_enqueue_script('tornevall_dnsbl_backend', plugin_dir_url(__FILE__) . 'js/api.min.js?t=' . time(),
        array('jquery'),
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
