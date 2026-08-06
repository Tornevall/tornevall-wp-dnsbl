<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

trait WooCommerceAdminTrait
{
    public static function registerAdminMenu(): void
    {
        add_submenu_page(
            'tornevallDnsblMenu',
            __('DNSBL WooCommerce protection', 'tornevall-networks-dnsbl-implementation'),
            __('WooCommerce', 'tornevall-networks-dnsbl-implementation'),
            'manage_options',
            self::SETTINGS_PAGE,
            [self::class, 'renderSettingsPage']
        );
    }

    public static function getSettingsUrl(): string
    {
        return admin_url('admin.php?page=' . self::SETTINGS_PAGE);
    }

    public static function renderGeneralSettingsNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'tornevallDnsblMenu') {
            return;
        }

        echo '<div class="notice notice-info"><p>';
        echo esc_html__('WooCommerce checkout protection has its own policy, customer message and notification settings.', 'tornevall-networks-dnsbl-implementation');
        echo ' <a class="button button-secondary" href="' . esc_url(self::getSettingsUrl()) . '">';
        echo esc_html__('Open WooCommerce DNSBL settings', 'tornevall-networks-dnsbl-implementation');
        echo '</a></p>';
        echo '<p><code>define(\'TORNEVALL_DNSBL_ADMIN_BYPASS\', true);</code> ';
        echo esc_html__('bypasses all DNSBL enforcement in an emergency.', 'tornevall-networks-dnsbl-implementation');
        echo '</p></div>';
    }

    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage these settings.', 'tornevall-networks-dnsbl-implementation'));
        }

        $selectedFlags = self::selectedFlags();
        $flagMap = Plugin::getCurrentFlagMap();
        $blockAction = self::blockAction();
        $notifyMode = self::notifyMode();
        $notifySchedule = self::notifySchedule();

        settings_errors(self::SETTINGS_GROUP);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Tornevall DNSBL - WooCommerce', 'tornevall-networks-dnsbl-implementation'); ?></h1>

            <nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__('DNSBL settings', 'tornevall-networks-dnsbl-implementation'); ?>">
                <a class="nav-tab" href="<?php echo esc_url(admin_url('admin.php?page=tornevallDnsblMenu')); ?>"><?php echo esc_html__('General', 'tornevall-networks-dnsbl-implementation'); ?></a>
                <a class="nav-tab nav-tab-active" href="<?php echo esc_url(self::getSettingsUrl()); ?>"><?php echo esc_html__('WooCommerce', 'tornevall-networks-dnsbl-implementation'); ?></a>
            </nav>

            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable integration', 'tornevall-networks-dnsbl-implementation'); ?></th>
                        <td>
                            <input type="hidden" name="tornevall_dnsbl_wc_enabled" value="0">
                            <label><input type="checkbox" name="tornevall_dnsbl_wc_enabled" value="1" <?php checked(self::isEnabled()); ?>>
                                <?php echo esc_html__('Block WooCommerce checkout attempts that match the selected DNSBL flags.', 'tornevall-networks-dnsbl-implementation'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tornevall_dnsbl_wc_filter_types"><?php echo esc_html__('Flags to block at checkout', 'tornevall-networks-dnsbl-implementation'); ?></label></th>
                        <td>
                            <input type="hidden" name="tornevall_dnsbl_wc_filter_types[]" value="">
                            <select id="tornevall_dnsbl_wc_filter_types" name="tornevall_dnsbl_wc_filter_types[]" multiple size="9" style="min-width:420px;">
                                <?php foreach ($flagMap as $flagName => $bitValue) { ?>
                                    <option value="<?php echo esc_attr($flagName); ?>" <?php selected(in_array($flagName, $selectedFlags, true)); ?>>
                                        <?php echo esc_html(self::flagLabel($flagName, (int)$bitValue)); ?>
                                    </option>
                                <?php } ?>
                            </select>
                            <p class="description"><?php echo esc_html__('This policy is independent from comment and registration blocking. Only a selected flag that is present in the resolved bitmask blocks checkout.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Block action', 'tornevall-networks-dnsbl-implementation'); ?></th>
                        <td>
                            <?php foreach (self::blockActionLabels() as $value => $label) { ?>
                                <label style="display:block;margin-bottom:6px;"><input type="radio" name="tornevall_dnsbl_wc_block_action" value="<?php echo esc_attr($value); ?>" <?php checked($blockAction, $value); ?>> <?php echo esc_html($label); ?></label>
                            <?php } ?>
                            <p class="description"><?php echo esc_html__('The classic checkout can redirect immediately. Store API checkout cannot navigate the browser from a REST response, so redirect modes reject payment and include the configured information URL in the checkout error.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tornevall_dnsbl_wc_customer_message"><?php echo esc_html__('Customer-facing message', 'tornevall-networks-dnsbl-implementation'); ?></label></th>
                        <td>
                            <textarea id="tornevall_dnsbl_wc_customer_message" name="tornevall_dnsbl_wc_customer_message" rows="4" class="large-text"><?php echo esc_textarea(self::customerMessage()); ?></textarea>
                            <p class="description"><?php echo esc_html__('Use a neutral message about the visitor IP and store policy. Support and eligible delisting guidance is appended automatically.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Delisting guidance', 'tornevall-networks-dnsbl-implementation'); ?></th>
                        <td>
                            <input type="hidden" name="tornevall_dnsbl_wc_delist_hint" value="0">
                            <label><input type="checkbox" name="tornevall_dnsbl_wc_delist_hint" value="1" <?php checked(self::delistHintEnabled()); ?>>
                                <?php echo esc_html__('Append removal-tool guidance when the matched flags are eligible.', 'tornevall-networks-dnsbl-implementation'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('The hint is suppressed for IP_PHISHING, IP_FRAUDCOMMERCE and IP_ABUSE_NO_SMTP because those flags represent active or specific abuse and must not offer self-delisting from checkout.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tornevall_dnsbl_wc_notify_email"><?php echo esc_html__('Notification recipients', 'tornevall-networks-dnsbl-implementation'); ?></label></th>
                        <td>
                            <input id="tornevall_dnsbl_wc_notify_email" type="text" class="regular-text" name="tornevall_dnsbl_wc_notify_email" value="<?php echo esc_attr((string)get_option('tornevall_dnsbl_wc_notify_email', '')); ?>" placeholder="<?php echo esc_attr((string)get_option('admin_email')); ?>">
                            <p class="description"><?php echo esc_html__('Separate multiple addresses with commas. The WordPress administration email is always included; leave this empty to use only that address.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Notification mode', 'tornevall-networks-dnsbl-implementation'); ?></th>
                        <td>
                            <?php foreach (self::notifyModeLabels() as $value => $label) { ?>
                                <label style="display:block;margin-bottom:6px;"><input type="radio" name="tornevall_dnsbl_wc_notify_mode" value="<?php echo esc_attr($value); ?>" <?php checked($notifyMode, $value); ?>> <?php echo esc_html($label); ?></label>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tornevall_dnsbl_wc_notify_schedule"><?php echo esc_html__('Bulk schedule', 'tornevall-networks-dnsbl-implementation'); ?></label></th>
                        <td>
                            <select id="tornevall_dnsbl_wc_notify_schedule" name="tornevall_dnsbl_wc_notify_schedule">
                                <?php foreach (self::notifyScheduleLabels() as $value => $label) { ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($notifySchedule, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php } ?>
                            </select>
                            <p class="description"><?php echo esc_html__('Used only when notification mode is Bulk.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Protect wp-admin', 'tornevall-networks-dnsbl-implementation'); ?></th>
                        <td>
                            <input type="hidden" name="tornevall_dnsbl_protect_wp_admin" value="0">
                            <label><input type="checkbox" name="tornevall_dnsbl_protect_wp_admin" value="1" <?php checked(self::protectWpAdminEnabled()); ?>>
                                <?php echo esc_html__('Evaluate wp-admin requests with the normal global DNSBL policy.', 'tornevall-networks-dnsbl-implementation'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('Whitelisted addresses remain allowed. This can lock out an administrator whose address becomes listed.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                            <p><strong><?php echo esc_html__('Emergency bypass:', 'tornevall-networks-dnsbl-implementation'); ?></strong> <code>define('TORNEVALL_DNSBL_ADMIN_BYPASS', true);</code></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-settings')); ?>"><?php echo esc_html__('Open WooCommerce settings', 'tornevall-networks-dnsbl-implementation'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tornevallDnsblMenu')); ?>"><?php echo esc_html__('Open general DNSBL settings', 'tornevall-networks-dnsbl-implementation'); ?></a>
                <a class="button" href="<?php echo esc_url(Plugin::toolsBaseUrl()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open Tornevall Networks Tools', 'tornevall-networks-dnsbl-implementation'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
         * Add a small WooCommerce settings tab that directs merchants to the full
         * DNSBL configuration instead of duplicating the settings in two places.
         */
    public static function addWooCommerceSettingsTab($tabs): array
    {
        $tabs = is_array($tabs) ? $tabs : [];
        $tabs['tornevall_dnsbl'] = __('DNSBL', 'tornevall-networks-dnsbl-implementation');

        return $tabs;
    }

    public static function renderWooCommerceSettingsShortcut(): void
    {
        echo '<h2>' . esc_html__('Tornevall DNSBL checkout protection', 'tornevall-networks-dnsbl-implementation') . '</h2>';
        echo '<p>' . esc_html__('Checkout blocking, flag selection, customer messages and notifications are managed in the Tornevall DNSBL settings.', 'tornevall-networks-dnsbl-implementation') . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::getSettingsUrl()) . '">' . esc_html__('Open DNSBL WooCommerce settings', 'tornevall-networks-dnsbl-implementation') . '</a></p>';
        echo '<p>' . esc_html__('Blacklist management, token access and delisting tools are available through the general DNSBL settings and Tornevall Networks Tools.', 'tornevall-networks-dnsbl-implementation') . '</p>';
    }

    public static function allowConfiguredRedirectHost($hosts): array
    {
        $hosts = is_array($hosts) ? $hosts : [];
        $host = wp_parse_url(Plugin::getBlockedRedirectUrl(), PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            $hosts[] = $host;
        }

        return array_values(array_unique($hosts));
    }

    /**
         * Apply the global policy to wp-admin when explicitly enabled. The emergency
         * constant is checked before any lookup, and whitelists remain effective.
         */
    public static function protectWpAdmin(): void
    {
        if (!self::protectWpAdminEnabled() || self::isEmergencyBypassEnabled()) {
            return;
        }

        if (!is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        $ip = Plugin::currentVisitorIp();
        if ($ip === '' || Plugin::isWhitelistedIp($ip)) {
            return;
        }

        $evaluation = Plugin::evaluateBlacklistState($ip, true);
        if (empty($evaluation['blocked'])) {
            return;
        }

        Plugin::recordStat($ip, (int)($evaluation['bitmask'] ?? 0), true, 'wp-admin-protected');
        wp_safe_redirect(Plugin::getBlockedRedirectUrl(), 302, 'Tornevall DNSBL');
        exit;
    }

}
