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

### Changed
- Language update (based on the historical Git tag commit message).

### Notes
- `2.1.9` is the latest historical Git tag before the current root `3.0.0` release line.
- The repository does not preserve a fuller local readme changelog block for this release.

## 2.0.8

### Added
- Added Contact Form 7 support (`DNSBLWP-63`).

## 2.0.7

### Fixed
- Fixed false positives shown with FraudBL (`DNSBLWP-60`).
- Fixed a WordPress 5 compatibility error (`DNSBLWP-52`).
- Fixed the spinner behaviour on delist click (`DNSBLWP-59`).
- Allowed admin handling without captcha in the relevant flow (`DNSBLWP-61`).

### Changed
- General code inspection and cleanup pass (`DNSBLWP-56`).

## 2.0.6

### Fixed
- Minor fix for open versus closed comments handling on the delisting page.

## 2.0.5

### Added
- Added the ability to disable comments on the removal page.
- Added an admin-facing notice when the current administrator is also blacklisted.

### Fixed
- Fixed URL text in the readme.

## 2.0.4

### Fixed
- Fixed the text domain in the translation layer.

## 2.0.3

### Changed
- Text and translation refresh.

## 2.0.2

### Fixed
- Fixed duplicate-index reporting issues.
- Improved compatibility for systems where `MODULE_NETWORK` and `MODULE_NETBITS` were not present.

## 2.0.1

### Notes
- The local tag readme preserves only a link to the historical release post, not detailed bullet notes.
- Historical release reference: <https://www.tornevall.net/2018/07/17/dnsbl-for-wordpress-2-0-1-changelog/>

## 2.0.0

### Notes
- The local tag readme preserves only a link to the historical release post, not detailed bullet notes.
- Historical release reference: <https://www.tornevall.net/2018/07/17/dnsbl-for-wordpress-2-0-0-changelog/>

## 1.1.1

### Fixed
- Fixed incorrect index handling on table alteration (`DNSBLWP-17`).
- Fixed database issues causing background notices.

### Changed
- Added a stricter check to ensure cURL is active before use, with a notice when unavailable.
- Reformatted code.
- Moved the location of the menus.
- Initialized parts of FraudBLv2.

## 1.1.0

### Fixed
- Fixed activation failures (`DNSBLWP-16`).

### Changed
- Synced with the current bitmask set (`DNSBLWP-14`).
- Updated the resolver class.

## 1.0.5

### Fixed
- Fixed an unclosed HTML tag that slipped into the translated 1.0.4 release.

## 1.0.4

### Added
- Added Swedish language support (`TSDWP-13`).

## 1.0.3

### Changed
- Switched the issue tracker to JIRA.

## 1.0.3.0

### Notes
- Git-only alias tag for the `1.0.3` WordPress trunk state (`1.0.3 as shown in WP trunk`).

## 1.0.2

### Fixed
- Fixed table-name handling.

## 1.0.1

### Added
- Added minimal statistics (`TSDWP-7`).

### Changed
- Updated timestamps before expiry (`TSDWP-6`).
- Avoided direct internal MySQL calls (`TSDWP-2`).

### Fixed
- Fixed duplicate-key problems (`TSDWP-1`).

## 1.0.0

### Added
- Initial plugin release (`TSDWP-9`, `TSDWP-5`).
- Added the admin control panel.
- Added host detection on bitmask level.

## Historical source trail

- Project URI: <https://github.com/Tornevall/tornevall-wp-dnsbl>
- Commit history: <https://github.com/Tornevall/tornevall-wp-dnsbl/commits/master>
- Issue tracker: <https://github.com/Tornevall/tornevall-wp-dnsbl/issues>
