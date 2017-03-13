<?php

global $wpdb;

class t_dnsbl {

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
        $table_name = $wpdb->prefix . "dnsblcache";

        $cacheAge = (get_option( "tornevall_dnsbl_cache_age" ) > 0 ? get_option( "tornevall_dnsbl_cache_age" ) : 900);
        // Clean up before checking
        $wpdb->query("DELETE FROM $table_name WHERE resolvetime < FROM_UNIXTIME(UNIX_TIMESTAMP() - $cacheAge)");
        $dnsbl_bitmask = null;
        $test_ip = $wpdb->get_results("SELECT * FROM $table_name WHERE ip = '$ip'");
        if (!isset($test_ip[0]->ip))
        {
            $fetchResolve = $this->rblresolve($ip);
            if (is_array($fetchResolve) && count($fetchResolve))
            {
                if (intval($fetchResolve[0]) == 127 && intval($fetchResolve[3]) > 0)
                {
                    $dnsbl_bitmask = $fetchResolve[3];
                    $wpdb->insert(
                        $table_name,
                        array(
                            'ip' => $_SERVER['REMOTE_ADDR'],
                            'resolvetime' => current_time('mysql', 1),
                            'resolve' => $fetchResolve[3]
                        ),
                        array(
                            '%s', '%s', '%d'
                        )
                    );
                }
            }
        }
        else
        {
            $dnsbl_bitmask = $test_ip[0]->resolve;
        }
        if ($dnsbl_bitmask > 0)
        {
            $bitList = $this->torneBits($dnsbl_bitmask);

            $testBlockComments = get_option("tornevall_dnsbl_nocomment");
            $testBlockFull = get_option("tornevall_dnsbl_blockfull");
            $filterOn = (get_option("tornevall_dnsbl_filter_types") ? get_option("tornevall_dnsbl_filter_types") : array("abuse"));
            if (is_array($filterOn))
            {
                $dnsblHit = false;
                foreach ($filterOn as $filterParam) {if (in_array($filterParam, $bitList)) {$dnsblHit = true;}}
                if ($dnsblHit)
                {
                    if ($testBlockComments) {add_filter( 'comments_open', 'dnsbl_disable_comments', 10, 2 );}
                    if ($testBlockFull) {
                        header("Location: https://dnsbl.tornevall.org/scan/", 0, 301);
                        exit;
                    }
                }
            }
        }
        else
        {
            $wpdb->insert(
                $table_name,
                array(
                    'ip' => $_SERVER['REMOTE_ADDR'],
                    'resolvetime' => current_time('mysql', 1),
                    'resolve' => '0'
                ),
                array(
                    '%s', '%s', '%d'
                )
            );
        }
    }

    /* Imported from TorneEngine v3 */
    function rblresolve ($ip = null, $rbldomain = 'dnsbl.tornevall.org')
    {
        if (!$ip) {return false;}                       // No data should return nothing
        if (!$rbldomain) {return false;}        // No rbl = ignore
        $returnthis = (long2ip(ip2long($ip)) != "0.0.0.0" ? explode('.', gethostbyname(implode('.', array_reverse(explode('.', $ip))) . '.' . $rbldomain)) : explode(".", gethostbyname($this->v6arpa($ip) . "." . $rbldomain)));
        if (implode(".", $returnthis) != (long2ip(ip2long($ip)) != "0.0.0.0" ? implode('.', array_reverse(explode('.', $ip))) . '.' . $rbldomain : $this->v6arpa($ip) . "." . $rbldomain)) {return $returnthis;} else {return false;}
    }
    function torneBits($lastvalue = 0, $returnstrings = false)
    {
        $stringarr = array();
        $hasabuse = false;
        foreach ($this->rbl_tornevall as $OPM_t => $OPM_tc)
        {
            $bit = ((($lastvalue & $OPM_tc) == 0) ? null : $OPM_t);
            if ($bit != null) {$stringarr[] = $bit;}
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

$DNSBL = new t_dnsbl();

if ( !is_admin() ) {
    // Debugging
    //$_SERVER['REMOTE_ADDR'] = "194.213.74.1";
    $DNSBL->testip($_SERVER['REMOTE_ADDR'], "dnsbl.tornevall.org");
}

function dnsbl_disable_comments($open='', $post_id='') {return false;}
