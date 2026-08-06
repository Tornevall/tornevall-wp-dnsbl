# WooCommerce checkout protection

Version 3.1.6 adds a WooCommerce-specific DNSBL policy for both the classic shortcode checkout and Checkout Blocks or Store API checkout.

## Policy

The WooCommerce policy is independent from the global comment and registration policy. The resolved bitmask is compared only with the flags selected under Tornevall DNSBL -> WooCommerce.

The default checkout flags are:

- `IP_FRAUDCOMMERCE`
- `IP_SECOND_EXIT`

Whitelisted addresses and logged-in administrators placing test orders are not blocked. The emergency constant below disables all DNSBL enforcement hooks for the request:

```php
define('TORNEVALL_DNSBL_ADMIN_BYPASS', true);
```

## Checkout behavior

Classic checkout uses `woocommerce_checkout_process`, which prevents order creation through a WooCommerce error notice or redirects to the configured blocked URL.

Checkout Blocks and Store API checkout use `woocommerce_store_api_checkout_update_order_meta`. Throwing a Store API `RouteException` prevents payment and shows the customer-facing error in the block checkout. A REST response cannot navigate the browser, so redirect modes include the configured information URL in the error instead of attempting an HTTP redirect.

## Customer guidance

The merchant can configure the base message. A support instruction is appended automatically. Delisting guidance is suppressed when any matched flag is one of:

- `IP_PHISHING`
- `IP_FRAUDCOMMERCE`
- `IP_ABUSE_NO_SMTP`

These flags represent specific or active abuse and must not offer self-delisting from checkout.

## Notifications

Notifications use WordPress `wp_mail()` and can be disabled, sent instantly, or collected for an hourly, twice-daily, or daily digest. An empty recipient field falls back to the WordPress administration email.

Bulk notification rows are stored in:

```text
{prefix}tornevall_dnsbl_wc_blocked_log
```

## wp-admin protection

The optional `tornevall_dnsbl_protect_wp_admin` setting applies the global DNSBL policy to normal wp-admin requests. AJAX and cron requests are excluded. Whitelists continue to work, and the emergency constant can be added to `wp-config.php` if an administrator is locked out.
