<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

class WooCommerce
{
    private const SETTINGS_PAGE = 'tornevallDnsblWooCommerce';
    private const CRON_HOOK = 'tornevall_dnsbl_wc_bulk_notification';
    private const TABLE_NAME = 'tornevall_dnsbl_wc_blocked_log';

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
        return 'Order could not be placed because the current visitor IP matches the active DNSBL policy.';
    }

    public static function registerSettings(): void
    {
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_wc_enabled', [
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_wc_filter_types', [
            'sanitize_callback' => [self::class, 'sanitizeFilterTypes'],
        ]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_wc_block_action', [
            'sanitize_callback' => [self::class, 'sanitizeBlockAction'],
        ]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_wc_customer_message', [
            'sanitize_callback' => [self::class, 'sanitizeCustomerMessage'],
        ]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_wc_delist_hint', [
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_wc_notify_email', [
            'sanitize_callback' => [self::class, 'sanitizeEmailList'],
        ]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_wc_notify_mode', [
            'sanitize_callback' => [self::class, 'sanitizeNotifyMode'],
        ]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_wc_notify_schedule', [
            'sanitize_callback' => [self::class, 'sanitizeNotifySchedule'],
        ]);
        register_setting('dnsblOptions-group', 'tornevall_dnsbl_protect_wp_admin', [
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

        settings_errors('dnsblOptions-group');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Tornevall DNSBL - WooCommerce', 'tornevall-networks-dnsbl-implementation'); ?></h1>

            <nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__('DNSBL settings', 'tornevall-networks-dnsbl-implementation'); ?>">
                <a class="nav-tab" href="<?php echo esc_url(admin_url('admin.php?page=tornevallDnsblMenu')); ?>"><?php echo esc_html__('General', 'tornevall-networks-dnsbl-implementation'); ?></a>
                <a class="nav-tab nav-tab-active" href="<?php echo esc_url(self::getSettingsUrl()); ?>"><?php echo esc_html__('WooCommerce', 'tornevall-networks-dnsbl-implementation'); ?></a>
            </nav>

            <form method="post" action="options.php">
                <?php settings_fields('dnsblOptions-group'); ?>

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
                            <p class="description"><?php echo esc_html__('Separate multiple addresses with commas. Leave empty to use the WordPress administration email.', 'tornevall-networks-dnsbl-implementation'); ?></p>
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

    /**
     * Validate classic checkout before WooCommerce creates an order.
     */
    public static function validateLegacyCheckout(): void
    {
        $evaluation = self::evaluateCheckoutRequest();
        if ($evaluation === null) {
            return;
        }

        $context = self::legacyOrderContext();
        self::recordBlockedAttempt($evaluation, $context);

        $action = self::blockAction();
        $message = self::buildCustomerMessage($evaluation, true);

        if ($action === 'notice' || $action === 'both') {
            wc_add_notice($message, 'error');
        }

        if ($action === 'redirect' || $action === 'both') {
            self::redirectBlockedCustomer();
        }
    }

    /**
     * Validate Checkout Block and direct Store API checkout before payment.
     *
     * @param mixed $order WooCommerce order object.
     * @throws \Exception Always when the current checkout must be blocked.
     */
    public static function validateStoreApiCheckout($order): void
    {
        $evaluation = self::evaluateCheckoutRequest();
        if ($evaluation === null) {
            return;
        }

        $context = self::storeApiOrderContext($order);
        self::recordBlockedAttempt($evaluation, $context);

        $message = self::buildCustomerMessage($evaluation, false);

        if (class_exists('\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'tornevall_dnsbl_checkout_blocked',
                wp_strip_all_tags($message),
                403
            );
        }

        throw new \Exception(wp_strip_all_tags($message), 403);
    }

    /**
     * @return array{ip:string,bitmask:int,matched_flags:list<string>}|null
     */
    private static function evaluateCheckoutRequest(): ?array
    {
        if (!self::isEnabled() || self::isEmergencyBypassEnabled()) {
            return null;
        }

        // Store administrators can place test orders. The explicit whitelist is
        // still checked for every other customer.
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return null;
        }

        $ip = Plugin::currentVisitorIp();
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP) || Plugin::isWhitelistedIp($ip)) {
            return null;
        }

        $bitmask = (int)Plugin::checkBlacklistCache($ip);
        if ($bitmask <= 0) {
            return null;
        }

        $matchedFlags = self::matchedSelectedFlags($bitmask);
        if (!count($matchedFlags)) {
            return null;
        }

        return [
            'ip' => $ip,
            'bitmask' => $bitmask,
            'matched_flags' => $matchedFlags,
        ];
    }

    /**
     * @return list<string>
     */
    private static function matchedSelectedFlags(int $bitmask): array
    {
        $selected = self::selectedFlags();
        $matches = [];

        foreach (Plugin::getCurrentFlagMap() as $flagName => $flagBit) {
            $flagBit = (int)$flagBit;
            if ($flagBit > 0 && ($bitmask & $flagBit) === $flagBit && in_array($flagName, $selected, true)) {
                $matches[] = $flagName;
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @param array{matched_flags:list<string>} $evaluation
     */
    private static function buildCustomerMessage(array $evaluation, bool $allowHtml): string
    {
        $parts = [self::customerMessage()];
        $parts[] = __('Contact the store support if you believe the address has been classified incorrectly.', 'tornevall-networks-dnsbl-implementation');

        $matchedFlags = (array)($evaluation['matched_flags'] ?? []);
        if (self::delistHintEnabled() && !self::containsHighRiskFlag($matchedFlags)) {
            if (Plugin::writeTokenSet()) {
                $parts[] = __('This store has a Tornevall Networks Tools connection, but removal is not performed from checkout. The store can review the listing through its configured Tools account.', 'tornevall-networks-dnsbl-implementation');
            } else {
                $parts[] = __('Removal requests are handled through Tornevall Networks removal tools and cannot be completed from checkout.', 'tornevall-networks-dnsbl-implementation');
            }
        }

        $action = self::blockAction();
        if ($action === 'redirect' || $action === 'both') {
            $url = Plugin::getBlockedRedirectUrl();
            if ($allowHtml) {
                $parts[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('More information', 'tornevall-networks-dnsbl-implementation') . '</a>';
            } else {
                $parts[] = sprintf(__('More information: %s', 'tornevall-networks-dnsbl-implementation'), esc_url_raw($url));
            }
        }

        if ($allowHtml) {
            $escaped = [];
            foreach ($parts as $index => $part) {
                $escaped[] = $index === count($parts) - 1 && str_contains($part, '<a ')
                    ? wp_kses_post($part)
                    : esc_html($part);
            }
            return implode(' ', $escaped);
        }

        return implode(' ', array_map('wp_strip_all_tags', $parts));
    }

    /**
     * @param list<string> $flags
     */
    private static function containsHighRiskFlag(array $flags): bool
    {
        return count(array_intersect($flags, [
            'IP_PHISHING',
            'IP_FRAUDCOMMERCE',
            'IP_ABUSE_NO_SMTP',
        ])) > 0;
    }

    private static function redirectBlockedCustomer(): void
    {
        $url = Plugin::getBlockedRedirectUrl();
        if ($url === '') {
            return;
        }

        wp_safe_redirect($url, 302, 'Tornevall DNSBL');
        exit;
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

    /**
     * @return array{order_id:int,order_total:string}
     */
    private static function legacyOrderContext(): array
    {
        $total = '';
        if (function_exists('WC') && WC() && WC()->cart) {
            $total = (string)WC()->cart->get_total('edit');
        }

        return [
            'order_id' => 0,
            'order_total' => $total,
        ];
    }

    /**
     * @param mixed $order
     * @return array{order_id:int,order_total:string}
     */
    private static function storeApiOrderContext($order): array
    {
        if (is_object($order) && method_exists($order, 'get_id') && method_exists($order, 'get_total')) {
            return [
                'order_id' => (int)$order->get_id(),
                'order_total' => (string)$order->get_total('edit'),
            ];
        }

        return [
            'order_id' => 0,
            'order_total' => '',
        ];
    }

    /**
     * @param array{ip:string,bitmask:int,matched_flags:list<string>} $evaluation
     * @param array{order_id:int,order_total:string} $context
     */
    private static function recordBlockedAttempt(array $evaluation, array $context): void
    {
        static $recorded = [];

        $key = md5(
            $evaluation['ip'] . '|'
            . $evaluation['bitmask'] . '|'
            . implode(',', $evaluation['matched_flags']) . '|'
            . $context['order_id']
        );
        if (isset($recorded[$key])) {
            return;
        }
        $recorded[$key] = true;

        Plugin::recordStat($evaluation['ip'], $evaluation['bitmask'], true, 'woocommerce-checkout');

        $mode = self::notifyMode();
        if ($mode === 'instant') {
            self::sendInstantNotification($evaluation, $context);
        } elseif ($mode === 'bulk') {
            self::storeBulkNotification($evaluation, $context);
        }
    }

    /**
     * @param array{ip:string,bitmask:int,matched_flags:list<string>} $evaluation
     * @param array{order_id:int,order_total:string} $context
     */
    private static function sendInstantNotification(array $evaluation, array $context): void
    {
        $siteName = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] DNSBL blocked a checkout attempt', $siteName);
        $body = self::formatNotificationBody($evaluation, $context);

        wp_mail(self::notificationRecipients(), $subject, $body);
    }

    /**
     * @param array{ip:string,bitmask:int,matched_flags:list<string>} $evaluation
     * @param array{order_id:int,order_total:string} $context
     */
    private static function storeBulkNotification(array $evaluation, array $context): void
    {
        global $wpdb;

        $table = self::tableName($wpdb);
        if (!self::tableExists($wpdb, $table)) {
            self::createLogTable();
        }

        $wpdb->insert(
            $table,
            [
                'ip' => $evaluation['ip'],
                'flags' => implode(',', $evaluation['matched_flags']),
                'order_id' => $context['order_id'],
                'order_total' => $context['order_total'] !== '' ? $context['order_total'] : null,
                'blocked_at' => current_time('mysql', true),
                'notified' => 0,
            ],
            ['%s', '%s', '%d', '%s', '%s', '%d']
        );

        self::syncNotificationSchedule();
    }

    public static function sendBulkNotifications(): void
    {
        global $wpdb;

        $table = self::tableName($wpdb);
        if (!self::tableExists($wpdb, $table)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the trusted WordPress prefix.
        $rows = $wpdb->get_results("SELECT id, ip, flags, order_id, order_total, blocked_at FROM {$table} WHERE notified = 0 ORDER BY id ASC LIMIT 500", ARRAY_A);
        if (!is_array($rows) || !count($rows)) {
            return;
        }

        $siteName = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] DNSBL blocked checkout attempts (%d)', $siteName, count($rows));
        $lines = [
            sprintf('Site: %s', $siteName),
            sprintf('Blocked checkout attempts: %d', count($rows)),
            sprintf('DNSBL settings: %s', self::getSettingsUrl()),
            '',
        ];

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row['id'];
            $lines[] = sprintf(
                '%s | IP: %s | Flags: %s | Order: %s | Total: %s',
                (string)$row['blocked_at'],
                (string)$row['ip'],
                (string)$row['flags'],
                ((int)$row['order_id']) > 0 ? '#' . (int)$row['order_id'] : 'not created',
                $row['order_total'] !== null && $row['order_total'] !== '' ? (string)$row['order_total'] : 'n/a'
            );
        }

        if (!wp_mail(self::notificationRecipients(), $subject, implode("\n", $lines))) {
            return;
        }

        $ids = array_values(array_filter(array_map('absint', $ids)));
        if (!count($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder count are generated internally.
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET notified = 1 WHERE id IN ({$placeholders})", $ids));
    }

    /**
     * @param array{ip:string,bitmask:int,matched_flags:list<string>} $evaluation
     * @param array{order_id:int,order_total:string} $context
     */
    private static function formatNotificationBody(array $evaluation, array $context): string
    {
        $siteName = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        return implode("\n", [
            sprintf('Site: %s', $siteName),
            sprintf('IP: %s', $evaluation['ip']),
            sprintf('Matched flags: %s', implode(', ', $evaluation['matched_flags'])),
            sprintf('Resolved bitmask: %d', $evaluation['bitmask']),
            sprintf('Timestamp (UTC): %s', current_time('mysql', true)),
            sprintf('Order: %s', $context['order_id'] > 0 ? '#' . $context['order_id'] : 'not created'),
            sprintf('Order total: %s', $context['order_total'] !== '' ? $context['order_total'] : 'n/a'),
            sprintf('DNSBL settings: %s', self::getSettingsUrl()),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function notificationRecipients(): array
    {
        $configured = self::sanitizeEmailList(get_option('tornevall_dnsbl_wc_notify_email', ''));
        if ($configured === '') {
            $adminEmail = sanitize_email((string)get_option('admin_email'));
            return $adminEmail !== '' ? [$adminEmail] : [];
        }

        return array_values(array_filter(explode(',', $configured)));
    }

    public static function syncNotificationSchedule(): void
    {
        if (!function_exists('wp_next_scheduled')) {
            return;
        }

        if (self::notifyMode() !== 'bulk') {
            self::clearNotificationSchedule();
            return;
        }

        $schedule = self::notifySchedule();
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_HOOK) : null;

        if ($event && isset($event->schedule) && (string)$event->schedule !== $schedule) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            $event = null;
        }

        if (!$event && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $schedule, self::CRON_HOOK);
        }
    }

    public static function clearNotificationSchedule(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    public static function createLogTable(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::tableName($wpdb);
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(50) NOT NULL,
            flags TEXT NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            order_total DECIMAL(20,6) NULL,
            blocked_at DATETIME NOT NULL,
            notified TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY notified_index (notified, blocked_at),
            KEY ip_index (ip)
        ) {$wpdb->get_charset_collate()};";

        dbDelta($sql);
    }

    public static function tableDefinition(): string
    {
        return '
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip` VARCHAR(50) NOT NULL,
            `flags` TEXT NOT NULL,
            `order_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `order_total` DECIMAL(20,6) NULL,
            `blocked_at` DATETIME NOT NULL,
            `notified` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `notified_index` (`notified`, `blocked_at`),
            KEY `ip_index` (`ip`)
        ';
    }

    public static function tableName($wpdb): string
    {
        return $wpdb->prefix . self::TABLE_NAME;
    }

    private static function tableExists($wpdb, string $table): bool
    {
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
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
