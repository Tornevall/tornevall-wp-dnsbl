<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stable plugin-to-plugin integration surface for Tornevall WordPress addons.
 *
 * Consumers use WordPress filters instead of reaching into DNSBL internals:
 * - tornevall_dnsbl_capabilities
 * - tornevall_dnsbl_check_ip
 * - tornevall_dnsbl_report_ip
 */
class Integration
{
    public const DEFAULT_GUESTBOOK_BITMASK = 64;

    public static function registerHooks(): void
    {
        add_filter('tornevall_dnsbl_capabilities', [self::class, 'capabilities'], 10, 2);
        add_filter('tornevall_dnsbl_check_ip', [self::class, 'checkIp'], 10, 3);
        add_filter('tornevall_dnsbl_report_ip', [self::class, 'reportIp'], 10, 4);
    }

    /**
     * @param mixed $current Existing capability payload from another provider.
     * @param mixed $context Optional consumer context.
     * @return array<string,mixed>
     */
    public static function capabilities($current = null, $context = []): array
    {
        $client = ApiClient::fromPluginOptions();
        if (!$client instanceof ApiClient) {
            return [
                'provider' => 'tornevall-wp-dnsbl',
                'installed' => true,
                'configured' => false,
                'can_check' => false,
                'can_report' => false,
                'message' => __('DNSBL is installed, but no DNSBL / Tools API token is configured.', 'tornevall-networks-dnsbl-implementation'),
            ];
        }

        $permissions = $client->getTokenPermissionSummary();

        return [
            'provider' => 'tornevall-wp-dnsbl',
            'installed' => true,
            'configured' => true,
            'can_check' => true,
            'can_report' => !empty($permissions['is_active']) && !empty($permissions['can_add']),
            'message' => trim((string)($permissions['message'] ?? '')),
            'permissions' => [
                'is_active' => !empty($permissions['is_active']),
                'can_add' => !empty($permissions['can_add']),
                'can_delete' => !empty($permissions['can_delete']),
            ],
        ];
    }

    /**
     * @param mixed $current Existing result from another integration provider.
     * @param mixed $ip IP address to inspect.
     * @param mixed $context Optional consumer context.
     * @return array<string,mixed>
     */
    public static function checkIp($current, $ip, $context = []): array
    {
        $ip = trim((string)$ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return self::errorResult('invalid_ip', __('A valid IP address is required.', 'tornevall-networks-dnsbl-implementation'));
        }

        $client = ApiClient::fromPluginOptions();
        if (!$client instanceof ApiClient) {
            return self::errorResult('not_configured', __('DNSBL is installed, but no DNSBL / Tools API token is configured.', 'tornevall-networks-dnsbl-implementation'));
        }

        $result = $client->checkIp($ip);
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $bitmask = self::extractBitmask($body);
        $listed = self::extractListed($body, $bitmask);

        return [
            'provider' => 'tornevall-wp-dnsbl',
            'available' => true,
            'ok' => !empty($result['ok']),
            'status' => (int)($result['status'] ?? 0),
            'ip' => $ip,
            'listed' => $listed,
            'bitmask' => $bitmask,
            'message' => self::resultMessage($result, $listed),
            'error' => trim((string)($result['error'] ?? '')),
        ];
    }

    /**
     * Explicitly report one abusive IP through the configured DNSBL write API.
     * This method never runs automatically from a content rejection hook.
     *
     * @param mixed $current Existing result from another integration provider.
     * @param mixed $ip IP address to report.
     * @param mixed $options Report options.
     * @param mixed $context Optional consumer context.
     * @return array<string,mixed>
     */
    public static function reportIp($current, $ip, $options = [], $context = []): array
    {
        if (!current_user_can('manage_options')) {
            return self::errorResult('forbidden', __('Administrator permission is required to report an IP address.', 'tornevall-networks-dnsbl-implementation'), 403);
        }

        $ip = trim((string)$ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return self::errorResult('invalid_ip', __('A valid IP address is required.', 'tornevall-networks-dnsbl-implementation'));
        }

        $client = ApiClient::fromPluginOptions();
        if (!$client instanceof ApiClient) {
            return self::errorResult('not_configured', __('DNSBL is installed, but no DNSBL / Tools API token is configured.', 'tornevall-networks-dnsbl-implementation'));
        }

        $permissions = $client->getTokenPermissionSummary();
        if (empty($permissions['is_active']) || empty($permissions['can_add'])) {
            return self::errorResult(
                'report_not_permitted',
                trim((string)($permissions['message'] ?? '')) ?: __('The configured DNSBL token cannot add blacklist records.', 'tornevall-networks-dnsbl-implementation'),
                403
            );
        }

        $options = is_array($options) ? $options : [];
        $bitmask = isset($options['bitmask']) ? (int)$options['bitmask'] : self::DEFAULT_GUESTBOOK_BITMASK;
        $bitmask = max(0, min(255, $bitmask)) & ~1;
        if ($bitmask === 0) {
            $bitmask = self::DEFAULT_GUESTBOOK_BITMASK;
        }

        $publicationType = strtolower(trim((string)($options['publication_type'] ?? 'dnsbl')));
        if (!in_array($publicationType, ['dnsbl', 'fraudbl', 'commerce'], true)) {
            $publicationType = 'dnsbl';
        }

        $sourceNote = sanitize_text_field((string)($options['source_note'] ?? 'Guestbook abuse reported by a WordPress administrator.'));
        $result = $client->addIp($ip, $bitmask, $publicationType, 300, [
            'dry_run' => !empty($options['dry_run']),
            'source_note' => $sourceNote,
            'source_type' => 'wordpress_guestbook',
        ]);

        return [
            'provider' => 'tornevall-wp-dnsbl',
            'available' => true,
            'ok' => !empty($result['ok']),
            'status' => (int)($result['status'] ?? 0),
            'ip' => $ip,
            'bitmask' => $bitmask,
            'publication_type' => $publicationType,
            'message' => self::resultMessage($result, null),
            'error' => trim((string)($result['error'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $body
     */
    private static function extractBitmask(array $body): ?int
    {
        foreach (['bitmask', 'combined_bitmask', 'reputation'] as $key) {
            if (isset($body[$key]) && is_numeric($body[$key])) {
                return (int)$body[$key];
            }
        }

        foreach (['result', 'lookup', 'data', 'summary'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $nested = self::extractBitmask($body[$key]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $body
     */
    private static function extractListed(array $body, ?int $bitmask): ?bool
    {
        foreach (['listed', 'is_listed'] as $key) {
            if (array_key_exists($key, $body)) {
                return (bool)$body[$key];
            }
        }

        if ($bitmask !== null) {
            return $bitmask > 0;
        }

        foreach (['result', 'lookup', 'data', 'summary'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $nested = self::extractListed($body[$key], self::extractBitmask($body[$key]));
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function resultMessage(array $result, ?bool $listed): string
    {
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $message = trim((string)($body['message'] ?? $body['reason'] ?? $result['error'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        if ($listed === true) {
            return __('The IP address is currently listed.', 'tornevall-networks-dnsbl-implementation');
        }
        if ($listed === false) {
            return __('The IP address is not currently listed.', 'tornevall-networks-dnsbl-implementation');
        }

        return !empty($result['ok'])
            ? __('DNSBL request completed.', 'tornevall-networks-dnsbl-implementation')
            : __('DNSBL request failed.', 'tornevall-networks-dnsbl-implementation');
    }

    /**
     * @return array<string,mixed>
     */
    private static function errorResult(string $reason, string $message, int $status = 400): array
    {
        return [
            'provider' => 'tornevall-wp-dnsbl',
            'available' => true,
            'ok' => false,
            'status' => $status,
            'reason' => $reason,
            'message' => $message,
            'error' => $message,
        ];
    }
}
