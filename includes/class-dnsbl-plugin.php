<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin
{
    private const FRONTEND_DRY_RUN_USER_META = 'tornevall_dnsbl_frontend_dry_run';
    private const FRONTEND_DRY_RUN_IP = '127.0.0.255';

    public static function registerHooks(): void
    {
        add_filter('cron_schedules', [self::class, 'cronSchedules']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('plugins_loaded', [Migrations::class, 'maybeUpgrade']);
        add_action('init', [self::class, 'syncCacheCleanupSchedule']);
        add_action('init', [self::class, 'checkpoint']);
        add_action('wp_ajax_tornevall_dnsbl_admin_tools', [\Tornevall\Networks\DNSBL\Admin::class, 'ajaxTools']);
        add_action('admin_post_tornevall_dnsbl_whitelist_current_visitor', [self::class, 'handleWhitelistCurrentVisitorAction']);
        add_action('admin_post_tornevall_dnsbl_toggle_frontend_dry_run', [self::class, 'handleFrontendDryRunToggle']);
        add_action('admin_notices', [self::class, 'renderActionNotice']);
        add_action('admin_notices', [self::class, 'renderProtectedUserNotice']);
        add_action('admin_bar_menu', [self::class, 'addFrontendDryRunAdminBarMenu'], 100);
        add_action('tornevall_dnsbl_cache_cleanup', [self::class, 'purgeExpiredCache']);
        add_action('wp_footer', [self::class, 'renderFrontendDryRunBanner']);

        add_filter('the_content', [self::class, 'contentHandler']);
        add_filter('comments_open', [self::class, 'disableComments'], 10, 1);
        add_filter('comments_array', [self::class, 'disableCommentsMessage'], 10, 1);
        add_filter('preprocess_comment', [self::class, 'preprocessComment'], 10, 1);
        add_filter('pre_comment_approved', [self::class, 'preCommentApproved'], 10, 2);
        add_action('comment_form_after_fields', [self::class, 'renderCommentTurnstileWidget']);
        add_action('comment_form_logged_in_after', [self::class, 'renderCommentTurnstileWidget']);
        add_action('register_form', [self::class, 'renderRegistrationTurnstileWidget']);
        add_filter('registration_errors', [self::class, 'validateRegistrationErrors'], 10, 3);
    }

    public static function defaultOptions(): array
    {
        return [
            'tornevall_dnsbl_cache_age' => self::defaultCacheAge(),
            'tornevall_dnsbl_cache_cleanup_interval' => self::defaultCleanupInterval(),
            'tornevall_dnsbl_filter_types' => self::defaultSelectedFlags(),
            'tornevall_dnsbl_nocomment' => '0',
            'tornevall_dnsbl_blockfull' => '0',
            'tornevall_dnsbl_delisting_page' => 0,
            'tornevall_dnsbl_resolver_hosts' => implode(',', self::defaultResolvers()),
            'tornevall_dnsbl_whitelist' => implode("\n", self::defaultWhitelistEntries()),
            'tornevall_dnsbl_blocked_redirecturl' => self::defaultBlockedRedirectUrl(),
            'tornevall_dnsbl_comments_disabled_style' => self::defaultCommentsDisabledStyle(),
            'tornevall_dnsbl_delistingpage_comments_disabled' => '0',
            'tornevall_dnsbl_dev_mode' => '0',
            'tornevall_dnsbl_tools_token' => '',
            'tornevall_dnsbl_tools_mode' => 'prod',
            'tornevall_dnsbl_removal_token' => '',
            'tornevall_dnsbl_comment_turnstile_enabled' => '0',
            'tornevall_dnsbl_comment_turnstile_site_key' => '',
            'tornevall_dnsbl_comment_turnstile_secret_key' => '',
            'tornevall_dnsbl_comment_turnstile_theme' => 'auto',
            'tornevall_dnsbl_registration_dnsbl_enabled' => '1',
            'tornevall_dnsbl_registration_turnstile_enabled' => '1',
            'tornevall_dnsbl_cache_last_cleanup' => 0,
        ];
    }

    public static function minimumCacheAge(): int
    {
        return 300;
    }

    public static function defaultCacheAge(): int
    {
        return 600;
    }

    public static function minimumCleanupInterval(): int
    {
        return 300;
    }

    public static function defaultCleanupInterval(): int
    {
        return 300;
    }

    public static function getCacheTtl(): int
    {
        $cacheAge = (int) get_option('tornevall_dnsbl_cache_age');
        if ($cacheAge < self::minimumCacheAge()) {
            $cacheAge = self::defaultCacheAge();
        }

        return $cacheAge;
    }

    public static function getCacheCleanupInterval(): int
    {
        $interval = (int) get_option('tornevall_dnsbl_cache_cleanup_interval');
        if ($interval < self::minimumCleanupInterval()) {
            $interval = self::defaultCleanupInterval();
        }

        return $interval;
    }

    public static function cronSchedules($schedules): array
    {
        if (!is_array($schedules)) {
            $schedules = [];
        }

        $interval = self::getCacheCleanupInterval();
        $schedules['tornevall_dnsbl_cache_cleanup_custom'] = [
            'interval' => $interval,
            'display' => sprintf(
                __('Tornevall DNSBL cache cleanup every %d minutes', 'tornevall-networks-dnsbl-implementation'),
                max(1, (int) ceil($interval / MINUTE_IN_SECONDS))
            ),
        ];

        return $schedules;
    }

    public static function syncCacheCleanupSchedule(): void
    {
        if (!function_exists('wp_next_scheduled')) {
            return;
        }

        $hook = 'tornevall_dnsbl_cache_cleanup';
        $interval = self::getCacheCleanupInterval();
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event($hook) : null;

        if ($event && isset($event->interval) && (int) $event->interval !== $interval) {
            wp_unschedule_event($event->timestamp, $hook, (array) $event->args);
            $event = null;
        }

        if (!$event && !wp_next_scheduled($hook)) {
            wp_schedule_event(time() + $interval, 'tornevall_dnsbl_cache_cleanup_custom', $hook);
        }
    }

    public static function clearCacheCleanupSchedule(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('tornevall_dnsbl_cache_cleanup');
        }
    }

    public static function maybeRunCacheCleanup(): void
    {
        $interval = self::getCacheCleanupInterval();
        $lastCleanup = (int) get_option('tornevall_dnsbl_cache_last_cleanup');
        if ($lastCleanup > 0 && (time() - $lastCleanup) < $interval) {
            return;
        }

        self::purgeExpiredCache();
    }

    public static function purgeExpiredCache(): int
    {
        global $wpdb;

        $tableCache = self::getCacheTableName($wpdb);
        if (!self::tableExists($wpdb, $tableCache)) {
            update_option('tornevall_dnsbl_cache_last_cleanup', time());
            return 0;
        }

        $threshold = time() - self::getCacheTtl();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
        $deletedRows = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$tableCache} WHERE lastResolve IS NULL OR lastResolve < %d", $threshold));

        update_option('tornevall_dnsbl_cache_last_cleanup', time());

        return $deletedRows > 0 ? $deletedRows : 0;
    }

    public static function isAdminContext(): bool
    {
        return current_user_can('administrator') || is_admin();
    }

    public static function isPrivilegedUser(): bool
    {
        return is_user_logged_in() && current_user_can('manage_options');
    }

    public static function isAdminBackOfficeRequest(): bool
    {
        return is_admin() && self::isPrivilegedUser();
    }

    public static function enqueue($hook = ''): void
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
            Migrations::schemaVersion(),
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

    public static function checkpoint(): void
    {
        global $dnsbl_blacklist_status, $dnsbl_blacklist_control_status;

        self::maybeRunCacheCleanup();

        $remoteAddr = self::currentVisitorIp();
        if (!$remoteAddr) {
            $dnsbl_blacklist_status = false;
            $dnsbl_blacklist_control_status = 'checked';
            return;
        }

        $evaluation = self::evaluateBlacklistState($remoteAddr);
        $source = is_admin() ? 'admin-request' : (!empty($evaluation['dry_run']) ? 'dry-run-request' : 'request');
        self::recordStat((string) ($evaluation['evaluated_ip'] ?? $remoteAddr), (int) $evaluation['bitmask'], !empty($evaluation['blocked']), $source);
        $dnsbl_blacklist_status = !empty($evaluation['blocked']);
        $dnsbl_blacklist_control_status = 'checked';
    }

    public static function defaultResolvers(): array
    {
        return [
            'dnsbl.tornevall.org',
            'bl.fraudbl.org',
        ];
    }

    public static function defaultSelectedFlags(): array
    {
        return [
            'IP_CONFIRMED',
            'IP_FRAUDCOMMERCE',
            'IP_SECOND_EXIT',
            'IP_ABUSE_NO_SMTP',
            'IP_ANONYMOUS',
        ];
    }

    /**
     * @return list<string>
     */
    public static function legacyDefaultSelectedFlags(): array
    {
        return [
            'IP_CONFIRMED',
            'IP_SECOND_EXIT',
            'IP_ABUSE_NO_SMTP',
            'IP_ANONYMOUS',
        ];
    }

    public static function defaultBlockedRedirectUrl(): string
    {
        return 'https://www.tornevall.net/removal/';
    }

    public static function canonicalBlockedRedirectUrl($redirectUrl): string
    {
        $redirectUrl = trim((string) $redirectUrl);

        if ($redirectUrl === '' || $redirectUrl === 'https://dnsbl.tornevall.org/removal?redirected') {
            return self::defaultBlockedRedirectUrl();
        }

        return $redirectUrl;
    }

    public static function getBlockedRedirectUrl(): string
    {
        $redirectUrl = (string) get_option('tornevall_dnsbl_blocked_redirecturl');
        $normalized = self::canonicalBlockedRedirectUrl($redirectUrl);

        if ($redirectUrl !== $normalized) {
            update_option('tornevall_dnsbl_blocked_redirecturl', $normalized);
        }

        return $normalized;
    }

    public static function defaultCommentsDisabledStyle(): string
    {
        return 'font-weight: bold;';
    }

    public static function getCommentsDisabledStyle(): string
    {
        $style = (string) get_option('tornevall_dnsbl_comments_disabled_style');

        return $style !== '' ? $style : self::defaultCommentsDisabledStyle();
    }

    public static function commentsAreHiddenForListedVisitors(): bool
    {
        return get_option('tornevall_dnsbl_nocomment') === '1';
    }

    public static function commentTurnstileEnabled(): bool
    {
        return get_option('tornevall_dnsbl_comment_turnstile_enabled') === '1'
            && self::commentTurnstileSiteKey() !== ''
            && self::commentTurnstileSecretKey() !== '';
    }

    public static function registrationDnsblEnabled(): bool
    {
        return get_option('tornevall_dnsbl_registration_dnsbl_enabled') === '1';
    }

    public static function registrationTurnstileEnabled(): bool
    {
        return get_option('tornevall_dnsbl_registration_turnstile_enabled') === '1'
            && self::commentTurnstileSiteKey() !== ''
            && self::commentTurnstileSecretKey() !== '';
    }

    public static function commentTurnstileSiteKey(): string
    {
        return trim((string) get_option('tornevall_dnsbl_comment_turnstile_site_key'));
    }

    public static function commentTurnstileSecretKey(): string
    {
        return trim((string) get_option('tornevall_dnsbl_comment_turnstile_secret_key'));
    }

    public static function normalizeCommentTurnstileTheme($theme): string
    {
        $theme = sanitize_key((string) $theme);

        return in_array($theme, ['auto', 'light', 'dark'], true) ? $theme : 'auto';
    }

    public static function commentTurnstileTheme(): string
    {
        return self::normalizeCommentTurnstileTheme(get_option('tornevall_dnsbl_comment_turnstile_theme'));
    }

    public static function currentVisitorIp(): string
    {
        $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        return ($remoteAddr && filter_var($remoteAddr, FILTER_VALIDATE_IP)) ? $remoteAddr : '';
    }

    public static function isFrontendDryRunAvailable(): bool
    {
        return !is_admin()
            && self::isPrivilegedUser();
    }

    public static function isFrontendDryRunEnabled(): bool
    {
        if (!self::isFrontendDryRunAvailable()) {
            return false;
        }

        return get_user_meta(get_current_user_id(), self::FRONTEND_DRY_RUN_USER_META, true) === '1';
    }

    public static function getFrontendDryRunIp(): string
    {
        return self::FRONTEND_DRY_RUN_IP;
    }

    public static function getEffectiveEvaluationIp($addr): string
    {
        $addr = (string) $addr;
        if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_IP)) {
            return '';
        }

        if (!is_admin() && self::isFrontendDryRunEnabled() && self::currentVisitorIp() === $addr) {
            return self::getFrontendDryRunIp();
        }

        return $addr;
    }

    public static function getFrontendDryRunToggleUrl($enable = true, $redirectUrl = ''): string
    {
        if ($redirectUrl === '') {
            $redirectUrl = self::currentUrl();
        }

        $url = add_query_arg([
            'action' => 'tornevall_dnsbl_toggle_frontend_dry_run',
            'enable' => $enable ? '1' : '0',
            'redirect_to' => rawurlencode((string) $redirectUrl),
        ], admin_url('admin-post.php'));

        return wp_nonce_url($url, 'tornevall_dnsbl_toggle_frontend_dry_run');
    }

    public static function handleFrontendDryRunToggle(): void
    {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'tornevall-networks-dnsbl-implementation'));
        }

        check_admin_referer('tornevall_dnsbl_toggle_frontend_dry_run');

        $enable = isset($_GET['enable']) && sanitize_key(wp_unslash($_GET['enable'])) === '1';
        update_user_meta(get_current_user_id(), self::FRONTEND_DRY_RUN_USER_META, $enable ? '1' : '0');

        $redirectUrl = isset($_GET['redirect_to']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['redirect_to']))) : '';
        if ($redirectUrl === '') {
            $redirectUrl = home_url('/');
        }

        $redirectUrl = remove_query_arg(['tornevall_dnsbl_notice', 'tornevall_dnsbl_notice_type'], $redirectUrl);

        wp_safe_redirect(add_query_arg([
            'tornevall_dnsbl_notice' => $enable ? 'dry-run-enabled' : 'dry-run-disabled',
            'tornevall_dnsbl_notice_type' => 'success',
        ], $redirectUrl));
        exit;
    }

    public static function addFrontendDryRunAdminBarMenu($adminBar): void
    {
        if (!self::isFrontendDryRunAvailable() || !is_admin_bar_showing()) {
            return;
        }

        $enabled = self::isFrontendDryRunEnabled();
        $adminBar->add_node([
            'id' => 'tornevall-dnsbl-dry-run',
            'title' => $enabled
                ? __('DNSBL Dry Run: ON (127.0.0.255)', 'tornevall-networks-dnsbl-implementation')
                : __('DNSBL Dry Run: OFF', 'tornevall-networks-dnsbl-implementation'),
            'href' => self::getFrontendDryRunToggleUrl(!$enabled),
            'meta' => [
                'title' => $enabled
                    ? __('Disable frontend dry run', 'tornevall-networks-dnsbl-implementation')
                    : __('Enable frontend dry run', 'tornevall-networks-dnsbl-implementation'),
            ],
        ]);
    }

    public static function renderFrontendDryRunBanner(): void
    {
        if (!self::isFrontendDryRunAvailable()) {
            return;
        }

        $enabled = self::isFrontendDryRunEnabled();
        $toggleUrl = self::getFrontendDryRunToggleUrl(!$enabled);

        echo '<div style="position:fixed;right:16px;bottom:16px;z-index:99999;max-width:340px;background:#111827;color:#f9fafb;padding:14px 16px;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.25);font-size:13px;line-height:1.5;">';
        echo '<strong>' . esc_html__('DNSBL frontend dry run', 'tornevall-networks-dnsbl-implementation') . '</strong><br>';
        echo $enabled
            ? esc_html__('Enabled: the current frontend request is evaluated as 127.0.0.255 so blacklist handling can be tested safely.', 'tornevall-networks-dnsbl-implementation')
            : esc_html__('Disabled: live visitor IP evaluation is active. Turn this on to simulate a blacklisted visitor safely on the public site.', 'tornevall-networks-dnsbl-implementation');
        echo '<div style="margin-top:10px;">';
        echo '<a href="' . esc_url($toggleUrl) . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:8px 12px;border-radius:6px;">';
        echo esc_html($enabled ? __('Disable dry run', 'tornevall-networks-dnsbl-implementation') : __('Enable dry run', 'tornevall-networks-dnsbl-implementation'));
        echo '</a>';
        echo '</div>';
        echo '</div>';
    }

    private static function currentUrl(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $requestUri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';

        return $scheme . $host . $requestUri;
    }

    public static function defaultWhitelistEntries(): array
    {
        $remoteAddr = self::currentVisitorIp();
        return $remoteAddr !== '' ? [$remoteAddr] : [];
    }

    public static function normalizeWhitelistToken($value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return '';
        }

        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }

        if (strpos($value, '/') === false) {
            return '';
        }

        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, '');
        $ip = trim($ip);
        $prefix = trim($prefix);

        if (!filter_var($ip, FILTER_VALIDATE_IP) || $prefix === '' || !ctype_digit($prefix)) {
            return '';
        }

        $maxPrefix = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
        $prefix = (int) $prefix;
        if ($prefix < 0 || $prefix > $maxPrefix) {
            return '';
        }

        return $ip . '/' . $prefix;
    }

    public static function parseWhitelistEntries($value): array
    {
        if (is_array($value)) {
            $value = implode("\n", $value);
        }

        $parts = preg_split('/[\s,]+/', (string) $value);
        $entries = [];
        foreach ((array) $parts as $part) {
            $normalized = self::normalizeWhitelistToken($part);
            if ($normalized !== '') {
                $entries[] = $normalized;
            }
        }

        return array_values(array_unique($entries));
    }

    public static function getWhitelistEntries(): array
    {
        $entries = self::parseWhitelistEntries(get_option('tornevall_dnsbl_whitelist'));
        if (!count($entries)) {
            $entries = self::defaultWhitelistEntries();
        }

        return array_values(array_unique($entries));
    }

    public static function ipMatchesCidr($ip, $cidr): bool
    {
        [$rangeIp, $prefixLength] = array_pad(explode('/', $cidr, 2), 2, '');
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($rangeIp, FILTER_VALIDATE_IP) || $prefixLength === '' || !ctype_digit($prefixLength)) {
            return false;
        }

        $ipBinary = inet_pton($ip);
        $rangeBinary = inet_pton($rangeIp);
        if ($ipBinary === false || $rangeBinary === false || strlen($ipBinary) !== strlen($rangeBinary)) {
            return false;
        }

        $prefixLength = (int) $prefixLength;
        $bytesLength = strlen($ipBinary);
        $maxPrefix = $bytesLength * 8;
        if ($prefixLength < 0 || $prefixLength > $maxPrefix) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($rangeBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($rangeBinary[$fullBytes]) & $mask);
    }

    public static function isWhitelistedIp($ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach (self::getWhitelistEntries() as $entry) {
            if ($entry === $ip) {
                return true;
            }
            if (strpos($entry, '/') !== false && self::ipMatchesCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    public static function getResolverHosts(): array
    {
        $resolverNames = array_values(array_filter(array_map('trim', explode(',', (string) get_option('tornevall_dnsbl_resolver_hosts')))));
        if (!count($resolverNames)) {
            $resolverNames = self::defaultResolvers();
        }

        return $resolverNames;
    }

    public static function defaultFlagMap(): array
    {
        return [
            'FREE_SLOT_1_PREVIOUSLY_REPORTED' => 1,
            'IP_CONFIRMED' => 2,
            'IP_PHISHING' => 4,
            'IP_FRAUDCOMMERCE' => 8,
            'IP_MAILSERVER_SPAM' => 16,
            'IP_SECOND_EXIT' => 32,
            'IP_ABUSE_NO_SMTP' => 64,
            'IP_ANONYMOUS' => 128,
            'BIT_256' => 256,
        ];
    }

    public static function canonicalFlagName($flagName): string
    {
        $flagName = strtoupper(preg_replace('/[^A-Z0-9_]+/i', '', (string) $flagName));

        return [
            'FREE_SLOT_8_PREVIOUSLY_PROXYTIMEOUT' => 'IP_FRAUDCOMMERCE',
        ][$flagName] ?? $flagName;
    }

    public static function isPowerOfTwo($value): bool
    {
        $number = (int) $value;

        return $number > 0 && (($number & ($number - 1)) === 0);
    }

    public static function normalizeFlagMap($structure): array
    {
        $normalized = [];

        foreach ((array) $structure as $flagName => $bitValue) {
            $flagName = self::canonicalFlagName($flagName);
            $bitValue = (int) $bitValue;

            if ($flagName === '' || !self::isPowerOfTwo($bitValue)) {
                continue;
            }

            $normalized[$flagName] = $bitValue;
        }

        if (!count($normalized)) {
            $normalized = self::defaultFlagMap();
        }

        asort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    public static function getCurrentFlagMap(): array
    {
        $structure = get_option('tornevall_dnsbl_current_flags');
        $normalized = self::normalizeFlagMap($structure);

        if (!is_array($structure) || $structure !== $normalized) {
            update_option('tornevall_dnsbl_current_flags', $normalized);
        }

        return $normalized;
    }

    public static function decodeBitmask($bitmask): array
    {
        $mask = (int) $bitmask;
        if ($mask <= 0) {
            return [];
        }

        $activeFlags = [];
        foreach (self::getCurrentFlagMap() as $flagName => $bitValue) {
            if (($mask & $bitValue) === $bitValue) {
                $activeFlags[] = $flagName;
            }
        }

        return $activeFlags;
    }

    public static function combineBitmasks($bitmasks): int
    {
        $combined = 0;
        foreach ((array) $bitmasks as $bitmask) {
            $combined |= (int) $bitmask;
        }

        return $combined;
    }

    public static function getSelectedFlags(): array
    {
        $selected = get_option('tornevall_dnsbl_filter_types');
        $normalized = self::normalizeSelectedFlags($selected);

        if (!is_array($selected) || $selected !== $normalized) {
            update_option('tornevall_dnsbl_filter_types', $normalized);
        }

        return $normalized;
    }

    public static function maybeUpgradeSelectedFlags(): void
    {
        $selected = get_option('tornevall_dnsbl_filter_types');
        $normalized = self::normalizeSelectedFlags($selected);
        $legacyDefaults = self::legacyDefaultSelectedFlags();
        $canonicalDefault = self::defaultSelectedFlags();

        if ($normalized === $legacyDefaults) {
            $normalized = $canonicalDefault;
        }

        if (!is_array($selected) || $selected !== $normalized) {
            update_option('tornevall_dnsbl_filter_types', $normalized);
        }
    }

    public static function normalizeSelectedFlags($selected): array
    {
        $selected = is_array($selected) ? $selected : [];
        $availableFlags = array_keys(self::getCurrentFlagMap());
        $normalized = [];

        foreach ($selected as $flagName) {
            $flagName = self::canonicalFlagName($flagName);

            if ($flagName !== '' && in_array($flagName, $availableFlags, true)) {
                $normalized[] = $flagName;
            }
        }

        $normalized = array_values(array_unique($normalized));

        if (!count($normalized)) {
            $normalized = self::defaultSelectedFlags();
        }

        return $normalized;
    }

    public static function matchesSelectedFlags($bitmask): bool
    {
        $selectedFlags = self::getSelectedFlags();

        foreach (self::decodeBitmask($bitmask) as $flagName) {
            if (in_array($flagName, $selectedFlags, true)) {
                return true;
            }
        }

        return false;
    }

    public static function toolsBaseUrl(): string
    {
        $rawMode = get_option('tornevall_dnsbl_tools_mode');
        $mode = self::canonicalToolsMode($rawMode);

        if ((string) $rawMode !== $mode) {
            update_option('tornevall_dnsbl_tools_mode', $mode);
        }

        if ($mode === 'dev') {
            return 'https://tools.tornevall.com';
        }

        return 'https://tools.tornevall.net';
    }

    public static function canonicalToolsMode($mode): string
    {
        return sanitize_key((string) $mode) === 'dev' ? 'dev' : 'prod';
    }

    public static function toolsToken(): string
    {
        return trim((string) get_option('tornevall_dnsbl_tools_token'));
    }

    public static function toolsRequest($path, $payload = [], $method = 'POST'): array
    {
        $url = untrailingslashit(self::toolsBaseUrl()) . '/' . ltrim($path, '/');
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $token = self::toolsToken();
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

    public static function toolsAssessComment($ip, $commentData = []): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return [
                'blocked' => false,
                'reason' => 'invalid-ip',
                'source' => 'tools',
            ];
        }

        $token = self::toolsToken();
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
                'comment_author' => isset($commentData['comment_author']) ? (string) $commentData['comment_author'] : '',
                'comment_author_email' => isset($commentData['comment_author_email']) ? (string) $commentData['comment_author_email'] : '',
                'comment_author_url' => isset($commentData['comment_author_url']) ? (string) $commentData['comment_author_url'] : '',
                'comment_content' => isset($commentData['comment_content']) ? (string) $commentData['comment_content'] : '',
            ],
        ];

        $response = self::toolsRequest('/api/tools/dnsbl/comment-assess', $payload, 'POST');
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
            'reason' => isset($body['reason']) ? (string) $body['reason'] : 'ok',
            'source' => 'tools',
        ];
    }

    public static function reverseIp($addr): ?string
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

    public static function extractRequestResponses($lookup): array
    {
        if (!is_array($lookup)) {
            return [];
        }

        $requestResponse = isset($lookup['response']['requestResponse']) && is_array($lookup['response']['requestResponse'])
            ? $lookup['response']['requestResponse']
            : [];

        return array_values(array_filter($requestResponse, 'is_array'));
    }

    public static function buildLookupResult($ip, $lookup = null): array
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
            $lookup = self::resolveAddr($ip);
        }

        $result = [
            'ip' => $ip,
            'listed' => false,
            'typebit' => 0,
            'constants' => [],
            'raw' => $lookup,
            'message' => '',
        ];

        $requestResponse = self::extractRequestResponses($lookup);
        if (!count($requestResponse)) {
            $result['message'] = __('Not blacklisted', 'tornevall-networks-dnsbl-implementation');
            return $result;
        }

        $typeBits = [];
        foreach ($requestResponse as $row) {
            $typeBits[] = isset($row['typebit']) ? (int) $row['typebit'] : 0;
        }

        $result['listed'] = true;
        $result['typebit'] = self::combineBitmasks($typeBits);
        $result['constants'] = self::decodeBitmask($result['typebit']);
        $result['message'] = __('Blacklisted', 'tornevall-networks-dnsbl-implementation');

        return $result;
    }

    public static function formatDiagnosticPayload($payload): string
    {
        if (is_scalar($payload) || $payload === null) {
            return (string) $payload;
        }

        $encoded = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : (string) wp_json_encode(['unserializable' => true]);
    }

    public static function contentHandler($content)
    {
        global $post;

        if (!$post || !isset($post->ID)) {
            return $content;
        }

        $currentDelistingPage = (int) get_option('tornevall_dnsbl_delisting_page');
        if (!$currentDelistingPage || (int) $post->ID !== $currentDelistingPage) {
            return $content;
        }

        $removalPlaceholder = '<div style="border:1px solid #cbd5e1; padding:10px; border-radius:6px; background:#f8fafc;">'
            . '<strong>' . esc_html__('Removal tools are handled through Tornevall Tools.', 'tornevall-networks-dnsbl-implementation') . '</strong><br>'
            . esc_html__('DNS lookup checks continue to work from this plugin.', 'tornevall-networks-dnsbl-implementation')
            . '</div>';

        if (preg_match('/\[dnsbl_removal_form]/i', (string) $content)) {
            return preg_replace('/\[dnsbl_removal_form]/i', $removalPlaceholder, (string) $content);
        }

        return (string) $content . '<br>' . $removalPlaceholder;
    }

    public static function disableComments($open)
    {
        global $post, $dnsbl_blacklist_control_status, $dnsbl_blacklist_status;

        $remoteAddr = self::currentVisitorIp();
        $currentDelistingPage = (int) get_option('tornevall_dnsbl_delisting_page');

        if ($dnsbl_blacklist_control_status !== 'checked' && $remoteAddr) {
            $dnsbl_blacklist_status = !empty(self::evaluateBlacklistState($remoteAddr)['blocked']);
        }

        if ($post && isset($post->ID) && (int) $post->ID === $currentDelistingPage && get_option('tornevall_dnsbl_delistingpage_comments_disabled') === '1') {
            return false;
        }

        if ($dnsbl_blacklist_status) {
            if (get_option('tornevall_dnsbl_blockfull')) {
                wp_safe_redirect(self::getBlockedRedirectUrl(), 301);
                exit;
            }

            if (self::commentsAreHiddenForListedVisitors()) {
                return false;
            }
        }

        return $open;
    }

    public static function disableCommentsMessage($comments)
    {
        $remoteAddr = self::currentVisitorIp();
        if ($remoteAddr === '') {
            return $comments;
        }

        $evaluation = self::evaluateBlacklistState($remoteAddr, true);
        if (empty($evaluation['blocked']) || !self::commentsAreHiddenForListedVisitors()) {
            return $comments;
        }

        $commentsDisabledStyle = self::getCommentsDisabledStyle();

        if (self::isAdminBackOfficeRequest()) {
            return $comments;
        }

        echo '<div style="' . esc_attr($commentsDisabledStyle) . '">'
            . esc_html__('Comments section is currently unavailable because this visitor IP is matched by the active DNSBL policy.', 'tornevall-networks-dnsbl-implementation')
            . ' <a href="' . esc_url(self::getBlockedRedirectUrl()) . '" target="_blank" rel="noopener noreferrer">'
            . esc_html__('More information', 'tornevall-networks-dnsbl-implementation')
            . '</a>'
            . '</div>';

        return [];
    }

    public static function renderCommentTurnstileWidget(): void
    {
        if (is_admin() || !self::commentTurnstileEnabled()) {
            return;
        }

        self::renderTurnstileWidget(__('Comment verification', 'tornevall-networks-dnsbl-implementation'));
    }

    public static function renderRegistrationTurnstileWidget(): void
    {
        if (is_admin() || !self::registrationTurnstileEnabled()) {
            return;
        }

        self::renderTurnstileWidget(__('Account registration verification', 'tornevall-networks-dnsbl-implementation'));
    }

    private static function renderTurnstileWidget($label): void
    {
        if (self::commentTurnstileSiteKey() === '' || self::commentTurnstileSecretKey() === '') {
            return;
        }

        static $scriptRendered = false;

        echo '<p class="comment-form-tornevall-turnstile">';
        echo '<label style="display:block; margin-bottom:6px; font-weight:600;">' . esc_html($label) . '</label>';
        echo '<div class="cf-turnstile" data-sitekey="' . esc_attr(self::commentTurnstileSiteKey()) . '" data-theme="' . esc_attr(self::commentTurnstileTheme()) . '"></div>';
        echo '</p>';

        if (!$scriptRendered) {
            $scriptRendered = true;
            echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        }
    }

    public static function verifyCommentTurnstile($responseToken, $ip): array
    {
        if (!self::commentTurnstileEnabled()) {
            return ['success' => true, 'message' => 'disabled'];
        }

        return self::verifyTurnstileToken($responseToken, $ip);
    }

    private static function verifyTurnstileToken($responseToken, $ip): array
    {
        if (self::commentTurnstileSiteKey() === '' || self::commentTurnstileSecretKey() === '') {
            return ['success' => true, 'message' => 'disabled'];
        }

        $responseToken = trim((string) $responseToken);
        if ($responseToken === '') {
            return [
                'success' => false,
                'message' => __('Verification failed. Please complete the Turnstile check.', 'tornevall-networks-dnsbl-implementation'),
            ];
        }

        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 8,
            'body' => [
                'secret' => self::commentTurnstileSecretKey(),
                'response' => $responseToken,
                'remoteip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Verification could not be completed right now. Please try again.', 'tornevall-networks-dnsbl-implementation'),
            ];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['success'])) {
            return [
                'success' => false,
                'message' => __('Verification failed. Please try again.', 'tornevall-networks-dnsbl-implementation'),
            ];
        }

        return ['success' => true, 'message' => 'ok'];
    }

    public static function verifyRegistrationTurnstile($responseToken, $ip): array
    {
        if (!self::registrationTurnstileEnabled()) {
            return ['success' => true, 'message' => 'disabled'];
        }

        return self::verifyTurnstileToken($responseToken, $ip);
    }

    public static function stopCommentSubmission($message, $statusCode = 403): void
    {
        wp_die(
            wp_kses_post($message),
            esc_html__('Comment submission blocked', 'tornevall-networks-dnsbl-implementation'),
            [
                'response' => (int) $statusCode,
                'back_link' => true,
            ]
        );
    }

    public static function preprocessComment($commentdata)
    {
        $commentdata = is_array($commentdata) ? $commentdata : [];

        if (self::isAdminBackOfficeRequest()) {
            return $commentdata;
        }

        $ip = isset($commentdata['comment_author_IP']) ? (string) $commentdata['comment_author_IP'] : self::currentVisitorIp();

        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $evaluation = self::evaluateBlacklistState($ip, true);

            if (!empty($evaluation['blocked']) && (self::commentsAreHiddenForListedVisitors() || get_option('tornevall_dnsbl_blockfull') === '1')) {
                self::recordStat($ip, (int) $evaluation['bitmask'], true, 'comment-rejected');
                self::stopCommentSubmission(
                    sprintf(
                        '%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                        esc_html__('Comment submission is blocked for this visitor because the active DNSBL policy marked the request as untrusted.', 'tornevall-networks-dnsbl-implementation'),
                        esc_url(self::getBlockedRedirectUrl()),
                        esc_html__('More information', 'tornevall-networks-dnsbl-implementation')
                    )
                );
            }
        }

        $turnstile = isset($_POST['cf-turnstile-response']) ? sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'])) : '';
        $turnstileVerification = self::verifyCommentTurnstile($turnstile, $ip);
        if (empty($turnstileVerification['success'])) {
            self::stopCommentSubmission((string) $turnstileVerification['message'], 400);
        }

        return $commentdata;
    }

    public static function validateRegistrationErrors($errors, $sanitizedUserLogin, $userEmail)
    {
        if (!($errors instanceof \WP_Error)) {
            $errors = new \WP_Error();
        }

        if (self::isAdminBackOfficeRequest()) {
            return $errors;
        }

        $ip = self::currentVisitorIp();
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && self::registrationDnsblEnabled()) {
            $evaluation = self::evaluateBlacklistState($ip, true);
            self::recordStat($ip, (int) $evaluation['bitmask'], !empty($evaluation['blocked']), 'registration-attempt');

            if (!empty($evaluation['blocked'])) {
                self::recordStat($ip, (int) $evaluation['bitmask'], true, 'registration-rejected');
                $errors->add(
                    'tornevall_dnsbl_registration_blocked',
                    __('Account registration is blocked because the current visitor IP matches the active DNSBL policy.', 'tornevall-networks-dnsbl-implementation')
                );

                return $errors;
            }
        }

        $turnstile = isset($_POST['cf-turnstile-response']) ? sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'])) : '';
        $turnstileVerification = self::verifyRegistrationTurnstile($turnstile, $ip);
        if (empty($turnstileVerification['success'])) {
            self::recordStat($ip, 0, true, 'registration-turnstile-failed');
            $errors->add('tornevall_dnsbl_registration_turnstile', (string) $turnstileVerification['message']);
        }

        return $errors;
    }

    public static function resolveAddr($addr): array
    {
        $arpaName = self::reverseIp($addr);
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

        foreach (self::getResolverHosts() as $resolverName) {
            $resolveHost = $arpaName . '.' . $resolverName;
            $resultHost = @gethostbyname($resolveHost);
            if (!$resultHost || $resultHost === $resolveHost) {
                continue;
            }

            $resultEx = explode('.', $resultHost);
            if (count($resultEx) < 4 || (string) $resultEx[0] !== '127') {
                continue;
            }

            $hasBlacklist = true;
            $typeBit = self::combineBitmasks([$typeBit, (int) $resultEx[3]]);
        }

        if ($hasBlacklist) {
            $newArray[] = [
                'ip' => $addr,
                'constants' => self::decodeBitmask($typeBit),
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

    public static function checkBlacklist($addr, $getIsListed = false, $adminPassThrough = false)
    {
        $evaluation = self::evaluateBlacklistState($addr, $adminPassThrough);
        $bitMaskResponse = (int) $evaluation['bitmask'];
        if ($getIsListed) {
            return $bitMaskResponse;
        }

        if (self::isAdminBackOfficeRequest() && !$adminPassThrough) {
            return false;
        }

        return !empty($evaluation['blocked']);
    }

    public static function getWhitelistCurrentVisitorUrl($redirectUrl = ''): string
    {
        $ip = self::currentVisitorIp();
        if ($ip === '') {
            return '';
        }

        if ($redirectUrl === '') {
            $redirectUrl = wp_get_referer();
        }
        if (!$redirectUrl) {
            $redirectUrl = admin_url('admin.php?page=tornevallDnsblMenu');
        }

        $url = add_query_arg([
            'action' => 'tornevall_dnsbl_whitelist_current_visitor',
            'redirect_to' => rawurlencode($redirectUrl),
        ], admin_url('admin-post.php'));

        return wp_nonce_url($url, 'tornevall_dnsbl_whitelist_current_visitor');
    }

    public static function renderWhitelistCurrentVisitorButton($label = '', $className = 'button button-secondary', $redirectUrl = ''): string
    {
        $ip = self::currentVisitorIp();
        if ($ip === '' || self::isWhitelistedIp($ip)) {
            return '';
        }

        if ($label === '') {
            $label = __('Whitelist current visitor address', 'tornevall-networks-dnsbl-implementation');
        }

        return '<a class="' . esc_attr($className) . '" href="' . esc_url(self::getWhitelistCurrentVisitorUrl($redirectUrl)) . '">' . esc_html($label) . '</a>';
    }

    public static function handleWhitelistCurrentVisitorAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'tornevall-networks-dnsbl-implementation'));
        }

        check_admin_referer('tornevall_dnsbl_whitelist_current_visitor');

        $redirectUrl = isset($_GET['redirect_to']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['redirect_to']))) : '';
        if ($redirectUrl === '') {
            $redirectUrl = wp_get_referer();
        }
        if (!$redirectUrl) {
            $redirectUrl = admin_url('admin.php?page=tornevallDnsblMenu');
        }
        $redirectUrl = remove_query_arg(['tornevall_dnsbl_notice', 'tornevall_dnsbl_notice_type'], $redirectUrl);

        $ip = self::currentVisitorIp();
        $notice = 'invalid-ip';
        $noticeType = 'error';

        if ($ip !== '') {
            $entries = self::getWhitelistEntries();
            if (in_array($ip, $entries, true)) {
                $notice = 'already-whitelisted';
                $noticeType = 'info';
            } else {
                $entries[] = $ip;
                update_option('tornevall_dnsbl_whitelist', implode("\n", array_values(array_unique($entries))));
                $notice = 'whitelisted';
                $noticeType = 'success';
            }
        }

        wp_safe_redirect(add_query_arg([
            'tornevall_dnsbl_notice' => $notice,
            'tornevall_dnsbl_notice_type' => $noticeType,
        ], $redirectUrl));
        exit;
    }

    public static function renderActionNotice(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $notice = isset($_GET['tornevall_dnsbl_notice']) ? sanitize_key(wp_unslash($_GET['tornevall_dnsbl_notice'])) : '';
        $noticeType = isset($_GET['tornevall_dnsbl_notice_type']) ? sanitize_key(wp_unslash($_GET['tornevall_dnsbl_notice_type'])) : 'info';
        if ($notice === '') {
            return;
        }

        $messages = [
            'whitelisted' => __('The current visitor address has been added to the DNSBL whitelist.', 'tornevall-networks-dnsbl-implementation'),
            'already-whitelisted' => __('The current visitor address is already present in the DNSBL whitelist.', 'tornevall-networks-dnsbl-implementation'),
            'invalid-ip' => __('The current visitor address could not be determined, so no whitelist change was made.', 'tornevall-networks-dnsbl-implementation'),
            'dry-run-enabled' => __('Frontend dry run is enabled. This session is now evaluated as 127.0.0.255 on the public site.', 'tornevall-networks-dnsbl-implementation'),
            'dry-run-disabled' => __('Frontend dry run is disabled. Live visitor IP evaluation is active again.', 'tornevall-networks-dnsbl-implementation'),
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        echo '<div class="notice notice-' . esc_attr($noticeType) . ' is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }

    public static function renderProtectedUserNotice(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $remoteAddr = self::currentVisitorIp();
        if ($remoteAddr === '') {
            return;
        }

        $evaluation = self::evaluateBlacklistState($remoteAddr);
        if (empty($evaluation['admin_protected']) || empty($evaluation['matches_selected_flags']) || !empty($evaluation['whitelisted'])) {
            return;
        }

        $actionButton = self::renderWhitelistCurrentVisitorButton(__('Whitelist this address now', 'tornevall-networks-dnsbl-implementation'));

        echo '<div class="notice notice-warning">';
        echo '<p><strong>' . esc_html__('Tornevall DNSBL protected your administrator session.', 'tornevall-networks-dnsbl-implementation') . '</strong><br>';
        echo esc_html(sprintf(__('Your current IP address (%s) matches the active DNSBL trigger flags, but WordPress admin access is still allowed to prevent lockout.', 'tornevall-networks-dnsbl-implementation'), $remoteAddr));
        echo '</p>';
        echo '<p>';
        echo '<a class="button button-link" target="_blank" rel="noopener noreferrer" href="' . esc_url(self::getBlockedRedirectUrl()) . '">' . esc_html__('Read blacklist details', 'tornevall-networks-dnsbl-implementation') . '</a>';
        if ($actionButton !== '') {
            echo ' ' . $actionButton;
        }
        echo '</p>';
        echo '</div>';
    }

    public static function getCacheTableName($wpdb): string
    {
        return $wpdb->prefix . 'dnsblcache';
    }

    public static function getStatsTableName($wpdb): string
    {
        return $wpdb->prefix . 'dnsblstats';
    }

    public static function tableExists($wpdb, $tableName): bool
    {
        $existingTable = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName));

        return is_string($existingTable) && $existingTable === $tableName;
    }

    public static function evaluateBlacklistState($addr, $adminPassThrough = false): array
    {
        $effectiveAddr = self::getEffectiveEvaluationIp($addr);
        $isDryRun = $effectiveAddr !== '' && $effectiveAddr !== (string) $addr;

        if ($isDryRun && $effectiveAddr === self::getFrontendDryRunIp()) {
            $bitMaskResponse = (int) (self::getCurrentFlagMap()['IP_CONFIRMED'] ?? 2);
        } else {
            $bitMaskResponse = (int) self::checkBlacklistCache($effectiveAddr);
        }

        $matchesSelectedFlags = $bitMaskResponse > 0 && self::matchesSelectedFlags($bitMaskResponse);
        $isProtectedAdmin = self::isAdminBackOfficeRequest() && !$adminPassThrough;
        $isWhitelisted = !$isDryRun && self::isWhitelistedIp($effectiveAddr);

        return [
            'bitmask' => $bitMaskResponse,
            'listed' => $bitMaskResponse > 0,
            'matches_selected_flags' => $matchesSelectedFlags,
            'blocked' => $matchesSelectedFlags && !$isProtectedAdmin && !$isWhitelisted,
            'admin_protected' => $isProtectedAdmin,
            'whitelisted' => $isWhitelisted,
            'original_ip' => (string) $addr,
            'evaluated_ip' => $effectiveAddr,
            'dry_run' => $isDryRun,
        ];
    }

    public static function recordStat($addr, $responseBitmask, $wasBlocked, $source = 'request'): void
    {
        global $wpdb;

        static $loggedEvents = [];

        if (!filter_var($addr, FILTER_VALIDATE_IP)) {
            return;
        }

        $source = sanitize_key((string) $source);
        if ($source === '') {
            $source = 'request';
        }

        $eventKey = md5($addr . '|' . (int) $responseBitmask . '|' . ((int) $wasBlocked) . '|' . $source);
        if (isset($loggedEvents[$eventKey])) {
            return;
        }

        $tableStats = self::getStatsTableName($wpdb);
        if (!self::tableExists($wpdb, $tableStats)) {
            return;
        }

        $loggedEvents[$eventKey] = true;

        $wpdb->insert(
            $tableStats,
            [
                'ipAddr' => $addr,
                'resolveTime' => (int) $responseBitmask,
                'wasBlocked' => $wasBlocked ? 1 : 0,
                'source' => $source,
                'createdAt' => current_time('mysql', true),
            ],
            ['%s', '%d', '%d', '%s', '%s']
        );
    }

    public static function getStatsSummary($lookbackHours = 0): array
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
            'cached_entries' => 0,
            'cached_blacklist_entries' => 0,
            'cached_clear_entries' => 0,
            'last_cache_cleanup' => (int) get_option('tornevall_dnsbl_cache_last_cleanup'),
        ];

        $tableStats = self::getStatsTableName($wpdb);
        $tableCache = self::getCacheTableName($wpdb);
        $summary['has_stats_table'] = self::tableExists($wpdb, $tableStats);
        $summary['has_cache_table'] = self::tableExists($wpdb, $tableCache);

        if ($summary['has_stats_table']) {
            if ((int) $lookbackHours > 0) {
                $since = gmdate('Y-m-d H:i:s', time() - ((int) $lookbackHours * HOUR_IN_SECONDS));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
                $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total_checks, COUNT(DISTINCT ipAddr) AS unique_visitors, SUM(CASE WHEN resolveTime > 0 THEN 1 ELSE 0 END) AS blacklist_hits, SUM(CASE WHEN wasBlocked > 0 THEN 1 ELSE 0 END) AS blocked_requests, COUNT(DISTINCT CASE WHEN wasBlocked > 0 THEN ipAddr ELSE NULL END) AS blocked_unique_visitors FROM {$tableStats} WHERE source <> %s AND createdAt >= %s", 'admin-request', $since), ARRAY_A);
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
                $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total_checks, COUNT(DISTINCT ipAddr) AS unique_visitors, SUM(CASE WHEN resolveTime > 0 THEN 1 ELSE 0 END) AS blacklist_hits, SUM(CASE WHEN wasBlocked > 0 THEN 1 ELSE 0 END) AS blocked_requests, COUNT(DISTINCT CASE WHEN wasBlocked > 0 THEN ipAddr ELSE NULL END) AS blocked_unique_visitors FROM {$tableStats} WHERE source <> %s", 'admin-request'), ARRAY_A);
            }

            if (is_array($row)) {
                foreach (['total_checks', 'unique_visitors', 'blacklist_hits', 'blocked_requests', 'blocked_unique_visitors'] as $metricKey) {
                    $summary[$metricKey] = isset($row[$metricKey]) ? (int) $row[$metricKey] : 0;
                }
            }
        }

        if ($summary['has_cache_table']) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
            $cacheRow = $wpdb->get_row("SELECT COUNT(*) AS cached_entries, SUM(CASE WHEN lastResponse > 0 THEN 1 ELSE 0 END) AS cached_blacklist_entries FROM {$tableCache}", ARRAY_A);
            if (is_array($cacheRow)) {
                $summary['cached_entries'] = isset($cacheRow['cached_entries']) ? (int) $cacheRow['cached_entries'] : 0;
                $summary['cached_blacklist_entries'] = isset($cacheRow['cached_blacklist_entries']) ? (int) $cacheRow['cached_blacklist_entries'] : 0;
                $summary['cached_clear_entries'] = max(0, $summary['cached_entries'] - $summary['cached_blacklist_entries']);
            }
        }

        return $summary;
    }

    public static function checkBlacklistCache($addr): int
    {
        global $wpdb;

        if (!filter_var($addr, FILTER_VALIDATE_IP)) {
            return 0;
        }

        self::maybeRunCacheCleanup();

        $tableCache = self::getCacheTableName($wpdb);
        if (!self::tableExists($wpdb, $tableCache)) {
            return (int) self::buildLookupResult($addr, self::resolveAddr($addr))['typebit'];
        }

        $cacheAge = self::getCacheTtl();
        $now = time();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tableCache} WHERE ipAddr = %s", $addr));

        if (!$existing || !isset($existing->ipAddr)) {
            $typeBit = (int) self::buildLookupResult($addr, self::resolveAddr($addr))['typebit'];

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$tableCache} (ipAddr, lastResponse, lastResolve) VALUES (%s, %d, %d)",
                $addr,
                $typeBit,
                $now
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            return $typeBit;
        }

        $lastRes = $now - (int) (isset($existing->lastResolve) ? $existing->lastResolve : 0);
        if ($lastRes >= $cacheAge) {
            $typeBit = (int) self::buildLookupResult($addr, self::resolveAddr($addr))['typebit'];

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$tableCache} SET lastResponse = %d, lastResolve = %d WHERE ipAddr = %s",
                $typeBit,
                $now,
                $addr
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            return $typeBit;
        }

        return (int) $existing->lastResponse;
    }

    public static function preCommentApproved($approved, $commentdata)
    {
        $ip = isset($commentdata['comment_author_IP']) ? (string) $commentdata['comment_author_IP'] : '';
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return $approved;
        }

        $evaluation = self::evaluateBlacklistState($ip, true);
        self::recordStat($ip, (int) $evaluation['bitmask'], !empty($evaluation['blocked']), 'comment-submit');

        if (!empty($evaluation['blocked'])) {
            return 'spam';
        }

        $toolsAssessment = self::toolsAssessComment($ip, is_array($commentdata) ? $commentdata : []);
        if (!empty($toolsAssessment['blocked'])) {
            self::recordStat($ip, (int) $evaluation['bitmask'], true, 'tools-comment-submit');
            return 'spam';
        }

        return $approved;
    }
}

