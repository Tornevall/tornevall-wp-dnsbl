<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stable plugin-to-plugin integration surface for optional WordPress consumers.
 *
 * The hooks are intentionally generic. Guestbooks, comments, Akismet adapters,
 * registration flows and future plugins can all use the same bridge without
 * reaching into DNSBL plugin internals.
 *
 * - tornevall_dnsbl_capabilities
 * - tornevall_dnsbl_check_ip
 * - tornevall_dnsbl_report_ip
 */
class Integration
{
    public const DEFAULT_WEB_ABUSE_BITMASK = 64;
    public const DEFAULT_GUESTBOOK_BITMASK = self::DEFAULT_WEB_ABUSE_BITMASK;

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
            'context' => is_array($context) ? $context : [],
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
            'context' => is_array($context) ? $context : [],
        ];
    }

    /**
     * Explicitly report one abusive IP through the configured DNSBL write API.
     * The bridge never reports automatically merely because another plugin
     * rejected content or classified it as spam.
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
        $context = is_array($context) ? $context : [];
        $requestedBitmask = isset($options['bitmask']) ? (int)$options['bitmask'] : self::DEFAULT_WEB_ABUSE_BITMASK;
        $requestedBitmask = max(0, min(255, $requestedBitmask)) & ~1;
        if ($requestedBitmask === 0) {
            $requestedBitmask = self::DEFAULT_WEB_ABUSE_BITMASK;
        }

        $check = self::checkIp(null, $ip, $context);
        $currentBitmask = !empty($check['ok']) && is_numeric($check['bitmask'] ?? null)
            ? (int)$check['bitmask']
            : 0;
        $bitmask = ($currentBitmask | $requestedBitmask) & ~1;

        $publicationType = strtolower(trim((string)($options['publication_type'] ?? 'dnsbl')));
        if (!in_array($publicationType, ['dnsbl', 'fraudbl', 'commerce'], true)) {
            $publicationType = 'dnsbl';
        }

        $sourceType = sanitize_key((string)($options['source_type'] ?? $context['source_type'] ?? $context['feature'] ?? 'wordpress'));
        $sourceName = sanitize_text_field((string)($options['source_name'] ?? $context['source_name'] ?? $context['consumer'] ?? 'wordpress'));
        $sourceNote = sanitize_text_field((string)($options['source_note'] ?? 'WordPress abuse report.'));
        $siteUrl = function_exists('home_url') ? trim((string)home_url('/')) : '';
        $siteHost = $siteUrl !== '' ? trim((string)wp_parse_url($siteUrl, PHP_URL_HOST)) : '';

        $result = self::publishReport([
            'ip' => $ip,
            'bitmask' => $bitmask,
            'publication_type' => $publicationType,
            'ttl' => 300,
            'dry_run' => !empty($options['dry_run']),
            'source_type' => $sourceType !== '' ? $sourceType : 'wordpress',
            'source_name' => $sourceName !== '' ? $sourceName : $siteHost,
            'source_note' => $sourceNote !== '' ? $sourceNote : 'WordPress abuse report.',
            'source_site_url' => $siteUrl,
            'source_site_host' => $siteHost,
        ]);

        return [
            'provider' => 'tornevall-wp-dnsbl',
            'available' => true,
            'ok' => !empty($result['ok']),
            'status' => (int)($result['status'] ?? 0),
            'ip' => $ip,
            'bitmask' => $bitmask,
            'publication_type' => $publicationType,
            'source_type' => $sourceType,
            'source_name' => $sourceName,
            'source_note' => $sourceNote,
            'message' => self::resultMessage($result, null),
            'error' => trim((string)($result['error'] ?? '')),
            'context' => $context,
        ];
    }

    /**
     * Send the explicit report with its source metadata so Tools can publish the
     * corresponding TXT record. This path is only reachable after the DNSBL
     * plugin has verified that its configured token is active and can add.
     *
     * @param array<string,mixed> $payload
     * @return array{ok:bool,status:int,body:array,error:string|null}
     */
    private static function publishReport(array $payload): array
    {
        $token = trim((string)Plugin::apiToken());
        $baseUrl = untrailingslashit(rtrim((string)Plugin::toolsBaseUrl(), '/'));
        if ($token === '' || $baseUrl === '') {
            return [
                'ok' => false,
                'status' => 0,
                'body' => [],
                'error' => __('DNSBL is not configured.', 'tornevall-networks-dnsbl-implementation'),
            ];
        }

        $response = wp_remote_request($baseUrl . '/api/dnsbl/records/add', [
            'method' => 'POST',
            'timeout' => 45,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Dnsbl-Token' => $token,
            ],
            'body' => wp_json_encode(array_filter($payload, static function ($value): bool {
                return $value !== null && $value !== '' && $value !== false;
            })),
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => [],
                'error' => $response->get_error_message(),
            ];
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        $rawBody = (string)wp_remote_retrieve_body($response);
        $body = json_decode($rawBody, true);
        $body = is_array($body) ? $body : ['raw' => $rawBody];
        $error = null;
        if ($status < 200 || $status >= 300) {
            $error = trim((string)($body['message'] ?? $body['reason'] ?? ''));
            if ($error === '') {
                $error = 'HTTP ' . $status;
            }
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
            'error' => $error,
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
