# Tornevall Networks DNSBL and FraudBL for WordPress

WordPress plugin for DNSBL/FraudBL-based protection of comments, registrations and other abuse-prone submission flows.

## Release metadata

- **Release:** `3.0.0`
- **Requires at least:** `5.8`
- **Requires PHP:** `8.1`
- **Tested up to:** `6.9`
- **Plugin URL:** <https://wordpress.org/plugins/tornevall-networks-dnsbl-implementation/>
- **Project URL:** <https://github.com/Tornevall/tornevall-wp-dnsbl>
- **Issues:** <https://github.com/Tornevall/tornevall-wp-dnsbl/issues>
- **Documentation:** <https://tools.tornevall.net/docs/dnsbl-api>

## What 3.0.0 ships

This first public `3.0.0` release of the current codebase includes:

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

See [`CHANGELOG.md`](./CHANGELOG.md).

### 3.0.0 highlights

- Added Cloudflare Turnstile protection for comments
- Added DNSBL/FraudBL and Turnstile protection for WordPress registrations
- Added visitor statistics and safer whitelist-based admin testing
- Added `IP_FRAUDCOMMERCE` to the default protection profile
- Tightened comment blocking and updated the public removal flow

