<?php
/*
 * Plugin Name: Tornevall Networks DNSBL and Fraud Blacklist implementation
 * Plugin URI: https://docs.tornevall.net/x/AoA_/
 * Project URI: https://tracker.tornevall.net/projects/DNSBLWP/
 * Description: Implements functions related to Tornevall Networks DNS Blacklist. Adds options to comment functions that will disable comments if an ip is blacklisted etc
 * Version: 2.0.8
 * Author: Tomas Tornevall
 * Author URI: https://www.tornevalls.se/
 * Text Domain: tornevall-networks-dnsbl-implementation
 */

define('TORNEVALL_DNSBL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TORNEVALL_DNSBL_VERSION', '2.0.8');
define('TORNEVALL_DNSBL_DATA_VERSION', '2.0.0');
define('TORNEVALL_DNSBL_NONCE_EQUALITY', true);

require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/bits.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/api.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/network.php');
require_once(TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/helpers.php');

load_plugin_textdomain(
    'tornevall-networks-dnsbl-implementation',
    false,
    dirname(plugin_basename(__FILE__)) . '/language'
);

$dnsbl_blacklist_status = false;
$dnsbl_blacklist_control_status = "unchecked";

$dnsblPermissionArray = [];
$dnsblClientData = @unserialize(get_option('tornevall_dnsbl_clientdata'));
$permissions = [
    'allow_cidr' => __(
        'The usage of CIDR-blocks are normally not permitted by the DNSBL API, in more functions than listing them. This permission also opens up for usage in DELETE/UPDATE cases (for CIDR-block removals this would help a lot). Adding data with CIDR and different flags is however still a problem.',
        'tornevall-networks-dnsbl-implementation'
    ),
    'allow_cidr_update' => __(
        'Setting that partially allows CIDR-block updates for the DNSBL (there migt me limitations linked to this permission - see the documentation for this information)',
        'tornevall-networks-dnsbl-implementation'
    ),
    'can_purge' => __(
        'Special ability to purge hosts instead of marking them deleted in the database',
        'tornevall-networks-dnsbl-implementation'
    ),
    'dnsbl_update' => __(
        'Standard DNSBL ability to update data in the DNSBL (dnsbl.tornevall.org and bl.fraudbl.org)',
        'tornevall-networks-dnsbl-implementation'
    ),
    'fraudbl_update' => __(
        'Extended ability to handle fraudbl-commerce (this is not the regular bl.fraudbl.org resolver)',
        'tornevall-networks-dnsbl-implementation'
    ),
    'global_delist' => __(
        'Global delisting permission (can use as delisting service for visitors)',
        'tornevall-networks-dnsbl-implementation'
    ),
    'local_delist' => __(
        'Local delisting permission (server can delist self)',
        'tornevall-networks-dnsbl-implementation'
    ),
    'overwrite_flags' => __(
        'When sending new or updated data to DNSBL, clients can only add more flags to the host. This feature makes it possible to overwrite old flags',
        'tornevall-networks-dnsbl-implementation'
    ),
];

$tornevallDnsblFlags = [];
if (is_object($dnsblClientData)) {
    if (isset($dnsblClientData->API_EXTENDED_PERMISSIONS)) {
        foreach ($dnsblClientData->API_EXTENDED_PERMISSIONS as $index => $eData) {
            $permission = $eData->permission;
            $tornevallDnsblFlags[] = $eData->permission;
            $dnsblPermissionArray[] = $permissions[$permission];
        }
    }
}

function tornevall_dnsbl_is_admin()
{
    if (current_user_can('administrator') || is_admin()) {
        return true;
    }

    return false;
}

function tornevall_dnsbl_enqueue()
{
    global $dnsblNonce;
    $dnsblNonceId = 'tornevall_dnsbl_n';
    if (!defined('TORNEVALL_DNSBL_NONCE_EQUALITY')) {
        if (is_admin()) {
            $dnsblNonceId = 'tornevall_dnsbl_a';
        }
    }
    $dnsblNonce = wp_create_nonce($dnsblNonceId);
    $tapi_spinner = plugin_dir_url(__FILE__) . "images/spinner-1s-32px.gif";
    $tapi_delete = plugin_dir_url(__FILE__) . "images/d.png";
    $tapi_q = plugin_dir_url(__FILE__) . "images/q.png";

    $adminUrl = admin_url('admin-ajax.php');
    $vars = [
        'ajax_url' => $adminUrl,
        'spinner' => $tapi_spinner,
        'd' => $tapi_delete,
        'q' => $tapi_q,
        'dnsbln' => $dnsblNonce,
        'tr_blacklisted' => __('Blacklisted', 'tornevall-networks-dnsbl-implementation'),
        'tr_api_reply_success' => __('API reply success', 'tornevall-networks-dnsbl-implementation'),
        'tr_api_reply_authorized' => __('API authorize response', 'tornevall-networks-dnsbl-implementation'),
        'tr_api_reply_fail' => __(
            'Failed. Did you save your settings before trying this?',
            'tornevall-networks-dnsbl-implementation'
        ),
        'tr_flags_updated' => __('Flags updated', 'tornevall-networks-dnsbl-implementation'),
        'tr_request_failure' => __('Request failure', 'tornevall-networks-dnsbl-implementation'),
        'tr_not_blacklisted' => __('Not blacklisted', 'tornevall-networks-dnsbl-implementation'),
        'tr_no_empty_value' => __(
            'Value must not be empty',
            'tornevall-networks-dnsbl-implementation'
        ),
        'tr_removed' => __('Removed', 'tornevall-networks-dnsbl-implementation'),
        'tr_delist_success' => __('Delist successful', 'tornevall-networks-dnsbl-implementation'),
        'tr_captcha_image' => __(
            'What does the image say (lowercase)?',
            'tornevall-networks-dnsbl-implementation'
        ),
        'tr_delist_extended' => __(
            'Removal time has been extended to ',
            'tornevall-networks-dnsbl-implementation'
        ),
        'tr_delist_penalties' => __(
            'but with penalties due too high removal count in too short time.',
            'tornevall-networks-dnsbl-implementation'
        ),
        'tornevall_dnsbl_getlisted_resolver' => get_option('tornevall_dnsbl_getlisted_resolver'),
        'saveConfigNotice' => __(
            'API data updated - If you have made any changes in this configuration, you should also save the settings.',
            'tornevall-networks-dnsbl-implementation'
        ),
        'tornevall_dnsbl_is_admin_notification' => tornevall_dnsbl_is_admin(),
    ];

    wp_enqueue_script(
        'tornevall_dnsbl_backend',
        plugin_dir_url(__FILE__) . 'js/api.min.js?t=' . time(),
        ['jquery'],
        TORNEVALL_DNSBL_VERSION
    );
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
    $dnsbl_blacklist_status = dnsbl_check_blacklist($_SERVER['REMOTE_ADDR']);
    $dnsbl_blacklist_control_status = "checked";

    if (get_option('tornevall_dnsbl_wpcf7')) {
        // WPCF7 (Contact Form DNSBL Addition).
        add_filter('wpcf7_submission_is_blacklisted', 'tornevall_dnsbl_wpcf7_is_blacklisted', 10, 2);
        add_filter('wpcf7_messages', 'tornevall_dnsbl_wpcf7_messages', 9);
        add_filter('wpcf7_display_message', 'tornevall_dnsbl_wpcf7_show_spam_warning', 11, 1);
    }
}

/**
 * @param $trg
 * @param $wpcf7 WPCF7_Submission
 * @return bool
 */
function tornevall_dnsbl_wpcf7_is_blacklisted($trg, $wpcf7)
{
    if (dnsbl_check_blacklist($_SERVER['REMOTE_ADDR'], false, true)) {
        $wpcf7->add_spam_log([
            'agent' => 'tornevall-dnsbl',
            'reason' => __('Blacklisted ip address in use', 'tornevall-networks-dnsbl-implementation'),
        ]);
        $wpcf7->set_status('tornevall_dnsbl');
        $wpcf7->set_response('tornevall_dnsbl_blacklist');
        return true;
    }
}

function tornevall_dnsbl_wpcf7_show_spam_warning($message)
{
    if (dnsbl_check_blacklist($_SERVER['REMOTE_ADDR'], false, true)) {
        $message = '<a href="https://dnsbl.tornevall.org/removal?redirected" target="_blank">' .
            __(
                'Your ip address seem to be blacklisted, so your message is not sent. Click for more information.',
                'tornevall-networks-dnsbl-implementation'
            )
            . '</a>';
    }

    return $message;
}

/**
 * @param $messages
 * @return array
 */
function tornevall_dnsbl_wpcf7_messages($messages)
{
    if (is_array($messages)) {
        $messages['tornevall_dnsbl_blacklist'] = [
            'description' => "Sender's message failed to send due to blacklist in Tornevall DNSBL.",
            'default' => "The ip address you are using to send this message is blacklisted.",
        ];
    }

    return $messages;
}

function dnsbl_resurs_data_info_version($dataInfoKey)
{
    return [
        'name' => 'Tornevall DNSBL Version',
        'value' => TORNEVALL_DNSBL_VERSION,
    ];
}

function dnsbl_resurs_data_info_array($array)
{
    $array[] = 'dnsbl_version';
    return $array;
}

add_action('admin_enqueue_scripts', 'tornevall_dnsbl_enqueue');
add_action('wp_enqueue_scripts', 'tornevall_dnsbl_enqueue');
add_action('wp_ajax_tornednsbl', 'tornevall_dnsbl_api');
add_action('wp_ajax_nopriv_tornednsbl', 'tornevall_dnsbl_api');
add_action('plugins_loaded', 'tornevall_dnsbl_checkpoint');

add_filter('the_content', 'tornevall_dnsbl_content_handler');
add_filter('comments_open', 'dnsbl_blacklist_disable_comments', 10, 1);
add_filter('comments_template', 'dnsbl_blacklist_comments');
add_filter('resursbank_data_info_array', 'dnsbl_resurs_data_info_array');
add_filter('resursbank_data_info_dnsbl_version', 'dnsbl_resurs_data_info_version');
