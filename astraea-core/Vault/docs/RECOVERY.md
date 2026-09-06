# Recovery Model

## Why the Astraea core gate exists

A normal WordPress backup plugin only runs when WordPress reaches that plugin in the active-plugin list. A broken plugin can fatal before the backup plugin loads.

Astraea Vault therefore ships an early integration file and a small `wp-settings.php` patch. In the supplied AstraeaOS WP source the gate executes after DB/options/multisite are available but before MU, network-active and normal plugins are loaded.

## Fatal correlation

A recent plugin update is not enough to trigger rollback. Vault correlates an eligible fatal error to the exact updated plugin path inside a bounded transaction window.

Eligible shutdown fatal classes currently include:

- `E_ERROR`
- `E_PARSE`
- `E_CORE_ERROR`
- `E_COMPILE_ERROR`
- `E_USER_ERROR`

Unrelated plugin errors do not match the updated plugin's path and therefore do not trigger its rollback.

## Recovery marker

The fatal shutdown handler performs minimal work:

- sanitize/record the incident;
- persist the correlated update transaction as pending recovery.

It does **not** perform a complex restore from the fatal shutdown context.

## Next-bootstrap recovery

The early gate:

1. loads only Vault's own minimal autoloader/services;
2. checks the pending recovery marker;
3. unlocks the master with the unattended service key;
4. validates that the snapshot is a plugin backup bound to the same plugin;
5. quarantines the failed current plugin path;
6. restores the verified previous files;
7. blocks the failed version from repeat automatic updates;
8. records and notifies the result.

If restore fails, the failed plugin is quarantined/deactivated and the site continues without repeatedly loading it.

## Manual restore safety

Manual restore authenticates the Vault passphrase **before** starting expensive backup work. Then it creates and verifies a full current-state safety snapshot. If the requested restore fails after apply begins, Vault attempts to restore the safety snapshot.

The system reports a hard storage fault if both primary restore and safety rollback fail; it does not report a false success.
