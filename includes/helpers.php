<?php

/**
 * Plugin activation hook
 *
 * Activates the plugin and updates the database with new tables.
 */
function tornevall_wp_dnsbl_activate_db()
{
    global $wpdb;

    $dbDeltaQuery = array();

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
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $tableName);
    }
}

/**
 * Removal interface
 *
 * @param $content
 *
 * @return string
 * @throws Exception
 * @todo Move this into class controller
 */
function tornevall_dnsbl_content_handler($content)
{
    global $post, $dnsblNonce, $tornevallDnsblFlags;
    if ( ! in_array('global_delist', $tornevallDnsblFlags)) {
        $content = preg_replace("/\[dnsbl_removal_form\]/", '<div style="font-weight: bold;color: #990000; background: #fffefe; border:1px solid #990000; padding: 5px;">' . __('This site does not have DNSBL delisting permissions. Ask the administrator to fix a proper API key for the DNSBL-service, to activate this function.', 'tornevall_dnsbl') . '</div>', $content);
        return $content;
    }

    $currentDelistingPage = get_option('tornevall_dnsbl_delisting_page');

    if ($post->ID != $currentDelistingPage) {
        return $content;
    }

    $hiddenParameters = '<input type="hidden" name="dNonce" id="dNonce" value="' . $dnsblNonce . '">';
    $isAjax           = true;
    $buttonAction     = '';
    $formAction       = '';

    if (isset($_REQUEST['plain']) || get_option('tornevall_dnsbl_form_noajax') == "1") {
        $isAjax           = false;
        $hiddenParameters .= '<input type="hidden" name="plain" value="1">';
    } else {
        $buttonAction = 'onclick="tFindDnsblAddr()"';
        $formAction   = 'onsubmit="return false;"';
    }

    $requestingAddress = (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : "");

    $delistingDataPlain     = null;
    $constants              = null;
    $delistingStatusDisplay = 'none';

    $showDeleteForm        = null;
    $deleteAddr            = isset($_REQUEST['deleteAddr']) ? $_REQUEST['deleteAddr'] : null;
    $alreadyDeletedControl = false;
    $removalController     = null;
    if ( ! is_null($deleteAddr)) {
        $getCaptcha = torneApi("captcha");
        if (isset($getCaptcha->captchaResponse)) {
            $captchaRequest = $getCaptcha->captchaResponse;
            $imageUrl       = $captchaRequest->imageUrl;
            $imageHash      = $captchaRequest->imageHash;
            $showDeleteForm = '<br><img src="' . $imageUrl . '"><br>
            <span style="color:#000000;">' . __('What does the image say?', 'tornevall_dnsbl') . '
                <input type="text" name="captchaString" value="">
                <input type="hidden" name="captchaHash" value="' . $imageHash . '">
            </span><br>
            <input type="submit" name="submitDelistingRequest">
            ';
        }
        $removalController = torneApi('dnsbl', '', array("ip" => $deleteAddr));
        if (isset($removalController->dnsblResponse)) {
            $removalController = $removalController->dnsblResponse;
        }
        if (is_array($removalController) && count($removalController) == 1) {
            $removalController = array_pop($removalController);
            if (isset($removalController->deleted)) {
                if ($removalController->deleted != "0000-00-00 00:00:00") {
                    $alreadyDeletedControl = true;
                }
            }
        }
    }

    $deleteRequestResponse = null;
    if (isset($_REQUEST['submitDelistingRequest']) && isset($_REQUEST['captchaString']) && ! empty($_REQUEST['captchaString'])) {
        // User input
        $cString = isset($_REQUEST['captchaString']) ? $_REQUEST['captchaString'] : null;
        $cHash   = isset($_REQUEST['captchaHash']) ? $_REQUEST['captchaHash'] : null;

        $showDeleteForm = null;
        $testCaptcha    = torneApi('captcha', 'testCaptcha', array('hash' => $cHash, 'response' => $cString));
        if ( ! is_null($deleteAddr) && isset($testCaptcha->testCaptchaResponse) && (bool)$testCaptcha->testCaptchaResponse === true) {

            $deleteRequest = torneApi("dnsbl", "", array('ip' => $deleteAddr), true, 'DELETE');
            $success       = isset($deleteRequest->success) ? $deleteRequest->success : false;
            $faultString   = isset($deleteRequest->faultstring) ? $deleteRequest->faultstring : __('Unknown API error',
                'tornevall_dnsbl');

            $deleteRequestResponse = $success ? __('Delist successful',
                'tornevall_dnsbl') : __('Delist request failed: ' . $faultString);
        }
    }

    if (isset($_REQUEST['findIpAddr'])) {
        $requestingAddress = $_REQUEST['findIpAddr'];

        $noAddr = false;
        if ( ! filter_var($requestingAddress, FILTER_VALIDATE_IP)) {
            $noAddr = true;
        }

        if ((preg_match_all("/\//", $requestingAddress) || $noAddr) && ! $alreadyDeletedControl) {
            $fontColor = "#990099";
            if (empty($requestingAddress)) {
                $blacklistInfo = __('Address must not be empty', 'tornevall_dnsbl');
            } elseif ($noAddr && ! preg_match("/\//", $requestingAddress)) {
                $blacklistInfo = __('Invalid address format', 'tornevall_dnsbl');
            } else {
                $blacklistInfo = __('Address format is not allowed in this mode', 'tornevall_dnsbl');
            }
            $borderFormat = "border: 1px solid #000099;padding: 5px;background:#FFEEFF";
        } else {
            $isListed  = dnsbl_check_blacklist($requestingAddress, true);
            $constants = 'Not available';
            if ($isListed && ! $alreadyDeletedControl) {
                $currentFlags  = get_option('tornevall_dnsbl_current_flags');
                $BIT           = new \Tornevall_WP_DNSBL\MODULE_NETBITS($currentFlags);
                $constants     = implode("<br>", $BIT->getBitArray($isListed));
                $blacklistInfo = $requestingAddress . ": " . __('Blacklisted', 'tornevall_dnsbl');
                $borderFormat  = "border: 1px solid #990000;padding: 5px;background:#FFEEFF";
                $fontColor     = "#990000";
            } else {
                $fontColor     = "#009900";
                $borderFormat  = "border: 1px solid #009900; padding: 5px;";
                $blacklistInfo = $requestingAddress . ": " . __('Not blacklisted', 'tornevall_dnsbl');
                if ($alreadyDeletedControl) {
                    $blacklistInfo = $requestingAddress . ": " . __('Address was delisted',
                            'tornevall_dnsbl') . " " . $removalController->deleted . " " . __('but servers are not synchronized yet',
                            'tornevall_dnsbl');
                }
                $showDeleteForm = null;
            }
        }

        $delistingStatusDisplay = "";
        $tapi_delete            = plugin_dir_url(__FILE__) . "../images/d.png";
        $tapi_q                 = plugin_dir_url(__FILE__) . "../images/q.png";

        // TODO: nonces missing
        $delistingDataPlain = '
            <div style="font-weight:bold;' . $borderFormat . ';color:' . $fontColor . ';vertical-align:middle;" title="' . $constants . '">
            <span onclick="$T_DNSBL(\'#dnsbl_ip_flags\').show()" style="cursor: pointer;"><img style="vertical-align: middle;" src="' . $tapi_q . '"></span>
                <form method="post">
            <span>
                    <input type="image" name="deleteIp" style="vertical-align: middle;" src="' . $tapi_delete . '">
                    <input type="hidden" name="deleteAddr" value="' . $requestingAddress . '">
            </span>' .
                              $blacklistInfo . '
                    ' . $showDeleteForm . '
                </form>
                <div id="dnsbl_ip_flags" style="display: none;color:#000000 !important;">' . $constants . '</div>
            </div>
            <div style="font-weight: bold;">' . $deleteRequestResponse . '</div>
        ';
    }

    $removalForm = '
    <form ' . $formAction . ' method="post">
    ' . $hiddenParameters . '
    <input type="text" size="50" maxlength="50" value="' . $requestingAddress . '" id="findIpAddr" name="findIpAddr" onkeyup="findIpAddrPress(event)"><br>
    <button type="' . ($isAjax ? 'button' : 'submit') . '" ' . $buttonAction . '>' . __('IP address control',
            'tornevall_dnsbl') . '</button><br>
            <br>
    <div id="delistingTestStatus" style="display: ' . $delistingStatusDisplay . ';">
    ' . $delistingDataPlain . '
    </div>
    </form>
    <br>
    ';

    if (preg_match("/\[dnsbl_removal_form\]/is", $content)) {
        $content = preg_replace("/\[dnsbl_removal_form\]/", $removalForm, $content);
    } else {
        $content .= $removalForm;
    }

    return $content;
}

/**
 * Disable comments on blacklist
 *
 * @return bool
 */
function dnsbl_blacklist_disable_comments()
{
    global $post, $dnsbl_blacklist_control_status, $dnsbl_blacklist_status;
    $currentDelistingPage = get_option('tornevall_dnsbl_delisting_page');

    // Reaching here without a proper state, renders a recheck
    if ($dnsbl_blacklist_control_status != "checked") {
        $dnsbl_blacklist_status = dnsbl_check_blacklist($_SERVER['REMOTE_ADDR'], false);
    }

    // Set the plugin free on delisting page
    if ($post->ID == $currentDelistingPage) {
        return true;
    }

    // Block on blacklist
    if ($dnsbl_blacklist_status) {

        if (get_option("tornevall_dnsbl_blockfull")) {
            $defaultRedirectUrl = 'https://dnsbl.tornevall.org/removal?redirected';
            $redirectUrl        = get_option('tornevall_dnsbl_blocked_redirecturl');
            if (empty($redirectUrl)) {
                $redirectUrl = $defaultRedirectUrl;
            }

            header("Location: " . $redirectUrl, 0, 301);
            die();

        }

        if ( ! get_option('tornevall_dnsbl_nocomment')) {
            return true;
        }

        return false;
    }

    return true;
}

/**
 * Experimental: Shows a message to blacklisted users in the comment section.
 * Problem: Comment widgets are affected
 * Currently disabled!
 *
 * @param $comments
 *
 * @return array
 */
function dnsbl_blacklist_disable_comments_message($comments)
{
    $isAdmin = false;
    if (is_admin() || current_user_can('administrator')) {
        $isAdmin = true;
    }

    // Check the blacklist status before showing comments, so we can notify admin about problems
    $dnsbl_blacklist_status = dnsbl_check_blacklist($_SERVER['REMOTE_ADDR'], false, true);

    if ($dnsbl_blacklist_status) {
        $commentsDisabledStyle = get_option('tornevall_dnsbl_comments_disabled_style');

        if ($isAdmin) {
            echo '<div style="' . $commentsDisabledStyle . '">' . __('Tornevall DNSBL scanner has detected that your current visiting ip address is blacklisted!',
                    'tornevall_dnsbl') . ' <a href="https://dnsbl.tornevall.org/removal?redirected" target="_blank">' . __('For more information, look here',
                    'tornevall_dnsbl') . '</a>' . '</div>';

            return $comments;
        } else {

            echo '<div style="' . $commentsDisabledStyle . '">' . __('Comments section is currently unavailable: Your ip address has been flagged as untrusted by a DNS Blacklist',
                    'tornevall_dnsbl') . '</div>';

            $comments = array();
        }

    }

    return $comments;
}

/**
 * DNS before API resolver
 *
 * @param $addr
 *
 * @return array
 * @todo Move this into class controller
 */
function dnsbl_resolve_addr($addr)
{
    $TESTNET       = new \Tornevall_WP_DNSBL\MODULE_NETWORK();
    $arpaName      = $TESTNET->getArpaFromAddr($addr);
    $resolverNames = explode(",", get_option('tornevall_dnsbl_resolver_hosts'));
    $currentFlags  = get_option('tornevall_dnsbl_current_flags');

    $newArray  = array();
    $constants = array();
    $typeBit   = 0;
    if (is_array($currentFlags) && count($currentFlags)) {
        $BIT = new \Tornevall_WP_DNSBL\MODULE_NETBITS($currentFlags);
        foreach ($resolverNames as $rName) {
            $listed      = false;
            $resolveHost = $arpaName . "." . $rName;
            $resultHost  = @gethostbyname($resolveHost);
            if ( ! empty($resultHost) && $resultHost != $resolveHost) {
                $resultEx = explode(".", $resultHost);
                if (isset($resultEx[0]) && isset($resultEx[3]) && $resultEx[0] == "127") {
                    $listed    = true;
                    $preResult = $BIT->getBitArray($resultEx[3]);
                    $typeBit   = $resultEx[3];
                    $constants = array();
                    foreach ($preResult as $preValue) {
                        if ( ! in_array($preValue, $constants)) {
                            $constants[] = $preValue;
                        }
                    }
                }
            }
        }
        $newArray[] = array(
            'ip'        => $addr,
            'constants' => $constants,
            'typebit'   => $typeBit,
            'deleted'   => (! empty($listed) ? '0000-00-00 00:00:00' : null),
        );
    }

    // Mirroring APIv3 response
    $returnThis = array(
        'response' => array(
            'requestResponse' => $newArray,
            'requestType'     => 'DNS'

        )
    );
    if ( ! count($newArray)) {
        $returnThis['errorcode']   = 404;
        $returnThis['errorstring'] = 'Nothing found as listed';
    } else {
        $returnThis['errorcode']   = null;
        $returnThis['errorstring'] = null;
    }

    return $returnThis;
}

/**
 * @param      $addr
 * @param bool $getIsListed
 * @param bool $adminPassThrough
 *
 * @return int
 * @todo Move this into class controller
 */
function dnsbl_check_blacklist($addr, $getIsListed = false, $adminPassThrough = false)
{
    $currentFlags = get_option('tornevall_dnsbl_current_flags');
    $savedFlags   = get_option("tornevall_dnsbl_filter_types");
    $BIT          = new \Tornevall_WP_DNSBL\MODULE_NETBITS($currentFlags);

    $bitMaskResponse        = dnsbl_check_blacklist_cache($addr);
    $isListedByRequirements = false;
    if (intval($bitMaskResponse)) {
        if ($getIsListed) {
            return $bitMaskResponse;
        }
        $currentBitArray = $BIT->getBitArray($bitMaskResponse);
        foreach ($currentBitArray as $currentBitName) {
            if (in_array($currentBitName, $savedFlags)) {
                $isListedByRequirements = true;
                break;
            }
        }
    }

    // No checking in admin
    if (is_admin() || current_user_can('administrator') && ! $adminPassThrough) {
        if ($isListedByRequirements) {
            add_action('admin_notices', 'dnsbl_is_protected_user');
        }

        return false;
    }

    return $isListedByRequirements;
}

/**
 * If host is blacklisted but user is admin
 */
function dnsbl_is_protected_user()
{
    $showDnsblWarning = false;
    if (isset($_REQUEST['page'])) {
        if ($_REQUEST['page'] == 'tornevallDnsblMenu') {
            $showDnsblWarning = true;
        }
    } else {
        $showDnsblWarning = true;
    }

    if ($showDnsblWarning == true) {

        ?>
        <div class="notice notice-error"
             style="font-weight: bold !important; background: #ffeeee; border:1px solid #990000; text-align: center;">
            <p><?php echo __('Tornevall DNSBL scanner has detected that your current visiting ip address is blacklisted!',
                        'tornevall_dnsbl') . ' <a href="https://dnsbl.tornevall.org/removal?redirected" target="_blank">' . __('For more information, look here',
                        'tornevall_dnsbl') . '</a>'; ?></p>
        </div>
        <?php
    }
}

/**
 * DNSBL cache controller - Prioritize cache before DNS and API
 *
 * @param $addr
 *
 * @return mixed
 * @todo Move this into class controller
 */
function dnsbl_check_blacklist_cache($addr)
{
    global $wpdb;
    $cacheAge = get_option('tornevall_dnsbl_cache_age');
    if (intval($cacheAge) < 900) {
        $cacheAge = 900;
    }

    $tableCache = $wpdb->prefix . 'dnsblcache';

    $test_ip        = $wpdb->prepare("SELECT * FROM {$tableCache} WHERE ipAddr = %s", array($addr));
    $testIpResponse = $wpdb->get_results($test_ip);

    if (isset($testIpResponse[0])) {
        $testIpResponseObject = $testIpResponse[0];
    }

    if ( ! isset($testIpResponseObject->ipAddr)) {
        $result         = dnsbl_resolve_addr($addr);
        $internalResult = array_pop($result['response']['requestResponse']);

        if (isset($internalResult['ip'])) {
            $wpdb->query($wpdb->prepare("INSERT INTO {$tableCache} (ipAddr, lastResponse, lastResolve) VALUES (%s, %d, %d)",
                array($addr, $internalResult['typebit'], time())));
        }

        return $internalResult['typebit'];
    } else {

        $lastRes = time() - (isset($testIpResponseObject->lastResolve) ? intval($testIpResponseObject->lastResolve) : time());
        // When time is up, update with new data
        if ($lastRes >= $cacheAge) {
            $result         = dnsbl_resolve_addr($addr);
            $internalResult = array_pop($result['response']['requestResponse']);
            $wpdb->query($wpdb->prepare("UPDATE {$tableCache} set lastResponse = %d, lastResolve = %d WHERE ipAddr = %s",
                array($internalResult['typebit'], time(), $addr)));

            return $internalResult['typebit'];
        }

        return $testIpResponseObject->lastResponse;
    }
}
