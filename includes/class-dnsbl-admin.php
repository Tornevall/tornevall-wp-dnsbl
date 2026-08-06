<?php

namespace Tornevall\Networks\DNSBL;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

class Admin
{
    public static function registerMenu(): void
    {
        add_menu_page(
                'Tornevall DNSBL Options',
                __('Tornevall DNSBL', 'tornevall-networks-dnsbl-implementation'),
                'manage_options',
                'tornevallDnsblMenu',
                [self::class, 'renderOptionsPage'],
                'dashicons-shield-alt'
        );
    }

    public static function sanitizeCheckbox($value): string
    {
        return empty($value) ? '0' : '1';
    }

    public static function sanitizeCacheAge($value): int
    {
        $cacheAge = absint($value);
        return $cacheAge < Plugin::minimumCacheAge() ? Plugin::defaultCacheAge() : $cacheAge;
    }

    public static function sanitizeCacheCleanupInterval($value): int
    {
        $interval = absint($value);
        return $interval < Plugin::minimumCleanupInterval() ? Plugin::defaultCleanupInterval() : $interval;
    }

    public static function sanitizeResolverHosts($value): string
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        $parts = preg_split('/[\s,]+/', strtolower((string)$value));
        $hosts = [];
        foreach ((array)$parts as $part) {
            $host = trim($part);
            if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
                continue;
            }
            $hosts[] = $host;
        }

        $hosts = array_values(array_unique($hosts));
        if (!count($hosts)) {
            $hosts = Plugin::defaultResolvers();
        }

        return implode(',', $hosts);
    }

    public static function sanitizeFilterTypes($value): array
    {
        return Plugin::normalizeSelectedFlags($value);
    }

    public static function sanitizeRedirectUrl($value): string
    {
        return Plugin::canonicalBlockedRedirectUrl(esc_url_raw(trim((string)$value)));
    }

    public static function sanitizeCommentsStyle($value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            $value = Plugin::defaultCommentsDisabledStyle();
        }

        return sanitize_text_field($value);
    }

    public static function sanitizeDelistingPageSelection($value): string
    {
        $selection = Plugin::canonicalDelistingPageSelection($value);
        if ($selection === '') {
            return '';
        }

        $currentSelection = Plugin::configuredDelistingPageSelection();
        $currentToken = Plugin::writeToken();
        $currentMode = Plugin::toolsMode();

        $postedToken = isset($_POST['tornevall_dnsbl_write_token'])
                ? self::sanitizeDnsblWriteToken(wp_unslash($_POST['tornevall_dnsbl_write_token']))
                : $currentToken;
        $postedMode = isset($_POST['tornevall_dnsbl_tools_mode'])
                ? self::sanitizeToolsMode(wp_unslash($_POST['tornevall_dnsbl_tools_mode']))
                : $currentMode;

        $selectionChanged = $selection !== $currentSelection;
        $tokenChanged = $postedToken !== $currentToken;
        $modeChanged = $postedMode !== $currentMode;

        if (!$selectionChanged && !$tokenChanged && !$modeChanged) {
            return $selection;
        }

        $baseUrl = $postedMode === 'dev'
                ? 'https://tools.tornevall.com'
                : 'https://tools.tornevall.net';
        $permissionSummary = Plugin::getWritePermissionSummary(true, $postedToken, $baseUrl);

        if (!empty($permissionSummary['can_delete'])) {
            return $selection;
        }

        add_settings_error(
                'dnsblOptions-group',
                'tornevall_dnsbl_delisting_page_permission',
                self::buildDelistingPagePermissionMessage($permissionSummary, $baseUrl),
                'warning'
        );

        // Hard gate: keep the page rendering active but warn that live removal is unavailable.
        return $selection;
    }

    public static function sanitizeDnsblWriteToken($value): string
    {
        $token = trim(sanitize_text_field((string)$value));

        // Accept common copy/paste formats from docs/tools, e.g.
        // "Bearer <token>" or quoted token strings.
        if (stripos($token, 'bearer ') === 0) {
            $token = trim(substr($token, 7));
        }

        $len = strlen($token);
        if ($len >= 2 && ((substr($token, 0, 1) === '"' && substr($token, -1) === '"')
                        || (substr($token, 0, 1) === "'" && substr($token, -1) === "'"))) {
            $token = trim(substr($token, 1, -1));
        }

        return $token;
    }

    public static function sanitizeToolsMode($value): string
    {
        return Plugin::canonicalToolsMode($value);
    }

    private static function buildDelistingPagePermissionMessage(array $permissionSummary, string $baseUrl): string
    {
        $detail = trim((string)($permissionSummary['message'] ?? ''));
        $prefix = sprintf(
                __('The selected delisting page was saved, but this site cannot offer live removal right now because Tornevall Networks/FraudBL delete permission is missing on %s.', 'tornevall-networks-dnsbl-implementation'),
                $baseUrl
        );

        return $detail !== '' ? $prefix . ' ' . $detail : $prefix;
    }

    public static function sanitizeInternalDelistSlug($value): string
    {
        $current = Plugin::internalDelistSlug();
        $sanitized = Plugin::sanitizeInternalDelistSlug($value);

        if ($sanitized !== $current) {
            Plugin::refreshInternalDelistRewriteRules(false);
        }

        return $sanitized;
    }

    public static function sanitizeTurnstileTheme($value): string
    {
        return Plugin::normalizeCommentTurnstileTheme($value);
    }

    public static function sanitizeWhitelist($value): string
    {
        return implode("\n", Plugin::parseWhitelistEntries($value));
    }

    public static function registerSettings(): void
    {
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_cache_age', ['sanitize_callback' => [self::class, 'sanitizeCacheAge']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_cache_cleanup_interval', ['sanitize_callback' => [self::class, 'sanitizeCacheCleanupInterval']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_filter_types', ['sanitize_callback' => [self::class, 'sanitizeFilterTypes']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_nocomment', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_blockfull', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_delisting_page', ['sanitize_callback' => [self::class, 'sanitizeDelistingPageSelection']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_internal_delist_slug', ['sanitize_callback' => [self::class, 'sanitizeInternalDelistSlug']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_resolver_hosts', ['sanitize_callback' => [self::class, 'sanitizeResolverHosts']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_whitelist', ['sanitize_callback' => [self::class, 'sanitizeWhitelist']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_blocked_redirecturl', ['sanitize_callback' => [self::class, 'sanitizeRedirectUrl']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_comments_disabled_style', ['sanitize_callback' => [self::class, 'sanitizeCommentsStyle']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_delistingpage_comments_disabled', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_dev_mode', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_tools_token', ['sanitize_callback' => [self::class, 'sanitizeToolsBearerToken']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_tools_mode', ['sanitize_callback' => [self::class, 'sanitizeToolsMode']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_write_token', ['sanitize_callback' => [self::class, 'sanitizeDnsblWriteToken']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_auto_report_spam', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_comment_turnstile_enabled', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_comment_turnstile_site_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_comment_turnstile_secret_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_comment_turnstile_theme', ['sanitize_callback' => [self::class, 'sanitizeTurnstileTheme']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_removal_turnstile_enabled', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_removal_turnstile_fail_open', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_registration_dnsbl_enabled', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_registration_turnstile_enabled', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_woocommerce_checkout_enabled', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
    }

    public static function sanitizeToolsBearerToken($value): string
    {
        return trim(sanitize_text_field((string)$value));
    }

    public static function ajaxTokenInfo(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tornevall-networks-dnsbl-implementation')], 403);
        }

        check_ajax_referer('tornevall_dnsbl_tools_action', 'tornevall_dnsbl_tools_nonce');

        $postedToken = isset($_POST['tornevall_dnsbl_write_token'])
                ? trim(sanitize_text_field(wp_unslash($_POST['tornevall_dnsbl_write_token'])))
                : '';
        $postedMode = isset($_POST['tornevall_dnsbl_tools_mode'])
                ? Plugin::canonicalToolsMode(sanitize_text_field(wp_unslash($_POST['tornevall_dnsbl_tools_mode'])))
                : '';

        $token = $postedToken !== '' ? $postedToken : Plugin::writeToken();
        $currentMode = $postedMode !== '' ? $postedMode : Plugin::toolsMode();
        $currentBaseUrl = $currentMode === 'dev'
                ? 'https://tools.tornevall.com'
                : 'https://tools.tornevall.net';

        if ($token === '') {
            $summary = ApiClient::emptyTokenPermissionSummary(__('No token configured.', 'tornevall-networks-dnsbl-implementation'));
            wp_send_json_success([
                    'verified' => false,
                    'summary' => $summary,
                    'message' => $summary['message'],
                    'checked_host' => $currentBaseUrl,
                    'checked_mode' => $currentMode,
                    'rendered_status_html' => self::renderTokenPermissionStatusMarkup($summary, $currentBaseUrl),
            ]);
        }

        $client = new ApiClient($token, $currentBaseUrl);

        $result = $client->getTokenInfo();
        $summary = ApiClient::normalizeTokenInfoResult($result, true);

        if (!$result['ok']) {
            if ((int)($result['status'] ?? 0) === 404 && $token !== '') {
                $alternateMode = $currentMode === 'dev' ? 'prod' : 'dev';
                $alternateBaseUrl = $alternateMode === 'dev'
                        ? 'https://tools.tornevall.com'
                        : 'https://tools.tornevall.net';
                $alternateClient = new ApiClient($token, $alternateBaseUrl);
                $alternateResult = $alternateClient->getTokenInfo();

                if (!empty($alternateResult['ok'])) {
                    $alternateSummary = ApiClient::normalizeTokenInfoResult($alternateResult, true);
                    $message = self::buildTokenEnvironmentMismatchMessage($currentMode, $currentBaseUrl, $alternateMode, $alternateBaseUrl);
                    $alternateSummary['message'] = $message;

                    wp_send_json_success([
                            'verified' => true,
                            'summary' => $alternateSummary,
                            'message' => $message,
                            'checked_host' => $currentBaseUrl,
                            'checked_mode' => $currentMode,
                            'resolved_host' => $alternateBaseUrl,
                            'resolved_mode' => $alternateMode,
                            'environment_mismatch' => true,
                            'rendered_status_html' => self::renderTokenPermissionStatusMarkup($alternateSummary, $alternateBaseUrl),
                    ]);
                }

                $summary['message'] = self::buildTokenNotFoundMessage($currentMode, $currentBaseUrl);
                $summary['error'] = $summary['message'];
            }
        }

        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $reason = (string)($body['reason'] ?? '');

        wp_send_json_success([
                'verified' => !empty($summary['ok']),
                'summary' => $summary,
                'message' => (string)($summary['message'] ?? __('Could not retrieve token info.', 'tornevall-networks-dnsbl-implementation')),
                'checked_host' => $currentBaseUrl,
                'checked_mode' => $currentMode,
                'wrong_token_type' => $reason === 'wrong_token_type',
                'raw_body' => $body,
                'rendered_status_html' => self::renderTokenPermissionStatusMarkup($summary, $currentBaseUrl),
        ]);
    }

    /**
     * @param array{has_token?:bool,ok?:bool,can_add?:bool,can_delete?:bool,can_update?:bool,message?:string,token?:array,is_active?:bool} $summary
     */
    private static function renderTokenPermissionStatusMarkup(array $summary, string $toolsBaseUrl): string
    {
        $status = self::describeTokenPermissionSummary($summary);
        $detail = trim((string)($status['detail'] ?? ''));
        $badge = trim((string)($status['badge'] ?? ''));

        ob_start();
        ?>
        <div id="tornevall-dnsbl-token-status"
             data-can-delete="<?php echo !empty($summary['can_delete']) ? '1' : '0'; ?>"
             style="margin-top:8px; padding:.7rem .8rem; border-radius:8px; border:1px solid #d1d5db; background:#f8fafc;">
            <div style="display:flex; flex-wrap:wrap; gap:.45rem .65rem; align-items:center;">
                <strong style="color:<?php echo esc_attr((string)$status['color']); ?>;">
                    <?php echo esc_html((string)$status['label']); ?>
                </strong>
                <?php if ($badge !== '') { ?>
                    <span style="display:inline-block; padding:.12rem .5rem; border-radius:999px; background:#e2e8f0; color:#334155; font-size:12px; font-weight:600;">
                        <?php echo esc_html($badge); ?>
                    </span>
                <?php } ?>
            </div>
            <?php if ($detail !== '') { ?>
                <div style="margin-top:.35rem; color:#475569;">
                    <?php echo esc_html($detail); ?>
                </div>
            <?php } ?>
            <div style="margin-top:.45rem; color:#64748b; font-size:12px;">
                <?php echo esc_html(sprintf(__('Current Tools host: %s', 'tornevall-networks-dnsbl-implementation'), $toolsBaseUrl)); ?>
            </div>
            <?php if (!empty($summary['ok']) || !empty($summary['has_token'])) { ?>
                <div style="margin-top:.45rem; color:#475569; font-size:12px;">
                    <?php echo esc_html(sprintf(
                            __('Permissions: add %1$s · delete %2$s · update %3$s', 'tornevall-networks-dnsbl-implementation'),
                            !empty($summary['can_add']) ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'),
                            !empty($summary['can_delete']) ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'),
                            !empty($summary['can_update']) ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation')
                    )); ?>
                </div>
                <?php $cidrFloor = isset($summary['delete_guardrails']['delete_min_cidr_prefix']) ? (int)$summary['delete_guardrails']['delete_min_cidr_prefix'] : 0; ?>
                <div style="margin-top:.35rem; color:#475569; font-size:12px;">
                    <?php echo esc_html(
                            !empty($summary['can_cidr_delete'])
                                    ? sprintf(__('CIDR delist range: /%1$d to /32', 'tornevall-networks-dnsbl-implementation'), $cidrFloor >= 24 ? $cidrFloor : 24)
                                    : __('CIDR delist is not delegated for this token.', 'tornevall-networks-dnsbl-implementation')
                    ); ?>
                </div>
            <?php } ?>
        </div>
        <?php

        return (string)ob_get_clean();
    }

    /**
     * @param array{has_token?:bool,ok?:bool,can_add?:bool,can_delete?:bool,can_update?:bool,message?:string,token?:array,is_active?:bool} $summary
     * @return array{label:string,color:string,detail:string,badge:string}
     */
    private static function describeTokenPermissionSummary(array $summary): array
    {
        $token = is_array($summary['token'] ?? null) ? $summary['token'] : [];
        $scopeLabel = trim((string)($token['scope_label'] ?? ''));
        $statusLabel = trim((string)($token['status'] ?? ''));
        $baseDetail = trim((string)($summary['message'] ?? ''));
        $badge = '';

        if (!empty($token['resolved_via']) && (string)$token['resolved_via'] === 'admin_api_key_passthrough') {
            $badge = __('Admin passthrough', 'tornevall-networks-dnsbl-implementation');
        } elseif (!empty($token['is_admin_token'])) {
            $badge = __('Admin token', 'tornevall-networks-dnsbl-implementation');
        }

        if (empty($summary['has_token'])) {
            return [
                    'label' => __('No DNSBL / Tools API token configured yet.', 'tornevall-networks-dnsbl-implementation'),
                    'color' => '#b45309',
                    'detail' => $baseDetail,
                    'badge' => $badge,
            ];
        }

        if (!empty($summary['can_delete'])) {
            return [
                    'label' => __('Live delisting is available for this site.', 'tornevall-networks-dnsbl-implementation'),
                    'color' => '#15803d',
                    'detail' => $scopeLabel !== ''
                            ? sprintf(__('Delete / delist confirmed. Scope: %s', 'tornevall-networks-dnsbl-implementation'), $scopeLabel)
                            : __('Delete / delist confirmed for the configured token.', 'tornevall-networks-dnsbl-implementation'),
                    'badge' => $badge,
            ];
        }

        if (!empty($summary['ok']) && !empty($summary['can_add'])) {
            $detail = __('The token is verified, but only add / list access is active right now.', 'tornevall-networks-dnsbl-implementation');
            if ($scopeLabel !== '') {
                $detail .= ' ' . sprintf(__('Scope: %s', 'tornevall-networks-dnsbl-implementation'), $scopeLabel);
            }

            return [
                    'label' => __('Token verified, but delisting is not allowed yet.', 'tornevall-networks-dnsbl-implementation'),
                    'color' => '#b45309',
                    'detail' => $detail,
                    'badge' => $badge,
            ];
        }

        if (!empty($summary['ok'])) {
            return [
                    'label' => __('Delisting is still locked for this site.', 'tornevall-networks-dnsbl-implementation'),
                    'color' => '#b45309',
                    'detail' => $baseDetail !== ''
                            ? $baseDetail
                            : ($statusLabel !== ''
                                    ? sprintf(__('The token is currently %s and does not expose delete / delist access.', 'tornevall-networks-dnsbl-implementation'), $statusLabel)
                                    : __('The token was checked, but delete / delist access is still unavailable.', 'tornevall-networks-dnsbl-implementation')),
                    'badge' => $badge,
            ];
        }

        return [
                'label' => __('Token permissions have not been confirmed yet.', 'tornevall-networks-dnsbl-implementation'),
                'color' => '#b91c1c',
                'detail' => $baseDetail !== '' ? $baseDetail : __('Run the permission check to confirm whether this token can delist through Tools.', 'tornevall-networks-dnsbl-implementation'),
                'badge' => $badge,
        ];
    }

    private static function buildTokenEnvironmentMismatchMessage(string $currentMode, string $currentBaseUrl, string $alternateMode, string $alternateBaseUrl): string
    {
        return sprintf(
                __('Token not found on the currently selected Tools environment (%1$s / %2$s), but it was found on the other environment (%3$s / %4$s). Switch Tools environment mode to %3$s, save the settings, and try again.', 'tornevall-networks-dnsbl-implementation'),
                strtoupper($currentMode),
                $currentBaseUrl,
                strtoupper($alternateMode),
                $alternateBaseUrl
        );
    }

    private static function buildTokenNotFoundMessage(string $currentMode, string $currentBaseUrl): string
    {
        return sprintf(
                __('Token not found on the selected Tools environment (%1$s / %2$s). Check that you pasted the full token value and that you selected the same Tools host where the token was created.', 'tornevall-networks-dnsbl-implementation'),
                strtoupper($currentMode),
                $currentBaseUrl
        );
    }

    public static function ajaxTools(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                    'message' => __('You do not have permission to run this tool.', 'tornevall-networks-dnsbl-implementation'),
            ], 403);
        }

        check_ajax_referer('tornevall_dnsbl_tools_action', 'tornevall_dnsbl_tools_nonce');

        $toolAction = isset($_POST['tornevall_dnsbl_tool_action']) ? sanitize_text_field(wp_unslash($_POST['tornevall_dnsbl_tool_action'])) : '';
        $toolResults = self::getToolResults($toolAction, $_POST);
        if (is_wp_error($toolResults)) {
            wp_send_json_error([
                    'message' => $toolResults->get_error_message(),
            ], $toolResults->get_error_code() === 'self-check-empty' ? 422 : 400);
        }

        $resolverNames = Plugin::getResolverHosts();
        $devMode = get_option('tornevall_dnsbl_dev_mode') === '1';

        wp_send_json_success([
                'html' => self::renderToolResultsMarkup($toolResults, $devMode, $resolverNames),
        ]);
    }

    public static function getToolResults($action, $request = [])
    {
        if ($action === 'lookup_ip') {
            $ip = isset($request['tornevall_dnsbl_lookup_ip']) ? trim(sanitize_text_field(wp_unslash($request['tornevall_dnsbl_lookup_ip']))) : '';
            if ($ip === '') {
                return new WP_Error('lookup-empty', __('Address must not be empty', 'tornevall-networks-dnsbl-implementation'));
            }

            return [
                    'title' => __('Manual DNS lookup result', 'tornevall-networks-dnsbl-implementation'),
                    'rows' => [
                            __('Requested address', 'tornevall-networks-dnsbl-implementation') => self::runLookup($ip),
                    ],
            ];
        }

        if ($action === 'self_check') {
            $rows = [];
            foreach (self::getSelfCheckCandidates() as $label => $ip) {
                $rows[$label] = self::runLookup($ip);
            }

            if (!count($rows)) {
                return new WP_Error('self-check-empty', __('No valid local addresses were available for self-check.', 'tornevall-networks-dnsbl-implementation'));
            }

            return [
                    'title' => __('Self-check result', 'tornevall-networks-dnsbl-implementation'),
                    'rows' => $rows,
            ];
        }

        return new WP_Error('unknown-tool-action', __('Unknown tool request.', 'tornevall-networks-dnsbl-implementation'));
    }

    public static function runLookup($ip): array
    {
        return Plugin::buildLookupResult($ip);
    }

    public static function getSelfCheckCandidates(): array
    {
        $candidates = [];
        $siteHost = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $serverAddress = isset($_SERVER['SERVER_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_ADDR'])) : '';
        $remoteAddress = Plugin::currentVisitorIp();

        if ($serverAddress && filter_var($serverAddress, FILTER_VALIDATE_IP)) {
            $candidates[__('Server address', 'tornevall-networks-dnsbl-implementation')] = $serverAddress;
        }
        if ($siteHost) {
            $resolvedSiteIp = gethostbyname($siteHost);
            if ($resolvedSiteIp && $resolvedSiteIp !== $siteHost && filter_var($resolvedSiteIp, FILTER_VALIDATE_IP)) {
                $candidates[__('Resolved site host', 'tornevall-networks-dnsbl-implementation')] = $resolvedSiteIp;
            }
        }
        if ($remoteAddress && filter_var($remoteAddress, FILTER_VALIDATE_IP)) {
            $candidates[__('Current visitor address', 'tornevall-networks-dnsbl-implementation')] = $remoteAddress;
        }

        return array_unique($candidates);
    }

    public static function renderToolResultsMarkup($toolResults, $devMode, $resolverNames): string
    {
        ob_start();
        self::renderToolResults($toolResults, $devMode, $resolverNames);
        return trim((string)ob_get_clean());
    }

    public static function renderToolResults($toolResults, $devMode, $resolverNames): void
    {
        if (!$toolResults || empty($toolResults['rows']) || !is_array($toolResults['rows'])) {
            return;
        }
        ?>
        <div class="postbox" style="margin-top:16px;">
            <div class="inside">
                <h2 style="margin-top:0;"><?php echo esc_html($toolResults['title']); ?></h2>
                <p class="description"><?php echo esc_html(sprintf(__('Resolvers used for this check: %s', 'tornevall-networks-dnsbl-implementation'), implode(', ', $resolverNames))); ?></p>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php echo esc_html__('Check', 'tornevall-networks-dnsbl-implementation'); ?></th>
                        <th><?php echo esc_html__('Address', 'tornevall-networks-dnsbl-implementation'); ?></th>
                        <th><?php echo esc_html__('Status', 'tornevall-networks-dnsbl-implementation'); ?></th>
                        <th><?php echo esc_html__('Details', 'tornevall-networks-dnsbl-implementation'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($toolResults['rows'] as $label => $row) { ?>
                        <tr>
                            <td><strong><?php echo esc_html($label); ?></strong></td>
                            <td><code><?php echo esc_html($row['ip']); ?></code></td>
                            <td>
                                <strong style="color:<?php echo $row['listed'] ? '#b91c1c' : '#15803d'; ?>;"><?php echo esc_html($row['message']); ?></strong>
                            </td>
                            <td>
                                <?php if (!empty($row['constants'])) { ?>
                                    <div><?php echo esc_html(sprintf(__('Matched flags: %s', 'tornevall-networks-dnsbl-implementation'), implode(', ', $row['constants']))); ?></div>
                                    <div class="description"><?php echo esc_html(sprintf(__('Bitmask: %d', 'tornevall-networks-dnsbl-implementation'), (int)$row['typebit'])); ?></div>
                                <?php } else { ?>
                                    <span class="description"><?php echo esc_html__('No blacklist flags returned for this address.', 'tornevall-networks-dnsbl-implementation'); ?></span>
                                <?php } ?>
                                <?php if ($devMode && !empty($row['raw'])) { ?>
                                    <details style="margin-top:8px;">
                                        <summary><?php echo esc_html__('Raw diagnostic response', 'tornevall-networks-dnsbl-implementation'); ?></summary>
                                        <pre style="white-space:pre-wrap; word-break:break-word; background:#f6f7f7; padding:10px; border:1px solid #dcdcde;"><?php echo esc_html(Plugin::formatDiagnosticPayload($row['raw'])); ?></pre>
                                    </details>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public static function renderLookupToolFields(): void
    {
        ?>
        <label for="tornevall_dnsbl_lookup_ip"
               class="screen-reader-text"><?php echo esc_html__('IP address to test', 'tornevall-networks-dnsbl-implementation'); ?></label>
        <input type="text" class="regular-text" id="tornevall_dnsbl_lookup_ip" name="tornevall_dnsbl_lookup_ip"
               placeholder="203.0.113.10">
        <?php
    }

    public static function renderOptionsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'tornevall-networks-dnsbl-implementation'));
        }

        self::ensureDefaults();

        $currentDelistingPage = Plugin::configuredDelistingPageSelection();
        $delistPageOption = self::buildPageOptions($currentDelistingPage);
        $internalDelistSlug = Plugin::internalDelistSlug();
        $internalDelistUrl = Plugin::internalDelistUrl();
        $resolverNames = Plugin::getResolverHosts();
        $currentFlags = Plugin::getCurrentFlagMap();
        $savedFlags = Plugin::getSelectedFlags();

        $flagListSelector = [];
        foreach ($currentFlags as $flag => $bitValue) {
            $flagListSelector[] = '<option value="' . esc_attr($flag) . '" ' . selected(in_array($flag, $savedFlags, true), true, false) . '>' . esc_html($flag . ' [' . $bitValue . ']') . '</option>';
        }

        $cacheAge = Plugin::getCacheTtl();
        $cacheCleanupInterval = Plugin::getCacheCleanupInterval();
        $redirectUrl = Plugin::getBlockedRedirectUrl();
        $commentsStyle = Plugin::getCommentsDisabledStyle();
        $devMode = get_option('tornevall_dnsbl_dev_mode') === '1';
        $apiToken = Plugin::apiToken();
        $toolsMode = Plugin::canonicalToolsMode(get_option('tornevall_dnsbl_tools_mode'));
        $apiTokenSet = $apiToken !== '';
        $autoReportSpam = Plugin::autoReportSpamEnabled();
        $turnstileEnabled = get_option('tornevall_dnsbl_comment_turnstile_enabled') === '1';
        $turnstileSiteKey = Plugin::commentTurnstileSiteKey();
        $turnstileSecretKey = Plugin::commentTurnstileSecretKey();
        $turnstileTheme = Plugin::commentTurnstileTheme();
        $removalTurnstileRequested = Plugin::removalTurnstileRequested();
        $removalTurnstileFailOpen = Plugin::removalTurnstileFailOpenEnabled();
        $removalTurnstileActive = Plugin::removalTurnstileEnabled();
        $registrationDnsblEnabled = Plugin::registrationDnsblEnabled();
        $registrationTurnstileEnabled = get_option('tornevall_dnsbl_registration_turnstile_enabled') === '1';
        $whitelistEntries = Plugin::getWhitelistEntries();
        $whitelistValue = implode("\n", $whitelistEntries);
        $currentVisitorAddress = Plugin::currentVisitorIp();
        $currentVisitorWhitelisted = $currentVisitorAddress && Plugin::isWhitelistedIp($currentVisitorAddress);
        $resolvedToolsBase = Plugin::toolsBaseUrl();
        $tokenPermissionSummary = Plugin::getWritePermissionSummary();
        $delistingAccessConfirmed = !empty($tokenPermissionSummary['can_delete']);
        $tokenStatusHtml = self::renderTokenPermissionStatusMarkup($tokenPermissionSummary, $resolvedToolsBase);
        $settingsOverviewNotice = self::buildSettingsOverviewNotice(
                $resolverNames,
                $resolvedToolsBase,
                $toolsMode,
                $apiTokenSet,
                $tokenPermissionSummary,
                $delistingAccessConfirmed,
                Plugin::getStatsSummary()
        );
        $toolResults = self::handleToolsRequest();
        $statsSummary = Plugin::getStatsSummary();
        $statsSummary24h = Plugin::getStatsSummary(24);
        $lastCleanupLabel = !empty($statsSummary['last_cache_cleanup'])
                ? sprintf(
                        __('Last cache cleanup: %s', 'tornevall-networks-dnsbl-implementation'),
                        wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int)$statsSummary['last_cache_cleanup'])
                )
                : __('Last cache cleanup: not yet recorded', 'tornevall-networks-dnsbl-implementation');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('DNS Blacklist Configurator', 'tornevall-networks-dnsbl-implementation'); ?></h1>

            <?php settings_errors(); ?>
            <?php settings_errors('tornevall_dnsbl_admin_tools'); ?>

            <?php if ($settingsOverviewNotice !== null) {
                echo self::renderSettingsOverviewNotice($settingsOverviewNotice); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated by helper with escaped content.
            } ?>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin:16px 0;">
                <div class="postbox">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('At a glance', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <ul style="margin:0; padding-left:18px;">
                            <li><?php echo esc_html(sprintf(__('Resolver count: %d', 'tornevall-networks-dnsbl-implementation'), count($resolverNames))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Trigger flags selected: %d', 'tornevall-networks-dnsbl-implementation'), count($savedFlags))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Whitelist entries configured: %d', 'tornevall-networks-dnsbl-implementation'), count($whitelistEntries))); ?></li>
                            <li><?php echo esc_html(sprintf(__('DNSBL / Tools API token configured: %s', 'tornevall-networks-dnsbl-implementation'), $apiTokenSet ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Delete / delist permission confirmed: %s', 'tornevall-networks-dnsbl-implementation'), $delistingAccessConfirmed ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Comment Turnstile enabled: %s', 'tornevall-networks-dnsbl-implementation'), $turnstileEnabled ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Removal page Turnstile enabled: %s', 'tornevall-networks-dnsbl-implementation'), $removalTurnstileActive ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Removal-page Turnstile automatic bypass enabled: %s', 'tornevall-networks-dnsbl-implementation'), $removalTurnstileFailOpen ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Registration DNSBL enabled: %s', 'tornevall-networks-dnsbl-implementation'), $registrationDnsblEnabled ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Registration Turnstile enabled: %s', 'tornevall-networks-dnsbl-implementation'), $registrationTurnstileEnabled ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('WooCommerce checkout protection: %s', 'tornevall-networks-dnsbl-implementation'), Plugin::woocommerceCheckoutEnabled() ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Dev mode: %s', 'tornevall-networks-dnsbl-implementation'), $devMode ? __('enabled', 'tornevall-networks-dnsbl-implementation') : __('disabled', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <?php if ($currentVisitorAddress && filter_var($currentVisitorAddress, FILTER_VALIDATE_IP)) { ?>
                                <li><?php echo esc_html(sprintf(__('Current visitor address is whitelisted: %s', 'tornevall-networks-dnsbl-implementation'), $currentVisitorWhitelisted ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <?php } ?>
                        </ul>
                    </div>
                </div>
                <div class="postbox">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Visitor statistics', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php if (!empty($statsSummary['has_stats_table'])) { ?>
                            <ul style="margin:0; padding-left:18px;">
                                <li><?php echo esc_html(sprintf(__('Visitor checks recorded: %d', 'tornevall-networks-dnsbl-implementation'), (int)$statsSummary['total_checks'])); ?></li>
                                <li><?php echo esc_html(sprintf(__('Unique visitor addresses seen: %d', 'tornevall-networks-dnsbl-implementation'), (int)$statsSummary['unique_visitors'])); ?></li>
                                <li><?php echo esc_html(sprintf(__('Blacklist hits recorded: %d', 'tornevall-networks-dnsbl-implementation'), (int)$statsSummary['blacklist_hits'])); ?></li>
                                <li><?php echo esc_html(sprintf(__('Blocked requests recorded: %d', 'tornevall-networks-dnsbl-implementation'), (int)$statsSummary['blocked_requests'])); ?></li>
                                <li><?php echo esc_html(sprintf(__('Unique blocked visitor addresses: %d', 'tornevall-networks-dnsbl-implementation'), (int)$statsSummary['blocked_unique_visitors'])); ?></li>
                                <li><?php echo esc_html(sprintf(__('Last 24 hours: %d checks / %d blocked', 'tornevall-networks-dnsbl-implementation'), (int)$statsSummary24h['total_checks'], (int)$statsSummary24h['blocked_requests'])); ?></li>
                                <?php if (!empty($statsSummary['has_cache_table'])) { ?>
                                    <li><?php echo esc_html(sprintf(__('Cached DNSBL entries currently stored: %d', 'tornevall-networks-dnsbl-implementation'), (int)$statsSummary['cached_entries'])); ?></li>
                                    <li><?php echo esc_html(sprintf(__('Cached blacklisted entries currently stored: %d', 'tornevall-networks-dnsbl-implementation'), (int)$statsSummary['cached_blacklist_entries'])); ?></li>
                                    <li><?php echo esc_html(sprintf(__('Cached clear / non-listed entries currently stored: %d', 'tornevall-networks-dnsbl-implementation'), (int)$statsSummary['cached_clear_entries'])); ?></li>
                                    <li><?php echo esc_html($lastCleanupLabel); ?></li>
                                <?php } ?>
                            </ul>
                        <?php } else { ?>
                            <p class="description"><?php echo esc_html__('The statistics table has not been created yet. Deactivate and reactivate the plugin if this persists after upgrade.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        <?php } ?>
                    </div>
                </div>
                <div class="postbox">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Plugin information and help', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <p><a href="<?php echo esc_url(TORNEVALL_DNSBL_PUBLIC_DOCS_URL); ?>" target="_blank"
                              rel="noopener noreferrer"><?php echo esc_html__('DNSBL plugin and endpoint documentation', 'tornevall-networks-dnsbl-implementation'); ?></a>
                        </p>
                        <p><a href="<?php echo esc_url(TORNEVALL_DNSBL_CHANGELOG_URL); ?>" target="_blank"
                              rel="noopener noreferrer"><?php echo esc_html__('Plugin changelog', 'tornevall-networks-dnsbl-implementation'); ?></a>
                        </p>
                        <p><a href="<?php echo esc_url(TORNEVALL_DNSBL_HISTORY_URL); ?>" target="_blank"
                              rel="noopener noreferrer"><?php echo esc_html__('Source history and diff trail', 'tornevall-networks-dnsbl-implementation'); ?></a>
                        </p>
                        <p><a href="<?php echo esc_url(TORNEVALL_DNSBL_ISSUES_URL); ?>" target="_blank"
                              rel="noopener noreferrer"><?php echo esc_html__('GitHub issue tracker', 'tornevall-networks-dnsbl-implementation'); ?></a>
                        </p>
                    </div>
                </div>
            </div>

            <div class="postbox" style="margin-top:16px;">
                <div class="inside">
                    <h2 style="margin-top:0;"><?php echo esc_html__('Try-tests and self-check', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                    <p class="description"><?php echo esc_html__('Use these tools to test direct DNS lookups without changing your saved settings.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px;">
                        <?php self::renderToolForm('lookup_ip', __('Lookup a specific IP address', 'tornevall-networks-dnsbl-implementation'), __('Run DNS lookup', 'tornevall-networks-dnsbl-implementation'), [self::class, 'renderLookupToolFields']); ?>
                        <?php self::renderToolForm('self_check', __('Check this server environment', 'tornevall-networks-dnsbl-implementation'), __('Run self-check', 'tornevall-networks-dnsbl-implementation')); ?>
                    </div>
                    <?php if ($toolResults) {
                        self::renderAjaxResultsContainer($toolResults, $devMode, $resolverNames);
                    } else {
                        self::renderAjaxNotice(__('Tool results will appear here without reloading the page.', 'tornevall-networks-dnsbl-implementation'), 'info');
                    } ?>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                <?php settings_fields('dnsblOptions-group'); ?>
                <?php do_settings_sections('dnsblOptions-group'); ?>

                <div class="postbox" style="margin-top:16px;">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Core DNS lookup settings', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label
                                            for="tornevall_dnsbl_resolver_hosts"><?php echo esc_html__('Preferred resolver hosts', 'tornevall-networks-dnsbl-implementation'); ?></label>
                                </th>
                                <td>
                                    <input type="text" class="regular-text" id="tornevall_dnsbl_resolver_hosts"
                                           name="tornevall_dnsbl_resolver_hosts"
                                           value="<?php echo esc_attr(implode(',', $resolverNames)); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                            for="tornevall_dnsbl_cache_age"><?php echo esc_html__('Cache age', 'tornevall-networks-dnsbl-implementation'); ?></label>
                                </th>
                                <td>
                                    <input type="number"
                                           min="<?php echo esc_attr((string)Plugin::minimumCacheAge()); ?>" step="60"
                                           id="tornevall_dnsbl_cache_age" name="tornevall_dnsbl_cache_age"
                                           value="<?php echo esc_attr((string)$cacheAge); ?>">
                                    <p class="description"><?php echo esc_html__('Cache both listed and non-listed IP lookups to avoid repeated DNS traffic. Recommended range: 5-10 minutes.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                            for="tornevall_dnsbl_cache_cleanup_interval"><?php echo esc_html__('Cache cleanup interval', 'tornevall-networks-dnsbl-implementation'); ?></label>
                                </th>
                                <td>
                                    <input type="number"
                                           min="<?php echo esc_attr((string)Plugin::minimumCleanupInterval()); ?>"
                                           step="60" id="tornevall_dnsbl_cache_cleanup_interval"
                                           name="tornevall_dnsbl_cache_cleanup_interval"
                                           value="<?php echo esc_attr((string)$cacheCleanupInterval); ?>">
                                    <p class="description"><?php echo esc_html__('Expired cache rows are purged automatically on this interval and also opportunistically during requests.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                            for="tornevall_dnsbl_filter_types"><?php echo esc_html__('Trigger on blacklist flags', 'tornevall-networks-dnsbl-implementation'); ?></label>
                                </th>
                                <td>
                                    <select multiple size="8" id="tornevall_dnsbl_filter_types"
                                            name="tornevall_dnsbl_filter_types[]"
                                            style="min-width:320px;"><?php echo implode("\n", $flagListSelector); ?></select>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="postbox" style="margin-top:16px;">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Protection behavior', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php
                        self::renderCheckboxRow('tornevall_dnsbl_nocomment', __('Hide comments for listed visitors', 'tornevall-networks-dnsbl-implementation'), __('Hides the comment section and blocks direct comment submission when a visitor matches the selected blacklist flags.', 'tornevall-networks-dnsbl-implementation'), (bool)get_option('tornevall_dnsbl_nocomment'));
                        self::renderCheckboxRow('tornevall_dnsbl_blockfull', __('Redirect listed visitors away from the page', 'tornevall-networks-dnsbl-implementation'), __('Immediately redirects listed visitors away from the page. wp-admin sessions are still protected from lockout.', 'tornevall-networks-dnsbl-implementation'), (bool)get_option('tornevall_dnsbl_blockfull'));
                        ?>
                        <p>
                            <label for="tornevall_dnsbl_whitelist"><?php echo esc_html__('Safe IP whitelist', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <textarea class="large-text code" rows="5" id="tornevall_dnsbl_whitelist"
                                      name="tornevall_dnsbl_whitelist"
                                      placeholder="203.0.113.10&#10;198.51.100.0/24"><?php echo esc_textarea($whitelistValue); ?></textarea>
                            <span class="description"
                                  style="display:block; margin-top:6px;"><?php echo esc_html__('Whitelisted IP addresses or CIDR ranges are still checked and can appear in statistics, but they will not be blocked, redirected or marked as spam. This is the safest way to dry-run DNSBL behaviour in a live WordPress environment.', 'tornevall-networks-dnsbl-implementation'); ?></span>
                            <?php if ($currentVisitorAddress && filter_var($currentVisitorAddress, FILTER_VALIDATE_IP)) { ?>
                                <span class="description"
                                      style="display:block; margin-top:4px;"><?php echo esc_html(sprintf(__('Current visitor address: %s', 'tornevall-networks-dnsbl-implementation'), $currentVisitorAddress)); ?></span>
                                <span class="description" style="display:block; margin-top:8px;">
                                    <?php if ($currentVisitorWhitelisted) {
                                        echo esc_html__('This address is already on the whitelist.', 'tornevall-networks-dnsbl-implementation');
                                    } else {
                                        echo Plugin::renderWhitelistCurrentVisitorButton(__('Add current visitor to whitelist', 'tornevall-networks-dnsbl-implementation'));
                                    } ?>
                                </span>
                            <?php } ?>
                        </p>
                        <p>
                            <label><?php echo esc_html__('Blocked visitor redirect URL', 'tornevall-networks-dnsbl-implementation'); ?>
                                <br><input type="url" class="regular-text" name="tornevall_dnsbl_blocked_redirecturl"
                                           value="<?php echo esc_attr($redirectUrl); ?>"></label></p>
                        <p>
                            <label><?php echo esc_html__('Admin notice style for comment blocking', 'tornevall-networks-dnsbl-implementation'); ?>
                                <br><input type="text" class="regular-text"
                                           name="tornevall_dnsbl_comments_disabled_style"
                                           value="<?php echo esc_attr($commentsStyle); ?>"></label></p>
                    </div>
                </div>

                <div class="postbox" style="margin-top:16px;">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Tools integration and development', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php self::renderCheckboxRow('tornevall_dnsbl_dev_mode', __('Show extended diagnostics in the admin panel', 'tornevall-networks-dnsbl-implementation'), __('Shows raw diagnostic responses in the try-test and self-check tools.', 'tornevall-networks-dnsbl-implementation'), $devMode); ?>
                        <p class="description"><?php echo esc_html__('Frontend dry run is only available when dev mode is enabled, Tools environment mode is set to dev, and you are logged in as an administrator on the public site. Use the admin-bar toggle there to simulate a blacklisted visitor safely without affecting wp-admin.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        <p>
                            <label for="tornevall_dnsbl_tools_mode"><?php echo esc_html__('Tools environment mode', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <select id="tornevall_dnsbl_tools_mode" name="tornevall_dnsbl_tools_mode">
                                <option value="dev" <?php selected($toolsMode, 'dev'); ?>><?php echo esc_html__('Force dev (tools.tornevall.com)', 'tornevall-networks-dnsbl-implementation'); ?></option>
                                <option value="prod" <?php selected($toolsMode, 'prod'); ?>><?php echo esc_html__('Prod default (tools.tornevall.net)', 'tornevall-networks-dnsbl-implementation'); ?></option>
                            </select>
                        </p>
                        <div>
                            <label for="tornevall_dnsbl_write_token"><?php echo esc_html__('DNSBL / Tools API token', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <input type="password" class="regular-text" id="tornevall_dnsbl_write_token"
                                   name="tornevall_dnsbl_write_token"
                                   value="<?php echo esc_attr($apiToken); ?>">
                            <span class="description"
                                  style="display:block; margin-top:4px;"><?php echo esc_html__('Single token used by the plugin for DNSBL/Tools API flows. The live checker asks Tools directly. If the token belongs to a Tools admin, automatic DNSBL access is reported. Non-admin tokens need DNSBL permissions approved on the Tools side.', 'tornevall-networks-dnsbl-implementation'); ?></span>
                            <button type="button" id="tornevall-dnsbl-check-token-btn"
                                    class="button button-secondary"
                                    style="margin-top:8px; vertical-align:middle;"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('tornevall_dnsbl_tools_action')); ?>">
                                <?php echo esc_html__('Check token permissions', 'tornevall-networks-dnsbl-implementation'); ?>
                            </button>
                            <?php echo $tokenStatusHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated by admin helper with escaped content.
                            ?>
                            <div id="tornevall-dnsbl-token-info-result" style="margin-top:10px; display:none;"></div>
                            <?php if (!$apiTokenSet): ?>
                                <span style="display:block; margin-top:4px; color:#b45309;">
                                    <?php echo esc_html__('No token configured. ', 'tornevall-networks-dnsbl-implementation'); ?>
                                    <a href="<?php echo esc_url(Plugin::toolsBaseUrl() . '/dnsbl/token/request'); ?>"
                                       target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html__('Request or manage a token at Tools →', 'tornevall-networks-dnsbl-implementation'); ?>
                                    </a>
                                </span>
                            <?php endif; ?>
                            <script>
                                (function () {
                                    var btn = document.getElementById('tornevall-dnsbl-check-token-btn');
                                    var box = document.getElementById('tornevall-dnsbl-token-info-result');
                                    var tokenStatus = document.getElementById('tornevall-dnsbl-token-status');
                                    var delistingPageSelect = document.getElementById('tornevall_dnsbl_delisting_page');
                                    var delistingPageMirror = document.getElementById('tornevall_dnsbl_delisting_page_mirror');
                                    var internalSlugInput = document.getElementById('tornevall_dnsbl_internal_delist_slug');
                                    var internalSlugMirror = document.getElementById('tornevall_dnsbl_internal_delist_slug_mirror');
                                    var delistingCommentsCheckbox = document.getElementById('tornevall_dnsbl_delistingpage_comments_disabled');
                                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                                    if (!btn || !box) return;

                                    function setDelistingControlsLocked(canDelete) {
                                        var disabled = !canDelete;
                                        [delistingPageSelect, internalSlugInput, delistingCommentsCheckbox].forEach(function (field) {
                                            if (!field) return;
                                            field.disabled = disabled;
                                            field.readOnly = disabled;
                                        });
                                        [delistingPageMirror, internalSlugMirror].forEach(function (field) {
                                            if (!field) return;
                                            field.disabled = !disabled;
                                        });
                                    }

                                    function renderResultBox(payload) {
                                        var summary = payload && payload.summary ? payload.summary : {};
                                        var message = payload && payload.message ? payload.message : '';
                                        var checkedHost = payload && payload.checked_host ? payload.checked_host : '';
                                        var resolvedHost = payload && payload.resolved_host ? payload.resolved_host : '';
                                        var rawBody = payload && payload.raw_body ? payload.raw_body : {};
                                        var html = '';

                                        if (message) {
                                            html += '<div class="notice notice-' + (summary.can_delete ? 'success' : (payload.verified ? 'warning' : 'error')) + ' inline"><p>' + esc(message) + '<\/p><\/div>';
                                        }

                                        if (checkedHost) {
                                            html += '<p><strong><?php echo esc_js(__('Checked host', 'tornevall-networks-dnsbl-implementation')); ?>:<\/strong> ' + esc(checkedHost) + '<\/p>';
                                        }
                                        if (resolvedHost) {
                                            html += '<p><strong><?php echo esc_js(__('Resolved host', 'tornevall-networks-dnsbl-implementation')); ?>:<\/strong> ' + esc(resolvedHost) + '<\/p>';
                                        }

                                        if (summary && (summary.has_token || summary.ok)) {
                                            var cidrFloor = summary.delete_guardrails && summary.delete_guardrails.delete_min_cidr_prefix ? parseInt(summary.delete_guardrails.delete_min_cidr_prefix, 10) : 0;
                                            var rows = '';
                                            if (summary.token && summary.token.name) {
                                                rows += '<tr><th><?php echo esc_js(__('Name', 'tornevall-networks-dnsbl-implementation')); ?><\/th><td>' + esc(summary.token.name) + '<\/td><\/tr>';
                                            }
                                            if (summary.token && summary.token.status) {
                                                rows += '<tr><th><?php echo esc_js(__('Status', 'tornevall-networks-dnsbl-implementation')); ?><\/th><td>' + esc(summary.token.status) + '<\/td><\/tr>';
                                            }
                                            if (summary.token && summary.token.scope_label) {
                                                rows += '<tr><th><?php echo esc_js(__('Scope', 'tornevall-networks-dnsbl-implementation')); ?><\/th><td>' + esc(summary.token.scope_label) + '<\/td><\/tr>';
                                            }
                                            if (summary.token && summary.token.resolved_via) {
                                                rows += '<tr><th><?php echo esc_js(__('Resolved via', 'tornevall-networks-dnsbl-implementation')); ?><\/th><td>' + esc(summary.token.resolved_via) + '<\/td><\/tr>';
                                            }
                                            rows += '<tr><th><?php echo esc_js(__('Can add (list IP)', 'tornevall-networks-dnsbl-implementation')); ?><\/th><td>' + (summary.can_add ? '✓' : '✗') + '<\/td><\/tr>';
                                            rows += '<tr><th><?php echo esc_js(__('Can delete (delist IP)', 'tornevall-networks-dnsbl-implementation')); ?><\/th><td>' + (summary.can_delete ? '✓' : '✗') + '<\/td><\/tr>';
                                            rows += '<tr><th><?php echo esc_js(__('Can update', 'tornevall-networks-dnsbl-implementation')); ?><\/th><td>' + (summary.can_update ? '✓' : '✗') + '<\/td><\/tr>';
                                            rows += '<tr><th><?php echo esc_js(__('CIDR delist', 'tornevall-networks-dnsbl-implementation')); ?><\/th><td>' + (summary.can_cidr_delete ? ('/' + (cidrFloor >= 24 ? cidrFloor : 24) + ' to /32') : '<?php echo esc_js(__('single-IP only', 'tornevall-networks-dnsbl-implementation')); ?>') + '<\/td><\/tr>';
                                            html += '<table class="widefat striped" style="margin-top:8px;"><tbody>' + rows + '<\/tbody><\/table>';
                                        } else if (rawBody && rawBody.reason === 'wrong_token_type') {
                                            html += '<div class="notice notice-warning inline"><p><?php echo esc_js(__('The supplied token was recognized by Tools, but it is not currently exposing DNSBL delete permissions for this site.', 'tornevall-networks-dnsbl-implementation')); ?><\/p><\/div>';
                                        }

                                        return html || '<div class="notice notice-error inline"><p><?php echo esc_js(__('Unknown error.', 'tornevall-networks-dnsbl-implementation')); ?><\/p><\/div>';
                                    }

                                    setDelistingControlsLocked(!!(tokenStatus && tokenStatus.dataset && tokenStatus.dataset.canDelete === '1'));

                                    btn.addEventListener('click', function () {
                                        var tokenInput = document.getElementById('tornevall_dnsbl_write_token');
                                        var modeInput = document.getElementById('tornevall_dnsbl_tools_mode');
                                        btn.disabled = true;
                                        btn.textContent = '<?php echo esc_js(__('Checking…', 'tornevall-networks-dnsbl-implementation')); ?>';
                                        box.style.display = 'none';
                                        box.innerHTML = '';
                                        var data = new FormData();
                                        data.append('action', 'tornevall_dnsbl_token_info');
                                        data.append('tornevall_dnsbl_tools_nonce', btn.dataset.nonce);
                                        if (tokenInput) {
                                            data.append('tornevall_dnsbl_write_token', tokenInput.value || '');
                                        }
                                        if (modeInput) {
                                            data.append('tornevall_dnsbl_tools_mode', modeInput.value || '');
                                        }
                                        fetch(ajaxUrl, {method: 'POST', body: data, credentials: 'same-origin'})
                                            .then(function (r) {
                                                return r.json();
                                            })
                                            .then(function (res) {
                                                btn.disabled = false;
                                                btn.textContent = '<?php echo esc_js(__('Check token permissions', 'tornevall-networks-dnsbl-implementation')); ?>';
                                                box.style.display = 'block';
                                                var payload = res && res.data ? res.data : {};
                                                box.innerHTML = renderResultBox(payload);
                                                if (payload.rendered_status_html) {
                                                    if (tokenStatus) {
                                                        tokenStatus.outerHTML = payload.rendered_status_html;
                                                    } else {
                                                        btn.insertAdjacentHTML('afterend', payload.rendered_status_html);
                                                    }
                                                    tokenStatus = document.getElementById('tornevall-dnsbl-token-status');
                                                }
                                                setDelistingControlsLocked(!!(payload.summary && payload.summary.can_delete));
                                            })
                                            .catch(function (e) {
                                                btn.disabled = false;
                                                btn.textContent = '<?php echo esc_js(__('Check token permissions', 'tornevall-networks-dnsbl-implementation')); ?>';
                                                box.style.display = 'block';
                                                box.innerHTML = '<div class="notice notice-error inline"><p><?php echo esc_js(__('Request failed. Check console for details.', 'tornevall-networks-dnsbl-implementation')); ?></p></div>';
                                                setDelistingControlsLocked(false);
                                                console.error('[DNSBL] token info error', e);
                                            });
                                    });

                                    function esc(s) {
                                        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                                    }
                                }());
                            </script>
                        </div>
                        <?php self::renderCheckboxRow(
                                'tornevall_dnsbl_auto_report_spam',
                                __('Auto-report detected spam IPs via DNSBL / Tools API token', 'tornevall-networks-dnsbl-implementation'),
                                __('When a DNSBL / Tools API token with DNSBL write access is configured and a comment is marked as spam by DNSBL or Akismet, the IP is queued for addition to the DNSBL. Operations are batched and sent in bulk at the end of the request.', 'tornevall-networks-dnsbl-implementation'),
                                $autoReportSpam
                        ); ?>
                    </div>
                </div>

                <div class="postbox" style="margin-top:16px;">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Cloudflare Turnstile for comments', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php self::renderCheckboxRow('tornevall_dnsbl_comment_turnstile_enabled', __('Require Turnstile on frontend comment submissions', 'tornevall-networks-dnsbl-implementation'), __('Adds a Cloudflare Turnstile widget to the public WordPress comment form and verifies it before accepting a comment.', 'tornevall-networks-dnsbl-implementation'), $turnstileEnabled); ?>
                        <p>
                            <label for="tornevall_dnsbl_comment_turnstile_site_key"><?php echo esc_html__('Turnstile site key', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <input type="text" class="regular-text" id="tornevall_dnsbl_comment_turnstile_site_key"
                                   name="tornevall_dnsbl_comment_turnstile_site_key"
                                   value="<?php echo esc_attr($turnstileSiteKey); ?>"></p>
                        <p>
                            <label for="tornevall_dnsbl_comment_turnstile_secret_key"><?php echo esc_html__('Turnstile secret key', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <input type="password" class="regular-text"
                                   id="tornevall_dnsbl_comment_turnstile_secret_key"
                                   name="tornevall_dnsbl_comment_turnstile_secret_key"
                                   value="<?php echo esc_attr($turnstileSecretKey); ?>"></p>
                        <p>
                            <label for="tornevall_dnsbl_comment_turnstile_theme"><?php echo esc_html__('Turnstile theme', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <select id="tornevall_dnsbl_comment_turnstile_theme"
                                    name="tornevall_dnsbl_comment_turnstile_theme">
                                <option value="auto" <?php selected($turnstileTheme, 'auto'); ?>><?php echo esc_html__('Auto', 'tornevall-networks-dnsbl-implementation'); ?></option>
                                <option value="light" <?php selected($turnstileTheme, 'light'); ?>><?php echo esc_html__('Light', 'tornevall-networks-dnsbl-implementation'); ?></option>
                                <option value="dark" <?php selected($turnstileTheme, 'dark'); ?>><?php echo esc_html__('Dark', 'tornevall-networks-dnsbl-implementation'); ?></option>
                            </select>
                        </p>
                        <p class="description"><?php echo esc_html__('Turnstile runs only on the public comment form. Save both the site key and the secret key before enabling production traffic.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                    </div>
                </div>

                <div class="postbox" style="margin-top:16px;">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('WordPress account registration protection', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php self::renderCheckboxRow('tornevall_dnsbl_registration_dnsbl_enabled', __('Check new account registrations against DNSBL/FraudBL', 'tornevall-networks-dnsbl-implementation'), __('Rejects a new WordPress account registration when the current visitor IP matches the selected blacklist trigger flags.', 'tornevall-networks-dnsbl-implementation'), $registrationDnsblEnabled); ?>
                        <?php self::renderCheckboxRow('tornevall_dnsbl_registration_turnstile_enabled', __('Require Turnstile on new account registrations', 'tornevall-networks-dnsbl-implementation'), __('Adds Cloudflare Turnstile to the public WordPress registration form, including multisite/network wp-signup flows, as an extra anti-bot and anti-abuse layer.', 'tornevall-networks-dnsbl-implementation'), $registrationTurnstileEnabled); ?>
                        <p class="description"><?php echo esc_html__('Registration Turnstile reuses the same site key, secret key and theme configured above for comments.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        <p class="description"><?php echo esc_html__('These controls apply to public WordPress account registration forms and do nothing when user registration is disabled in WordPress.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                    </div>
                </div>

                <div class="postbox" style="margin-top:16px;">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('WooCommerce checkout protection', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php self::renderCheckboxRow('tornevall_dnsbl_woocommerce_checkout_enabled', __('Check WooCommerce orders against DNSBL/FraudBL', 'tornevall-networks-dnsbl-implementation'), __('Rejects a WooCommerce order placement when the current visitor IP matches the selected blacklist trigger flags. Works with both the classic (legacy) checkout and the blocks-based checkout.', 'tornevall-networks-dnsbl-implementation'), Plugin::woocommerceCheckoutEnabled()); ?>
                        <p class="description"><?php echo esc_html__('These controls apply only when WooCommerce is active.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                    </div>
                </div>

                <div class="postbox" style="margin-top:16px;">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Delisting page integration', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php if (!$delistingAccessConfirmed) { ?>
                            <div class="notice notice-warning inline">
                                <p><?php echo esc_html__('These delisting-page controls stay read-only until delete / delist permission has been confirmed for the configured token.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                            </div>
                        <?php } ?>
                        <p>
                            <label for="tornevall_dnsbl_delisting_page"><?php echo esc_html__('Delisting page', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <select id="tornevall_dnsbl_delisting_page"
                                    name="tornevall_dnsbl_delisting_page" <?php disabled(!$delistingAccessConfirmed, true); ?>><?php echo implode("\n", $delistPageOption); ?></select>
                            <?php if (!$delistingAccessConfirmed) { ?><input type="hidden"
                                                                             id="tornevall_dnsbl_delisting_page_mirror"
                                                                             name="tornevall_dnsbl_delisting_page"
                                                                             value="<?php echo esc_attr($currentDelistingPage); ?>"><?php } ?>
                        </p>
                        <p>
                            <label for="tornevall_dnsbl_internal_delist_slug"><?php echo esc_html__('Internal integration slug', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <input type="text" class="regular-text" id="tornevall_dnsbl_internal_delist_slug"
                                   name="tornevall_dnsbl_internal_delist_slug"
                                   value="<?php echo esc_attr($internalDelistSlug); ?>" <?php disabled(!$delistingAccessConfirmed, true); ?>>
                            <?php if (!$delistingAccessConfirmed) { ?><input type="hidden"
                                                                             id="tornevall_dnsbl_internal_delist_slug_mirror"
                                                                             name="tornevall_dnsbl_internal_delist_slug"
                                                                             value="<?php echo esc_attr($internalDelistSlug); ?>"><?php } ?>
                            <span class="description"
                                  style="display:block; margin-top:4px;"><?php echo esc_html__('Used only when "Internal integration" is selected. Default: delist.', 'tornevall-networks-dnsbl-implementation'); ?></span>
                            <span class="description"
                                  style="display:block; margin-top:4px;"><?php echo esc_html(sprintf(__('Internal integration URL: %s', 'tornevall-networks-dnsbl-implementation'), $internalDelistUrl)); ?></span>
                        </p>
                        <p class="description" style="max-width:820px; margin-top:-4px;">
                            <?php echo esc_html__('Saving an internal delisting integration or custom delisting page performs a live DNSBL token check. If delete permission is missing, the page still renders with a warning and live removal stays unavailable until Tornevall Networks/FraudBL permissions are granted.', 'tornevall-networks-dnsbl-implementation'); ?>
                        </p>
                        <p class="description" style="max-width:820px;">
                            <?php echo esc_html__('Select "Internal integration" to let the plugin serve its own built-in delisting page on the configured slug. If you choose a normal WordPress page instead, place [dnsbl_delist_form] in that page content where the DNSBL form should appear. Legacy shortcode aliases remain available, but the new canonical shortcode is [dnsbl_delist_form].', 'tornevall-networks-dnsbl-implementation'); ?>
                        </p>
                        <?php
                        self::renderCheckboxRow('tornevall_dnsbl_delistingpage_comments_disabled', __('Disable comments on the delisting page', 'tornevall-networks-dnsbl-implementation'), __('Useful if the delisting page attracts many support comments.', 'tornevall-networks-dnsbl-implementation'), (bool)get_option('tornevall_dnsbl_delistingpage_comments_disabled'));
                        self::renderCheckboxRow('tornevall_dnsbl_removal_turnstile_enabled', __('Require Turnstile on public delisting/removal submissions', 'tornevall-networks-dnsbl-implementation'), __('Adds Cloudflare Turnstile to live delist/removal submissions on the public page. Checker-only and background follow-up requests stay verification-free so the lookup flow still works when Turnstile has temporary issues.', 'tornevall-networks-dnsbl-implementation'), $removalTurnstileRequested);
                        self::renderCheckboxRow('tornevall_dnsbl_removal_turnstile_fail_open', __('Automatically bypass removal-page Turnstile if Cloudflare Turnstile has operational problems', 'tornevall-networks-dnsbl-implementation'), __('When enabled, the public delisting/removal form can temporarily continue without the Turnstile challenge if the widget cannot initialize or Cloudflare verification has an operational outage. This applies only to the public removal page and does not weaken comment or registration protection.', 'tornevall-networks-dnsbl-implementation'), $removalTurnstileFailOpen);
                        ?>
                        <p class="description" style="max-width:820px; margin-top:-4px;">
                            <?php echo esc_html__('Removal-page Turnstile reuses the same site key, secret key and theme configured above for comments. Keep this off unless you explicitly want CAPTCHA on the public delist flow.', 'tornevall-networks-dnsbl-implementation'); ?>
                        </p>
                        <p class="description" style="max-width:820px;">
                            <?php echo esc_html__('Turnstile is optional on the public delisting/removal page and is now controlled separately from comment and registration protection.', 'tornevall-networks-dnsbl-implementation'); ?>
                        </p>
                        <p class="description" style="max-width:820px;">
                            <?php echo esc_html__('The automatic bypass opens only for the public removal page when the Turnstile widget or Cloudflare siteverify endpoint is unhealthy. A later successful Turnstile verification closes that temporary bypass again.', 'tornevall-networks-dnsbl-implementation'); ?>
                        </p>
                        <?php if (!$delistingAccessConfirmed) { ?>
                            <script>
                                (function () {
                                    var checkbox = document.getElementById('tornevall_dnsbl_delistingpage_comments_disabled');
                                    var hidden = document.querySelector('input[type="hidden"][name="tornevall_dnsbl_delistingpage_comments_disabled"]');
                                    if (checkbox) {
                                        checkbox.disabled = true;
                                    }
                                    if (hidden) {
                                        hidden.value = <?php echo (bool)get_option('tornevall_dnsbl_delistingpage_comments_disabled') ? '"1"' : '"0"'; ?>;
                                    }
                                }());
                            </script>
                        <?php } ?>
                    </div>
                </div>

                <?php submit_button(__('Save settings', 'tornevall-networks-dnsbl-implementation')); ?>
            </form>
        </div>
        <?php
    }

    public static function ensureDefaults(): void
    {
        Migrations::ensureDefaultOptions();
    }

    public static function buildPageOptions($currentDelistingPage): array
    {
        $currentSelection = Plugin::canonicalDelistingPageSelection($currentDelistingPage);
        $options = ['<option value="" ' . selected($currentSelection, '', false) . '>' . esc_html__('None', 'tornevall-networks-dnsbl-implementation') . '</option>'];
        $options[] = '<option value="' . esc_attr(Plugin::internalDelistSelectionValue()) . '" ' . selected($currentSelection, Plugin::internalDelistSelectionValue(), false) . '>' . esc_html__('Internal integration', 'tornevall-networks-dnsbl-implementation') . '</option>';
        $pages = get_pages();
        if (is_array($pages)) {
            foreach ($pages as $pageObject) {
                $value = 'page:' . (int)$pageObject->ID;
                $options[] = '<option value="' . esc_attr($value) . '" ' . selected($currentSelection, $value, false) . '>' . esc_html($pageObject->post_title) . '</option>';
            }
        }

        return $options;
    }

    /**
     * @param list<string> $resolverNames
     * @param array<string,mixed> $statsSummary
     * @param array<string,mixed> $permissionSummary
     * @return array{type:string,message:string,details:list<string>}|null
     */
    private static function buildSettingsOverviewNotice(
            array  $resolverNames,
            string $toolsBaseUrl,
            string $toolsMode,
            bool   $apiTokenSet,
            array  $permissionSummary,
            bool   $delistingAccessConfirmed,
            array  $statsSummary
    ): ?array
    {
        $details = [];

        if (!empty($statsSummary) && empty($statsSummary['has_stats_table'])) {
            $details[] = __('The statistics table has not been created yet. Deactivate and reactivate the plugin if this persists after upgrade.', 'tornevall-networks-dnsbl-implementation');
        }

        if ($toolsMode === 'dev') {
            $details[] = sprintf(__('Tools environment mode is currently set to dev: %s', 'tornevall-networks-dnsbl-implementation'), $toolsBaseUrl);
        }

        if ($apiTokenSet && empty($permissionSummary['ok'])) {
            $details[] = trim((string)($permissionSummary['message'] ?? __('The configured DNSBL / Tools API token could not be verified right now.', 'tornevall-networks-dnsbl-implementation')));

            return [
                    'type' => 'warning',
                    'message' => __('Tools integration needs attention before this site can use permission-aware DNSBL features.', 'tornevall-networks-dnsbl-implementation'),
                    'details' => array_values(array_filter(array_merge($details, [
                            sprintf(__('Current Tools base URL: %s', 'tornevall-networks-dnsbl-implementation'), $toolsBaseUrl),
                            sprintf(__('Supported resolvers in active use: %s', 'tornevall-networks-dnsbl-implementation'), implode(', ', $resolverNames)),
                    ]))),
            ];
        }

        if ($apiTokenSet && !$delistingAccessConfirmed) {
            $details[] = trim((string)($permissionSummary['message'] ?? __('The token was checked, but delete / delist access is still unavailable.', 'tornevall-networks-dnsbl-implementation')));

            return [
                    'type' => 'warning',
                    'message' => __('The configured token is limited, so live delisting is still unavailable on this site.', 'tornevall-networks-dnsbl-implementation'),
                    'details' => array_values(array_filter(array_merge($details, [
                            sprintf(__('Current Tools base URL: %s', 'tornevall-networks-dnsbl-implementation'), $toolsBaseUrl),
                            sprintf(__('Supported resolvers in active use: %s', 'tornevall-networks-dnsbl-implementation'), implode(', ', $resolverNames)),
                    ]))),
            ];
        }

        if (!$apiTokenSet && Plugin::configuredDelistingPageSelection() !== '') {
            return [
                    'type' => 'warning',
                    'message' => __('A delisting page is selected, but no DNSBL / Tools API token is configured yet.', 'tornevall-networks-dnsbl-implementation'),
                    'details' => array_values(array_filter(array_merge($details, [
                            sprintf(__('Current Tools base URL: %s', 'tornevall-networks-dnsbl-implementation'), $toolsBaseUrl),
                    ]))),
            ];
        }

        if (count($details)) {
            return [
                    'type' => 'info',
                    'message' => __('There are environment details worth reviewing for this plugin configuration.', 'tornevall-networks-dnsbl-implementation'),
                    'details' => $details,
            ];
        }

        return null;
    }

    public static function handleToolsRequest()
    {
        $isAjaxRequest = function_exists('wp_doing_ajax') && wp_doing_ajax();
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if (!current_user_can('manage_options') || $isAjaxRequest || $requestMethod !== 'POST' || !isset($_POST['tornevall_dnsbl_tool_action'])) {
            return null;
        }

        $nonce = isset($_POST['tornevall_dnsbl_tools_nonce']) ? sanitize_text_field(wp_unslash($_POST['tornevall_dnsbl_tools_nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'tornevall_dnsbl_tools_action')) {
            add_settings_error('tornevall_dnsbl_admin_tools', 'invalid-tools-nonce', __('Security check failed. Please try again.', 'tornevall-networks-dnsbl-implementation'), 'error');
            return null;
        }

        $toolResults = self::getToolResults(sanitize_text_field(wp_unslash($_POST['tornevall_dnsbl_tool_action'])), $_POST);
        if (is_wp_error($toolResults)) {
            add_settings_error('tornevall_dnsbl_admin_tools', $toolResults->get_error_code(), $toolResults->get_error_message(), $toolResults->get_error_code() === 'self-check-empty' ? 'warning' : 'error');
            return null;
        }

        return $toolResults;
    }

    /**
     * @param array{type:string,message:string,details:list<string>} $notice
     */
    private static function renderSettingsOverviewNotice(array $notice): string
    {
        $type = in_array((string)($notice['type'] ?? 'info'), ['error', 'warning', 'success', 'info'], true)
                ? (string)$notice['type']
                : 'info';
        $message = trim((string)($notice['message'] ?? ''));
        $details = array_values(array_filter(array_map('strval', (array)($notice['details'] ?? []))));

        if ($message === '' && !count($details)) {
            return '';
        }

        ob_start();
        ?>
        <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible inline">
            <?php if ($message !== '') { ?>
                <p><strong><?php echo esc_html($message); ?></strong></p>
            <?php } ?>
            <?php if (count($details)) { ?>
                <ul style="margin:.2rem 0 .6rem 1.2rem; list-style:disc;">
                    <?php foreach ($details as $detail) { ?>
                        <li><?php echo esc_html($detail); ?></li>
                    <?php } ?>
                </ul>
            <?php } ?>
        </div>
        <?php

        return (string)ob_get_clean();
    }

    public static function renderToolForm($toolAction, $title, $buttonLabel, $contentCallback = null): void
    {
        ?>
        <form method="post" action="" data-tornevall-dnsbl-tool-form="1">
            <?php wp_nonce_field('tornevall_dnsbl_tools_action', 'tornevall_dnsbl_tools_nonce'); ?>
            <input type="hidden" name="tornevall_dnsbl_tool_action" value="<?php echo esc_attr($toolAction); ?>">
            <h3 style="margin-top:0;"><?php echo esc_html($title); ?></h3>
            <?php if (is_callable($contentCallback)) {
                call_user_func($contentCallback);
            } ?>
            <?php submit_button($buttonLabel, 'secondary', 'submit', false); ?>
        </form>
        <?php
    }

    public static function renderAjaxResultsContainer($toolResults, $devMode, $resolverNames): void
    {
        ?>
        <div id="tornevall-dnsbl-tool-results" aria-live="polite">
            <?php self::renderToolResults($toolResults, $devMode, $resolverNames); ?>
        </div>
        <?php
    }

    public static function renderAjaxNotice($message, $type = 'error'): void
    {
        ?>
        <div id="tornevall-dnsbl-tool-results" aria-live="polite">
            <div class="notice notice-<?php echo esc_attr($type); ?> inline"><p><?php echo esc_html($message); ?></p>
            </div>
        </div>
        <?php
    }

    public static function renderCheckboxRow($name, $label, $description, $checked): void
    {
        ?>
        <label style="display:block; margin-bottom:10px;">
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="0">
            <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($checked, true); ?>>
            <strong><?php echo esc_html($label); ?></strong>
            <span class="description"
                  style="display:block; margin-top:3px;"><?php echo esc_html($description); ?></span>
        </label>
        <?php
    }
}
