<?php

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('TORNEVALL_DNSBL_PLUGIN_DIR', dirname(__DIR__) . '/');
    define('TORNEVALL_DNSBL_PLUGIN_FILE', dirname(__DIR__) . '/tornevall-wp-dnsbl.php');

    class WooCommerce
    {
    }

    $GLOBALS['tornevall_dnsbl_wc_actions'] = [];
    $GLOBALS['tornevall_dnsbl_wc_filters'] = [];
    $GLOBALS['tornevall_dnsbl_wc_options'] = [
        'tornevall_dnsbl_wc_enabled' => '1',
        'tornevall_dnsbl_wc_filter_types' => ['IP_FRAUDCOMMERCE'],
        'tornevall_dnsbl_wc_notify_mode' => 'off',
        'tornevall_dnsbl_wc_notify_schedule' => 'daily',
    ];

    function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1): void
    {
        $GLOBALS['tornevall_dnsbl_wc_actions'][$hook][] = [$callback, $priority, $acceptedArgs];
    }

    function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1): void
    {
        $GLOBALS['tornevall_dnsbl_wc_filters'][$hook][] = [$callback, $priority, $acceptedArgs];
    }

    function remove_action($hook, $callback, $priority = 10): void
    {
    }

    function remove_filter($hook, $callback, $priority = 10): void
    {
    }

    function get_option($key, $default = false)
    {
        return array_key_exists($key, $GLOBALS['tornevall_dnsbl_wc_options'])
            ? $GLOBALS['tornevall_dnsbl_wc_options'][$key]
            : $default;
    }

    function add_option($key, $value): bool
    {
        $GLOBALS['tornevall_dnsbl_wc_options'][$key] = $value;
        return true;
    }

    function __($text, $domain = null): string
    {
        return $text;
    }

    function sanitize_key($value): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$value));
    }

    function sanitize_textarea_field($value): string
    {
        return trim(strip_tags((string)$value));
    }

    function sanitize_email($value): string
    {
        return filter_var((string)$value, FILTER_SANITIZE_EMAIL);
    }

    function is_email($value): bool
    {
        return filter_var((string)$value, FILTER_VALIDATE_EMAIL) !== false;
    }
}

namespace Tornevall\Networks\DNSBL {
    class Plugin
    {
        public static function getCurrentFlagMap(): array
        {
            return [
                'IP_PHISHING' => 4,
                'IP_FRAUDCOMMERCE' => 8,
                'IP_SECOND_EXIT' => 32,
                'IP_ABUSE_NO_SMTP' => 64,
            ];
        }

        public static function canonicalFlagName($flag): string
        {
            $flag = strtoupper(trim((string)$flag));
            return array_key_exists($flag, self::getCurrentFlagMap()) ? $flag : '';
        }
    }

    require_once dirname(__DIR__) . '/includes/class-dnsbl-woocommerce.php';
    require_once dirname(__DIR__) . '/includes/class-dnsbl-migrations.php';

    WooCommerce::registerHooks();
    WooCommerce::boot();

    $requiredActions = [
        'before_woocommerce_init',
        'plugins_loaded',
        'tornevall_dnsbl_wc_bulk_notification',
        'woocommerce_checkout_process',
        'woocommerce_store_api_checkout_update_order_meta',
    ];

    foreach ($requiredActions as $hook) {
        if (empty($GLOBALS['tornevall_dnsbl_wc_actions'][$hook])) {
            fwrite(STDERR, "Missing WooCommerce checkout hook: {$hook}\n");
            exit(1);
        }
    }

    $requiredFilters = [
        'allowed_redirect_hosts',
        'woocommerce_settings_tabs_array',
    ];

    foreach ($requiredFilters as $hook) {
        if (empty($GLOBALS['tornevall_dnsbl_wc_filters'][$hook])) {
            fwrite(STDERR, "Missing WooCommerce checkout filter: {$hook}\n");
            exit(1);
        }
    }

    if (WooCommerce::defaultSelectedFlags() !== ['IP_FRAUDCOMMERCE', 'IP_SECOND_EXIT']) {
        fwrite(STDERR, "Unexpected default WooCommerce checkout flags.\n");
        exit(1);
    }

    $selected = WooCommerce::sanitizeFilterTypes(['IP_FRAUDCOMMERCE', 'IP_SECOND_EXIT', 'INVALID']);
    if ($selected !== ['IP_FRAUDCOMMERCE', 'IP_SECOND_EXIT']) {
        fwrite(STDERR, "WooCommerce checkout flag sanitization failed.\n");
        exit(1);
    }

    $matched = new \ReflectionMethod(WooCommerce::class, 'matchedSelectedFlags');
    $matched->setAccessible(true);

    if ($matched->invoke(null, 32) !== []) {
        fwrite(STDERR, "Unselected IP_SECOND_EXIT unexpectedly blocks checkout.\n");
        exit(1);
    }

    if ($matched->invoke(null, 8) !== ['IP_FRAUDCOMMERCE']) {
        fwrite(STDERR, "Selected IP_FRAUDCOMMERCE did not match checkout policy.\n");
        exit(1);
    }

    $highRisk = new \ReflectionMethod(WooCommerce::class, 'containsHighRiskFlag');
    $highRisk->setAccessible(true);

    if (!$highRisk->invoke(null, ['IP_PHISHING']) || $highRisk->invoke(null, ['IP_SECOND_EXIT'])) {
        fwrite(STDERR, "WooCommerce high-risk delisting policy failed.\n");
        exit(1);
    }

    if (Migrations::schemaVersion() !== '3.1.1') {
        fwrite(STDERR, "WooCommerce checkout migration schema was not preserved.\n");
        exit(1);
    }

    $tables = Migrations::tableDefinitions();
    if (!isset($tables['tornevall_dnsbl_wc_blocked_log'])) {
        fwrite(STDERR, "WooCommerce checkout notification table definition is missing.\n");
        exit(1);
    }

    echo "WooCommerce checkout protection runtime checks passed.\n";
}
