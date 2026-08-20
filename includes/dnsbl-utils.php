<?php

use Tornevall\Networks\DNSBL\Migrations;
use Tornevall\Networks\DNSBL\Plugin;
use Tornevall\Networks\DNSBL\Telemetry;

if (!defined('ABSPATH')) {
    exit;
}

function tornevall_dnsbl_get_table_definitions()
{
    return Migrations::tableDefinitions();
}

function tornevall_dnsbl_get_table_cleanup_candidates($wpdb)
{
    return Migrations::tableCleanupCandidates($wpdb);
}

function tornevall_wp_dnsbl_activate_db()
{
    Migrations::activate();
}

function tornevall_wp_dnsbl_deactivate_db()
{
    Telemetry::clearSchedule();
    Migrations::deactivate();
}

function tornevall_wp_dnsbl_uninstall_db()
{
    Telemetry::uninstall();
    Migrations::uninstall();
}

function tornevall_dnsbl_default_resolvers()
{
    return Plugin::defaultResolvers();
}

function tornevall_dnsbl_default_selected_flags()
{
    return Plugin::defaultSelectedFlags();
}

function tornevall_dnsbl_default_blocked_redirect_url()
{
    return Plugin::defaultBlockedRedirectUrl();
}

function tornevall_dnsbl_default_comments_disabled_style()
{
    return Plugin::defaultCommentsDisabledStyle();
}

function tornevall_dnsbl_default_whitelist_entries()
{
    return Plugin::defaultWhitelistEntries();
}

function tornevall_dnsbl_normalize_whitelist_token($value)
{
    return Plugin::normalizeWhitelistToken($value);
}

function tornevall_dnsbl_parse_whitelist_entries($value)
{
    return Plugin::parseWhitelistEntries($value);
}

function tornevall_dnsbl_get_whitelist_entries()
{
    return Plugin::getWhitelistEntries();
}

function tornevall_dnsbl_ip_matches_cidr($ip, $cidr)
{
    return Plugin::ipMatchesCidr($ip, $cidr);
}

function tornevall_dnsbl_is_whitelisted_ip($ip)
{
    return Plugin::isWhitelistedIp($ip);
}

function tornevall_dnsbl_get_resolver_hosts()
{
    return Plugin::getResolverHosts();
}

function tornevall_dnsbl_default_flag_map()
{
    return Plugin::defaultFlagMap();
}

function tornevall_dnsbl_is_power_of_two($value)
{
    return Plugin::isPowerOfTwo($value);
}

function tornevall_dnsbl_normalize_flag_map($structure)
{
    return Plugin::normalizeFlagMap($structure);
}

function tornevall_dnsbl_get_current_flag_map()
{
    return Plugin::getCurrentFlagMap();
}

function tornevall_dnsbl_decode_bitmask($bitmask)
{
    return Plugin::decodeBitmask($bitmask);
}

function tornevall_dnsbl_combine_bitmasks($bitmasks)
{
    return Plugin::combineBitmasks($bitmasks);
}

function tornevall_dnsbl_get_selected_flags()
{
    return Plugin::getSelectedFlags();
}

function tornevall_dnsbl_matches_selected_flags($bitmask)
{
    return Plugin::matchesSelectedFlags($bitmask);
}

function tornevall_dnsbl_tools_base_url()
{
    return Plugin::toolsBaseUrl();
}

function tornevall_dnsbl_tools_token()
{
    return Plugin::toolsToken();
}

function tornevall_dnsbl_tools_request($path, $payload = [], $method = 'POST')
{
    return Plugin::toolsRequest($path, $payload, $method);
}

function tornevall_dnsbl_tools_assess_comment($ip, $commentData = [])
{
    return Plugin::toolsAssessComment($ip, $commentData);
}

function tornevall_dnsbl_reverse_ip($addr)
{
    return Plugin::reverseIp($addr);
}

function tornevall_dnsbl_extract_request_responses($lookup)
{
    return Plugin::extractRequestResponses($lookup);
}

function tornevall_dnsbl_build_lookup_result($ip, $lookup = null)
{
    return Plugin::buildLookupResult($ip, $lookup);
}

function tornevall_dnsbl_format_diagnostic_payload($payload)
{
    return Plugin::formatDiagnosticPayload($payload);
}

function tornevall_dnsbl_content_handler($content)
{
    return Plugin::contentHandler($content);
}

function dnsbl_blacklist_disable_comments($open)
{
    return Plugin::disableComments($open);
}

function dnsbl_blacklist_disable_comments_message($comments)
{
    return Plugin::disableCommentsMessage($comments);
}

function dnsbl_resolve_addr($addr)
{
    return Plugin::resolveAddr($addr);
}

function dnsbl_check_blacklist($addr, $getIsListed = false, $adminPassThrough = false)
{
    return Plugin::checkBlacklist($addr, $getIsListed, $adminPassThrough);
}

function dnsbl_is_protected_user()
{
    Plugin::renderProtectedUserNotice();
}

function tornevall_dnsbl_get_cache_table_name($wpdb)
{
    return Plugin::getCacheTableName($wpdb);
}

function tornevall_dnsbl_get_stats_table_name($wpdb)
{
    return Plugin::getStatsTableName($wpdb);
}

function tornevall_dnsbl_table_exists($wpdb, $tableName)
{
    return Plugin::tableExists($wpdb, $tableName);
}

function tornevall_dnsbl_evaluate_blacklist_state($addr, $adminPassThrough = false)
{
    return Plugin::evaluateBlacklistState($addr, $adminPassThrough);
}

function tornevall_dnsbl_record_stat($addr, $responseBitmask, $wasBlocked, $source = 'request')
{
    Plugin::recordStat($addr, $responseBitmask, $wasBlocked, $source);
}

function tornevall_dnsbl_get_stats_summary($lookbackHours = 0)
{
    return Plugin::getStatsSummary($lookbackHours);
}

function dnsbl_check_blacklist_cache($addr)
{
    return Plugin::checkBlacklistCache($addr);
}

function tornevall_dnsbl_pre_comment_approved($approved, $commentdata)
{
    return Plugin::preCommentApproved($approved, $commentdata);
}
