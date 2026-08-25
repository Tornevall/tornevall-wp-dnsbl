<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

trait WooCommerceSettingsTrait
{
    public static function registerSettings(): void
    {
        register_setting(self::SETTINGS_GROUP, 'tornevall_dnsbl_wc_enabled', [
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        register_setting(self::SETTINGS_GROUP, 'tornevall_dnsbl_wc_filter_types', [
            'sanitize_callback' => [self::class, 'sanitizeFilterTypes'],
        ]);
        register_setting(self::SETTINGS_GROUP, 'tornevall_dnsbl_wc_block_action', [
            'sanitize_callback' => [self::class, 'sanitizeBlockAction'],
        ]);
        register_setting(self::SETTINGS_GROUP, 'tornevall_dnsbl_wc_customer_message', [
            'sanitize_callback' => [self::class, 'sanitizeCustomerMessage'],
        ]);
        register_setting(self::SETTINGS_GROUP, 'tornevall_dnsbl_wc_delist_hint', [
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        register_setting(self::SETTINGS_GROUP, 'tornevall_dnsbl_wc_notify_email', [
            'sanitize_callback' => [self::class, 'sanitizeEmailList'],
        ]);
        register_setting(self::SETTINGS_GROUP, 'tornevall_dnsbl_wc_notify_mode', [
            'sanitize_callback' => [self::class, 'sanitizeNotifyMode'],
        ]);
        register_setting(self::SETTINGS_GROUP, 'tornevall_dnsbl_wc_notify_schedule', [
            'sanitize_callback' => [self::class, 'sanitizeNotifySchedule'],
        ]);
        register_setting(self::SETTINGS_GROUP, 'tornevall_dnsbl_protect_wp_admin', [
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
    }

    public static function sanitizeCheckbox($value): string
    {
        return empty($value) ? '0' : '1';
    }

    /**
     * @return list<string>
     */
    public static function sanitizeFilterTypes($value): array
    {
        $available = array_keys(Plugin::getCurrentFlagMap());
        $selected = [];

        foreach ((array)$value as $flag) {
            $flag = Plugin::canonicalFlagName($flag);
            if ($flag !== '' && in_array($flag, $available, true)) {
                $selected[] = $flag;
            }
        }

        $selected = array_values(array_unique($selected));

        return count($selected) ? $selected : self::defaultSelectedFlags();
    }

    public static function sanitizeBlockAction($value): string
    {
        $value = sanitize_key((string)$value);

        return in_array($value, ['notice', 'redirect', 'both'], true) ? $value : 'notice';
    }

    public static function sanitizeCustomerMessage($value): string
    {
        $value = trim(sanitize_textarea_field((string)$value));

        return $value !== '' ? $value : self::defaultCustomerMessage();
    }

    public static function sanitizeEmailList($value): string
    {
        $emails = preg_split('/[\s,;]+/', (string)$value);
        $valid = [];

        foreach ((array)$emails as $email) {
            $email = sanitize_email(trim($email));
            if ($email !== '' && is_email($email)) {
                $valid[] = $email;
            }
        }

        return implode(',', array_values(array_unique($valid)));
    }

    public static function sanitizeNotifyMode($value): string
    {
        $value = sanitize_key((string)$value);

        return in_array($value, ['off', 'instant', 'bulk'], true) ? $value : 'off';
    }

    public static function sanitizeNotifySchedule($value): string
    {
        $value = sanitize_key((string)$value);

        return in_array($value, ['hourly', 'twicedaily', 'daily'], true) ? $value : 'daily';
    }

    /**
     * @return list<string>
     */
    public static function selectedFlags(): array
    {
        return self::sanitizeFilterTypes(get_option('tornevall_dnsbl_wc_filter_types', self::defaultSelectedFlags()));
    }

    public static function blockAction(): string
    {
        return self::sanitizeBlockAction(get_option('tornevall_dnsbl_wc_block_action', 'notice'));
    }

    public static function customerMessage(): string
    {
        return self::sanitizeCustomerMessage(get_option('tornevall_dnsbl_wc_customer_message', self::defaultCustomerMessage()));
    }

    public static function delistHintEnabled(): bool
    {
        return get_option('tornevall_dnsbl_wc_delist_hint', '1') === '1';
    }

    public static function notifyMode(): string
    {
        return self::sanitizeNotifyMode(get_option('tornevall_dnsbl_wc_notify_mode', 'off'));
    }

    public static function notifySchedule(): string
    {
        return self::sanitizeNotifySchedule(get_option('tornevall_dnsbl_wc_notify_schedule', 'daily'));
    }

    public static function protectWpAdminEnabled(): bool
    {
        return get_option('tornevall_dnsbl_protect_wp_admin', '0') === '1';
    }

    private static function flagLabel(string $flagName, int $bitValue): string
    {
        $labels = [
            'FREE_SLOT_1_PREVIOUSLY_REPORTED' => 'Previously reported (legacy)',
            'IP_CONFIRMED' => 'Confirmed listed',
            'IP_PHISHING' => 'Phishing origin',
            'IP_FRAUDCOMMERCE' => 'Commerce fraud',
            'IP_MAILSERVER_SPAM' => 'Mail spam source',
            'IP_SECOND_EXIT' => 'Secondary proxy or exit node',
            'IP_ABUSE_NO_SMTP' => 'Abuse (non-SMTP)',
            'IP_ANONYMOUS' => 'Anonymous or proxy',
            'BIT_256' => 'Reserved',
        ];

        return sprintf('%s - %d - %s', $flagName, $bitValue, $labels[$flagName] ?? $flagName);
    }

    /**
     * @return array<string,string>
     */
    private static function blockActionLabels(): array
    {
        return [
            'notice' => __('Notice only', 'tornevall-networks-dnsbl-implementation'),
            'redirect' => __('Redirect or Store API error with information URL', 'tornevall-networks-dnsbl-implementation'),
            'both' => __('Notice and redirect', 'tornevall-networks-dnsbl-implementation'),
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function notifyModeLabels(): array
    {
        return [
            'off' => __('Off', 'tornevall-networks-dnsbl-implementation'),
            'instant' => __('Instant email', 'tornevall-networks-dnsbl-implementation'),
            'bulk' => __('Bulk or periodic email', 'tornevall-networks-dnsbl-implementation'),
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function notifyScheduleLabels(): array
    {
        return [
            'hourly' => __('Hourly', 'tornevall-networks-dnsbl-implementation'),
            'twicedaily' => __('Twice daily', 'tornevall-networks-dnsbl-implementation'),
            'daily' => __('Daily', 'tornevall-networks-dnsbl-implementation'),
        ];
    }
}
