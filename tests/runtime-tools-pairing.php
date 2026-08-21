<?php

namespace {
    define('ABSPATH', __DIR__ . '/');

    $GLOBALS['tornevall_dnsbl_pairing_actions'] = [];

    function add_action($hook, $callback): void
    {
        $GLOBALS['tornevall_dnsbl_pairing_actions'][$hook] = $callback;
    }

    function wp_http_validate_url($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : false;
    }

    function wp_parse_url($url, $component = -1)
    {
        return parse_url($url, $component);
    }
}

namespace Tornevall\Networks\DNSBL {
    class Plugin
    {
        public static function toolsBaseUrl(): string
        {
            return 'https://tools.tornevall.net';
        }
    }

    require_once dirname(__DIR__) . '/includes/class-dnsbl-tools-pairing.php';

    ToolsPairing::registerHooks();

    $expectedHooks = [
        'admin_post_tornevall_dnsbl_tools_pairing_start' => 'start',
        'admin_post_tornevall_dnsbl_tools_pairing_complete' => 'complete',
        'admin_notices' => 'renderAdminNotice',
    ];

    foreach ($expectedHooks as $hook => $method) {
        if (!isset($GLOBALS['tornevall_dnsbl_pairing_actions'][$hook])) {
            fwrite(STDERR, "Missing Tools pairing hook: {$hook}\n");
            exit(1);
        }

        $callback = $GLOBALS['tornevall_dnsbl_pairing_actions'][$hook];
        if (!is_array($callback) || $callback[0] !== ToolsPairing::class || $callback[1] !== $method) {
            fwrite(STDERR, "Unexpected Tools pairing callback for {$hook}\n");
            exit(1);
        }
    }

    $reflection = new \ReflectionMethod(ToolsPairing::class, 'isToolsAuthorizationUrl');
    $reflection->setAccessible(true);

    $cases = [
        'https://tools.tornevall.net/integrations/wordpress/authorize?user_code=ABC123' => true,
        'https://tools.tornevall.net.evil.example/integrations/wordpress/authorize' => false,
        'https://evil.example/integrations/wordpress/authorize' => false,
        'http://tools.tornevall.net/integrations/wordpress/authorize' => false,
        'not-a-url' => false,
    ];

    foreach ($cases as $url => $expected) {
        $actual = $reflection->invoke(null, $url);
        if ($actual !== $expected) {
            fwrite(STDERR, "Unexpected authorization URL result for {$url}\n");
            exit(1);
        }
    }

    echo "Tools pairing runtime checks passed.\n";
}
