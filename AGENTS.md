# AGENTS.md

This file defines repository-local guidance for Tornevall Networks DNSBL WordPress development.

## Scope

This repository contains the standalone Tornevall Networks DNSBL WordPress plugin. Keep DNSBL/FraudBL behavior here rather than duplicating it in Tornevall Tools for WordPress.

## Branch model

Read `.github/BRANCHING.md` before branch or release work.

- `3.1` is the current stable maintenance/development line.
- `master` mirrors the current stable line.
- `3.2` is downstream from `3.1` and contains the 3.2.0 WooCommerce/fraud work.
- Stable changes that apply to both lines should land in `3.1` first and then flow to `master` and `3.2`.
- Do not create release tags merely because branches are synchronized.
- Never force-push or silently resolve forward-sync conflicts.

## Tools account pairing

The standalone plugin may pair directly with Tornevall Tools using the documented device-style flow.

- Keep pairing opt-in and administrator initiated.
- Never display or log raw device codes or service token values.
- Keep the raw device code short-lived and server-side.
- Validate callback state, nonce, authorization host and same-site callback expectations before token exchange.
- Explicit DNSBL plugin credentials take precedence over managed fallback credentials supplied by Tornevall Tools for WordPress.
- Admin DNSBL tokens must never be installed directly into WordPress; Tools must return a non-admin delegated credential.

## Security

- Sanitize input and escape output.
- Keep tokens and secrets server-side.
- Protect state-changing admin actions with capability checks and nonces.
- Do not send credentials in URLs, browser-visible markup, logs, tests, screenshots or documentation examples.
- Do not broaden remote origins without an explicit reviewed requirement.

## Testing

Material changes require relevant tests and CI coverage where practical.

- Run PHP syntax checks for changed PHP files.
- Keep WordPress Plugin Check coverage intact.
- Add regression tests for behavior changes whenever practical.
- Preserve existing runtime, required-file and WooCommerce/fraud tests when touching adjacent code.

## Documentation

Behavior changes should update the relevant repository documentation, including `README.md`, `readme.txt`, `CHANGELOG.md` and files under `docs/` when applicable.

Every development change should have a linked issue and pull request. Reuse existing issues and PRs when they already track the work.
