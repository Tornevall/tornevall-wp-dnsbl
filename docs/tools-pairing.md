# Direct Tornevall Tools pairing

The DNSBL plugin can authorize directly against Tornevall Tools without requiring an administrator to copy a DNSBL token manually.

## Flow

1. A WordPress administrator starts the connection from the DNSBL settings page.
2. The plugin creates a short-lived pairing request at `POST /api/integrations/wordpress/device` and requests only the `dnsbl` service.
3. The administrator is redirected to Tornevall Tools and signs in with the normal Tools account session.
4. Tools lists active DNSBL tokens owned by that user using safe metadata only. Existing raw token values are never displayed.
5. The administrator selects a token and chooses whether to rotate/reuse it or create a separate site token.
6. After approval, WordPress receives the callback and exchanges the high-entropy device code once through `POST /api/integrations/wordpress/token`.
7. The returned DNSBL credential is stored directly in the canonical `tornevall_dnsbl_write_token` WordPress option.

## Existing-token rotation

Rotation is the default choice for a selected non-admin DNSBL token.

Tools preserves the existing token database id, permissions and delete guardrails, generates a new secret and invalidates the previous secret immediately. Only the newly generated value is returned through the one-time server-to-server exchange.

Any other client still using the old secret must be updated after rotation. The Tools approval page displays this warning before the user approves the operation.

## Separate site token

The Tools approval page also allows the selected token to remain unchanged. In that mode Tools creates a new non-admin DNSBL token with the selected token's effective permissions and returns that new site credential instead.

## Admin tokens

Admin DNSBL tokens are never installed directly in WordPress and are never rotated into an external WordPress installation. If an admin token is selected, Tools creates a non-admin token carrying the effective DNSBL permissions instead.

## Authentication model

This integration uses a device-style authorization flow rather than a separate OAuth client secret.

- The Tools browser session authenticates the user.
- Approval is explicit.
- The raw device code has high entropy and is stored only temporarily by the WordPress plugin.
- Tools stores only the device-code hash.
- The approved credential bundle is encrypted while waiting for exchange.
- Credential exchange is server-to-server and single-use.
- The plugin validates the Tools authorization host, its WordPress nonce, the local pairing state and the returned user code before exchanging credentials.

## Compatibility with Tornevall Tools for WordPress

The standalone DNSBL plugin can pair with Tools directly. It also continues to support the `tornevall_dnsbl_managed_api_token` server-side filter so Tornevall Tools for WordPress may provide a managed fallback credential when no explicit DNSBL token is configured locally.

An explicitly configured DNSBL write token remains the highest-priority credential.