<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

trait WooCommerceCoreTrait
{
    /**
     * Register integration hooks that are safe before WooCommerce has loaded.
     */
    public static function registerHooks(): void
    {
        add_action('before_woocommerce_init', [self::class, 'declareBlocksCompatibility']);
        add_action('plugins_loaded', [self::class, 'boot'], 20);
        add_action(self::CRON_HOOK, [self::class, 'sendBulkNotifications']);

        if (self::isEmergencyBypassEnabled()) {
            self::disableCoreDnsblChecks();
        }
    }

    /**
     * Activate WooCommerce-specific hooks only when WooCommerce is available.
     */
    public static function boot(): void
    {
        if (!self::isWooCommerceActive()) {
            self::clearNotificationSchedule();
            return;
        }

        self::ensureDefaultOptions();

        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_menu', [self::class, 'registerAdminMenu'], 20);
        add_action('admin_notices', [self::class, 'renderGeneralSettingsNotice']);
        add_action('admin_init', [self::class, 'protectWpAdmin'], 1);
        add_action('init', [self::class, 'syncNotificationSchedule'], 20);

        add_filter('allowed_redirect_hosts', [self::class, 'allowConfiguredRedirectHost']);
        add_filter('woocommerce_settings_tabs_array', [self::class, 'addWooCommerceSettingsTab'], 50);
        add_action('woocommerce_settings_tabs_tornevall_dnsbl', [self::class, 'renderWooCommerceSettingsShortcut']);

        if (!self::isEnabled() || self::isEmergencyBypassEnabled()) {
            return;
        }

        // Legacy shortcode checkout. wc_add_notice() here prevents order creation.
        add_action('woocommerce_checkout_process', [self::class, 'validateLegacyCheckout'], 1);

        // Blocks and Store API checkout. WooCommerce documents that throwing from
        // this hook prevents checkout and surfaces the exception in the block UI.
        add_action('woocommerce_store_api_checkout_update_order_meta', [self::class, 'validateStoreApiCheckout'], 1, 1);
    }

    public static function declareBlocksCompatibility(): void
    {
        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                TORNEVALL_DNSBL_PLUGIN_FILE,
                true
            );
        }
    }

    public static function isWooCommerceActive(): bool
    {
        return class_exists('\\WooCommerce') || function_exists('WC');
    }

    public static function isEnabled(): bool
    {
        return get_option('tornevall_dnsbl_wc_enabled', '0') === '1';
    }

    public static function isEmergencyBypassEnabled(): bool
    {
        return defined('TORNEVALL_DNSBL_ADMIN_BYPASS')
            && TORNEVALL_DNSBL_ADMIN_BYPASS === true;
    }

    /**
     * The emergency constant is deliberately stronger than the normal admin
     * bypass. It removes DNSBL enforcement hooks for the whole request.
     */
    private static function disableCoreDnsblChecks(): void
    {
        remove_action('init', [Plugin::class, 'checkpoint'], 10);
        remove_filter('comments_open', [Plugin::class, 'disableComments'], 10);
        remove_filter('comments_array', [Plugin::class, 'disableCommentsMessage'], 10);
        remove_filter('preprocess_comment', [Plugin::class, 'preprocessComment'], 10);
        remove_filter('pre_comment_approved', [Plugin::class, 'preCommentApproved'], 10);
        remove_filter('registration_errors', [Plugin::class, 'validateRegistrationErrors'], 10);
        remove_filter('wpmu_validate_user_signup', [Plugin::class, 'validateMultisiteUserSignup'], 10);
        remove_filter('wpmu_validate_blog_signup', [Plugin::class, 'validateMultisiteBlogSignup'], 10);
    }

    /**
     * Default values are kept here so the integration can stay isolated from the
     * core plugin defaults when WooCommerce is not installed.
     *
     * @return array<string,mixed>
     */
    public static function defaultOptions(): array
    {
        return [
            'tornevall_dnsbl_wc_enabled' => '0',
            'tornevall_dnsbl_wc_filter_types' => self::defaultSelectedFlags(),
            'tornevall_dnsbl_wc_block_action' => 'notice',
            'tornevall_dnsbl_wc_customer_message' => self::defaultCustomerMessage(),
            'tornevall_dnsbl_wc_delist_hint' => '1',
            'tornevall_dnsbl_wc_notify_email' => '',
            'tornevall_dnsbl_wc_notify_mode' => 'off',
            'tornevall_dnsbl_wc_notify_schedule' => 'daily',
            'tornevall_dnsbl_protect_wp_admin' => '0',
        ];
    }

    public static function ensureDefaultOptions(): void
    {
        foreach (self::defaultOptions() as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function defaultSelectedFlags(): array
    {
        return ['IP_FRAUDCOMMERCE', 'IP_SECOND_EXIT'];
    }

    public static function defaultCustomerMessage(): string
    {
        return __('Order could not be placed because the current visitor IP matches the active DNSBL policy.', 'tornevall-networks-dnsbl-implementation');
    }
}
