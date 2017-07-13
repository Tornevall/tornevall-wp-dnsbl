<?php

/**
 * Class TornevallDNSBL - DNSBL Modification from TorneLIB
 *
 * @link https://bitbucket.tornevall.net/projects/LIB/repos/tornelib-php/browse/classes/tornevall_dnsbl.php
 */
class TornevallDNSBL {
    private $BitClass;
    private $NETLIB;
    private $blZones = array('dnsbl.tornevall.org');    // Not using bl.fraudbl.org as this is a mail-only service
    private $cacheAge;
    private $tables = array('cache' => '', 'stats' => '');
    private $test;
    private $filterOn;
    private $ipBits;
    private $scanUrl = "https://dnsbl.tornevall.org/removal/";

    /**
     * TornevallDNSBL constructor.
     */
    function __construct()
    {
        global $wpdb;
        $this->cacheAge = (get_option("tornevall_dnsbl_cache_age") > 0 ? get_option("tornevall_dnsbl_cache_age") : 900);
        $this->tables = array(
            'cache' => $wpdb->prefix . "dnsblcache",
            'stats' => $wpdb->prefix . "dnsblstats"
        );
        $filterTypesOption = get_option("tornevall_dnsbl_filter_types");
        $this->filterOn = (is_array($filterTypesOption) ? $filterTypesOption : array());
        $compatFilters = array();
        // Make us compatible
        foreach ($this->filterOn as $filterKey) { $compatFilters[] = "BIT_" . strtoupper($filterKey); }
        $this->filterOn = $compatFilters;
        $this->BitClass = new \TorneLIB\TORNEVALL_DNSBL_BITS();
        $this->NETLIB = new \TorneLIB\TorneLIB_Network();
        // Used for testing
        if (isset($_REQUEST['dnsbltest'])) {
            if (empty($_REQUEST['dnsbltest'])) {
                $this->test = "255.255.255.255";
            } else {
                $this->test = $_REQUEST['dnsbltest'];
            }
        }

        $this->dbClean();
    }
    /**
     * Purge old entries
     */
    private function dbClean() {
        global $wpdb;
        $resolveHistory = strftime("%Y-%m-%d %H:%M:%S", time() - $this->cacheAge);
        $wpdb->query("DELETE FROM " . $this->tables['cache'] . " WHERE resolvetime < '" . $resolveHistory . "'");
    }

    /**
     * Test ip against the cache
     *
     * @param string $addr
     * @return string
     * @throws Exception
     */
    private function getFromCache($addr = '') {
        global $wpdb;
        $test_ip = $wpdb->get_results("SELECT * FROM " . $this->tables['cache'] . " WHERE ip = '$addr'");
        if (isset($test_ip[0]->ip)) {
            return $test_ip[0]->resolve;
        }
        throw new \Exception("Not listed in cache");
    }

    private function setCache($addr, $bitResponse) {
        global $wpdb;

        try {
            $testCache = $this->getFromCache($addr);
            $hasCache = true;
        } catch (\Exception $e) {
            $hasCache = false;
        }

        if (!$hasCache) {
            $wpdb->insert(
                $this->tables['cache'],
                array(
                    'ip' => $addr,
                    'resolvetime' => current_time('mysql', 1),
                    'resolve' => !empty($bitResponse) ? $bitResponse : 0
                ),
                array(
                    '%s', '%s', '%d'
                )
            );
        } else {
            $wpdb->update(
                $this->tables['cache'],
                array('resolvetime' => current_time('mysql', 1)),
                array('ip' => $addr)
            );
        }
    }

    /**
     * Returns true if the address requested is "sane". This is put here just to prevent defective resolvers that returns the value of 127 when they should not.
     *
     * @param $ipAddr
     * @param $matchAddr
     * @return bool
     */
    private function isSaneAddress($ipAddr, $matchAddr) {
        if ($this->NETLIB->getArpaFromAddr($ipAddr, true) !== \TorneLIB\TorneLIB_Network_IP::IPTYPE_NONE) {
            $requestAddress = explode(".", $ipAddr);
            $matchAddress = explode(".", $matchAddr);
            if (isset($requestAddress[3]) && isset($matchAddress[3])) {
                unset($requestAddress[3], $matchAddress[3]);
                /*
                 * Resolver failed if those two implosions outputs the same result.
                 */
                if (implode(".", $requestAddress) == implode(".", $matchAddress)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Returns a list of where the ipaddress is blacklisted. Use $getListedTypes = true if you want to get the blacklisting types (bitmasks) from the resolved host.
     *
     * @param $ipAddr
     * @param bool $getListedTypes
     * @param bool $getBitValue
     * @return array
     */
    private function resolveBlacklist($ipAddr, $getListedTypes = false, $getBitValue = false) {
        $listedAt = array();
        if ($this->NETLIB->getArpaFromAddr($ipAddr, true) !== \TorneLIB\TorneLIB_Network_IP::IPTYPE_NONE) {
            $arpaResolve = $this->NETLIB->getArpaFromAddr($ipAddr);
            foreach ($this->blZones as $blZone) {
                $hostName = $arpaResolve . "." . $blZone;
                $resolveAddr = gethostbyname($hostName);
                if (preg_match("/^127/", $resolveAddr) && $this->isSaneAddress($resolveAddr, $ipAddr)) {
                    $lastBit = explode(".", $resolveAddr);
                    /*
                     * Making sure that the bit value is really not 0, since 0 is not a proper value. Also making sure that the requested ip address does not equal
                     * to the resolved address since that may indicate on faulty resolvers.
                     */
                    if (isset($lastBit[3]) && $lastBit[3] > 0) {
                        if (!$getListedTypes) {
                            $listedAt[$blZone] = $resolveAddr;
                        } else {
                            $listedAt[$blZone] = $this->BitClass->getBitArray($lastBit[3]);
                        }
                    }
                }
            }
        }
        if ($getBitValue) {
            return $lastBit[3];
        }
        return $listedAt;
    }

    /**
     * Returns a basic value if the address is listed anywhere (use resolveBlacklist() if you need to use the details yourself)
     *
     * @param $ipaddr
     * @param bool $getBitValue
     * @return bool
     */
    private function isListed($ipaddr, $getBitValue = false) {
        $this->ipBits = $this->resolveBlacklist($ipaddr, true, $getBitValue);
        if ($getBitValue) {
            return $this->ipBits;
        }
        if (count($this->ipBits['dnsbl.tornevall.org'])) {
            return $this->ipBits['dnsbl.tornevall.org'];
        }
        return null;
    }


    public function canBlockComments() {
        return get_option("tornevall_dnsbl_nocomment");

    }
    public function canRedirect() {
        return get_option("tornevall_dnsbl_blockfull");
    }

    /**
     * Test ip address (still handles parameters if necessary)
     *
     * @param string $remoteAddressRequest
     */
    public function testip($remoteAddressRequest = '') {
        // Console? Prevent dumb notices
        if (!isset($_SERVER['REMOTE_ADDR'])) {
            $_SERVER['REMOTE_ADDR'] = null;
        }
        // Default
        $addr = $_SERVER['REMOTE_ADDR'];

        // Set own address
        if (!empty($remoteAddressRequest)) {
            $addrType = $this->NETLIB->getArpaFromAddr($remoteAddressRequest, true);
            // If valid
            if ($addrType > 0) {
                $addr = $remoteAddressRequest;
            }
        }
        // Set from debugging in browser
        if (!empty($this->test)) {
            $addr = $this->test;
        }


        try {
            $bitValue = $this->getFromCache($addr);
            $isListed = $this->BitClass->getBitArray($bitValue);
        } catch (\Exception $e) {
            $isListed = $this->isListed($addr);
            $bitValue = $this->isListed($addr, true);
        }
        $this->setCache($addr, $bitValue);

        if (isset($_REQUEST['showresponse'])) {
            echo __('Requested to show dnsbl response instead of showing site.') . "<br>";
            echo __('Resolved address is: ') . $addr . "<br>";
            echo __('Response is: ') . (!empty($isListed) ? $isListed : __('Not set'));
            die();
        }
        if (!is_null($isListed)) {
            $dnsblReact = false;
            foreach ($this->filterOn as $filterKey) {
                if (in_array($filterKey, $isListed)) {
                    $dnsblReact = true;
                    break;
                }
            }
            if ($dnsblReact) {
                if ($this->canRedirect()) {
                    header("Location: https://dnsbl.tornevall.org/scan/", 0, 301);
                }
                if ($this->canBlockComments()) {
                    add_filter('comments_open', 'dnsbl_disable_comments', 1, 2);
                }
            }
        }
    }
}



