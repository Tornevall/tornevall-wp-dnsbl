<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gateway-neutral WooCommerce lifecycle observer.
 *
 * This class deliberately does not classify normal payment failures or order
 * status changes as fraud. It emits normalized commerce observations for every
 * WooCommerce gateway and lets explicit provider adapters or trusted filters
 * promote an observation to a fraud operation when they have real fraud data.
 */
class WooCommerceGatewayObserver
{
    public static function registerHooks(): void
    {
        add_action('woocommerce_checkout_order_processed', [self::class, 'observeClassicCheckout'], 20, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'observeStoreApiCheckout'], 20, 1);
        add_action('woocommerce_payment_complete', [self::class, 'observePaymentComplete'], 20, 2);
        add_action('woocommerce_order_status_changed', [self::class, 'observeOrderStatusChanged'], 20, 4);

        add_filter('tornevall_dnsbl_woocommerce_gateway_inventory', [self::class, 'filterGatewayInventory'], 10, 1);
    }

    /**
     * Classic shortcode checkout.
     *
     * @param int   $orderId WooCommerce order ID.
     * @param array $postedData Checkout request data.
     * @param mixed $order WC_Order when available.
     */
    public static function observeClassicCheckout($orderId, $postedData, $order): void
    {
        if (!is_object($order) && function_exists('wc_get_order')) {
            $order = wc_get_order((int)$orderId);
        }

        self::observeOrder($order, 'checkout_processed', 'classic_checkout');
    }

    /**
     * Checkout Blocks / Store API checkout.
     *
     * @param mixed $order WC_Order.
     */
    public static function observeStoreApiCheckout($order): void
    {
        self::observeOrder($order, 'checkout_processed', 'store_api_checkout');
    }

    /**
     * Generic WooCommerce payment completion hook used across gateways.
     *
     * @param int    $orderId WooCommerce order ID.
     * @param string $transactionId Transaction ID when supplied by the gateway.
     */
    public static function observePaymentComplete($orderId, $transactionId = ''): void
    {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order((int)$orderId);
        self::observeOrder($order, 'payment_complete', 'payment_complete', (string)$transactionId);
    }

    /**
     * Generic order status transition for every WooCommerce payment gateway.
     *
     * @param int    $orderId WooCommerce order ID.
     * @param string $from Previous status.
     * @param string $to New status.
     * @param mixed  $order WC_Order.
     */
    public static function observeOrderStatusChanged($orderId, $from, $to, $order): void
    {
        if (!is_object($order) && function_exists('wc_get_order')) {
            $order = wc_get_order((int)$orderId);
        }

        $from = sanitize_key((string)$from);
        $to = sanitize_key((string)$to);

        self::observeOrder(
            $order,
            'order_status_changed',
            $from . '->' . $to
        );
    }

    /**
     * Return all registered WooCommerce payment gateways in a normalized form.
     *
     * This is exposed through the tornevall_dnsbl_woocommerce_gateway_inventory
     * filter so the admin UI and future gateway adapters can consume one stable
     * inventory without knowing WooCommerce internals.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function gatewayInventory(): array
    {
        if (!function_exists('WC')) {
            return [];
        }

        $woocommerce = WC();
        if (!is_object($woocommerce) || !isset($woocommerce->payment_gateways)) {
            return [];
        }

        $registry = $woocommerce->payment_gateways;
        if (!is_object($registry) || !method_exists($registry, 'payment_gateways')) {
            return [];
        }

        $gateways = $registry->payment_gateways();
        if (!is_array($gateways)) {
            return [];
        }

        $inventory = [];

        foreach ($gateways as $key => $gateway) {
            if (!is_object($gateway)) {
                continue;
            }

            $id = self::gatewayId($gateway, (string)$key);
            if ($id === '') {
                continue;
            }

            $inventory[$id] = [
                'id' => $id,
                'title' => self::gatewayTitle($gateway),
                'enabled' => isset($gateway->enabled) ? ((string)$gateway->enabled === 'yes') : null,
                'class' => get_class($gateway),
                'supports' => isset($gateway->supports) && is_array($gateway->supports)
                    ? array_values(array_map('strval', $gateway->supports))
                    : [],
            ];
        }

        ksort($inventory);
        return $inventory;
    }

    public static function filterGatewayInventory($inventory): array
    {
        $current = is_array($inventory) ? $inventory : [];
        return array_replace($current, self::gatewayInventory());
    }

    private static function observeOrder($order, string $state, string $rawStatus, string $transactionId = ''): void
    {
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            return;
        }

        $gatewayId = self::gatewayId($order);
        $paymentId = trim($transactionId);

        if ($paymentId === '' && method_exists($order, 'get_transaction_id')) {
            $paymentId = trim((string)$order->get_transaction_id());
        }

        $ip = '';
        if (method_exists($order, 'get_customer_ip_address')) {
            $ip = trim((string)$order->get_customer_ip_address());
        }

        CommerceFraud::dispatch([
            'source' => 'woocommerce_gateway:' . ($gatewayId !== '' ? $gatewayId : 'unknown'),
            'state' => $state,
            'operation' => '',
            'order_id' => (int)$order->get_id(),
            'payment_id' => $paymentId,
            'ip' => $ip,
            'raw_status' => $rawStatus,
            'context' => [
                'gateway_id' => $gatewayId,
                'gateway_title' => self::gatewayTitle($order),
            ],
        ]);
    }

    private static function gatewayId($object, string $fallback = ''): string
    {
        $id = '';

        if (is_object($object) && method_exists($object, 'get_payment_method')) {
            $id = (string)$object->get_payment_method();
        } elseif (is_object($object) && isset($object->id)) {
            $id = (string)$object->id;
        }

        if ($id === '') {
            $id = $fallback;
        }

        return sanitize_key($id);
    }

    private static function gatewayTitle($object): string
    {
        if (is_object($object) && method_exists($object, 'get_payment_method_title')) {
            return trim((string)$object->get_payment_method_title());
        }

        if (is_object($object) && method_exists($object, 'get_title')) {
            return trim((string)$object->get_title());
        }

        if (is_object($object) && isset($object->title)) {
            return trim((string)$object->title);
        }

        return '';
    }
}
