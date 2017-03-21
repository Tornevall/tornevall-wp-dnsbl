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
    <h1><?php echo __("Tornevall Networks DNSBL Options", "tornevall_dnsbl"); ?></h1>

    <h2><?php echo __("Information"); ?></h2>
    <?php echo __("Tornevall Networks is offering some ways for you, to get information about the ongoing projects. You can always go there for support, help and updates.", "tornevall_dnsbl");?><br>
    <?php echo __("Here are some links for you, that you may want to remember.");?><br>
    <br>
    <a href="https://tornevall.net/forum/project.php?12-Wordpress-DNSBL" target="_blank"><?php echo __("Project status for this plugin", "tornevall_dnsbl"); ?></a><br>
    <a href="https://tornevall.net/forum/project.php?2-DNSBL-Project" target="_blank"><?php echo __("Project status for the major DNSBL project", "tornevall_dnsbl"); ?></a><br>
    <a href="https://dnsbl.tornevall.org/" target="_blank"><?php echo __("Primary site for the DNSBL with removal instructions, usage, etc", "tornevall_dnsbl");?></a><br>
    <br>

    <?php echo __("Database version", "tornevall_dnsbl"); ?>: <?php echo get_option("tornevall_dnsbl_db_version"); ?><br>
    <?php echo __("Handled hosts the last 24 hours", "tornevall_dnsbl");?>: <?php echo $dnsblCounter; ?><br>

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
                    <b><?php echo __("Cache age", "tornevall_dnsbl"); ?></b><br>
                    <i><?php echo __("Defines for how long one blacklisted ip should checked against cache instead of resolvers.", "tornevall_dnsbl"); ?></i>
                </td>
                <td>
                    <input type="text" name="tornevall_dnsbl_cache_age" value="<?php echo esc_attr( get_option('tornevall_dnsbl_cache_age') ? get_option('tornevall_dnsbl_cache_age') : 900  ); ?>">
                </td>
            </tr>
            <tr valign="top">
                <td>
                    <b><?php echo __("Actions on", "tornevall_dnsbl"); ?></b>
                </td>
                <td>
                    <select multiple size="8" name="tornevall_dnsbl_filter_types[]">
                        <option value="checked" <?php echo (isset($types) && is_array($types) && in_array("checked", $types) ? "selected=selected": ""); ?>><?php echo __("Checked proxy", "tornevall_dnsbl"); ?></option>
                        <option value="working" <?php echo (isset($types) && is_array($types) && in_array("working", $types) ? "selected=selected": ""); ?>><?php echo __("Working proxy", "tornevall_dnsbl"); ?></option>
                        <option value="email" <?php echo (isset($types) && is_array($types) && in_array("email", $types) ? "selected=selected": ""); ?>><?php echo __("Mailspam host", "tornevall_dnsbl"); ?></option>
                        <option value="timeout" <?php echo (isset($types) && is_array($types) && in_array("timeout", $types) ? "selected=selected": ""); ?>><?php echo __("Proxies that has been tested but timed out", "tornevall_dnsbl"); ?></option>
                        <option value="error" <?php echo (isset($types) && is_array($types) && in_array("error", $types) ? "selected=selected": ""); ?>><?php echo __("Proxies that has been tested but probably not works", "tornevall_dnsbl"); ?></option>
                        <option value="elite" <?php echo (isset($types) && is_array($types) && in_array("elite", $types) ? "selected=selected": ""); ?>><?php echo __("Anonymous proxies / TOR Exit nodes", "tornevall_dnsbl"); ?></option>
                        <option value="abuse" <?php echo (isset($types) && is_array($types) && in_array("abuse", $types) ? "selected=selected": ""); ?>><?php echo __("Ip-adress that has been marked as abusive host (spam, etc)", "tornevall_dnsbl"); ?></option>
                        <option value="anonymous" <?php echo (isset($types) && is_array($types) && in_array("anonymous", $types) ? "selected=selected": ""); ?>><?php echo __("Anonymous hosts (where ip has another kinds of anonymous states)", "tornevall_dnsbl"); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <input type="checkbox" <?php echo (get_option("tornevall_dnsbl_nocomment") ? "checked": ""); ?> value="1" name="tornevall_dnsbl_nocomment"> <?php echo __("Hide comment section on detection", "tornevall_dnsbl"); ?><br>
                    <input type="checkbox" <?php echo (get_option("tornevall_dnsbl_blockfull") ? "checked": ""); ?> value="1" name="tornevall_dnsbl_blockfull"> <?php echo __("Block access to whole page on detection (Redirecting to DNSBL-page)", "tornevall_dnsbl"); ?><br>
                    <input type="checkbox" <?php echo (get_option("tornevall_dnsbl_update_timestamp") ? "checked": ""); ?> value="1" name="tornevall_dnsbl_update_timestamp"> <?php echo __("Update timestamps on cached entries", "tornevall_dnsbl"); ?> (<a href="https://tornevall.net/forum/issue.php?69-Update-timestamps-instead-of-expire" target="_blank"><?php echo __("delayed expires", "tornevall_dnsbl"); ?></a>)<br>
                </td>
            </tr>

        </table>
        <?php submit_button(); ?>
    </form>

   <?php
}

