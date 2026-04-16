<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin
{
    private const FRONTEND_DRY_RUN_USER_META = 'tornevall_dnsbl_frontend_dry_run';
    private const FRONTEND_DRY_RUN_IP = '127.0.0.255';
    private const DELIST_FORM_SHORTCODE = 'dnsbl_delist_form';
    private const REMOVAL_FORM_SHORTCODE = self::DELIST_FORM_SHORTCODE;
    private const REMOVAL_FORM_SHORTCODE_ALIAS = 'dnsbl_removal_form';
    private const REMOVAL_FORM_SHORTCODE_LEGACY = 'tornevall_dnsbl_removal_form';
    private const REMOVAL_FORM_AJAX_ACTION = 'tornevall_dnsbl_removal_form_submit';
    private const WRITE_PERMISSION_CACHE_TTL = 300;
    private const INTERNAL_DELIST_SELECTION = '__internal__';
    private const INTERNAL_DELIST_QUERY_VAR = 'tornevall_dnsbl_internal_delist';

    public static function registerHooks(): void
    {
        add_filter('cron_schedules', [self::class, 'cronSchedules']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('plugins_loaded', [self::class, 'loadTranslations']);
        add_action('plugins_loaded', [Migrations::class, 'maybeUpgrade']);
        add_filter('query_vars', [self::class, 'registerQueryVars']);
        add_action('init', [self::class, 'registerInternalDelistRewrite']);
        add_action('init', [self::class, 'syncCacheCleanupSchedule']);
        add_action('init', [self::class, 'checkpoint']);
        add_action('wp_ajax_tornevall_dnsbl_admin_tools', [\Tornevall\Networks\DNSBL\Admin::class, 'ajaxTools']);
        add_action('wp_ajax_tornevall_dnsbl_token_info', [\Tornevall\Networks\DNSBL\Admin::class, 'ajaxTokenInfo']);
        add_action('admin_post_tornevall_dnsbl_whitelist_current_visitor', [self::class, 'handleWhitelistCurrentVisitorAction']);
        add_action('admin_post_tornevall_dnsbl_toggle_frontend_dry_run', [self::class, 'handleFrontendDryRunToggle']);
        add_action('admin_notices', [self::class, 'renderActionNotice']);
        add_action('admin_notices', [self::class, 'renderProtectedUserNotice']);
        add_action('admin_notices', [self::class, 'renderWriteAccessAdminNotice']);
        add_action('admin_bar_menu', [self::class, 'addFrontendDryRunAdminBarMenu'], 100);
        add_action('tornevall_dnsbl_cache_cleanup', [self::class, 'purgeExpiredCache']);
        add_action('wp_footer', [self::class, 'renderFrontendDryRunBanner']);
        add_action('template_redirect', [self::class, 'maybeRenderInternalDelistPage']);
        add_action('wp_ajax_' . self::REMOVAL_FORM_AJAX_ACTION, [self::class, 'handleRemovalFormAjax']);
        add_action('wp_ajax_nopriv_' . self::REMOVAL_FORM_AJAX_ACTION, [self::class, 'handleRemovalFormAjax']);
        add_shortcode(self::REMOVAL_FORM_SHORTCODE, [self::class, 'renderRemovalFormShortcode']);
        add_shortcode(self::REMOVAL_FORM_SHORTCODE_ALIAS, [self::class, 'renderRemovalFormShortcode']);
        add_shortcode(self::REMOVAL_FORM_SHORTCODE_LEGACY, [self::class, 'renderRemovalFormShortcode']);

        add_filter('the_content', [self::class, 'contentHandler']);
        add_filter('comments_open', [self::class, 'disableComments'], 10, 1);
        add_filter('comments_array', [self::class, 'disableCommentsMessage'], 10, 1);
        add_filter('preprocess_comment', [self::class, 'preprocessComment'], 10, 1);
        add_filter('pre_comment_approved', [self::class, 'preCommentApproved'], 10, 2);
        add_action('comment_form_after_fields', [self::class, 'renderCommentTurnstileWidget']);
        add_action('comment_form_logged_in_after', [self::class, 'renderCommentTurnstileWidget']);
        add_action('register_form', [self::class, 'renderRegistrationTurnstileWidget']);
        add_filter('registration_errors', [self::class, 'validateRegistrationErrors'], 10, 3);

        // Auto-report spam: hook into Akismet spam transition if write token is configured.
        if (self::writeTokenSet() && self::autoReportSpamEnabled()) {
            add_action('transition_comment_status', [self::class, 'handleCommentStatusTransition'], 10, 3);
        }
    }

    public static function defaultOptions(): array
    {
        return [
            'tornevall_dnsbl_cache_age' => self::defaultCacheAge(),
            'tornevall_dnsbl_cache_cleanup_interval' => self::defaultCleanupInterval(),
            'tornevall_dnsbl_filter_types' => self::defaultSelectedFlags(),
            'tornevall_dnsbl_nocomment' => '0',
            'tornevall_dnsbl_blockfull' => '0',
            'tornevall_dnsbl_delisting_page' => '',
            'tornevall_dnsbl_internal_delist_slug' => 'delist',
            'tornevall_dnsbl_resolver_hosts' => implode(',', self::defaultResolvers()),
            'tornevall_dnsbl_whitelist' => implode("\n", self::defaultWhitelistEntries()),
            'tornevall_dnsbl_blocked_redirecturl' => self::defaultBlockedRedirectUrl(),
            'tornevall_dnsbl_comments_disabled_style' => self::defaultCommentsDisabledStyle(),
            'tornevall_dnsbl_delistingpage_comments_disabled' => '0',
            'tornevall_dnsbl_dev_mode' => '0',
            'tornevall_dnsbl_tools_token' => '', // legacy hidden fallback for older installs
            'tornevall_dnsbl_tools_mode' => 'prod',
            'tornevall_dnsbl_write_token' => '',
            'tornevall_dnsbl_removal_token' => '', // legacy alias – migrated to write_token
            'tornevall_dnsbl_auto_report_spam' => '0',
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

    public static function internalDelistSelectionValue(): string
    {
        return self::INTERNAL_DELIST_SELECTION;
    }

    public static function canonicalDelistingPageSelection($value): string
    {
        if (is_numeric($value)) {
            $pageId = absint($value);
            return $pageId > 0 ? 'page:' . $pageId : '';
        }

        $value = trim((string) $value);
        if ($value === '' || $value === '0' || strtolower($value) === 'none') {
            return '';
        }

        if ($value === self::INTERNAL_DELIST_SELECTION || strtolower($value) === 'internal') {
            return self::INTERNAL_DELIST_SELECTION;
        }

        if (preg_match('/^page:(\d+)$/', $value, $matches)) {
            $pageId = absint($matches[1] ?? 0);
            return $pageId > 0 ? 'page:' . $pageId : '';
        }

        $pageId = absint($value);

        return $pageId > 0 ? 'page:' . $pageId : '';
    }

    public static function configuredDelistingPageSelection(): string
    {
        return self::canonicalDelistingPageSelection(get_option('tornevall_dnsbl_delisting_page'));
    }

    public static function configuredDelistingPageId(): int
    {
        $selection = self::configuredDelistingPageSelection();
        if (preg_match('/^page:(\d+)$/', $selection, $matches)) {
            return absint($matches[1] ?? 0);
        }

        return 0;
    }

    public static function isInternalDelistingPageSelected(): bool
    {
        return self::configuredDelistingPageSelection() === self::INTERNAL_DELIST_SELECTION;
    }

    public static function sanitizeInternalDelistSlug($value): string
    {
        $slug = sanitize_title((string) $value);
        return $slug !== '' ? $slug : 'delist';
    }

    public static function internalDelistSlug(): string
    {
        return self::sanitizeInternalDelistSlug(get_option('tornevall_dnsbl_internal_delist_slug', 'delist'));
    }

    public static function internalDelistUrl(): string
    {
        return home_url('/' . self::internalDelistSlug() . '/');
    }

    public static function internalDelistQueryVar(): string
    {
        return self::INTERNAL_DELIST_QUERY_VAR;
    }

    /**
     * @param array<int,string> $vars
     * @return array<int,string>
     */
    public static function registerQueryVars($vars): array
    {
        if (!is_array($vars)) {
            $vars = [];
        }

        $queryVar = self::internalDelistQueryVar();
        if (!in_array($queryVar, $vars, true)) {
            $vars[] = $queryVar;
        }

        return $vars;
    }

    public static function registerInternalDelistRewrite(): void
    {
        $slug = trim(self::internalDelistSlug(), '/');
        if ($slug === '') {
            return;
        }

        add_rewrite_rule(
            '^' . preg_quote($slug, '#') . '/?$',
            'index.php?' . self::internalDelistQueryVar() . '=1',
            'top'
        );
    }

    public static function refreshInternalDelistRewriteRules(bool $hard = false): void
    {
        self::registerInternalDelistRewrite();
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules($hard);
        }
    }

    private static function currentRequestPath(): string
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $parsedPath = wp_parse_url('https://example.invalid' . $requestUri, PHP_URL_PATH);

        return trim((string) $parsedPath, '/');
    }

    private static function expectedInternalDelistPath(): string
    {
        $path = wp_parse_url(self::internalDelistUrl(), PHP_URL_PATH);

        return trim((string) $path, '/');
    }

    public static function isCurrentInternalDelistRequest(): bool
    {
        $queryVar = self::internalDelistQueryVar();
        $queryValue = get_query_var($queryVar, null);
        if ($queryValue !== null && $queryValue !== '' && $queryValue !== '0') {
            return true;
        }

        if (isset($_GET[$queryVar])) {
            $rawQueryValue = sanitize_text_field(wp_unslash((string) $_GET[$queryVar]));
            if ($rawQueryValue !== '' && $rawQueryValue !== '0') {
                return true;
            }
        }

        $expectedPath = self::expectedInternalDelistPath();
        if ($expectedPath === '') {
            return false;
        }

        $requestPath = self::currentRequestPath();
        if ($requestPath === $expectedPath) {
            return true;
        }

        $trimmedExpected = trim($expectedPath, '/');
        $trimmedRequest = trim($requestPath, '/');

        if ($trimmedRequest === $trimmedExpected) {
            return true;
        }

        if ($trimmedRequest === 'index.php/' . $trimmedExpected) {
            return true;
        }

        return str_ends_with($trimmedRequest, '/' . $trimmedExpected);
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

    public static function isDevModeEnabled(): bool
    {
        return get_option('tornevall_dnsbl_dev_mode') === '1';
    }

    public static function isToolsDevMode(): bool
    {
        return self::canonicalToolsMode(get_option('tornevall_dnsbl_tools_mode')) === 'dev';
    }

    public static function loadTranslations(): void
    {
        $domain = 'tornevall-networks-dnsbl-implementation';
        $locale = self::currentLocale();
        $relativeLanguageDir = dirname(plugin_basename(TORNEVALL_DNSBL_PLUGIN_FILE)) . '/language/';
        $languageDir = trailingslashit(TORNEVALL_DNSBL_PLUGIN_DIR . 'language');

        load_plugin_textdomain($domain, false, $relativeLanguageDir);

        foreach ([
            $languageDir . $domain . '-' . $locale . '.mo',
            $languageDir . 'tornevall_dnsbl-' . $locale . '.mo',
        ] as $moFile) {
            if (is_readable($moFile)) {
                load_textdomain($domain, $moFile);
                break;
            }
        }

        add_filter('gettext', [self::class, 'filterRuntimeTranslations'], 10, 3);
    }

    private static function currentLocale(): string
    {
        if (function_exists('determine_locale')) {
            return (string) determine_locale();
        }

        return function_exists('get_locale') ? (string) get_locale() : 'en_US';
    }

    public static function filterRuntimeTranslations($translation, $text, $domain)
    {
        if ($domain !== 'tornevall-networks-dnsbl-implementation') {
            return $translation;
        }

        if (strpos(strtolower(self::currentLocale()), 'sv') !== 0) {
            return $translation;
        }

        $fallbacks = [
            'DNSBL removal access is not active for this site.' => 'DNSBL-avlistningsåtkomst är inte aktiv för denna sajt.',
            'This site cannot offer live DNSBL/FraudBL removals because no DNSBL / Tools API token is configured.' => 'Denna sajt kan inte erbjuda live-avlistning i DNSBL/FraudBL eftersom ingen DNSBL / Tools API-token är konfigurerad.',
            'This site cannot offer live DNSBL/FraudBL removals because the configured token is missing delete / delist permission.' => 'Denna sajt kan inte erbjuda live-avlistning i DNSBL/FraudBL eftersom den konfigurerade tokenen saknar delete-/delist-behörighet.',
            'This site cannot offer live DNSBL/FraudBL removals because Tools could not verify the configured token permissions right now.' => 'Denna sajt kan inte erbjuda live-avlistning i DNSBL/FraudBL eftersom Tools inte kunde verifiera den konfigurerade tokenens behörigheter just nu.',
            'Request DNSBL access via Tools' => 'Begär DNSBL-behörighet via Tools',
            'Current Tools host: %s' => 'Aktuell Tools-värd: %s',
            'Admin passthrough' => 'Admin-passthrough',
            'Admin token' => 'Admin-token',
            'No DNSBL / Tools API token configured yet.' => 'Ingen DNSBL / Tools API-token är konfigurerad ännu.',
            'Live delisting is available for this site.' => 'Live-avlistning är tillgänglig för denna sajt.',
            'Delete / delist confirmed. Scope: %s' => 'Delete-/delist-behörighet bekräftad. Omfång: %s',
            'Delete / delist confirmed for the configured token.' => 'Delete-/delist-behörighet är bekräftad för den konfigurerade tokenen.',
            'The token is verified, but only add / list access is active right now.' => 'Tokenen är verifierad, men endast add-/list-behörighet är aktiv just nu.',
            'Scope: %s' => 'Omfång: %s',
            'Token verified, but delisting is not allowed yet.' => 'Tokenen är verifierad, men avlistning är ännu inte tillåten.',
            'Delisting is still locked for this site.' => 'Avlistning är fortfarande låst för denna sajt.',
            'The token is currently %s and does not expose delete / delist access.' => 'Tokenen är för närvarande %s och exponerar inte delete-/delist-behörighet.',
            'The token was checked, but delete / delist access is still unavailable.' => 'Tokenen kontrollerades, men delete-/delist-behörighet är fortfarande inte tillgänglig.',
            'Token permissions have not been confirmed yet.' => 'Tokenens behörigheter har ännu inte bekräftats.',
            'Run the permission check to confirm whether this token can delist through Tools.' => 'Kör behörighetskontrollen för att bekräfta om denna token kan avlista via Tools.',
            'Permissions: add %1$s · delete %2$s · update %3$s' => 'Behörigheter: add %1$s · delete %2$s · update %3$s',
            'Delete / delist permission confirmed: %s' => 'Delete-/delist-behörighet bekräftad: %s',
            'No token configured.' => 'Ingen token är konfigurerad.',
            'Checked host' => 'Kontrollerad värd',
            'Name' => 'Namn',
            'Scope' => 'Omfång',
            'Resolved via' => 'Löst via',
            'Can add (list IP)' => 'Kan lägga till (lista IP)',
            'Can delete (delist IP)' => 'Kan ta bort (avlista IP)',
            'Resolved host' => 'Löst värdnamn',
            'Can update' => 'Kan uppdatera',
            'The supplied token was recognized by Tools, but it is not currently exposing DNSBL delete permissions for this site.' => 'Den angivna tokenen känns igen av Tools, men exponerar för närvarande inte DNSBL delete-behörighet för denna sajt.',
            'These delisting-page controls stay read-only until delete / delist permission has been confirmed for the configured token.' => 'Dessa kontroller för avlistningssidan förblir skrivskyddade tills delete-/delist-behörighet har bekräftats för den konfigurerade tokenen.',
            'Tools environment mode is currently set to dev: %s' => 'Tools-miljöläget är för närvarande satt till dev: %s',
            'Tools integration needs attention before this site can use permission-aware DNSBL features.' => 'Tools-integrationen behöver ses över innan denna sajt kan använda behörighetsstyrda DNSBL-funktioner.',
            'The configured token is limited, so live delisting is still unavailable on this site.' => 'Den konfigurerade tokenen är begränsad, så live-avlistning är fortfarande inte tillgänglig på denna sajt.',
            'A delisting page is selected, but no DNSBL / Tools API token is configured yet.' => 'En avlistningssida är vald, men ingen DNSBL / Tools API-token är konfigurerad ännu.',
            'There are environment details worth reviewing for this plugin configuration.' => 'Det finns miljödetaljer som bör granskas för denna plugin-konfiguration.',
            'Enter an IP address to check whether it is currently listed. If the IP is listed, a delist request is sent automatically.' => 'Ange en IP-adress för att kontrollera om den är listad. Om IP-adressen är listad skickas en avlistningsbegäran automatiskt.',
            'Check if listed' => 'Kontrollera om adressen är listad',
            'Checking listing status…' => 'Kontrollerar listningsstatus…',
             'Checking Tools API in the background…' => 'Kontrollerar Tools API i bakgrunden…',
             'Preparing delist details…' => 'Förbereder avlistningsdetaljer…',
             'Delist is ready. You can now submit the request.' => 'Avlistning är klar att skickas. Du kan nu skicka begäran.',
             'Security session expired (HTTP 419). Please refresh this page and try again.' => 'Säkerhetssessionen har gått ut (HTTP 419). Ladda om sidan och försök igen.',
             'The Tools API follow-up could not be completed. The first DNS result is still shown above.' => 'Tools API-uppföljningen kunde inte slutföras. Det första DNS-resultatet visas fortfarande ovan.',
             'Enter an IP address and run the listing check first. If listed, the plugin continues a token-backed background check and then enables Delist.' => 'Ange en IP-adress och kör listningskontrollen först. Om den är listad fortsätter pluginet med en token-baserad bakgrundskontroll och aktiverar sedan Delist.',
             'Delist' => 'Avlista',
             'Advanced delist range (CIDR)' => 'Avancerat avlistningsintervall (CIDR)',
             'Optional: set a small CIDR range tied to the listed IP. Large ranges are blocked. Use /24 to /32 only.' => 'Valfritt: ange ett litet CIDR-intervall kopplat till den listade IP-adressen. Stora intervall blockeras. Använd endast /24 till /32.',
             'CIDR range' => 'CIDR-intervall',
             'Run the listing check first before using CIDR delist.' => 'Kör listningskontrollen först innan du använder CIDR-avlistning.',
             'CIDR value is empty.' => 'CIDR-värdet är tomt.',
             'CIDR must be an IPv4 range like 203.0.113.0/24.' => 'CIDR måste vara ett IPv4-intervall som 203.0.113.0/24.',
             'CIDR currently supports IPv4 only.' => 'CIDR stöder för närvarande endast IPv4.',
             'CIDR prefix must be between /24 and /32. Large ranges like /16 or /8 are blocked.' => 'CIDR-prefix måste vara mellan /24 och /32. Stora intervall som /16 eller /8 blockeras.',
             'CIDR must include the listed IP you just checked.' => 'CIDR måste inkludera den listade IP-adress du precis kontrollerade.',
             'Could not resolve the requested CIDR range into addresses.' => 'Det gick inte att lösa upp det begärda CIDR-intervallet till adresser.',
             'CIDR delist request failed in one of the bulk chunks.' => 'CIDR-avlistningen misslyckades i en av bulk-delarna.',
             'CIDR delist request accepted.' => 'CIDR-avlistningsbegäran accepterades.',
            'Send delist request' => 'Skicka avlistningsbegäran',
            'This IP is listed. Click the button again to send the delist request.' => 'Denna IP är listad. Klicka på knappen igen för att skicka avlistningsbegäran.',
             'This IP is listed. Delist is ready.' => 'Denna IP är listad. Avlistning är klar att skickas.',
             'This IP is listed. A Tools API follow-up is now running in the background to confirm delist details.' => 'Denna IP är listad. En Tools API-uppföljning körs nu i bakgrunden för att bekräfta avlistningsdetaljerna.',
            'Check and send delist request' => 'Kontrollera och skicka avlistningsbegäran',
            'This IP is not currently listed in DNSBL/FraudBL, so no delist request was sent.' => 'Denna IP är för närvarande inte listad i DNSBL/FraudBL, så ingen avlistningsbegäran skickades.',
             'This IP is not currently listed in DNSBL/FraudBL. A Tools API follow-up is still running in the background for a second opinion.' => 'Denna IP är för närvarande inte listad i DNSBL/FraudBL. En Tools API-uppföljning körs ändå i bakgrunden för en andra kontroll.',
            'The IP appears listed, but no removable bitmask could be resolved right now. Please try again in a moment.' => 'IP-adressen verkar vara listad, men ingen borttagningsbar bitmask kunde hämtas just nu. Försök igen om en stund.',
             'The IP appears listed, but the local DNS resolve could not decide the removable bitmask. A Tools API follow-up is now running in the background.' => 'IP-adressen verkar vara listad, men den lokala DNS-kontrollen kunde inte avgöra den borttagningsbara bitmasken. En Tools API-uppföljning körs nu i bakgrunden.',
             'No Tools API token is configured for the background DNSBL check.' => 'Ingen Tools API-token är konfigurerad för DNSBL-kontrollen i bakgrunden.',
             'Tools API follow-up confirms delist readiness for: %s.' => 'Tools API-uppföljningen bekräftar att avlistning kan skickas för: %s.',
             'Tools API follow-up still sees this IP listed, but the configured token cannot send delete requests for it right now.' => 'Tools API-uppföljningen ser fortfarande denna IP som listad, men den konfigurerade tokenen kan inte skicka delete-begäran för den just nu.',
             'Tools API follow-up did not find a current removable DNSBL/FraudBL listing for this IP.' => 'Tools API-uppföljningen hittade ingen aktuell borttagningsbar DNSBL/FraudBL-listning för denna IP.',
             'The Tools API follow-up could not be completed right now.' => 'Tools API-uppföljningen kunde inte slutföras just nu.',
            'It may take a little while before the delist result is visible across all resolvers.' => 'Det kan ta en stund innan avlistningen syns hos alla resolvers.',
            'Use this page to check one IP address at a time. If the IP is currently listed, the plugin sends a delist request through this site’s configured DNSBL / Tools API token. After a successful request, it can still take a little while before all resolvers show the updated result.' => 'Använd denna sida för att kontrollera en IP-adress i taget. Om IP-adressen är listad skickar pluginet en avlistningsbegäran via sajtens konfigurerade DNSBL / Tools API-token. Efter en lyckad begäran kan det ändå ta en stund innan alla resolvers visar det uppdaterade resultatet.',
            'Dashboard and plugin settings now warn when this site lacks live DNSBL delete / delist access, so administrators can request approval from Tools before advertising removals.' => 'Dashboarden och plugin-inställningarna varnar nu när denna sajt saknar live delete-/delist-behörighet för DNSBL, så att administratörer kan begära godkännande i Tools innan avlistning erbjuds.',
            'The WordPress dashboard now shows a warning when the configured DNSBL / Tools API token cannot perform live removals, including a direct link to the current Tools token-access page.' => 'WordPress-dashboarden visar nu en varning när den konfigurerade DNSBL / Tools API-tokenen inte kan utföra live-avlistningar, inklusive en direktlänk till aktuell token-/åtkomstsida i Tools.',
            'The settings page now shows the same removal-access status inline near the token field, and the plugin also loads the legacy Swedish translation catalog filename used by older packaged language files.' => 'Inställningssidan visar nu samma status för avlistningsåtkomst direkt vid tokenfältet, och pluginet laddar nu även det äldre svenska översättningsfilnamnet som använts i tidigare paketerade språkfiler.',
        ];

        if (($translation === '' || $translation === $text) && isset($fallbacks[$text])) {
            return $fallbacks[$text];
        }

        return $translation;
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

    private static function enqueueRemovalFormAssets(): void
    {
        $scriptPath = TORNEVALL_DNSBL_PLUGIN_DIR . 'js/dnsbl-removal-form.js';
        $scriptVersion = file_exists($scriptPath)
            ? (string) filemtime($scriptPath)
            : Migrations::schemaVersion();

        wp_enqueue_script(
            'tornevall-dnsbl-removal-form',
            TORNEVALL_DNSBL_PLUGIN_URL . 'js/dnsbl-removal-form.js',
            [],
            $scriptVersion,
            true
        );

        wp_localize_script('tornevall-dnsbl-removal-form', 'tornevallDnsblRemovalForm', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::REMOVAL_FORM_AJAX_ACTION,
            'nonce' => wp_create_nonce('tornevall_dnsbl_removal_form'),
            'sendingText' => __('Submitting request…', 'tornevall-networks-dnsbl-implementation'),
            'checkingText' => __('Checking listing status…', 'tornevall-networks-dnsbl-implementation'),
            'backgroundCheckingText' => __('Checking Tools API in the background…', 'tornevall-networks-dnsbl-implementation'),
            'backgroundCheckFailedText' => __('The Tools API follow-up could not be completed. The first DNS result is still shown above.', 'tornevall-networks-dnsbl-implementation'),
            'checkerCheckText' => __('Check if listed', 'tornevall-networks-dnsbl-implementation'),
            'checkerSubmitText' => __('Delist', 'tornevall-networks-dnsbl-implementation'),
            'checkerPreparingDelistText' => __('Preparing delist details…', 'tornevall-networks-dnsbl-implementation'),
            'checkerReadyText' => __('Delist is ready. You can now submit the request.', 'tornevall-networks-dnsbl-implementation'),
            'csrfExpiredText' => __('Security session expired (HTTP 419). Please refresh this page and try again.', 'tornevall-networks-dnsbl-implementation'),
            'networkErrorText' => __('Network error. Please try again.', 'tornevall-networks-dnsbl-implementation'),
        ]);
    }

    private static function getWritePermissionCacheKey(string $token, string $baseUrl): string
    {
        return 'tornevall_dnsbl_token_perm_' . md5($baseUrl . '|' . $token);
    }

    /**
     * @return array{ok:bool,status:int,body:array,token:array,message:string,error:string,has_token:bool,is_active:bool,can_add:bool,can_delete:bool,can_update:bool}
     */
    public static function getWritePermissionSummary(bool $forceRefresh = false, ?string $token = null, ?string $baseUrl = null): array
    {
        $token = $token !== null ? trim((string) $token) : self::writeToken();
        $baseUrl = $baseUrl !== null && $baseUrl !== '' ? untrailingslashit($baseUrl) : self::toolsBaseUrl();

        if ($token === '') {
            return ApiClient::emptyTokenPermissionSummary(
                __('No DNSBL / Tools API token is configured for live write operations.', 'tornevall-networks-dnsbl-implementation')
            );
        }

        $cacheKey = self::getWritePermissionCacheKey($token, $baseUrl);
        if (!$forceRefresh) {
            $cached = get_transient($cacheKey);
            if (is_array($cached) && isset($cached['has_token'])) {
                return $cached;
            }
        }

        $summary = (new ApiClient($token, $baseUrl))->getTokenPermissionSummary();
        set_transient($cacheKey, $summary, self::WRITE_PERMISSION_CACHE_TTL);

        return $summary;
    }

    /**
     * @return list<string>
     */
    private static function parseAllowedRemovalActions($value): array
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        $allowed = [];
        foreach (preg_split('/[\s,]+/', strtolower((string) $value)) as $part) {
            $action = sanitize_key((string) $part);
            if (in_array($action, ['add', 'delete', 'update'], true)) {
                $allowed[] = $action;
            }
        }

        $allowed = array_values(array_unique($allowed));

        return count($allowed) ? $allowed : ['delete', 'add', 'update'];
    }

    /**
     * @return array<string,string>
     */
    private static function getRemovalOperationLabels(): array
    {
        return [
            'delete' => __('Delete / Delist', 'tornevall-networks-dnsbl-implementation'),
            'add' => __('Add / List', 'tornevall-networks-dnsbl-implementation'),
            'update' => __('Update bitmask', 'tornevall-networks-dnsbl-implementation'),
        ];
    }

    /**
     * @param  list<string> $requestedActions
     * @return array<string,string>
     */
    private static function getPermittedRemovalActions(array $requestedActions, array $permissionSummary): array
    {
        $catalog = self::getRemovalOperationLabels();
        $allowed = [];

        foreach ($requestedActions as $action) {
            $isAllowed = false;
            if ($action === 'delete') {
                $isAllowed = !empty($permissionSummary['can_delete']);
            } elseif ($action === 'add') {
                $isAllowed = !empty($permissionSummary['can_add']);
            } elseif ($action === 'update') {
                $isAllowed = !empty($permissionSummary['can_update']);
            }

            if ($isAllowed && isset($catalog[$action])) {
                $allowed[$action] = $catalog[$action];
            }
        }

        return $allowed;
    }

    /**
     * @param  array<string,string> $allowedActions
     */
    private static function normalizeRemovalDefaultAction(string $requestedAction, array $allowedActions): string
    {
        $requestedAction = strtolower($requestedAction);
        if (isset($allowedActions[$requestedAction])) {
            return $requestedAction;
        }

        $fallback = array_key_first($allowedActions);

        return is_string($fallback) ? $fallback : 'delete';
    }

    /**
     * @param  list<string> $requestedActions
     */
    private static function buildRemovalAccessNoticeMessage(array $permissionSummary, array $requestedActions): string
    {
        if (empty($permissionSummary['has_token'])) {
            return __('This site can render the removal page, but it cannot offer live DNSBL/FraudBL removal because no DNSBL / Tools API token is configured with Tornevall Networks.', 'tornevall-networks-dnsbl-implementation');
        }

        if (empty($permissionSummary['ok'])) {
            return __('This site can render the removal page, but it cannot offer live DNSBL/FraudBL removal because Tornevall Networks could not verify the required token permissions right now.', 'tornevall-networks-dnsbl-implementation');
        }

        if (count($requestedActions) === 1 && in_array('delete', $requestedActions, true)) {
            return __('This site can render the removal page, but it cannot offer live removal because delete/delist permission is missing for the configured Tornevall Networks / FraudBL token.', 'tornevall-networks-dnsbl-implementation');
        }

        if (count($requestedActions) === 1 && in_array('add', $requestedActions, true)) {
            return __('This site is not currently approved to submit live add / list requests through the configured DNSBL token.', 'tornevall-networks-dnsbl-implementation');
        }

        if (count($requestedActions) === 1 && in_array('update', $requestedActions, true)) {
            return __('This site is not currently approved to submit live update requests through the configured DNSBL token.', 'tornevall-networks-dnsbl-implementation');
        }

        return __('This site can render the removal page, but it cannot offer the requested live DNSBL/FraudBL write operations because required Tornevall Networks permissions are missing.', 'tornevall-networks-dnsbl-implementation');
    }

    private static function renderRemovalAccessNotice(string $message, array $permissionSummary = []): string
    {
        $detail = trim((string) ($permissionSummary['message'] ?? ''));
        $settingsUrl = current_user_can('manage_options') ? admin_url('admin.php?page=tornevallDnsblMenu') : '';

        ob_start();
        ?>
        <div class="tornevall-dnsbl-removal-access-notice" style="margin:0 0 1rem 0; padding:1rem 1.1rem; border-radius:10px; border:1px solid #fdba74; background:#fff7ed; color:#9a3412;">
            <p style="margin:0 0 .5rem 0;"><strong><?php echo esc_html($message); ?></strong></p>
            <?php if ($detail !== '' && $detail !== $message && current_user_can('manage_options')) { ?>
                <p style="margin:0 0 .5rem 0;"><?php echo esc_html($detail); ?></p>
            <?php } ?>
            <?php if ($settingsUrl !== '') { ?>
                <p style="margin:0;">
                    <a class="button button-secondary" href="<?php echo esc_url($settingsUrl); ?>">
                        <?php echo esc_html__('Open DNSBL plugin settings', 'tornevall-networks-dnsbl-implementation'); ?>
                    </a>
                </p>
            <?php } ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private static function renderPluginTemplate(string $relativePath, array $vars = []): string
    {
        $templatePath = TORNEVALL_DNSBL_PLUGIN_DIR . ltrim($relativePath, '/');
        if (!is_readable($templatePath)) {
            return '';
        }

        ob_start();
        extract($vars, EXTR_SKIP);
        require $templatePath;

        return (string) ob_get_clean();
    }

    private static function renderManagedRemovalPage(string $content): string
    {
        $formHtml = self::renderRemovalFormShortcode([
            'title' => __('Request DNSBL delisting', 'tornevall-networks-dnsbl-implementation'),
            'action' => 'delete',
            'allowed_actions' => 'delete',
            'lock_operation' => '1',
            'show_dry_run' => '0',
            'show_api_dry_run' => '0',
            'checker_mode' => '1',
            'show_operation' => '0',
            'show_bitmask' => '0',
            'show_publication_type' => '0',
            'show_ttl' => '0',
        ]);

        $rendered = self::renderPluginTemplate('templates/removal-page.php', [
            'content_html' => (string) $content,
            'form_html' => $formHtml,
            'show_custom_pages_help' => false,
        ]);

        return $rendered !== '' ? $rendered : ((string) $content . "\n\n" . $formHtml);
    }

    private static function getWriteAccessAdminNoticeContext(): ?array
    {
        $permissionSummary = self::getWritePermissionSummary();
        if (!empty($permissionSummary['can_delete'])) {
            return null;
        }

        $toolsBaseUrl = self::toolsBaseUrl();
        $requestUrl = untrailingslashit($toolsBaseUrl) . '/dnsbl/token/request';
        $settingsUrl = admin_url('admin.php?page=tornevallDnsblMenu');
        $detail = trim((string) ($permissionSummary['message'] ?? ''));

        if (empty($permissionSummary['has_token'])) {
            $message = __('This site cannot offer live DNSBL/FraudBL removals because no DNSBL / Tools API token is configured.', 'tornevall-networks-dnsbl-implementation');
        } elseif (empty($permissionSummary['ok'])) {
            $message = __('This site cannot offer live DNSBL/FraudBL removals because Tools could not verify the configured token permissions right now.', 'tornevall-networks-dnsbl-implementation');
        } else {
            $message = __('This site cannot offer live DNSBL/FraudBL removals because the configured token is missing delete / delist permission.', 'tornevall-networks-dnsbl-implementation');
        }

        return [
            'title' => __('DNSBL removal access is not active for this site.', 'tornevall-networks-dnsbl-implementation'),
            'message' => $message,
            'detail' => $detail,
            'request_url' => $requestUrl,
            'settings_url' => $settingsUrl,
            'tools_base_url' => $toolsBaseUrl,
        ];
    }

    public static function renderWriteAccessStatusPanel(bool $inline = false): string
    {
        $context = self::getWriteAccessAdminNoticeContext();
        if ($context === null) {
            return '';
        }

        $wrapperClass = $inline ? 'notice notice-warning inline' : 'notice notice-warning';
        $title = (string) ($context['title'] ?? '');
        $message = (string) ($context['message'] ?? '');
        $detail = (string) ($context['detail'] ?? '');
        $requestUrl = (string) ($context['request_url'] ?? '');
        $settingsUrl = (string) ($context['settings_url'] ?? '');
        $toolsBaseUrl = (string) ($context['tools_base_url'] ?? '');

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapperClass); ?>">
            <p>
                <strong><?php echo esc_html($title); ?></strong><br>
                <?php echo esc_html($message); ?>
            </p>
            <?php if ($detail !== '' && $detail !== $message) { ?>
                <p><?php echo esc_html($detail); ?></p>
            <?php } ?>
            <p>
                <?php if ($requestUrl !== '') { ?>
                    <a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($requestUrl); ?>">
                        <?php echo esc_html__('Request DNSBL access via Tools', 'tornevall-networks-dnsbl-implementation'); ?>
                    </a>
                <?php } ?>
                <?php if ($settingsUrl !== '') { ?>
                    <a class="button button-link" href="<?php echo esc_url($settingsUrl); ?>">
                        <?php echo esc_html__('Open DNSBL plugin settings', 'tornevall-networks-dnsbl-implementation'); ?>
                    </a>
                <?php } ?>
            </p>
            <?php if ($toolsBaseUrl !== '') { ?>
                <p class="description" style="margin-top:0;">
                    <?php echo esc_html(sprintf(__('Current Tools host: %s', 'tornevall-networks-dnsbl-implementation'), $toolsBaseUrl)); ?>
                </p>
            <?php } ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public static function renderWriteAccessAdminNotice(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }

        $screenKeys = array_filter([
            (string) ($screen->id ?? ''),
            (string) ($screen->base ?? ''),
        ]);
        $allowedScreens = ['dashboard', 'dashboard-network'];

        if (!count(array_intersect($allowedScreens, $screenKeys))) {
            return;
        }

        echo self::renderWriteAccessStatusPanel(false);
    }

    public static function maybeRenderInternalDelistPage(): void
    {
        if (is_admin() || !self::isInternalDelistingPageSelected() || !self::isCurrentInternalDelistRequest()) {
            return;
        }

        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $wp_query->is_404 = false;
        }

        status_header(200);
        nocache_headers();
        $html = self::renderManagedRemovalPage('');

        if (function_exists('get_header') && function_exists('get_footer')) {
            get_header();
            echo '<main id="primary" class="site-main tornevall-dnsbl-internal-delist" style="max-width:960px; margin:0 auto; padding:1.5rem 1rem;">';
            echo $html;
            echo '</main>';
            get_footer();
            exit;
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . esc_html__('DNSBL delisting', 'tornevall-networks-dnsbl-implementation')
            . '</title></head><body style="font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif; margin:0; background:#f8fafc; color:#0f172a;">'
            . '<main style="max-width:960px; margin:0 auto; padding:1.5rem 1rem;">'
            . $html
            . '</main></body></html>';
        exit;
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    private static function getRemovalOperationPermissionError(string $operation, array $permissionSummary): ?array
    {
        if (empty($permissionSummary['has_token'])) {
            return [
                'status' => 400,
                'payload' => [
                    'ok' => false,
                    'message' => __('No DNSBL / Tools API token is configured for DNSBL writes.', 'tornevall-networks-dnsbl-implementation'),
                ],
            ];
        }

        if (empty($permissionSummary['ok'])) {
            // Never forward HTTP 419 from the remote API as the WordPress AJAX
            // HTTP status. 419 is reserved for WordPress CSRF failures and would
            // show a misleading "session expired – refresh page" banner to users.
            $rawStatus = (int) ($permissionSummary['status'] ?? 0);
            $safeStatus = ($rawStatus === 419) ? 502 : (max(400, $rawStatus) ?: 503);
            return [
                'status' => $safeStatus,
                'payload' => [
                    'ok' => false,
                    'message' => (string) ($permissionSummary['message'] ?: __('The configured DNSBL token could not be verified right now.', 'tornevall-networks-dnsbl-implementation')),
                ],
            ];
        }

        if ($operation === 'delete' && empty($permissionSummary['can_delete'])) {
            return [
                'status' => 403,
                'payload' => [
                    'ok' => false,
                    'message' => __('The configured DNSBL token does not allow delete / delist requests.', 'tornevall-networks-dnsbl-implementation'),
                ],
            ];
        }

        if ($operation === 'add' && empty($permissionSummary['can_add'])) {
            return [
                'status' => 403,
                'payload' => [
                    'ok' => false,
                    'message' => __('The configured DNSBL token does not allow add / list requests.', 'tornevall-networks-dnsbl-implementation'),
                ],
            ];
        }

        if ($operation === 'update' && empty($permissionSummary['can_update'])) {
            return [
                'status' => 403,
                'payload' => [
                    'ok' => false,
                    'message' => __('The configured DNSBL token does not allow update requests because both add and delete access are required.', 'tornevall-networks-dnsbl-implementation'),
                ],
            ];
        }

        return null;
    }

    public static function renderRemovalFormShortcode($atts = []): string
    {
        $atts = shortcode_atts([
            'title' => __('DNSBL removal request', 'tornevall-networks-dnsbl-implementation'),
            'action' => 'delete',
            'allowed_actions' => 'delete,add,update',
            'lock_operation' => '0',
            'publication_type' => 'dnsbl',
            'bitmask' => 2,
            'ttl' => 300,
            'show_dry_run' => '1',
            'show_api_dry_run' => '1',
            'checker_mode' => '0',
            'show_operation' => '1',
            'show_bitmask' => '1',
            'show_publication_type' => '1',
            'show_ttl' => '1',
        ], (array) $atts, self::REMOVAL_FORM_SHORTCODE);

        $checkerMode = self::requestBoolean($atts['checker_mode'] ?? false);
        $showOperationFields = self::requestBoolean($atts['show_operation'] ?? true);
        $showBitmaskField = self::requestBoolean($atts['show_bitmask'] ?? true);
        $showPublicationTypeField = self::requestBoolean($atts['show_publication_type'] ?? true);
        $showTtlField = self::requestBoolean($atts['show_ttl'] ?? true);

        if ($checkerMode) {
            $atts['action'] = 'delete';
            $atts['allowed_actions'] = 'delete';
            $atts['lock_operation'] = '1';
            $showOperationFields = false;
            $showBitmaskField = false;
            $showPublicationTypeField = false;
            $showTtlField = false;
        }

        $permissionSummary = self::getWritePermissionSummary();
        $requestedActions = self::parseAllowedRemovalActions($atts['allowed_actions']);
        $availableActions = self::getPermittedRemovalActions($requestedActions, $permissionSummary);

        if (!count($availableActions)) {
            return self::renderRemovalAccessNotice(
                self::buildRemovalAccessNoticeMessage($permissionSummary, $requestedActions),
                $permissionSummary
            );
        }

        self::enqueueRemovalFormAssets();

        $defaultAction = self::normalizeRemovalDefaultAction((string) $atts['action'], $availableActions);
        $lockOperation = self::requestBoolean($atts['lock_operation']) || count($availableActions) === 1;
        $showOperationSelector = !$lockOperation && count($availableActions) > 1;
        $defaultActionIsUpdate = $defaultAction === 'update';
        $prefillIp = $checkerMode ? self::currentVisitorIp() : '';
        $canUseAdvancedCidr = $checkerMode ? self::canUseAdvancedCidr($permissionSummary) : false;
        $restrictedNotice = count($availableActions) < count($requestedActions)
            ? __('Only the DNSBL operations allowed by the currently configured token are shown below.', 'tornevall-networks-dnsbl-implementation')
            : '';

        ob_start();
        ?>
        <form class="tornevall-dnsbl-removal-form" data-tornevall-dnsbl-removal-form="1" data-checker-mode="<?php echo $checkerMode ? '1' : '0'; ?>" data-can-cidr-delete="<?php echo $canUseAdvancedCidr ? '1' : '0'; ?>" novalidate>
            <h3 style="margin-top:0;"><?php echo esc_html((string) $atts['title']); ?></h3>
            <p style="margin:0 0 .75rem 0; opacity:.82;">
                <?php
                echo esc_html($checkerMode
                    ? __('Enter an IP address and run the listing check first. If listed, the plugin continues a token-backed background check and then enables Delist.', 'tornevall-networks-dnsbl-implementation')
                    : __('Submit add/delete/update requests via WordPress backend proxy to the Tools DNSBL API.', 'tornevall-networks-dnsbl-implementation'));
                ?>
            </p>

            <input type="hidden" name="checker_mode" value="<?php echo $checkerMode ? '1' : '0'; ?>">

            <?php if ($restrictedNotice !== '') { ?>
                <p style="margin:0 0 .9rem 0; padding:.65rem .75rem; border-radius:8px; border:1px solid #bfdbfe; background:#eff6ff; color:#1d4ed8;">
                    <?php echo esc_html($restrictedNotice); ?>
                </p>
            <?php } ?>

            <?php if ($showOperationFields && $showOperationSelector) { ?>
                <p>
                    <label><?php echo esc_html__('Operation', 'tornevall-networks-dnsbl-implementation'); ?><br>
                        <select name="operation" style="min-width:200px;" data-removal-operation>
                            <?php foreach ($availableActions as $operationKey => $operationLabel) { ?>
                                <option value="<?php echo esc_attr($operationKey); ?>" <?php selected($defaultAction, $operationKey); ?>><?php echo esc_html($operationLabel); ?></option>
                            <?php } ?>
                        </select>
                    </label>
                </p>
            <?php } elseif ($showOperationFields) { ?>
                <input type="hidden" name="operation" value="<?php echo esc_attr($defaultAction); ?>">
                <p style="margin-bottom:.85rem;"><strong><?php echo esc_html__('Operation', 'tornevall-networks-dnsbl-implementation'); ?>:</strong> <?php echo esc_html((string) ($availableActions[$defaultAction] ?? $defaultAction)); ?></p>
            <?php } else { ?>
                <input type="hidden" name="operation" value="<?php echo esc_attr($checkerMode ? 'check' : $defaultAction); ?>">
            <?php } ?>

            <p>
                <label><?php echo esc_html__('IP address', 'tornevall-networks-dnsbl-implementation'); ?><br>
                    <input type="text" name="ip" value="<?php echo esc_attr($prefillIp); ?>" placeholder="203.0.113.10" required style="min-width:260px;" data-removal-ip data-prefill-ip="<?php echo esc_attr($prefillIp); ?>" />
                </label>
            </p>

            <?php if ($checkerMode && $canUseAdvancedCidr) { ?>
                <p style="margin:0 0 .65rem 0;">
                    <button type="button" class="button button-secondary" data-removal-advanced-toggle style="display:none;">
                        <?php echo esc_html__('Advanced', 'tornevall-networks-dnsbl-implementation'); ?>
                    </button>
                </p>
                <div data-removal-advanced style="display:none; margin:0 0 .8rem 0; padding:.65rem .75rem; border:1px solid #dbeafe; border-radius:8px; background:#eff6ff;">
                    <p style="margin:0 0 .45rem 0; color:#1e3a8a;"><strong><?php echo esc_html__('Advanced delist range (CIDR)', 'tornevall-networks-dnsbl-implementation'); ?></strong></p>
                    <p style="margin:0 0 .45rem 0; color:#1e3a8a; font-size:.92em;">
                        <?php echo esc_html__('Optional: set a small CIDR range tied to the listed IP. Large ranges are blocked. Use /24 to /32 only.', 'tornevall-networks-dnsbl-implementation'); ?>
                    </p>
                    <label><?php echo esc_html__('CIDR range', 'tornevall-networks-dnsbl-implementation'); ?><br>
                        <input type="text" name="cidr_range" placeholder="203.0.113.0/24" style="min-width:260px;" data-removal-cidr />
                    </label>
                </div>
            <?php } elseif ($checkerMode) { ?>
                <p style="margin:0 0 .8rem 0; color:#92400e;">
                    <?php
                    echo esc_html__('CIDR delist is disabled for this token. Only single-IP delist is available here.', 'tornevall-networks-dnsbl-implementation');
                    echo ' ';
                    printf(
                        '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                        esc_url(untrailingslashit(self::toolsBaseUrl()) . '/dnsbl/cidr/request'),
                        esc_html__('Request CIDR removal approval', 'tornevall-networks-dnsbl-implementation')
                    );
                    ?>
                </p>
            <?php } ?>

            <?php if ($showBitmaskField) { ?>
                <p>
                    <label><?php echo esc_html__('Bitmask', 'tornevall-networks-dnsbl-implementation'); ?><br>
                        <input type="number" name="bitmask" min="0" max="255" value="<?php echo esc_attr((string) ((int) $atts['bitmask'])); ?>" required />
                    </label>
                </p>
            <?php } else { ?>
                <input type="hidden" name="bitmask" value="<?php echo esc_attr((string) ((int) $atts['bitmask'])); ?>">
            <?php } ?>

            <?php if ($showOperationFields) { ?>
                <p data-removal-old-bitmask-wrap style="display:<?php echo $defaultActionIsUpdate ? 'block' : 'none'; ?>;">
                    <label><?php echo esc_html__('Old bitmask (required for update)', 'tornevall-networks-dnsbl-implementation'); ?><br>
                        <input type="number" name="old_bitmask" min="0" max="255" value="0" <?php echo $defaultActionIsUpdate ? 'required' : ''; ?> />
                    </label>
                </p>
            <?php } else { ?>
                <input type="hidden" name="old_bitmask" value="0">
            <?php } ?>

            <?php if ($showPublicationTypeField) { ?>
                <p>
                    <label><?php echo esc_html__('Publication type', 'tornevall-networks-dnsbl-implementation'); ?><br>
                        <select name="publication_type" style="min-width:200px;">
                            <?php
                            foreach (['dnsbl', 'fraudbl', 'commerce'] as $pubType) {
                                echo '<option value="' . esc_attr($pubType) . '" ' . selected((string) $atts['publication_type'], $pubType, false) . '>' . esc_html($pubType) . '</option>';
                            }
                            ?>
                        </select>
                    </label>
                </p>
            <?php } else { ?>
                <input type="hidden" name="publication_type" value="<?php echo esc_attr((string) $atts['publication_type']); ?>">
            <?php } ?>

            <?php if ($showTtlField) { ?>
                <p>
                    <label><?php echo esc_html__('TTL (seconds)', 'tornevall-networks-dnsbl-implementation'); ?><br>
                        <input type="number" name="ttl" min="300" max="86400" value="<?php echo esc_attr((string) max(300, (int) $atts['ttl'])); ?>" />
                    </label>
                </p>
            <?php } else { ?>
                <input type="hidden" name="ttl" value="<?php echo esc_attr((string) max(300, (int) $atts['ttl'])); ?>">
            <?php } ?>

            <?php if ((string) $atts['show_dry_run'] === '1') { ?>
                <p style="margin-bottom:.35rem;">
                    <label>
                        <input type="checkbox" name="dry_run" value="1" />
                        <?php echo esc_html__('Dry run in WordPress only (no API write)', 'tornevall-networks-dnsbl-implementation'); ?>
                    </label>
                </p>
            <?php } ?>

            <?php if ((string) $atts['show_api_dry_run'] === '1') { ?>
                <p style="margin-bottom:.8rem;">
                    <label>
                        <input type="checkbox" name="api_dry_run" value="1" />
                        <?php echo esc_html__('Request API dry run acknowledgment', 'tornevall-networks-dnsbl-implementation'); ?>
                    </label>
                </p>
            <?php } ?>

            <?php if (!$checkerMode && !empty($permissionSummary['token']['scope_label'])) { ?>
                <p style="margin:0 0 .8rem 0; color:#475569; font-size:.95em;">
                    <?php
                    echo esc_html(sprintf(
                        __('Active token scope: %s', 'tornevall-networks-dnsbl-implementation'),
                        (string) $permissionSummary['token']['scope_label']
                    ));
                    ?>
                </p>
            <?php } ?>

            <?php if (self::removalTurnstileEnabled()) { ?>
                <?php $turnstileContainerId = 'tornevall-removal-turnstile-' . wp_generate_uuid4(); ?>
                <div data-removal-turnstile-wrap style="margin:.25rem 0 .9rem 0;<?php echo $checkerMode ? 'display:none;' : ''; ?>">
                    <div id="<?php echo esc_attr($turnstileContainerId); ?>" data-theme="<?php echo esc_attr(self::commentTurnstileTheme()); ?>"></div>
                    <input type="hidden" name="cf_turnstile_token" value="" <?php echo $checkerMode ? '' : 'required'; ?> data-turnstile-container="<?php echo esc_attr($turnstileContainerId); ?>" data-turnstile-sitekey="<?php echo esc_attr(self::commentTurnstileSiteKey()); ?>" />
                    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                </div>
            <?php } ?>

            <p style="margin:0; display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                <button type="submit" class="button button-primary" data-removal-submit>
                    <?php echo esc_html($checkerMode ? __('Check if listed', 'tornevall-networks-dnsbl-implementation') : __('Submit request', 'tornevall-networks-dnsbl-implementation')); ?>
                </button>
                <?php if ($checkerMode) { ?>
                    <button type="button" class="button button-secondary" data-removal-delist style="display:none; opacity:.95;" disabled>
                        <?php echo esc_html__('Delist', 'tornevall-networks-dnsbl-implementation'); ?>
                    </button>
                <?php } ?>
            </p>

            <div data-removal-result style="display:none; margin-top:.85rem; padding:.65rem .75rem; border-radius:6px; border:1px solid #d1d5db;"></div>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    public static function handleRemovalFormAjax(): void
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'tornevall_dnsbl_removal_form')) {
            wp_send_json_error([
                'ok' => false,
                'message' => __('Security validation failed. Please refresh and try again.', 'tornevall-networks-dnsbl-implementation'),
            ], 403);
        }

        $ip = isset($_POST['ip']) ? trim(sanitize_text_field(wp_unslash($_POST['ip']))) : '';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error([
                'ok' => false,
                'message' => __('Invalid IP address format.', 'tornevall-networks-dnsbl-implementation'),
            ], 400);
        }

        $operation = isset($_POST['operation']) ? strtolower(sanitize_key(wp_unslash($_POST['operation']))) : 'delete';
        if (!in_array($operation, ['add', 'delete', 'update', 'check'], true)) {
            $operation = 'delete';
        }
        $checkerMode = self::requestBoolean($_POST['checker_mode'] ?? false);
        $checkOnly = self::requestBoolean($_POST['check_only'] ?? false);
        $confirmedListed = self::requestBoolean($_POST['confirmed_listed'] ?? false);
        $backgroundApiCheck = self::requestBoolean($_POST['background_api_check'] ?? false);
        if ($checkerMode) {
            $operation = ($checkOnly || $backgroundApiCheck || !$confirmedListed) ? 'check' : 'delete';
        }

        // Turnstile is enforced for actual write submissions, but skipped for
        // checker-only/background pre-checks to avoid false verification failures.
        $requiresTurnstile = !($checkerMode && ($checkOnly || $backgroundApiCheck));
        if (self::removalTurnstileEnabled() && $requiresTurnstile) {
            $turnstile = isset($_POST['cf_turnstile_token']) ? sanitize_text_field(wp_unslash($_POST['cf_turnstile_token'])) : '';
            if ($turnstile === '' && isset($_POST['cf-turnstile-response'])) {
                $turnstile = sanitize_text_field(wp_unslash($_POST['cf-turnstile-response']));
            }

            $turnstileVerification = self::verifyTurnstileToken($turnstile, $ip);
            if (empty($turnstileVerification['success'])) {
                wp_send_json_error([
                    'ok' => false,
                    'message' => (string) ($turnstileVerification['message'] ?? __('Verification failed. Please try again.', 'tornevall-networks-dnsbl-implementation')),
                ], 400);
            }
        }

        $publicationType = isset($_POST['publication_type']) ? strtolower(sanitize_key(wp_unslash($_POST['publication_type']))) : 'dnsbl';
        if (!in_array($publicationType, ['dnsbl', 'fraudbl', 'commerce'], true)) {
            $publicationType = 'dnsbl';
        }

        $bitmask = isset($_POST['bitmask']) ? (int) $_POST['bitmask'] : 0;
        if ($bitmask < 0 || $bitmask > 255) {
            wp_send_json_error([
                'ok' => false,
                'message' => __('Bitmask must be between 0 and 255.', 'tornevall-networks-dnsbl-implementation'),
            ], 400);
        }

        if ($checkerMode && $backgroundApiCheck) {
            $client = ApiClient::fromPluginOptions();
            if (!$client) {
                wp_send_json_error([
                    'ok' => false,
                    'message' => __('No Tools API token is configured for the background DNSBL check.', 'tornevall-networks-dnsbl-implementation'),
                    'step' => 'tools_check',
                    'ip' => $ip,
                ], 400);
            }

            $apiResult = $client->checkIp($ip);
            $payload = self::buildCheckerToolsCheckResponse($ip, $apiResult);

            if (!empty($apiResult['ok'])) {
                wp_send_json_success($payload, (int) ($apiResult['status'] ?? 200));
            }

            // Never relay a 419 from the remote Tools API as HTTP 419 to the browser.
            // A 419 from Tools is a server-to-server CSRF/auth issue on the external call,
            // not a WordPress session issue; forwarding it as 419 triggers the client-side
            // "session expired – refresh page" UI even though the WP nonce is still valid.
            wp_send_json_error($payload, self::normalizeExternalApiStatus((int) ($apiResult['status'] ?? 500)));
        }

        if ($checkerMode) {
            $lookup = self::buildLookupResult($ip);
            $backgroundCheckAvailable = self::writeTokenSet();
            if (empty($lookup['listed'])) {
                wp_send_json_error([
                    'ok' => false,
                    'message' => $backgroundCheckAvailable
                        ? __('This IP is not currently listed in DNSBL/FraudBL. A Tools API follow-up is still running in the background for a second opinion.', 'tornevall-networks-dnsbl-implementation')
                        : __('This IP is not currently listed in DNSBL/FraudBL, so no delist request was sent.', 'tornevall-networks-dnsbl-implementation'),
                    'operation' => 'check',
                    'ip' => $ip,
                    'check_only' => true,
                    'listed' => false,
                    'background_check_available' => $backgroundCheckAvailable,
                    'lookup' => $lookup,
                ], 409);
            }

            $resolvedMask = (int) ($lookup['typebit'] ?? 0);
            if ($resolvedMask <= 0) {
                wp_send_json_error([
                    'ok' => false,
                    'message' => $backgroundCheckAvailable
                        ? __('The IP appears listed, but the local DNS resolve could not decide the removable bitmask. A Tools API follow-up is now running in the background.', 'tornevall-networks-dnsbl-implementation')
                        : __('The IP appears listed, but no removable bitmask could be resolved right now. Please try again in a moment.', 'tornevall-networks-dnsbl-implementation'),
                    'operation' => 'check',
                    'ip' => $ip,
                    'check_only' => true,
                    'listed' => true,
                    'background_check_available' => $backgroundCheckAvailable,
                    'lookup' => $lookup,
                ], 503);
            }

            $bitmask = $resolvedMask;

            if ($checkOnly || !$confirmedListed) {
                wp_send_json_success([
                    'ok' => true,
                    'message' => $backgroundCheckAvailable
                        ? __('This IP is listed. A Tools API follow-up is now running in the background to confirm delist details.', 'tornevall-networks-dnsbl-implementation')
                        : __('This IP is listed. Delist is ready.', 'tornevall-networks-dnsbl-implementation'),
                    'operation' => 'check',
                    'ip' => $ip,
                    'check_only' => true,
                    'listed' => true,
                    'bitmask' => $bitmask,
                    'background_check_available' => $backgroundCheckAvailable,
                    'lookup' => $lookup,
                ]);
            }
        }

        $checkerDeleteCandidates = $checkerMode
            ? self::parseCheckerDeleteCandidates($_POST['checker_delete_candidates'] ?? [])
            : [];

        $cidrRangeRaw = isset($_POST['cidr_range']) ? trim(sanitize_text_field(wp_unslash($_POST['cidr_range']))) : '';
        $cidrRange = '';
        $targetIps = [$ip];
        if ($operation === 'delete' && $cidrRangeRaw !== '') {
            $permissionSummaryForCidr = self::getWritePermissionSummary();
            if (!self::canUseAdvancedCidr($permissionSummaryForCidr)) {
                wp_send_json_error([
                    'ok' => false,
                    'message' => __('CIDR delist is not allowed for this token. Request approval via Tools before using Advanced mode.', 'tornevall-networks-dnsbl-implementation'),
                    'request_url' => untrailingslashit(self::toolsBaseUrl()) . '/dnsbl/cidr/request',
                ], 403);
            }

            if ($checkerMode && ($checkOnly || !$confirmedListed)) {
                wp_send_json_error([
                    'ok' => false,
                    'message' => __('Run the listing check first before using CIDR delist.', 'tornevall-networks-dnsbl-implementation'),
                ], 409);
            }

            $cidrValidation = self::validateRemovalCidr($cidrRangeRaw, $ip);
            if (empty($cidrValidation['ok'])) {
                wp_send_json_error([
                    'ok' => false,
                    'message' => (string) ($cidrValidation['message'] ?? __('Invalid CIDR range.', 'tornevall-networks-dnsbl-implementation')),
                ], (int) ($cidrValidation['status'] ?? 422));
            }

            $cidrRange = (string) ($cidrValidation['cidr'] ?? '');
            $targetIps = self::expandIpv4Cidr($cidrRange);
            if (!count($targetIps)) {
                wp_send_json_error([
                    'ok' => false,
                    'message' => __('Could not resolve the requested CIDR range into addresses.', 'tornevall-networks-dnsbl-implementation'),
                ], 422);
            }
        }

        if ($checkerMode && count($checkerDeleteCandidates)) {
            $primaryDeleteCandidate = $checkerDeleteCandidates[0];
            $publicationType = (string) ($primaryDeleteCandidate['publication_type'] ?? $publicationType);
            $bitmask = (int) ($primaryDeleteCandidate['bitmask'] ?? $bitmask);
        }

        $oldBitmask = isset($_POST['old_bitmask']) ? (int) $_POST['old_bitmask'] : 0;
        if ($operation === 'update' && ($oldBitmask < 0 || $oldBitmask > 255)) {
            wp_send_json_error([
                'ok' => false,
                'message' => __('Old bitmask must be between 0 and 255 for update operations.', 'tornevall-networks-dnsbl-implementation'),
            ], 400);
        }

        $ttl = isset($_POST['ttl']) ? (int) $_POST['ttl'] : 300;
        if ($ttl < 300) {
            $ttl = 300;
        }
        if ($ttl > 86400) {
            $ttl = 86400;
        }

        $wpDryRun = self::requestBoolean($_POST['dry_run'] ?? false);
        $apiDryRun = self::requestBoolean($_POST['api_dry_run'] ?? false);

        if ($wpDryRun && !$apiDryRun) {
            wp_send_json_success([
                'ok' => true,
                'message' => __('Dry run completed in WordPress. No API call was sent.', 'tornevall-networks-dnsbl-implementation'),
                'dry_run' => true,
                'dry_run_mode' => 'wordpress_only',
                'operation' => $operation,
                'ip' => $ip,
                'bitmask' => $bitmask,
                'publication_type' => $publicationType,
            ]);
        }

        $permissionSummary = self::getWritePermissionSummary();
        $permissionError = self::getRemovalOperationPermissionError($operation, $permissionSummary);
        if ($permissionError !== null) {
            wp_send_json_error($permissionError['payload'], $permissionError['status']);
        }

        $client = ApiClient::fromPluginOptions();
        if (!$client) {
            wp_send_json_error([
                'ok' => false,
                'message' => __('No Tools API token is configured for DNSBL writes.', 'tornevall-networks-dnsbl-implementation'),
            ], 400);
        }

        $requestOptions = [
            'dry_run' => $apiDryRun,
        ];

        if ($operation === 'add') {
            $apiResult = $client->addIp($ip, $bitmask, $publicationType, $ttl, $requestOptions);
        } elseif ($operation === 'update') {
            $apiResult = $client->updateIp($ip, $oldBitmask, $bitmask, $publicationType, $ttl, $requestOptions);
        } else {
            if (count($targetIps) > 1) {
                $bulkOperations = [];

                foreach ($targetIps as $targetIp) {
                    $bulkOperations[] = [
                        'action' => 'delete',
                        'ip' => (string) $targetIp,
                        'bitmask' => $bitmask,
                        'publication_type' => $publicationType,
                    ];
                }

                $chunks = array_chunk($bulkOperations, 200);
                $chunkResults = [];
                $failedChunk = null;
                foreach ($chunks as $chunkIndex => $chunk) {
                    $chunkResult = $client->bulkOperation($chunk, $requestOptions);
                    $chunkResults[] = $chunkResult;
                    if (empty($chunkResult['ok'])) {
                        $failedChunk = [
                            'index' => $chunkIndex,
                            'result' => $chunkResult,
                        ];
                        break;
                    }
                }

                if ($failedChunk !== null) {
                    $apiResult = [
                        'ok' => false,
                        'status' => (int) (($failedChunk['result']['status'] ?? 500)),
                        'body' => [
                            'message' => __('CIDR delist request failed in one of the bulk chunks.', 'tornevall-networks-dnsbl-implementation'),
                            'failed_chunk' => (int) $failedChunk['index'],
                            'chunk_results' => $chunkResults,
                        ],
                        'error' => (string) ($failedChunk['result']['error'] ?? ''),
                    ];
                } else {
                    $apiResult = [
                        'ok' => true,
                        'status' => 200,
                        'body' => [
                            'ok' => true,
                            'message' => __('CIDR delist request accepted.', 'tornevall-networks-dnsbl-implementation'),
                            'chunk_count' => count($chunks),
                            'operation_count' => count($bulkOperations),
                            'target_count' => count($targetIps),
                            'chunk_results' => $chunkResults,
                        ],
                        'error' => null,
                    ];
                }
            } else {
                $apiResult = $client->removeIp($ip, $bitmask, $publicationType, $requestOptions);
            }
        }

        $body = is_array($apiResult['body'] ?? null) ? $apiResult['body'] : [];
        $apiAcknowledged = !empty($body['ok']) || !empty($apiResult['ok']);
        $message = (string) ($body['message'] ?? $apiResult['error'] ?? __('Request completed.', 'tornevall-networks-dnsbl-implementation'));

        $payload = [
            'ok' => !empty($apiResult['ok']),
            'message' => $message,
            'status' => (int) ($apiResult['status'] ?? 0),
            'operation' => $operation,
            'ip' => $ip,
            'publication_type' => $publicationType,
            'checker_mode' => $checkerMode,
            'dry_run' => !empty($body['dry_run']) || $apiDryRun,
            'api_acknowledged' => $apiAcknowledged,
            'api_result' => $body,
            'delete_candidate_count' => count($checkerDeleteCandidates),
            'cidr_range' => $cidrRange,
            'target_count' => count($targetIps),
        ];

        if (!empty($apiResult['ok'])) {
            if ($checkerMode && $operation === 'delete') {
                $payload['message'] = rtrim($payload['message']) . ' ' . __('It may take a little while before the delist result is visible across all resolvers.', 'tornevall-networks-dnsbl-implementation');
            }
            wp_send_json_success($payload, (int) ($apiResult['status'] ?? 200));
        }

        wp_send_json_error($payload, self::normalizeExternalApiStatus((int) ($apiResult['status'] ?? 500)));
    }

    private static function requestBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Map HTTP status codes from external API responses to safe WordPress AJAX
     * status codes. In particular, 419 (CSRF/session) from the remote Tools API
     * must NOT be forwarded as HTTP 419 to the browser – that code is reserved for
     * WordPress CSRF failures and would incorrectly show a "session expired" UI
     * even though the WordPress nonce is still valid.
     *
     * 419 from Tools → 502 Bad Gateway (server-side auth issue on external call).
     */
    private static function normalizeExternalApiStatus(int $status): int
    {
        if ($status === 419) {
            return 502;
        }

        return $status > 0 ? $status : 500;
    }

    /**
     * @param  mixed $rawCandidates
     * @return list<array{publication_type:string,bitmask:int,active_flags:list<string>,zones:list<string>}>
     */
    private static function parseCheckerDeleteCandidates($rawCandidates): array
    {
        if (is_string($rawCandidates)) {
            $decoded = json_decode(wp_unslash($rawCandidates), true);
            $rawCandidates = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($rawCandidates)) {
            return [];
        }

        $candidates = [];
        foreach ($rawCandidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $publicationType = strtolower(sanitize_key((string) ($candidate['publication_type'] ?? '')));
            $bitmask = (int) ($candidate['bitmask'] ?? 0);
            $zones = array_values(array_filter(array_map(static function ($zone) {
                return sanitize_text_field((string) $zone);
            }, (array) ($candidate['zones'] ?? []))));
            $activeFlags = array_values(array_filter(array_map(static function ($flag) {
                return sanitize_text_field((string) $flag);
            }, (array) ($candidate['active_flags'] ?? []))));

            if (!in_array($publicationType, ['dnsbl', 'fraudbl', 'commerce'], true)) {
                continue;
            }

            if ($bitmask <= 0 || $bitmask > 255) {
                continue;
            }

            $candidates[] = [
                'publication_type' => $publicationType,
                'bitmask' => $bitmask,
                'active_flags' => $activeFlags,
                'zones' => $zones,
            ];
        }

        return array_values($candidates);
    }

    /**
     * @return array{ok:bool,status?:int,message?:string,cidr?:string}
     */
    private static function validateRemovalCidr(string $cidr, string $listedIp): array
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return ['ok' => false, 'status' => 422, 'message' => __('CIDR value is empty.', 'tornevall-networks-dnsbl-implementation')];
        }

        if (!preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})\/(\d{1,2})$/', $cidr, $matches)) {
            return ['ok' => false, 'status' => 422, 'message' => __('CIDR must be an IPv4 range like 203.0.113.0/24.', 'tornevall-networks-dnsbl-implementation')];
        }

        $networkIp = (string) $matches[1];
        $prefix = (int) $matches[2];

        if (!filter_var($networkIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ok' => false, 'status' => 422, 'message' => __('CIDR currently supports IPv4 only.', 'tornevall-networks-dnsbl-implementation')];
        }

        if ($prefix < 24 || $prefix > 32) {
            return ['ok' => false, 'status' => 422, 'message' => __('CIDR prefix must be between /24 and /32. Large ranges like /16 or /8 are blocked.', 'tornevall-networks-dnsbl-implementation')];
        }

        if (!self::ipMatchesCidr($listedIp, $networkIp . '/' . $prefix)) {
            return ['ok' => false, 'status' => 422, 'message' => __('CIDR must include the listed IP you just checked.', 'tornevall-networks-dnsbl-implementation')];
        }

        return [
            'ok' => true,
            'cidr' => $networkIp . '/' . $prefix,
        ];
    }

    private static function canUseAdvancedCidr(array $permissionSummary): bool
    {
        $token = is_array($permissionSummary['token'] ?? null) ? $permissionSummary['token'] : [];
        if (!empty($token['is_admin_token'])) {
            return true;
        }

        if (!empty($token['can_cidr_delete'])) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function expandIpv4Cidr(string $cidr): array
    {
        if (!preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})\/(\d{1,2})$/', $cidr, $matches)) {
            return [];
        }

        $networkIp = (string) $matches[1];
        $prefix = (int) $matches[2];
        $networkLong = ip2long($networkIp);
        if ($networkLong === false || $prefix < 24 || $prefix > 32) {
            return [];
        }

        $mask = ((~0 << (32 - $prefix)) & 0xFFFFFFFF);
        $network = $networkLong & $mask;
        $hostCount = 1 << (32 - $prefix);
        if ($hostCount < 1 || $hostCount > 256) {
            return [];
        }

        $ips = [];
        for ($i = 0; $i < $hostCount; $i++) {
            $addr = long2ip(($network + $i) & 0xFFFFFFFF);
            if ($addr !== false && filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = (string) $addr;
            }
        }

        return $ips;
    }

    /**
     * @param  array{ok?:bool,status?:int,body?:array,error?:string|null} $apiResult
     * @return array<string,mixed>
     */
    private static function buildCheckerToolsCheckResponse(string $ip, array $apiResult): array
    {
        $body = is_array($apiResult['body'] ?? null) ? $apiResult['body'] : [];
        $lookup = is_array($body['lookup'] ?? null) ? $body['lookup'] : [];
        $token = is_array($body['token'] ?? null) ? $body['token'] : [];
        $deleteCandidates = self::parseCheckerDeleteCandidates($lookup['delete_candidates'] ?? []);
        $listed = !empty($lookup['listed']);
        $canDelete = !empty($token['can_delete']);
        $reason = (string) ($body['reason'] ?? '');
        $message = (string) ($body['message'] ?? $apiResult['error'] ?? __('The Tools API follow-up could not be completed right now.', 'tornevall-networks-dnsbl-implementation'));

        if (!empty($apiResult['ok'])) {
            if ($listed && $canDelete && count($deleteCandidates)) {
                $message = sprintf(
                    __('Tools API follow-up confirms delist readiness for: %s.', 'tornevall-networks-dnsbl-implementation'),
                    self::formatCheckerDeleteCandidateLabels($deleteCandidates)
                );
            } elseif ($listed) {
                $message = __('Tools API follow-up still sees this IP listed, but the configured token cannot send delete requests for it right now.', 'tornevall-networks-dnsbl-implementation');
            } else {
                $message = __('Tools API follow-up did not find a current removable DNSBL/FraudBL listing for this IP.', 'tornevall-networks-dnsbl-implementation');
            }
        } elseif ($reason === 'wrong_token_type') {
            $message = __('Tools API follow-up could not confirm delist rights because the configured token belongs to another Tools API token/provider and is not a DNSBL write token. The DNS-first result above still indicates that this IP is listed.', 'tornevall-networks-dnsbl-implementation');
        } elseif ($reason === 'inactive_admin_api_key') {
            $message = __('Tools API follow-up could not confirm delist rights because the matched admin API key is inactive. The DNS-first result above still indicates that this IP is listed.', 'tornevall-networks-dnsbl-implementation');
        }

        return [
            'ok' => !empty($apiResult['ok']),
            'message' => $message,
            'step' => 'tools_check',
            'ip' => $ip,
            'listed' => $listed,
            'can_delete' => $canDelete,
            'reason' => $reason,
            'token' => $token,
            'lookup' => $lookup,
            'delete_candidates' => $deleteCandidates,
            'delete_candidate_count' => count($deleteCandidates),
            'api_acknowledged' => !empty($body['ok']) || !empty($apiResult['ok']),
            'status' => (int) ($apiResult['status'] ?? 0),
        ];
    }

    /**
     * @param  list<array{publication_type:string,bitmask:int,active_flags:list<string>,zones:list<string>}> $deleteCandidates
     */
    private static function formatCheckerDeleteCandidateLabels(array $deleteCandidates): string
    {
        $labels = [];
        foreach ($deleteCandidates as $candidate) {
            $labels[] = strtoupper((string) ($candidate['publication_type'] ?? 'dnsbl')) . ' (' . (int) ($candidate['bitmask'] ?? 0) . ')';
        }

        return implode(', ', array_values(array_unique($labels)));
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
            'opm.tornevall.org',
            'bl.fraudbl.org',
            'ecom.fraudbl.org',
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

    // ─── DNSBL Write Token & API ──────────────────────────────────────────────────

    /**
     * Resolve the single effective API token used by the plugin.
     *
     * Priority:
     * 1) Visible DNSBL / Tools API token field
     * 2) Legacy removal token alias
     * 3) Hidden legacy Tools token option from older plugin UI
     */
    public static function apiToken(): string
    {
        $writeToken = self::normalizeApiTokenValue((string) get_option('tornevall_dnsbl_write_token'));
        if ($writeToken !== '') {
            return $writeToken;
        }

        $legacyRemovalToken = self::normalizeApiTokenValue((string) get_option('tornevall_dnsbl_removal_token'));
        if ($legacyRemovalToken !== '') {
            return $legacyRemovalToken;
        }

        return self::normalizeApiTokenValue((string) get_option('tornevall_dnsbl_tools_token'));
    }

    private static function normalizeApiTokenValue(string $token): string
    {
        $token = trim($token);
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

    /**
     * Get the plugin API token for DNSBL API operations.
     */
    public static function writeToken(): string
    {
        return self::apiToken();
    }

    /**
     * Check if a write token is configured (non-empty).
     */
    public static function writeTokenSet(): bool
    {
        return self::writeToken() !== '';
    }

    /**
     * Get the Tools backend base URL.
     * Built from tools_mode (dev/prod) or explicit config.
     */
    public static function toolsBaseUrl(): string
    {
        $mode = self::toolsMode();
        return $mode === 'dev'
            ? 'https://tools.tornevall.com'
            : 'https://tools.tornevall.net';
    }

    /**
     * Get the Tools environment mode (dev or prod).
     */
    public static function toolsMode(): string
    {
        $mode = trim((string) get_option('tornevall_dnsbl_tools_mode', 'prod'));
        return in_array($mode, ['dev', 'prod'], true) ? $mode : 'prod';
    }

    /**
     * Check if auto-report spam is enabled.
     * Requires a write token to be configured.
     */
    public static function autoReportSpamEnabled(): bool
    {
        return get_option('tornevall_dnsbl_auto_report_spam') === '1' && self::writeTokenSet();
    }

    /**
     * Handle comment status transitions (e.g., Akismet marking comments as spam).
     * When a comment transitions to spam and auto-report is enabled, queue the
     * commenter's IP for DNSBL listing.
     *
     * Bitmask used: 64 = IP_ABUSE_NO_SMTP (generic spam/abuse indicator).
     */
    public static function handleCommentStatusTransition(string $newStatus, string $oldStatus, \WP_Comment $comment): void
    {
        // Only interested in transitions TO spam
        if ($newStatus !== 'spam' || $oldStatus === 'spam') {
            return;
        }

        if (!self::autoReportSpamEnabled()) {
            return;
        }

        $ip = isset($comment->comment_author_IP) ? sanitize_text_field($comment->comment_author_IP) : '';
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        // Queue the IP for listing with bitmask 64 (IP_ABUSE_NO_SMTP)
        WriteQueue::getInstance()->queueAdd($ip, 64, 'dnsbl');
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

    public static function removalTurnstileEnabled(): bool
    {
        return self::commentTurnstileSiteKey() !== ''
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
            && self::isDevModeEnabled()
            && self::isToolsDevMode()
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

        if (!self::isDevModeEnabled() || !self::isToolsDevMode()) {
            wp_die(__('Frontend dry run is only available when DNSBL dev mode is enabled and Tools environment mode is set to dev.', 'tornevall-networks-dnsbl-implementation'));
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

    public static function canonicalToolsMode($mode): string
    {
        return sanitize_key((string) $mode) === 'dev' ? 'dev' : 'prod';
    }

    public static function toolsToken(): string
    {
        return self::apiToken();
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

        $currentDelistingPage = self::configuredDelistingPageId();
        if ($currentDelistingPage <= 0 || (int) $post->ID !== $currentDelistingPage) {
            return $content;
        }

        return $content;
    }

    public static function disableComments($open)
    {
        global $post, $dnsbl_blacklist_control_status, $dnsbl_blacklist_status;

        $remoteAddr = self::currentVisitorIp();
        $currentDelistingPage = self::configuredDelistingPageId();

        if ($dnsbl_blacklist_control_status !== 'checked' && $remoteAddr) {
            $dnsbl_blacklist_status = !empty(self::evaluateBlacklistState($remoteAddr)['blocked']);
        }

        if ((self::isCurrentInternalDelistRequest() || ($post && isset($post->ID) && (int) $post->ID === $currentDelistingPage))
            && get_option('tornevall_dnsbl_delistingpage_comments_disabled') === '1') {
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

