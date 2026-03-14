# Changelog

All notable changes to the DNSBL plugin should be documented in this file.

## Unreleased

### Added
- Added a visitor statistics summary to the admin dashboard.
- Added counters for resolved checks, blacklist hits, blocked requests, unique visitor addresses, and cached blacklist entries.
- Added changelog and source-history links to the admin help card.
- Added public documentation for the plugin under `tools.tornevall.net/docs/dnsbl-plugin`.

### Changed
- DNSBL request checks are now written to the existing `dnsblstats` table so the statistics view reflects live traffic.
- Comment-submission checks now also record DNSBL and Tools-driven spam decisions for reporting purposes.

### Fixed
- Restored the missing `CHANGELOG.md` in the plugin root.
- Restored the missing public DNSBL plugin documentation page in Tools.
- Reconnected the previously unused statistics table to real admin-visible metrics.

## 3.0.0

### Added
- Refactored the plugin around WordPress-native DNS lookups and admin AJAX tooling.
- Added asynchronous admin lookup and self-check tools that run without reloading the page.
- Simplified comment protection to use DNSBL checks and optional Tools-based assessment.
- Preserved the historical main plugin file name `tornevall-wp-dnsbl.php` for backward compatibility.

### Changed
- Replaced the old bitmask parser layer with a lightweight internal utility implementation.
- Consolidated helper and Tools communication logic into a shared `includes/dnsbl-utils.php` module with docblocks.
- Standardized internal file names for admin, bootstrap, migrations, utils and admin JavaScript.
- Updated plugin structure, metadata and readme content for the current maintenance release.

### Fixed
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
