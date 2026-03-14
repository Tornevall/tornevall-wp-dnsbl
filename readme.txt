=== Tornevall Networks DNSBL Implementation ===
Contributors: Tornevall
Tags: antispam, blacklist, fraud, comment spam, user registration
Requires at least: 5.8
Requires PHP: 8.1
Tested up to: 6.9
Stable tag: 3.0.3
License: GPLv2 or later

Tornevall Networks DNSBL implementation with FraudBL support for WordPress

== Description ==

Tornevall Networks DNSBL and FraudBL protection for WordPress. The plugin helps block comment activity, account registrations and other unwanted submissions from addresses flagged by Tornevall Networks DNSBL and FraudBL.

FraudBL is part of the protection layer used by the plugin and is available at [fraudbl.org](https://www.fraudbl.org/). For general discovery, broader search terms like fraud, blacklist, comment spam and user registration are usually easier to find than niche technical acronyms alone.

The plugin is intended to provide a lightweight anti-spam and anti-abuse layer for WordPress, with support for local caching to reduce repeated lookups and unnecessary load against blacklist services.

Current admin features include manual DNS lookup tools, self-check tools, visitor statistics, safe IP whitelisting, frontend dry-run support for administrators, Cloudflare Turnstile for comments, and DNSBL plus Turnstile protection for new WordPress account registrations. WooCommerce-oriented protection is a planned next step rather than part of the current release.

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

= 3.0.3 =

Fixes the public frontend dry-run popup so it stays hidden in production Tools mode.

= 3.0.2 =

Packaging refresh for WordPress.org. Republishes the release so screenshots/assets are picked up properly and the readme link formatting is restored.

= 3.0.1 =

Maintenance packaging release. Cleans up readme metadata, tag usage, author spelling and plugin naming before distribution.

= 3.0.0 =

Important feature release. Adds Cloudflare Turnstile to comments and WordPress registrations, adds DNSBL/FraudBL checks to registrations, introduces statistics and safer whitelist-based dry-run tooling, and updates the public removal flow.

