<?php

namespace TorneLIB;

/**
 * Class TORNEVALL_DNSBL_BITS - Bitmasks used by Tornevall DNSBL with FraudBL
 *
 * @package TorneLIB
 */
class TORNEVALL_DNSBL_BITS {

    /*
     * Registered bits from dnsbl.tornevall.org (For full reference, see http://docs.tornevall.net/x/AoA_/)
     */
    const BIT_REPORTED = 1;                 /* DEPRECATED: IP has been reported */
    const BIT_CONFIRMED = 2;                /* IP has been confirmed as working proxy */
    const BIT_FRAUDBL = 4;                  /* Phishing (Fraudible) */
    const BIT_EMPTY = 8;                    /* DEPRECATED: Empty response - IP was tested, but was never returning anything */
    const BIT_SPAM = 16;                    /* E-Mail spam */
    const BIT_SECOND_ENTRY = 32;            /* IP is tested and is fully functional but there is a second entry point */
    const BIT_ABUSE = 64;                   /* Abusive host, webspam, portscanner, etc */
    const BIT_DIFFERENT_STATE = 128;        /* IP has a different anonymous-state (web-based proxies, like anonymouse, etc) */

    /**
     * Get and return active bits from a bitvalue-representative
     *
     * @param int $bitValue
     * @return array
     */
    public function getBitArray($bitValue = 0)
    {
        $returnBitList = array();
        if ($this->isBit(self::BIT_REPORTED, $bitValue)) { $returnBitList[] = "BIT_REPORTED"; }
        if ($this->isBit(self::BIT_CONFIRMED, $bitValue)) { $returnBitList[] = "BIT_CONFIRMED"; }
        if ($this->isBit(self::BIT_FRAUDBL, $bitValue)) { $returnBitList[] = "BIT_FRAUDBL"; }
        if ($this->isBit(self::BIT_EMPTY, $bitValue)) { $returnBitList[] = "BIT_EMPTY"; }
        if ($this->isBit(self::BIT_SPAM, $bitValue)) { $returnBitList[] = "BIT_SPAM"; }
        if ($this->isBit(self::BIT_SECOND_ENTRY, $bitValue)) { $returnBitList[] = "BIT_SECOND_ENTRY"; }
        if ($this->isBit(self::BIT_ABUSE, $bitValue)) { $returnBitList[] = "BIT_ABUSE";}
        if ($this->isBit(self::BIT_DIFFERENT_STATE, $bitValue)) { $returnBitList[] = "BIT_DIFFERENT_STATE"; }
        return $returnBitList;
    }

    /**
     * Finds out if a bitmasked value is located in a bitarray
     *
     * @param int $requestedBitValue
     * @param int $matchWith
     * @return bool
     */
    public function isBit($requestedBitValue = 0, $matchWith = 0)
    {
        preg_match_all("/\d/", sprintf("%08d", decbin($matchWith)), $bitArray);
        for ($bitCount = count($bitArray[0]); $bitCount >= 0; $bitCount--) {
            if (isset($bitArray[0][$bitCount])) {
                if ($matchWith & pow(2, $bitCount)) {
                    if ($requestedBitValue == pow(2, $bitCount)) { return true; }
                }
            }
        }
    }
}