# Tornevall Networks DNSBL Implementation

WordPress plugin for DNSBL/FraudBL-based protection of comments, registrations, commerce fraud signals and other abuse-prone submission flows.

## Release metadata

- **Release:** `3.2.0`
- **Requires at least:** `5.8`
- **Requires PHP:** `8.1`
- **Tested up to:** `7.0`
- **Plugin URL:** <https://wordpress.org/plugins/tornevall-networks-dnsbl-implementation/>
- **Project URL:** <https://github.com/Tornevall/tornevall-wp-dnsbl>
- **Issues:** <https://github.com/Tornevall/tornevall-wp-dnsbl/issues>
- **Documentation:** <https://tools.tornevall.net/docs/dnsbl-api>

## What the current codebase includes

The current code line keeps the DNSBL API integration on the intended public release line and presents one visible DNSBL / Tools API token flow in the WordPress admin UI. The live permission checker asks Tools directly, reports environment mismatches clearly, and shows automatic DNSBL access when the configured token belongs to an active Tools admin.

The current release line includes:

- WordPress-native DNSBL/FraudBL checking
- admin AJAX lookup and self-check tools
- visitor statistics in the admin dashboard
- cache TTL and cleanup scheduling for listed and non-listed lookups
- safe IP whitelisting and protected-admin handling
- frontend dry-run support for administrators
- Cloudflare Turnstile for WordPress comments
- DNSBL/FraudBL protection for WordPress account registrations, including WordPress multisite/network signup flows on `wp-signup.php`
- Cloudflare Turnstile for WordPress account registrations, including WordPress multisite/network signup flows on `wp-signup.php`
- one visible DNSBL / Tools API token field in the WordPress settings UI, plus live **Check token permissions** diagnostics and permission-aware token status for delete / delist work
- dashboard/settings warnings when live DNSBL delete / delist access is missing, together with gating for the configured main delisting page
- built-in main removal-page template plus shortcode-based custom removal pages that only expose the operations allowed by the current token
- checker-style public delist flow with local-first DNS answers, a Tools-backed follow-up lookup, separate **Check if listed** / **Delist** actions, reusable post-check searches, a dedicated **Reset** action, safer disabled-state submit handling, and a dedicated busy spinner/status row while live requests are running
- optional advanced CIDR delist mode for permitted tokens, with plugin-local resolver scans, live progress feedback, a visible hit list of listed IPs, listed-hit-only delete targeting, guarded ranges, sequential per-IP delete calls, explicit approval guidance when CIDR removal is not allowed, and a delegated CIDR floor from Tools so non-admin tokens can be limited to ranges like `/25`..`/32`
- optional Turnstile protection for live removal submits, controlled by a dedicated removal-page checkbox instead of being inherited automatically from comment/registration Turnstile settings
- an extra removal-page Turnstile fail-open checkbox so the public delist flow can temporarily bypass the Turnstile challenge automatically when the widget or Cloudflare verification path has operational problems
- AJAX proxy flow for DNSBL writes through WordPress backend, plus dry-run controls for both local simulation and API acknowledgement (`dry_run`)
- additive site identity stamping on Tools DNSBL write/check requests (`source_type`, `source_name`, `source_site_url`, `source_site_host`) so backend delist audits can show which WordPress site submitted the request
- a dismissible admin reminder that invites site owners to leave WordPress.org feedback when the plugin is helping them
- the default protection profile includes `IP_FRAUDCOMMERCE`, and public removal references continue to point at <https://www.tornevall.net/removal/>

### Commerce hooks in 3.2.0

Version 3.2.0 adds an optional listener-based commerce fraud layer. The implementation is entirely inside this DNSBL plugin; supported payment plugins are observed through existing WordPress hooks and are not patched or replaced.

Initial integrations include:

- **Klarna Payments** - listens to the plugin's accepted, pending and rejected fraud-status actions
- **Kustom Checkout** - observes the Kustom order callback filter before `fraud_status` is interpreted
- **Resurs legacy SOAP/RCO** - listens to the existing `resurs_hook_orderinfo` and `resurs_hook_callback` payloads when the old plugin is installed; no calls are made to the legacy API/plugin
- **Resurs Merchant API** - passively observes the current plugin's rejected task-status filter but deliberately does not classify a normal rejection as fraud unless a trusted custom classifier or explicit signal says so
- **Generic integrations** - can emit `tornevall_dnsbl_commerce_fraud` with a normalized event array
- **Resurs MAPI custom integrations** - can emit `tornevall_dnsbl_resurs_mapi_fraud` without modifying the Resurs plugin

Confirmed/rejected fraud can queue a Tools `commerce` add using `IP_FRAUDCOMMERCE`. Pending/review states are only observed. A later accepted/cleared event may remove the commerce listing only when the matching source/order reference is locally tracked as having created or owned that listing. Multiple active references sharing one IP are tracked so one successful order does not automatically remove a listing still owned by another active fraud reference.

The admin-only **Commerce hooks** view shows detected integrations, recent normalized events and a sandbox for explicit ADD/UPDATE/REMOVE tests. Sandbox writes are allowed only when the configured Tools environment is `dev`.

## Description

Tornevall Networks DNSBL and FraudBL protection for WordPress.

The plugin is intended to provide a lightweight anti-spam and anti-abuse layer for WordPress, with local caching to reduce repeated lookups and unnecessary load against blacklist services.

Current admin features include:

- manual DNS lookup tools
- self-check tools
- visitor statistics for blacklist activity
- safe IP whitelisting
- protected-admin notices and quick whitelist actions
- Turnstile settings for comments and registrations, including multisite/network `wp-signup.php` registrations, plus a separate opt-in toggle and optional automatic fail-open bypass for public delisting/removal submits
- live DNSBL token permission checks before the main delisting page is activated
- dashboard/settings warnings when the current token cannot offer live removals yet
- built-in removal-page template plus shortcode-based custom page support
- commerce hook integration status, recent event history and a dev-only mutation sandbox

## Installation

1. Upload the plugin archive to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open the plugin settings page and configure DNSBL/FraudBL behaviour.
4. If you want Turnstile protection, add your Cloudflare Turnstile keys in the plugin settings and then opt in separately for comments, registrations, and/or public delisting/removal submits.
5. If you want commerce fraud listeners, open **Commerce hooks** below the DNSBL menu and enable them after the Tools write token has been configured.

The plugin creates and uses cache/statistics tables to avoid excessive DNS traffic and to surface admin metrics.

## Commerce custom hook example

A provider/site integration that already knows a payment has been confirmed as fraud can emit the generic hook without changing the payment plugin:

```php
do_action('tornevall_dnsbl_commerce_fraud', [
    'source' => 'my_gateway',
    'state' => 'fraud',
    'order_id' => 123,
    'payment_id' => 'payment-reference',
]);
```

The customer IP is resolved from the WooCommerce order when possible. An explicit `ip` can also be supplied by trusted integrations.

For the current Resurs Merchant API generation there is also a dedicated custom signal surface:

```php
do_action(
    'tornevall_dnsbl_resurs_mapi_fraud',
    123,
    'fraud',
    'payment-reference',
    []
);
```

A regular MAPI rejection is not treated as fraud automatically.

## FAQ

### Can I get delisted?

Yes. If you are blacklisted in Tornevall DNSBL, use:

<https://www.tornevall.net/removal/>

You can also add the built-in shortcode form to a custom WordPress page:

```text
[dnsbl_removal_form]
```

Alias shortcode:

```text
[tornevall_dnsbl_removal_form]
```

### Does the DNSBL plugin modify Klarna, Kustom or Resurs plugins?

No. Version 3.2.0 observes their existing WordPress integration surfaces. All new implementation code lives in this DNSBL plugin.

### How do I test commerce events safely?

Use the **Commerce hooks** sandbox while Tools mode is set to `dev`. The sandbox enters the same normalized event path as the real listeners but refuses live sandbox writes in production mode.

### How do I test the general plugin without locking myself out?

Use the safe IP whitelist and the frontend dry-run support for administrators. Whitelisted IPs are still checked and counted in statistics, but they are not blocked.

## Changelog

See [`CHANGELOG.md`](./CHANGELOG.md) for the complete version series from `1.0.0` onward.

### 3.2.0 highlights

- Added normalized WooCommerce commerce fraud events and Tools-backed `commerce` ADD/UPDATE/REMOVE handling
- Added Klarna Payments and Kustom Checkout listeners
- Added legacy Resurs fraud-hook listeners without communicating with the legacy plugin/API
- Added passive current Resurs MAPI observation plus explicit custom classification/signal hooks
- Added ownership-aware clearing so unrelated successful orders do not automatically delist an IP
- Added an admin-only Commerce hooks surface and a Tools-dev-only sandbox

### 3.1.5 highlights

- Fixed WordPress multisite/network registrations so the second `wp-signup.php` step can reuse the successful Turnstile check from step 1 instead of failing and sending the visitor back to the first step

### 3.1.4 highlights

- Tested with WP7
- Registration Turnstile and DNSBL/FraudBL checks now also protect WordPress multisite/network signups on `wp-signup.php`

### 3.1.2 highlights

- Fixed the public removal-form Turnstile lifecycle and stale/empty Turnstile response handling
- Released the Tools-backed site identity metadata

### 3.1.0 highlights

- Added the Tools-backed DNSBL write-token flow for add/delete/update/bulk operations
- Added the shortcode-based delisting/removal form with AJAX proxy and dry-run support
- Added the checker-style public delist flow and local CIDR scanning/removal workflow

### 3.0.0 highlights

- Added Cloudflare Turnstile protection for comments
- Added DNSBL/FraudBL and Turnstile protection for WordPress registrations
- Added visitor statistics and safer whitelist-based admin testing
