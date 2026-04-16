<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTTP client for the Tools DNSBL write API.
 *
 * Used by WordPress plugin to submit IP add/delete/update operations to the
 * Tools DNSBL publication API at /api/dnsbl/records/*.
 * Auth: X-Dnsbl-Token header carrying the plugin's configured API token.
 * Dedicated DNSBL tokens remain the normal delegated model, while active
 * admin-owned Tools tokens are also accepted and report automatic DNSBL access.
 */
class ApiClient
{
    /** @var string */
    private $writeToken;

    /** @var string */
    private $baseUrl;

    /** @var int Timeout in seconds per write/info HTTP request */
    private $timeout = 45;

    /** @var int Timeout in seconds for checker follow-up requests */
    private $checkTimeout = 30;

    public function __construct(string $writeToken, string $baseUrl)
    {
        $this->writeToken = $writeToken;
        $this->baseUrl = untrailingslashit(rtrim($baseUrl, '/'));
    }

    // ─── Factory ──────────────────────────────────────────────────────────────

    /**
     * Build from current plugin options.
     * Returns null when no plugin API token is configured.
     */
    public static function fromPluginOptions(): ?self
    {
        $token = Plugin::apiToken();
        if ($token === '') {
            return null;
        }

        return new self($token, Plugin::toolsBaseUrl());
    }

    /**
     * Add or upgrade an IP listing.
     *
     * Note: upgrade-only is enforced server-side for non-admin tokens. The new
     * bitmask must be strictly greater than the current DNS value for the IP.
     *
     * @param string $publicationType dnsbl|fraudbl|commerce (default: dnsbl)
     * @param int $ttl Record TTL in seconds (min 300)
     * @return array{ok:bool, status:int, body:array, error:string|null}
     */
    public function addIp(string $ip, int $bitmask, string $publicationType = 'dnsbl', int $ttl = 300, array $options = []): array
    {
        $payload = [
            'ip' => $ip,
            'bitmask' => $bitmask,
            'publication_type' => $publicationType,
            'ttl' => max(300, $ttl),
        ];

        if (!empty($options['dry_run'])) {
            $payload['dry_run'] = true;
        }

        return $this->apiRequest('/api/dnsbl/records/add', $payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool, status:int, body:array, error:string|null}
     */
    private function apiRequest(string $path, array $payload, ?int $timeout = null): array
    {
        $url = $this->baseUrl . $path;
        $timeoutSeconds = $timeout !== null ? max(1, (int)$timeout) : $this->timeout;

        $args = [
            'method' => 'POST',
            'timeout' => $timeoutSeconds,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Dnsbl-Token' => $this->writeToken,
            ],
            'body' => wp_json_encode($payload),
        ];

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $timedOut = $this->isTimeoutError($response);
            return [
                'ok' => false,
                'status' => 0,
                'body' => [],
                'error' => $response->get_error_message(),
                'timed_out' => $timedOut,
            ];
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $body = json_decode($rawBody, true);

        // Build a user-facing error string that never leaks "HTTP 419: session/security…"
        // because that phrasing implies a WordPress session issue to the user.
        // When the remote body already contains a message key, prefer that over any
        // synthesised string. For 419 specifically (remote CSRF/auth), use a message
        // that makes clear this is a remote-API issue, not a local WP session problem.
        $errorText = null;
        if ($status >= 400) {
            $bodyMessage = is_array($body) ? (string)($body['message'] ?? $body['reason'] ?? '') : '';
            if ($bodyMessage !== '') {
                $errorText = 'HTTP ' . $status . ': ' . $bodyMessage;
            } elseif ($status === 419) {
                $errorText = __('The DNSBL request was rejected by the Tools API (remote authentication error). Please verify your DNSBL token is valid and has the required permissions.', 'tornevall-networks-dnsbl-implementation');
            } else {
                $errorText = 'HTTP ' . $status;
            }
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => is_array($body) ? $body : ['raw' => $rawBody],
            'error' => $errorText,
            'timed_out' => false,
        ];
    }

    // ─── Operations ───────────────────────────────────────────────────────────

    private function isTimeoutError($error): bool
    {
        if (!is_wp_error($error)) {
            return false;
        }

        $message = strtolower(trim((string)$error->get_error_message()));
        if ($message === '') {
            return false;
        }

        return strpos($message, 'timed out') !== false
            || strpos($message, 'timeout') !== false
            || strpos($message, 'curl error 28') !== false;
    }

    /**
     * Delete multiple listed IPs sequentially.
     *
     * This is intentionally slower than bulk mode, but it makes CIDR delist
     * progress/failure boundaries much clearer and avoids sending a whole block
     * back to Tools in one chunked batch.
     *
     * @param list<string> $ips
     * @return array{ok:bool,status:int,body:array,error:string|null,timed_out?:bool}
     */
    public function removeIpsSequentially(array $ips, int $bitmask, string $publicationType = 'dnsbl', array $options = []): array
    {
        $normalizedIps = array_values(array_unique(array_filter(array_map(static function ($ip) {
            return filter_var($ip, FILTER_VALIDATE_IP) ? (string)$ip : '';
        }, $ips))));

        if (empty($normalizedIps)) {
            return [
                'ok' => true,
                'status' => 200,
                'body' => [
                    'ok' => true,
                    'message' => __('No CIDR delist targets were left after IP normalization.', 'tornevall-networks-dnsbl-implementation'),
                    'operation_count' => 0,
                    'target_count' => 0,
                    'completed_target_count' => 0,
                    'operation_results' => [],
                    'sequential' => true,
                    'skipped' => 'empty_after_normalization',
                ],
                'error' => null,
                'timed_out' => false,
            ];
        }

        $operationResults = [];
        $completedTargetCount = 0;

        foreach ($normalizedIps as $targetIp) {
            $deleteResult = $this->removeIp($targetIp, $bitmask, $publicationType, $options);
            $operationResults[] = [
                'ip' => $targetIp,
                'result' => $deleteResult,
            ];

            if (empty($deleteResult['ok'])) {
                $timedOut = !empty($deleteResult['timed_out']);

                return [
                    'ok' => false,
                    'status' => $timedOut ? 504 : (int)($deleteResult['status'] ?? 500),
                    'body' => [
                        'ok' => false,
                        'message' => $timedOut
                            ? __('CIDR delist request timed out while waiting for Tools during one of the sequential delete calls. Already submitted IP deletes may still finish in the background, so re-check the listed hit list before retrying immediately.', 'tornevall-networks-dnsbl-implementation')
                            : __('CIDR delist request failed during one of the sequential delete calls.', 'tornevall-networks-dnsbl-implementation'),
                        'failed_ip' => $targetIp,
                        'target_count' => count($normalizedIps),
                        'completed_target_count' => $completedTargetCount,
                        'operation_count' => count($operationResults),
                        'operation_results' => $operationResults,
                        'sequential' => true,
                        'timed_out' => $timedOut,
                    ],
                    'error' => (string)($deleteResult['error'] ?? ''),
                    'timed_out' => $timedOut,
                ];
            }

            $completedTargetCount++;
        }

        return [
            'ok' => true,
            'status' => 200,
            'body' => [
                'ok' => true,
                'message' => __('CIDR delist request accepted.', 'tornevall-networks-dnsbl-implementation'),
                'target_count' => count($normalizedIps),
                'completed_target_count' => $completedTargetCount,
                'operation_count' => count($operationResults),
                'operation_results' => $operationResults,
                'sequential' => true,
            ],
            'error' => null,
            'timed_out' => false,
        ];
    }

    /**
     * Delist / remove an IP entry.
     *
     * @param string $publicationType dnsbl|fraudbl|commerce
     * @return array{ok:bool, status:int, body:array, error:string|null}
     */
    public function removeIp(string $ip, int $bitmask, string $publicationType = 'dnsbl', array $options = []): array
    {
        $payload = [
            'ip' => $ip,
            'bitmask' => $bitmask,
            'publication_type' => $publicationType,
        ];

        if (!empty($options['dry_run'])) {
            $payload['dry_run'] = true;
        }

        return $this->apiRequest('/api/dnsbl/records/delete', $payload);
    }

    /**
     * Update an IP entry (changes bitmask). Unlike add, update allows decreasing
     * the bitmask. Internally executes delete old + add new.
     *
     * @return array{ok:bool, status:int, body:array, error:string|null}
     */
    public function updateIp(
        string $ip,
        int    $oldBitmask,
        int    $newBitmask,
        string $publicationType = 'dnsbl',
        int    $ttl = 300,
        array  $options = []
    ): array
    {
        $payload = [
            'ip' => $ip,
            'bitmask' => $newBitmask,
            'old_bitmask' => $oldBitmask,
            'publication_type' => $publicationType,
            'ttl' => max(300, $ttl),
        ];

        if (!empty($options['dry_run'])) {
            $payload['dry_run'] = true;
        }

        return $this->apiRequest('/api/dnsbl/records/update', $payload);
    }

    /**
     * Bulk DNSBL operations (add, delete, or update) in a single HTTP call.
     *
     * Each operation is an associative array:
     * [
     *   'action'           => 'add|delete|update',
     *   'ip'               => '1.2.3.4',
     *   'bitmask'          => 64,
     *   'publication_type' => 'dnsbl',   // optional
     *   'ttl'              => 300,        // optional, add/update only
     *   'old_bitmask'      => 32,         // required for update
     * ]
     *
     * @param list<array<string,mixed>> $operations
     * @return array{ok:bool, status:int, body:array, error:string|null}
     */
    public function bulkOperation(array $operations, array $options = []): array
    {
        // Normalize and guard the operations array.
        $normalized = [];
        foreach ($operations as $op) {
            if (!isset($op['action'], $op['ip'], $op['bitmask'])) {
                continue;
            }
            if (!filter_var($op['ip'], FILTER_VALIDATE_IP)) {
                continue;
            }
            $normalized[] = [
                'action' => strtolower((string)$op['action']),
                'ip' => (string)$op['ip'],
                'bitmask' => (int)$op['bitmask'],
                'publication_type' => (string)($op['publication_type'] ?? 'dnsbl'),
                'ttl' => (int)($op['ttl'] ?? 300),
                'old_bitmask' => isset($op['old_bitmask']) ? (int)$op['old_bitmask'] : null,
            ];
        }

        if (empty($normalized)) {
            return ['ok' => true, 'status' => 200, 'body' => ['skipped' => 'empty_after_normalization'], 'error' => null];
        }

        $payload = ['operations' => $normalized];
        if (!empty($options['dry_run'])) {
            $payload['dry_run'] = true;
        }

        return $this->apiRequest('/api/dnsbl/records/bulk', $payload);
    }

    // ─── HTTP layer ───────────────────────────────────────────────────────────

    /**
     * @return array{ok:bool,status:int,body:array,token:array,message:string,error:string,has_token:bool,is_active:bool,can_add:bool,can_delete:bool,can_update:bool}
     */
    public function getTokenPermissionSummary(): array
    {
        return self::normalizeTokenInfoResult($this->getTokenInfo(), $this->writeToken !== '');
    }

    /**
     * @param array{ok?:bool,status?:int,body?:array,error?:string|null} $result
     * @return array{ok:bool,status:int,body:array,token:array,message:string,error:string,has_token:bool,is_active:bool,can_add:bool,can_delete:bool,can_update:bool}
     */
    public static function normalizeTokenInfoResult(array $result, bool $hasToken = true): array
    {
        if (!$hasToken) {
            return self::emptyTokenPermissionSummary();
        }

        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $token = is_array($body['token'] ?? null) ? $body['token'] : [];
        $message = trim((string)($body['message'] ?? $result['error'] ?? ''));
        $statusLabel = strtolower((string)($token['status'] ?? ''));
        $isActive = !empty($result['ok']) && $statusLabel === 'active';
        $canAdd = $isActive && !empty($token['can_add']);
        $canDelete = $isActive && !empty($token['can_delete']);

        if ($message === '' && empty($result['ok'])) {
            $message = __('The configured DNSBL / Tools API token could not be verified right now.', 'tornevall-networks-dnsbl-implementation');
        }

        if ($message === '' && !$isActive && $statusLabel !== '') {
            $message = sprintf(
                __('The configured DNSBL token is currently %s, so live DNSBL writes are unavailable.', 'tornevall-networks-dnsbl-implementation'),
                $statusLabel
            );
        }

        if ($message === '' && empty($token)) {
            $message = __('The DNSBL token-info response did not contain any permission details.', 'tornevall-networks-dnsbl-implementation');
        }

        return [
            'ok' => !empty($result['ok']),
            'status' => (int)($result['status'] ?? 0),
            'body' => $body,
            'token' => $token,
            'message' => $message,
            'error' => trim((string)($result['error'] ?? $message)),
            'has_token' => true,
            'is_active' => $isActive,
            'can_add' => $canAdd,
            'can_delete' => $canDelete,
            'can_update' => $canAdd && $canDelete,
        ];
    }

    /**
     * @return array{ok:bool,status:int,body:array,token:array,message:string,error:string,has_token:bool,is_active:bool,can_add:bool,can_delete:bool,can_update:bool}
     */
    public static function emptyTokenPermissionSummary(string $message = ''): array
    {
        $message = $message !== ''
            ? $message
            : __('No DNSBL / Tools API token is configured.', 'tornevall-networks-dnsbl-implementation');

        return [
            'ok' => false,
            'status' => 0,
            'body' => [],
            'token' => [],
            'message' => $message,
            'error' => $message,
            'has_token' => false,
            'is_active' => false,
            'can_add' => false,
            'can_delete' => false,
            'can_update' => false,
        ];
    }

    /**
     * Fetch permission/scope info for the configured plugin API token.
     *
     * Returns the raw API response array (ok, status, body, error).
     * `body.token` contains: name, status, is_admin_token, allow_add, allow_delete,
     * can_add, can_delete, scope_label, zones, approved_at.
     *
     * @return array{ok:bool, status:int, body:array, error:string|null}
     */
    public function getTokenInfo(): array
    {
        $url = $this->baseUrl . '/api/dnsbl/token/info';

        $args = [
            'method' => 'GET',
            'timeout' => $this->timeout,
            'headers' => [
                'Accept' => 'application/json',
                'X-Dnsbl-Token' => $this->writeToken,
            ],
        ];

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => [],
                'error' => $response->get_error_message(),
            ];
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $body = json_decode($rawBody, true);

        $tokenInfoBodyMsg = is_array($body) ? (string)($body['message'] ?? $body['reason'] ?? '') : '';
        $tokenInfoError = null;
        if ($status >= 400) {
            if ($tokenInfoBodyMsg !== '') {
                $tokenInfoError = 'HTTP ' . $status . ': ' . $tokenInfoBodyMsg;
            } elseif ($status === 419) {
                $tokenInfoError = __('The token info request was rejected by the Tools API (remote authentication error). Please verify your DNSBL token configuration.', 'tornevall-networks-dnsbl-implementation');
            } else {
                $tokenInfoError = 'HTTP ' . $status;
            }
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => is_array($body) ? $body : ['raw' => $rawBody],
            'error' => $tokenInfoError,
        ];
    }

    /**
     * Inspect one IP through the Tools DNSBL check endpoint.
     *
     * Returns live DNS lookup info plus token-aware delist candidates when the
     * configured token/session can expose them.
     *
     * @return array{ok:bool, status:int, body:array, error:string|null}
     */
    public function checkIp(string $ip): array
    {
        return $this->apiRequest('/api/dnsbl/check-ip', [
            'ip' => $ip,
        ], $this->checkTimeout);
    }
}

