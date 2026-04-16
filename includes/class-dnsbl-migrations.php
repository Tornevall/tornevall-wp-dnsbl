<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

class Migrations
{
    public static function maybeUpgrade(): void
    {
        $dbVersion = get_option('tornevall_dnsbl_database_version');
        if ($dbVersion === false || version_compare((string)$dbVersion, self::schemaVersion(), '<')) {
            self::run();
            return;
        }

        self::cleanupRetiredOptions();
        self::ensureDefaultOptions();
        Plugin::syncCacheCleanupSchedule();
    }

    public static function schemaVersion(): string
    {
        return '3.1.0';
    }

    public static function run(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names are generated internally from the trusted WordPress table prefix.
        foreach (self::tableCleanupCandidates($wpdb) as $tableName) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $tableName);
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        self::cleanupRetiredOptions();

        $queries = [];
        foreach (self::tableDefinitions() as $tableName => $tableData) {
            $queries[] = 'CREATE TABLE ' . $wpdb->prefix . $tableName . ' (' . $tableData . ') ' . $wpdb->get_charset_collate();
        }
        dbDelta($queries);

        self::ensureDefaultOptions();

        update_option('tornevall_dnsbl_database_version', self::schemaVersion());
        Plugin::registerInternalDelistRewrite();
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
        Plugin::syncCacheCleanupSchedule();
        Plugin::purgeExpiredCache();
    }

    /**
     * @param \wpdb $wpdb
     *
     * @return string[]
     */
    public static function tableCleanupCandidates($wpdb): array
    {
        return [
            $wpdb->prefix . 'dnsbl_cache',
            $wpdb->prefix . 'dnsbl_stats',
            $wpdb->prefix . 'tornevall_dnsbl_cache',
            $wpdb->prefix . 'tornevall_dnsbl_stats',
        ];
    }

    public static function cleanupRetiredOptions(): void
    {
        foreach (self::retiredOptions() as $optionKey) {
            delete_option($optionKey);
        }
    }

    /**
     * @return string[]
     */
    public static function retiredOptions(): array
    {
        return [
            'tornevall_dnsbl_form_noajax',
            'tornevall_dnsbl_getlisted_resolver',
            'tornevall_dnsbl_removal_token', // replaced by tornevall_dnsbl_write_token in 3.1.0
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function tableDefinitions(): array
    {
        return [
            'dnsblcache' => '
                `ipAddr` VARCHAR(50) NOT NULL,
                `lastResponse` INT NOT NULL DEFAULT 0,
                `lastResolve` INT NULL DEFAULT 0,
                PRIMARY KEY (`ipAddr`),
                KEY `lastResolveIndex` (`lastResolve`)
            ',
            'dnsblstats' => '
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ipAddr` VARCHAR(50) NULL,
                `resolveTime` INT NULL DEFAULT 0,
                `wasBlocked` INT NULL DEFAULT 0,
                `source` VARCHAR(32) NULL,
                `createdAt` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `denyIndex` (`ipAddr`, `wasBlocked`)
            ',
        ];
    }

    public static function ensureDefaultOptions(): void
    {
        foreach (Plugin::defaultOptions() as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        // Migrate legacy removal_token -> write_token on first upgrade.
        $legacy = trim((string)get_option('tornevall_dnsbl_removal_token'));
        if ($legacy !== '' && trim((string)get_option('tornevall_dnsbl_write_token')) === '') {
            update_option('tornevall_dnsbl_write_token', $legacy);
        }

        // Older plugin builds exposed a separate hidden Tools token option.
        // Migrate any visible legacy value into the single current API token field.
        $writeToken = trim((string)get_option('tornevall_dnsbl_write_token'));
        $toolsToken = trim((string)get_option('tornevall_dnsbl_tools_token'));
        if ($writeToken === '' && $toolsToken !== '') {
            update_option('tornevall_dnsbl_write_token', $toolsToken);
            $writeToken = $toolsToken;
        }

        Plugin::maybeUpgradeSelectedFlags();
        self::maybeAddMissingResolverHosts();
    }

    /**
     * Ensure all four canonical DNSBL zones are present in the stored resolver hosts option.
     * Adds any missing default hosts without removing custom hosts the admin may have added.
     */
    public static function maybeAddMissingResolverHosts(): void
    {
        $stored = trim((string)get_option('tornevall_dnsbl_resolver_hosts'));
        if ($stored === '') {
            // Empty: ensureDefaultOptions() will have already added the default via add_option.
            return;
        }

        $current = array_values(array_filter(array_map('trim', explode(',', $stored))));
        $changed = false;

        foreach (Plugin::defaultResolvers() as $host) {
            if (!in_array($host, $current, true)) {
                $current[] = $host;
                $changed = true;
            }
        }

        if ($changed) {
            update_option('tornevall_dnsbl_resolver_hosts', implode(',', $current));
        }
    }

    public static function activate(): void
    {
        self::run();
    }

    public static function deactivate(): void
    {
        Plugin::clearCacheCleanupSchedule();
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
    }

    public static function uninstall(): void
    {
        global $wpdb;

        Plugin::clearCacheCleanupSchedule();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names are generated internally from the trusted WordPress table prefix.
        foreach (array_keys(self::tableDefinitions()) as $tableName) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $tableName);
        }

        foreach (self::tableCleanupCandidates($wpdb) as $tableName) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $tableName);
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        $optionsToDelete = [
            'tornevall_dnsbl_cache_age',
            'tornevall_dnsbl_cache_cleanup_interval',
            'tornevall_dnsbl_cache_last_cleanup',
            'tornevall_dnsbl_filter_types',
            'tornevall_dnsbl_nocomment',
            'tornevall_dnsbl_blockfull',
            'tornevall_dnsbl_delisting_page',
            'tornevall_dnsbl_update_timestamp',
            'tornevall_dnsbl_resolver_hosts',
            'tornevall_dnsbl_whitelist',
            'tornevall_dnsbl_blocked_redirecturl',
            'tornevall_dnsbl_comments_disabled_style',
            'tornevall_dnsbl_delistingpage_comments_disabled',
            'tornevall_dnsbl_current_flags',
            'tornevall_dnsbl_dev_mode',
            'tornevall_dnsbl_tools_token',
            'tornevall_dnsbl_tools_mode',
            'tornevall_dnsbl_write_token',
            'tornevall_dnsbl_auto_report_spam',
            'tornevall_dnsbl_removal_token',
            'tornevall_dnsbl_comment_turnstile_enabled',
            'tornevall_dnsbl_comment_turnstile_site_key',
            'tornevall_dnsbl_comment_turnstile_secret_key',
            'tornevall_dnsbl_comment_turnstile_theme',
            'tornevall_dnsbl_registration_dnsbl_enabled',
            'tornevall_dnsbl_registration_turnstile_enabled',
            'tornevall_dnsbl_database_version',
        ];

        $optionsToDelete = array_merge($optionsToDelete, self::retiredOptions());

        foreach ($optionsToDelete as $optionKey) {
            delete_option($optionKey);
        }
    }
}

