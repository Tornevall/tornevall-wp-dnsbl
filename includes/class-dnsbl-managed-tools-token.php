<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lets another server-side WordPress integration provide a DNSBL credential
 * without coupling this plugin to that integration.
 */
class ManagedToolsToken
{
    /**
     * Legacy Tools token option used as the last fallback by Plugin::apiToken().
     *
     * Filtering this option preserves the existing priority order:
     * explicit write token, legacy removal token, stored legacy Tools token,
     * then an externally managed token.
     */
    private const LEGACY_TOOLS_TOKEN_OPTION = 'tornevall_dnsbl_tools_token';

    public static function registerHooks(): void
    {
        add_filter('option_' . self::LEGACY_TOOLS_TOKEN_OPTION, [self::class, 'resolve'], 20, 2);
    }

    /**
     * @param mixed $storedValue Stored legacy Tools token value.
     * @return mixed
     */
    public static function resolve($storedValue)
    {
        if (is_scalar($storedValue) && trim((string)$storedValue) !== '') {
            return $storedValue;
        }

        /**
         * Supply a managed DNSBL API token from another server-side plugin.
         *
         * The value must never be rendered into browser markup or JavaScript.
         * Explicit DNSBL plugin token settings always take precedence because
         * this filter is attached only to the final legacy Tools fallback.
         *
         * @param string $token Managed token candidate. Empty by default.
         */
        $managed = apply_filters('tornevall_dnsbl_managed_api_token', '');

        if (!is_scalar($managed)) {
            return $storedValue;
        }

        $managed = trim((string)$managed);
        return $managed !== '' ? $managed : $storedValue;
    }
}
