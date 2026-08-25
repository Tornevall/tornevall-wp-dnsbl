# Deprecated DNSBL bit 1

DNSBL bit value `1` is retained only for decoding legacy DNSBL responses and historical data. Its canonical legacy name is `FREE_SLOT_1_PREVIOUSLY_REPORTED`.

The active verified-proxy flag remains `IP_CONFIRMED` on bit value `2`. Bit 1 must not be repurposed as another verified-proxy trigger.

## WordPress trigger settings

The WordPress plugin does not expose `FREE_SLOT_1_PREVIOUSLY_REPORTED [1]` in the "Trigger on blacklist flags" selector.

Existing installations that still have the deprecated flag stored in `tornevall_dnsbl_filter_types` are normalized automatically. The deprecated value is removed while all active selected flags are preserved.

The internal DNSBL flag map still retains bit value 1 so legacy response masks can continue to be decoded without changing the meaning or value of the active flags.
