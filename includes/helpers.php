<?php

/**
 * Plugin activation hook
 *
 * Activates the plugin and updates the database with new tables.
 */
function tornevall_wp_dnsbl_activate_db()
{
    global $wpdb;

    $dnsbl_db_tables = array(
        'dnsblcache' => '
            `ipAddr` VARCHAR(50) NOT NULL,
            `lastResponse` INT NOT NULL DEFAULT 0,
            `lastResolve` INT NULL DEFAULT 0,
            PRIMARY KEY (`ipAddr`, `lastResponse`)
            ',
        'dnsblstats' => '
            `ipAddr` VARCHAR(50) NULL,
            `resolveTime` INT NULL DEFAULT 0,
            `wasBlocked` INT NULL DEFAULT 0,
            INDEX `denyIndex` (`ipAddr` ASC, `wasBlocked` ASC)
        ',
    );

    foreach ($dnsbl_db_tables as $tableName => $tableData) {
        $dbDeltaQuery[] = 'CREATE TABLE ' . $wpdb->prefix . $tableName . ' (' . $tableData . ') ' . $wpdb->get_charset_collate();
    }
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($dbDeltaQuery);
}

/**
 * Plugin deactivation hook
 *
 * Each time the plugin gets deactivated, we'll clear up the tables. As the database tables acts like caches, this is normally
 * preferred as we also get fresh tables during next upgrade, if there are upgrades that affects the database. However,
 * the statistics database is not dropped at this moment.
 */
function tornevall_wp_dnsbl_deactivate_db()
{
    global $wpdb;

    $dnsbl_db_tables = array('dnsblcache', 'dnsblstats');
    foreach ($dnsbl_db_tables as $tableName) {
        $wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . $tableName);
    }
}

/**
 * Plugin uninstallation hook
 *
 * When we delete the plugin we'll also clean up the last statistics table
 */
function tornevall_wp_dnsbl_uninstall_db()
{
    global $wpdb;

    $dnsbl_db_tables = array('dnsblstats');
    foreach ($dnsbl_db_tables as $tableName) {
        $wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . $tableName);
    }
}