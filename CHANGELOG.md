# Changelog

All notable changes to the DNSBL plugin should be documented in this file.

## Unreleased (post-3.1.0 maintenance)

### Fixed
- **Delist request failing with "Invalid IP address format."** – The checker form locks the IP input (`disabled`) once a listing is confirmed, which causes browsers to omit that field from FormData on the delist submit. The JS form handler now explicitly re-adds the confirmed IP to the POST payload before sending, so the plugin proxy always receives a valid `ip` value regardless of the input's disabled state.
- **Background check showing "419 / session expired" after "IP is listed" confirmation** – When the Tools API returned HTTP 419 (CSRF) on the server-to-server `POST /api/dnsbl/check-ip` call, the PHP handler relayed the 419 status verbatim to the browser. The WordPress AJAX client then showed the WordPress-CSRF-expired message ("Please refresh this page") even though the WordPress nonce was still valid. The handler now maps any 419 from the external Tools API to HTTP 502 (Bad Gateway) before sending the response, so the client shows the real error text instead. The JS background-check path also no longer overwrites the payload message with the generic CSRF text when the HTTP status happens to be 419.

### Changed
- **Preferred resolver hosts now include all four known DNSBL/FraudBL zones** – `defaultResolvers()` previously only listed `dnsbl.tornevall.org` and `bl.fraudbl.org`. It now also includes `opm.tornevall.org` and `ecom.fraudbl.org` so checker-mode local DNS lookups can match IPs that are exclusively listed in the OPM or ecom/FraudBL families.
- **Migration automatically adds missing resolver hosts to existing installs** – `Migrations::maybeAddMissingResolverHosts()` runs on every load and merges any missing default zones into the stored `tornevall_dnsbl_resolver_hosts` option without removing custom hosts.
- **Checker-mode Delist now shows submit state on the correct button** – clicking **Delist** now immediately disables both checker buttons and puts the in-flight “Submitting request…” label on the Delist button itself instead of on **Check if listed**.
- **Removal-form frontend asset now cache-busts by file modification time** – the script enqueue no longer relies only on the static schema/plugin version, which helps browsers pick up checker-flow JS fixes immediately after deployment.

## Unreleased - 2026-04-10

### Added
- Added a second-step Tools-backed IP inspection call for the public checker/removal flow. When a DNSBL / Tools API token is configured, the form now keeps the first local DNS answer immediate and then runs a background follow-up against `POST /api/dnsbl/check-ip` to confirm live delist candidates before the delete request is enabled.

### Changed
- Checker-mode delisting now prefers token-backed delete candidates from Tools when they are available, which means the plugin can target the correct publication family (`dnsbl`, `fraudbl`, `commerce`) instead of relying only on the first local resolver impression.
- When Tools reports more than one delist candidate for the same IP, the checker now reuses the existing bulk write path so one confirmed delist action can fan out to multiple publication families.
- The public checker button now stays in a background-check state while the Tools follow-up is running, instead of immediately offering the delete action before token-side details are known.

## Unreleased - 2026-04-14

### Fixed
- **HTTP 419 on background Tools API check-ip call** – all `POST /api/dnsbl/*` endpoints on the Tools backend are now excluded from CSRF verification. Previously, server-side requests from the WordPress plugin (and any other token-backed client without a browser CSRF cookie) would always receive HTTP 419 from `POST /api/dnsbl/check-ip`, causing the background follow-up message to report a session/security failure instead of confirming delist details.

### Added
- Managed checker mode now exposes an advanced CIDR section only when delist is confirmed as possible; CIDR requests are validated to IPv4 `/24`..`/32`, must include the checked listed IP, and are chunked into safe bulk API batches.
- Removal-form AJAX now verifies Cloudflare Turnstile tokens whenever Turnstile keys are configured for the plugin.

### Changed
- Checker flow now keeps **Check if listed** as a dedicated first step and shows a separate **Delist** button after listing + token-backed follow-up are ready.
- When delist is ready, the checked IP field is locked and the advanced section is opened to avoid accidental mismatch edits.
- Background Tools follow-up and direct API errors now map HTTP `419` to an explicit session/security-expired message instead of a bare `HTTP 419:` output.
- When Advanced CIDR mode is opened after a confirmed checker match, the CIDR input now auto-fills with `<checked-ip>/32` (single-IP range) if the field is still empty.
- DNSBL token input sanitizing now also normalizes common copy/paste formats such as `Bearer <token>` and quoted token strings before saving/using them.

### Fixed
- DNSBL write/check auth diagnostics now distinguish true unknown/revoked DNSBL tokens from "matched non-DNSBL token/provider" and inactive admin API-key cases, instead of always collapsing them into a generic invalid/revoked token error.

### Fixed
- Removal Turnstile verification is now skipped for checker-only/background pre-check requests and enforced on write submissions, which fixes false `Verification failed. Please try again.` messages during the checker follow-up phase.

### Changed
- Checker mode now keeps Turnstile hidden/inactive until delist is actually actionable, so pure listing checks remain frictionless.
- Checker pre-check/background requests now use operation intent `check` and only switch to `delete` on confirmed delist submission.
- Checker mode now pre-fills the IP field with the current visitor IP when available, while still allowing manual override before check.
- Increased checker-follow-up HTTP timeout tolerance in the API client to reduce false timeout errors on slower hosts.
- Delist is now enabled as soon as local DNS resolve confirms listed status, while Tools follow-up continues in the background and can still append richer context.
- Checker IP input now clears the prefilled visitor IP immediately on first focus/click so entering another IP starts without manual deletion.
- Advanced CIDR mode is now hidden behind an explicit **Advanced** toggle and is only available when token capability allows CIDR delete (or admin token); otherwise only single-IP delist is shown.
- CIDR delete attempts are now blocked server-side in the plugin proxy unless CIDR permission is present, with a Tools request URL returned for approval flow.

## Unreleased - 2026-04-09

### Added
- Added a built-in plugin-owned main removal-page template at `templates/removal-page.php` for delisting pages that do not already contain a removal shortcode.
- Added live token-permission gating for the primary delisting-page activation flow, so WordPress now confirms **delete / delist** access through `GET /api/dnsbl/token/info` before accepting the selected page as the plugin-managed main removal page.
- Added an admin/dashboard warning when the configured DNSBL / Tools API token cannot currently perform live delete / delist operations, with a direct link to request or review access via the active Tools host.

### Changed
- Shortcode-based custom removal pages still work, but the form now only exposes the DNSBL operations that the currently configured token is allowed to perform.
- The plugin-managed main removal page now stays focused on delete / delist requests while custom pages can continue to host the backend-connected form through shortcode placement.
- The plugin-managed public delist page now runs in checker-style mode (IP only), verifies listing state before sending delete requests, and appends a propagation-delay hint after successful delist acknowledgement.
- The custom shortcode helper block (`Custom removal pages` + shortcode examples) is now hidden on the managed public delist page.
- Hard-gate behavior for delisting pages now keeps page rendering enabled even when delete permission is missing; the UI shows a warning that live removal cannot be offered until Tornevall Networks/FraudBL permissions are granted.
- The plugin settings page now shows the same write/remove-access warning inline near the token field, and the runtime translation loader now also supports the legacy shipped Swedish `tornevall_dnsbl-sv_SE.mo` filename.
- The token status area now reports actual add/delete/update capabilities instead of only saying that a token exists, and the delisting-page settings stay read-only until delete / delist permission is confirmed.
- The generic Tools/resolver intro copy on the settings page no longer shows all the time; it is now replaced by a dismissible notice that only appears when the token/environment/configuration actually needs attention.
- Internal delist routing now registers a dedicated query var + rewrite rule and refreshes rewrite rules on activation/slug changes, which fixes 404s on the plugin-managed internal delist slug URL.

## 3.1.0 - 2026-04-06

### Added
- **Write token** (`tornevall_dnsbl_write_token`): New setting for the Tools DNSBL write API token. Covers both add (listing) and delete (delisting) operations. Replaces the placeholder removal token field.
- **`DnsblApiClient` class** (`includes/class-dnsbl-api-client.php`): HTTP client for the Tools DNSBL publication API. Supports `addIp()`, `removeIp()`, `updateIp()`, and `bulkOperation()`. Uses the `X-Dnsbl-Token` header.
- **`DnsblWriteQueue` class** (`includes/class-dnsbl-write-queue.php`): Singleton bulk-write queue. Callers use `queueAdd()`, `queueDelete()`, `queueUpdate()`. All queued operations are flushed as a single bulk request at WordPress `shutdown`, avoiding per-request API calls.
- **Auto-report spam IPs** (`tornevall_dnsbl_auto_report_spam` option): When enabled and a write token is configured, IPs of comments transitioned to spam status are automatically queued for DNSBL add (bitmask 64 = `IP_ABUSE_NO_SMTP`). Server-side upgrade-only enforcement prevents downgrading existing entries.
- Write token request link in admin panel pointing to Tools DNSBL token management UI.
- Shortcode-based delisting/removal tool form: `[dnsbl_removal_form]` (alias: `[tornevall_dnsbl_removal_form]`).
- AJAX backend proxy in WordPress for DNSBL add/delete/update requests, so pages can host the tool without exposing token logic in frontend code.
- Dual dry-run controls in the form:
  - **WordPress dry run** (simulates request locally, no API call)
  - **API dry run** (`dry_run=1`) forwarded to Tools DNSBL endpoints for acknowledgement without applying DNS writes.

### Changed
- The "Removal token" field is now functional and renamed "Write token" (covers add and removal). Legacy `tornevall_dnsbl_removal_token` values are automatically migrated to the new `tornevall_dnsbl_write_token` option on upgrade.
- The public plugin release line now stays on `3.1.0` while this DNSBL API/token work is finalized.
- The settings page now presents one visible **DNSBL / Tools API token** field instead of a split-token UI.
- The **Check token permissions** flow now always asks Tools for a live answer, can explain environment mismatches (`tools.tornevall.com` vs `tools.tornevall.net`), and reports automatic DNSBL access when the configured token belongs to an active Tools admin.
- Delisting page integration now auto-injects the removal shortcode only when the configured page does not already include either supported shortcode.
- Existing shortcode marker is no longer replaced by a placeholder block.

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
