<?php

use Tornevall\Networks\DNSBL\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

$dnsbl_blacklist_status = false;
$dnsbl_blacklist_control_status = 'unchecked';

function tornevall_dnsbl_is_admin()
{
    return Plugin::isAdminContext();
}

function tornevall_dnsbl_enqueue($hook = '')
{
    Plugin::enqueue($hook);
}

function tornevall_dnsbl_checkpoint()
{
    Plugin::checkpoint();
}

function tornevall_dnsbl_register_hooks()
{
    Plugin::registerHooks();
}

