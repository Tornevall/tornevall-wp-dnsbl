# Tornevall Networks DNSBL Implementation

WordPress plugin for DNSBL/FraudBL-based protection of comments, registrations and other abuse-prone submission flows.

## Release metadata

- **Release:** `3.1.0`
- **Requires at least:** `5.8`
- **Requires PHP:** `8.1`
- **Tested up to:** `6.9`
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
- DNSBL/FraudBL protection for WordPress account registrations
- Cloudflare Turnstile for WordPress account registrations
- one visible DNSBL / Tools API token field in the WordPress settings UI, with hidden legacy fallback handling for older saved installs
- clearer **Check token permissions** diagnostics when a token exists on the other Tools environment (`tools.tornevall.com` vs `tools.tornevall.net`)
- the permission checker now uses the current token field value and currently selected Tools environment immediately, even before the settings form is saved
- the permission checker now sends any non-empty token string to Tools for live inspection and can report when the value matches another Tools token type/provider instead of a DNSBL write token
- when that other matched token belongs to an active Tools admin token owner, the checker now reports automatic effective DNSBL access through the same transport
- WordPress dashboard + plugin settings warning when live DNSBL delete / delist access is missing, with a direct Tools request link for admins
- permission-aware token status panel that shows current add/delete/update capability instead of only confirming that a token string is saved
- main delisting-page activation now performs a live delete-permission check before the selected WordPress page is accepted as the plugin's primary removal page
- built-in plugin template for the primary removal page (`templates/removal-page.php`) when the selected page does not already contain a removal shortcode
- shortcode form for page-level delisting/removal tooling (`[dnsbl_removal_form]`)
- shortcode-driven custom removal pages that automatically hide operations the configured token is not allowed to perform
- delisting-page settings remain read-only until delete / delist permission has been confirmed for the configured token
- internal delist slug routing now uses a dedicated rewrite/query-var path and refreshes rewrite rules on activation and slug changes to avoid `/delist` 404s
- managed public delist page now uses a checker-style flow with IP-only input and automatic blacklist-state validation before delete requests
- when a DNSBL / Tools API token is configured, that managed checker now keeps the first DNS answer local/instant and then runs a second Tools-backed background check before the delete button is unlocked
- checker mode now uses explicit two-step actions: **Check if listed** first, then a separate **Delist** button when token-backed follow-up confirms request readiness
- checker requests now use operation intent `check` (non-write) and only switch to `delete` when a confirmed Delist submission is sent
- checker mode now pre-fills the IP field with the current visitor IP (`REMOTE_ADDR`) when available, while still allowing manual override
- the background check now asks Tools for live delist candidates so the eventual delete request can follow the correct publication family (`dnsbl`, `fraudbl`, `commerce`) instead of guessing only from the first resolver hit
- removal-form requests now enforce Cloudflare Turnstile when Turnstile keys are configured
- checker mode includes an advanced optional CIDR delist input once delist is ready, constrained to safe IPv4 ranges (`/24`..`/32`) that must include the checked listed IP
- Advanced CIDR mode is hidden by default and only shown after clicking **Advanced**, and only when the token has CIDR-delete permission (or is admin token)
- if CIDR permission is missing, Advanced mode is hidden and only single-IP delist is available (with a direct request/approval link to Tools)
- Turnstile is enforced for actual write submissions (including Delist), while checker/background follow-up calls are kept non-blocking so pre-check flow does not fail on consumed captcha state
- in checker mode, Turnstile stays inactive/hidden until Delist becomes actionable; listing checks remain free/local-first
- once an IP is locally confirmed as listed, Delist is enabled immediately even while Tools follow-up still runs in the background
- when **Delist** is clicked, the Delist button itself now shows the in-flight submit state while both checker buttons are disabled to avoid double-click duplicates
- API client timeout values for checker follow-up are increased to better tolerate slower host environments
- the checker/removal script is now versioned from the actual JS file modification time so frontend fixes are not hidden behind a stale cached `dnsbl-removal-form.js`
- AJAX proxy flow that sends DNSBL writes through WordPress backend to Tools API
- dry-run controls for both local simulation and API acknowledgement (`dry_run`)
- `IP_FRAUDCOMMERCE` included in the default trigger-flag profile
- updated delisting flow via <https://www.tornevall.net/removal/>

FraudBL and fraud-related discovery are intentionally kept visible in the project description even though the plugin title now aligns more closely with the slug and package identity.

WooCommerce-oriented protection is a planned next step, but it is not part of the packaged `3.1.0` release yet.

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
- live DNSBL token permission checks before the main delisting page is activated
- dashboard/settings warnings when the current token cannot offer live removals yet
- built-in removal-page template plus shortcode-based custom page support

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

You can also add the built-in shortcode form to a custom WordPress page:

```text
[dnsbl_removal_form]
```

Alias shortcode:

```text
[tornevall_dnsbl_removal_form]
```

If you select a **Delisting page** in the plugin settings and that page does **not** already contain one of those shortcodes, the plugin now renders its built-in main template from `templates/removal-page.php` automatically.

Important behaviour:

- saving a main delisting page now performs a live permission check against `GET /api/dnsbl/token/info`
- the selected page is saved even without delete permission, but WordPress warns that live removal stays unavailable until Tornevall Networks/FraudBL access is granted
- custom shortcode pages continue to work even when the built-in main page is not used
- shortcode forms now only expose the DNSBL operations that the configured token is actually allowed to perform
- the managed internal/public delist page keeps the UX minimal (IP only), gives an immediate local DNS statement first, and when a token exists it then runs a background Tools follow-up before sending delist; success messages note that propagation can take a little while
- when delist is ready the IP input is locked and a dedicated Delist action is shown; the advanced CIDR section opens only in that ready state

### How do I test the plugin without locking myself out?

Use the safe IP whitelist and the frontend dry-run support for administrators. Whitelisted IPs are still checked and counted in statistics, but they are not blocked.

## Changelog

See [`CHANGELOG.md`](./CHANGELOG.md) for the complete version series from `1.0.0` onward.

### Unreleased post-3.1.0 maintenance highlights

- Fixed the checker-mode delist submit flow so the confirmed IP is always re-posted even when the input is locked/disabled
- Fixed the checker follow-up path so external Tools API 419 errors no longer masquerade as a local WordPress session-expired state
- Delist now shows its in-flight state on the Delist button itself while both checker buttons are disabled, and the removal-form script now cache-busts from the real JS file timestamp

### 3.1.0 highlights

- Added the Tools-backed DNSBL write-token flow for add/delete/update/bulk operations
- Added the shortcode-based delisting/removal form with AJAX proxy and dry-run support
- The live token checker now reports automatic DNSBL access for active admin-owned Tools tokens and no longer frames that case as a separate token model in the plugin UI

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
