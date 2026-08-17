<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

class FraudMock
{
    private const ENTRIES_OPTION = 'tornevall_dnsbl_fraud_mock_entries';
    private const RUNTIME_OPTION = 'tornevall_dnsbl_fraud_mock_runtime';
    private const PAGE_SLUG = 'tornevallDnsblFraudMock';
    private const STATUSES = [
        'SUSPECTED_FRAUD',
        'CONFIRMED_FRAUD',
        'CONFIRMED_NOT_FRAUD',
        'CERTAINLY_FRAUD',
    ];

    public static function registerHooks(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu'], 20);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'tornevallDnsblMenu',
            __('Fraud Mock', 'tornevall-networks-dnsbl-implementation'),
            __('Fraud Mock', 'tornevall-networks-dnsbl-implementation'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    /**
     * Resolve a configured mock identifier.
     * Timed transitions start on the first lookup and persist until reset.
     *
     * @return array<string,mixed>|null
     */
    public static function resolve(string $identifier, ?int $now = null): ?array
    {
        $matchKey = self::matchKey($identifier);
        if ($matchKey === '') {
            return null;
        }

        $entry = self::findByMatchKey($matchKey);
        if ($entry === null) {
            return null;
        }

        $currentStatus = (string)$entry['initial_status'];
        $elapsed = null;
        $timestamp = $now ?? time();

        if ($entry['transition_mode'] === 'after' && $entry['transition_status'] !== '') {
            $runtime = self::runtime();
            $startedAt = isset($runtime[$entry['id']]) ? (int)$runtime[$entry['id']] : $timestamp;
            if (!isset($runtime[$entry['id']])) {
                $runtime[$entry['id']] = $startedAt;
                self::saveRuntime($runtime);
            }

            $elapsed = max(0, $timestamp - $startedAt);
            if ($elapsed >= (int)$entry['delay_seconds']) {
                $currentStatus = (string)$entry['transition_status'];
            }
        }

        return array_merge($entry, [
            'current_status' => $currentStatus,
            'elapsed_seconds' => $elapsed,
        ]);
    }

    public static function renderPage(): void
    {
        self::requireAdmin();
        $testResult = self::handlePost();
        $entries = self::entries();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Fraud Mock', 'tornevall-networks-dnsbl-implementation'); ?></h1>
            <p class="description" style="max-width:900px;">
                <?php echo esc_html__('Configurable fraud test identities stored in WordPress data. No test identities are hard-coded into the plugin.', 'tornevall-networks-dnsbl-implementation'); ?>
            </p>

            <?php if ($testResult !== null) { ?>
                <div class="notice notice-info inline" style="margin:16px 0;">
                    <p>
                        <?php if (empty($testResult['found'])) { ?>
                            <?php echo esc_html__('No mock entry matched the identifier.', 'tornevall-networks-dnsbl-implementation'); ?>
                        <?php } else { ?>
                            <code><?php echo esc_html((string)$testResult['identifier']); ?></code>
                            - <strong><?php echo esc_html((string)$testResult['current_status']); ?></strong>
                            <?php if ($testResult['elapsed_seconds'] !== null) { ?>
                                (<?php echo esc_html(sprintf(__('%d seconds elapsed', 'tornevall-networks-dnsbl-implementation'), (int)$testResult['elapsed_seconds'])); ?>)
                            <?php } ?>
                        <?php } ?>
                    </p>
                </div>
            <?php } ?>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:16px; margin-top:16px; align-items:start;">
                <div class="postbox">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Add or update mock entry', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <form method="post">
                            <?php wp_nonce_field('tornevall_dnsbl_fraud_mock_admin'); ?>
                            <input type="hidden" name="fraud_mock_operation" value="save">
                            <p><label><strong><?php echo esc_html__('Identifier', 'tornevall-networks-dnsbl-implementation'); ?></strong><br>
                                <input type="text" class="regular-text" name="identifier" required></label></p>
                            <p><label><strong><?php echo esc_html__('Initial status', 'tornevall-networks-dnsbl-implementation'); ?></strong><br>
                                <?php self::renderStatusSelect('initial_status', 'SUSPECTED_FRAUD', false); ?></label></p>
                            <p><label><strong><?php echo esc_html__('Transition status', 'tornevall-networks-dnsbl-implementation'); ?></strong><br>
                                <?php self::renderStatusSelect('transition_status', '', true); ?></label></p>
                            <p><label><strong><?php echo esc_html__('Transition behavior', 'tornevall-networks-dnsbl-implementation'); ?></strong><br>
                                <select name="transition_mode">
                                    <option value="none"><?php echo esc_html__('No transition', 'tornevall-networks-dnsbl-implementation'); ?></option>
                                    <option value="after"><?php echo esc_html__('After delay', 'tornevall-networks-dnsbl-implementation'); ?></option>
                                    <option value="never">NEVER</option>
                                </select></label></p>
                            <p><label><strong><?php echo esc_html__('Delay in seconds', 'tornevall-networks-dnsbl-implementation'); ?></strong><br>
                                <input type="number" min="0" step="1" name="delay_seconds" value="0"></label></p>
                            <p><label><strong><?php echo esc_html__('Internal note', 'tornevall-networks-dnsbl-implementation'); ?></strong><br>
                                <textarea class="large-text" rows="3" name="note"></textarea></label></p>
                            <?php submit_button(__('Save mock entry', 'tornevall-networks-dnsbl-implementation'), 'primary', 'submit', false); ?>
                        </form>
                    </div>
                </div>

                <div class="postbox">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Run mock lookup', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <p class="description"><?php echo esc_html__('For delayed transitions, the timer starts when the identifier is looked up for the first time.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        <form method="post">
                            <?php wp_nonce_field('tornevall_dnsbl_fraud_mock_admin'); ?>
                            <input type="hidden" name="fraud_mock_operation" value="test">
                            <p><input type="text" class="regular-text" name="identifier" required></p>
                            <?php submit_button(__('Run mock lookup', 'tornevall-networks-dnsbl-implementation'), 'secondary', 'submit', false); ?>
                        </form>
                        <form method="post" style="margin-top:16px;">
                            <?php wp_nonce_field('tornevall_dnsbl_fraud_mock_admin'); ?>
                            <input type="hidden" name="fraud_mock_operation" value="reset_runtime">
                            <?php submit_button(__('Reset all mock timers', 'tornevall-networks-dnsbl-implementation'), 'secondary', 'submit', false); ?>
                        </form>
                    </div>
                </div>
            </div>

            <div class="postbox" style="margin-top:16px;">
                <div class="inside">
                    <h2 style="margin-top:0;"><?php echo esc_html__('Configured entries', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                    <?php if (!count($entries)) { ?>
                        <p class="description"><?php echo esc_html__('No fraud mock entries configured yet.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                    <?php } else { ?>
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php echo esc_html__('Identifier', 'tornevall-networks-dnsbl-implementation'); ?></th>
                                <th><?php echo esc_html__('Initial', 'tornevall-networks-dnsbl-implementation'); ?></th>
                                <th><?php echo esc_html__('Transition', 'tornevall-networks-dnsbl-implementation'); ?></th>
                                <th><?php echo esc_html__('Note', 'tornevall-networks-dnsbl-implementation'); ?></th>
                                <th></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($entries as $entry) { ?>
                                <tr>
                                    <td><code><?php echo esc_html((string)$entry['identifier']); ?></code></td>
                                    <td><code><?php echo esc_html((string)$entry['initial_status']); ?></code></td>
                                    <td><?php echo wp_kses_post(self::transitionLabel($entry)); ?></td>
                                    <td><?php echo esc_html((string)$entry['note']); ?></td>
                                    <td>
                                        <form method="post">
                                            <?php wp_nonce_field('tornevall_dnsbl_fraud_mock_admin'); ?>
                                            <input type="hidden" name="fraud_mock_operation" value="delete">
                                            <input type="hidden" name="entry_id" value="<?php echo esc_attr((string)$entry['id']); ?>">
                                            <button class="button button-small" type="submit" onclick="return confirm('<?php echo esc_js(__('Delete this mock entry?', 'tornevall-networks-dnsbl-implementation')); ?>');"><?php echo esc_html__('Delete', 'tornevall-networks-dnsbl-implementation'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    <?php } ?>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:16px; margin-top:16px; align-items:start;">
                <div class="postbox">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Bulk import JSON', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <p class="description"><?php echo esc_html__('Existing identifiers are updated and new identifiers are added.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        <form method="post">
                            <?php wp_nonce_field('tornevall_dnsbl_fraud_mock_admin'); ?>
                            <input type="hidden" name="fraud_mock_operation" value="import">
                            <textarea class="large-text code" rows="12" name="import_json" placeholder='[{"identifier":"...","initial_status":"SUSPECTED_FRAUD","transition_status":"CONFIRMED_FRAUD","transition_mode":"after","delay_seconds":5,"note":"..."}]'></textarea>
                            <?php submit_button(__('Import JSON', 'tornevall-networks-dnsbl-implementation'), 'secondary', 'submit', false); ?>
                        </form>
                    </div>
                </div>
                <div class="postbox">
                    <div class="inside">
                        <h2 style="margin-top:0;"><?php echo esc_html__('Export JSON', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                        <textarea class="large-text code" rows="12" readonly><?php echo esc_textarea((string)wp_json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function handlePost(): ?array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['fraud_mock_operation'])) {
            return null;
        }

        check_admin_referer('tornevall_dnsbl_fraud_mock_admin');
        $operation = sanitize_text_field(wp_unslash($_POST['fraud_mock_operation']));

        if ($operation === 'test') {
            $identifier = sanitize_text_field(wp_unslash($_POST['identifier'] ?? ''));
            $result = self::resolve($identifier);
            return $result === null ? ['found' => false] : array_merge(['found' => true], $result);
        }

        if ($operation === 'reset_runtime') {
            self::saveRuntime([]);
            self::redirectSelf();
        }

        if ($operation === 'delete') {
            $entryId = sanitize_text_field(wp_unslash($_POST['entry_id'] ?? ''));
            self::saveEntries(array_values(array_filter(self::entries(), static fn(array $entry): bool => $entry['id'] !== $entryId)));
            self::removeRuntime($entryId);
            self::redirectSelf();
        }

        if ($operation === 'import') {
            $decoded = json_decode((string)wp_unslash($_POST['import_json'] ?? ''), true);
            if (is_array($decoded)) {
                foreach ($decoded as $rawEntry) {
                    if (is_array($rawEntry)) {
                        self::upsert($rawEntry);
                    }
                }
                self::saveRuntime([]);
            }
            self::redirectSelf();
        }

        if ($operation === 'save') {
            self::upsert([
                'identifier' => wp_unslash($_POST['identifier'] ?? ''),
                'initial_status' => wp_unslash($_POST['initial_status'] ?? ''),
                'transition_status' => wp_unslash($_POST['transition_status'] ?? ''),
                'transition_mode' => wp_unslash($_POST['transition_mode'] ?? ''),
                'delay_seconds' => $_POST['delay_seconds'] ?? 0,
                'note' => wp_unslash($_POST['note'] ?? ''),
            ]);
            self::redirectSelf();
        }

        return null;
    }

    /**
     * @param array<string,mixed> $rawEntry
     */
    private static function upsert(array $rawEntry): void
    {
        $entry = self::sanitizeEntry($rawEntry);
        if ($entry === null) {
            return;
        }

        $entries = self::entries();
        foreach ($entries as $index => $existing) {
            if ($existing['match_key'] === $entry['match_key']) {
                $entry['id'] = $existing['id'];
                $entries[$index] = $entry;
                self::saveEntries($entries);
                self::removeRuntime((string)$entry['id']);
                return;
            }
        }

        $entries[] = $entry;
        self::saveEntries($entries);
    }

    /**
     * @param array<string,mixed> $rawEntry
     * @return array<string,mixed>|null
     */
    private static function sanitizeEntry(array $rawEntry): ?array
    {
        $identifier = sanitize_text_field((string)($rawEntry['identifier'] ?? ''));
        $matchKey = self::matchKey($identifier);
        $initial = strtoupper(sanitize_text_field((string)($rawEntry['initial_status'] ?? 'SUSPECTED_FRAUD')));
        $transition = strtoupper(sanitize_text_field((string)($rawEntry['transition_status'] ?? '')));
        $mode = strtolower(sanitize_text_field((string)($rawEntry['transition_mode'] ?? 'none')));

        if ($identifier === '' || $matchKey === '' || !in_array($initial, self::STATUSES, true)) {
            return null;
        }
        if ($transition !== '' && !in_array($transition, self::STATUSES, true)) {
            return null;
        }
        if (!in_array($mode, ['none', 'after', 'never'], true) || $transition === '') {
            $mode = 'none';
        }

        $id = sanitize_text_field((string)($rawEntry['id'] ?? ''));
        if ($id === '') {
            $id = wp_generate_uuid4();
        }

        return [
            'id' => $id,
            'identifier' => $identifier,
            'match_key' => $matchKey,
            'initial_status' => $initial,
            'transition_status' => $transition,
            'transition_mode' => $mode,
            'delay_seconds' => absint($rawEntry['delay_seconds'] ?? 0),
            'note' => sanitize_textarea_field((string)($rawEntry['note'] ?? '')),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function entries(): array
    {
        $entries = get_option(self::ENTRIES_OPTION, []);
        if (!is_array($entries)) {
            return [];
        }

        $valid = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $sanitized = self::sanitizeEntry($entry);
                if ($sanitized !== null) {
                    $valid[] = $sanitized;
                }
            }
        }
        return $valid;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findByMatchKey(string $matchKey): ?array
    {
        foreach (self::entries() as $entry) {
            if ($entry['match_key'] === $matchKey) {
                return $entry;
            }
        }
        return null;
    }

    private static function matchKey(string $identifier): string
    {
        return preg_replace('/[^a-z0-9]+/i', '', strtolower(trim($identifier))) ?? '';
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     */
    private static function saveEntries(array $entries): void
    {
        update_option(self::ENTRIES_OPTION, array_values($entries), false);
    }

    /**
     * @return array<string,int>
     */
    private static function runtime(): array
    {
        $runtime = get_option(self::RUNTIME_OPTION, []);
        return is_array($runtime) ? $runtime : [];
    }

    /**
     * @param array<string,int> $runtime
     */
    private static function saveRuntime(array $runtime): void
    {
        update_option(self::RUNTIME_OPTION, $runtime, false);
    }

    private static function removeRuntime(string $entryId): void
    {
        $runtime = self::runtime();
        unset($runtime[$entryId]);
        self::saveRuntime($runtime);
    }

    private static function renderStatusSelect(string $name, string $selectedStatus, bool $allowEmpty): void
    {
        echo '<select name="' . esc_attr($name) . '">';
        if ($allowEmpty) {
            echo '<option value="">' . esc_html__('None', 'tornevall-networks-dnsbl-implementation') . '</option>';
        }
        foreach (self::STATUSES as $status) {
            echo '<option value="' . esc_attr($status) . '" ' . selected($selectedStatus, $status, false) . '>' . esc_html($status) . '</option>';
        }
        echo '</select>';
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function transitionLabel(array $entry): string
    {
        if ($entry['transition_mode'] === 'none' || $entry['transition_status'] === '') {
            return '<span class="description">' . esc_html__('None', 'tornevall-networks-dnsbl-implementation') . '</span>';
        }
        if ($entry['transition_mode'] === 'never') {
            return '<code>' . esc_html((string)$entry['transition_status']) . '</code> - <strong>NEVER</strong>';
        }
        return '<code>' . esc_html((string)$entry['transition_status']) . '</code> - ' . esc_html(sprintf(__('%d seconds', 'tornevall-networks-dnsbl-implementation'), (int)$entry['delay_seconds']));
    }

    private static function requireAdmin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'tornevall-networks-dnsbl-implementation'));
        }
    }

    private static function redirectSelf(): void
    {
        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }
}
