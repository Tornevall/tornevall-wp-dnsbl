=== Tornevall Networks DNSBL and Fraud Blacklist implementation ===
Contributors: Tornevall
Donate link: https://auth.tornevall.com/donate/
Tags: comments, spam, dnsbl, blacklist, dns blacklist, tor, tor exit nodes, proxy, antiproxy, proxy blocking
Requires at least: 3.0.1
Tested up to: 4.9.7
Stable tag: 2.0.0
License: Apache

Tornevall Networks DNS Blacklist support for Wordpress

== Description ==

Tornevall Networks DNS Blacklist support. Blocks comment functions or redirects visitors who is blacklisted to external site.

[Project and plugin development](https://tracker.tornevall.net/projects/DNSBLWP/issues) - [Plugin URL](https://wordpress.org/plugins/tornevall-networks-dnsbl-implementation/)

= Contribute =

Do you think there are ways to make our plugin even better? Join our project for Tornevall DNSBL at [Bitbucket](https://bitbucket.tornevall.net/projects/WWW/repos/tornevall-wp-dnsbl/browse) or 
You can also visit the [project tracker](https://tracker.tornevall.net/secure/Dashboard.jspa?selectPageId=10900) for the "DNSBL for WordPress" - or the [major project tracker for Tornevall Networks DNSBL with FraudBL](https://tracker.tornevall.net/secure/Dashboard.jspa?selectPageId=10601).

Want to add a new language to this plugin? You can contribute via [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/tornevall-networks-dnsbl-implementation).


== Installation ==

1. Upload the plugin archive to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin via admin control panel

The installations creates a new caching table in your wordpress database. This is used to not overload DNS servers with extreme resolving. The default cache lives for 900 sec (5 minutes) and will then clean up itself.

== Frequently Asked Questions ==

Can I get delisted?

Yes. If you are blacklisted in Tornevall DNSBL, you can via https://dnsbl.tornevall.org - otherwise, you can't.


== Screenshots ==

The below screenshots is obsolete. New will come soone!

1. Screenshot that shows custom CSS, when comments section is disabled due to blacklisted address

https://www.tornevall.com/wp-content/uploads/2018/07/commentsDisabledCustomCSS.png

2. A part of the new DNSBL configuration interface

https://www.tornevall.com/wp-content/uploads/2018/07/dnsbl_config.png

The old interface: https://www.tornevall.com/wp-content/uploads/2018/07/dnsblOptions.jpg


== Changelog ==

= 2.0.0 =

    * [DNSBLWP-30] - IPtype in helpers.php has no effect anywhere (and is translated into arpas instead of iptypes)
    * [DNSBLWP-33] - Do not use HTTP Post when sending DELETE (it fails)
    * [DNSBLWP-4] - API Key for handling of blacklists
    * [DNSBLWP-18] - Instead of using TorneLIB-curl, use internal WP functions where it is possible
    * [DNSBLWP-21] - Clean up database during deactivation/uninstall
    * [DNSBLWP-22] - Configuration menus
    * [DNSBLWP-25] - Reinstate the commentblock
    * [DNSBLWP-26] - Reinstate page redirection
    * [DNSBLWP-27] - Reinstate listcontrol
    * [DNSBLWP-28] - DNS Lookups in plain mode
    * [DNSBLWP-29] - Currently the lookups is based on API - make it resolver-based when single addresses are being requested
    * [DNSBLWP-31] - Remove test hosts from project
    * [DNSBLWP-32] - Use API instead of DNS when running delisting requests
    * [DNSBLWP-34] - Delist in ajax mode
    * [DNSBLWP-35] - Allow enter in search form (ajax)


== Upgrade Notice ==

Nothing to see here

