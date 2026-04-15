<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bulk write queue for DNSBL add/delete/update operations.
 *
 * Callers queue individual operations via queueAdd() / queueDelete() /
 * queueUpdate(). On WordPress `shutdown`, all queued operations are flushed
 * as a single bulk request to the Tools DNSBL API (/api/dnsbl/records/bulk).
 *
 * This prevents per-request DNS API calls from causing performance issues in
 * high-throughput contexts (many Akismet/spam checks per page load).
 *
 * Usage:
 *   WriteQueue::getInstance()->queueAdd('1.2.3.4', 64);
 *   // The queue is flushed automatically on shutdown.
 *   // Or force an early flush:
 *   WriteQueue::getInstance()->flush();
 */
class WriteQueue
{
    /** @var self|null */
    private static $instance = null;

    /** @var list<array<string,mixed>> */
    private $operations = [];

    /** @var bool */
    private $shutdownRegistered = false;

    /** @var bool */
    private $flushed = false;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    // ─── Queue operations ─────────────────────────────────────────────────────

    /**
     * Queue an IP add / listing request.
     *
     * Upgrade-only is enforced server-side: the new bitmask must be strictly
     * greater than the current DNS value for the IP.
     */
    public function queueAdd(string $ip, int $bitmask, string $publicationType = 'dnsbl', int $ttl = 300): void
    {
        if (!$this->isValidIp($ip) || $bitmask <= 0) {
            return;
        }

        $this->enqueue([
            'action' => 'add',
            'ip' => $ip,
            'bitmask' => $bitmask,
            'publication_type' => $publicationType,
            'ttl' => max(300, $ttl),
        ]);
    }

    /**
     * Queue an IP delete / delist request.
     */
    public function queueDelete(string $ip, int $bitmask, string $publicationType = 'dnsbl'): void
    {
        if (!$this->isValidIp($ip)) {
            return;
        }

        $this->enqueue([
            'action' => 'delete',
            'ip' => $ip,
            'bitmask' => $bitmask,
            'publication_type' => $publicationType,
        ]);
    }

    /**
     * Queue an IP update (bitmask change, can increase or decrease).
     */
    public function queueUpdate(
        string $ip,
        int $oldBitmask,
        int $newBitmask,
        string $publicationType = 'dnsbl',
        int $ttl = 300
    ): void {
        if (!$this->isValidIp($ip)) {
            return;
        }

        $this->enqueue([
            'action' => 'update',
            'ip' => $ip,
            'bitmask' => $newBitmask,
            'old_bitmask' => $oldBitmask,
            'publication_type' => $publicationType,
            'ttl' => max(300, $ttl),
        ]);
    }

    // ─── Flush ────────────────────────────────────────────────────────────────

    /**
     * Flush all queued operations as a single bulk API request.
     *
     * @return array{ok:bool, skipped?:string, error?:string, result?:array}
     */
    public function flush(): array
    {
        if (empty($this->operations)) {
            return ['ok' => true, 'skipped' => 'empty_queue'];
        }

        if ($this->flushed) {
            // Prevent double-flush on shutdown if flush() was already called explicitly.
            return ['ok' => true, 'skipped' => 'already_flushed'];
        }

        $this->flushed = true;
        $operations = $this->operations;
        $this->operations = [];

        $client = ApiClient::fromPluginOptions();
        if (!$client) {
            return ['ok' => false, 'error' => 'no_write_token'];
        }

        // Bulk if > 1 operation, single call otherwise.
        if (count($operations) === 1) {
            $op = $operations[0];
            $action = $op['action'] ?? 'add';

            if ($action === 'add') {
                $result = $client->addIp(
                    $op['ip'],
                    $op['bitmask'],
                    $op['publication_type'] ?? 'dnsbl',
                    $op['ttl'] ?? 300
                );
            } elseif ($action === 'delete') {
                $result = $client->removeIp(
                    $op['ip'],
                    $op['bitmask'],
                    $op['publication_type'] ?? 'dnsbl'
                );
            } else {
                $result = $client->updateIp(
                    $op['ip'],
                    $op['old_bitmask'] ?? 0,
                    $op['bitmask'],
                    $op['publication_type'] ?? 'dnsbl',
                    $op['ttl'] ?? 300
                );
            }

            return ['ok' => (bool) $result['ok'], 'result' => $result];
        }

        $result = $client->bulkOperation($operations);

        return ['ok' => (bool) $result['ok'], 'result' => $result];
    }

    /**
     * Flush callback used for the WordPress shutdown action.
     */
    public static function shutdownFlush(): void
    {
        $instance = self::$instance;
        if ($instance && !empty($instance->operations) && !$instance->flushed) {
            $instance->flush();
        }
    }

    /**
     * Number of currently queued operations.
     */
    public function pendingCount(): int
    {
        return count($this->operations);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $op */
    private function enqueue(array $op): void
    {
        // Reset the flushed flag so a new batch can be sent after an explicit flush.
        $this->flushed = false;
        $this->operations[] = $op;
        $this->ensureShutdownRegistered();
    }

    private function ensureShutdownRegistered(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        add_action('shutdown', [self::class, 'shutdownFlush'], PHP_INT_MAX);
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}

