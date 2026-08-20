<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

trait WooCommerceNotificationsTrait
{
    /**
         * @param array{ip:string,bitmask:int,active_flags:list<string>,matched_flags:list<string>} $evaluation
         * @param array{order_id:int,order_total:string} $context
         */
    private static function sendInstantNotification(array $evaluation, array $context): void
    {
        $siteName = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] DNSBL blocked a checkout attempt', $siteName);
        $body = self::formatNotificationBody($evaluation, $context);

        wp_mail(self::notificationRecipients(), $subject, $body);
    }

    /**
         * @param array{ip:string,bitmask:int,active_flags:list<string>,matched_flags:list<string>} $evaluation
         * @param array{order_id:int,order_total:string} $context
         */
    private static function storeBulkNotification(array $evaluation, array $context): void
    {
        global $wpdb;

        $table = self::tableName($wpdb);
        if (!self::tableExists($wpdb, $table)) {
            self::createLogTable();
        }

        $wpdb->insert(
            $table,
            [
                'ip' => $evaluation['ip'],
                'flags' => implode(',', $evaluation['matched_flags']),
                'order_id' => $context['order_id'],
                'order_total' => $context['order_total'] !== '' ? $context['order_total'] : null,
                'blocked_at' => current_time('mysql', true),
                'notified' => 0,
            ],
            ['%s', '%s', '%d', '%s', '%s', '%d']
        );

        self::syncNotificationSchedule();
    }

    public static function sendBulkNotifications(): void
    {
        global $wpdb;

        $table = self::tableName($wpdb);
        if (!self::tableExists($wpdb, $table)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the trusted WordPress prefix.
        $rows = $wpdb->get_results("SELECT id, ip, flags, order_id, order_total, blocked_at FROM {$table} WHERE notified = 0 ORDER BY id ASC LIMIT 500", ARRAY_A);
        if (!is_array($rows) || !count($rows)) {
            return;
        }

        $siteName = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] DNSBL blocked checkout attempts (%d)', $siteName, count($rows));
        $lines = [
            sprintf('Site: %s', $siteName),
            sprintf('Blocked checkout attempts: %d', count($rows)),
            sprintf('DNSBL settings: %s', self::getSettingsUrl()),
            '',
        ];

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row['id'];
            $lines[] = sprintf(
                '%s | IP: %s | Flags: %s | Order: %s | Total: %s',
                (string)$row['blocked_at'],
                (string)$row['ip'],
                (string)$row['flags'],
                ((int)$row['order_id']) > 0 ? '#' . (int)$row['order_id'] : 'not created',
                $row['order_total'] !== null && $row['order_total'] !== '' ? (string)$row['order_total'] : 'n/a'
            );
        }

        if (!wp_mail(self::notificationRecipients(), $subject, implode("\n", $lines))) {
            return;
        }

        $ids = array_values(array_filter(array_map('absint', $ids)));
        if (!count($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder count are generated internally.
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET notified = 1 WHERE id IN ({$placeholders})", $ids));
    }

    /**
         * @param array{ip:string,bitmask:int,active_flags:list<string>,matched_flags:list<string>} $evaluation
         * @param array{order_id:int,order_total:string} $context
         */
    private static function formatNotificationBody(array $evaluation, array $context): string
    {
        $siteName = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        return implode("\n", [
            sprintf('Site: %s', $siteName),
            sprintf('IP: %s', $evaluation['ip']),
            sprintf('Matched checkout flags: %s', implode(', ', $evaluation['matched_flags'])),
            sprintf('All active flags: %s', implode(', ', $evaluation['active_flags'])),
            sprintf('Resolved bitmask: %d', $evaluation['bitmask']),
            sprintf('Timestamp (UTC): %s', current_time('mysql', true)),
            sprintf('Order: %s', $context['order_id'] > 0 ? '#' . $context['order_id'] : 'not created'),
            sprintf('Order total: %s', $context['order_total'] !== '' ? $context['order_total'] : 'n/a'),
            sprintf('DNSBL settings: %s', self::getSettingsUrl()),
        ]);
    }

    /**
         * @return list<string>
         */
    private static function notificationRecipients(): array
    {
        $recipients = [];
        $adminEmail = sanitize_email((string)get_option('admin_email'));
        if ($adminEmail !== '' && is_email($adminEmail)) {
            $recipients[] = $adminEmail;
        }

        $configured = self::sanitizeEmailList(get_option('tornevall_dnsbl_wc_notify_email', ''));
        if ($configured !== '') {
            $recipients = array_merge($recipients, array_values(array_filter(explode(',', $configured))));
        }

        return array_values(array_unique($recipients));
    }

    public static function syncNotificationSchedule(): void
    {
        if (!function_exists('wp_next_scheduled')) {
            return;
        }

        if (self::notifyMode() !== 'bulk') {
            self::clearNotificationSchedule();
            return;
        }

        $schedule = self::notifySchedule();
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_HOOK) : null;

        if ($event && isset($event->schedule) && (string)$event->schedule !== $schedule) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            $event = null;
        }

        if (!$event && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $schedule, self::CRON_HOOK);
        }
    }

    public static function clearNotificationSchedule(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    public static function createLogTable(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::tableName($wpdb);
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(50) NOT NULL,
            flags TEXT NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            order_total DECIMAL(20,6) NULL,
            blocked_at DATETIME NOT NULL,
            notified TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY notified_index (notified, blocked_at),
            KEY ip_index (ip)
        ) {$wpdb->get_charset_collate()};";

        dbDelta($sql);
    }

    public static function tableDefinition(): string
    {
        return '
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip` VARCHAR(50) NOT NULL,
            `flags` TEXT NOT NULL,
            `order_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `order_total` DECIMAL(20,6) NULL,
            `blocked_at` DATETIME NOT NULL,
            `notified` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `notified_index` (`notified`, `blocked_at`),
            KEY `ip_index` (`ip`)
        ';
    }

    public static function tableName($wpdb): string
    {
        return $wpdb->prefix . self::TABLE_NAME;
    }

    private static function tableExists($wpdb, string $table): bool
    {
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }

}
