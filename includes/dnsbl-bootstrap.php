<?php

if (!defined('ABSPATH')) {
    exit;
}

$dnsbl_blacklist_status = false;
$dnsbl_blacklist_control_status = 'unchecked';

/**
 * Check whether the current request is running in an administrative context.
 *
 * @return bool
 */
function tornevall_dnsbl_is_admin()
{
    return current_user_can('administrator') || is_admin();
}

/**
 * Enqueue assets used by the plugin admin screen.
 *
 * @param string $hook Current admin screen hook suffix.
 *
 * @return void
 */
function tornevall_dnsbl_enqueue($hook = '')
{
    if (!is_admin()) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'toplevel_page_tornevallDnsblMenu') {
        return;
    }

    wp_enqueue_script(
        'tornevall-dnsbl-admin-tools',
        TORNEVALL_DNSBL_PLUGIN_URL . 'js/dnsbl-admin-tools.js',
        [],
        tornevall_dnsbl_schema_version(),
        true
    );

    wp_localize_script('tornevall-dnsbl-admin-tools', 'tornevallDnsblAdminTools', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'action' => 'tornevall_dnsbl_admin_tools',
        'resultSelector' => '#tornevall-dnsbl-tool-results',
        'loadingText' => __('Running check…', 'tornevall-networks-dnsbl-implementation'),
        'errorText' => __('The request could not be completed. Please try again.', 'tornevall-networks-dnsbl-implementation'),
    ]);
}

/**
 * Prime the request blacklist status for the current visitor.
 *
 * @return void
 */
function tornevall_dnsbl_checkpoint()
{
    global $dnsbl_blacklist_status, $dnsbl_blacklist_control_status;

    $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    if (!$remoteAddr || !filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        $dnsbl_blacklist_status = false;
        $dnsbl_blacklist_control_status = 'checked';
        return;
    }

    $evaluation = tornevall_dnsbl_evaluate_blacklist_state($remoteAddr);
    $source = is_admin() ? 'admin-request' : 'request';
    tornevall_dnsbl_record_stat($remoteAddr, (int)$evaluation['bitmask'], !empty($evaluation['blocked']), $source);
    $dnsbl_blacklist_status = !empty($evaluation['blocked']);
    $dnsbl_blacklist_control_status = 'checked';
}

/**
 * Register all plugin hooks.
 *
 * @return void
 */
function tornevall_dnsbl_register_hooks()
{
    add_action('admin_enqueue_scripts', 'tornevall_dnsbl_enqueue');
    add_action('plugins_loaded', 'tornevall_dnsbl_migration_maybe_upgrade');
    add_action('init', 'tornevall_dnsbl_checkpoint');
    add_action('wp_ajax_tornevall_dnsbl_admin_tools', 'tornevall_dnsbl_admin_ajax_tools');

    add_filter('the_content', 'tornevall_dnsbl_content_handler');
    add_filter('comments_open', 'dnsbl_blacklist_disable_comments', 10, 1);
    add_filter('comments_array', 'dnsbl_blacklist_disable_comments_message', 10, 1);
    add_filter('pre_comment_approved', 'tornevall_dnsbl_pre_comment_approved', 10, 2);
}

