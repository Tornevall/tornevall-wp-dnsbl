# Changelog

All notable changes to the DNSBL plugin should be documented in this file.

## 3.0.0 - 2026-03-14

### Added
- Added a refactored WordPress-native DNSBL/FraudBL core with admin AJAX lookup and self-check tools.
- Added visitor statistics in the admin dashboard for resolved checks, blacklist hits, blocked requests, unique visitor addresses and cached blacklist activity.
- Added configurable cache cleanup scheduling, recorded cleanup timestamps and caching of both listed and non-listed DNS lookups.
- Added a safe IP whitelist, activating-user IP seeding, protected-admin notices and a one-click current-visitor whitelist action.
- Added changelog, source-history and public documentation links in the admin help flow.
- Added Cloudflare Turnstile protection for frontend WordPress comments.
- Added DNSBL/FraudBL checks for new WordPress account registrations.
- Added Cloudflare Turnstile protection for new WordPress account registrations.
- Added `IP_FRAUDCOMMERCE` to the default trigger-flag profile.

### Changed
- DNSBL request checks are now written to the existing `dnsblstats` table so the statistics view reflects live traffic.
- Comment-submission checks now also record DNSBL and Tools-driven spam decisions for reporting purposes.
- Simplified comment protection to use DNSBL checks and optional Tools-based assessment, while blocking direct submissions when the form is hidden.
- Restricted dry-run simulation to the public site for logged-in administrators.
- Switched Tools integration to production mode by default.
- Updated removal and delisting references to <https://www.tornevall.net/removal/>.
- Preserved the historical main plugin file name `tornevall-wp-dnsbl.php` for backward compatibility while standardizing internal file names and namespaced classes.

### Fixed
- Restored the missing `CHANGELOG.md` in the plugin root.
- Restored the missing public DNSBL plugin documentation page in Tools.
- Reconnected the previously unused statistics table to real admin-visible metrics.
- Prevented whitelisted visitor IPs from being blocked while still allowing them to be checked and counted in statistics.
- Improved migration handling to clean up retired options and old table names during upgrades.
- Removed retired legacy/APIv3/resource integration code, legacy assets and old compatibility layers.

## 2.1.9

### Notes
- `2.1.9` is the latest historical tag visible in the repository before the current `3.x` codebase cleanup.
- Use the repository commit history for a line-by-line diff trail across the older 2.x series and the current working tree.

## Historical source trail

- Project URI: <https://github.com/Tornevall/tornevall-wp-dnsbl>
- Commit history: <https://github.com/Tornevall/tornevall-wp-dnsbl/commits/master>
- Issue tracker: <https://github.com/Tornevall/tornevall-wp-dnsbl/issues>
