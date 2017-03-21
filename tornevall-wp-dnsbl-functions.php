<?php

global $wpdb;

class t_dnsbl
{

    var $rbl_tornevall = array
    (
        'checked' => 1,
        'working' => 2,
        'email' => 4,
        'timeout' => 8,
        'error' => 16,
        'elite' => 32,
        'abuse' => 64,
        'anonymous' => 128
    );

    function testip($ip = null)
    {
        global $wpdb;
        if (is_null($ip)) return;
        $table_cache_name = $wpdb->prefix . "dnsblcache";
        $table_stats_name = $wpdb->prefix . "dnsblstats";

        $cacheAge = (get_option("tornevall_dnsbl_cache_age") > 0 ? get_option("tornevall_dnsbl_cache_age") : 900);
        // 2014-12-05: http://tracker.tornevall.net/browse/TSDWP-6
        $resolveHistory = strftime("%Y-%m-%d %H:%M:%S", time() - $cacheAge);
        // Clean up before checking
        $wpdb->query("DELETE FROM " . $table_cache_name . " WHERE resolvetime < '" . $resolveHistory . "'");
        $dnsbl_bitmask = null;
        $test_ip = $wpdb->get_results("SELECT * FROM " . $table_cache_name . " WHERE ip = '" . $ip . "'");
        if (!isset($test_ip[0]->ip)) {
            $fetchResolve = $this->rblresolve($ip);
            if (is_array($fetchResolve) && count($fetchResolve)) {
                if (intval($fetchResolve[0]) == 127 && intval($fetchResolve[3]) > 0) {
                    $dnsbl_bitmask = $fetchResolve[3];
                    $wpdb->insert(
                        $table_cache_name,
                        array(
                            'ip' => $ip,
                            'resolvetime' => current_time('mysql', 1),
                            'resolve' => $fetchResolve[3]
                        ),
                        array(
                            '%s', '%s', '%d'
                        )
                    );
                }
            }
        } else {
            $dnsbl_bitmask = $test_ip[0]->resolve;
        }
        if ($dnsbl_bitmask > 0) {
            $bitList = $this->torneBits($dnsbl_bitmask);

            $testBlockComments = get_option("tornevall_dnsbl_nocomment");
            $testBlockFull = get_option("tornevall_dnsbl_blockfull");
            $filterOn = (get_option("tornevall_dnsbl_filter_types") ? get_option("tornevall_dnsbl_filter_types") : array("abuse"));
            if (is_array($filterOn)) {
                $dnsblHit = false;
                foreach ($filterOn as $filterParam) {
                    if (in_array($filterParam, $bitList)) {
                        $dnsblHit = true;
                    }
                }
                if ($dnsblHit) {
                    $blockType = "";
                    if ($testBlockComments) {
                        add_filter('comments_open', 'dnsbl_disable_comments', 10, 2);
                        $blockType = "comments";
                    }
                    if ($testBlockFull) {
                        $blockType = "redirect";
                    }
                    $wpdb->insert(
                        $table_stats_name,
                        array(
                            'ip' => $ip,
                            'resolvetime' => current_time('mysql', 1),
                            'blocked' => $blockType
                        ),
                        array(
                            '%s', '%s', '%s'
                        )
                    );
                    if ($testBlockFull) {
                        header("Location: https://dnsbl.tornevall.org/scan/", 0, 301);
                        exit;
                    }
                }
            }
        } else {
            // http://tracker.tornevall.net/browse/TSDWP-1
            if (!isset($test_ip[0]->ip)) {
                // http://tracker.tornevall.net/projects/TSDWP/issues/TSDWP-10
                $wpdb->insert(
                    $table_cache_name,
                    array(
                        'ip' => $ip,
                        'resolvetime' => current_time('mysql', 1),
                        'resolve' => '0'
                    ),
                    array(
                        '%s', '%s', '%d'
                    )
                );
            } else {
                // http://tracker.tornevall.net/browse/TSDWP-6
                if (get_option("tornevall_dnsbl_update_timestamp")) {
                    // http://tracker.tornevall.net/projects/TSDWP/issues/TSDWP-10
                    $wpdb->query("UPDATE " . $table_cache_name . " SET resolvetime = '" . strftime("%Y-%m-%d %H:%M:%S", time()) . "' WHERE resolvetime < '" . $resolveHistory . "')");
                }
            }
        }
    }

    /* Imported from TorneEngine v3 */
    function rblresolve($ip = null, $rbldomain = 'dnsbl.tornevall.org')
    {
        if (!$ip) {
            return false;
        }                       // No data should return nothing
        if (!$rbldomain) {
            return false;
        }        // No rbl = ignore
        $returnthis = (long2ip(ip2long($ip)) != "0.0.0.0" ? explode('.', gethostbyname(implode('.', array_reverse(explode('.', $ip))) . '.' . $rbldomain)) : explode(".", gethostbyname($this->v6arpa($ip) . "." . $rbldomain)));
        if (implode(".", $returnthis) != (long2ip(ip2long($ip)) != "0.0.0.0" ? implode('.', array_reverse(explode('.', $ip))) . '.' . $rbldomain : $this->v6arpa($ip) . "." . $rbldomain)) {
            return $returnthis;
        } else {
            return false;
        }

        function torneBits($lastvalue = 0, $returnstrings = false)
        {
            $stringarr = array();
            $hasabuse = false;
            foreach ($this->rbl_tornevall as $OPM_t => $OPM_tc) {
                $bit = ((($lastvalue & $OPM_tc) == 0) ? null : $OPM_t);
                if ($bit != null) {
                    $stringarr[] = $bit;
                }
            }
            return $stringarr;
        }

        function v6arpa($ip = '::')
        {
            $unpack = @unpack('H*hex', inet_pton($ip));
            $hex = $unpack['hex'];
            return implode('.', array_reverse(str_split($hex)));
        }
    }
}

$DNSBL = new t_dnsbl();
if (is_admin()) { $DNSBL->testip($_SERVER['REMOTE_ADDR'], "dnsbl.tornevall.org"); }

function dnsbl_disable_comments($open='', $post_id='') {return false;}
load_plugin_textdomain('tornevall_dnsbl', false, dirname( plugin_basename( __FILE__ ) ) . '/language');
