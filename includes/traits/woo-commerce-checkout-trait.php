<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

trait WooCommerceCheckoutTrait
{
    /**
         * Validate classic checkout before WooCommerce creates an order.
         */
    public static function validateLegacyCheckout(): void
    {
        $evaluation = self::evaluateCheckoutRequest();
        if ($evaluation === null) {
            return;
        }

        $context = self::legacyOrderContext();
        self::recordBlockedAttempt($evaluation, $context);

        $action = self::blockAction();
        $message = self::buildCustomerMessage($evaluation, true);

        if ($action === 'notice' || $action === 'both') {
            wc_add_notice($message, 'error');
        }

        if ($action === 'redirect' || $action === 'both') {
            self::redirectBlockedCustomer();
        }
    }

    /**
         * Validate Checkout Block and direct Store API checkout before payment.
         *
         * @param mixed $order WooCommerce order object.
         * @throws \Exception Always when the current checkout must be blocked.
         */
    public static function validateStoreApiCheckout($order): void
    {
        $evaluation = self::evaluateCheckoutRequest();
        if ($evaluation === null) {
            return;
        }

        $context = self::storeApiOrderContext($order);
        self::recordBlockedAttempt($evaluation, $context);

        $message = self::buildCustomerMessage($evaluation, false);
        $plainMessage = esc_html(wp_strip_all_tags($message));

        if (class_exists('\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'tornevall_dnsbl_checkout_blocked',
                $plainMessage,
                403
            );
        }

        throw new \Exception($plainMessage, 403);
    }

    /**
         * @return array{ip:string,bitmask:int,active_flags:list<string>,matched_flags:list<string>}|null
         */
    private static function evaluateCheckoutRequest(): ?array
    {
        if (!self::isEnabled() || self::isEmergencyBypassEnabled()) {
            return null;
        }

        // Store administrators can place test orders. The explicit whitelist is
        // still checked for every other customer.
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return null;
        }

        $ip = Plugin::currentVisitorIp();
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP) || Plugin::isWhitelistedIp($ip)) {
            return null;
        }

        $bitmask = (int)Plugin::checkBlacklistCache($ip);
        if ($bitmask <= 0) {
            return null;
        }

        $matchedFlags = self::matchedSelectedFlags($bitmask);
        if (!count($matchedFlags)) {
            return null;
        }

        return [
            'ip' => $ip,
            'bitmask' => $bitmask,
            'active_flags' => self::activeFlags($bitmask),
            'matched_flags' => $matchedFlags,
        ];
    }

    /**
         * @return list<string>
         */
    private static function activeFlags(int $bitmask): array
    {
        $active = [];

        foreach (Plugin::getCurrentFlagMap() as $flagName => $flagBit) {
            $flagBit = (int)$flagBit;
            if ($flagBit > 0 && ($bitmask & $flagBit) === $flagBit) {
                $active[] = $flagName;
            }
        }

        return array_values(array_unique($active));
    }

    /**
         * @return list<string>
         */
    private static function matchedSelectedFlags(int $bitmask): array
    {
        return array_values(array_intersect(self::activeFlags($bitmask), self::selectedFlags()));
    }

    /**
         * @param array{active_flags:list<string>,matched_flags:list<string>} $evaluation
         */
    private static function buildCustomerMessage(array $evaluation, bool $allowHtml): string
    {
        $parts = [self::customerMessage()];
        $parts[] = __('Contact the store support if you believe the address has been classified incorrectly.', 'tornevall-networks-dnsbl-implementation');

        $activeFlags = (array)($evaluation['active_flags'] ?? []);
        if (self::delistHintEnabled() && !self::containsHighRiskFlag($activeFlags)) {
            if (Plugin::writeTokenSet()) {
                $parts[] = __('This store has a Tornevall Networks Tools connection, but removal is not performed from checkout. The store can review the listing through its configured Tools account.', 'tornevall-networks-dnsbl-implementation');
            } else {
                $parts[] = __('Removal requests are handled through Tornevall Networks removal tools and cannot be completed from checkout.', 'tornevall-networks-dnsbl-implementation');
            }
        }

        $action = self::blockAction();
        if ($action === 'redirect' || $action === 'both') {
            $url = Plugin::getBlockedRedirectUrl();
            if ($allowHtml) {
                $parts[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('More information', 'tornevall-networks-dnsbl-implementation') . '</a>';
            } else {
                $parts[] = sprintf(__('More information: %s', 'tornevall-networks-dnsbl-implementation'), esc_url_raw($url));
            }
        }

        if ($allowHtml) {
            $escaped = [];
            foreach ($parts as $index => $part) {
                $escaped[] = $index === count($parts) - 1 && str_contains($part, '<a ')
                    ? wp_kses_post($part)
                    : esc_html($part);
            }
            return implode(' ', $escaped);
        }

        return implode(' ', array_map('wp_strip_all_tags', $parts));
    }

    /**
         * @param list<string> $flags
         */
    private static function containsHighRiskFlag(array $flags): bool
    {
        return count(array_intersect($flags, [
            'IP_PHISHING',
            'IP_FRAUDCOMMERCE',
            'IP_ABUSE_NO_SMTP',
        ])) > 0;
    }

    private static function redirectBlockedCustomer(): void
    {
        $url = Plugin::getBlockedRedirectUrl();
        if ($url === '') {
            return;
        }

        wp_safe_redirect($url, 302, 'Tornevall DNSBL');
        exit;
    }

    /**
         * @return array{order_id:int,order_total:string}
         */
    private static function legacyOrderContext(): array
    {
        $total = '';
        if (function_exists('WC') && WC() && WC()->cart) {
            $total = (string)WC()->cart->get_total('edit');
        }

        return [
            'order_id' => 0,
            'order_total' => $total,
        ];
    }

    /**
         * @param mixed $order
         * @return array{order_id:int,order_total:string}
         */
    private static function storeApiOrderContext($order): array
    {
        if (is_object($order) && method_exists($order, 'get_id') && method_exists($order, 'get_total')) {
            return [
                'order_id' => (int)$order->get_id(),
                'order_total' => (string)$order->get_total('edit'),
            ];
        }

        return [
            'order_id' => 0,
            'order_total' => '',
        ];
    }

    /**
         * @param array{ip:string,bitmask:int,active_flags:list<string>,matched_flags:list<string>} $evaluation
         * @param array{order_id:int,order_total:string} $context
         */
    private static function recordBlockedAttempt(array $evaluation, array $context): void
    {
        static $recorded = [];

        $key = md5(
            $evaluation['ip'] . '|'
            . $evaluation['bitmask'] . '|'
            . implode(',', $evaluation['matched_flags']) . '|'
            . $context['order_id']
        );
        if (isset($recorded[$key])) {
            return;
        }
        $recorded[$key] = true;

        Plugin::recordStat($evaluation['ip'], $evaluation['bitmask'], true, 'woocommerce-checkout');

        $mode = self::notifyMode();
        if ($mode === 'instant') {
            self::sendInstantNotification($evaluation, $context);
        } elseif ($mode === 'bulk') {
            self::storeBulkNotification($evaluation, $context);
        }
    }

}
