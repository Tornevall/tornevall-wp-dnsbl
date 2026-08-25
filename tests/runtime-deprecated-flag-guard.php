<?php

define('ABSPATH', __DIR__ . '/');

namespace Tornevall\Networks\DNSBL {
    class Plugin
    {
        public static function normalizeSelectedFlags($selected): array
        {
            if (!is_array($selected)) {
                return [];
            }

            $active = [
                'FREE_SLOT_1_PREVIOUSLY_REPORTED',
                'IP_CONFIRMED',
                'IP_PHISHING',
                'IP_FRAUDCOMMERCE',
                'IP_MAILSERVER_SPAM',
                'IP_SECOND_EXIT',
                'IP_ABUSE_NO_SMTP',
                'IP_ANONYMOUS',
            ];

            return array_values(array_unique(array_values(array_filter(
                $selected,
                static function ($flag) use ($active): bool {
                    return is_string($flag) && in_array($flag, $active, true);
                }
            ))));
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/includes/class-dnsbl-deprecated-flag-guard.php';

    use Tornevall\Networks\DNSBL\DeprecatedFlagGuard;

    $selection = DeprecatedFlagGuard::normalizeSelection([
        'FREE_SLOT_1_PREVIOUSLY_REPORTED',
        'IP_CONFIRMED',
        'IP_FRAUDCOMMERCE',
        'IP_ABUSE_NO_SMTP',
        'IP_CONFIRMED',
    ]);

    $expected = [
        'IP_CONFIRMED',
        'IP_FRAUDCOMMERCE',
        'IP_ABUSE_NO_SMTP',
    ];

    if ($selection !== $expected) {
        fwrite(STDERR, 'Deprecated flag normalization failed.' . PHP_EOL);
        exit(1);
    }

    $saved = DeprecatedFlagGuard::normalizeForStorage([
        'FREE_SLOT_1_PREVIOUSLY_REPORTED',
        'IP_SECOND_EXIT',
        'IP_ANONYMOUS',
    ]);

    if ($saved !== ['IP_SECOND_EXIT', 'IP_ANONYMOUS']) {
        fwrite(STDERR, 'Saved trigger normalization failed.' . PHP_EOL);
        exit(1);
    }

    if (in_array('FREE_SLOT_1_PREVIOUSLY_REPORTED', DeprecatedFlagGuard::normalizeSelection([
        'FREE_SLOT_1_PREVIOUSLY_REPORTED',
    ]), true)) {
        fwrite(STDERR, 'Deprecated bit 1 remained selectable.' . PHP_EOL);
        exit(1);
    }

    echo 'Deprecated flag guard checks passed.' . PHP_EOL;
}
