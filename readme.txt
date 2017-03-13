=== Plugin Name ===
Contributors: -
Donate link: https://tornevall.net/donate/
Tags: comments, spam, dnsbl, blacklist, dns blacklist
Requires at least: 3.0.1
Tested up to: 4.x
Stable tag: 1.0.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Project URL: https://tornevall.net/forum/project.php?12-Wordpress-DNSBL

Tornevall Networks DNS Blacklist support for Wordpress

== Description ==

Tornevall Networks DNS Blacklist support. Blocks comment functions or redirects visitors who is blacklisted to external site.


== Installation ==

1. Upload the plugin archive to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin via admin control panel

The installations creates a new caching table in your wordpress database. This is used to not overload DNS servers with extreme resolving. The default cache lives for 900 sec (5 minutes) and will then clean up itself.

== Frequently Asked Questions ==

Empty space


== Screenshots ==

1. Screen shot that shows how the control panel looks like

https://tornevall.net/forum/issue.php?36-Primary-DNSBL-Blocking-of-bad-hosts

2. Full view for 1.0.1-updates (141205)

https://tornevall.net/forum/attachment.php?attachmentid=11954

== Changelog ==

= 1.0.2 =

* Tablename fixes

= 1.0.1 =

* Minimalistic statistics (https://tornevall.net/forum/issue.php?39-DNSBL-Statistics)
* Update timestamps before expire (https://tornevall.net/forum/issue.php?69-Update-timestamps-instead-of-expire)
* Avoid using internal MySQL Calls (https://tornevall.net/forum/issue.php?68-Avoid-using-internal-function-for-UNIX_TIMESTAMP)
* Duplicate key-fixes (https://tornevall.net/forum/issue.php?67-Duplicate-keys)

= 1.0.0 =

* Plugin init
* Admin control panel added
* Detection of hosts on bitmask level


== Upgrade Notice ==

= 1.0.0 =
Nothing to see here

