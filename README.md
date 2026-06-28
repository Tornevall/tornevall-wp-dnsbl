# Tornevall Networks DNSBL Implementation

WordPress plugin for DNSBL/FraudBL-based protection of comments, registrations and other abuse-prone submission flows.

## Release metadata

- **Release:** `3.1.2`
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
- one visible DNSBL / Tools API token field in the WordPress settings UI, plus live **Check token permissions** diagnostics and permission-aware token status for delete / delist work
- dashboard/settings warnings when live DNSBL delete / delist access is missing, together with gating for the configured main delisting page
- built-in main removal-page template plus shortcode-based custom removal pages that only expose the operations allowed by the current token
- checker-style public delist flow with local-first DNS answers, a Tools-backed follow-up lookup, separate **Check if listed** / **Delist** actions, reusable post-check searches, a dedicated **Reset** action, safer disabled-state submit handling, and a dedicated busy spinner/status row while live requests are running
- optional advanced CIDR delist mode for permitted tokens, with plugin-local resolver scans, live progress feedback, a visible hit list of listed IPs, listed-hit-only delete targeting, guarded ranges, sequential per-IP delete calls, explicit approval guidance when CIDR removal is not allowed, and a delegated CIDR floor from Tools so non-admin tokens can be limited to ranges like `/25`..`/32`
- if the user clicks **Check if listed** with a valid CIDR still sitting in the first checker field, the plugin now opens **Advanced**, moves the CIDR there, and lets that Advanced CIDR scope drive the later local scan and delist submit
- optional Turnstile protection for live removal submits, controlled by a dedicated removal-page checkbox and using an explicit Turnstile widget lifecycle that waits for Cloudflare's API, keeps the returned widget id, and clears stale tokens
- AJAX proxy flow for DNSBL writes through WordPress backend, plus dry-run controls for both local simulation and API acknowledgement (`dry_run`)
- additive site identity stamping on Tools DNSBL write/check requests (`source_type`, `source_name`, `source_site_url`, `source_site_host`) so backend delist audits can show which WordPress site submitted the request
- a dismissible admin reminder that invites site owners to leave WordPress.org feedback when the plugin is helping them
- the default protection profile still includes `IP_FRAUDCOMMERCE`, and public removal references continue to point at <https://www.tornevall.net/removal/>

FraudBL and fraud-related discovery are intentionally kept visible in the project description even though the plugin title now aligns more closely with the slug and package identity.

WooCommerce-oriented protection is a planned next step, but it is not part of the packaged `3.1.2` release yet.

## Description

Tornevall Networks DNSBL and FraudBL protection for WordPress.

The plugin is intended to provide a lightweight anti-spam and anti-abuse layer for WordPress, with local caching to reduce repeated lookups and unnecessary load against blacklist services.

Current admin features include:

- manual DNS lookup tools
- self-check tools
- visitor statistics for blacklist activity
- safe IP whitelisting
- protected-admin notices and quick whitelist actions
- Turnstile settings for comments and registrations, plus a separate opt-in toggle for public delisting/removal submits
- live DNSBL token permission checks before the main delisting page is activated
- dashboard/settings warnings when the current token cannot offer live removals yet
- built-in removal-page template plus shortcode-based custom page support

## Installation

1. Upload the plugin archive to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open the plugin settings page and configure DNSBL/FraudBL behaviour.
4. If you want Turnstile protection, add your Cloudflare Turnstile keys in the plugin settings and then opt in separately for comments, registrations, and/or public delisting/removal submits.

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
- when single-IP delist is ready a dedicated Delist action is shown, but the checker can now still be reused immediately for another lookup; **Reset** clears the current checker/CIDR state without needing a page reload, and Advanced CIDR can also be opened earlier through the explicit toggle or automatic CIDR handoff and then becomes the authoritative scope for that flow
- checker and delist requests now also show a dedicated spinner/status row below the action buttons so it is clearer that the live request is still working
- advanced CIDR checks are now performed locally by the WordPress plugin itself, using the configured resolver hosts in small batches with a progress bar and hit list instead of sending the block lookup to the Tools API
- Advanced CIDR now follows the delegated CIDR floor exposed by Tools (`delete_min_cidr_prefix`), so non-admin tokens can be restricted to `/25`..`/32`, `/26`..`/32`, or single-IP `/32` only instead of always getting `/24` access
- the final CIDR delete still goes through the DNSBL write endpoint, but only after the local scan has found at least one listed address in the allowed CIDR block, and the plugin now only submits delete operations for the IPs that the local CIDR scan actually marked as listed, one IP at a time
- the CIDR scan is intentionally paced in small local batches so the resolver side is not flooded while the progress UI keeps moving forward
- if a valid CIDR is left in the first checker field and the user clicks **Check if listed**, the plugin now moves it into the Advanced CIDR field automatically, keeps that Advanced CIDR value as the authoritative delete scope, and opens the section immediately instead of leaving the form in an invalid single-IP state
- when the plugin talks to the Tools DNSBL endpoints it now also includes its own site identity metadata, so Tools-side removal audits can tell which WordPress site triggered a delist request even in server-to-server flows
- Turnstile on the public delisting/removal flow is explicitly opt-in and reuses the same site key, secret key, and theme configured for comment protection; the widget is rendered only after Cloudflare's API is available, reset/read through the returned widget id, and guarded against stale or empty response tokens before live delete requests are sent

### Can I leave feedback somewhere?

Yes. The plugin admin now shows a small dismissible reminder with a direct link to the WordPress.org review form so you can quickly rate the plugin and say what should improve next.

### How do I test the plugin without locking myself out?

Use the safe IP whitelist and the frontend dry-run support for administrators. Whitelisted IPs are still checked and counted in statistics, but they are not blocked.

## Changelog

See [`CHANGELOG.md`](./CHANGELOG.md) for the complete version series from `1.0.0` onward.

### 3.1.2 highlights

- Fixed the public removal-form Turnstile lifecycle so the widget waits for Cloudflare's API before rendering, keeps the returned widget id, and uses that widget id for reset/response handling
- Fixed stale or empty Turnstile response handling by clearing tokens on expiration, timeout or error and recovering the current widget response before submit when the hidden token field is empty
- Released the Tools-backed site identity metadata and bumped the plugin release metadata to `3.1.2`

### 3.1.1 highlights

- Added a dedicated admin checkbox for Turnstile on the public delisting/removal flow
- The public removal page no longer inherits Turnstile automatically just because comment/registration Turnstile is configured
- Cloudflare problems on `challenges.cloudflare.com` can now be mitigated by disabling only the removal-page challenge while keeping comment and registration protection unchanged

### 3.1.0 highlights

- Added the Tools-backed DNSBL write-token flow for add/delete/update/bulk operations
- Added the shortcode-based delisting/removal form with AJAX proxy and dry-run support
- The live token checker now reports automatic DNSBL access for active admin-owned Tools tokens and no longer frames that case as a separate token model in the plugin UI
- 3.1.0 also covers the current checker-style public delist flow, including the Tools-backed follow-up lookup, main removal-page template/permission gating, the current Delist-button submit/captcha/spinner handling fixes, and the local CIDR progress/hit-list/listed-target workflow with Advanced CIDR as the authoritative delete scope

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
