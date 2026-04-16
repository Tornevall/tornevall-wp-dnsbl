# Changelog

All notable changes to the DNSBL plugin should be documented in this file.

## 3.1.0

### Added
- Added the Tools-backed DNSBL write flow around one visible **Write token** field (`tornevall_dnsbl_write_token`) plus `DnsblApiClient`, `DnsblWriteQueue`, bulk queueing, dry-run support, and auto-reporting of spam IPs.
- Added the shortcode-based removal tool (`[dnsbl_removal_form]` / `[tornevall_dnsbl_removal_form]`) together with the WordPress AJAX proxy for DNSBL add/delete/update requests.
- Added the checker/removal follow-up against `POST /api/dnsbl/check-ip`, so the public delist flow can confirm live delist candidates after the first local DNS answer.
- Added the built-in main removal-page template, live delisting-page permission gating, and admin/dashboard warnings when the configured token still lacks delete / delist access.
- Added advanced optional CIDR delist support for permitted tokens together with Cloudflare Turnstile protection for live removal-form submissions.

### Changed
- Renamed the old removal-token concept to one functional **Write token** flow, migrated legacy saved values, and consolidated the settings UI around one visible DNSBL / Tools token field.
- The live **Check token permissions** flow now always asks Tools directly, explains environment mismatches, and reports effective DNSBL access for both dedicated write tokens and eligible admin-owned Tools tokens.
- Resolver defaults now cover all four canonical DNSBL/FraudBL zones, and migrations merge missing defaults into existing installs without removing custom hosts.
- The public delist flow now uses a checker-style UX with separate **Check if listed** / **Delist** steps, local-first listing confirmation, background Tools follow-up, and permission-aware shortcode/main-page behavior.
- The plugin-managed main removal page stays delete-focused, while custom shortcode pages remain supported but only expose the operations allowed by the current token.
- Delisting-page controls, token status panels, admin warnings, rewrite handling, and settings copy were all tightened so delete / delist availability is clearer and 404-prone internal routing is avoided.
- Advanced CIDR mode now sits behind an explicit **Advanced** toggle, stays permission-aware, auto-fills safer defaults when opened from a confirmed checker result, and runs its `/24`-`/32` lookup locally inside WordPress instead of using Tools for the block scan itself.
- The advanced CIDR UI now walks the requested block in small local batches, shows scan progress live, and keeps a visible hit list of listed IPs found in the block while the scan runs.
- Checker and delist submits now also show a dedicated busy spinner/status row below the buttons, so it is clearer that a live request is still running even before the result box updates.
- CIDR delete now targets only the IPs that the local CIDR scan actually found as listed, and those delete calls are now sent sequentially one IP at a time instead of in chunked bulk batches.
- If a user clicks **Check if listed** while a valid CIDR is still sitting in the first checker IP field, the plugin now opens **Advanced** at that point, moves the CIDR there automatically, and treats that Advanced CIDR scope as the authoritative range for the later local scan and delist submit.
- Checker copy now says explicitly when a listed/not-listed result comes from the plugin's configured DNS resolvers first, before the optional Tools follow-up appends delist-specific detail.

### Fixed
- Delist request no longer fails with `Invalid IP address format.` when the checker flow has locked the IP field before submit; the confirmed IP is now re-posted explicitly.
- Background Tools follow-up no longer misreports remote `POST /api/dnsbl/check-ip` auth/CSRF failures as a local WordPress “419 / session expired” problem.
- `POST /api/dnsbl/*` endpoints on the Tools backend are now excluded from CSRF verification for token-backed server-to-server calls, which fixes checker follow-up `419` failures.
- DNSBL write/check auth diagnostics now distinguish wrong-token-type and inactive-admin-key cases from truly invalid/revoked DNSBL tokens.
- Removal Turnstile verification is now skipped for checker-only/background pre-check requests and enforced on actual write submissions, which fixes false `Verification failed. Please try again.` messages.
- CIDR delist no longer depends on the originally checked single IP being the only anchor for the requested block; the final submit now accepts a completed local CIDR scan ticket for the exact Advanced-range scope instead.
- CIDR delete timeouts now surface as an explicit timeout/warning state instead of the older generic “failed chunk” message when WordPress only lost the HTTP response while Tools may still have completed already-submitted deletes.
- The public checker can now be reused immediately after a completed lookup: the IP field no longer stays terminally locked after a listed result, and a dedicated **Reset** button now clears checker/CIDR/background state so users can start over without refreshing the page.
- Reset/input changes now invalidate older checker/CIDR async callbacks more aggressively, so stale background responses from a previous search are less likely to repaint the form after the user has already started over.

### Technical
- `class-dnsbl-migrations.php`: `tornevall_dnsbl_removal_token` moved to `retiredOptions()`; migration transfers its value to `tornevall_dnsbl_write_token` if the latter is empty.
- New PHP classes follow the `Tornevall\Networks\DNSBL` namespace.
- Legacy hidden `tornevall_dnsbl_tools_token` values are now migrated into the single visible API-token field when needed.

## 3.0.3 - 2026-03-15

### Fixed
- Frontend dry-run availability now requires both DNSBL dev mode and Tools environment mode set to `dev`, so the public popup and admin-bar toggle stay hidden in production mode.

## 3.0.2 - 2026-03-14

### Changed
- Repackaged the release so updated screenshots and other WordPress.org assets can be picked up properly.
- Restored Markdown-style links in the WordPress readme after the previous plain-URL formatting pass.

## 3.0.1 - 2026-03-14

### Changed
- Simplified and aligned the public plugin name so it better matches the WordPress.org slug.
- Corrected the author metadata spelling to Thomas Tornevall.
- Reduced the WordPress.org tags to five broader discovery terms with better general search value.
- Refreshed the readme wording around FraudBL/fraud discoverability.
- Added a note that WooCommerce-oriented protection is planned follow-up work, not part of the packaged release.

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
