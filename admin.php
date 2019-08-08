<?php

function tornevall_wp_dnsbl_admin()
{
    add_action('admin_init', 'register_dnsbl_settings');
    add_menu_page(
        'Tornevall DNSBL Options',
        __(
            'Tornevall DNSBL',
            'tornevall-networks-dnsbl-implementation'
        ),
        'manage_options',
        'tornevallDnsblMenu',
        'tornevall_dnsbl_options'
    );
}

function register_dnsbl_settings()
{
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_cache_age');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_filter_types');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_nocomment');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_blockfull');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_delisting_page');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_update_timestamp');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_resolver_hosts');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_form_noajax');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_blocked_redirecturl');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_prefer_api');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_getlisted_resolver');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_comments_disabled_style');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_delistingpage_comments_disabled');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_wpcf7');

    register_setting('dnsblOptions-group', 'tornevall_dnsbl_preferred_api_url');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_api_id');
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_api_key');

    register_setting('dnsblOptions-group', 'tornevall_dnsbl_fraudbl_resursbank_woocommerce');
}

function tornevall_dnsbl_options()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    global $tornevallDnsblFlags, $dnsblPermissionArray, $permissions;

    $pagelist = get_pages();
    $currentDelistingPage = get_option('tornevall_dnsbl_delisting_page');
    $delistPageOption = [];
    if (is_array($pagelist)) {
        $delistPageOption[] = '<option value="">None</option>';
        foreach ($pagelist as $pageObject) {
            $selectedPage = '';
            if ($pageObject->ID == $currentDelistingPage) {
                $selectedPage = 'selected=selected';
            }
            $delistPageOption[] = '<option value="' .
                $pageObject->ID . '" ' . $selectedPage . '>' . $pageObject->post_title . '</option>';
        }
    }

    $cacheAgeTest = get_option('tornevall_dnsbl_cache_age');
    if (empty($cacheAgeTest)) {
        update_option('tornevall_dnsbl_cache_age', 900);
    }

    $redirectUrl = get_option('tornevall_dnsbl_blocked_redirecturl');
    if (empty($redirectUrl)) {
        $redirectUrl = 'https://dnsbl.tornevall.org/removal?redirected';
        update_option('tornevall_dnsbl_blocked_redirecturl', $redirectUrl);
    }

    $authUrl = "https://auth.tornevall.net";
    $prefApiUrl = get_option('tornevall_dnsbl_preferred_api_url');
    if (empty($prefApiUrl)) {
        $prefApiUrl = "https://api.tornevall.net/3.0/";
    }

    ?>

    <h1><?php echo __('DNS Blacklist Configurator', 'tornevall-networks-dnsbl-implementation'); ?></h1>

    <h2><?php echo __('Plugin information and help'); ?></h2>
    <a href="https://docs.tornevall.net/x/AoA_/" target="_blank"><?php
        echo
        __("About DNSBLv5", 'tornevall-networks-dnsbl-implementation'); ?></a><br>
    <a href="https://tracker.tornevall.net/projects/DNSBLWP" target="_blank"><?php
        echo
        __("DNSBLWP Issue tracker", 'tornevall-networks-dnsbl-implementation'); ?></a><br>
    <a href="https://dnsbl.tornevall.org/removal/" target="_blank"><?php
        echo
        __("How to get delisted", 'tornevall-networks-dnsbl-implementation'); ?></a><br>

    <form method="post" action="options.php">
        <?php
        settings_fields('dnsblOptions-group');
        do_settings_sections('dnsblOptions-group');

        $td = [
            'left' => '250px',
            'right' => '550px',
        ];

        $flagListSelector = [];
        $currentFlags = get_option('tornevall_dnsbl_current_flags');
        $savedFlags = get_option("tornevall_dnsbl_filter_types");
        if (!is_array($savedFlags)) {
            // Configure best practice initially
            $savedFlags = [
                'IP_CONFIRMED',
                'IP_SECOND_EXIT',
                'IP_ABUSE_NO_SMTP',
                'IP_ANONYMOUS',
            ];
            update_option('tornevall_dnsbl_filter_types', $savedFlags);
        }

        $hasProperResolvers = false;
        $resolverNames = explode(",", get_option('tornevall_dnsbl_resolver_hosts'));
        if (is_array($resolverNames) && count($resolverNames)) {
            foreach ($resolverNames as $rName) {
                if (!empty($rName)) {
                    $hasProperResolvers = true;
                }
            }
        }
        if (!is_array($resolverNames) ||
            (is_array($resolverNames) &&
                !count($resolverNames)) ||
            !in_array(
                'dnsbl.tornevall.org',
                $resolverNames
            ) || !$hasProperResolvers
        ) {
            $resolverNames = [
                'dnsbl.tornevall.org',
                'bl.fraudbl.org',
            ];
            update_option('tornevall_dnsbl_resolver_hosts', implode(",", array_map("trim", $resolverNames)));
        }

        if (empty($currentFlags) || !is_array($currentFlags)) {
            // Flag list updated 180609
            $currentFlags = unserialize(
                'a:9:{s:31:"FREE_SLOT_1_PREVIOUSLY_REPORTED";s:1:"1";s:12:"IP_CONFIRMED";s:1:"2";s:11:"IP_PHISHING";s:1:"4";s:35:"FREE_SLOT_8_PREVIOUSLY_PROXYTIMEOUT";s:1:"8";s:18:"IP_MAILSERVER_SPAM";s:2:"16";s:14:"IP_SECOND_EXIT";s:2:"32";s:16:"IP_ABUSE_NO_SMTP";s:2:"64";s:12:"IP_ANONYMOUS";s:3:"128";s:7:"BIT_256";s:3:"256";}'
            );
        }
        foreach ($currentFlags as $flag => $bitValue) {
            $flagListSelector[] = '<option value="' . $flag . '" ' . (
                in_array($flag, $savedFlags) ? 'selected=selected' : ''
                ) . '>' . htmlentities($flag) . ' [' . $bitValue . ']</option>';
        }
        $commentsStyle = get_option('tornevall_dnsbl_comments_disabled_style');
        if (empty($commentsStyle)) {
            $commentsStyle = 'font-weight: bold;';
            update_option('tornevall_dnsbl_comments_disabled_style', $commentsStyle);
        }

        $cacheAge = esc_attr(get_option('tornevall_dnsbl_cache_age') ? get_option('tornevall_dnsbl_cache_age') : 900);

        $apiKeyInformation =
            __(
                'The API key you are using indicates that this plugin supports global delistings. This means that your site can be used as a delisting service.',
                'tornevall-networks-dnsbl-implementation'
            ) . ' ' .
            __(
                'This option allows you to set up a page where the search-and-delist form should be shown.',
                'tornevall-networks-dnsbl-implementation'
            ) . " " .
            __(
                'If you can\'t find any comfortable match, you can create a new under pages editor. You can use the shortcode [dnsbl_removal_form] if you want to customize the page.',
                'tornevall-networks-dnsbl-implementation'
            ) . ' ' .
            __(
                'If no shortcode is found, the form will be appended to the page.',
                'tornevall-networks-dnsbl-implementation'
            ) . ' ' .
            __(
                'There is a plain view accessible, in case the standard AJAX form does not work. Use ?plain in the URL to reach it',
                'tornevall-networks-dnsbl-implementation'
            ) . ' ';


        ?>

        <div style="border-top:1px dashed gray;margin-top:10px;margin-bottom: 5px;">

            <div style="font-weight: bold; font-size: 20px !important;margin-top:5px;margin-bottom:5px;"><?php echo
                __('Plugin behaviour', 'tornevall-networks-dnsbl-implementation'); ?>
            </div>

            <table width="80%" cellpadding="6" cellspacing="0" style="border: 1px solid black;">
                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;"><?php echo
                            __('Trigger on', 'tornevall-networks-dnsbl-implementation') . " ...";
                        ?>
                    </td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <label>
                            <select multiple size="8" name="tornevall_dnsbl_filter_types[]">
                                <?php echo implode("\n", $flagListSelector); ?>
                            </select>
                        </label><br>
                        <a href="https://docs.tornevall.net/x/AoA_#DNSBLv5:Aboutandusage-RBLBitmaskingData">
                            <?php echo __(
                                'See full description on what the flags mean, here',
                                'tornevall-networks-dnsbl-implementation'
                            ); ?></a><br>
                        <?php echo
                        __(
                            'To get a updated list of flags, you should consider using the API',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?>
                    </td>
                </tr>

                <tr>
                    <td>
                        <b><?php echo __("Cache age", 'tornevall-networks-dnsbl-implementation'); ?></b><br>
                        <i><?php echo
                            __(
                                'Time to live in local caches (seconds) - minimum is 900 seconds',
                                'tornevall-networks-dnsbl-implementation'
                            );
                            ?></i>
                    </td>
                    <td>
                        <label>
                            <input type="text" name="tornevall_dnsbl_cache_age"
                                   value="<?php echo $cacheAge; ?>">
                        </label>
                    </td>
                </tr>


                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;">
                        <?php
                        echo __(
                            'Preferred resolver hosts (comma separated).',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?>
                    </td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <label>
                            <input type="text" size="32" value="<?php echo implode(",", $resolverNames); ?>"
                                   name="tornevall_dnsbl_resolver_hosts">
                        </label>
                    </td>
                </tr>

                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;">
                        <?php
                        echo __(
                            'Protective options',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?>
                    </td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <label>
                            <input type="checkbox" <?php
                            echo(get_option("tornevall_dnsbl_nocomment") ? "checked" : ""); ?> value="1"
                                   name="tornevall_dnsbl_nocomment">
                        </label>
                        <?php echo __(
                            'Hiding the comment section when a potential spammer arrives.',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?>
                        <br>

                        <label>
                            <input type="checkbox" <?php echo(get_option("tornevall_dnsbl_blockfull") ? "checked" : ""); ?>
                                   value="1"
                                   name="tornevall_dnsbl_blockfull">
                        </label>
                        <?php echo __(
                            'Immediately block access to the whole page by redirecting (does not affect logged in admins)',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?>
                        <br>

                        <label>
                            <input type="checkbox" <?php echo(get_option("tornevall_dnsbl_wpcf7") ? "checked" : ""); ?>
                                   value="1"
                                   name="tornevall_dnsbl_wpcf7">
                        </label>
                        <?php echo __(
                            'Turn on support for WPCF7 (Contact-Form 7) and block submitting on hits.',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?>
                        <br>

                        <br>
                        <i><b><?php
                                echo __(
                                    'Redirect to this URL when blocked',
                                    'tornevall-networks-dnsbl-implementation'
                                ) ?>:</b></i><br>
                        <label>
                            <input type="text" value="<?php echo $redirectUrl ?>"
                                   name="tornevall_dnsbl_blocked_redirecturl" size="32">
                        </label>
                    </td>
                </tr>

                <?php

                if (in_array('global_delist', $tornevallDnsblFlags)) {
                    ?>

                    <tr style="border-top:1px dotted gray;">
                        <td width="<?php echo $td['left']; ?>" valign="top"
                            style="font-weight: bold;border-top:1px dotted gray;">
                            <?php echo __('Delisting page', 'tornevall-networks-dnsbl-implementation'); ?>
                        </td>
                        <td width="<?php echo $td['right']; ?>" valign="top"
                            style="border-top:1px dotted gray;">
                            <label>
                                <select name="tornevall_dnsbl_delisting_page"><?php
                                    echo
                                    is_array($delistPageOption) ? implode("\n", $delistPageOption) : ''; ?></select>

                            </label> <br>
                            <i>
                                <?php

                                echo $apiKeyInformation;

                                ?>
                            </i>
                        </td>
                    </tr>

                    <tr>
                        <td width="<?php echo $td['left']; ?>" valign="top" style="font-weight: bold;">
                            <?php echo __(
                                'Disable comments on delisting page',
                                'tornevall-networks-dnsbl-implementation'
                            ); ?>
                        </td>
                        <td width="<?php echo $td['right']; ?>" valign="top">
                            <label>
                                <input type="checkbox" <?php
                                echo get_option("tornevall_dnsbl_delistingpage_comments_disabled") ? "checked" : ""; ?>
                                       value="1"
                                       name="tornevall_dnsbl_delistingpage_comments_disabled">
                            </label>
                            <?php echo __(
                                "If you are experiencing a lot of comments that ask you to delist people, you can turn off comments by using this setting",
                                'tornevall-networks-dnsbl-implementation'
                            ); ?>

                        </td>
                    </tr>

                    <tr>
                        <td width="<?php echo $td['left']; ?>" valign="top" style="font-weight: bold;">
                            <?php echo __(
                                'Show delisting form in non-responsive mode',
                                'tornevall-networks-dnsbl-implementation'
                            ); ?>
                        </td>
                        <td width="<?php echo $td['right']; ?>" valign="top">
                            <label>
                                <input type="checkbox" <?php echo(get_option("tornevall_dnsbl_form_noajax") ? "checked" : ""); ?>
                                       value="1"
                                       name="tornevall_dnsbl_form_noajax">
                            </label>
                            <?php
                            echo __(
                                "Check this box to use prioritize the non-responsive form over the standard delisting form",
                                'tornevall-networks-dnsbl-implementation'
                            ); ?>

                        </td>
                    </tr>

                    <?php
                }

                ?>

            </table>

            <div style="font-weight: bold;font-size: 20px !important;margin-top:5px;margin-bottom:5px;">
                <?php echo __(
                    'API',
                    'tornevall-networks-dnsbl-implementation'
                ); ?></div>
            <?php echo
            __(
                'The plugin is fully functional even if the API is not in use',
                'tornevall-networks-dnsbl-implementation'
            ); ?>

            <table width="80%" cellpadding="6" cellspacing="0" style="border: 1px solid black;" id="dnsblApiView">
                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top" style="font-weight: bold;">API</td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <button type="button" onclick="runApiTest('test')">
                            <?php echo __(
                                'Test API functionality',
                                'tornevall-networks-dnsbl-implementation'
                            ); ?></button>
                        <button type="button"
                                onclick="runApiTest('flags')">
                            <?php echo __(
                                'Update above flag list (no credentials required)',
                                'tornevall-networks-dnsbl-implementation'
                            ); ?></button>
                        <div style="font-style: italic;">
                            <?php echo __(
                                'By entering an API id and key below, this function will validate that your key is correct.',
                                'tornevall-networks-dnsbl-implementation'
                            ); ?></div>
                        <div style="display: none;" id="apiTestResponse"></div>
                        <div style="margin-top: 5px; font-style: italic;color:#000099;"
                             id="apiInformation">
                            <?php echo __(
                                'Get your API key at',
                                'tornevall-networks-dnsbl-implementation'
                            ); ?> <a
                                    href="<?php echo $authUrl; ?>"><?php echo $authUrl; ?></a> today, to extend the
                            functions of the DNS Blacklist.
                        </div>
                    </td>
                </tr>
                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;">
                        <?php echo __(
                            'Application API ID/Name',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?>
                    </td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <label for="tornevall_dnsbl_api_id"></label>
                        <input type="text" size="32"
                               id="tornevall_dnsbl_api_id"
                               name="tornevall_dnsbl_api_id"
                               value="<?php echo get_option('tornevall_dnsbl_api_id'); ?>">
                        <?php
                        if (is_array($dnsblPermissionArray) && count($dnsblPermissionArray)) {
                            echo '
                                <div style="color:#000099;font-weight: bold;font-size:16px;">Discovered permissions</div>
                                <div style="color:#009900;font-weight: bold;">' .
                                implode(
                                    "<br>\n",
                                    $dnsblPermissionArray
                                ) . '</div>';
                        }
                        echo '<div style="color:#990033;font-weight: bold;font-size:11px;cursor: pointer;margin-top:6px;" onclick="jQuery(\'#avPermissionList\').toggle(\'medium\')">' .
                            __(
                                'Click here to view available permissions',
                                'tornevall-networks-dnsbl-implementation'
                            ) . '</div>
                                <div id="avPermissionList" style="display:none;"><ul>';
                        foreach ($permissions as $flag => $description) {
                            echo '<strong>' . $flag . '</strong><br><em>' . htmlentities($description) . '</em><br>';
                        }
                        echo '</ul></div>
                        ';

                        ?>
                    </td>
                </tr>
                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;">
                        <?php echo __(
                            'Application API Key',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?></td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <label for="tornevall_dnsbl_api_key"></label><input type="password" size="50"
                                                                            id="tornevall_dnsbl_api_key"
                                                                            name="tornevall_dnsbl_api_key"
                                                                            value="<?php echo get_option('tornevall_dnsbl_api_key'); ?>">
                    </td>
                </tr>
                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;">
                        <?php
                        echo __(
                            'Preferred API URL',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?></td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <label>
                            <input type="text" name="tornevall_dnsbl_preferred_api_url"
                                   value="<?php echo $prefApiUrl; ?>">
                        </label>
                    </td>
                </tr>

                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;">
                        <?php
                        echo __(
                            'API MODE',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?>
                    </td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <label>
                            <input type="checkbox" <?php
                            echo(get_option("tornevall_dnsbl_prefer_api") ? "checked" : "");
                            ?> value="1"
                                   name="tornevall_dnsbl_prefer_api">
                        </label>
                        <?php echo
                        __(
                            'Always use API instead of DNS lookups when possible (limited requests)',
                            'tornevall-networks-dnsbl-implementation'
                        );
                        ?><br>
                        <i><?php echo
                            __(
                                'This mode is incompatible with the plain form mode',
                                'tornevall-networks-dnsbl-implementation'
                            ); ?></i>
                    </td>
                </tr>

                <tr>
                    <td width="<?php echo $td['left']; ?>" valign="top"
                        style="font-weight: bold;">
                        <?php echo __(
                            'Request remotely resolved hosts',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?>
                    </td>
                    <td width="<?php echo $td['right']; ?>" valign="top">
                        <label>
                            <input type="checkbox" <?php
                            echo(get_option("tornevall_dnsbl_getlisted_resolver") ? "checked" : ""); ?>
                                   value="1"
                                   name="tornevall_dnsbl_getlisted_resolver">
                        </label>
                        <?php
                        echo __(
                            'Include (if any) the ip address resolved hostname in the request',
                            'tornevall-networks-dnsbl-implementation'
                        ); ?>
                    </td>
                </tr>

            </table>

            <br>
            <div style="font-style: italic;">
                <?php echo
                __(
                    'Make sure that you really save your settings before trying to use them from this page',
                    'tornevall-networks-dnsbl-implementation'
                );
                ?></div>

        </div>
        <?php submit_button(); ?>
    </form>
    <?php
}
