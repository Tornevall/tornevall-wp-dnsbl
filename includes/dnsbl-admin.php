<?php

use Tornevall\Networks\DNSBL\Admin;
use Tornevall\Networks\DNSBL\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

function tornevall_wp_dnsbl_admin()
{
    Admin::registerMenu();
}

function tornevall_dnsbl_admin_get_default_resolvers()
{
    return Plugin::defaultResolvers();
}

function tornevall_dnsbl_admin_get_default_filter_flags()
{
    return Plugin::defaultSelectedFlags();
}

function tornevall_dnsbl_admin_get_configured_resolvers()
{
    return Plugin::getResolverHosts();
}

function tornevall_dnsbl_admin_get_current_flags()
{
    return Plugin::getCurrentFlagMap();
}

function tornevall_dnsbl_admin_sanitize_checkbox($value)
{
    return Admin::sanitizeCheckbox($value);
}

function tornevall_dnsbl_admin_sanitize_cache_age($value)
{
    return Admin::sanitizeCacheAge($value);
}

function tornevall_dnsbl_admin_sanitize_cache_cleanup_interval($value)
{
    return Admin::sanitizeCacheCleanupInterval($value);
}

function tornevall_dnsbl_admin_sanitize_resolver_hosts($value)
{
    return Admin::sanitizeResolverHosts($value);
}

function tornevall_dnsbl_admin_sanitize_filter_types($value)
{
    return Admin::sanitizeFilterTypes($value);
}

function tornevall_dnsbl_admin_sanitize_redirect_url($value)
{
    return Admin::sanitizeRedirectUrl($value);
}

function tornevall_dnsbl_admin_sanitize_comments_style($value)
{
    return Admin::sanitizeCommentsStyle($value);
}

function tornevall_dnsbl_admin_sanitize_tools_mode($value)
{
    return Admin::sanitizeToolsMode($value);
}

function tornevall_dnsbl_admin_sanitize_whitelist($value)
{
    return Admin::sanitizeWhitelist($value);
}

function tornevall_dnsbl_admin_ensure_defaults()
{
    Admin::ensureDefaults();
}

function register_dnsbl_settings()
{
    Admin::registerSettings();
}

function tornevall_dnsbl_admin_render_checkbox_row($name, $label, $description, $checked)
{
    Admin::renderCheckboxRow($name, $label, $description, $checked);
}

function tornevall_dnsbl_admin_build_page_options($currentDelistingPage)
{
    return Admin::buildPageOptions($currentDelistingPage);
}

function tornevall_dnsbl_admin_run_lookup($ip)
{
    return Admin::runLookup($ip);
}

function tornevall_dnsbl_admin_get_self_check_candidates()
{
    return Admin::getSelfCheckCandidates();
}

function tornevall_dnsbl_admin_get_tool_results($action, $request = [])
{
    return Admin::getToolResults($action, $request);
}

function tornevall_dnsbl_admin_handle_tools_request()
{
    return Admin::handleToolsRequest();
}

function tornevall_dnsbl_admin_render_tool_results_markup($toolResults, $devMode, $resolverNames)
{
    return Admin::renderToolResultsMarkup($toolResults, $devMode, $resolverNames);
}

function tornevall_dnsbl_admin_ajax_tools()
{
    Admin::ajaxTools();
}

function tornevall_dnsbl_admin_render_ajax_results_container($toolResults, $devMode, $resolverNames)
{
    Admin::renderAjaxResultsContainer($toolResults, $devMode, $resolverNames);
}

function tornevall_dnsbl_admin_render_tool_form($toolAction, $title, $buttonLabel, $contentCallback = null)
{
    Admin::renderToolForm($toolAction, $title, $buttonLabel, $contentCallback);
}

function tornevall_dnsbl_admin_render_lookup_tool_fields()
{
    Admin::renderLookupToolFields();
}

function tornevall_dnsbl_admin_render_ajax_notice($message, $type = 'error')
{
    Admin::renderAjaxNotice($message, $type);
}

function tornevall_dnsbl_admin_render_tool_results($toolResults, $devMode, $resolverNames)
{
    Admin::renderToolResults($toolResults, $devMode, $resolverNames);
}

function tornevall_dnsbl_options()
{
    Admin::renderOptionsPage();
}
