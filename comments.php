<?php

$isBlacklistControl = dnsbl_check_blacklist($_SERVER['REMOTE_ADDR'], false, true);

if (is_admin() || current_user_can('administrator') && $isBlacklistControl) {
    ?>
    <div class="notice notice-error"
         style="font-weight: bold !important; background: #ffeeee; border:1px solid #990000; text-align: center;">
        <p>
            <?php echo __(
                'Tornevall DNSBL scanner has detected that your current visiting ip address is blacklisted!',
                'tornevall-networks-dnsbl-implementation'
            );
            ?>
            <br><a href="https://dnsbl.tornevall.org/removal?redirected" target="_blank">
                <?php echo __(
                    'For more information, look here',
                    'tornevall-networks-dnsbl-implementation'
                ); ?></a>
        </p>
    </div>
    <br>
    <?php
}

echo __(
    'The comment system on this DNSBL delisting page has been disabled as it does not cover delisting support via comments!',
    'tornevall-networks-dnsbl-implementation'
);
