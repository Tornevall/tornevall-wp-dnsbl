<?php

if (!defined('ABSPATH')) {
    exit;
}

$contentHtml = isset($content_html) ? (string) $content_html : '';
$formHtml = isset($form_html) ? (string) $form_html : '';
$showCustomPagesHelp = !isset($show_custom_pages_help) || (bool) $show_custom_pages_help;
?>
<section class="tornevall-dnsbl-removal-page" style="display:grid; gap:1.25rem;">
    <div style="padding:1.25rem 1.35rem; border-radius:14px; background:linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%); border:1px solid #bfdbfe;">
        <h2 style="margin:0 0 .6rem 0;"><?php echo esc_html__('DNSBL delisting and removal', 'tornevall-networks-dnsbl-implementation'); ?></h2>
        <p style="margin:0; max-width:58rem; color:#334155; line-height:1.65;">
            <?php echo esc_html__('Use this page to check one IP address at a time. If the IP is currently listed, the plugin sends a delist request through this site’s configured DNSBL / Tools API token. After a successful request, it can still take a little while before all resolvers show the updated result.', 'tornevall-networks-dnsbl-implementation'); ?>
        </p>
    </div>

    <?php if ($contentHtml !== '') { ?>
        <div class="tornevall-dnsbl-removal-page__content">
            <?php echo $contentHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already processed by the_content before template injection. ?>
        </div>
    <?php } ?>

    <div class="tornevall-dnsbl-removal-page__form" style="padding:1.1rem 1.2rem; border-radius:14px; border:1px solid #e2e8f0; background:#ffffff; box-shadow:0 10px 35px rgba(15, 23, 42, 0.05);">
        <?php echo $formHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form markup is produced by the plugin renderer. ?>
    </div>

    <?php if ($showCustomPagesHelp) { ?>
        <div style="padding:1rem 1.1rem; border-radius:12px; border:1px solid #e2e8f0; background:#f8fafc; color:#475569;">
            <strong><?php echo esc_html__('Custom removal pages', 'tornevall-networks-dnsbl-implementation'); ?></strong>
            <p style="margin:.5rem 0 0 0; line-height:1.6;">
                <?php echo esc_html__('If you want your own WordPress page layout, keep your own page content and place the shortcode below where the DNSBL form should appear. The shortcode automatically respects the current token permissions.', 'tornevall-networks-dnsbl-implementation'); ?>
            </p>
            <p style="margin:.7rem 0 0 0;"><code>[dnsbl_removal_form]</code> &nbsp; <code>[tornevall_dnsbl_removal_form]</code></p>
        </div>
    <?php } ?>
</section>

