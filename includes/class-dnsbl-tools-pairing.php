<?php

namespace Tornevall\Networks\DNSBL;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direct device-style authorization between this DNSBL plugin and Tools.
 *
 * The browser is only used for the logged-in Tools approval step. The raw
 * device code and returned DNSBL credential stay server-side in WordPress.
 */
class ToolsPairing
{
    private const START_ACTION = 'tornevall_dnsbl_tools_pairing_start';
    private const COMPLETE_ACTION = 'tornevall_dnsbl_tools_pairing_complete';
    private const TRANSIENT_PREFIX = 'tornevall_dnsbl_tools_pairing_';
    private const MAX_PAIRING_TTL = 600;

    public static function registerHooks(): void
    {
        add_action('admin_post_' . self::START_ACTION, [self::class, 'start']);
        add_action('admin_post_' . self::COMPLETE_ACTION, [self::class, 'complete']);
        add_action('admin_notices', [self::class, 'renderAdminNotice']);
    }

    public static function start(): void
    {
        self::requireAdmin();
        check_admin_referer(self::START_ACTION);

        $state = wp_generate_password(48, false, false);
        $callbackUrl = add_query_arg([
            'action' => self::COMPLETE_ACTION,
            'pair_state' => $state,
            '_wpnonce' => wp_create_nonce(self::COMPLETE_ACTION),
        ], admin_url('admin-post.php'));

        $response = self::postTools('/api/integrations/wordpress/device', [
            'site_name' => get_bloginfo('name') . ' - Tornevall DNSBL',
            'site_url' => site_url('/'),
            'callback_url' => $callbackUrl,
            'requested_services' => ['dnsbl'],
        ]);

        if (is_wp_error($response)) {
            self::redirectWithNotice('error', $response->get_error_message());
        }

        $deviceCode = isset($response['device_code']) ? trim((string)$response['device_code']) : '';
        $userCode = isset($response['user_code']) ? sanitize_text_field((string)$response['user_code']) : '';
        $verificationUrl = isset($response['verification_uri_complete'])
            ? esc_url_raw((string)$response['verification_uri_complete'])
            : '';
        $expiresIn = isset($response['expires_in']) ? absint($response['expires_in']) : self::MAX_PAIRING_TTL;

        if ($deviceCode === '' || $userCode === '' || !self::isToolsAuthorizationUrl($verificationUrl)) {
            self::redirectWithNotice('error', __('Tools returned an invalid DNSBL pairing response.', 'tornevall-networks-dnsbl-implementation'));
        }

        set_transient(self::transientKey(), [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'state_hash' => hash('sha256', $state),
        ], max(60, min(self::MAX_PAIRING_TTL, $expiresIn)));

        wp_redirect($verificationUrl);
        exit;
    }

    public static function complete(): void
    {
        self::requireAdmin();
        check_admin_referer(self::COMPLETE_ACTION);

        $pairing = get_transient(self::transientKey());
        $pairing = is_array($pairing) ? $pairing : [];
        $state = isset($_GET['pair_state']) ? sanitize_text_field(wp_unslash((string)$_GET['pair_state'])) : '';
        $userCode = isset($_GET['user_code']) ? sanitize_text_field(wp_unslash((string)$_GET['user_code'])) : '';
        $connectionState = isset($_GET['tools_connection'])
            ? sanitize_key(wp_unslash((string)$_GET['tools_connection']))
            : (isset($_GET['ttfw_connection']) ? sanitize_key(wp_unslash((string)$_GET['ttfw_connection'])) : '');

        if (
            empty($pairing['device_code'])
            || empty($pairing['user_code'])
            || empty($pairing['state_hash'])
            || $state === ''
            || !hash_equals((string)$pairing['state_hash'], hash('sha256', $state))
            || $userCode === ''
            || !hash_equals((string)$pairing['user_code'], $userCode)
        ) {
            delete_transient(self::transientKey());
            self::redirectWithNotice('error', __('The DNSBL Tools pairing session is missing, expired, or does not match this callback.', 'tornevall-networks-dnsbl-implementation'));
        }

        if ($connectionState === 'denied') {
            delete_transient(self::transientKey());
            self::redirectWithNotice('denied', __('The DNSBL Tools connection was denied.', 'tornevall-networks-dnsbl-implementation'));
        }

        if ($connectionState !== 'complete') {
            delete_transient(self::transientKey());
            self::redirectWithNotice('error', __('Tools returned an unknown DNSBL pairing state.', 'tornevall-networks-dnsbl-implementation'));
        }

        $response = self::postTools('/api/integrations/wordpress/token', [
            'device_code' => (string)$pairing['device_code'],
        ]);
        delete_transient(self::transientKey());

        if (is_wp_error($response)) {
            self::redirectWithNotice('error', $response->get_error_message());
        }

        $dnsbl = isset($response['services']['dnsbl']) && is_array($response['services']['dnsbl'])
            ? $response['services']['dnsbl']
            : [];
        $token = isset($dnsbl['token']) && is_scalar($dnsbl['token'])
            ? Admin::sanitizeDnsblWriteToken((string)$dnsbl['token'])
            : '';

        if (empty($dnsbl['available']) || $token === '') {
            $reason = isset($dnsbl['reason']) ? sanitize_key((string)$dnsbl['reason']) : '';
            $message = $reason === 'no_active_dnsbl_token'
                ? __('The connected Tools account has no active DNSBL token available.', 'tornevall-networks-dnsbl-implementation')
                : __('Tools approved the connection but returned no usable DNSBL credential.', 'tornevall-networks-dnsbl-implementation');
            self::redirectWithNotice('error', $message);
        }

        update_option('tornevall_dnsbl_write_token', $token, false);

        $credentialMode = isset($dnsbl['credential_mode'])
            ? sanitize_key((string)$dnsbl['credential_mode'])
            : '';

        self::redirectWithNotice(
            'connected',
            self::successMessage($credentialMode),
            $credentialMode
        );
    }

    public static function renderAdminNotice(): void
    {
        if (!current_user_can('manage_options') || !self::isDnsblSettingsPage()) {
            return;
        }

        self::renderResultNotice();

        $mode = Plugin::toolsMode();
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::START_ACTION),
            self::START_ACTION
        );
        $hasToken = Plugin::writeTokenSet();
        $description = $hasToken
            ? __('Authorize with your Tools account to select this or another DNSBL token. By default Tools rotates the selected existing token and installs its new value here automatically; you can choose a separate site token on the approval page instead.', 'tornevall-networks-dnsbl-implementation')
            : __('Authorize with your Tools account to select an existing DNSBL token. By default Tools rotates that token and installs its new value here automatically; you can choose a separate site token on the approval page instead.', 'tornevall-networks-dnsbl-implementation');
        $buttonLabel = $hasToken
            ? __('Connect / rotate token via Tools', 'tornevall-networks-dnsbl-implementation')
            : __('Connect to Tools and select token', 'tornevall-networks-dnsbl-implementation');

        echo '<div class="notice notice-info" style="padding:14px 18px;">';
        echo '<p><strong>' . esc_html__('Connect DNSBL directly to Tornevall Tools', 'tornevall-networks-dnsbl-implementation') . '</strong></p>';
        echo '<p>' . esc_html($description) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html($buttonLabel) . '</a></p>';
        echo '<p class="description">' . esc_html(sprintf(
            __('Authorization host: %s. No token value is displayed in the browser.', 'tornevall-networks-dnsbl-implementation'),
            Plugin::toolsBaseUrl()
        )) . '</p>';
        if ($mode === 'dev') {
            echo '<p class="description"><strong>' . esc_html__('Development mode is active.', 'tornevall-networks-dnsbl-implementation') . '</strong></p>';
        }
        echo '</div>';
    }

    private static function renderResultNotice(): void
    {
        $notice = isset($_GET['tornevall_dnsbl_pairing'])
            ? sanitize_key(wp_unslash((string)$_GET['tornevall_dnsbl_pairing']))
            : '';
        if (!in_array($notice, ['connected', 'denied', 'error'], true)) {
            return;
        }

        $message = isset($_GET['tornevall_dnsbl_pairing_message'])
            ? sanitize_text_field(wp_unslash((string)$_GET['tornevall_dnsbl_pairing_message']))
            : '';
        if ($message === '') {
            $message = $notice === 'connected'
                ? __('The DNSBL Tools connection was updated.', 'tornevall-networks-dnsbl-implementation')
                : __('The DNSBL Tools connection could not be completed.', 'tornevall-networks-dnsbl-implementation');
        }

        $class = $notice === 'connected' ? 'notice-success' : ($notice === 'denied' ? 'notice-warning' : 'notice-error');
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * @param string              $path API path.
     * @param array<string,mixed> $payload Request payload.
     * @return array<string,mixed>|WP_Error
     */
    private static function postTools(string $path, array $payload)
    {
        $url = untrailingslashit(Plugin::toolsBaseUrl()) . '/' . ltrim($path, '/');
        $response = wp_remote_post($url, [
            'timeout' => 20,
            'redirection' => 0,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('tornevall_dnsbl_tools_pairing_transport', __('Could not reach Tornevall Tools.', 'tornevall-networks-dnsbl-implementation'));
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : [];

        if ($status < 200 || $status >= 300) {
            $message = isset($body['message']) && is_scalar($body['message'])
                ? sanitize_text_field((string)$body['message'])
                : __('Tornevall Tools rejected the DNSBL pairing request.', 'tornevall-networks-dnsbl-implementation');
            return new WP_Error('tornevall_dnsbl_tools_pairing_http', $message, ['status' => $status]);
        }

        return $body;
    }

    private static function isToolsAuthorizationUrl(string $url): bool
    {
        if (!wp_http_validate_url($url)) {
            return false;
        }

        $expectedHost = strtolower((string)wp_parse_url(Plugin::toolsBaseUrl(), PHP_URL_HOST));
        return strtolower((string)wp_parse_url($url, PHP_URL_SCHEME)) === 'https'
            && strtolower((string)wp_parse_url($url, PHP_URL_HOST)) === $expectedHost;
    }

    private static function isDnsblSettingsPage(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string)$_GET['page'])) : '';
        return $page === 'tornevalldnsblmenu';
    }

    private static function transientKey(): string
    {
        return self::TRANSIENT_PREFIX . get_current_user_id();
    }

    private static function successMessage(string $credentialMode): string
    {
        if ($credentialMode === 'rotated_existing') {
            return __('The selected existing DNSBL token was rotated and its new value is now configured in this plugin. The previous token value is no longer valid.', 'tornevall-networks-dnsbl-implementation');
        }
        if ($credentialMode === 'copied_from_admin') {
            return __('Tools created a non-admin DNSBL credential from the selected admin permissions and configured it in this plugin. The admin token itself was not changed.', 'tornevall-networks-dnsbl-implementation');
        }
        if ($credentialMode === 'created_copy') {
            return __('Tools created a separate DNSBL site token and configured it in this plugin. The selected existing token was not changed.', 'tornevall-networks-dnsbl-implementation');
        }

        return __('The DNSBL credential returned by Tools is now configured in this plugin.', 'tornevall-networks-dnsbl-implementation');
    }

    private static function redirectWithNotice(string $notice, string $message, string $credentialMode = ''): void
    {
        $args = [
            'page' => 'tornevallDnsblMenu',
            'tornevall_dnsbl_pairing' => sanitize_key($notice),
            'tornevall_dnsbl_pairing_message' => sanitize_text_field($message),
        ];
        if ($credentialMode !== '') {
            $args['tornevall_dnsbl_credential_mode'] = sanitize_key($credentialMode);
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function requireAdmin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You are not allowed to manage DNSBL Tools authorization.', 'tornevall-networks-dnsbl-implementation'),
                '',
                ['response' => 403]
            );
        }
    }
}
