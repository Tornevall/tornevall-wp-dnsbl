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

    $authUrl    = "https://auth.tornevall.net";
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

    <form method="post" action="options.php">

        <?php

        settings_fields('dnsblOptions-group');
        do_settings_sections('dnsblOptions-group');

        $td = array(
            'left'  => '250px',
            'right' => '550px',
        );

        $dnsblPermissionArray = array();
        $dnsblClientData      = @unserialize(get_option('tornevall_dnsbl_clientdata'));
        $permissions          = array(
            'global_delist'   => __('Global delisting permission (can use as delisting service for visitors)',
                'tornevall_dnsbl'),
            'local_delist'    => __('Local delisting permission (server can delist self)', 'tornevall_dnsbl'),
            'dnsbl_update'    => __('Standard DNSBL ability to update data in the DNSBL (dnsbl.tornevall.org and bl.fraudbl.org)',
                'tornevall_dnsbl'),
            'fraudbl_update'  => __('Extended ability to handle fraudbl-commerce (this is not the regular bl.fraudbl.org resolver)',
                'tornevall_dnsbl'),
            'can_purge'       => __('Special ability to purge hosts instead of marking them deleted in the database',
                'tornevall_dnsbl'),
            'allow_cidr'      => __('The usage of CIDR-blocks are normally not permitted by the DNSBL API, in more functions than listing them. This permission also opens up for usage in DELETE/UPDATE cases (for CIDR-block removals this would help a lot). Adding data with CIDR and different flags is however still a problem.',
                'tornevall_dnsbl'),
            'overwrite_flags' => __('When sending new or updated data to DNSBL, clients can only add more flags to the host. This feature makes it possible to overwrite old flags',
                'tornevall_dnsbl'),
        );

        if (is_object($dnsblClientData)) {
            if (isset($dnsblClientData->API_EXTENDED_PERMISSIONS)) {
                foreach ($dnsblClientData->API_EXTENDED_PERMISSIONS as $index => $eData) {
                    $permission             = $eData->permission;
                    $dnsblPermissionArray[] = $permissions[$permission];
                }
            }
        }

        $flagListSelector = array();
        $currentFlags     = get_option('tornevall_dnsbl_current_flags');
        $savedFlags       = get_option("tornevall_dnsbl_filter_types");
        if ( ! is_array($savedFlags)) {
            // Configure best practice initially
            $savedFlags = array(
                'IP_CONFIRMED',
                'IP_SECOND_EXIT',
                'IP_ABUSE_NO_SMTP',
                'IP_ANONYMOUS'
            );
            update_option('tornevall_dnsbl_filter_types', $savedFlags);
        }

        if (empty($currentFlags) || ! is_array($currentFlags)) {
            // Flag list updated 180609
            $currentFlags = unserialize('a:9:{s:31:"FREE_SLOT_1_PREVIOUSLY_REPORTED";s:1:"1";s:12:"IP_CONFIRMED";s:1:"2";s:11:"IP_PHISHING";s:1:"4";s:35:"FREE_SLOT_8_PREVIOUSLY_PROXYTIMEOUT";s:1:"8";s:18:"IP_MAILSERVER_SPAM";s:2:"16";s:14:"IP_SECOND_EXIT";s:2:"32";s:16:"IP_ABUSE_NO_SMTP";s:2:"64";s:12:"IP_ANONYMOUS";s:3:"128";s:7:"BIT_256";s:3:"256";}');
        }
        foreach ($currentFlags as $flag => $bitValue) {
            $flagListSelector[] = '<option value="' . $flag . '" ' . (in_array($flag,
                    $savedFlags) ? 'selected=selected' : '') . '>' . htmlentities($flag) . ' [' . $bitValue . ']</option>';
        }

        ?>

        <div style="border-top:1px dashed gray;margin-top:10px;margin-bottom: 5px;">

            <div style="font-weight: bold; font-size: 20px !important;margin-top:5px;margin-bottom:5px;"><?php echo __('Plugin behaviour',
                    'tornevall_dnsbl'); ?></div>

            <table width="80%" cellpadding="6" cellspacing="0" style="border: 1px solid black;">
                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;"><?php echo __('Trigger on', 'tornevall_dnsbl') . " ..."; ?>
                    </td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <select multiple size="8" name="tornevall_dnsbl_filter_types[]">
                            <?php echo implode("\n", $flagListSelector); ?>
                        </select><br>
                        <a href="https://docs.tornevall.net/x/AoA_#DNSBLv5:AbouttheDNSBlacklistProjectandusage-RBLBitmaskingData"><?php echo __('See full description on what the flags mean, here',
                                'tornevall_dnsbl'); ?></a> <br>

                        <?php echo __('To get a updated list of flags, you should consider using the API',
                            'tornevall_dnsbl'); ?>
                    </td>
                </tr>
                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;"><?php echo __('Protect this site by ...',
                                'tornevall_dnsbl') . " ..."; ?>
                    </td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <input type="checkbox" <?php echo(get_option("tornevall_dnsbl_nocomment") ? "checked" : ""); ?>
                               value="1"
                               name="tornevall_dnsbl_nocomment"> <?php echo "... " . __("hiding the comment section when a potential spammer arrives.",
                                "tornevall_dnsbl"); ?>
                        <br>
                        <input type="checkbox" <?php echo(get_option("tornevall_dnsbl_blockfull") ? "checked" : ""); ?>
                               value="1"
                               name="tornevall_dnsbl_blockfull"> <?php echo "... " . __("immediately block access to the whole page by redirecting (does not affect logged in admins)",
                                "tornevall_dnsbl"); ?>
                        <br>
                    </td>
                </tr>
            </table>

            <div style="font-weight: bold;font-size: 20px !important;margin-top:5px;margin-bottom:5px;"
                 onclick="$('#dnsblApiView').show()"><?php echo __('API',
                    'tornevall_dnsbl'); ?></div>
            <?php echo __('The plugin is fully functional even if the API is not in use'); ?>

            <table width="80%" cellpadding="6" cellspacing="0" style="border: 1px solid black;" id="dnsblApiView">
                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top" style="font-weight: bold;">API</td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <button type="button" onclick="runApiTest('test')"><?php echo __('Test API functionality',
                                'tornevall_dnsbl'); ?></button>
                        <button type="button"
                                onclick="runApiTest('flags')"><?php echo __('Update above flag list (no credentials required)',
                                'tornevall_dnsbl'); ?></button>
                        <div style="font-style: italic;"><?php echo __('By entering an API id and key below, this function will validate that your key is correct.',
                                'tornevall_dnsbl'); ?></div>
                        <div style="display: none;" id="apiTestResponse"></div>
                        <div style="margin-top: 5px; font-style: italic;color:#000099;"
                             id="apiInformation"><?php echo __('Get your API key at', 'tornevall_dnsbl'); ?> <a
                                    href="<?php echo $authUrl; ?>"><?php echo $authUrl; ?></a> today, to extend the
                            functions of the DNS Blacklist.
                        </div>
                    </td>
                </tr>
                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;"><?php echo __('Application API ID/Name', 'tornevall_dnsbl'); ?> </td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <input type="text" size="32" id="tornevall_dnsbl_api_id" name="tornevall_dnsbl_api_id"
                               value="<?php echo get_option('tornevall_dnsbl_api_id'); ?>">
                        <?php
                        if (is_array($dnsblPermissionArray) && count($dnsblPermissionArray)) {
                            echo '
                                <div style="color:#000099;font-weight: bold;font-size:16px;">Discovered permissions</div>
                                <div style="color:#009900;font-weight: bold;">' . implode("<br>\n",
                                    $dnsblPermissionArray) . '</div>';
                        }
                        echo '
                                <div style="color:#990033;font-weight: bold;font-size:11px;cursor: pointer;margin-top:6px;" onclick="jQuery(\'#avPermissionList\').toggle(\'medium\')">' . __('Click here to view available permissions',
                                'tornevall_dnsbl') . '</div>
                                <div id="avPermissionList" style="display:none;"><ul>';
                        foreach ($permissions as $flag => $description) {
                            echo '<b>' . $flag . '</b><br><i>' . htmlentities($description) . '</i><br>';
                        }
                        echo '</ul></div>
                        ';

                        ?>
                    </td>
                </tr>
                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;"><?php echo __('Application API Key', 'tornevall_dnsbl'); ?></td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <input type="password" size="50" id="tornevall_dnsbl_api_key" name="tornevall_dnsbl_api_key"
                               value="<?php echo get_option('tornevall_dnsbl_api_key'); ?>">
                    </td>
                </tr>
                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;"><?php echo __('Preferred API URL', 'tornevall_dnsbl'); ?></td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <input type="text" name="tornevall_dnsbl_preferred_api_url" value="<?php echo $prefApiUrl; ?>">
                    </td>
                </tr>
            </table>

            <br>
            <div style="font-style: italic;"><?php echo __('Make sure that you really save your settings before trying to use them from this page',
                    'tornevall_dnsbl') ?></div>

        </div>

        <?php submit_button(); ?>

    </form>


    <?php
}
