<?php

if (!defined('ABSPATH')) {
    exit;
}

function tornevall_wp_dnsbl_admin()
{
    add_action('admin_init', 'register_dnsbl_settings');
    add_menu_page(
        'Tornevall DNSBL Options',
        __('Tornevall DNSBL', 'tornevall-networks-dnsbl-implementation'),
        'manage_options',
        'tornevallDnsblMenu',
        'tornevall_dnsbl_options'
    );
}

function tornevall_dnsbl_admin_get_default_resolvers()
{
    return tornevall_dnsbl_default_resolvers();
}

function tornevall_dnsbl_admin_get_default_filter_flags()
{
    return tornevall_dnsbl_default_selected_flags();
}

function tornevall_dnsbl_admin_get_configured_resolvers()
{
    return tornevall_dnsbl_get_resolver_hosts();
}

function tornevall_dnsbl_admin_get_current_flags()
{
    return tornevall_dnsbl_get_current_flag_map();
}

function tornevall_dnsbl_admin_sanitize_checkbox($value)
{
    return empty($value) ? '0' : '1';
}

function tornevall_dnsbl_admin_sanitize_cache_age($value)
{
    $cacheAge = absint($value);
    return $cacheAge < 900 ? 900 : $cacheAge;
}

function tornevall_dnsbl_admin_sanitize_resolver_hosts($value)
{
    if (is_array($value)) {
        $value = implode(',', $value);
    }

    $parts = preg_split('/[\s,]+/', strtolower((string)$value));
    $hosts = [];
    foreach ($parts as $part) {
        $host = trim($part);
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            continue;
        }
        $hosts[] = $host;
    }

    $hosts = array_values(array_unique($hosts));
    if (!count($hosts)) {
        $hosts = tornevall_dnsbl_admin_get_default_resolvers();
    }

    return implode(',', $hosts);
}

function tornevall_dnsbl_admin_sanitize_filter_types($value)
{
    $value = is_array($value) ? $value : [];
    $availableFlags = array_keys(tornevall_dnsbl_admin_get_current_flags());
    $clean = [];
    foreach ($value as $flag) {
        $flag = sanitize_text_field((string)$flag);
        if (in_array($flag, $availableFlags, true)) {
            $clean[] = $flag;
        }
    }

    $clean = array_values(array_unique($clean));
    if (!count($clean)) {
        $clean = tornevall_dnsbl_admin_get_default_filter_flags();
    }

    return $clean;
}

function tornevall_dnsbl_admin_sanitize_redirect_url($value)
{
    $value = esc_url_raw(trim((string)$value));
    if ($value === '') {
        $value = tornevall_dnsbl_default_blocked_redirect_url();
    }

    return $value;
}

function tornevall_dnsbl_admin_sanitize_comments_style($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        $value = tornevall_dnsbl_default_comments_disabled_style();
    }

    return sanitize_text_field($value);
}

function tornevall_dnsbl_admin_sanitize_tools_mode($value)
{
    $value = sanitize_text_field((string)$value);
    if (!in_array($value, ['auto', 'dev', 'prod'], true)) {
        $value = 'auto';
    }

    return $value;
}

function tornevall_dnsbl_admin_ensure_defaults()
{
    $defaults = [
        'tornevall_dnsbl_cache_age' => 900,
        'tornevall_dnsbl_filter_types' => tornevall_dnsbl_admin_get_default_filter_flags(),
        'tornevall_dnsbl_resolver_hosts' => implode(',', tornevall_dnsbl_admin_get_default_resolvers()),
        'tornevall_dnsbl_blocked_redirecturl' => tornevall_dnsbl_default_blocked_redirect_url(),
        'tornevall_dnsbl_comments_disabled_style' => tornevall_dnsbl_default_comments_disabled_style(),
        'tornevall_dnsbl_dev_mode' => '0',
        'tornevall_dnsbl_tools_token' => '',
        'tornevall_dnsbl_tools_mode' => 'auto',
        'tornevall_dnsbl_removal_token' => '',
    ];

    foreach ($defaults as $key => $value) {
        if (get_option($key) === false) {
            add_option($key, $value);
        }
    }
}

function register_dnsbl_settings()
{
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_cache_age', ['sanitize_callback' => 'tornevall_dnsbl_admin_sanitize_cache_age']);
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_filter_types', ['sanitize_callback' => 'tornevall_dnsbl_admin_sanitize_filter_types']);
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_nocomment', ['sanitize_callback' => 'tornevall_dnsbl_admin_sanitize_checkbox']);
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_blockfull', ['sanitize_callback' => 'tornevall_dnsbl_admin_sanitize_checkbox']);
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_delisting_page', ['sanitize_callback' => 'absint']);
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_resolver_hosts', ['sanitize_callback' => 'tornevall_dnsbl_admin_sanitize_resolver_hosts']);
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_blocked_redirecturl', ['sanitize_callback' => 'tornevall_dnsbl_admin_sanitize_redirect_url']);
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_comments_disabled_style', ['sanitize_callback' => 'tornevall_dnsbl_admin_sanitize_comments_style']);
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_delistingpage_comments_disabled', ['sanitize_callback' => 'tornevall_dnsbl_admin_sanitize_checkbox']);

    register_setting('dnsblOptions-group', 'tornevall_dnsbl_dev_mode', ['sanitize_callback' => 'tornevall_dnsbl_admin_sanitize_checkbox']);
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_tools_token', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_tools_mode', ['sanitize_callback' => 'tornevall_dnsbl_admin_sanitize_tools_mode']);
    register_setting('dnsblOptions-group', 'tornevall_dnsbl_removal_token', ['sanitize_callback' => 'sanitize_text_field']);
}

function tornevall_dnsbl_admin_render_checkbox_row($name, $label, $description, $checked)
{
    ?>
    <label style="display:block; margin-bottom:10px;">
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="0">
        <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($checked, true); ?>>
        <strong><?php echo esc_html($label); ?></strong>
        <span class="description" style="display:block; margin-top:3px;"><?php echo esc_html($description); ?></span>
    </label>
    <?php
}

function tornevall_dnsbl_admin_build_page_options($currentDelistingPage)
{
    $options = ['<option value="">' . esc_html__('None', 'tornevall-networks-dnsbl-implementation') . '</option>'];
    $pages = get_pages();
    if (is_array($pages)) {
        foreach ($pages as $pageObject) {
            $options[] = '<option value="' . esc_attr($pageObject->ID) . '" ' . selected((int)$pageObject->ID, (int)$currentDelistingPage, false) . '>' . esc_html($pageObject->post_title) . '</option>';
        }
    }

    return $options;
}

function tornevall_dnsbl_admin_run_lookup($ip)
{
    return tornevall_dnsbl_build_lookup_result($ip);
}

function tornevall_dnsbl_admin_get_self_check_candidates()
{
    $candidates = [];
    $siteHost = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $serverAddress = isset($_SERVER['SERVER_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_ADDR'])) : '';
    $remoteAddress = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

    if ($serverAddress && filter_var($serverAddress, FILTER_VALIDATE_IP)) {
        $candidates[__('Server address', 'tornevall-networks-dnsbl-implementation')] = $serverAddress;
    }
    if ($siteHost) {
        $resolvedSiteIp = gethostbyname($siteHost);
        if ($resolvedSiteIp && $resolvedSiteIp !== $siteHost && filter_var($resolvedSiteIp, FILTER_VALIDATE_IP)) {
            $candidates[__('Resolved site host', 'tornevall-networks-dnsbl-implementation')] = $resolvedSiteIp;
        }
    }
    if ($remoteAddress && filter_var($remoteAddress, FILTER_VALIDATE_IP)) {
        $candidates[__('Current visitor address', 'tornevall-networks-dnsbl-implementation')] = $remoteAddress;
    }

    return array_unique($candidates);
}

function tornevall_dnsbl_admin_get_tool_results($action, $request = [])
{
    if ($action === 'lookup_ip') {
        $ip = isset($request['tornevall_dnsbl_lookup_ip']) ? trim(sanitize_text_field(wp_unslash($request['tornevall_dnsbl_lookup_ip']))) : '';
        if ($ip === '') {
            return new WP_Error('lookup-empty', __('Address must not be empty', 'tornevall-networks-dnsbl-implementation'));
        }

        return [
            'title' => __('Manual DNS lookup result', 'tornevall-networks-dnsbl-implementation'),
            'rows' => [
                __('Requested address', 'tornevall-networks-dnsbl-implementation') => tornevall_dnsbl_admin_run_lookup($ip),
            ],
        ];
    }

    if ($action === 'self_check') {
        $rows = [];
        foreach (tornevall_dnsbl_admin_get_self_check_candidates() as $label => $ip) {
            $rows[$label] = tornevall_dnsbl_admin_run_lookup($ip);
        }

        if (!count($rows)) {
            return new WP_Error('self-check-empty', __('No valid local addresses were available for self-check.', 'tornevall-networks-dnsbl-implementation'));
        }

        return [
            'title' => __('Self-check result', 'tornevall-networks-dnsbl-implementation'),
            'rows' => $rows,
        ];
    }

    return new WP_Error('unknown-tool-action', __('Unknown tool request.', 'tornevall-networks-dnsbl-implementation'));
}

function tornevall_dnsbl_admin_handle_tools_request()
{
    $isAjaxRequest = function_exists('wp_doing_ajax') && wp_doing_ajax();
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
    if (!current_user_can('manage_options') || $isAjaxRequest || $requestMethod !== 'POST' || !isset($_POST['tornevall_dnsbl_tool_action'])) {
        return null;
    }

    $nonce = isset($_POST['tornevall_dnsbl_tools_nonce']) ? sanitize_text_field(wp_unslash($_POST['tornevall_dnsbl_tools_nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'tornevall_dnsbl_tools_action')) {
        add_settings_error('tornevall_dnsbl_admin_tools', 'invalid-tools-nonce', __('Security check failed. Please try again.', 'tornevall-networks-dnsbl-implementation'), 'error');
        return null;
    }

    $toolResults = tornevall_dnsbl_admin_get_tool_results(sanitize_text_field(wp_unslash($_POST['tornevall_dnsbl_tool_action'])), $_POST);
    if (is_wp_error($toolResults)) {
        add_settings_error('tornevall_dnsbl_admin_tools', $toolResults->get_error_code(), $toolResults->get_error_message(), $toolResults->get_error_code() === 'self-check-empty' ? 'warning' : 'error');
        return null;
    }

    return $toolResults;
}

function tornevall_dnsbl_admin_render_tool_results_markup($toolResults, $devMode, $resolverNames)
{
    ob_start();
    tornevall_dnsbl_admin_render_tool_results($toolResults, $devMode, $resolverNames);
    return trim((string)ob_get_clean());
}

function tornevall_dnsbl_admin_ajax_tools()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error([
            'message' => __('You do not have permission to run this tool.', 'tornevall-networks-dnsbl-implementation'),
        ], 403);
    }

    check_ajax_referer('tornevall_dnsbl_tools_action', 'tornevall_dnsbl_tools_nonce');

    $toolAction = isset($_POST['tornevall_dnsbl_tool_action']) ? sanitize_text_field(wp_unslash($_POST['tornevall_dnsbl_tool_action'])) : '';
    $toolResults = tornevall_dnsbl_admin_get_tool_results($toolAction, $_POST);
    if (is_wp_error($toolResults)) {
        wp_send_json_error([
            'message' => $toolResults->get_error_message(),
        ], $toolResults->get_error_code() === 'self-check-empty' ? 422 : 400);
    }

    $resolverNames = tornevall_dnsbl_admin_get_configured_resolvers();
    $devMode = get_option('tornevall_dnsbl_dev_mode') === '1';

    wp_send_json_success([
        'html' => tornevall_dnsbl_admin_render_tool_results_markup($toolResults, $devMode, $resolverNames),
    ]);
}

function tornevall_dnsbl_admin_render_ajax_results_container($toolResults, $devMode, $resolverNames)
{
    ?>
    <div id="tornevall-dnsbl-tool-results" aria-live="polite">
        <?php tornevall_dnsbl_admin_render_tool_results($toolResults, $devMode, $resolverNames); ?>
    </div>
    <?php
}

function tornevall_dnsbl_admin_render_tool_form($toolAction, $title, $buttonLabel, $contentCallback = null)
{
    ?>
    <form method="post" action="" data-tornevall-dnsbl-tool-form="1">
        <?php wp_nonce_field('tornevall_dnsbl_tools_action', 'tornevall_dnsbl_tools_nonce'); ?>
        <input type="hidden" name="tornevall_dnsbl_tool_action" value="<?php echo esc_attr($toolAction); ?>">
        <h3 style="margin-top:0;"><?php echo esc_html($title); ?></h3>
        <?php if (is_callable($contentCallback)) {
            call_user_func($contentCallback);
        } ?>
        <?php submit_button($buttonLabel, 'secondary', 'submit', false); ?>
    </form>
    <?php
}

function tornevall_dnsbl_admin_render_lookup_tool_fields()
{
    ?>
    <label for="tornevall_dnsbl_lookup_ip" class="screen-reader-text"><?php echo esc_html__('IP address to test', 'tornevall-networks-dnsbl-implementation'); ?></label>
    <input type="text" class="regular-text" id="tornevall_dnsbl_lookup_ip" name="tornevall_dnsbl_lookup_ip" placeholder="203.0.113.10">
    <?php
}

function tornevall_dnsbl_admin_render_ajax_notice($message, $type = 'error')
{
    ?>
    <div id="tornevall-dnsbl-tool-results" aria-live="polite">
        <div class="notice notice-<?php echo esc_attr($type); ?> inline"><p><?php echo esc_html($message); ?></p></div>
    </div>
    <?php
}

function tornevall_dnsbl_admin_render_tool_results($toolResults, $devMode, $resolverNames)
{
    if (!$toolResults || empty($toolResults['rows']) || !is_array($toolResults['rows'])) {
        return;
    }
    ?>
    <div class="postbox" style="margin-top:16px;">
        <div class="inside">
            <h2 style="margin-top:0;"><?php echo esc_html($toolResults['title']); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(__('Resolvers used for this check: %s', 'tornevall-networks-dnsbl-implementation'), implode(', ', $resolverNames))); ?></p>
            <table class="widefat striped">
                <thead>
                <tr>
                    <th><?php echo esc_html__('Check', 'tornevall-networks-dnsbl-implementation'); ?></th>
                    <th><?php echo esc_html__('Address', 'tornevall-networks-dnsbl-implementation'); ?></th>
                    <th><?php echo esc_html__('Status', 'tornevall-networks-dnsbl-implementation'); ?></th>
                    <th><?php echo esc_html__('Details', 'tornevall-networks-dnsbl-implementation'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($toolResults['rows'] as $label => $row) { ?>
                    <tr>
                        <td><strong><?php echo esc_html($label); ?></strong></td>
                        <td><code><?php echo esc_html($row['ip']); ?></code></td>
                        <td><strong style="color:<?php echo $row['listed'] ? '#b91c1c' : '#15803d'; ?>;"><?php echo esc_html($row['message']); ?></strong></td>
                        <td>
                            <?php if (!empty($row['constants'])) { ?>
                                <div><?php echo esc_html(sprintf(__('Matched flags: %s', 'tornevall-networks-dnsbl-implementation'), implode(', ', $row['constants']))); ?></div>
                                <div class="description"><?php echo esc_html(sprintf(__('Bitmask: %d', 'tornevall-networks-dnsbl-implementation'), (int)$row['typebit'])); ?></div>
                            <?php } else { ?>
                                <span class="description"><?php echo esc_html__('No blacklist flags returned for this address.', 'tornevall-networks-dnsbl-implementation'); ?></span>
                            <?php } ?>
                            <?php if ($devMode && !empty($row['raw'])) { ?>
                                <details style="margin-top:8px;">
                                    <summary><?php echo esc_html__('Raw diagnostic response', 'tornevall-networks-dnsbl-implementation'); ?></summary>
                                    <pre style="white-space:pre-wrap; word-break:break-word; background:#f6f7f7; padding:10px; border:1px solid #dcdcde;"><?php echo esc_html(tornevall_dnsbl_format_diagnostic_payload($row['raw'])); ?></pre>
                                </details>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function tornevall_dnsbl_options()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'tornevall-networks-dnsbl-implementation'));
    }

    tornevall_dnsbl_admin_ensure_defaults();

    $currentDelistingPage = (int)get_option('tornevall_dnsbl_delisting_page');
    $delistPageOption = tornevall_dnsbl_admin_build_page_options($currentDelistingPage);
    $resolverNames = tornevall_dnsbl_admin_get_configured_resolvers();

    $currentFlags = tornevall_dnsbl_admin_get_current_flags();
    $savedFlags = get_option('tornevall_dnsbl_filter_types');
    if (!is_array($savedFlags)) {
        $savedFlags = tornevall_dnsbl_admin_get_default_filter_flags();
    }

    $flagListSelector = [];
    foreach ($currentFlags as $flag => $bitValue) {
        $flagListSelector[] = '<option value="' . esc_attr($flag) . '" ' . selected(in_array($flag, $savedFlags, true), true, false) . '>' . esc_html($flag . ' [' . $bitValue . ']') . '</option>';
    }

    $cacheAge = max(900, (int)get_option('tornevall_dnsbl_cache_age'));
    $redirectUrl = (string)get_option('tornevall_dnsbl_blocked_redirecturl');
    if ($redirectUrl === '') {
        $redirectUrl = tornevall_dnsbl_default_blocked_redirect_url();
    }
    $commentsStyle = (string)get_option('tornevall_dnsbl_comments_disabled_style');
    if ($commentsStyle === '') {
        $commentsStyle = tornevall_dnsbl_default_comments_disabled_style();
    }
    $devMode = get_option('tornevall_dnsbl_dev_mode') === '1';
    $toolsTokenSet = trim((string)get_option('tornevall_dnsbl_tools_token')) !== '';
    $toolsMode = (string)get_option('tornevall_dnsbl_tools_mode');
    if (!in_array($toolsMode, ['auto', 'dev', 'prod'], true)) {
        $toolsMode = 'auto';
    }

    $resolvedToolsBase = tornevall_dnsbl_tools_base_url();
    $toolResults = tornevall_dnsbl_admin_handle_tools_request();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('DNS Blacklist Configurator', 'tornevall-networks-dnsbl-implementation'); ?></h1>
        <p class="description"><?php echo esc_html__('The plugin prioritizes direct DNS lookups. Optional Tools integration is used for enhanced comment risk assessment.', 'tornevall-networks-dnsbl-implementation'); ?></p>

        <?php settings_errors(); ?>
        <?php settings_errors('tornevall_dnsbl_admin_tools'); ?>

        <div class="notice notice-info inline">
            <p><?php echo esc_html(sprintf(__('Supported resolvers in active use: %s', 'tornevall-networks-dnsbl-implementation'), implode(', ', tornevall_dnsbl_admin_get_default_resolvers()))); ?></p>
            <p><?php echo esc_html(sprintf(__('Current Tools base URL: %s', 'tornevall-networks-dnsbl-implementation'), $resolvedToolsBase)); ?></p>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin:16px 0;">
            <div class="postbox"><div class="inside">
                    <h2 style="margin-top:0;"><?php echo esc_html__('At a glance', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                    <ul style="margin:0; padding-left:18px;">
                        <li><?php echo esc_html(sprintf(__('Resolver count: %d', 'tornevall-networks-dnsbl-implementation'), count($resolverNames))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Trigger flags selected: %d', 'tornevall-networks-dnsbl-implementation'), count($savedFlags))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Tools token configured: %s', 'tornevall-networks-dnsbl-implementation'), $toolsTokenSet ? __('yes', 'tornevall-networks-dnsbl-implementation') : __('no', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Dev mode: %s', 'tornevall-networks-dnsbl-implementation'), $devMode ? __('enabled', 'tornevall-networks-dnsbl-implementation') : __('disabled', 'tornevall-networks-dnsbl-implementation'))); ?></li>
                    </ul>
                </div></div>
            <div class="postbox"><div class="inside">
                    <h2 style="margin-top:0;"><?php echo esc_html__('Plugin information and help', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                    <p><a href="https://tools.tornevall.net/docs/dnsbl-plugin" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('DNSBL plugin documentation', 'tornevall-networks-dnsbl-implementation'); ?></a></p>
                    <p><a href="https://github.com/Tornevall/tornevall-wp-dnsbl/issues" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('GitHub issue tracker', 'tornevall-networks-dnsbl-implementation'); ?></a></p>
                </div></div>
        </div>

        <div class="postbox" style="margin-top:16px;">
            <div class="inside">
                <h2 style="margin-top:0;"><?php echo esc_html__('Try-tests and self-check', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                <p class="description"><?php echo esc_html__('Use these tools to test direct DNS lookups without changing your saved settings.', 'tornevall-networks-dnsbl-implementation'); ?></p>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px;">
                    <?php tornevall_dnsbl_admin_render_tool_form('lookup_ip', __('Lookup a specific IP address', 'tornevall-networks-dnsbl-implementation'), __('Run DNS lookup', 'tornevall-networks-dnsbl-implementation'), 'tornevall_dnsbl_admin_render_lookup_tool_fields'); ?>
                    <?php tornevall_dnsbl_admin_render_tool_form('self_check', __('Check this server environment', 'tornevall-networks-dnsbl-implementation'), __('Run self-check', 'tornevall-networks-dnsbl-implementation')); ?>
                </div>
                <?php if ($toolResults) {
                    tornevall_dnsbl_admin_render_ajax_results_container($toolResults, $devMode, $resolverNames);
                } else {
                    tornevall_dnsbl_admin_render_ajax_notice(__('Tool results will appear here without reloading the page.', 'tornevall-networks-dnsbl-implementation'), 'info');
                } ?>
            </div>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
            <?php settings_fields('dnsblOptions-group'); ?>
            <?php do_settings_sections('dnsblOptions-group'); ?>

            <div class="postbox" style="margin-top:16px;"><div class="inside">
                    <h2 style="margin-top:0;"><?php echo esc_html__('Core DNS lookup settings', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="tornevall_dnsbl_resolver_hosts"><?php echo esc_html__('Preferred resolver hosts', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td>
                                <input type="text" class="regular-text" id="tornevall_dnsbl_resolver_hosts" name="tornevall_dnsbl_resolver_hosts" value="<?php echo esc_attr(implode(',', $resolverNames)); ?>">
                            </td></tr>
                        <tr><th scope="row"><label for="tornevall_dnsbl_cache_age"><?php echo esc_html__('Cache age', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td>
                                <input type="number" min="900" step="60" id="tornevall_dnsbl_cache_age" name="tornevall_dnsbl_cache_age" value="<?php echo esc_attr((string)$cacheAge); ?>">
                            </td></tr>
                        <tr><th scope="row"><label for="tornevall_dnsbl_filter_types"><?php echo esc_html__('Trigger on blacklist flags', 'tornevall-networks-dnsbl-implementation'); ?></label></th><td>
                                <select multiple size="8" id="tornevall_dnsbl_filter_types" name="tornevall_dnsbl_filter_types[]" style="min-width:320px;"><?php echo implode("\n", $flagListSelector); ?></select>
                            </td></tr>
                    </table>
                </div></div>

            <div class="postbox" style="margin-top:16px;"><div class="inside">
                    <h2 style="margin-top:0;"><?php echo esc_html__('Protection behavior', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                    <?php
                    tornevall_dnsbl_admin_render_checkbox_row('tornevall_dnsbl_nocomment', __('Hide comments for listed visitors', 'tornevall-networks-dnsbl-implementation'), __('Hides the comment section when a visitor matches the selected blacklist flags.', 'tornevall-networks-dnsbl-implementation'), (bool)get_option('tornevall_dnsbl_nocomment'));
                    tornevall_dnsbl_admin_render_checkbox_row('tornevall_dnsbl_blockfull', __('Redirect listed visitors away from the page', 'tornevall-networks-dnsbl-implementation'), __('Immediately redirects listed visitors away from the page. Logged-in administrators are still protected from lockout.', 'tornevall-networks-dnsbl-implementation'), (bool)get_option('tornevall_dnsbl_blockfull'));
                    ?>
                    <p><label><?php echo esc_html__('Blocked visitor redirect URL', 'tornevall-networks-dnsbl-implementation'); ?><br><input type="url" class="regular-text" name="tornevall_dnsbl_blocked_redirecturl" value="<?php echo esc_attr($redirectUrl); ?>"></label></p>
                    <p><label><?php echo esc_html__('Admin notice style for comment blocking', 'tornevall-networks-dnsbl-implementation'); ?><br><input type="text" class="regular-text" name="tornevall_dnsbl_comments_disabled_style" value="<?php echo esc_attr($commentsStyle); ?>"></label></p>
                </div></div>

            <div class="postbox" style="margin-top:16px;"><div class="inside">
                    <h2 style="margin-top:0;"><?php echo esc_html__('Tools integration and development', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                    <?php tornevall_dnsbl_admin_render_checkbox_row('tornevall_dnsbl_dev_mode', __('Show extended diagnostics in the admin panel', 'tornevall-networks-dnsbl-implementation'), __('Shows raw diagnostic responses in the try-test and self-check tools.', 'tornevall-networks-dnsbl-implementation'), $devMode); ?>
                    <p><label for="tornevall_dnsbl_tools_mode"><?php echo esc_html__('Tools environment mode', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                        <select id="tornevall_dnsbl_tools_mode" name="tornevall_dnsbl_tools_mode">
                            <option value="auto" <?php selected($toolsMode, 'auto'); ?>><?php echo esc_html__('Auto (from dev mode)', 'tornevall-networks-dnsbl-implementation'); ?></option>
                            <option value="dev" <?php selected($toolsMode, 'dev'); ?>><?php echo esc_html__('Force dev (tools.tornevall.com)', 'tornevall-networks-dnsbl-implementation'); ?></option>
                            <option value="prod" <?php selected($toolsMode, 'prod'); ?>><?php echo esc_html__('Force prod (tools.tornevall.net)', 'tornevall-networks-dnsbl-implementation'); ?></option>
                        </select>
                    </p>
                    <p><label for="tornevall_dnsbl_tools_token"><?php echo esc_html__('Tools token', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                        <input type="password" class="regular-text" id="tornevall_dnsbl_tools_token" name="tornevall_dnsbl_tools_token" value="<?php echo esc_attr((string)get_option('tornevall_dnsbl_tools_token')); ?>"></p>
                    <p><label for="tornevall_dnsbl_removal_token_display"><?php echo esc_html__('Removal token', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                        <input type="hidden" name="tornevall_dnsbl_removal_token" value="<?php echo esc_attr((string)get_option('tornevall_dnsbl_removal_token')); ?>">
                        <input type="text" class="regular-text" id="tornevall_dnsbl_removal_token_display" value="<?php echo esc_attr((string)get_option('tornevall_dnsbl_removal_token')); ?>" placeholder="<?php echo esc_attr__('Coming soon', 'tornevall-networks-dnsbl-implementation'); ?>" disabled></p>
                </div></div>

            <div class="postbox" style="margin-top:16px;"><div class="inside">
                    <h2 style="margin-top:0;"><?php echo esc_html__('Delisting page integration', 'tornevall-networks-dnsbl-implementation'); ?></h2>
                    <p><label for="tornevall_dnsbl_delisting_page"><?php echo esc_html__('Delisting page', 'tornevall-networks-dnsbl-implementation'); ?></label><br>
                        <select id="tornevall_dnsbl_delisting_page" name="tornevall_dnsbl_delisting_page"><?php echo implode("\n", $delistPageOption); ?></select></p>
                    <?php
                    tornevall_dnsbl_admin_render_checkbox_row('tornevall_dnsbl_delistingpage_comments_disabled', __('Disable comments on the delisting page', 'tornevall-networks-dnsbl-implementation'), __('Useful if the delisting page attracts many support comments.', 'tornevall-networks-dnsbl-implementation'), (bool)get_option('tornevall_dnsbl_delistingpage_comments_disabled'));
                    ?>
                </div></div>

            <?php submit_button(__('Save settings', 'tornevall-networks-dnsbl-implementation')); ?>
        </form>
    </div>
    <?php
}
