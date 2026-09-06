<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('astraea_vault_backup_event');

$role = get_role('administrator');
if ($role !== null) {
    foreach (['manage_astraea_vault', 'restore_astraea_vault', 'download_astraea_vault'] as $cap) {
        $role->remove_cap($cap);
    }
}

// Deliberately preserve encrypted backups, the keyring and recovery metadata.
// Destructive cryptographic data deletion requires an explicit in-product action, not WordPress uninstall side effects.
