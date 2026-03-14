<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the current schema version used for migration checks.
 *
 * @return string
 */
function tornevall_dnsbl_schema_version()
{
    return '3.0.0';
}

/**
 * Get the active table definitions for the plugin schema.
 *
 * @return array<string,string>
 */
function tornevall_dnsbl_migration_table_definitions()
{
    return [
        'dnsblcache' => '
            `ipAddr` VARCHAR(50) NOT NULL,
            `lastResponse` INT NOT NULL DEFAULT 0,
            `lastResolve` INT NULL DEFAULT 0,
            PRIMARY KEY (`ipAddr`)
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

/**
 * Get database tables that should be removed during upgrade or uninstall cleanup.
 *
 * @param wpdb $wpdb WordPress database abstraction.
 *
 * @return string[]
 */
function tornevall_dnsbl_migration_table_cleanup_candidates($wpdb)
{
    return [
        $wpdb->prefix . 'dnsbl_cache',
        $wpdb->prefix . 'dnsbl_stats',
        $wpdb->prefix . 'tornevall_dnsbl_cache',
        $wpdb->prefix . 'tornevall_dnsbl_stats',
    ];
}

/**
 * Get retired option keys that should be deleted during cleanup.
 *
 * @return string[]
 */
function tornevall_dnsbl_migration_retired_options()
{
    return [
        'tornevall_dnsbl_form_noajax',
        'tornevall_dnsbl_getlisted_resolver',
    ];
}

/**
 * Remove retired options from WordPress storage.
 *
 * @return void
 */
function tornevall_dnsbl_migration_cleanup_retired_options()
{
    foreach (tornevall_dnsbl_migration_retired_options() as $optionKey) {
        delete_option($optionKey);
    }
}

/**
 * Run the install or upgrade migration.
 *
 * @return void
 */
function tornevall_dnsbl_migration_run()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names are generated internally from the trusted WordPress table prefix.
    foreach (tornevall_dnsbl_migration_table_cleanup_candidates($wpdb) as $tableName) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $tableName);
    }
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

    tornevall_dnsbl_migration_cleanup_retired_options();

    $queries = [];
    foreach (tornevall_dnsbl_migration_table_definitions() as $tableName => $tableData) {
        $queries[] = 'CREATE TABLE ' . $wpdb->prefix . $tableName . ' (' . $tableData . ') ' . $wpdb->get_charset_collate();
    }
    dbDelta($queries);

    $defaults = [
        'tornevall_dnsbl_cache_age' => 900,
        'tornevall_dnsbl_resolver_hosts' => 'dnsbl.tornevall.org,bl.fraudbl.org',
        'tornevall_dnsbl_tools_mode' => 'auto',
    ];

    foreach ($defaults as $key => $value) {
        if (get_option($key) === false) {
            add_option($key, $value);
        }
    }

    update_option('tornevall_dnsbl_database_version', tornevall_dnsbl_schema_version());
}

/**
 * Run the migration when the stored database version is outdated.
 *
 * @return void
 */
function tornevall_dnsbl_migration_maybe_upgrade()
{
    $dbVersion = get_option('tornevall_dnsbl_database_version');
    if ($dbVersion === false || version_compare((string)$dbVersion, tornevall_dnsbl_schema_version(), '<')) {
        tornevall_dnsbl_migration_run();
        return;
    }

    tornevall_dnsbl_migration_cleanup_retired_options();
}

/**
 * Activation callback that ensures the schema is up to date.
 *
 * @return void
 */
function tornevall_dnsbl_migration_activate()
{
    tornevall_dnsbl_migration_run();
}

/**
 * Deactivation callback.
 *
 * @return void
 */
function tornevall_dnsbl_migration_deactivate()
{
    // Keep tables and options intact on deactivate.
}

/**
 * Remove plugin tables and options during uninstall.
 *
 * @return void
 */
function tornevall_dnsbl_migration_uninstall()
{
    global $wpdb;

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names are generated internally from the trusted WordPress table prefix.
    foreach (array_keys(tornevall_dnsbl_migration_table_definitions()) as $tableName) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $tableName);
    }

    foreach (tornevall_dnsbl_migration_table_cleanup_candidates($wpdb) as $tableName) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $tableName);
    }
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

    $optionsToDelete = [
        'tornevall_dnsbl_cache_age',
        'tornevall_dnsbl_filter_types',
        'tornevall_dnsbl_nocomment',
        'tornevall_dnsbl_blockfull',
        'tornevall_dnsbl_delisting_page',
        'tornevall_dnsbl_update_timestamp',
        'tornevall_dnsbl_resolver_hosts',
        'tornevall_dnsbl_blocked_redirecturl',
        'tornevall_dnsbl_comments_disabled_style',
        'tornevall_dnsbl_delistingpage_comments_disabled',
        'tornevall_dnsbl_current_flags',
        'tornevall_dnsbl_dev_mode',
        'tornevall_dnsbl_tools_token',
        'tornevall_dnsbl_tools_mode',
        'tornevall_dnsbl_removal_token',
        'tornevall_dnsbl_database_version',
    ];

    $optionsToDelete = array_merge($optionsToDelete, tornevall_dnsbl_migration_retired_options());

    foreach ($optionsToDelete as $optionKey) {
        delete_option($optionKey);
    }
}

