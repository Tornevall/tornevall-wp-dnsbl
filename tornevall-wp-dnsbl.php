<?php

/*
 * Plugin Name: Tornevall Networks DNSBL Implementation
 * Plugin URI: http://docs.tornevall.net/x/AoA_/
 * Project URI: http://tracker.tornevall.net/projects/TSDWP/
 * Description: Implements functions related to Tornevall Networks DNS Blacklist. Adds options to comment functions that will disable comments if an ip is blacklisted etc
 * Version: 1.0.5
 * Author: Tomas Tornevall
 * Author URI: http://tornevalls.se/blog/
 */

define( 'TORNEVALL_DNSBL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TORNEVALL_DNSBL_VERSION', '1.0.3' );
define( 'TORNEVALL_DNSBL_DATA_VERSION', '1.0.1' );

require_once('tornevall-wp-dnsbl-functions.php');

function tornevall_wp_dnsbl_install_db()
{
    global $wpdb;
    $table_cache_name = $wpdb->prefix . "dnsblcache";
    $table_stats_name = $wpdb->prefix . "dnsblstats";
    $charset_collate = '';
    if ( ! empty( $wpdb->charset ) ) {$charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";}
    if ( ! empty( $wpdb->collate ) ) {$charset_collate .= " COLLATE {$wpdb->collate}";}

    //$installed_db = get_option( "tornevall_dnsbl_db_version" );

    $sql_cache = "CREATE TABLE $table_cache_name (
      ip varchar(45) NOT NULL DEFAULT '',
      resolvetime datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
      resolve int(10) unsigned NOT NULL DEFAULT '0',
      UNIQUE KEY (ip)
      ) $charset_collate;";
    $sql_stats = "CREATE TABLE $table_stats_name (
      ip varchar(45) NOT NULL DEFAULT '',
      resolvetime datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
      blocked varchar(16) NOT NULL DEFAULT '',
      INDEX index_blocks (blocked)
      ) $charset_collate;";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql_cache );
    dbDelta( $sql_stats );
    update_option( "tornevall_dnsbl_db_version", TORNEVALL_DNSBL_DATA_VERSION );
}

function tornevall_wp_dnsbl_admin()
{
    add_action( 'admin_init', 'register_dnsbl_settings' );
    add_options_page('Tornevall DNSBL', 'Tornevall DNSBL', 'manage_options', 'dnsblOptions', 'tornevall_dnsbl_options');
}

if ( is_admin() ) {

    require_once( TORNEVALL_DNSBL_PLUGIN_DIR . 'admin.php' );
    add_action('admin_menu', 'tornevall_wp_dnsbl_admin');
    register_activation_hook( __FILE__, 'tornevall_wp_dnsbl_install_db' );
}

