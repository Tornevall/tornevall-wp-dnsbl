<?php

$root = dirname(__DIR__);
$observerFile = $root . '/includes/class-dnsbl-woocommerce-gateway-observer.php';
$source = file_get_contents($observerFile);

if ($source === false) {
    fwrite(STDERR, "Unable to read WooCommerce gateway observer.\n");
    exit(1);
}

$requiredHooks = [
    'woocommerce_checkout_order_processed',
    'woocommerce_store_api_checkout_order_processed',
    'woocommerce_payment_complete',
    'woocommerce_order_status_changed',
    'tornevall_dnsbl_woocommerce_gateway_inventory',
];

foreach ($requiredHooks as $hook) {
    if (strpos($source, $hook) === false) {
        fwrite(STDERR, "Missing generic WooCommerce hook: {$hook}\n");
        exit(1);
    }
}

$requiredStates = [
    'checkout_processed',
    'payment_complete',
    'order_status_changed',
];

foreach ($requiredStates as $state) {
    if (strpos($source, "'{$state}'") === false) {
        fwrite(STDERR, "Missing neutral WooCommerce observation state: {$state}\n");
        exit(1);
    }
}

$forbiddenAutomaticFraudStates = [
    "'state' => 'rejected'",
    "'state' => 'accepted'",
    "'state' => 'fraud'",
    "'operation' => 'add'",
    "'operation' => 'remove'",
];

foreach ($forbiddenAutomaticFraudStates as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        fwrite(STDERR, "Gateway-neutral observer must not publish fraud automatically: {$forbidden}\n");
        exit(1);
    }
}

if (strpos($source, "'operation' => ''") === false) {
    fwrite(STDERR, "Gateway-neutral observer must emit observation-only events by default.\n");
    exit(1);
}

echo "WooCommerce gateway-neutral observer checks passed.\n";
