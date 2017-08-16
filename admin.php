<?php

/**
 * Compatibility set for DNSBL v5. This is a list over how DNSBL v5 differs compared to this plugin
 *
 * checked      became REPORTED, but is deprecated
 * working      became CONFIRMED
 * email        became FRAUDBL
 * timeout      became EMPTY, and is deprecated
 * error        became SPAM
 * elite        became SECOND_ENTRY
 * abuse        is still ABUSE
 * anonymous    is still anonymous but renamed to DIFFERENT_STATE
 *
 */

function register_dnsbl_settings() {
	register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_cache_age' );
	register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_filter_types' );
	register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_nocomment' );
	register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_blockfull' );
	register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_update_timestamp' );
	register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_db_version' );

	register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_fraudbl_resursbank_woocommerce' );
}

function tornevall_dnsbl_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
	global $wpdb;

	$blockHistoryTime = strftime( "%Y-%m-%d %H:%M:%S", time() - 86400 );

	$dnsblCounter = 0;
	$statsInfo    = $wpdb->get_results( "SELECT COUNT(*) AS count FROM " . ( $wpdb->prefix . "dnsblstats" ) . " WHERE resolvetime > '" . $blockHistoryTime . "'" );
	if ( isset( $statsInfo[0]->count ) ) {
		$dnsblCounter = $statsInfo[0]->count;
	}

	?>
    <h1><?php echo __( "Tornevall Networks DNSBL Options", "tornevall_dnsbl" ); ?></h1>

    <h2><?php echo __( "Information" ); ?></h2>
	<?php echo __( "Tornevall Networks is offering some ways for you, to get information about the ongoing projects. You can always go there for support, help and updates.", "tornevall_dnsbl" ); ?>
    <br>
	<?php echo __( "Here are some links for you, that you may want to remember." ); ?><br>
    <br>
    <a href="https://docs.tornevall.net/x/AoA_/"
       target="_blank"><?php echo __( "DNSBL usage documentation", "tornevall_dnsbl" ); ?></a><br>
    <a href="https://tracker.tornevall.net/projects/DNSBLWP"
       target="_blank"><?php echo __( "Project status for this plugin", "tornevall_dnsbl" ); ?></a><br>
    <a href="https://dnsbl.tornevall.org/removal/"
       target="_blank"><?php echo __( "Primary site for the DNSBL with removal instructions, usage, etc", "tornevall_dnsbl" ); ?></a>
    <br>
    <br>

    <h2>DNSBL Actions</h2>
    <form method="post" action="options.php">
		<?php
		settings_fields( 'dnsblOptions-group' );
		do_settings_sections( 'dnsblOptions-group' );

		$types = get_option( "tornevall_dnsbl_filter_types" );
		?>
        <table width="800" cellpadding="6" cellspacing="0" style="border: 1px solid black;">
            <tr>
                <td>
                    <b><?php echo __( "Cache age", "tornevall_dnsbl" ); ?></b><br>
                    <i><?php echo __( "Defines for how long one blacklisted ip should checked against cache instead of resolvers.", "tornevall_dnsbl" ); ?></i>
                </td>
                <td>
                    <input type="text" name="tornevall_dnsbl_cache_age"
                           value="<?php echo esc_attr( get_option( 'tornevall_dnsbl_cache_age' ) ? get_option( 'tornevall_dnsbl_cache_age' ) : 900 ); ?>">
                </td>
            </tr>
            <tr valign="top">
                <td>
                    <b><?php echo __( "Actions on", "tornevall_dnsbl" ); ?></b>
                </td>
                <td>
                    <select multiple size="8" name="tornevall_dnsbl_filter_types[]">
                        <option value="reported" <?php echo( isset( $types ) && is_array( $types ) && in_array( "reported", $types ) ? "selected=selected" : "" ); ?>><?php echo __( "Reported proxy (not confirmed, and might be false alarm)", "tornevall_dnsbl" ); ?></option>
                        <option value="confirmed" <?php echo( isset( $types ) && is_array( $types ) && in_array( "confirmed", $types ) ? "selected=selected" : "" ); ?>><?php echo __( "Confirmed proxy (internally tested)", "tornevall_dnsbl" ); ?></option>
                        <option value="fraudbl" <?php echo( isset( $types ) && is_array( $types ) && in_array( "fraudbl", $types ) ? "selected=selected" : "" ); ?>><?php echo __( "FraudBL host (Reported as phishing/fraudmail)", "tornevall_dnsbl" ); ?></option>
                        <option value="spam" <?php echo( isset( $types ) && is_array( $types ) && in_array( "spam", $types ) ? "selected=selected" : "" ); ?>><?php echo __( "Host has been used to spam via e-mail" ); ?></option>
                        <option value="second_entry" <?php echo( isset( $types ) && is_array( $types ) && in_array( "second_entry", $types ) ? "selected=selected" : "" ); ?>><?php echo __( "Anonymous proxies / TOR Exit nodes", "tornevall_dnsbl" ); ?></option>
                        <option value="abuse" <?php echo( isset( $types ) && is_array( $types ) && in_array( "abuse", $types ) ? "selected=selected" : "" ); ?>><?php echo __( "Address that has been marked as abusive host (webspammers, etc)", "tornevall_dnsbl" ); ?></option>
                        <option value="different_state" <?php echo( isset( $types ) && is_array( $types ) && in_array( "different_state", $types ) ? "selected=selected" : "" ); ?>><?php echo __( "Anonymous hosts (where ip has another kinds of anonymous states)", "tornevall_dnsbl" ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <input type="checkbox" <?php echo( get_option( "tornevall_dnsbl_nocomment" ) ? "checked" : "" ); ?>
                           value="1"
                           name="tornevall_dnsbl_nocomment"> <?php echo __( "Hide comment section on detection", "tornevall_dnsbl" ); ?>
                    <br>
                    <input type="checkbox" <?php echo( get_option( "tornevall_dnsbl_blockfull" ) ? "checked" : "" ); ?>
                           value="1"
                           name="tornevall_dnsbl_blockfull"> <?php echo __( "Immediately block access to whole page on detection (Redirecting to DNSBL-page)", "tornevall_dnsbl" ); ?>
                    <br>
                    <!--
                    <hr>
                    <b>FraudBLv2 Blacklist for eCommerce</b><br>
                    <input type="checkbox" <?php echo( get_option( "tornevall_dnsbl_fraudbl_resursbank_woocommerce" ) ? "checked" : "" ); ?> value="1" name="tornevall_dnsbl_fraudbl_resursbank_woocommerce"> <?php echo __( "Enable FraudBLv2 for Resurs Bank WooCommerce Plugin", "tornevall_dnsbl" ); ?><br>
                    -->
                </td>
            </tr>

        </table>
		<?php submit_button(); ?>
    </form>
	<?php
}

function tornevall_dnsbl_fraudbl() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
	global $wpdb;
	?>
    <h1><?php echo __( "Tornevall Networks DNSBL - FraudBLv2", "tornevall_dnsbl" ); ?></h1>
    <h2><?php echo __( "What is this?", "tornevall_dnsbl" ) ?></h2>
    <div style="width:800px">
		<?php echo __( "FraudBLv2 is a blacklisting system used for eCommerce sites, to prevent fraud on a global level.", "tornevall_dnsbl" ) ?>
        <br>
        <br>
		<?php echo __( "In short, you could say that if you run a payment service provider that supports fraud detection, you could share data with other websites, to temporarily disable payments made from ip-addresses that gets positive hits during a payment. To keep high privacy profile, no personal customer data is shared nor stored anywhere - the only data we actually need, is an ip address, during the blacklisting process.", "tornevall_dnsbl" ) ?><br>
        <br>
        <i><?php echo __( "Data that comes from FraudBLv2 has unlike the regular DNS Blacklist service, significantly shorter life span since ip-address aging tend to have high speed during changes. Delisting from this service should therefore normally not be needed as they do expire automatically after a shorter time, unless the don't show up somewhere else during the blacklisting period.", "tornevall_dnsbl" ) ?></i><br>
        <br>
        <i><?php echo __( "You can read more about this at", "tornevall_dnsbl" ) ?><a href="https://docs.tornevall.net/x/IIAeAQ" target="_blank">https://docs.tornevall.net/x/IIAeAQ</a></i><br>
        <br>
        <b><?php echo __( "Usage of this service is free. You however need an API key to be able to register ip addresses in the system. You can register such key at", "tornevall_dnsbl" ) ?> <a href="https://auth.tornevall.net/">https://auth.tornevall.net</a></b>
        <br>
    </div>
    <hr>
    <h1><?php echo __( "Configuration", "tornevall_dnsbl" ) ?></h1>
    <?php echo __( "API Key", "tornevall_dnsbl" ) ?>
    <?php echo __( "Minimum lifetime cycle", "tornevall_dnsbl" ) ?>
	<?php
}
