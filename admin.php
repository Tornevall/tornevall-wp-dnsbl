<?php

$pagelist             = get_pages();
$currentDelistingPage = get_option('tornevall_dnsbl_delisting_page');
$delistPageOption     = array();
if (is_array($pagelist)) {
    $delistPageOption[] = '<option value="">None</option>';
    foreach ($pagelist as $pageObject) {
        $selectedPage = '';
        if ($pageObject->ID == $currentDelistingPage) {
            $selectedPage = 'selected=selected';
        }
        $delistPageOption[] = '<option value="' . $pageObject->ID . '" ' . $selectedPage . '>' . $pageObject->post_title . '</option>';
    }
}


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
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_delisting_page');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_update_timestamp');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_form_noajax');

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
    global $wpdb, $tornevallDnsblFlags, $dnsblPermissionArray, $permissions, $delistPageOption;

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

                <?php

                if (in_array('global_delist', $tornevallDnsblFlags)) {
                    ?>

                    <tr style="border-top:1px dotted gray;">
                        <td width="<?php echo $td['left']; ?>" valign="top" style="font-weight: bold;border-top:1px dotted gray;">
                            <?php echo __('Delisting page', 'tornevall_dnsbl'); ?>
                        </td>
                        <td width="<?php echo $td['right']; ?>" valign="top" style="border-top:1px dotted gray;">
                            <select name="tornevall_dnsbl_delisting_page"><?php echo(is_array($delistPageOption) ? implode("\n",
                                    $delistPageOption) : ''); ?></select> <br>
                            <i>
                                <?php
                                echo __('The API key you are using indicates that this plugin supports global delistings. This means that your site can be used as a delisting service.',
                                        'tornevall_dnsbl') . " ";
                                echo __('This option allows you to set up a page where the search-and-delist form should be shown.',
                                        'tornevall_dnsbl') . " ";
                                echo __('If you can\'t find any comfortable match, you can create a new under pages editor. You can use the shortcode [dnsbl_removal_form] if you want to customize the page.',
                                        'tornevall_dnsbl') . " ";
                                echo __('If no shortcode is found, the form will be appended to the page.',
                                        'tornevall_dnsbl') . " ";
                                echo __('There is a plain view accessible, in case the standard AJAX form does not work. Use ?plain in the URL to reach it',
                                        'tornevall_dnsbl') . " ";
                                ?>
                            </i>
                        </td>
                    </tr>

                    <tr>
                        <td width="<?php echo $td['left']; ?>" valign="top" style="font-weight: bold;">
                            <?php echo __('Show delisting form in non-responsive mode', 'tornevall_dnsbl'); ?>
                        </td>
                        <td width="<?php echo $td['right']; ?>" valign="top">
                            <input type="checkbox" <?php echo(get_option("tornevall_dnsbl_form_noajax") ? "checked" : ""); ?>
                                   value="1"
                                   name="tornevall_dnsbl_form_noajax"> <?php echo __("Check this box to use prioritize the non-responsive form over the standard delisting form",
                                "tornevall_dnsbl"); ?>

                        </td>
                    </tr>

                    </tr>


                    <?php
                }

                ?>

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
