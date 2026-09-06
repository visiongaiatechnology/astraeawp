<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);
namespace Astraea\Vault\Support;
use Astraea\Vault\Exception\StorageException;
final class DbLock
{
    private bool $held = false;
    public function acquire(string $name, int $timeoutSeconds = 0): void
    {
        global $wpdb;
        if (preg_match('/^[a-z0-9:_-]{1,64}$/D', $name) !== 1) {
            throw new StorageException('Invalid lock identifier.');
        }
        $sql = $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, max(0, min(30, $timeoutSeconds)));
        $result = $wpdb->get_var($sql);
        if ((string)$result !== '1') {
            throw new StorageException('Astraea Vault is busy with another protected operation.');
        }
        $this->held = true;
    }
    public function release(string $name): void
    {
        if (!$this->held) { return; }
        global $wpdb;
        $sql = $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name);
        $wpdb->get_var($sql);
        $this->held = false;
    }
}
