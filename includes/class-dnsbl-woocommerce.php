<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

foreach ([
    'woocommerce-core-trait.php',
    'woocommerce-settings-trait.php',
    'woocommerce-admin-trait.php',
    'woocommerce-checkout-trait.php',
    'woocommerce-notifications-trait.php',
] as $tornevallDnsblWooCommerceTrait) {
    require_once TORNEVALL_DNSBL_PLUGIN_DIR . 'includes/traits/' . $tornevallDnsblWooCommerceTrait;
}

class WooCommerce
{
    use WooCommerceCoreTrait;
    use WooCommerceSettingsTrait;
    use WooCommerceAdminTrait;
    use WooCommerceCheckoutTrait;
    use WooCommerceNotificationsTrait;

    private const SETTINGS_PAGE = 'tornevallDnsblWooCommerce';
    private const SETTINGS_GROUP = 'dnsblWooCommerceOptions-group';
    private const CRON_HOOK = 'tornevall_dnsbl_wc_bulk_notification';
    private const TABLE_NAME = 'tornevall_dnsbl_wc_blocked_log';
}
