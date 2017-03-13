<?php

function register_dnsbl_settings()
{
    register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_cache_age' );
    register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_filter_types' );
    register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_nocomment' );
    register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_blockfull' );
    register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_update_timestamp' );
    register_setting( 'dnsblOptions-group', 'tornevall_dnsbl_db_version' );
}

function tornevall_dnsbl_options()
{
    if ( !current_user_can( 'manage_options' ) )  {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    global $wpdb;

    $blockHistoryTime = strftime("%Y-%m-%d %H:%M:%S", time() - 86400);

    $dnsblCounter = 0;
    $statsInfo = $wpdb->get_results("SELECT COUNT(*) AS count FROM ".($wpdb->prefix . "dnsblstats")." WHERE resolvetime > '".$blockHistoryTime."'");
    if (isset($statsInfo[0]->count)) {$dnsblCounter = $statsInfo[0]->count;}

    ?>
    <h1>Tornevall Networks DNSBL Options</h1>

    <h2>Information</h2>
    Tornevall Networks is offering some ways to get information about the ongoing projects. You can always go there for support, help and updates.<br>
    Here are some links for you, that you may want to remember.<br>
    <br>
    <a href="https://tornevall.net/forum/project.php?12-Wordpress-DNSBL" target="_blank">Project status for this plugin</a><br>
    <a href="https://tornevall.net/forum/project.php?2-DNSBL-Project" target="_blank">Project status for the major DNSBL project</a><br>
    <a href="https://dnsbl.tornevall.org/" target="_blank">Primary site for the DNSBL with removal instructions, usage, etc</a><br>
    <br>

    Database version: <?php echo get_option("tornevall_dnsbl_db_version"); ?><br>
    Handled hosts the last 24 hours: <?php echo $dnsblCounter; ?><br>

    <h2>DNSBL Actions</h2>
    <form method="post" action="options.php">
        <?php
            settings_fields( 'dnsblOptions-group' );
            do_settings_sections( 'dnsblOptions-group' );

        $types = get_option("tornevall_dnsbl_filter_types");
        ?>
        <table width="800" cellpadding="6" cellspacing="0" style="border: 1px solid black;">
            <tr>
                <td>
                    <b>Cache age</b><br>
                    <i>Defines for how long one blacklisted ip should checked against cache instead of resolvers.</i>
                </td>
                <td>
                    <input type="text" name="tornevall_dnsbl_cache_age" value="<?php echo esc_attr( get_option('tornevall_dnsbl_cache_age') ? get_option('tornevall_dnsbl_cache_age') : 900  ); ?>">
                </td>
            </tr>
            <tr valign="top">
                <td>
                    <b>React on</b>
                </td>
                <td>
                    <select multiple size="8" name="tornevall_dnsbl_filter_types[]">
                        <option value="checked" <?php echo (in_array("checked", $types) ? "selected=selected": ""); ?>>Checked proxy</option>
                        <option value="working" <?php echo (in_array("working", $types) ? "selected=selected": ""); ?>>Working proxy</option>
                        <option value="email" <?php echo (in_array("email", $types) ? "selected=selected": ""); ?>>Mailspam host</option>
                        <option value="timeout" <?php echo (in_array("timeout", $types) ? "selected=selected": ""); ?>>Proxies that has been tested but timed out</option>
                        <option value="error" <?php echo (in_array("error", $types) ? "selected=selected": ""); ?>>Proxies that has been tested but probably not works</option>
                        <option value="elite" <?php echo (in_array("elite", $types) ? "selected=selected": ""); ?>>Anonymous proxies / TOR Exit nodes</option>
                        <option value="abuse" <?php echo (in_array("abuse", $types) ? "selected=selected": ""); ?>>Ip-adress that has been marked as abusive host (spam, etc)</option>
                        <option value="anonymous" <?php echo (in_array("anonymous", $types) ? "selected=selected": ""); ?>>Anonymous hosts (where ip has another kinds of anonymous states)</option>
                    </select>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <input type="checkbox" <?php echo (get_option("tornevall_dnsbl_nocomment") ? "checked": ""); ?> value="1" name="tornevall_dnsbl_nocomment"> Hide comment section on detection<br>
                    <input type="checkbox" <?php echo (get_option("tornevall_dnsbl_blockfull") ? "checked": ""); ?> value="1" name="tornevall_dnsbl_blockfull"> Block access to whole page on detection (Redirecting to DNSBL-page)<br>
                    <input type="checkbox" <?php echo (get_option("tornevall_dnsbl_update_timestamp") ? "checked": ""); ?> value="1" name="tornevall_dnsbl_update_timestamp"> Update timestamps on cached entries (<a href="https://tornevall.net/forum/issue.php?69-Update-timestamps-instead-of-expire" target="_blank">delayed expires</a>)<br>
                </td>
            </tr>

        </table>
        <?php submit_button(); ?>
    </form>

   <?php
}

