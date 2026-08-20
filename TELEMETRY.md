# Opt-in usage statistics

Tornevall Networks DNSBL can send aggregate usage statistics to Tornevall Tools only after a WordPress administrator explicitly enables the telemetry checkbox.

## Default state

Telemetry is disabled by default. Configuring a DNSBL / Tools API token does not enable telemetry and is not treated as consent.

When telemetry is enabled for the first time, the cursor is moved to the current end of the local DNSBL statistics table. Existing statistics collected before consent are therefore not sent retroactively.

Disabling telemetry stops the telemetry WP-Cron schedule and discards any unsent pending telemetry batch.

## What is sent

The plugin reads its existing local `dnsblstats` rows and aggregates them before transmission. A batch can contain:

- schema version
- random batch ID used for idempotent retries
- plugin version
- reporting period start and end
- aggregate DNSBL evaluation rows containing:
  - returned bitmask
  - listed / not listed result
  - blocked / not blocked decision
  - internal source category such as `request`, `admin-request`, or `dry-run-request`
  - count for that combination

The plugin does not include queried or visitor IP addresses, comments, usernames, email addresses, site URLs, hostnames, or raw DNS responses in telemetry batches.

## Transport and authentication

Batches are sent over HTTPS to the configured Tornevall Tools environment at:

`POST /api/dnsbl/telemetry/batch`

The existing DNSBL / Tools API token is supplied in the `X-Dnsbl-Token` request header for authentication. The token is not duplicated inside the telemetry JSON body.

Tornevall Tools attributes accepted statistics internally to the authenticated token and token owner. The telemetry endpoint is write-only for the WordPress plugin and does not provide the sending site with a telemetry readback API.

As with any HTTPS request, the sending WordPress server's network IP address is necessarily visible to Tornevall Tools and can be present in normal server/API request logs. This transport metadata is separate from the aggregate telemetry payload and is not the visitor/query IP address being evaluated by DNSBL.

## Frequency and retry behavior

Telemetry is scheduled with WordPress WP-Cron and normally sends at most one batch per hour. WP-Cron runs when WordPress receives traffic, so actual delivery can be later on low-traffic sites.

A pending batch keeps the same batch ID until Tools acknowledges it. If a request fails or times out, the local cursor is not advanced and the same batch is retried on a later run. Tools treats the token + batch ID combination as idempotent so a retry cannot intentionally count the same accepted batch twice.

## WordPress privacy integration

The plugin registers suggested privacy-policy text with `wp_add_privacy_policy_content()` so site administrators can disclose the optional telemetry through WordPress' built-in Privacy Policy Guide.

The WordPress.org plugin readme also documents the external service, data categories, opt-in behavior, and transmission frequency.

Tornevall Tools documentation: https://tools.tornevall.net/docs

Privacy policy: https://tools.tornevall.net/docs/en/privacy-policy

Terms of service: https://tools.tornevall.net/docs/en/terms-of-service
