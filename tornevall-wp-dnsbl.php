<?php
/*
 * Plugin Name: Tornevall Networks DNSBL Implementation
 * Plugin URI: https://docs.tornevall.net/x/AoA_/
 * Project URI: https://tracker.tornevall.net/projects/DNSBLWP/
 * Description: Implements functions related to Tornevall Networks DNS Blacklist. Adds options to comment functions that will disable comments if an ip is blacklisted etc
 * Version: 1.1.1
 * Author: Tomas Tornevall
 * Author URI: http://tornevalls.se/blog/
 * Text Domain: tornevall_dnsbl
 * Domain Path: /language
 */

define( 'TORNEVALL_DNSBL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TORNEVALL_DNSBL_VERSION', '1.1.1' );
define( 'TORNEVALL_DNSBL_DATA_VERSION', '1.0.2' );
define( 'TORNEVALL_DNSBL_SHORT_PATH', 'tornevall-networks-dnsbl-implementation' );

require_once( 'includes/tornevall_bits.php' );
require_once( 'includes/tornevall_network.php' );
require_once( 'includes/tornevall_dnsbl_functions.php' );
//require_once('includes/resursbank.php');

function dsnbl_nocurl_notice() {
	?>
    <div class="notice notice-error">
        <p><?php _e( 'Warning: Curl has been disabled on your platform, so this plugin will not function properly.', 'tornevall_dnsbl' ); ?></p>
    </div>
	<?php
}

function tornevall_wp_dnsbl_install_db() {
	global $wpdb;
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	$tableCacheName  = $wpdb->prefix . "dnsblcache";
	$tableStatsName  = $wpdb->prefix . "dnsblstats";
	$charset_collate = '';
	if ( ! empty( $wpdb->charset ) ) {
		$charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
	}
	if ( ! empty( $wpdb->collate ) ) {
		$charset_collate .= " COLLATE {$wpdb->collate}";
	}
	$sql_cache = "CREATE TABLE {$tableCacheName} (
      ip varchar(50) NOT NULL,
      resolvetime datetime,
      resolve int(10) unsigned NOT NULL DEFAULT '0',
      PRIMARY KEY (ip)
      UNIQUE KEY ip (ip)
      ) $charset_collate;";
	$sql_stats = "CREATE TABLE {$tableStatsName} (
      ip varchar(50) NOT NULL,
      resolvetime datetime,
      blocked varchar(16) NOT NULL DEFAULT '',
      INDEX index_blocks (blocked)
      ) $charset_collate;";
	dbDelta( array( $sql_stats, $sql_cache ) );
	update_option( "tornevall_dnsbl_db_version", TORNEVALL_DNSBL_DATA_VERSION );
}

function tornevall_wp_dnsbl_admin() {
	add_action( 'admin_init', 'register_dnsbl_settings' );
	add_menu_page( "Tornevall DNSBL Options", __( "Tornevall DNSBL", "tornevall_dnsbl" ), "manage_options", "tornevallDnsblMenu", "tornevall_dnsbl_options" );
	//add_submenu_page("tornevallDnsblMenu", "FraudBLv2", "FraudBLv2", "manage_options", "tornevallDnsblFraudBl", "tornevall_dnsbl_fraudbl");
}

if ( is_admin() ) {
	require_once( TORNEVALL_DNSBL_PLUGIN_DIR . 'admin.php' );
	add_action( 'admin_menu', 'tornevall_wp_dnsbl_admin' );
	register_activation_hook( __FILE__, 'tornevall_wp_dnsbl_install_db' );
}
function dnsbl_disable_comments( $open = '', $post_id = '' ) {
	return false;
}

load_plugin_textdomain( 'tornevall_dnsbl', false, dirname( plugin_basename( __FILE__ ) ) . '/language' );
$TornevallDNSBL = new \Tornevall_WP_DNSBL\TornevallDNSBL();

if ( ! function_exists( 'curl_exec' ) ) {
	add_action( 'admin_notices', 'dsnbl_nocurl_notice' );
} else {
	if ( ! is_admin() ) {
		$TornevallDNSBL->testip( $_SERVER['REMOTE_ADDR'] );
	}
}