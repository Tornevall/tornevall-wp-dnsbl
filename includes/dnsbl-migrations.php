<?php

use Tornevall\Networks\DNSBL\Migrations;

if (!defined('ABSPATH')) {
    exit;
}

function tornevall_dnsbl_schema_version()
{
    return Migrations::schemaVersion();
}

function tornevall_dnsbl_migration_table_definitions()
{
    return Migrations::tableDefinitions();
}

function tornevall_dnsbl_migration_table_cleanup_candidates($wpdb)
{
    return Migrations::tableCleanupCandidates($wpdb);
}

function tornevall_dnsbl_migration_retired_options()
{
    return Migrations::retiredOptions();
}

function tornevall_dnsbl_migration_cleanup_retired_options()
{
    Migrations::cleanupRetiredOptions();
}

function tornevall_dnsbl_migration_run()
{
    Migrations::run();
}

function tornevall_dnsbl_migration_maybe_upgrade()
{
    Migrations::maybeUpgrade();
}

function tornevall_dnsbl_migration_activate()
{
    Migrations::activate();
}

function tornevall_dnsbl_migration_deactivate()
{
    Migrations::deactivate();
}

function tornevall_dnsbl_migration_uninstall()
{
    Migrations::uninstall();
}

