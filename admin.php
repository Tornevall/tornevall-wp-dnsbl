<?php

use TorneLIB\Utils\Generic;

/**
 * @param $template
 * @param $assignedVariables
 * @return false|string
 * @throws Exception
 */
function tornevall_wp_dnsbl_fetch_template($template, $assignedVariables)
{
    $generic = new Generic();
    $generic->setTemplatePath(__DIR__ . '/templates');
    return $generic->getTemplate($template, $assignedVariables);
}


function tornevall_wp_dnsbl_admin()
{
    add_action('admin_init', 'register_dnsbl_settings');
    add_menu_page(
        'Tornevall DNSBL Options',
        __(
            'Tornevall DNSBL',
            'tornevall-networks-dnsbl-implementation'
        ),
        'manage_options',
        'tornevallDnsblMenu',
        'tornevall_dnsbl_options'
    );
}

function register_dnsbl_settings()
{
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_cache_age');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_filter_types');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_nocomment');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_blockfull');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_delisting_page');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_update_timestamp');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_resolver_hosts');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_form_noajax');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_blocked_redirecturl');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_prefer_api');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_getlisted_resolver');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_comments_disabled_style');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_delistingpage_comments_disabled');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_wpcf7');

    register_setting('dnsblOptions-group', 'tornevall_dnsbl_preferred_api_url');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_api_id');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_api_key');

    register_setting('dnsblOptions-group', 'tornevall_dnsbl_fraudbl_resursbank_woocommerce');
}

function tornevall_dnsbl_options()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    global $tornevallDnsblFlags, $dnsblPermissionArray, $permissions;

    $pagelist = get_pages();
    $currentDelistingPage = get_option('tornevall_dnsbl_delisting_page');
    $delistPageOption = [];
    if (is_array($pagelist)) {
        $delistPageOption[] = '<option value="">None</option>';
        foreach ($pagelist as $pageObject) {
            $selectedPage = '';
            if ($pageObject->ID == $currentDelistingPage) {
                $selectedPage = 'selected=selected';
            }
            $delistPageOption[] = '<option value="' .
                $pageObject->ID . '" ' . $selectedPage . '>' . $pageObject->post_title . '</option>';
        }
    }

    $cacheAgeTest = get_option('tornevall_dnsbl_cache_age');
    if (empty($cacheAgeTest)) {
        update_option('tornevall_dnsbl_cache_age', 900);
    }

    $redirectUrl = get_option('tornevall_dnsbl_blocked_redirecturl');
    if (empty($redirectUrl)) {
        $redirectUrl = 'https://dnsbl.tornevall.org/removal?redirected';
        update_option('tornevall_dnsbl_blocked_redirecturl', $redirectUrl);
    }

    $authUrl = "https://auth.tornevall.net";
    $prefApiUrl = get_option('tornevall_dnsbl_preferred_api_url');
    if (empty($prefApiUrl)) {
        $prefApiUrl = "https://api.tornevall.net/3.0/";
    }

    $assignedVariables = [
        'authUrl' => $authUrl,
        'prefApiUrl' => $prefApiUrl,
        'redirectUrl' => $redirectUrl,
        'cacheAgeTest' => $cacheAgeTest,
        'delistPageOption' => $delistPageOption,
        'tornevallDnsblFlags' => $tornevallDnsblFlags,
        'dnsblPermissionArray' => $dnsblPermissionArray,
        'permissions' => $permissions,
    ];

    echo tornevall_wp_dnsbl_fetch_template('adminview', $assignedVariables);
}
