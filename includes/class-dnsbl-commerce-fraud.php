<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes commerce fraud signals from supported payment plugins and custom
 * integrations before they are translated into Tools DNSBL commerce writes.
 *
 * No payment plugin is modified by this class. It only listens to existing
 * WordPress hooks and exposes a generic action for integrations that already
 * know when a fraud state has been reached.
 */
class CommerceFraud
{
    private const OPTION_ENABLED = 'tornevall_dnsbl_commerce_fraud_enabled';
    private const OPTION_OWNERSHIP = 'tornevall_dnsbl_commerce_fraud_ownership';
    private const OPTION_EVENT_LOG = 'tornevall_dnsbl_commerce_fraud_event_log';
    private const ADMIN_SLUG = 'tornevallDnsblCommerceHooks';
    private const SAVE_ACTION = 'tornevall_dnsbl_commerce_save';
    private const SANDBOX_ACTION = 'tornevall_dnsbl_commerce_sandbox';
    private const BITMASK = 8;
    private const PUBLICATION_TYPE = 'commerce';
    private const EVENT_LOG_LIMIT = 40;

    /**
     * Register provider listeners, custom integration hooks, and the admin-only
     * sandbox surface.
     */
    public static function registerHooks(): void
    {
        // Generic integration entry point. Other plugins/themes can emit one
        // normalized event without depending on this class directly.
        add_action('tornevall_dnsbl_commerce_fraud', [self::class, 'handleGenericSignal'], 10, 1);

        // Resurs Merchant API currently does not expose a dedicated fraud value
        // through the WooCommerce plugin. This hook is intentionally ours and
        // lets trusted site-specific code/sandbox tests inject a known MAPI fraud
        // decision without changing the Resurs plugin.
        add_action('tornevall_dnsbl_resurs_mapi_fraud', [self::class, 'handleResursMapiSignal'], 10, 4);
        add_filter('resurs_payment_task_status', [self::class, 'observeResursMapiTaskStatus'], PHP_INT_MAX, 4);

        // Klarna Payments exposes explicit fraud-status processing actions.
        add_action('wc_klarna_payments_accepted', [self::class, 'handleKlarnaAccepted'], 10, 2);
        add_action('wc_klarna_payments_pending', [self::class, 'handleKlarnaPending'], 10, 2);
        add_action('wc_klarna_payments_rejected', [self::class, 'handleKlarnaRejected'], 10, 2);

        // Kustom Checkout exposes the remote order through this filter directly
        // before fraud_status is interpreted by the checkout plugin.
        add_filter('kco_wc_api_callbacks_push_klarna_order', [self::class, 'handleKustomOrder'], PHP_INT_MAX, 1);

        // Legacy Resurs SOAP/RCO integrations expose fraud/frozen in these hooks.
        // We only listen to them; no call is made to the legacy plugin/API.
        add_action('resurs_hook_orderinfo', [self::class, 'handleLegacyResursOrderInfo'], 10, 1);
        add_action('resurs_hook_callback', [self::class, 'handleLegacyResursCallback'], 10, 1);

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_' . self::SAVE_ACTION, [self::class, 'handleSettingsSave']);
        add_action('admin_post_' . self::SANDBOX_ACTION, [self::class, 'handleSandboxSubmit']);
    }

    public static function enabled(): bool
    {
        return get_option(self::OPTION_ENABLED, '0') === '1' && Plugin::writeTokenSet();
    }

    /**
     * Generic public integration entry point.
     *
     * Event keys:
     * - source: integration/provider identifier
     * - state: rejected|fraud|pending|accepted|cleared|...
     * - operation: optional add|update|remove override
     * - order_id: optional WooCommerce order ID
     * - payment_id: optional provider payment/order identifier
     * - ip: optional explicit IP; otherwise read from WC order
     * - bitmask / old_bitmask: optional mutation bitmasks (default commerce bit 8)
     * - ttl: optional TTL (minimum 300)
     */
    public static function handleGenericSignal($event): array
    {
        return self::dispatch(is_array($event) ? $event : []);
    }

    public static function handleResursMapiSignal($orderId, $state, $paymentId = '', $context = []): array
    {
        $event = is_array($context) ? $context : [];
        $event['source'] = 'resurs_mapi';
        $event['state'] = (string)$state;
        $event['order_id'] = (int)$orderId;
        $event['payment_id'] = (string)$paymentId;

        return self::dispatch($event);
    }

    /**
     * Passive listener for the current Resurs Merchant API plugin. The filter is
     * invoked while a rejected payment is translated to a WooCommerce status.
     * A rejection is not automatically fraud: a site-specific classifier can
     * return an event array through tornevall_dnsbl_resurs_mapi_fraud_signal.
     */
    public static function observeResursMapiTaskStatus($status, $taskStatusDetails, $payment, $order)
    {
        $orderId = is_object($order) && method_exists($order, 'get_id') ? (int)$order->get_id() : 0;
        $paymentId = is_object($payment) && isset($payment->id) ? (string)$payment->id : '';

        $observation = [
            'source' => 'resurs_mapi',
            'state' => 'rejected_unclassified',
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'raw_status' => (string)$status,
        ];

        $classified = apply_filters(
            'tornevall_dnsbl_resurs_mapi_fraud_signal',
            null,
            $observation,
            $taskStatusDetails,
            $payment,
            $order
        );

        if (is_array($classified)) {
            self::dispatch(array_merge($observation, $classified));
        } else {
            self::dispatch($observation);
        }

        return $status;
    }

    public static function handleKlarnaAccepted($orderId, $payload): array
    {
        return self::dispatch(self::providerEvent('klarna_payments', 'accepted', $orderId, $payload));
    }

    public static function handleKlarnaPending($orderId, $payload): array
    {
        return self::dispatch(self::providerEvent('klarna_payments', 'pending', $orderId, $payload));
    }

    public static function handleKlarnaRejected($orderId, $payload): array
    {
        return self::dispatch(self::providerEvent('klarna_payments', 'rejected', $orderId, $payload));
    }

    /**
     * Observe Kustom order payload without changing it.
     */
    public static function handleKustomOrder($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }

        $fraudStatus = strtoupper(trim((string)($payload['fraud_status'] ?? '')));
        if ($fraudStatus === '') {
            return $payload;
        }

        $providerOrderId = trim((string)($payload['order_id'] ?? ''));
        $orderId = 0;

        if ($providerOrderId !== '' && function_exists('kco_get_order_by_klarna_id')) {
            $order = kco_get_order_by_klarna_id($providerOrderId);
            if (is_object($order) && method_exists($order, 'get_id')) {
                $orderId = (int)$order->get_id();
            }
        }

        self::dispatch([
            'source' => 'kustom_checkout',
            'state' => strtolower($fraudStatus),
            'order_id' => $orderId,
            'payment_id' => $providerOrderId,
            'raw_status' => $fraudStatus,
        ]);

        return $payload;
    }

    public static function handleLegacyResursOrderInfo($content): array
    {
        return self::handleLegacyResurs($content, 'orderinfo');
    }

    public static function handleLegacyResursCallback($content): array
    {
        return self::handleLegacyResurs($content, 'callback');
    }

    private static function handleLegacyResurs($content, string $hookType): array
    {
        if (!is_array($content)) {
            return self::result('ignored', ['reason' => 'invalid_legacy_resurs_payload']);
        }

        if (!array_key_exists('fraud', $content)) {
            return self::result('ignored', ['reason' => 'legacy_resurs_fraud_missing']);
        }

        $fraud = filter_var($content['fraud'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($fraud === null) {
            $fraud = !empty($content['fraud']);
        }

        $orderId = isset($content['internalOrderId']) ? (int)$content['internalOrderId'] : 0;
        $paymentId = trim((string)($content['id'] ?? ''));

        if ($orderId <= 0 && $paymentId !== '' && function_exists('wc_get_order_id_by_payment_id')) {
            $orderId = (int)wc_get_order_id_by_payment_id($paymentId);
        }

        return self::dispatch([
            'source' => 'resurs_legacy',
            'state' => $fraud ? 'fraud' : 'cleared',
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'raw_status' => $hookType,
            'context' => [
                'frozen' => $content['frozen'] ?? null,
                'status' => $content['status'] ?? null,
                'callback' => $content['iscallback'] ?? null,
            ],
        ]);
    }

    private static function providerEvent(string $source, string $state, $orderId, $payload): array
    {
        $payload = is_array($payload) ? $payload : [];

        return [
            'source' => $source,
            'state' => $state,
            'order_id' => (int)$orderId,
            'payment_id' => trim((string)($payload['order_id'] ?? $payload['id'] ?? '')),
            'raw_status' => (string)($payload['fraud_status'] ?? $state),
        ];
    }

    /**
     * Normalize a signal and, when appropriate, queue an ADD/UPDATE/REMOVE
     * against Tools using publication_type=commerce.
     */
    public static function dispatch(array $event, bool $sandbox = false): array
    {
        $event = self::normalizeEvent($event, $sandbox);
        $event = apply_filters('tornevall_dnsbl_commerce_fraud_event', $event);

        if (!is_array($event)) {
            return self::result('ignored', ['reason' => 'event_filter_rejected']);
        }

        $event['ip'] = self::resolveEventIp($event);
        $event['operation'] = self::resolveOperation($event);
        $event['operation'] = apply_filters('tornevall_dnsbl_commerce_fraud_operation', $event['operation'], $event);

        do_action('tornevall_dnsbl_commerce_fraud_observed', $event);

        if (!$sandbox && !self::enabled()) {
            return self::recordAndReturn($event, 'ignored', 'listeners_disabled');
        }

        if ($sandbox && Plugin::toolsMode() !== 'dev') {
            return self::recordAndReturn($event, 'blocked', 'sandbox_requires_tools_dev_mode');
        }

        if ($event['operation'] === '') {
            return self::recordAndReturn($event, 'observed', 'no_dnsbl_mutation_for_state');
        }

        if (!filter_var($event['ip'], FILTER_VALIDATE_IP)) {
            return self::recordAndReturn($event, 'ignored', 'missing_valid_ip');
        }

        if (!Plugin::writeTokenSet()) {
            return self::recordAndReturn($event, 'blocked', 'no_write_token');
        }

        $allowed = (bool)apply_filters('tornevall_dnsbl_commerce_fraud_should_write', true, $event);
        if (!$allowed) {
            return self::recordAndReturn($event, 'blocked', 'write_filter_rejected');
        }

        switch ($event['operation']) {
            case 'add':
                $result = self::applyAdd($event);
                break;
            case 'remove':
                $result = self::applyRemove($event);
                break;
            case 'update':
                $result = self::applyUpdate($event);
                break;
            default:
                $result = self::result('ignored', ['reason' => 'unsupported_operation']);
                break;
        }

        $result['event'] = self::safeEventForLog($event);
        self::appendEventLog($result);
        do_action('tornevall_dnsbl_commerce_fraud_processed', $result, $event);

        return $result;
    }

    private static function normalizeEvent(array $event, bool $sandbox): array
    {
        $source = strtolower(trim((string)($event['source'] ?? 'custom')));
        $state = strtolower(trim((string)($event['state'] ?? '')));
        $operation = strtolower(trim((string)($event['operation'] ?? '')));

        if (!preg_match('/^[a-z0-9_.:-]+$/', $source)) {
            $source = 'custom';
        }

        if (!in_array($operation, ['', 'add', 'remove', 'update'], true)) {
            $operation = '';
        }

        return array_merge($event, [
            'source' => $source,
            'state' => $state,
            'operation' => $operation,
            'order_id' => max(0, (int)($event['order_id'] ?? 0)),
            'payment_id' => trim((string)($event['payment_id'] ?? '')),
            'ip' => trim((string)($event['ip'] ?? '')),
            'raw_status' => trim((string)($event['raw_status'] ?? '')),
            'bitmask' => max(1, (int)($event['bitmask'] ?? self::BITMASK)),
            'old_bitmask' => max(0, (int)($event['old_bitmask'] ?? self::BITMASK)),
            'ttl' => max(300, (int)($event['ttl'] ?? 300)),
            'sandbox' => $sandbox,
        ]);
    }

    private static function resolveOperation(array $event): string
    {
        if (in_array($event['operation'], ['add', 'remove', 'update'], true)) {
            return $event['operation'];
        }

        switch ($event['state']) {
            case 'rejected':
            case 'fraud':
            case 'confirmed_fraud':
            case 'certainly_fraud':
                return 'add';

            case 'accepted':
            case 'cleared':
            case 'confirmed_not_fraud':
                return 'remove';

            // Pending/suspected/review is intentionally observable but is not
            // published as confirmed commerce fraud in DNS.
            case 'pending':
            case 'suspected':
            case 'suspected_fraud':
            case 'review':
                return '';
        }

        return '';
    }

    private static function resolveEventIp(array $event): string
    {
        if (filter_var($event['ip'], FILTER_VALIDATE_IP)) {
            return $event['ip'];
        }

        $orderId = (int)($event['order_id'] ?? 0);
        if ($orderId <= 0 || !function_exists('wc_get_order')) {
            return '';
        }

        $order = wc_get_order($orderId);
        if (!is_object($order)) {
            return '';
        }

        if (method_exists($order, 'get_customer_ip_address')) {
            $ip = trim((string)$order->get_customer_ip_address());
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        if (method_exists($order, 'get_meta')) {
            $ip = trim((string)$order->get_meta('_customer_ip_address', true));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '';
    }

    private static function applyAdd(array $event): array
    {
        $ownership = self::ownership();
        $key = self::eventKey($event);
        $ip = $event['ip'];

        if (!empty($ownership[$key]['active']) && ($ownership[$key]['ip'] ?? '') === $ip) {
            return self::result('ignored', ['reason' => 'already_active_for_reference']);
        }

        $otherActive = self::activeOwnershipForIp($ownership, $ip, $key);
        if (!empty($otherActive)) {
            $ownership[$key] = self::ownershipRow($event, true, false);
            self::saveOwnership($ownership);
            return self::result('owned', ['reason' => 'ip_already_owned_by_another_active_reference']);
        }

        $remoteBitmask = self::currentRemoteBitmask($ip);
        $remoteAlreadyCommerce = $remoteBitmask !== null && (($remoteBitmask & self::BITMASK) === self::BITMASK);

        $ownership[$key] = self::ownershipRow($event, true, !$remoteAlreadyCommerce);
        self::saveOwnership($ownership);

        if ($remoteAlreadyCommerce) {
            return self::result('owned', [
                'reason' => 'commerce_listing_preexisted',
                'remote_bitmask' => $remoteBitmask,
            ]);
        }

        WriteQueue::getInstance()->queueAdd(
            $ip,
            (int)$event['bitmask'],
            self::PUBLICATION_TYPE,
            (int)$event['ttl']
        );

        return self::result('queued', [
            'operation' => 'add',
            'remote_bitmask_before' => $remoteBitmask,
            'remote_ownership_verified' => $remoteBitmask !== null,
        ]);
    }

    private static function applyRemove(array $event): array
    {
        $ownership = self::ownership();
        $key = self::eventKey($event);

        if (empty($ownership[$key]['active'])) {
            return self::result('ignored', ['reason' => 'reference_did_not_create_active_listing']);
        }

        $row = $ownership[$key];
        $ip = (string)($row['ip'] ?? $event['ip']);
        $remoteOwned = !empty($row['remote_owned']);

        $ownership[$key]['active'] = false;
        $ownership[$key]['updated_at'] = time();
        $ownership[$key]['last_state'] = $event['state'];

        $otherActiveKeys = self::activeOwnershipForIp($ownership, $ip, $key);
        if (!empty($otherActiveKeys)) {
            if ($remoteOwned) {
                $transferKey = reset($otherActiveKeys);
                if (is_string($transferKey) && isset($ownership[$transferKey])) {
                    $ownership[$transferKey]['remote_owned'] = true;
                }
            }
            self::saveOwnership($ownership);
            return self::result('owned', ['reason' => 'other_active_reference_still_owns_ip']);
        }

        self::saveOwnership($ownership);

        if (!$remoteOwned) {
            return self::result('ignored', ['reason' => 'remote_listing_was_not_created_by_this_plugin']);
        }

        WriteQueue::getInstance()->queueDelete(
            $ip,
            (int)($row['bitmask'] ?? self::BITMASK),
            self::PUBLICATION_TYPE
        );

        return self::result('queued', ['operation' => 'remove']);
    }

    private static function applyUpdate(array $event): array
    {
        $ownership = self::ownership();
        $key = self::eventKey($event);

        if (empty($ownership[$key]['active']) && empty($event['sandbox'])) {
            return self::result('ignored', ['reason' => 'update_requires_active_owned_reference']);
        }

        WriteQueue::getInstance()->queueUpdate(
            $event['ip'],
            (int)$event['old_bitmask'],
            (int)$event['bitmask'],
            self::PUBLICATION_TYPE,
            (int)$event['ttl']
        );

        $ownership[$key] = self::ownershipRow($event, (($event['bitmask'] & self::BITMASK) === self::BITMASK), true);
        self::saveOwnership($ownership);

        return self::result('queued', ['operation' => 'update']);
    }

    private static function currentRemoteBitmask(string $ip): ?int
    {
        $client = ApiClient::fromPluginOptions();
        if (!$client) {
            return null;
        }

        $result = $client->checkIp($ip);
        if (empty($result['ok']) || !is_array($result['body'] ?? null)) {
            return null;
        }

        return self::findIntByKey($result['body'], 'combined_bitmask');
    }

    private static function findIntByKey(array $data, string $needle): ?int
    {
        foreach ($data as $key => $value) {
            if ((string)$key === $needle && is_numeric($value)) {
                return (int)$value;
            }
            if (is_array($value)) {
                $found = self::findIntByKey($value, $needle);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private static function ownership(): array
    {
        $ownership = get_option(self::OPTION_OWNERSHIP, []);
        return is_array($ownership) ? $ownership : [];
    }

    private static function saveOwnership(array $ownership): void
    {
        // Keep inactive history bounded while preserving active ownership rows.
        uasort($ownership, static function ($a, $b): int {
            return (int)($b['updated_at'] ?? 0) <=> (int)($a['updated_at'] ?? 0);
        });

        $active = [];
        $inactive = [];
        foreach ($ownership as $key => $row) {
            if (!empty($row['active'])) {
                $active[$key] = $row;
            } elseif (count($inactive) < 100) {
                $inactive[$key] = $row;
            }
        }

        update_option(self::OPTION_OWNERSHIP, $active + $inactive, false);
    }

    private static function ownershipRow(array $event, bool $active, bool $remoteOwned): array
    {
        return [
            'source' => $event['source'],
            'order_id' => (int)$event['order_id'],
            'payment_id' => (string)$event['payment_id'],
            'ip' => (string)$event['ip'],
            'bitmask' => (int)$event['bitmask'],
            'active' => $active,
            'remote_owned' => $remoteOwned,
            'last_state' => (string)$event['state'],
            'updated_at' => time(),
        ];
    }

    /**
     * @return array<int,string> matching ownership keys
     */
    private static function activeOwnershipForIp(array $ownership, string $ip, string $excludeKey = ''): array
    {
        $keys = [];
        foreach ($ownership as $key => $row) {
            if ($key === $excludeKey || empty($row['active']) || ($row['ip'] ?? '') !== $ip) {
                continue;
            }
            $keys[] = (string)$key;
        }

        return $keys;
    }

    private static function eventKey(array $event): string
    {
        if ((int)$event['order_id'] > 0) {
            $reference = 'order:' . (int)$event['order_id'];
        } elseif ($event['payment_id'] !== '') {
            $reference = 'payment:' . $event['payment_id'];
        } else {
            $reference = 'ip:' . $event['ip'];
        }

        return sha1($event['source'] . '|' . $reference);
    }

    public static function registerAdminMenu(): void
    {
        add_submenu_page(
            'tornevallDnsblMenu',
            __('Commerce hooks', 'tornevall-networks-dnsbl-implementation'),
            __('Commerce hooks', 'tornevall-networks-dnsbl-implementation'),
            'manage_options',
            self::ADMIN_SLUG,
            [self::class, 'renderAdminPage']
        );
    }

    public static function handleSettingsSave(): void
    {
        self::requireAdmin();
        check_admin_referer(self::SAVE_ACTION);

        update_option(self::OPTION_ENABLED, empty($_POST['enabled']) ? '0' : '1', false);
        self::redirectAdmin('settings_saved');
    }

    public static function handleSandboxSubmit(): void
    {
        self::requireAdmin();
        check_admin_referer(self::SANDBOX_ACTION);

        $event = [
            'source' => sanitize_key((string)wp_unslash($_POST['source'] ?? 'custom')),
            'state' => sanitize_key((string)wp_unslash($_POST['state'] ?? 'fraud')),
            'operation' => sanitize_key((string)wp_unslash($_POST['operation'] ?? 'add')),
            'order_id' => absint($_POST['order_id'] ?? 0),
            'payment_id' => sanitize_text_field((string)wp_unslash($_POST['payment_id'] ?? '')),
            'ip' => sanitize_text_field((string)wp_unslash($_POST['ip'] ?? '')),
            'old_bitmask' => absint($_POST['old_bitmask'] ?? self::BITMASK),
            'bitmask' => absint($_POST['bitmask'] ?? self::BITMASK),
            'ttl' => absint($_POST['ttl'] ?? 300),
        ];

        $result = self::dispatch($event, true);
        if (($result['status'] ?? '') === 'queued') {
            $result['flush'] = WriteQueue::getInstance()->flush();
            self::appendEventLog([
                'status' => 'sandbox_flush',
                'reason' => !empty($result['flush']['ok']) ? 'ok' : 'failed',
                'event' => self::safeEventForLog($event),
                'flush' => $result['flush'],
            ]);
        }

        set_transient('tornevall_dnsbl_commerce_sandbox_result_' . get_current_user_id(), $result, 120);
        self::redirectAdmin('sandbox_complete');
    }

    public static function renderAdminPage(): void
    {
        self::requireAdmin();

        $enabled = get_option(self::OPTION_ENABLED, '0') === '1';
        $sandboxResult = get_transient('tornevall_dnsbl_commerce_sandbox_result_' . get_current_user_id());
        delete_transient('tornevall_dnsbl_commerce_sandbox_result_' . get_current_user_id());
        $log = get_option(self::OPTION_EVENT_LOG, []);
        $log = is_array($log) ? $log : [];

        $providers = [
            'Klarna Payments' => function_exists('kp_process_rejected') || class_exists('KP_Callbacks'),
            'Kustom Checkout' => function_exists('kco_get_order_by_klarna_id') || class_exists('KCO_API_Callbacks'),
            'Resurs Merchant API' => class_exists('Resursbank\\Woocommerce\\Modules\\Gateway\\Resursbank'),
            'Resurs legacy SOAP/RCO' => function_exists('ThirdPartyHooks') || class_exists('WC_Resurs_Bank'),
        ];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Commerce hooks', 'tornevall-networks-dnsbl-implementation'); ?></h1>
            <p><?php echo esc_html__('Listen for commerce fraud decisions from supported WooCommerce payment plugins and translate confirmed signals into the Tools commerce DNSBL flow. Payment plugins are never modified by this feature.', 'tornevall-networks-dnsbl-implementation'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::SAVE_ACTION); ?>
                <p>
                    <label>
                        <input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>>
                        <?php echo esc_html__('Enable automatic commerce fraud listeners', 'tornevall-networks-dnsbl-implementation'); ?>
                    </label>
                </p>
                <p class="description"><?php echo esc_html__('Only confirmed/rejected fraud states are added. Pending/review states are observed but are not published. A cleared/accepted state can remove a listing only when this plugin owns the active reference that created it.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                <?php submit_button(__('Save', 'tornevall-networks-dnsbl-implementation')); ?>
            </form>

            <h2><?php echo esc_html__('Detected integrations', 'tornevall-networks-dnsbl-implementation'); ?></h2>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                <?php foreach ($providers as $name => $detected) { ?>
                    <tr>
                        <td><?php echo esc_html($name); ?></td>
                        <td><?php echo esc_html($detected ? __('Detected', 'tornevall-networks-dnsbl-implementation') : __('Not detected', 'tornevall-networks-dnsbl-implementation')); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <p class="description"><?php echo esc_html__('The current Resurs Merchant API plugin does not expose a dedicated fraud value. It is therefore supported through the generic/custom MAPI signal hook and sandbox, while the legacy Resurs listener only observes the old fraud-enabled hooks when that plugin happens to be installed.', 'tornevall-networks-dnsbl-implementation'); ?></p>

            <h2><?php echo esc_html__('Sandbox', 'tornevall-networks-dnsbl-implementation'); ?></h2>
            <p><?php echo esc_html__('Sandbox events enter the same normalized event path as real listeners. Live sandbox writes are only allowed while the configured Tools environment is dev.', 'tornevall-networks-dnsbl-implementation'); ?></p>
            <?php if (Plugin::toolsMode() !== 'dev') { ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html__('Sandbox writes are locked because Tools mode is not set to dev.', 'tornevall-networks-dnsbl-implementation'); ?></p></div>
            <?php } ?>
            <?php if (is_array($sandboxResult)) { ?>
                <div class="notice notice-info inline"><p><code><?php echo esc_html(wp_json_encode($sandboxResult)); ?></code></p></div>
            <?php } ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SANDBOX_ACTION); ?>">
                <?php wp_nonce_field(self::SANDBOX_ACTION); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="commerce-source"><?php echo esc_html__('Source', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td>
                        <select id="commerce-source" name="source">
                            <option value="custom">custom</option>
                            <option value="klarna_payments">klarna_payments</option>
                            <option value="kustom_checkout">kustom_checkout</option>
                            <option value="resurs_mapi">resurs_mapi</option>
                            <option value="resurs_legacy">resurs_legacy</option>
                        </select>
                    </td></tr>
                    <tr><th><label for="commerce-operation"><?php echo esc_html__('Operation', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td>
                        <select id="commerce-operation" name="operation"><option>add</option><option>update</option><option>remove</option></select>
                    </td></tr>
                    <tr><th><label for="commerce-state"><?php echo esc_html__('State', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td><input id="commerce-state" name="state" value="fraud" class="regular-text"></td></tr>
                    <tr><th><label for="commerce-order"><?php echo esc_html__('WooCommerce order ID', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td><input id="commerce-order" name="order_id" type="number" min="0"></td></tr>
                    <tr><th><label for="commerce-payment"><?php echo esc_html__('Payment/reference ID', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td><input id="commerce-payment" name="payment_id" class="regular-text"></td></tr>
                    <tr><th><label for="commerce-ip"><?php echo esc_html__('IP address', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td><input id="commerce-ip" name="ip" class="regular-text" placeholder="203.0.113.4"><p class="description"><?php echo esc_html__('May be omitted when a WooCommerce order ID is supplied and that order has a stored customer IP.', 'tornevall-networks-dnsbl-implementation'); ?></p></td></tr>
                    <tr><th><label for="commerce-old-bitmask"><?php echo esc_html__('Old bitmask', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td><input id="commerce-old-bitmask" name="old_bitmask" type="number" min="0" value="8"></td></tr>
                    <tr><th><label for="commerce-bitmask"><?php echo esc_html__('Bitmask', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td><input id="commerce-bitmask" name="bitmask" type="number" min="1" value="8"></td></tr>
                    <tr><th><label for="commerce-ttl"><?php echo esc_html__('TTL', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td><input id="commerce-ttl" name="ttl" type="number" min="300" value="300"></td></tr>
                </table>
                <?php submit_button(__('Run sandbox event', 'tornevall-networks-dnsbl-implementation'), 'secondary'); ?>
            </form>

            <h2><?php echo esc_html__('Custom hooks', 'tornevall-networks-dnsbl-implementation'); ?></h2>
            <p><code>tornevall_dnsbl_commerce_fraud</code> - <?php echo esc_html__('generic event array', 'tornevall-networks-dnsbl-implementation'); ?></p>
            <p><code>tornevall_dnsbl_resurs_mapi_fraud</code> - <?php echo esc_html__('order ID, state, payment ID, context array', 'tornevall-networks-dnsbl-implementation'); ?></p>

            <h2><?php echo esc_html__('Recent events', 'tornevall-networks-dnsbl-implementation'); ?></h2>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr><th><?php echo esc_html__('Time', 'tornevall-networks-dnsbl-implementation'); ?></th><th><?php echo esc_html__('Source', 'tornevall-networks-dnsbl-implementation'); ?></th><th><?php echo esc_html__('State', 'tornevall-networks-dnsbl-implementation'); ?></th><th><?php echo esc_html__('Operation', 'tornevall-networks-dnsbl-implementation'); ?></th><th><?php echo esc_html__('IP', 'tornevall-networks-dnsbl-implementation'); ?></th><th><?php echo esc_html__('Result', 'tornevall-networks-dnsbl-implementation'); ?></th></tr></thead>
                <tbody>
                <?php if (empty($log)) { ?>
                    <tr><td colspan="6"><?php echo esc_html__('No commerce events recorded yet.', 'tornevall-networks-dnsbl-implementation'); ?></td></tr>
                <?php } else { foreach ($log as $row) { $e = is_array($row['event'] ?? null) ? $row['event'] : []; ?>
                    <tr>
                        <td><?php echo esc_html(isset($row['time']) ? gmdate('Y-m-d H:i:s', (int)$row['time']) . ' UTC' : ''); ?></td>
                        <td><?php echo esc_html((string)($e['source'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($e['state'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($e['operation'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($e['ip'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($row['status'] ?? '') . ((string)($row['reason'] ?? '') !== '' ? ': ' . (string)$row['reason'] : '')); ?></td>
                    </tr>
                <?php }} ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function appendEventLog(array $row): void
    {
        $log = get_option(self::OPTION_EVENT_LOG, []);
        $log = is_array($log) ? $log : [];
        array_unshift($log, array_merge(['time' => time()], $row));
        $log = array_slice($log, 0, self::EVENT_LOG_LIMIT);
        update_option(self::OPTION_EVENT_LOG, $log, false);
    }

    private static function recordAndReturn(array $event, string $status, string $reason): array
    {
        $result = [
            'status' => $status,
            'reason' => $reason,
            'event' => self::safeEventForLog($event),
        ];
        self::appendEventLog($result);
        do_action('tornevall_dnsbl_commerce_fraud_processed', $result, $event);
        return $result;
    }

    private static function safeEventForLog(array $event): array
    {
        return [
            'source' => (string)($event['source'] ?? ''),
            'state' => (string)($event['state'] ?? ''),
            'operation' => (string)($event['operation'] ?? ''),
            'order_id' => (int)($event['order_id'] ?? 0),
            'payment_id' => (string)($event['payment_id'] ?? ''),
            'ip' => (string)($event['ip'] ?? ''),
            'raw_status' => (string)($event['raw_status'] ?? ''),
            'bitmask' => (int)($event['bitmask'] ?? self::BITMASK),
            'old_bitmask' => (int)($event['old_bitmask'] ?? self::BITMASK),
            'sandbox' => !empty($event['sandbox']),
        ];
    }

    private static function result(string $status, array $extra = []): array
    {
        return array_merge(['status' => $status], $extra);
    }

    private static function requireAdmin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'tornevall-networks-dnsbl-implementation'), '', ['response' => 403]);
        }
    }

    private static function redirectAdmin(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => self::ADMIN_SLUG,
            'commerce_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }
}
