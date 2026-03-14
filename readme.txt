=== Tornevall Networks AntiSpam and Fraud Blacklist (DNSBL w/FraudBL) implementation ===
Contributors: Tornevall
Tags: spam, antispam, dnsbl, fraudbl, blacklist, dns blacklist, proxy, tor, tor exit nodes, comments, comment spam, abuse prevention
Requires at least: 5.8
Requires PHP: 8.1
Tested up to: 6.9
Stable tag: 3.1.0
License: GPLv2 or later

Tornevall Networks DNS Blacklist support for WordPress

== Description ==

Tornevall Networks DNS Blacklist support for WordPress. The plugin helps block comment activity and other unwanted submissions from addresses flagged by Tornevall Networks DNSBL and FraudBL.

FraudBL is part of the protection layer used by the plugin and is available at https://www.fraudbl.org/.

The plugin is intended to provide a lightweight anti-spam and anti-abuse layer for WordPress, with support for local caching to reduce repeated lookups and unnecessary load against blacklist services.

Current admin features include manual DNS lookup tools, self-check tools, and a statistics overview for recorded checks, blacklist hits and blocked requests.

Report issues and feedback: https://github.com/Tornevall/tornevall-wp-dnsbl/issues
Plugin URL: https://wordpress.org/plugins/tornevall-networks-dnsbl-implementation/
Documentation: https://tools.tornevall.net/docs/dnsbl-plugin


= Support and feedback =

Bug reports and feedback can currently be submitted via GitHub:
https://github.com/Tornevall/tornevall-wp-dnsbl/issues

Full API Documentation: https://tools.tornevall.net/docs/dnsbl-plugin

Translations can be contributed via https://translate.wordpress.org/projects/wp-plugins/tornevall-networks-dnsbl-implementation.


== Installation ==

1. Upload the plugin archive to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin via admin control panel

The installation creates a cache table in the WordPress database. This reduces repeated DNS lookups and helps avoid unnecessary load against blacklist services. Both blacklisted and non-listed lookups are cached. The default cache lifetime is 600 seconds and the cleanup interval is 300 seconds.

The plugin also supports a safe IP whitelist. Whitelisted IP addresses are still checked and can appear in statistics, but they are not blocked, redirected or marked as spam. When possible, the activating visitor IP is seeded into that whitelist automatically during first-time setup.

If the database schema becomes out of sync after an upgrade or a manual source-based install, deactivate and reactivate the plugin to recreate the required tables.

== Frequently Asked Questions ==

* Can I get delisted?

Yes. If you are blacklisted in Tornevall DNSBL, you can via https://dnsbl.tornevall.org - otherwise, you can't.

* How do I test DNSBL without locking myself out?

Use the Safe IP whitelist in the plugin settings. Keep your own IP address there, then use the built-in lookup and self-check tools to verify behaviour. Requests from whitelisted IPs are still evaluated and counted in statistics, but they are not blocked.



== Screenshots ==

Current screenshots from the plugin interface.

1. Screenshot that shows custom CSS, when comments section is disabled due to blacklisted address

https://www.tornevall.net/wp-content/uploads/2018/07/commentsDisabledCustomCSS.png

2. A part of the new DNSBL configuration interface

https://www.tornevall.net/wp-content/uploads/2018/07/dnsbl_config.png

The old interface: https://www.tornevall.com/wp-content/uploads/2018/07/dnsblOptions.jpg


== Changelog ==

= Unreleased =

* Added a visitor statistics summary in the admin dashboard.
* Added counters for resolved checks, blacklist hits, blocked requests, total cached entries, and cached non-listed entries.
* Restored public DNSBL plugin documentation under `tools.tornevall.net/docs/dnsbl-plugin`.
* Added a dedicated `CHANGELOG.md` to the plugin root.
* Added changelog and source-history links to the admin help panel.
* Added configurable cache cleanup intervals and automatic expiry cleanup.
* Added a one-click current-visitor whitelist action and a protected-admin notice.

= 3.1.0 =

* Cache now stores both listed and non-listed lookups, reducing repeat DNS traffic.
* Added configurable cache TTL and configurable cleanup interval with automatic purging.
* Added a plugin-owned admin notice for protected administrators whose IP matches active DNSBL flags.
* Added a one-click action to add the current visitor IP to the whitelist.
* Refactored the plugin internals behind namespaced classes while keeping the historical callback names for compatibility.

= 3.0.0 =

* Refactored the plugin around WordPress-native DNS lookups and admin AJAX tooling.
* Replaced the old bitmask parser layer with a lightweight internal utility implementation.
* Consolidated helper and Tools communication logic into a shared `includes/dnsbl-utils.php` module with docblocks.
* Removed retired legacy/APIv3/resource integration code, legacy assets and old compatibility layers.
* Added asynchronous admin lookup and self-check tools that run without reloading the page.
* Improved migration handling to clean up retired options and old table names during upgrades.
* Simplified comment protection to use DNSBL checks and optional Tools-based assessment.
* Preserved the historical main plugin file name `tornevall-wp-dnsbl.php` for backward compatibility.
* Standardized internal file names for admin, bootstrap, migrations, utils and admin JavaScript.
* Updated plugin structure, metadata and readme content for the current maintenance release.


== Upgrade Notice ==


= 3.1.0 =

Update recommended for the new cache management controls, total cache statistics, one-click whitelist flow and namespaced internal refactor.

= 3.0.0 =

Update recommended for the new DNS/Tools architecture, compatibility-safe packaging cleanup and validation refresh.

