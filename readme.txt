=== Tornevall Networks DNSBL Implementation ===
Contributors: Tornevall
Tags: antispam, blacklist, fraud, comment spam, user registration
Requires at least: 5.8
Requires PHP: 8.1
Tested up to: 6.9
Stable tag: 3.1.0
License: GPLv2 or later

Tornevall Networks DNSBL implementation with FraudBL support for WordPress

== Description ==

Tornevall Networks DNSBL and FraudBL protection for WordPress. The plugin helps block comment activity, account registrations and other unwanted submissions from addresses flagged by Tornevall Networks DNSBL and FraudBL.

FraudBL is part of the protection layer used by the plugin and is available at [fraudbl.org](https://www.fraudbl.org/). For general discovery, broader search terms like fraud, blacklist, comment spam and user registration are usually easier to find than niche technical acronyms alone.

The plugin is intended to provide a lightweight anti-spam and anti-abuse layer for WordPress, with support for local caching to reduce repeated lookups and unnecessary load against blacklist services.

Current admin features include manual DNS lookup tools, self-check tools, visitor statistics, safe IP whitelisting, frontend dry-run support for administrators, Cloudflare Turnstile for comments, and DNSBL plus Turnstile protection for new WordPress account registrations. Tools integration now uses one visible DNSBL / Tools API token field in the plugin UI. The live **Check token permissions** flow always asks Tools directly, warns clearly when the token exists on the other Tools environment (`tools.tornevall.com` vs `tools.tornevall.net`), and reports the effective DNSBL permissions for the configured token. Active admin-owned Tools tokens are shown as having automatic DNSBL access through the same `X-Dnsbl-Token` transport. The plugin now also warns on the WordPress dashboard and settings page when the current token cannot perform live delete / delist operations yet, with a direct link to the active Tools access page. The token status area shows current add/delete/update capability instead of only saying that a token exists, and delisting-page controls stay read-only until delete / delist permission has been confirmed. Internal delist slug routing now uses a dedicated rewrite/query-var path and refreshes rewrite rules on activation and slug changes to avoid `/delist` 404 cases. The managed public delist page now runs as a checker-style IP-only flow (checks listing first, then submits delist), while custom shortcode pages still support the broader permission-aware operations. The plugin also ships a shortcode delisting/removal form (`[dnsbl_removal_form]`) with AJAX backend proxy and optional API dry-run acknowledgement, plus a built-in primary removal-page template that is only activated when the configured token has live delete permission.

Report issues and feedback: [GitHub issues](https://github.com/Tornevall/tornevall-wp-dnsbl/issues)
Plugin URL: [WordPress.org plugin page](https://wordpress.org/plugins/tornevall-networks-dnsbl-implementation/)
Documentation: [DNSBL API documentation](https://tools.tornevall.net/docs/dnsbl-api)


= Support and feedback =

Bug reports and feedback can currently be submitted via [GitHub issues](https://github.com/Tornevall/tornevall-wp-dnsbl/issues).

Full Documentation: [DNSBL API documentation](https://tools.tornevall.net/docs/dnsbl-api)

Translations can be contributed via [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/tornevall-networks-dnsbl-implementation).


== Installation ==

1. Upload the plugin archive to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin via admin control panel

The installation creates a cache table in the WordPress database. This reduces repeated DNS lookups and helps avoid unnecessary load against blacklist services. Both blacklisted and non-listed lookups are cached. The default cache lifetime is 600 seconds and the cleanup interval is 300 seconds.

The plugin also supports a safe IP whitelist. Whitelisted IP addresses are still checked and can appear in statistics, but they are not blocked, redirected or marked as spam. When possible, the activating visitor IP is seeded into that whitelist automatically during first-time setup.

If the database schema becomes out of sync after an upgrade or a manual source-based install, deactivate and reactivate the plugin to recreate the required tables.

== Frequently Asked Questions ==

* Can I get delisted?

Yes. If you are blacklisted in Tornevall DNSBL, you can use [the removal page](https://www.tornevall.net/removal/) - otherwise, you can't.

You can host the built-in form on any page with:

`[dnsbl_removal_form]`

Alias shortcode:

`[tornevall_dnsbl_removal_form]`

If you select a **Delisting page** in the plugin settings and that page does not already contain one of those shortcodes, the plugin renders its built-in main template from `templates/removal-page.php` automatically.

Important behaviour:

* Saving a main delisting page now performs a live permission check against `GET /api/dnsbl/token/info`
* The selected page is saved even when delete / delist permission is missing, but WordPress warns that live removal remains unavailable until Tornevall Networks/FraudBL access is granted
* Custom shortcode pages continue to work even when the built-in main page is not used
* Shortcode forms only expose the DNSBL operations that the configured token is actually allowed to perform

* How do I test DNSBL without locking myself out?

Use the Safe IP whitelist in the plugin settings. Keep your own IP address there, then use the built-in lookup and self-check tools to verify behaviour. Requests from whitelisted IPs are still evaluated and counted in statistics, but they are not blocked.



== Screenshots ==

1. Try-tests and self-check: direct DNS lookups for a specific IP plus a self-check of the current server and visitor address.

2. At a glance and visitor statistics: resolver status, selected trigger flags, whitelist state, Turnstile/registration protection status, and recorded DNSBL activity.

3. Core DNS lookup settings: preferred resolver hosts, cache age, cleanup interval, and the active blacklist trigger-flag profile including FraudBL-related flags.

4. Protection behavior: comment hiding, redirect handling, safe IP whitelisting, blocked-visitor redirect URL, and admin notice styling.

5. Tools integration and development: diagnostics mode, frontend dry-run guidance, production/dev Tools mode selection, and token configuration.

6. Cloudflare Turnstile and registration protection: Turnstile settings for comments plus DNSBL/FraudBL and Turnstile protection for new WordPress account registrations.

7. Frontend dry run in action: admin-bar dry-run indicator, blocked-comments notice on the public site, and the floating dry-run status banner used for safe live testing.


== Changelog ==

= Unreleased (post-3.1.0 maintenance) =

* Fixed checker-mode delist submit so the confirmed IP is always sent even when the IP field is locked/disabled.
* Fixed external Tools API `419` follow-up failures so they no longer appear as a local WordPress session-expired error in the checker UI.
* Improved checker-mode delist UX so **Delist** itself shows the in-flight submit state while both checker buttons are disabled to prevent duplicate submits.
* The removal-form frontend script now uses file-modification-time cache-busting so deployed JS fixes are picked up immediately instead of staying behind a stale `?ver=3.1.0` asset URL.

= Unreleased =

* Added a built-in plugin template for the primary removal page (`templates/removal-page.php`) when the selected delisting page does not already contain a removal shortcode.
* Saving the primary delisting page now performs a live delete-permission check against `GET /api/dnsbl/token/info` before WordPress accepts that page as the plugin-managed removal page.
* Shortcode-based custom removal pages continue to work, and the form now hides operations that the configured token is not allowed to perform.
* WordPress dashboard and plugin settings now warn when the configured DNSBL / Tools API token still lacks live delete / delist access, and the plugin can load the legacy shipped Swedish translation filename used by older packages.
* The token status block now reports the current add/delete/update capability, and delisting-page controls stay read-only until delete / delist access has been confirmed.
* Internal delist slug routing now registers a dedicated query-var rewrite path and refreshes rewrite rules on activation/slug updates, fixing plugin-managed `/delist` 404 issues.
* The managed public delist page now uses an IP-only checker flow and hides the custom-shortcode helper block; custom WordPress pages can still use `[dnsbl_removal_form]` and `[tornevall_dnsbl_removal_form]`.

= 3.1.0 =

* Added the plugin-side DNSBL write API integration (`add`, `delete`, `update`, `bulk`) plus bulk queueing and optional dry-run acknowledgement through the Tools DNSBL endpoints.
* Added the shortcode-based delisting/removal form (`[dnsbl_removal_form]`) with AJAX backend proxy support.
* The plugin UI now uses one visible **DNSBL / Tools API token** field instead of presenting separate token models in the settings page.
* The **Check token permissions** tool now always asks Tools for a live answer, warns when the token exists on the other Tools environment, and reports automatic DNSBL access when the configured token belongs to an active Tools admin.

= 3.0.3 =

* Fixed frontend dry-run availability so the public banner and toggle only appear when DNSBL dev mode is enabled and Tools environment mode is set to dev.

= 3.0.2 =

* Repackaged the release so updated screenshots and other WordPress.org assets can be picked up properly.
* Restored Markdown-style links in the readme after the previous plain-URL formatting pass.

= 3.0.1 =

* Simplified and aligned the public plugin name so it better matches the WordPress.org slug.
* Corrected the author metadata spelling to Thomas Tornevall.
* Reduced the WordPress.org tags to five broader discovery terms with better general search value.
* Refreshed the readme wording for FraudBL/fraud discovery and noted planned WooCommerce-oriented follow-up work.

= 3.0.0 =

* Refactored the plugin around WordPress-native DNS lookups, admin AJAX tooling and a namespaced internal structure while keeping the historical main plugin file name and compatibility entry points.
* Added asynchronous admin lookup and self-check tools that run without reloading the page.
* Added visitor statistics for resolved checks, blacklist hits, blocked requests, unique visitor addresses and cached blacklist activity.
* Added configurable cache TTL, configurable cleanup intervals and automatic expiry cleanup for both listed and non-listed DNSBL lookups.
* Added a safe IP whitelist, protected-admin notices and a one-click current-visitor whitelist action.
* Added public documentation links, changelog links and source-history links in the admin help flow.
* Added Cloudflare Turnstile protection for frontend WordPress comments.
* Added DNSBL/FraudBL checks for new WordPress account registrations.
* Added Cloudflare Turnstile protection for new WordPress account registrations.
* Added `IP_FRAUDCOMMERCE` to the default trigger-flag profile.
* Tightened comment blocking so hidden comment forms also reject direct submissions.
* Restricted dry-run simulation to the public site for logged-in administrators.
* Switched Tools integration default mode to production.
* Updated removal and delisting references to [the removal page](https://www.tornevall.net/removal/).

= 2.1.9 =

* `2.1.9` is the latest historical tag visible in the repository before the current 3.x cleanup and refactor work.


== Upgrade Notice ==

= Unreleased =

Planned next packaging step for the checker-mode delist payload fix, clearer follow-up error handling, and the corrected Delist-button loading/caching behavior for the public removal flow.


= 3.1.0 =

Adds the public DNSBL API token flow, AJAX-backed delisting tooling, and the final single-token permission-checker wording for the current release line.


= 3.0.3 =

Fixes the public frontend dry-run popup so it stays hidden in production Tools mode.

= 3.0.2 =

Packaging refresh for WordPress.org. Republishes the release so screenshots/assets are picked up properly and the readme link formatting is restored.

= 3.0.1 =

Maintenance packaging release. Cleans up readme metadata, tag usage, author spelling and plugin naming before distribution.

= 3.0.0 =

Important feature release. Adds Cloudflare Turnstile to comments and WordPress registrations, adds DNSBL/FraudBL checks to registrations, introduces statistics and safer whitelist-based dry-run tooling, and updates the public removal flow.
