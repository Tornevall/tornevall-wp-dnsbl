<?php

namespace Tornevall\Networks\DNSBL;

define('ABSPATH', dirname(__DIR__) . '/');

$GLOBALS['dnsbl_test_options'] = [];
$GLOBALS['dnsbl_test_submenu_args'] = [];

function get_option(string $name, $default = false)
{
    return $GLOBALS['dnsbl_test_options'][$name] ?? $default;
}

function update_option(string $name, $value, $autoload = null): bool
{
    $GLOBALS['dnsbl_test_options'][$name] = $value;
    return true;
}

function apply_filters(string $hook, $value, ...$args)
{
    return $value;
}

function do_action(string $hook, ...$args): void
{
}

function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    return true;
}

function add_filter(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    return true;
}

function add_submenu_page(...$args)
{
    $GLOBALS['dnsbl_test_submenu_args'][] = $args;
    return 'dnsbl-commerce-hooks';
}

function __(string $text, string $domain = ''): string
{
    return $text;
}

class Plugin
{
    public static bool $writeTokenSet = true;
    public static string $toolsMode = 'dev';

    public static function writeTokenSet(): bool
    {
        return self::$writeTokenSet;
    }

    public static function toolsMode(): string
    {
        return self::$toolsMode;
    }
}

class ApiClient
{
    public static array $remoteBitmasks = [];
    public static bool $available = true;

    public static function fromPluginOptions(): ?self
    {
        return self::$available ? new self() : null;
    }

    public function checkIp(string $ip): array
    {
        if (!array_key_exists($ip, self::$remoteBitmasks)) {
            return ['ok' => true, 'body' => ['combined_bitmask' => 0]];
        }

        return ['ok' => true, 'body' => ['combined_bitmask' => self::$remoteBitmasks[$ip]]];
    }
}

class WriteQueue
{
    private static ?self $instance = null;

    public array $operations = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = new self();
    }

    public function queueAdd(string $ip, int $bitmask, string $publicationType = 'dnsbl', int $ttl = 300): void
    {
        $this->operations[] = compact('ip', 'bitmask', 'publicationType', 'ttl') + ['action' => 'add'];
    }

    public function queueDelete(string $ip, int $bitmask, string $publicationType = 'dnsbl'): void
    {
        $this->operations[] = compact('ip', 'bitmask', 'publicationType') + ['action' => 'delete'];
    }

    public function queueUpdate(
        string $ip,
        int $oldBitmask,
        int $newBitmask,
        string $publicationType = 'dnsbl',
        int $ttl = 300
    ): void {
        $this->operations[] = compact('ip', 'oldBitmask', 'newBitmask', 'publicationType', 'ttl')
            + ['action' => 'update'];
    }

    public function flush(): array
    {
        return ['ok' => true];
    }
}

require dirname(__DIR__) . '/includes/class-dnsbl-commerce-fraud.php';

function resetFixture(): void
{
    $GLOBALS['dnsbl_test_options'] = [
        'tornevall_dnsbl_commerce_fraud_enabled' => '1',
        'tornevall_dnsbl_commerce_fraud_ownership' => [],
        'tornevall_dnsbl_commerce_fraud_event_log' => [],
    ];
    $GLOBALS['dnsbl_test_submenu_args'] = [];
    Plugin::$writeTokenSet = true;
    Plugin::$toolsMode = 'dev';
    ApiClient::$available = true;
    ApiClient::$remoteBitmasks = [];
    WriteQueue::reset();
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true) . "\n"
        );
        exit(1);
    }
}

resetFixture();
$pending = CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'pending',
    'order_id' => 10,
    'ip' => '203.0.113.10',
]);
assertSameValue('observed', $pending['status'], 'Pending signals must only be observed.');
assertSameValue([], WriteQueue::getInstance()->operations, 'Pending signals must not queue a DNSBL write.');

resetFixture();
$added = CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'rejected',
    'order_id' => 20,
    'ip' => '203.0.113.20',
]);
assertSameValue('queued', $added['status'], 'A confirmed rejected signal must queue an add.');
assertSameValue('add', WriteQueue::getInstance()->operations[0]['action'], 'The first operation must be an add.');
assertSameValue('commerce', WriteQueue::getInstance()->operations[0]['publicationType'], 'Adds must use commerce publication.');

$removed = CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'accepted',
    'order_id' => 20,
    'ip' => '203.0.113.20',
]);
assertSameValue('queued', $removed['status'], 'The matching accepted signal must queue a remove.');
assertSameValue('delete', WriteQueue::getInstance()->operations[1]['action'], 'The second operation must be a delete.');

resetFixture();
ApiClient::$remoteBitmasks['203.0.113.30'] = 8;
$preexisting = CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'fraud',
    'order_id' => 30,
    'ip' => '203.0.113.30',
]);
assertSameValue('owned', $preexisting['status'], 'A pre-existing commerce listing must not be added again.');
assertSameValue([], WriteQueue::getInstance()->operations, 'A pre-existing listing must not queue an add.');

$preexistingClear = CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'cleared',
    'order_id' => 30,
    'ip' => '203.0.113.30',
]);
assertSameValue('ignored', $preexistingClear['status'], 'A pre-existing listing must not be removed by this plugin.');
assertSameValue([], WriteQueue::getInstance()->operations, 'A pre-existing listing must not queue a delete.');

resetFixture();
ApiClient::$available = false;
$unverified = CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'fraud',
    'order_id' => 35,
    'ip' => '203.0.113.35',
]);
assertSameValue('queued', $unverified['status'], 'An unavailable pre-check may still queue the confirmed fraud add.');

$unverifiedClear = CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'cleared',
    'order_id' => 35,
    'ip' => '203.0.113.35',
]);
assertSameValue('ignored', $unverifiedClear['status'], 'An unverified listing must not grant delete ownership.');
assertSameValue(1, count(WriteQueue::getInstance()->operations), 'An unverified listing must not queue a delete.');

resetFixture();
CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'fraud',
    'order_id' => 40,
    'ip' => '203.0.113.40',
]);
$secondOwner = CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'fraud',
    'order_id' => 41,
    'ip' => '203.0.113.40',
]);
assertSameValue('owned', $secondOwner['status'], 'A second active reference must share local ownership without another add.');

$firstClear = CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'accepted',
    'order_id' => 40,
    'ip' => '203.0.113.40',
]);
assertSameValue('owned', $firstClear['status'], 'Clearing one reference must keep an IP owned by another active reference.');
assertSameValue(1, count(WriteQueue::getInstance()->operations), 'The first clear must not queue a delete.');

$lastClear = CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'accepted',
    'order_id' => 41,
    'ip' => '203.0.113.40',
]);
assertSameValue('queued', $lastClear['status'], 'Clearing the final owned reference must queue a delete.');
assertSameValue('delete', WriteQueue::getInstance()->operations[1]['action'], 'The final clear must delete the listing.');

resetFixture();
Plugin::$toolsMode = 'prod';
$sandbox = CommerceFraud::dispatch([
    'source' => 'test',
    'state' => 'fraud',
    'order_id' => 50,
    'ip' => '203.0.113.50',
], true);
assertSameValue('blocked', $sandbox['status'], 'Sandbox writes must be blocked outside Tools dev mode.');

resetFixture();
CommerceFraud::registerAdminMenu();
$submenu = $GLOBALS['dnsbl_test_submenu_args'][0] ?? [];
assertSameValue(6, count($submenu), 'The Commerce hooks submenu must use the WordPress six-argument form.');
assertSameValue('manage_options', $submenu[3] ?? null, 'The Commerce hooks submenu must require manage_options.');

echo "Commerce fraud runtime tests passed.\n";
