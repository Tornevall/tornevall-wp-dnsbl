<?php

/**
 * Plugin activation hook
 *
 * Activates the plugin and updates the database with new tables.
 */
function tornevall_wp_dnsbl_activate_db()
{
    global $wpdb;

    $dnsbl_db_tables = array(
        'dnsblcache' => '
            `ipAddr` VARCHAR(50) NOT NULL,
            `lastResponse` INT NOT NULL DEFAULT 0,
            `lastResolve` INT NULL DEFAULT 0,
            PRIMARY KEY (`ipAddr`, `lastResponse`)
            ',
        'dnsblstats' => '
            `ipAddr` VARCHAR(50) NULL,
            `resolveTime` INT NULL DEFAULT 0,
            `wasBlocked` INT NULL DEFAULT 0,
            INDEX `denyIndex` (`ipAddr` ASC, `wasBlocked` ASC)
        ',
    );

    foreach ($dnsbl_db_tables as $tableName => $tableData) {
        $dbDeltaQuery[] = 'CREATE TABLE ' . $wpdb->prefix . $tableName . ' (' . $tableData . ') ' . $wpdb->get_charset_collate();
    }
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($dbDeltaQuery);
}

/**
 * Plugin deactivation hook
 *
 * Each time the plugin gets deactivated, we'll clear up the tables. As the database tables acts like caches, this is
 * normally preferred as we also get fresh tables during next upgrade, if there are upgrades that affects the database.
 * However, the statistics database is not dropped at this moment.
 */
function tornevall_wp_dnsbl_deactivate_db()
{
    global $wpdb;

    $dnsbl_db_tables = array('dnsblcache', 'dnsblstats');
    foreach ($dnsbl_db_tables as $tableName) {
        $wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . $tableName);
    }
}

/**
 * Plugin uninstallation hook
 *
 * When we delete the plugin we'll also clean up the last statistics table
 */
function tornevall_wp_dnsbl_uninstall_db()
{
    global $wpdb;

    $dnsbl_db_tables = array('dnsblstats');
    foreach ($dnsbl_db_tables as $tableName) {
        $wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . $tableName);
    }
}

function tornevall_dnsbl_content_handler()
{
    global $post, $dnsblNonce, $tornevallDnsblFlags;

    if ( ! in_array('global_delist', $tornevallDnsblFlags)) {
        return;
    }

    $currentDelistingPage = get_option('tornevall_dnsbl_delisting_page');
    $hiddenParameters     = '<input type="hidden" name="dNonce" id="dNonce" value="' . $dnsblNonce . '">';
    $isAjax               = true;
    $buttonAction         = '';
    $formAction           = '';

    if (isset($_REQUEST['plain']) || get_option('tornevall_dnsbl_form_noajax') == "1") {
        $isAjax           = false;
        $hiddenParameters .= '<input type="hidden" name="plain" value="1">';
    } else {
        $buttonAction = 'onclick="tFindDnsblAddr()"';
        $formAction   = 'onsubmit="return false;"';
    }

    $requestingAddress = (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : "");

    if (isset($_REQUEST['findIpAddr'])) {
        $requestingAddress = $_REQUEST['findIpAddr'];
    }

    $MODULE_NET = new \Tornevall_WP_DNSBL\MODULE_NETWORK();
    $ipType     = $MODULE_NET->getArpaFromAddr($requestingAddress);

    $removalForm = '
    <form ' . $formAction . ' method="post">
    ' . $hiddenParameters . '
    <input type="text" size="50" maxlength="50" value="' . $requestingAddress . '" id="findIpAddr" name="findIpAddr"><br>
    <button type="' . ($isAjax ? 'button' : 'submit') . '" ' . $buttonAction . '>' . __('IP address control',
            'tornevall_dnsbl') . '</button><br>
            <br>
    <div id="delistingTestStatus" style="display: none;"></div>
    </form>
    <br>
    ';

    if ($post->ID == $currentDelistingPage) {
        if (preg_match("/\[dnsbl_removal_form\]/is", $post->post_content)) {
            return preg_replace("/\[dnsbl_removal_form\]/", $removalForm, $post->post_content);
        } else {
            $usePostContent = $post->post_content . $removalForm;

            return $usePostContent;
        }
    }

    return $post->post_content;
}

function dnsbl_disable_comments()
{
    global $post;
    $currentDelistingPage = get_option('tornevall_dnsbl_delisting_page');

    // Set the plugin free on delisting page
    if ($post->ID == $currentDelistingPage) {
        return;
    }
}