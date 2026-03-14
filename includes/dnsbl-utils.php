<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the table definitions used by the plugin.
 *
 * @return array<string,string>
 */
function tornevall_dnsbl_get_table_definitions()
{
    return tornevall_dnsbl_migration_table_definitions();
}

/**
 * Get cleanup candidates for previously used table names.
 *
 * @param wpdb $wpdb WordPress database abstraction.
 *
 * @return string[]
 */
function tornevall_dnsbl_get_table_cleanup_candidates($wpdb)
{
    return tornevall_dnsbl_migration_table_cleanup_candidates($wpdb);
}

/**
 * Run plugin activation migration tasks.
 *
 * @return void
 */
function tornevall_wp_dnsbl_activate_db()
{
    tornevall_dnsbl_migration_activate();
}

/**
 * Run plugin deactivation tasks.
 *
 * @return void
 */
function tornevall_wp_dnsbl_deactivate_db()
{
    tornevall_dnsbl_migration_deactivate();
}

/**
 * Run plugin uninstall cleanup tasks.
 *
 * @return void
 */
function tornevall_wp_dnsbl_uninstall_db()
{
    tornevall_dnsbl_migration_uninstall();
}

/**
 * Get the default DNSBL resolver hosts.
 *
 * @return string[]
 */
function tornevall_dnsbl_default_resolvers()
{
    return [
        'dnsbl.tornevall.org',
        'bl.fraudbl.org',
    ];
}

/**
 * Get the default trigger flags used when no option has been saved yet.
 *
 * @return string[]
 */
function tornevall_dnsbl_default_selected_flags()
{
    return [
        'IP_CONFIRMED',
        'IP_SECOND_EXIT',
        'IP_ABUSE_NO_SMTP',
        'IP_ANONYMOUS',
    ];
}

/**
 * Get the default blacklist redirect URL.
 *
 * @return string
 */
function tornevall_dnsbl_default_blocked_redirect_url()
{
    return 'https://dnsbl.tornevall.org/removal?redirected';
}

/**
 * Get the default admin notice style used when comments are disabled.
 *
 * @return string
 */
function tornevall_dnsbl_default_comments_disabled_style()
{
    return 'font-weight: bold;';
}

/**
 * Get the currently configured resolver hosts.
 *
 * @return string[]
 */
function tornevall_dnsbl_get_resolver_hosts()
{
    $resolverNames = array_values(array_filter(array_map('trim', explode(',', (string)get_option('tornevall_dnsbl_resolver_hosts')))));
    if (!count($resolverNames)) {
        $resolverNames = tornevall_dnsbl_default_resolvers();
    }

    return $resolverNames;
}

/**
 * Get the current flag map, normalized to valid bit values.
 *
 * @return array<string,int>
 */
function tornevall_dnsbl_default_flag_map()
{
    return [
        'FREE_SLOT_1_PREVIOUSLY_REPORTED' => 1,
        'IP_CONFIRMED' => 2,
        'IP_PHISHING' => 4,
        'FREE_SLOT_8_PREVIOUSLY_PROXYTIMEOUT' => 8,
        'IP_MAILSERVER_SPAM' => 16,
        'IP_SECOND_EXIT' => 32,
        'IP_ABUSE_NO_SMTP' => 64,
        'IP_ANONYMOUS' => 128,
        'BIT_256' => 256,
    ];
}

/**
 * Check whether a numeric value is a valid power-of-two bit.
 *
 * @param mixed $value Value to test.
 *
 * @return bool
 */
function tornevall_dnsbl_is_power_of_two($value)
{
    $number = (int)$value;

    return $number > 0 && (($number & ($number - 1)) === 0);
}

/**
 * Normalize a saved flag map and discard invalid entries.
 *
 * @param mixed $structure Raw flag structure from options storage.
 *
 * @return array<string,int>
 */
function tornevall_dnsbl_normalize_flag_map($structure)
{
    $normalized = [];

    foreach ((array)$structure as $flagName => $bitValue) {
        $flagName = strtoupper(preg_replace('/[^A-Z0-9_]+/i', '', (string)$flagName));
        $bitValue = (int)$bitValue;

        if ($flagName === '' || !tornevall_dnsbl_is_power_of_two($bitValue)) {
            continue;
        }

        $normalized[$flagName] = $bitValue;
    }

    if (!count($normalized)) {
        $normalized = tornevall_dnsbl_default_flag_map();
    }

    asort($normalized, SORT_NUMERIC);

    return $normalized;
}

/**
 * Get the active normalized flag map from WordPress options.
 *
 * @return array<string,int>
 */
function tornevall_dnsbl_get_current_flag_map()
{
    $structure = get_option('tornevall_dnsbl_current_flags');
    $normalized = tornevall_dnsbl_normalize_flag_map($structure);

    if (!is_array($structure) || $structure !== $normalized) {
        update_option('tornevall_dnsbl_current_flags', $normalized);
    }

    return $normalized;
}

/**
 * Decode a bitmask into active flag names.
 *
 * @param int|string $bitmask Bitmask value.
 *
 * @return string[]
 */
function tornevall_dnsbl_decode_bitmask($bitmask)
{
    $mask = (int)$bitmask;
    if ($mask <= 0) {
        return [];
    }

    $activeFlags = [];
    foreach (tornevall_dnsbl_get_current_flag_map() as $flagName => $bitValue) {
        if (($mask & $bitValue) === $bitValue) {
            $activeFlags[] = $flagName;
        }
    }

    return $activeFlags;
}

/**
 * Combine multiple bitmasks into one value.
 *
 * @param array<int|string> $bitmasks Bitmask values to merge.
 *
 * @return int
 */
function tornevall_dnsbl_combine_bitmasks($bitmasks)
{
    $combined = 0;
    foreach ((array)$bitmasks as $bitmask) {
        $combined |= (int)$bitmask;
    }

    return $combined;
}

/**
 * Get the selected trigger flags from options storage.
 *
 * @return string[]
 */
function tornevall_dnsbl_get_selected_flags()
{
    $selected = get_option('tornevall_dnsbl_filter_types');
    if (!is_array($selected) || !count($selected)) {
        $selected = tornevall_dnsbl_default_selected_flags();
        update_option('tornevall_dnsbl_filter_types', $selected);
    }

    return array_values(array_unique(array_map('strval', $selected)));
}

/**
 * Check whether any selected trigger flags are present in a bitmask.
 *
 * @param int|string $bitmask Bitmask value to inspect.
 *
 * @return bool
 */
function tornevall_dnsbl_matches_selected_flags($bitmask)
{
    $selectedFlags = tornevall_dnsbl_get_selected_flags();

    foreach (tornevall_dnsbl_decode_bitmask($bitmask) as $flagName) {
        if (in_array($flagName, $selectedFlags, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Resolve the configured Tools base URL.
 *
 * @return string
 */
function tornevall_dnsbl_tools_base_url()
{
    $mode = sanitize_text_field((string)get_option('tornevall_dnsbl_tools_mode'));
    if ($mode === 'dev') {
        return 'https://tools.tornevall.com';
    }
    if ($mode === 'prod') {
        return 'https://tools.tornevall.net';
    }

    $devMode = get_option('tornevall_dnsbl_dev_mode') === '1';

    return $devMode ? 'https://tools.tornevall.com' : 'https://tools.tornevall.net';
}

/**
 * Get the configured Tools bearer token.
 *
 * @return string
 */
function tornevall_dnsbl_tools_token()
{
    return trim((string)get_option('tornevall_dnsbl_tools_token'));
}

/**
 * Send a request to the Tools backend.
 *
 * @param string              $path    Relative API path.
 * @param array<string,mixed> $payload Request payload.
 * @param string              $method  HTTP method.
 *
 * @return array<string,mixed>
 */
function tornevall_dnsbl_tools_request($path, $payload = [], $method = 'POST')
{
    $url = untrailingslashit(tornevall_dnsbl_tools_base_url()) . '/' . ltrim($path, '/');
    $headers = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ];

    $token = tornevall_dnsbl_tools_token();
    if ($token !== '') {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    $args = [
        'method' => strtoupper($method),
        'headers' => $headers,
        'timeout' => 8,
        'body' => wp_json_encode($payload),
    ];

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        return [
            'ok' => false,
            'error' => $response->get_error_message(),
            'status' => 0,
        ];
    }

    $status = wp_remote_retrieve_response_code($response);
    $rawBody = wp_remote_retrieve_body($response);
    $body = json_decode($rawBody, true);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_array($body) ? $body : ['raw' => $rawBody],
        'error' => $status >= 400 ? ('HTTP ' . $status) : null,
    ];
}

/**
 * Ask Tools for an optional comment risk assessment.
 *
 * @param string              $ip          Visitor IP address.
 * @param array<string,mixed> $commentData Comment payload.
 *
 * @return array<string,mixed>
 */
function tornevall_dnsbl_tools_assess_comment($ip, $commentData = [])
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return [
            'blocked' => false,
            'reason' => 'invalid-ip',
            'source' => 'tools',
        ];
    }

    $token = tornevall_dnsbl_tools_token();
    if ($token === '') {
        return [
            'blocked' => false,
            'reason' => 'no-token',
            'source' => 'tools',
        ];
    }

    $payload = [
        'ip' => $ip,
        'context' => [
            'comment_author' => isset($commentData['comment_author']) ? (string)$commentData['comment_author'] : '',
            'comment_author_email' => isset($commentData['comment_author_email']) ? (string)$commentData['comment_author_email'] : '',
            'comment_author_url' => isset($commentData['comment_author_url']) ? (string)$commentData['comment_author_url'] : '',
            'comment_content' => isset($commentData['comment_content']) ? (string)$commentData['comment_content'] : '',
        ],
    ];

    $response = tornevall_dnsbl_tools_request('/api/tools/dnsbl/comment-assess', $payload, 'POST');
    if (!$response['ok']) {
        return [
            'blocked' => false,
            'reason' => 'tools-unavailable',
            'source' => 'tools',
        ];
    }

    $body = is_array($response['body']) ? $response['body'] : [];

    return [
        'blocked' => !empty($body['blocked']),
        'reason' => isset($body['reason']) ? (string)$body['reason'] : 'ok',
        'source' => 'tools',
    ];
}

/**
 * Reverse an IPv4 or IPv6 address to the DNSBL lookup format.
 *
 * @param string $addr IP address.
 *
 * @return string|null
 */
function tornevall_dnsbl_reverse_ip($addr)
{
    if (!filter_var($addr, FILTER_VALIDATE_IP)) {
        return null;
    }

    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return implode('.', array_reverse(explode('.', $addr)));
    }

    if (!filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return null;
    }

    $packed = @inet_pton($addr);
    if ($packed === false) {
        return null;
    }

    $hex = unpack('H*hex', $packed);
    if (!isset($hex['hex'])) {
        return null;
    }

    return implode('.', array_reverse(str_split($hex['hex'])));
}

/**
 * Extract normalized request-response rows from a DNS lookup payload.
 *
 * @param mixed $lookup Raw lookup result.
 *
 * @return array<int,array<string,mixed>>
 */
function tornevall_dnsbl_extract_request_responses($lookup)
{
    if (!is_array($lookup)) {
        return [];
    }

    $requestResponse = isset($lookup['response']['requestResponse']) && is_array($lookup['response']['requestResponse'])
        ? $lookup['response']['requestResponse']
        : [];

    return array_values(array_filter($requestResponse, 'is_array'));
}

/**
 * Build a normalized lookup result payload used by admin tools and cache refreshes.
 *
 * @param string                   $ip     IP address to inspect.
 * @param array<string,mixed>|null $lookup Optional lookup payload.
 *
 * @return array<string,mixed>
 */
function tornevall_dnsbl_build_lookup_result($ip, $lookup = null)
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return [
            'ip' => $ip,
            'listed' => false,
            'typebit' => 0,
            'constants' => [],
            'raw' => null,
            'message' => __('Invalid address format', 'tornevall-networks-dnsbl-implementation'),
        ];
    }

    if ($lookup === null) {
        $lookup = dnsbl_resolve_addr($ip);
    }

    $result = [
        'ip' => $ip,
        'listed' => false,
        'typebit' => 0,
        'constants' => [],
        'raw' => $lookup,
        'message' => '',
    ];

    $requestResponse = tornevall_dnsbl_extract_request_responses($lookup);
    if (!count($requestResponse)) {
        $result['message'] = __('Not blacklisted', 'tornevall-networks-dnsbl-implementation');
        return $result;
    }

    $typeBits = [];
    foreach ($requestResponse as $row) {
        $typeBits[] = isset($row['typebit']) ? (int)$row['typebit'] : 0;
    }

    $result['listed'] = true;
    $result['typebit'] = tornevall_dnsbl_combine_bitmasks($typeBits);
    $result['constants'] = tornevall_dnsbl_decode_bitmask($result['typebit']);
    $result['message'] = __('Blacklisted', 'tornevall-networks-dnsbl-implementation');

    return $result;
}

/**
 * Format diagnostic payloads for safe admin display.
 *
 * @param mixed $payload Diagnostic payload.
 *
 * @return string
 */
function tornevall_dnsbl_format_diagnostic_payload($payload)
{
    if (is_scalar($payload) || $payload === null) {
        return (string)$payload;
    }

    $encoded = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : wp_json_encode(['unserializable' => true]);
}

/**
 * Render delisting content for the configured delisting page.
 *
 * @param string $content Post content.
 *
 * @return string
 */
function tornevall_dnsbl_content_handler($content)
{
    global $post;

    if (!$post || !isset($post->ID)) {
        return $content;
    }

    $currentDelistingPage = (int)get_option('tornevall_dnsbl_delisting_page');
    if (!$currentDelistingPage || (int)$post->ID !== $currentDelistingPage) {
        return $content;
    }

    $removalPlaceholder = '<div style="border:1px solid #cbd5e1; padding:10px; border-radius:6px; background:#f8fafc;">'
        . '<strong>' . esc_html__('Removal tools are handled through Tornevall Tools.', 'tornevall-networks-dnsbl-implementation') . '</strong><br>'
        . esc_html__('DNS lookup checks continue to work from this plugin.', 'tornevall-networks-dnsbl-implementation')
        . '</div>';

    if (preg_match('/\[dnsbl_removal_form]/i', (string)$content)) {
        return preg_replace('/\[dnsbl_removal_form]/i', $removalPlaceholder, (string)$content);
    }

    return (string)$content . '<br>' . $removalPlaceholder;
}

/**
 * Disable comments for blocked visitors.
 *
 * @param bool $open Whether comments are currently open.
 *
 * @return bool
 */
function dnsbl_blacklist_disable_comments($open)
{
    global $post, $dnsbl_blacklist_control_status, $dnsbl_blacklist_status;

    $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    $currentDelistingPage = (int)get_option('tornevall_dnsbl_delisting_page');

    if ($dnsbl_blacklist_control_status !== 'checked' && $remoteAddr) {
        $dnsbl_blacklist_status = dnsbl_check_blacklist($remoteAddr, false);
    }

    if ($post && isset($post->ID) && (int)$post->ID === $currentDelistingPage && get_option('tornevall_dnsbl_delistingpage_comments_disabled') === '1') {
        return false;
    }

    if ($dnsbl_blacklist_status) {
        if (get_option('tornevall_dnsbl_blockfull')) {
            $redirectUrl = (string)get_option('tornevall_dnsbl_blocked_redirecturl');
            if ($redirectUrl === '') {
                $redirectUrl = tornevall_dnsbl_default_blocked_redirect_url();
            }

            wp_safe_redirect($redirectUrl, 301);
            exit;
        }

        return false;
    }

    return $open;
}

/**
 * Replace comment output with a contextual warning for blocked visitors.
 *
 * @param array<int,mixed> $comments Comment list.
 *
 * @return array<int,mixed>
 */
function dnsbl_blacklist_disable_comments_message($comments)
{
    $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    if (!$remoteAddr || !filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return $comments;
    }

    $isBlocked = dnsbl_check_blacklist($remoteAddr, false, true);
    if (!$isBlocked) {
        return $comments;
    }

    $commentsDisabledStyle = (string)get_option('tornevall_dnsbl_comments_disabled_style');
    if ($commentsDisabledStyle === '') {
        $commentsDisabledStyle = tornevall_dnsbl_default_comments_disabled_style();
    }

    if (is_admin() || current_user_can('administrator')) {
        echo '<div style="' . esc_attr($commentsDisabledStyle) . '">'
            . esc_html__('Tornevall DNSBL scanner has detected that your current visiting ip address is blacklisted!', 'tornevall-networks-dnsbl-implementation')
            . ' <a href="' . esc_url(tornevall_dnsbl_default_blocked_redirect_url()) . '" target="_blank" rel="noopener noreferrer">'
            . esc_html__('For more information, look here', 'tornevall-networks-dnsbl-implementation')
            . '</a></div>';
        return $comments;
    }

    echo '<div style="' . esc_attr($commentsDisabledStyle) . '">'
        . esc_html__('Comments section is currently unavailable: Your ip address has been flagged as untrusted by a DNS Blacklist', 'tornevall-networks-dnsbl-implementation')
        . '</div>';

    return [];
}

/**
 * Resolve an address against all configured DNSBL resolvers.
 *
 * @param string $addr IP address.
 *
 * @return array<string,mixed>
 */
function dnsbl_resolve_addr($addr)
{
    $arpaName = tornevall_dnsbl_reverse_ip($addr);
    if (!$arpaName) {
        return [
            'response' => [
                'requestResponse' => [],
                'requestType' => 'DNS',
            ],
            'errorcode' => 400,
            'errorstring' => __('Invalid address format', 'tornevall-networks-dnsbl-implementation'),
        ];
    }

    $newArray = [];
    $typeBit = 0;
    $hasBlacklist = false;

    foreach (tornevall_dnsbl_get_resolver_hosts() as $resolverName) {
        $resolveHost = $arpaName . '.' . $resolverName;
        $resultHost = @gethostbyname($resolveHost);
        if (!$resultHost || $resultHost === $resolveHost) {
            continue;
        }

        $resultEx = explode('.', $resultHost);
        if (count($resultEx) < 4 || (string)$resultEx[0] !== '127') {
            continue;
        }

        $hasBlacklist = true;
        $typeBit = tornevall_dnsbl_combine_bitmasks([$typeBit, (int)$resultEx[3]]);
    }

    if ($hasBlacklist) {
        $newArray[] = [
            'ip' => $addr,
            'constants' => tornevall_dnsbl_decode_bitmask($typeBit),
            'typebit' => $typeBit,
            'deleted' => '0000-00-00 00:00:00',
        ];
    }

    return [
        'response' => [
            'requestResponse' => $newArray,
            'requestType' => 'DNS',
        ],
        'errorcode' => count($newArray) ? null : 404,
        'errorstring' => count($newArray) ? null : __('Nothing found as listed', 'tornevall-networks-dnsbl-implementation'),
    ];
}

/**
 * Check whether an IP address should be treated as blocked.
 *
 * @param string $addr             IP address.
 * @param bool   $getIsListed      Return raw bitmask instead of a boolean.
 * @param bool   $adminPassThrough Bypass the admin lockout protection.
 *
 * @return bool|int
 */
function dnsbl_check_blacklist($addr, $getIsListed = false, $adminPassThrough = false)
{
    $evaluation = tornevall_dnsbl_evaluate_blacklist_state($addr, $adminPassThrough);
    $bitMaskResponse = (int)$evaluation['bitmask'];
    if ($getIsListed) {
        return $bitMaskResponse;
    }

    $isListedByRequirements = !empty($evaluation['matches_selected_flags']);

    if ((is_admin() || current_user_can('administrator')) && !$adminPassThrough) {
        if ($isListedByRequirements) {
            add_action('admin_notices', 'dnsbl_is_protected_user');
        }

        return false;
    }

    return $isListedByRequirements;
}

/**
 * Render an admin notice when the current administrator is blacklisted.
 *
 * @return void
 */
function dnsbl_is_protected_user()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $showDnsblWarning = !$screen || $screen->id === 'toplevel_page_tornevallDnsblMenu';

    if ($showDnsblWarning) {
        ?>
        <div class="notice notice-error" style="font-weight:bold !important; background:#ffeeee; border:1px solid #990000; text-align:center;">
            <p>
                <?php echo esc_html__('Tornevall DNSBL scanner has detected that your current visiting ip address is blacklisted!', 'tornevall-networks-dnsbl-implementation'); ?>
                <br>
                <a href="<?php echo esc_url(tornevall_dnsbl_default_blocked_redirect_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('For more information, look here', 'tornevall-networks-dnsbl-implementation'); ?></a>
            </p>
        </div>
        <?php
    }
}

/**
 * Get the cache table name for the current site.
 *
 * @param wpdb $wpdb WordPress database abstraction.
 *
 * @return string
 */
function tornevall_dnsbl_get_cache_table_name($wpdb)
{
    return $wpdb->prefix . 'dnsblcache';
}

/**
 * Get the stats table name for the current site.
 *
 * @param wpdb $wpdb WordPress database abstraction.
 *
 * @return string
 */
function tornevall_dnsbl_get_stats_table_name($wpdb)
{
    return $wpdb->prefix . 'dnsblstats';
}

/**
 * Check whether a database table exists.
 *
 * @param wpdb   $wpdb      WordPress database abstraction.
 * @param string $tableName Fully qualified table name.
 *
 * @return bool
 */
function tornevall_dnsbl_table_exists($wpdb, $tableName)
{
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SHOW TABLES LIKE requires the table pattern as a runtime value.
    $existingTable = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName));

    return is_string($existingTable) && $existingTable === $tableName;
}

/**
 * Build a normalized evaluation payload for an IP address.
 *
 * @param string $addr             IP address.
 * @param bool   $adminPassThrough Bypass the admin lockout protection.
 *
 * @return array<string,mixed>
 */
function tornevall_dnsbl_evaluate_blacklist_state($addr, $adminPassThrough = false)
{
    $bitMaskResponse = (int)dnsbl_check_blacklist_cache($addr);
    $matchesSelectedFlags = $bitMaskResponse > 0 && tornevall_dnsbl_matches_selected_flags($bitMaskResponse);
    $isProtectedAdmin = (is_admin() || current_user_can('administrator')) && !$adminPassThrough;

    return [
        'bitmask' => $bitMaskResponse,
        'listed' => $bitMaskResponse > 0,
        'matches_selected_flags' => $matchesSelectedFlags,
        'blocked' => $matchesSelectedFlags && !$isProtectedAdmin,
        'admin_protected' => $isProtectedAdmin,
    ];
}

/**
 * Persist a DNSBL statistics event.
 *
 * @param string $addr            IP address.
 * @param int    $responseBitmask Combined blacklist bitmask.
 * @param bool   $wasBlocked      Whether the request was blocked.
 * @param string $source          Source identifier.
 *
 * @return void
 */
function tornevall_dnsbl_record_stat($addr, $responseBitmask, $wasBlocked, $source = 'request')
{
    global $wpdb;

    static $loggedEvents = [];

    if (!filter_var($addr, FILTER_VALIDATE_IP)) {
        return;
    }

    $source = sanitize_key((string)$source);
    if ($source === '') {
        $source = 'request';
    }

    $eventKey = md5($addr . '|' . (int)$responseBitmask . '|' . ((int)$wasBlocked) . '|' . $source);
    if (isset($loggedEvents[$eventKey])) {
        return;
    }

    $tableStats = tornevall_dnsbl_get_stats_table_name($wpdb);
    if (!tornevall_dnsbl_table_exists($wpdb, $tableStats)) {
        return;
    }

    $loggedEvents[$eventKey] = true;

    // resolveTime is retained from the historical schema and used for the
    // recorded blacklist response bitmask in statistics aggregation.
    $wpdb->insert(
        $tableStats,
        [
            'ipAddr' => $addr,
            'resolveTime' => (int)$responseBitmask,
            'wasBlocked' => $wasBlocked ? 1 : 0,
            'source' => $source,
            'createdAt' => current_time('mysql', true),
        ],
        ['%s', '%d', '%d', '%s', '%s']
    );
}

/**
 * Get aggregated DNSBL statistics for admin display.
 *
 * @param int $lookbackHours Number of hours to include. Zero means all time.
 *
 * @return array<string,int|bool>
 */
function tornevall_dnsbl_get_stats_summary($lookbackHours = 0)
{
    global $wpdb;

    $summary = [
        'has_stats_table' => false,
        'has_cache_table' => false,
        'total_checks' => 0,
        'unique_visitors' => 0,
        'blacklist_hits' => 0,
        'blocked_requests' => 0,
        'blocked_unique_visitors' => 0,
        'cached_blacklist_entries' => 0,
    ];

    $tableStats = tornevall_dnsbl_get_stats_table_name($wpdb);
    $tableCache = tornevall_dnsbl_get_cache_table_name($wpdb);
    $summary['has_stats_table'] = tornevall_dnsbl_table_exists($wpdb, $tableStats);
    $summary['has_cache_table'] = tornevall_dnsbl_table_exists($wpdb, $tableCache);

    if ($summary['has_stats_table']) {
        if ((int)$lookbackHours > 0) {
            $since = gmdate('Y-m-d H:i:s', time() - ((int)$lookbackHours * HOUR_IN_SECONDS));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
            $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total_checks, COUNT(DISTINCT ipAddr) AS unique_visitors, SUM(CASE WHEN resolveTime > 0 THEN 1 ELSE 0 END) AS blacklist_hits, SUM(CASE WHEN wasBlocked > 0 THEN 1 ELSE 0 END) AS blocked_requests, COUNT(DISTINCT CASE WHEN wasBlocked > 0 THEN ipAddr ELSE NULL END) AS blocked_unique_visitors FROM {$tableStats} WHERE source <> %s AND createdAt >= %s", 'admin-request', $since), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
            $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total_checks, COUNT(DISTINCT ipAddr) AS unique_visitors, SUM(CASE WHEN resolveTime > 0 THEN 1 ELSE 0 END) AS blacklist_hits, SUM(CASE WHEN wasBlocked > 0 THEN 1 ELSE 0 END) AS blocked_requests, COUNT(DISTINCT CASE WHEN wasBlocked > 0 THEN ipAddr ELSE NULL END) AS blocked_unique_visitors FROM {$tableStats} WHERE source <> %s", 'admin-request'), ARRAY_A);
        }

        if (is_array($row)) {
            foreach (['total_checks', 'unique_visitors', 'blacklist_hits', 'blocked_requests', 'blocked_unique_visitors'] as $metricKey) {
                $summary[$metricKey] = isset($row[$metricKey]) ? (int)$row[$metricKey] : 0;
            }
        }
    }

    if ($summary['has_cache_table']) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
        $summary['cached_blacklist_entries'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tableCache} WHERE lastResponse > 0");
    }

    return $summary;
}

/**
 * Retrieve or refresh the cached blacklist bitmask for an IP address.
 *
 * @param string $addr IP address.
 *
 * @return int
 */
function dnsbl_check_blacklist_cache($addr)
{
    global $wpdb;

    if (!filter_var($addr, FILTER_VALIDATE_IP)) {
        return 0;
    }

    $cacheAge = (int)get_option('tornevall_dnsbl_cache_age');
    if ($cacheAge < 900) {
        $cacheAge = 900;
    }

    $tableCache = tornevall_dnsbl_get_cache_table_name($wpdb);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tableCache} WHERE ipAddr = %s", $addr));

    if (!$existing || !isset($existing->ipAddr)) {
        $typeBit = (int)tornevall_dnsbl_build_lookup_result($addr, dnsbl_resolve_addr($addr))['typebit'];

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$tableCache} (ipAddr, lastResponse, lastResolve) VALUES (%s, %d, %d)",
            $addr,
            $typeBit,
            time()
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $typeBit;
    }

    $lastRes = time() - (int)(isset($existing->lastResolve) ? $existing->lastResolve : 0);
    if ($lastRes >= $cacheAge) {
        $typeBit = (int)tornevall_dnsbl_build_lookup_result($addr, dnsbl_resolve_addr($addr))['typebit'];

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$tableCache} SET lastResponse = %d, lastResolve = %d WHERE ipAddr = %s",
            $typeBit,
            time(),
            $addr
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $typeBit;
    }

    return (int)$existing->lastResponse;
}

/**
 * Mark comments as spam when DNSBL or Tools says the author should be blocked.
 *
 * @param string|int              $approved    Approval status.
 * @param array<string,mixed>     $commentdata Comment data.
 *
 * @return string|int
 */
function tornevall_dnsbl_pre_comment_approved($approved, $commentdata)
{
    $ip = isset($commentdata['comment_author_IP']) ? (string)$commentdata['comment_author_IP'] : '';
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return $approved;
    }

    $evaluation = tornevall_dnsbl_evaluate_blacklist_state($ip, true);
    tornevall_dnsbl_record_stat($ip, (int)$evaluation['bitmask'], !empty($evaluation['blocked']), 'comment-submit');

    if (!empty($evaluation['blocked'])) {
        return 'spam';
    }

    $toolsAssessment = tornevall_dnsbl_tools_assess_comment($ip, is_array($commentdata) ? $commentdata : []);
    if (!empty($toolsAssessment['blocked'])) {
        tornevall_dnsbl_record_stat($ip, (int)$evaluation['bitmask'], true, 'tools-comment-submit');
        return 'spam';
    }

    return $approved;
}
