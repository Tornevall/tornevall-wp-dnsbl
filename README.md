# Tornevall Networks DNSBL Implementation

WordPress plugin for DNSBL/FraudBL-based protection of comments, registrations and other abuse-prone submission flows.

## Release metadata

- **Release:** `3.0.3`
- **Requires at least:** `5.8`
- **Requires PHP:** `8.1`
- **Tested up to:** `6.9`
- **Plugin URL:** <https://wordpress.org/plugins/tornevall-networks-dnsbl-implementation/>
- **Project URL:** <https://github.com/Tornevall/tornevall-wp-dnsbl>
- **Issues:** <https://github.com/Tornevall/tornevall-wp-dnsbl/issues>
- **Documentation:** <https://tools.tornevall.net/docs/dnsbl-api>

## What 3.0.3 ships

This `3.0.3` package fixes the frontend dry-run availability logic so the public dry-run popup and toggle are hidden when Tools environment mode is set to production.

The current release line includes:

- WordPress-native DNSBL/FraudBL checking
- admin AJAX lookup and self-check tools
- visitor statistics in the admin dashboard
- cache TTL and cleanup scheduling for listed and non-listed lookups
- safe IP whitelisting and protected-admin handling
- frontend dry-run support for administrators
- Cloudflare Turnstile for WordPress comments
- DNSBL/FraudBL protection for WordPress account registrations
- Cloudflare Turnstile for WordPress account registrations
- `IP_FRAUDCOMMERCE` included in the default trigger-flag profile
- updated delisting flow via <https://www.tornevall.net/removal/>

FraudBL and fraud-related discovery are intentionally kept visible in the project description even though the plugin title now aligns more closely with the slug and package identity.

WooCommerce-oriented protection is a planned next step, but it is not part of the packaged `3.0.3` release yet.

## Description

Tornevall Networks DNSBL and FraudBL protection for WordPress.

The plugin is intended to provide a lightweight anti-spam and anti-abuse layer for WordPress, with local caching to reduce repeated lookups and unnecessary load against blacklist services.

Current admin features include:

- manual DNS lookup tools
- self-check tools
- visitor statistics for blacklist activity
- safe IP whitelisting
- protected-admin notices and quick whitelist actions
- Turnstile settings for comments and registrations

## Installation

1. Upload the plugin archive to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open the plugin settings page and configure DNSBL/FraudBL behaviour.
4. If you want Turnstile protection, add your Cloudflare Turnstile keys in the plugin settings.

The plugin creates and uses cache/statistics tables to avoid excessive DNS traffic and to surface admin metrics.

## FAQ

### Can I get delisted?

Yes. If you are blacklisted in Tornevall DNSBL, use:

<https://www.tornevall.net/removal/>

### How do I test the plugin without locking myself out?

Use the safe IP whitelist and the frontend dry-run support for administrators. Whitelisted IPs are still checked and counted in statistics, but they are not blocked.

## Changelog

See [`CHANGELOG.md`](./CHANGELOG.md) for the complete version series from `1.0.0` onward.

### 3.0.3 highlights

- Fixed frontend dry-run availability so the public banner and toggle only appear when DNSBL dev mode is enabled and Tools mode is set to `dev`

### 3.0.2 highlights

- Repackaged the release so updated screenshots and other WordPress.org assets can be picked up properly
- Restored Markdown-style links in the WordPress readme after the previous plain-URL formatting pass

### 3.0.1 highlights

- Simplified and aligned the public plugin name to better match the slug
- Corrected the author metadata spelling to Thomas Tornevall
- Reduced the WordPress.org tags to the five most relevant discovery terms
- Refreshed the readme wording around FraudBL/fraud discoverability and planned WooCommerce follow-up work

### 3.0.0 highlights

- Added Cloudflare Turnstile protection for comments
- Added DNSBL/FraudBL and Turnstile protection for WordPress registrations
- Added visitor statistics and safer whitelist-based admin testing
- Added `IP_FRAUDCOMMERCE` to the default protection profile
- Tightened comment blocking and updated the public removal flow

