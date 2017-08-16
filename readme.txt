=== Plugin Name ===
Contributors: Tornevall
Donate link: https://tornevall.net/donate/
Tags: comments, spam, dnsbl, blacklist, dns blacklist, tor, tor exit nodes, proxy, antiproxy, proxy blocking
Requires at least: 3.0.1
Tested up to: 4.8
Stable tag: 1.1.0
License: Apache

Tornevall Networks DNS Blacklist support for Wordpress

== Description ==

Tornevall Networks DNS Blacklist support. Blocks comment functions or redirects visitors who is blacklisted to external site.

[Project and plugin development](https://tracker.tornevall.net/projects/DNSBLWP/issues)
[Plugin URL](https://wordpress.org/plugins/tornevall-networks-dnsbl-implementation/)


== Installation ==

1. Upload the plugin archive to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin via admin control panel

The installations creates a new caching table in your wordpress database. This is used to not overload DNS servers with extreme resolving. The default cache lives for 900 sec (5 minutes) and will then clean up itself.

== Frequently Asked Questions ==

Empty space


== Screenshots ==

1. Screen shot that shows how the control panel looks like

http://tracker.tornevall.net/secure/attachment/10200/dnsbl_config_comments.jpg

2. Full view for 1.0.1-updates (141205)

https://tracker.tornevall.net/secure/attachment/10201/dnsblOptions.jpg

== Changelog ==

= 1.1.1 =

 * DNSBLWP-17: Incorrect index on table alteration might still cause headers already sent (Final!)
 * Making sure that curl is really active before approving usage (showing notice if not available or disabled)

= 1.1.0 =

 * DNSBLWP-16 - Activation failures
 * DNSBLWP-14 - Synch with current bitmask set
 * Resolver class update

= 1.0.5 =

* Found a html-tag that was not closed properly in the translation releae (which was 1.0.4)

= 1.0.4 =

* Added support for language Swedish (http://tracker.tornevall.net/browse/TSDWP-13)

= 1.0.3 =

* Issue tracker switched to JIRA (http://tracker.tornevall.net/projects/TSDWP)

= 1.0.2 =

* Tablename fixes

= 1.0.1 =

* Minimalistic statistics (http://tracker.tornevall.net/browse/TSDWP-7)
* Update timestamps before expire (http://tracker.tornevall.net/browse/TSDWP-6)
* Avoid using internal MySQL Calls (http://tracker.tornevall.net/browse/TSDWP-2)
* Duplicate key-fixes (http://tracker.tornevall.net/browse/TSDWP-1)

= 1.0.0 =

* Plugin init (http://tracker.tornevall.net/browse/TSDWP-9, http://tracker.tornevall.net/projects/TSDWP/issues/TSDWP-5)
* Admin control panel added
* Detection of hosts on bitmask level


== Upgrade Notice ==

= 1.0.0 =

Nothing to see here

