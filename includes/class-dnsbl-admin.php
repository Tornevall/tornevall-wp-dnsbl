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

        $parts = preg_split('/[\s,]+/', strtolower((string) $value));
        $hosts = [];
        foreach ((array) $parts as $part) {
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
        return Plugin::canonicalBlockedRedirectUrl(esc_url_raw(trim((string) $value)));
    }

    public static function sanitizeCommentsStyle($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            $value = Plugin::defaultCommentsDisabledStyle();
        }

        return sanitize_text_field($value);
    }

    public static function sanitizeToolsMode($value): string
    {
        return Plugin::canonicalToolsMode($value);
    }

    public static function sanitizeTurnstileTheme($value): string
    {
        return Plugin::normalizeCommentTurnstileTheme($value);
    }

    public static function sanitizeWhitelist($value): string
    {
        return implode("\n", Plugin::parseWhitelistEntries($value));
    }

    public static function ensureDefaults(): void
    {
        Migrations::ensureDefaultOptions();
    }

    public static function registerSettings(): void
    {
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_cache_age', ['sanitize_callback' => [self::class, 'sanitizeCacheAge']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_cache_cleanup_interval', ['sanitize_callback' => [self::class, 'sanitizeCacheCleanupInterval']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_filter_types', ['sanitize_callback' => [self::class, 'sanitizeFilterTypes']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_nocomment', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_blockfull', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_delisting_page', ['sanitize_callback' => 'absint']);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_resolver_hosts', ['sanitize_callback' => [self::class, 'sanitizeResolverHosts']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_whitelist', ['sanitize_callback' => [self::class, 'sanitizeWhitelist']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_blocked_redirecturl', ['sanitize_callback' => [self::class, 'sanitizeRedirectUrl']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_comments_disabled_style', ['sanitize_callback' => [self::class, 'sanitizeCommentsStyle']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_delistingpage_comments_disabled', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_dev_mode', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_tools_token', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_tools_mode', ['sanitize_callback' => [self::class, 'sanitizeToolsMode']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_removal_token', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_comment_turnstile_enabled', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_comment_turnstile_site_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_comment_turnstile_secret_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_comment_turnstile_theme', ['sanitize_callback' => [self::class, 'sanitizeTurnstileTheme']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_registration_dnsbl_enabled', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_registration_turnstile_enabled', ['sanitize_callback' => [self::class, 'sanitizeCheckbox']]);
    }

    public static function renderCheckboxRow($name, $label, $description, $checked): void
    {
        ?>
        <label style="display:block; margin-bottom:10px;">
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="0">
            <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($checked, true); ?>>
            <strong><?php echo esc_html($label); ?></strong>
            <span class="description" style="display:block; margin-top:3px;"><?php echo esc_html($description); ?></span>
        </label>
        <?php
    }

    public static function buildPageOptions($currentDelistingPage): array
    {
        $options = ['<option value="">' . esc_html__('None', 'tornevall-networks-dnsbl-implementation') . '</option>'];
        $pages = get_pages();
        if (is_array($pages)) {
            foreach ($pages as $pageObject) {
                $options[] = '<option value="' . esc_attr($pageObject->ID) . '" ' . selected((int) $pageObject->ID, (int) $currentDelistingPage, false) . '>' . esc_html($pageObject->post_title) . '</option>';
            }
        }

        return $options;
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

    public static function renderToolResultsMarkup($toolResults, $devMode, $resolverNames): string
    {
        ob_start();
        self::renderToolResults($toolResults, $devMode, $resolverNames);
        return trim((string) ob_get_clean());
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

    public static function renderAjaxResultsContainer($toolResults, $devMode, $resolverNames): void
    {
        ?>
        <div id="tornevall-dnsbl-tool-results" aria-live="polite">
            <?php self::renderToolResults($toolResults, $devMode, $resolverNames); ?>
        </div>
        <?php
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

    public static function renderLookupToolFields(): void
    {
        ?>
        <label for="tornevall_dnsbl_lookup_ip" class="screen-reader-text"><?php echo esc_html__('IP address to test', 'tornevall-networks-dnsbl-implementation'); ?></label>
        <input type="text" class="regular-text" id="tornevall_dnsbl_lookup_ip" name="tornevall_dnsbl_lookup_ip" placeholder="203.0.113.10">
        <?php
    }

    public static function renderAjaxNotice($message, $type = 'error'): void
    {
        ?>
        <div id="tornevall-dnsbl-tool-results" aria-live="polite">
            <div class="notice notice-<?php echo esc_attr($type); ?> inline"><p><?php echo esc_html($message); ?></p></div>
        </div>
        <?php
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
                            <td><strong style="color:<?php echo $row['listed'] ? '#b91c1c' : '#15803d'; ?>;"><?php echo esc_html($row['message']); ?></strong></td>
                            <td>
                                <?php if (!empty($row['constants'])) { ?>
                                    <div><?php echo esc_html(sprintf(__('Matched flags: %s', 'tornevall-networks-dnsbl-implementation'), implode(', ', $row['constants']))); ?></div>
                                    <div class="description"><?php echo esc_html(sprintf(__('Bitmask: %d', 'tornevall-networks-dnsbl-implementation'), (int) $row['typebit'])); ?></div>
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

    public static function renderOptionsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'tornevall-networks-dnsbl-implementation'));
        }

        self::ensureDefaults();

        $currentDelistingPage = (int) get_option('tornevall_dnsbl_delisting_page');
        $delistPageOption = self::buildPageOptions($currentDelistingPage);
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
        $toolsTokenSet = trim((string) get_option('tornevall_dnsbl_tools_token')) !== '';
        $toolsMode = Plugin::canonicalToolsMode(get_option('tornevall_dnsbl_tools_mode'));
        $turnstileEnabled = get_option('tornevall_dnsbl_comment_turnstile_enabled') === '1';
        $turnstileSiteKey = Plugin::commentTurnstileSiteKey();
        $turnstileSecretKey = Plugin::commentTurnstileSecretKey();
        $turnstileTheme = Plugin::commentTurnstileTheme();
        $registrationDnsblEnabled = Plugin::registrationDnsblEnabled();
        $registrationTurnstileEnabled = get_option('tornevall_dnsbl_registration_turnstile_enabled') === '1';
        $whitelistEntries = Plugin::getWhitelistEntries();
        $whitelistValue = implode("\n", $whitelistEntries);
        $currentVisitorAddress = Plugin::currentVisitorIp();
        $currentVisitorWhitelisted = $currentVisitorAddress && Plugin::isWhitelistedIp($currentVisitorAddress);
        $resolvedToolsBase = Plugin::toolsBaseUrl();
        $toolResults = self::handleToolsRequest();
        $statsSummary = Plugin::getStatsSummary();
        $statsSummary24h = Plugin::getStatsSummary(24);
        $lastCleanupLabel = !empty($statsSummary['last_cache_cleanup'])
            ? sprintf(
                __('Last cache cleanup: %s', 'tornevall-networks-dnsbl-implementation'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $statsSummary['last_cache_cleanup'])
            )
            : __('Last cache cleanup: not yet recorded', 'tornevall-networks-dnsbl-implementation');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('DNS Blacklist Configurator', 'tornevall-networks-dnsbl-implementation'); ?></h1>
            <p class="description"><?php echo esc_html__('The plugin prioritizes direct DNS lookups. Optional Tools integration is used for enhanced comment risk assessment.', 'tornevall-networks-dnsbl-implementation'); ?></p>

            <?php settings_errors(); ?>
            <?php settings_errors('tornevall_dnsbl_admin_tools'); ?>

            <div class="notice notice-info inline">
                <p><?php echo esc_html(sprintf(__('Supported resolvers in active use: %s', 'tornevall-networks-dnsbl-implementation'), implode(', ', Plugin::defaultResolvers()))); ?></p>
                <p><?php echo esc_html(sprintf(__('Current Tools base URL: %s', 'tornevall-networks-dnsbl-implementation'), $resolvedToolsBase)); ?></p>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin:16px 0;">
                <div class="postbox"><div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('At a glance', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <ul style="margin:0; padding-left:18px;">
                            <li><?php echo esc_html(sprintf(__('Resolver count: %d', 'tornevall-networks-dnsbl-implementation'), count($resolverNames))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Trigger flags selected: %d', 'tornevall-networks-dnsbl-implementation'), count($savedFlags))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Whitelist entries configured: %d', 'tornevall-networks-dnsbl-implementation'), count($whitelistEntries))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Tools token configured: %s', 'tornevall-networks-dnsbl-implementation'), $toolsTokenSet ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Comment Turnstile enabled: %s', 'tornevall-networks-dnsbl-implementation'), $turnstileEnabled ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Registration DNSBL enabled: %s', 'tornevall-networks-dnsbl-implementation'), $registrationDnsblEnabled ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Registration Turnstile enabled: %s', 'tornevall-networks-dnsbl-implementation'), $registrationTurnstileEnabled ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Dev mode: %s', 'tornevall-networks-dnsbl-implementation'), $devMode ? __('enabled', 'tornevall-networks-dnsbl-implementation') : __('disabled', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <?php if ($currentVisitorAddress && filter_var($currentVisitorAddress, FILTER_VALIDATE_IP)) { ?>
                                <li><?php echo esc_html(sprintf(__('Current visitor address is whitelisted: %s', 'tornevall-networks-dnsbl-implementation'), $currentVisitorWhitelisted ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                            <?php } ?>
                        </ul>
                    </div></div>
                <div class="postbox"><div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Visitor statistics', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php if (!empty($statsSummary['has_stats_table'])) { ?>
                            <ul style="margin:0; padding-left:18px;">
                                <li><?php echo esc_html(sprintf(__('Visitor checks recorded: %d', 'tornevall-networks-dnsbl-implementation'), (int) $statsSummary['total_checks'])); ?></li>
                                <li><?php echo esc_html(sprintf(__('Unique visitor addresses seen: %d', 'tornevall-networks-dnsbl-implementation'), (int) $statsSummary['unique_visitors'])); ?></li>
                                <li><?php echo esc_html(sprintf(__('Blacklist hits recorded: %d', 'tornevall-networks-dnsbl-implementation'), (int) $statsSummary['blacklist_hits'])); ?></li>
                                <li><?php echo esc_html(sprintf(__('Blocked requests recorded: %d', 'tornevall-networks-dnsbl-implementation'), (int) $statsSummary['blocked_requests'])); ?></li>
                                <li><?php echo esc_html(sprintf(__('Unique blocked visitor addresses: %d', 'tornevall-networks-dnsbl-implementation'), (int) $statsSummary['blocked_unique_visitors'])); ?></li>
                                <li><?php echo esc_html(sprintf(__('Last 24 hours: %d checks / %d blocked', 'tornevall-networks-dnsbl-implementation'), (int) $statsSummary24h['total_checks'], (int) $statsSummary24h['blocked_requests'])); ?></li>
                                <?php if (!empty($statsSummary['has_cache_table'])) { ?>
                                    <li><?php echo esc_html(sprintf(__('Cached DNSBL entries currently stored: %d', 'tornevall-networks-dnsbl-implementation'), (int) $statsSummary['cached_entries'])); ?></li>
                                    <li><?php echo esc_html(sprintf(__('Cached blacklisted entries currently stored: %d', 'tornevall-networks-dnsbl-implementation'), (int) $statsSummary['cached_blacklist_entries'])); ?></li>
                                    <li><?php echo esc_html(sprintf(__('Cached clear / non-listed entries currently stored: %d', 'tornevall-networks-dnsbl-implementation'), (int) $statsSummary['cached_clear_entries'])); ?></li>
                                    <li><?php echo esc_html($lastCleanupLabel); ?></li>
                                <?php } ?>
                            </ul>
                        <?php } else { ?>
                            <p class="description"><?php echo esc_html__('The statistics table has not been created yet. Deactivate and reactivate the plugin if this persists after upgrade.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        <?php } ?>
                    </div></div>
                <div class="postbox"><div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Plugin information and help', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <p><a href="<?php echo esc_url(TORNEVALL_DNSBL_PUBLIC_DOCS_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('DNSBL plugin and endpoint documentation', 'tornevall-networks-dnsbl-implementation'); ?></a></p>
                        <p><a href="<?php echo esc_url(TORNEVALL_DNSBL_CHANGELOG_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Plugin changelog', 'tornevall-networks-dnsbl-implementation'); ?></a></p>
                        <p><a href="<?php echo esc_url(TORNEVALL_DNSBL_HISTORY_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Source history and diff trail', 'tornevall-networks-dnsbl-implementation'); ?></a></p>
                        <p><a href="<?php echo esc_url(TORNEVALL_DNSBL_ISSUES_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('GitHub issue tracker', 'tornevall-networks-dnsbl-implementation'); ?></a></p>
                    </div></div>
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

                <div class="postbox" style="margin-top:16px;"><div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Core DNS lookup settings', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr><th scope="row"><label for="tornevall_dnsbl_resolver_hosts"><?php echo esc_html__('Preferred resolver hosts', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td>
                                    <input type="text" class="regular-text" id="tornevall_dnsbl_resolver_hosts" name="tornevall_dnsbl_resolver_hosts" value="<?php echo esc_attr(implode(',', $resolverNames)); ?>">
                                </td></tr>
                            <tr><th scope="row"><label for="tornevall_dnsbl_cache_age"><?php echo esc_html__('Cache age', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td>
                                    <input type="number" min="<?php echo esc_attr((string) Plugin::minimumCacheAge()); ?>" step="60" id="tornevall_dnsbl_cache_age" name="tornevall_dnsbl_cache_age" value="<?php echo esc_attr((string) $cacheAge); ?>">
                                    <p class="description"><?php echo esc_html__('Cache both listed and non-listed IP lookups to avoid repeated DNS traffic. Recommended range: 5-10 minutes.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                                </td></tr>
                            <tr><th scope="row"><label for="tornevall_dnsbl_cache_cleanup_interval"><?php echo esc_html__('Cache cleanup interval', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td>
                                    <input type="number" min="<?php echo esc_attr((string) Plugin::minimumCleanupInterval()); ?>" step="60" id="tornevall_dnsbl_cache_cleanup_interval" name="tornevall_dnsbl_cache_cleanup_interval" value="<?php echo esc_attr((string) $cacheCleanupInterval); ?>">
                                    <p class="description"><?php echo esc_html__('Expired cache rows are purged automatically on this interval and also opportunistically during requests.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                                </td></tr>
                            <tr><th scope="row"><label for="tornevall_dnsbl_filter_types"><?php echo esc_html__('Trigger on blacklist flags', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td>
                                    <select multiple size="8" id="tornevall_dnsbl_filter_types" name="tornevall_dnsbl_filter_types[]" style="min-width:320px;"><?php echo implode("\n", $flagListSelector); ?></select>
                                </td></tr>
                        </table>
                    </div></div>

                <div class="postbox" style="margin-top:16px;"><div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Protection behavior', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php
                        self::renderCheckboxRow('tornevall_dnsbl_nocomment', __('Hide comments for listed visitors', 'tornevall-networks-dnsbl-implementation'), __('Hides the comment section and blocks direct comment submission when a visitor matches the selected blacklist flags.', 'tornevall-networks-dnsbl-implementation'), (bool) get_option('tornevall_dnsbl_nocomment'));
                        self::renderCheckboxRow('tornevall_dnsbl_blockfull', __('Redirect listed visitors away from the page', 'tornevall-networks-dnsbl-implementation'), __('Immediately redirects listed visitors away from the page. wp-admin sessions are still protected from lockout.', 'tornevall-networks-dnsbl-implementation'), (bool) get_option('tornevall_dnsbl_blockfull'));
                        ?>
                        <p>
                            <label for="tornevall_dnsbl_whitelist"><?php echo esc_html__('Safe IP whitelist', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <textarea class="large-text code" rows="5" id="tornevall_dnsbl_whitelist" name="tornevall_dnsbl_whitelist" placeholder="203.0.113.10&#10;198.51.100.0/24"><?php echo esc_textarea($whitelistValue); ?></textarea>
                            <span class="description" style="display:block; margin-top:6px;"><?php echo esc_html__('Whitelisted IP addresses or CIDR ranges are still checked and can appear in statistics, but they will not be blocked, redirected or marked as spam. This is the safest way to dry-run DNSBL behaviour in a live WordPress environment.', 'tornevall-networks-dnsbl-implementation'); ?></span>
                            <?php if ($currentVisitorAddress && filter_var($currentVisitorAddress, FILTER_VALIDATE_IP)) { ?>
                                <span class="description" style="display:block; margin-top:4px;"><?php echo esc_html(sprintf(__('Current visitor address: %s', 'tornevall-networks-dnsbl-implementation'), $currentVisitorAddress)); ?></span>
                                <span class="description" style="display:block; margin-top:8px;">
                                    <?php if ($currentVisitorWhitelisted) {
                                        echo esc_html__('This address is already on the whitelist.', 'tornevall-networks-dnsbl-implementation');
                                    } else {
                                        echo Plugin::renderWhitelistCurrentVisitorButton(__('Add current visitor to whitelist', 'tornevall-networks-dnsbl-implementation'));
                                    } ?>
                                </span>
                            <?php } ?>
                        </p>
                        <p><label><?php echo esc_html__('Blocked visitor redirect URL', 'tornevall-networks-dnsbl-implementation'); ?><br><input type="url" class="regular-text" name="tornevall_dnsbl_blocked_redirecturl" value="<?php echo esc_attr($redirectUrl); ?>"></label></p>
                        <p><label><?php echo esc_html__('Admin notice style for comment blocking', 'tornevall-networks-dnsbl-implementation'); ?><br><input type="text" class="regular-text" name="tornevall_dnsbl_comments_disabled_style" value="<?php echo esc_attr($commentsStyle); ?>"></label></p>
                    </div></div>

                <div class="postbox" style="margin-top:16px;"><div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Tools integration and development', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php self::renderCheckboxRow('tornevall_dnsbl_dev_mode', __('Show extended diagnostics in the admin panel', 'tornevall-networks-dnsbl-implementation'), __('Shows raw diagnostic responses in the try-test and self-check tools.', 'tornevall-networks-dnsbl-implementation'), $devMode); ?>
                        <p class="description"><?php echo esc_html__('Frontend dry run is only available when dev mode is enabled, Tools environment mode is set to dev, and you are logged in as an administrator on the public site. Use the admin-bar toggle there to simulate a blacklisted visitor safely without affecting wp-admin.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        <p><label for="tornevall_dnsbl_tools_mode"><?php echo esc_html__('Tools environment mode', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <select id="tornevall_dnsbl_tools_mode" name="tornevall_dnsbl_tools_mode">
                                <option value="dev" <?php selected($toolsMode, 'dev'); ?>><?php echo esc_html__('Force dev (tools.tornevall.com)', 'tornevall-networks-dnsbl-implementation'); ?></option>
                                <option value="prod" <?php selected($toolsMode, 'prod'); ?>><?php echo esc_html__('Prod default (tools.tornevall.net)', 'tornevall-networks-dnsbl-implementation'); ?></option>
                            </select>
                        </p>
                        <p><label for="tornevall_dnsbl_tools_token"><?php echo esc_html__('Tools token', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <input type="password" class="regular-text" id="tornevall_dnsbl_tools_token" name="tornevall_dnsbl_tools_token" value="<?php echo esc_attr((string) get_option('tornevall_dnsbl_tools_token')); ?>"></p>
                        <p><label for="tornevall_dnsbl_removal_token_display"><?php echo esc_html__('Removal token', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <input type="hidden" name="tornevall_dnsbl_removal_token" value="<?php echo esc_attr((string) get_option('tornevall_dnsbl_removal_token')); ?>">
                            <input type="text" class="regular-text" id="tornevall_dnsbl_removal_token_display" value="<?php echo esc_attr((string) get_option('tornevall_dnsbl_removal_token')); ?>" placeholder="<?php echo esc_attr__('Coming soon', 'tornevall-networks-dnsbl-implementation'); ?>" disabled></p>
                    </div></div>

                <div class="postbox" style="margin-top:16px;"><div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Cloudflare Turnstile for comments', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php self::renderCheckboxRow('tornevall_dnsbl_comment_turnstile_enabled', __('Require Turnstile on frontend comment submissions', 'tornevall-networks-dnsbl-implementation'), __('Adds a Cloudflare Turnstile widget to the public WordPress comment form and verifies it before accepting a comment.', 'tornevall-networks-dnsbl-implementation'), $turnstileEnabled); ?>
                        <p><label for="tornevall_dnsbl_comment_turnstile_site_key"><?php echo esc_html__('Turnstile site key', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <input type="text" class="regular-text" id="tornevall_dnsbl_comment_turnstile_site_key" name="tornevall_dnsbl_comment_turnstile_site_key" value="<?php echo esc_attr($turnstileSiteKey); ?>"></p>
                        <p><label for="tornevall_dnsbl_comment_turnstile_secret_key"><?php echo esc_html__('Turnstile secret key', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <input type="password" class="regular-text" id="tornevall_dnsbl_comment_turnstile_secret_key" name="tornevall_dnsbl_comment_turnstile_secret_key" value="<?php echo esc_attr($turnstileSecretKey); ?>"></p>
                        <p><label for="tornevall_dnsbl_comment_turnstile_theme"><?php echo esc_html__('Turnstile theme', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <select id="tornevall_dnsbl_comment_turnstile_theme" name="tornevall_dnsbl_comment_turnstile_theme">
                                <option value="auto" <?php selected($turnstileTheme, 'auto'); ?>><?php echo esc_html__('Auto', 'tornevall-networks-dnsbl-implementation'); ?></option>
                                <option value="light" <?php selected($turnstileTheme, 'light'); ?>><?php echo esc_html__('Light', 'tornevall-networks-dnsbl-implementation'); ?></option>
                                <option value="dark" <?php selected($turnstileTheme, 'dark'); ?>><?php echo esc_html__('Dark', 'tornevall-networks-dnsbl-implementation'); ?></option>
                            </select>
                        </p>
                        <p class="description"><?php echo esc_html__('Turnstile runs only on the public comment form. Save both the site key and the secret key before enabling production traffic.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                    </div></div>

                <div class="postbox" style="margin-top:16px;"><div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('WordPress account registration protection', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <?php self::renderCheckboxRow('tornevall_dnsbl_registration_dnsbl_enabled', __('Check new account registrations against DNSBL/FraudBL', 'tornevall-networks-dnsbl-implementation'), __('Rejects a new WordPress account registration when the current visitor IP matches the selected blacklist trigger flags.', 'tornevall-networks-dnsbl-implementation'), $registrationDnsblEnabled); ?>
                        <?php self::renderCheckboxRow('tornevall_dnsbl_registration_turnstile_enabled', __('Require Turnstile on new account registrations', 'tornevall-networks-dnsbl-implementation'), __('Adds Cloudflare Turnstile to the public wp-login registration form as an extra anti-bot and anti-abuse sales argument.', 'tornevall-networks-dnsbl-implementation'), $registrationTurnstileEnabled); ?>
                        <p class="description"><?php echo esc_html__('Registration Turnstile reuses the same site key, secret key and theme configured above for comments.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        <p class="description"><?php echo esc_html__('These controls apply to public WordPress account registration forms and do nothing when user registration is disabled in WordPress.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                    </div></div>

                <div class="postbox" style="margin-top:16px;"><div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Delisting page integration', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <p><label for="tornevall_dnsbl_delisting_page"><?php echo esc_html__('Delisting page', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                            <select id="tornevall_dnsbl_delisting_page" name="tornevall_dnsbl_delisting_page"><?php echo implode("\n", $delistPageOption); ?></select></p>
                        <?php
                        self::renderCheckboxRow('tornevall_dnsbl_delistingpage_comments_disabled', __('Disable comments on the delisting page', 'tornevall-networks-dnsbl-implementation'), __('Useful if the delisting page attracts many support comments.', 'tornevall-networks-dnsbl-implementation'), (bool) get_option('tornevall_dnsbl_delistingpage_comments_disabled'));
                        ?>
                    </div></div>

                <?php submit_button(__('Save settings', 'tornevall-networks-dnsbl-implementation')); ?>
            </form>
        </div>
        <?php
    }
}

