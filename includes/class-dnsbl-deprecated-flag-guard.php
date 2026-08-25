<?php

namespace Tornevall\Networks\DNSBL;

if (!defined('ABSPATH')) {
    exit;
}

class DeprecatedFlagGuard
{
    public const DEPRECATED_TRIGGER_FLAG = 'FREE_SLOT_1_PREVIOUSLY_REPORTED';

    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'normalizeStoredSelection'], 1);
        add_filter('pre_update_option_tornevall_dnsbl_filter_types', [self::class, 'normalizeForStorage'], 10, 3);
        add_action('admin_footer', [self::class, 'removeDeprecatedAdminOption']);
    }

    public static function normalizeSelection($selected): array
    {
        $normalized = Plugin::normalizeSelectedFlags($selected);

        return array_values(array_filter(
            $normalized,
            static function ($flagName): bool {
                return $flagName !== self::DEPRECATED_TRIGGER_FLAG;
            }
        ));
    }

    public static function normalizeForStorage($newValue, $oldValue = null, $option = null): array
    {
        return self::normalizeSelection($newValue);
    }

    public static function normalizeStoredSelection(): void
    {
        $selected = get_option('tornevall_dnsbl_filter_types');
        $normalized = self::normalizeSelection($selected);

        if (!is_array($selected) || $selected !== $normalized) {
            update_option('tornevall_dnsbl_filter_types', $normalized);
        }
    }

    public static function removeDeprecatedAdminOption(): void
    {
        if (!is_admin()) {
            return;
        }

        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var selector = document.getElementById('tornevall_dnsbl_filter_types');
                if (!selector) {
                    return;
                }

                var deprecated = selector.querySelector('option[value="<?php echo esc_js(self::DEPRECATED_TRIGGER_FLAG); ?>"]');
                if (deprecated) {
                    deprecated.remove();
                }
            });
        </script>
        <?php
    }
}
