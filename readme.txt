=== Tornevall Networks DNSBL Implementation ===
Contributors: Tornevall
Tags: antispam, blacklist, fraud, comment spam, user registration
Requires at least: 5.8
Requires PHP: 8.1
Tested up to: 7.1
Stable tag: 3.1.5
License: GPLv2 or later

Tornevall Networks DNSBL implementation with FraudBL support for WordPress

== Description ==

Protect your WordPress site against comment spam, abusive registrations, and other unwanted submissions with Tornevall Networks DNSBL and FraudBL.

The plugin is built to give site owners a practical anti-abuse layer without turning everyday moderation into a maintenance project..

Other Tornevall WordPress plugins can use the optional plugin-to-plugin integration filters to check visitor IP addresses and, when the configured DNSBL token permits it, perform an explicit administrator-approved abuse report. Installing this plugin is not required for those other plugins to function.

The 3.2 development line adds an opt-in WooCommerce commerce layer that can normalize confirmed fraud signals from payment integrations and custom hooks. Pending/review states are observed without publication, and removal is limited to matching locally owned references.

Report issues and feedback: [GitHub issues](https://github.com/Tornevall/tornevall-wp-dnsbl/issues)
Plugin URL: [WordPress.org plugin page](https://wordpress.org/plugins/tornevall-networks-dnsbl-implementation/)
Documentation: [DNSBL API documentation](https://tools.tornevall.net/docs/dnsbl-api)

= Support and feedback =

Bug reports and feedback can currently be submitted via [GitHub issues](https://github.com/Tornevall/tornevall-wp-dnsbl/issues).

Full Documentation: [DNSBL API documentation](https://tools.tornevall.net/docs/dnsbl-api)

Translations can be contributed via [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/tornevall-networks-dnsbl-implementation).

= Privacy and optional usage statistics =

The plugin includes optional aggregate usage statistics. This telemetry is disabled by default and is only enabled after a WordPress administrator explicitly checks the usage-statistics consent checkbox and saves the preference. Configuring a DNSBL / Tools API token does not enable telemetry and is not treated as consent.

When enabled, the plugin periodically sends aggregated DNSBL evaluation counters to Tornevall Tools. A batch can contain the plugin version, reporting time window, DNSBL listed/not-listed outcomes, returned bitmasks, blocked/not-blocked decisions, internal source categories such as request/admin-request/dry-run-request, and a count for each aggregate combination.

Telemetry batches do not include queried or visitor IP addresses, comments, usernames, email addresses, site URLs, hostnames, or raw DNS responses. Statistics collected locally before the administrator opts in are not sent retroactively.

Telemetry is normally sent at most once per hour through WordPress WP-Cron. Low-traffic sites may send later because WP-Cron is traffic-driven. Failed or timed-out submissions keep the same batch ID for a later retry so the Tools receiver can treat the batch idempotently instead of counting it twice.

The telemetry request is sent over HTTPS to the selected Tornevall Tools environment and authenticated with the configured DNSBL / Tools API token in the `X-Dnsbl-Token` header. The token is not included inside the telemetry JSON body. Tornevall Tools attributes accepted statistics internally to the authenticated token and token owner. The plugin does not expose a telemetry readback function to the sending site.

Turning telemetry off stops the telemetry schedule and discards any unsent telemetry batch. The plugin also adds suggested disclosure text to WordPress' built-in Privacy Policy Guide.

External service: [Tornevall Tools](https://tools.tornevall.net/)

Service documentation: [Tornevall Tools documentation](https://tools.tornevall.net/docs)

Privacy Policy: [Tornevall Tools Privacy Policy](https://tools.tornevall.net/docs/en/privacy-policy)

Terms of Service: [Tornevall Tools Terms of Service](https://tools.tornevall.net/docs/en/terms-of-service)

Technical telemetry details are also documented in `TELEMETRY.md` in the plugin source repository.


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

* Can I host a delisting page?

Yes. You can host the built-in form on any page with:

`[dnsbl_removal_form]`

But you need permissions for this, which can be gained by request via [https://tools.tornevall.net/](https://tools.tornevall.net/).

* Does another Tornevall plugin need DNSBL to work?

No. DNSBL is an optional protection add-on. A consumer such as Tornevall Tools for WordPress can continue without it. When DNSBL is active, the consumer can discover whether IP checking and explicit abuse reporting are available through the plugin integration filters.

* Does the plugin send usage statistics?

Only if a WordPress administrator explicitly opts in. Usage statistics are disabled by default. When enabled, aggregate DNSBL evaluation counters are batched and normally sent to Tornevall Tools at most once per hour through WP-Cron. Queried IP addresses, comments, usernames, email addresses, site URLs, hostnames, and raw DNS responses are not included in telemetry batches.


== Screenshots ==

1. Try-tests and self-check: direct DNS lookups for a specific IP plus a self-check of the current server and visitor address.

2. At a glance and visitor statistics: resolver status, selected trigger flags, whitelist state, Turnstile/registration protection status, and recorded DNSBL activity.

3. Core DNS lookup settings: preferred resolver hosts, cache age, cleanup interval, and the active blacklist trigger-flag profile including FraudBL-related flags.

4. Protection behavior: comment hiding, redirect handling, safe IP whitelisting, blocked-visitor redirect URL, and admin notice styling.

5. Tools integration and development: diagnostics mode, frontend dry-run guidance, production/dev Tools mode selection, and token configuration.

6. Cloudflare Turnstile and registration protection: Turnstile settings for comments, the optional public delisting/removal flow, plus DNSBL/FraudBL and Turnstile protection for new WordPress account registrations.

7. Frontend dry run in action: admin-bar dry-run indicator, blocked-comments notice on the public site, and the floating dry-run status banner used for safe live testing.


== Changelog ==

= 3.2.0 =

* Development line for first-class WooCommerce payment gateway and fraud integration.
* Added an opt-in normalized commerce fraud event layer with ownership-safe ADD / UPDATE / REMOVE handling.
* Added initial adapters for Klarna Payments, Kustom Checkout, current Resurs Merchant API signals and legacy Resurs compatibility hooks.
* Added generic DNSBL-owned commerce hooks so additional WooCommerce gateways and fraud providers can integrate without coupling to core internals.
* Added an administrator-only Commerce hooks page and development-only sandbox.
* Ordinary payment rejection is not treated as fraud unless an explicit fraud signal or trusted classifier confirms it.

= 3.1.6 =

* Added explicit opt-in aggregate DNSBL usage statistics with a separate administrator consent checkbox; configuring a token alone does not enable telemetry.
* Usage statistics are aggregated locally and normally sent at most once per hour through WP-Cron instead of sending one report per lookup/evaluation.
* Telemetry batches omit queried IP addresses, comments, usernames, email addresses, site URLs, hostnames, and raw DNS responses, and pre-consent history is not sent retroactively.
* Added WordPress Privacy Policy Guide disclosure text and public telemetry/service documentation.
* Added a stable plugin-to-plugin DNSBL integration bridge for optional Tornevall WordPress add-ons.
* Consumers can discover check/report capability, check a visitor IP and explicitly report web/guestbook abuse without reading DNSBL plugin internals.
* Guestbook/web abuse reports default to `IP_ABUSE_NO_SMTP` (64).
* Abuse publication is never triggered automatically by the integration bridge; reporting requires an administrator action and a DNSBL token with add permission.
* 3.1.6 remains untagged while the branch is under development.

= 3.1.5 =

* Fixed WordPress multisite/network registrations so the second `wp-signup.php` step can reuse the successful Turnstile check from step 1 instead of failing and sending the visitor back to the first step.

= 3.1.4 =

* Tested with WP7.
* Registration Turnstile and DNSBL/FraudBL checks now also protect WordPress multisite/network signups on `wp-signup.php`.

= 3.1.3 =

* Tested with WP7.

= 3.1.2 =

* Fixed the public removal-form Turnstile lifecycle so the widget waits for Cloudflare's API before rendering, keeps the returned widget id, and uses that widget id for reset/response handling.
* Fixed stale or empty Turnstile response handling by clearing tokens on expiration, timeout or error and recovering the current widget response before submit when the hidden token field is empty.
* Released the Tools-backed site identity metadata and bumped the plugin release metadata to `3.1.2`.

= 3.1.1 =

* Added an explicit admin checkbox for Turnstile on the public delisting/removal page.
* The public delist flow no longer inherits Turnstile automatically just because comment or registration Turnstile is configured.
* Site owners can now disable only the removal-page challenge when Cloudflare Turnstile has temporary problems, while keeping comment and registration protection enabled.

= 3.1.0 =

* Added the plugin-side DNSBL write integration (`add`, `delete`, `update`, `bulk`) around one visible **Write token** field, bulk queueing, and optional dry-run acknowledgement through the Tools DNSBL endpoints.
* Added the shortcode-based removal form (`[dnsbl_removal_form]`) with AJAX backend proxy support, built-in main removal-page templating, and live delisting-page permission gating.
* Added the Tools-backed checker/removal follow-up via `POST /api/dnsbl/check-ip`, together with the checker-style public delist flow and dashboard/settings warnings when live delete / delist access is still missing.
* Added advanced optional CIDR removal flow for permitted tokens, including safe `/24`..`/32` validation, plugin-local scan progress, a visible hit list of listed addresses, listed-hit-only delete targeting, sequential per-IP delete requests, and Cloudflare Turnstile verification for live removal-form submissions.
* The plugin UI now uses one visible **DNSBL / Tools API token** field, and the **Check token permissions** tool now always asks Tools for a live answer and reports effective DNSBL access more clearly.
* Preferred resolver hosts now cover all four canonical DNSBL/FraudBL zones, and migrations merge any missing defaults into existing installs without removing custom hosts.
* Shortcode/custom removal pages now expose only the operations allowed by the current token, while the plugin-managed main removal page stays delete-focused.
* Token status panels, delisting-page controls, and checker submits now better reflect real delete capability, including explicit IP reposting when the checker has locked the field before submit.
* Checker follow-up failures now report clearer backend/API errors for remote `419` cases, and write/check diagnostics now distinguish true invalid DNSBL tokens from wrong-token-type or inactive admin-key cases.
* Checker-mode Turnstile stays hidden during pre-check/background steps, is enforced on actual write submissions, the Delist button now carries the in-flight submit state itself, and checker/delist requests now also show a dedicated busy spinner row.
* CIDR scanning now stays inside WordPress in small local batches so the resolver side is not flooded, while the final delete still goes through the DNSBL write endpoint after the block scan has found at least one listed address and only for the IPs the local scan actually marked as listed, one IP at a time.
* If the user clicks **Check if listed** while a valid CIDR is still entered in the first checker IP field, the plugin now opens Advanced automatically, moves the CIDR there, and keeps that Advanced CIDR value as the authoritative range for the later scan/delete flow instead of requiring a separate single-IP anchor.
* The admin UI now also shows a dismissible reminder that links directly to the WordPress.org review form for quick feedback.

== Upgrade Notice ==

= 3.2.0 =

Development line for WooCommerce payment gateway and fraud integration. Not published as the WordPress.org stable tag yet.

= 3.1.6 =

Adds explicit optional usage-statistics consent and hourly aggregate telemetry batching plus the optional DNSBL integration bridge. Telemetry is disabled by default and is never enabled merely by configuring a DNSBL / Tools API token. No 3.1.6 release tag has been published yet.

= 3.1.5 =

Fixes the multisite/network `wp-signup.php` step-two registration flow so Turnstile no longer breaks the second step after a successful first-step check.

= 3.1.4 =

Adds multisite/network registration protection for Turnstile and DNSBL/FraudBL checks on `wp-signup.php`.

= 3.1.2 =

Fixes the public removal-form Cloudflare Turnstile lifecycle for live delist submissions and bumps the release metadata to 3.1.2.

= 3.1.1 =

Urgent hotfix release. Adds a dedicated Turnstile toggle for the public delisting/removal page so Cloudflare issues there can be mitigated without turning off comment or registration protection.

= 3.1.0 =

Adds the public DNSBL API token flow, AJAX-backed delisting tooling, the checker-style public removal flow, and the current local CIDR progress/hit-list/listed-target scan for the 3.1.0 release line.
