<?php

function tornevall_wp_dnsbl_admin()
{
    add_action('admin_init', 'register_dnsbl_settings');
    add_menu_page("Tornevall DNSBL Options", __("Tornevall DNSBL", "tornevall_dnsbl"), "manage_options",
        "tornevallDnsblMenu", "tornevall_dnsbl_options");
}

function register_dnsbl_settings()
{
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_cache_age');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_filter_types');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_nocomment');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_blockfull');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_update_timestamp');

    register_setting('dnsblOptions-group', 'tornevall_dnsbl_preferred_api_url');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_api_id');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_api_key');

    register_setting('dnsblOptions-group', 'tornevall_dnsbl_fraudbl_resursbank_woocommerce');
}

function tornevall_dnsbl_options()
{
    if ( ! current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    global $wpdb;

    $dnsblCounter     = 0;
    $blockHistoryTime = time() - 86400;

    $statsInfo = $wpdb->get_results("SELECT COUNT(*) AS count FROM " . ($wpdb->prefix . "dnsblstats") . " WHERE resolvetime > '" . $blockHistoryTime . "'");
    if (isset($statsInfo[0]->count)) {
        $dnsblCounter = $statsInfo[0]->count;
    }

    $authUrl = "https://auth.tornevall.net";
    $prefApiUrl = get_option('tornevall_dnsbl_preferred_api_url');
    if (empty($prefApiUrl)) {
        $prefApiUrl = "https://api.tornevall.net/3.0/";
    }

    ?>

    <h1><?php echo __('DNS Blacklist Configurator', 'tornevall_dnsbl'); ?></h1>

    <h2><?php echo __('Plugin information and help'); ?></h2>
    <a href="https://docs.tornevall.net/x/AoA_/" target="_blank"><?php echo __("About DNSBLv5",
            "tornevall_dnsbl"); ?></a><br>
    <a href="https://tracker.tornevall.net/projects/DNSBLWP" target="_blank"><?php echo __("DNSBLWP Issue tracker",
            "tornevall_dnsbl"); ?></a><br>
    <a href="https://dnsbl.tornevall.org/removal/" target="_blank"><?php echo __("How to get delisted",
            "tornevall_dnsbl"); ?></a><br>

    <h2>DNSBL Actions</h2>

    <form method="post" action="options.php">

        <?php

        settings_fields( 'dnsblOptions-group' );
        do_settings_sections( 'dnsblOptions-group' );

        $td = array(
            'left'=>'250px',
            'right'=>'550px',
        );

        $dnsblPermissionArray = array();
        $dnsblClientData = @unserialize(get_option('tornevall_dnsbl_clientdata'));
        $permissions = array(
                'global_delist' => __('Global delisting permission (can use as delisting service for visitors)', 'tornevall_dnsbl'),
                'local_delist' => __('Local delisting permission (server can delist self)', 'tornevall_dnsbl'),
        );

        if (is_object($dnsblClientData)) {
            if (isset($dnsblClientData->API_EXTENDED_PERMISSIONS)) {
                foreach ($dnsblClientData->API_EXTENDED_PERMISSIONS as $index => $eData) {
                    $permission = $eData->permission;
                    $dnsblPermissionArray[] = $permissions[$permission];
                }
            }
        }

        ?>

        <table width="80%" cellpadding="6" cellspacing="0" style="border: 1px solid black;">
            <tr>
                <td width="<?php echo $td['left'];?>" valign="top" style="font-weight: bold;">API</td>
                <td width="<?php echo $td['right'];?>" valign="top">
                    <button type="button" onclick="runApiTest()"><?php echo __('Test API functionality', 'tornevall_dnsbl'); ?></button>
                    <div style="font-style: italic;"><?php echo __('By entering an API id and key below, this function will validate that your key is correct.', 'tornevall_dnsbl');?></div>
                    <div style="display: none;" id="apiTestResponse"></div>
                    <div style="margin-top: 5px; font-style: italic;color:#000099;" id="apiInformation"><?php echo __('Get your API key at', 'tornevall_dnsbl');?> <a href="<?php echo $authUrl; ?>"><?php echo $authUrl; ?></a> today, to extend the functions of the DNS Blacklist.</div>
                </td>
            </tr>
            <tr>
                <td width="<?php echo $td['left'];?>" valign="top" style="font-weight: bold;"><?php echo __('Application API ID/Name', 'tornevall_dnsbl');?> </td>
                <td width="<?php echo $td['right'];?>" valign="top">
                    <input type="text" size="32" id="tornevall_dnsbl_api_id" name="tornevall_dnsbl_api_id" value="<?php echo get_option('tornevall_dnsbl_api_id'); ?>">
                        <?php
                        if (is_array($dnsblPermissionArray) && count($dnsblPermissionArray)) {
                            echo '
                                <div style="color:#000099;font-weight: bold;font-size:16px;;">Discovered permissions</div>
                                <div style="color:#009900;font-weight: bold;">' . implode("<br>\n", $dnsblPermissionArray) . '</div>';
                        }
                        ?>
                </td>
            </tr>
            <tr>
                <td width="<?php echo $td['left'];?>" valign="top" style="font-weight: bold;"><?php echo __('Application API Key', 'tornevall_dnsbl');?></td>
                <td width="<?php echo $td['right'];?>" valign="top">
                    <input type="password" size="50" id="tornevall_dnsbl_api_key" name="tornevall_dnsbl_api_key" value="<?php echo get_option('tornevall_dnsbl_api_key'); ?>">
                </td>
            </tr>
            <tr>
                <td width="<?php echo $td['left'];?>" valign="top" style="font-weight: bold;"><?php echo __('Preferred API URL', 'tornevall_dnsbl');?></td>
                <td width="<?php echo $td['right'];?>" valign="top">
                    <input type="text" name="tornevall_dnsbl_preferred_api_url" value="<?php echo $prefApiUrl; ?>">
                </td>
            </tr>
        </table>

        <br>
        <div style="font-style: italic;"><?php echo __('Make sure that you really save your settings before trying to use them from this page', 'tornevall_dnsbl') ?></div>

        <?php submit_button(); ?>

    </form>


    <?php
}
