# Managed Tools DNSBL token bridge

The DNSBL plugin remains fully standalone and keeps its own explicit token settings as the highest priority.

An optional server-side integration may provide a site-specific DNSBL credential through:

```php
add_filter('tornevall_dnsbl_managed_api_token', function ($token) {
    return $managedToken;
});
```

The bridge is intended for integrations such as Tornevall Tools for WordPress after a WordPress administrator explicitly connects and authorizes the site in Tools.

## Priority

The existing DNSBL token resolution order is preserved. The managed token feeds only the final Tools fallback and is therefore used only after the plugin's own configured token values are empty.

1. Visible DNSBL write token.
2. Existing legacy removal token fallback, when still present on an upgraded installation.
3. Existing stored legacy Tools token fallback.
4. `tornevall_dnsbl_managed_api_token` supplied by another server-side plugin.

This means a manually configured DNSBL token always remains the explicit override.

## Security

- The DNSBL plugin does not request or discover Tools account tokens itself.
- The filter is server-side only. The managed credential must not be rendered into HTML, browser JavaScript, diagnostics, screenshots or logs.
- The provider is responsible for obtaining the credential through an explicit authorization flow.
- The DNSBL plugin continues to perform normal token-info and write permission checks against the effective resolved token.
- If no provider is installed, the filter returns an empty value and the DNSBL plugin behaves exactly as before.

## Tornevall Tools for WordPress

Tornevall Tools for WordPress can use the Tools WordPress pairing API to obtain a dedicated site-specific DNSBL credential after the logged-in Tools user approves the connection. It then supplies that credential through this filter without copying DNSBL implementation into the Tools-for-WordPress plugin.
