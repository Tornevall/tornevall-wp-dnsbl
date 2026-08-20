<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Explicit opt-in telemetry for aggregate DNSBL usage statistics.
 *
 * The telemetry path never sends queried IP addresses, comments, usernames,
 * site URLs, or raw DNS responses. It aggregates the plugin's existing local
 * dnsblstats rows and submits one periodic batch through the configured Tools
 * DNSBL token.
 */
class Telemetry
{
    private const CONSENT_OPTION = 'tornevall_dnsbl_telemetry_consent';
    private const DECIDED_OPTION = 'tornevall_dnsbl_telemetry_consent_decided';
    private const CURSOR_OPTION = 'tornevall_dnsbl_telemetry_cursor';
    private const PENDING_OPTION = 'tornevall_dnsbl_telemetry_pending_batch';
    private const LAST_SENT_OPTION = 'tornevall_dnsbl_telemetry_last_sent';
    private const CRON_HOOK = 'tornevall_dnsbl_telemetry_flush';
    private const ADMIN_ACTION = 'tornevall_dnsbl_save_telemetry_consent';
    private const ENDPOINT = '/api/dnsbl/telemetry/batch';
    private const SCHEMA_VERSION = 1;

    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'syncSchedule']);
        add_action(self::CRON_HOOK, [self::class, 'flush']);
        add_action('admin_post_' . self::ADMIN_ACTION, [self::class, 'handleConsentSave']);
        add_action('admin_notices', [self::class, 'renderConsentControl']);
        add_action('admin_init', [self::class, 'addPrivacyPolicyContent']);
    }

    public static function consentEnabled(): bool
    {
        return get_option(self::CONSENT_OPTION) === '1';
    }

    public static function consentDecided(): bool
    {
        return get_option(self::DECIDED_OPTION) === '1';
    }

    public static function syncSchedule(): void
    {
        if (!function_exists('wp_next_scheduled')) {
            return;
        }

        $shouldSchedule = self::consentEnabled() && Plugin::apiToken() !== '';
        $scheduled = wp_next_scheduled(self::CRON_HOOK);

        if (!$shouldSchedule) {
            if ($scheduled && function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook(self::CRON_HOOK);
            }
            return;
        }

        if (!$scheduled) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public static function clearSchedule(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    public static function handleConsentSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to change DNSBL telemetry settings.', 'tornevall-networks-dnsbl-implementation'));
        }

        check_admin_referer(self::ADMIN_ACTION);

        $enabled = isset($_POST['tornevall_dnsbl_telemetry_consent'])
            && sanitize_key(wp_unslash($_POST['tornevall_dnsbl_telemetry_consent'])) === '1';
        $wasEnabled = self::consentEnabled();

        update_option(self::CONSENT_OPTION, $enabled ? '1' : '0', false);
        update_option(self::DECIDED_OPTION, '1', false);

        if ($enabled && !$wasEnabled) {
            self::moveCursorToCurrentEnd();
            delete_option(self::PENDING_OPTION);
        }

        if (!$enabled) {
            self::moveCursorToCurrentEnd();
            delete_option(self::PENDING_OPTION);
            self::clearSchedule();
        } else {
            self::syncSchedule();
        }

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=tornevallDnsblMenu');
        }

        $redirect = add_query_arg('tornevall_dnsbl_telemetry_saved', '1', $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    public static function renderConsentControl(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $onPluginPage = isset($_GET['page'])
            && sanitize_key(wp_unslash($_GET['page'])) === sanitize_key('tornevallDnsblMenu');

        if (self::consentDecided() && !$onPluginPage) {
            return;
        }

        $enabled = self::consentEnabled();
        $tokenConfigured = Plugin::apiToken() !== '';
        $saved = isset($_GET['tornevall_dnsbl_telemetry_saved'])
            && sanitize_key(wp_unslash($_GET['tornevall_dnsbl_telemetry_saved'])) === '1';

        $classes = $saved ? 'notice notice-success' : 'notice notice-info';
        ?>
        <div class="<?php echo esc_attr($classes); ?>">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="padding:10px 0;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_ACTION); ?>">
                <?php wp_nonce_field(self::ADMIN_ACTION); ?>
                <p style="margin-top:0;"><strong><?php echo esc_html__('Tornevall DNSBL usage statistics', 'tornevall-networks-dnsbl-implementation'); ?></strong></p>
                <label for="tornevall_dnsbl_telemetry_consent" style="display:block; margin:8px 0;">
                    <input type="checkbox"
                           id="tornevall_dnsbl_telemetry_consent"
                           name="tornevall_dnsbl_telemetry_consent"
                           value="1"
                           <?php checked($enabled); ?>>
                    <?php echo esc_html__('Allow this plugin to send anonymous aggregate usage statistics to Tornevall Tools.', 'tornevall-networks-dnsbl-implementation'); ?>
                </label>
                <p class="description" style="max-width:900px;">
                    <?php echo esc_html__('When enabled, the plugin batches aggregate DNSBL evaluation counts such as listed/not-listed outcomes, returned bitmasks, blocked/not-blocked decisions, source category, plugin version, and reporting window. Queried visitor IP addresses, comments, usernames, site URLs, and raw DNS responses are not included. Batches are normally sent about once per hour through WP-Cron and authenticated with the configured DNSBL / Tools API token. The token itself is not included in the telemetry payload.', 'tornevall-networks-dnsbl-implementation'); ?>
                </p>
                <?php if (!$tokenConfigured) { ?>
                    <p class="description">
                        <?php echo esc_html__('Telemetry cannot be sent until a DNSBL / Tools API token is configured. Consent may still be saved in advance.', 'tornevall-networks-dnsbl-implementation'); ?>
                    </p>
                <?php } ?>
                <p>
                    <button type="submit" class="button button-secondary">
                        <?php echo esc_html__('Save usage statistics preference', 'tornevall-networks-dnsbl-implementation'); ?>
                    </button>
                    <?php if ($saved) { ?>
                        <span style="margin-left:8px;"><?php echo esc_html__('Preference saved.', 'tornevall-networks-dnsbl-implementation'); ?></span>
                    <?php } ?>
                </p>
            </form>
        </div>
        <?php
    }

    public static function addPrivacyPolicyContent(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<p class="privacy-policy-tutorial">'
            . esc_html__('Tornevall DNSBL includes optional aggregate usage statistics. The feature is disabled by default and requires an administrator opt-in.', 'tornevall-networks-dnsbl-implementation')
            . '</p>'
            . '<strong class="privacy-policy-tutorial">'
            . esc_html__('Suggested text:', 'tornevall-networks-dnsbl-implementation')
            . '</strong> '
            . '<p>'
            . esc_html__('If the site administrator enables Tornevall DNSBL usage statistics, this site periodically sends aggregate DNSBL evaluation counts to Tornevall Tools. The statistics can include listed/not-listed outcomes, DNSBL bitmasks, blocked/not-blocked decisions, source categories, plugin version, and reporting time windows. Queried visitor IP addresses, comments, usernames, site URLs, and raw DNS responses are not included in these telemetry batches. The batches are authenticated using the site administrator\'s configured DNSBL / Tools API token and are normally sent about once per hour through WordPress cron.', 'tornevall-networks-dnsbl-implementation')
            . '</p>';

        wp_add_privacy_policy_content(
            __('Tornevall Networks DNSBL Implementation', 'tornevall-networks-dnsbl-implementation'),
            wp_kses_post(wpautop($content, false))
        );
    }

    public static function flush(): void
    {
        if (!self::consentEnabled() || Plugin::apiToken() === '') {
            self::clearSchedule();
            return;
        }

        $pending = get_option(self::PENDING_OPTION);
        if (!is_array($pending) || empty($pending['payload']) || empty($pending['cursor_to'])) {
            $pending = self::buildPendingBatch();
            if (!$pending) {
                return;
            }
            update_option(self::PENDING_OPTION, $pending, false);
        }

        $response = self::sendPayload((array)$pending['payload']);
        if (!$response['ok']) {
            return;
        }

        update_option(self::CURSOR_OPTION, (int)$pending['cursor_to'], false);
        update_option(self::LAST_SENT_OPTION, time(), false);
        delete_option(self::PENDING_OPTION);
    }

    /**
     * @return array{payload:array<string,mixed>,cursor_to:int}|null
     */
    private static function buildPendingBatch(): ?array
    {
        global $wpdb;

        $table = Plugin::getStatsTableName($wpdb);
        if (!Plugin::tableExists($wpdb, $table)) {
            return null;
        }

        $cursor = max(0, (int)get_option(self::CURSOR_OPTION, 0));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
        $cursorTo = (int)$wpdb->get_var("SELECT MAX(id) FROM {$table}");
        if ($cursorTo <= $cursor) {
            return null;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT resolveTime AS bitmask, wasBlocked, COALESCE(NULLIF(source, ''), 'request') AS source, COUNT(*) AS event_count, MIN(createdAt) AS first_seen, MAX(createdAt) AS last_seen FROM {$table} WHERE id > %d AND id <= %d GROUP BY resolveTime, wasBlocked, source ORDER BY resolveTime ASC, wasBlocked ASC, source ASC",
                $cursor,
                $cursorTo
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (!is_array($rows) || !$rows) {
            update_option(self::CURSOR_OPTION, $cursorTo, false);
            return null;
        }

        $events = [];
        $periodStart = null;
        $periodEnd = null;

        foreach ($rows as $row) {
            $count = max(0, (int)($row['event_count'] ?? 0));
            if ($count < 1) {
                continue;
            }

            $bitmask = max(0, min(65535, (int)($row['bitmask'] ?? 0)));
            $blocked = !empty($row['wasBlocked']);
            $source = sanitize_key((string)($row['source'] ?? 'request'));
            if ($source === '') {
                $source = 'request';
            }

            $firstSeen = self::normalizeMysqlUtc((string)($row['first_seen'] ?? ''));
            $lastSeen = self::normalizeMysqlUtc((string)($row['last_seen'] ?? ''));
            if ($firstSeen !== null && ($periodStart === null || $firstSeen < $periodStart)) {
                $periodStart = $firstSeen;
            }
            if ($lastSeen !== null && ($periodEnd === null || $lastSeen > $periodEnd)) {
                $periodEnd = $lastSeen;
            }

            $events[] = [
                'type' => 'dnsbl_evaluation',
                'bitmask' => $bitmask,
                'listed' => $bitmask > 0,
                'blocked' => $blocked,
                'source' => substr($source, 0, 32),
                'count' => $count,
            ];
        }

        if (!$events) {
            update_option(self::CURSOR_OPTION, $cursorTo, false);
            return null;
        }

        return [
            'cursor_to' => $cursorTo,
            'payload' => [
                'schema_version' => self::SCHEMA_VERSION,
                'batch_id' => wp_generate_uuid4(),
                'plugin_version' => defined('TORNEVALL_DNSBL_PLUGIN_VERSION') ? (string)TORNEVALL_DNSBL_PLUGIN_VERSION : '',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'events' => $events,
            ],
        ];
    }

    /**
     * @return array{ok:bool,status:int}
     */
    private static function sendPayload(array $payload): array
    {
        $token = Plugin::apiToken();
        $baseUrl = untrailingslashit(rtrim((string)Plugin::toolsBaseUrl(), '/'));
        if ($token === '' || $baseUrl === '') {
            return ['ok' => false, 'status' => 0];
        }

        $response = wp_remote_post($baseUrl . self::ENDPOINT, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Dnsbl-Token' => $token,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => 0];
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
        ];
    }

    private static function moveCursorToCurrentEnd(): void
    {
        global $wpdb;

        $table = Plugin::getStatsTableName($wpdb);
        if (!Plugin::tableExists($wpdb, $table)) {
            update_option(self::CURSOR_OPTION, 0, false);
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived internally from the trusted WordPress table prefix.
        $lastId = (int)$wpdb->get_var("SELECT MAX(id) FROM {$table}");
        update_option(self::CURSOR_OPTION, max(0, $lastId), false);
    }

    private static function normalizeMysqlUtc(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');
        if ($timestamp === false) {
            return null;
        }

        return gmdate('c', $timestamp);
    }

    public static function uninstall(): void
    {
        self::clearSchedule();
        foreach ([
            self::CONSENT_OPTION,
            self::DECIDED_OPTION,
            self::CURSOR_OPTION,
            self::PENDING_OPTION,
            self::LAST_SENT_OPTION,
        ] as $option) {
            delete_option($option);
        }
    }
}
