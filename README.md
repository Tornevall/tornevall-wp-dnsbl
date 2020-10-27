=== Tornevall Networks AntiSpam and Fraud Blacklist (DNSBL w/FraudBL) implementation ===
Contributors: Tornevall
Donate link: https://auth.tornevall.com/donate/
Tags: comments, spam, dnsbl, blacklist, dns blacklist, tor, tor exit nodes, proxy, antiproxy, proxy blocking, antispam, wpcf7, contactform, contact-form
Requires at least: 3.0.1
Tested up to: 5.2.2
Stable tag: 2.0.8
License: Apach

Tornevall Networks DNS Blacklist support for Wordpress

== Description ==

Tornevall Networks DNS Blacklist support. Blocks comment functions or redirects visitors who is blacklisted to external site.
Tested with WPCF7 5.1.4 (v2.0.8).

[Project tracker](https://tracker.tornevall.net/projects/DNSBLWP/issues) - Contribute with suggestions or bug reports here!
[Plugin URL](https://wordpress.org/plugins/tornevall-networks-dnsbl-implementation/)


= Contribute =

Can you help? Register and join the [project tracker](https://tracker.tornevall.net/projects/DNSBLWP/issues) and start creating!
You can also join the open source project at [Bitbucket](https://bitbucket.tornevall.net/projects/WWW/repos/tornevall-wp-dnsbl/browse). 

Want to add a new language to this plugin? You can contribute via [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/tornevall-networks-dnsbl-implementation).


== Installation ==

1. Upload the plugin archive to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin via admin control panel

The installations creates a new caching table in your wordpress database. This is used to not overload DNS servers with extreme resolving. The default cache lives for 900 sec (5 minutes) and will then clean up itself.

As of 2.0.0, the plugin should be "self healed" when the database is not in synch. Installing this plugin via for example [GIT](https://bitbucket.tornevall.net/projects/WWW/repos/tornevall-wp-dnsbl) might put the plugin in this mode (desynch) since tables might update between versions. If this is happening, disable and enable the plugin to reset them (as they during each disable/enable/plugin removal are reinstalled).

== Frequently Asked Questions ==

* Can I get delisted?

Yes. If you are blacklisted in Tornevall DNSBL, you can via https://dnsbl.tornevall.org - otherwise, you can't.



== Screenshots ==

The below screenshots is obsolete. New will come soone!

1. Screenshot that shows custom CSS, when comments section is disabled due to blacklisted address

https://www.tornevall.com/wp-content/uploads/2018/07/commentsDisabledCustomCSS.png

2. A part of the new DNSBL configuration interface

https://www.tornevall.com/wp-content/uploads/2018/07/dnsbl_config.png

The old interface: https://www.tornevall.com/wp-content/uploads/2018/07/dnsblOptions.jpg


== Changelog ==

= 2.0.8 =

    * [DNSBLWP-63] - Support ContactForm7


= Recent versions =

[CHANGELOG 2.0.8](https://www.tornevall.net/2019/08/08/dnsbl-for-wordpress-2-0-8-changelog/)
[CHANGELOG 2.0.7](https://www.tornevall.net/2019/07/27/dnsbl-for-wordpress-2-0-7-changelog/)
[CHANGELOG 2.0.6](https://www.tornevall.net/2018/08/01/dnsbl-for-wordpress-2-0-5-changelog/)
[CHANGELOG 2.0.5](https://www.tornevall.net/2018/08/01/dnsbl-for-wordpress-2-0-5-changelog/)
[CHANGELOG 2.0.2](https://www.tornevall.net/2018/07/18/dnsbl-for-wordpress-2-0-2-changelog/)
[CHANGELOG 2.0.1](https://www.tornevall.net/2018/07/17/dnsbl-for-wordpress-2-0-1-changelog/)
[CHANGELOG 2.0.0](https://www.tornevall.net/2018/07/17/dnsbl-for-wordpress-2-0-0-changelog/)


== Upgrade Notice ==

Nothing to see here

