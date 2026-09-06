<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Restore;

use Astraea\Vault\Exception\SecurityException;
use Astraea\Vault\Exception\StorageException;

final class DatabaseRestorer
{
    public function apply(array $tables): array
    {
        global $wpdb;
        $stats = ['tables' => 0, 'rows' => 0];
        $keyring = get_option(\Astraea\Vault\Config::OPTION_KEYRING, null);
        $settings = get_option(\Astraea\Vault\Config::OPTION_SETTINGS, null);
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $table => $entry) {
                $this->assertTable($table);
                $schema = (string)($entry['schema'] ?? '');
                $rowFile = (string)($entry['rows_file'] ?? '');
                if (!$this->validateSchema($table, $schema)) {
                    throw new SecurityException('Database schema validation failed.');
                }
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
                $result = $wpdb->query($schema);
                if ($result === false) {
                    throw new StorageException('Database schema restore failed.');
                }
                if ($rowFile !== '') {
                    $handle = @fopen($rowFile, 'rb');
                    if (!is_resource($handle)) {
                        throw new StorageException('Database staging file is unavailable.');
                    }
                    try {
                        while (($line = fgets($handle)) !== false) {
                            $payload = json_decode(rtrim($line, "\r\n"), true, 128, JSON_THROW_ON_ERROR);
                            if (!is_array($payload) || (string)($payload['table'] ?? '') !== $table || !is_array($payload['rows'] ?? null)) {
                                throw new SecurityException('Database staging record validation failed.');
                            }
                            foreach ($payload['rows'] as $encodedRow) {
                                if (!is_array($encodedRow)) {
                                    throw new SecurityException('Database row validation failed.');
                                }
                                $row = [];
                                foreach ($encodedRow as $column => $value) {
                                    if (!is_string($column) || preg_match('/^[A-Za-z0-9_$]+$/D', $column) !== 1) {
                                        throw new SecurityException('Database column validation failed.');
                                    }
                                    if ($value === null) {
                                        $row[$column] = null;
                                    } elseif (is_string($value)) {
                                        $decoded = base64_decode($value, true);
                                        if ($decoded === false) {
                                            throw new SecurityException('Database value decoding failed.');
                                        }
                                        $row[$column] = $decoded;
                                    } else {
                                        throw new SecurityException('Database value type validation failed.');
                                    }
                                }
                                if ($wpdb->insert($table, $row) === false) {
                                    throw new StorageException('Database row restore failed.');
                                }
                                $stats['rows']++;
                            }
                        }
                        if (!feof($handle)) {
                            throw new StorageException('Database staging stream read failed.');
                        }
                    } finally {
                        fclose($handle);
                    }
                }
                $largeRows = is_array($entry['large_rows'] ?? null) ? $entry['large_rows'] : [];
                foreach ($largeRows as $largeRow) {
                    if (!is_array($largeRow) || !is_array($largeRow['values'] ?? null)) {
                        throw new SecurityException('Large database row staging metadata validation failed.');
                    }
                    $row = [];
                    foreach ($largeRow['values'] as $column => $path) {
                        if (!is_string($column) || preg_match('/^[A-Za-z0-9_$]+$/D', $column) !== 1) {
                            throw new SecurityException('Large database row column validation failed.');
                        }
                        if ($path === null) {
                            $row[$column] = null;
                            continue;
                        }
                        if (!is_string($path) || !is_file($path) || is_link($path)) {
                            throw new SecurityException('Large database row staging path validation failed.');
                        }
                        $value = @file_get_contents($path);
                        if ($value === false) {
                            throw new StorageException('Unable to read large database row staging value.');
                        }
                        $row[$column] = $value;
                    }
                    if ($wpdb->insert($table, $row) === false) {
                        throw new StorageException('Large database row restore failed.');
                    }
                    $stats['rows']++;
                }
                $stats['tables']++;
            }
        } finally {
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            if (is_array($keyring)) {
                update_option(\Astraea\Vault\Config::OPTION_KEYRING, $keyring, false);
            }
            if (is_array($settings)) {
                update_option(\Astraea\Vault\Config::OPTION_SETTINGS, $settings, false);
            }
            wp_cache_flush();
        }
        return $stats;
    }

    private function assertTable(string $table): void
    {
        global $wpdb;
        if (!str_starts_with($table, $wpdb->prefix) || preg_match('/^[A-Za-z0-9_$]+$/D', $table) !== 1) {
            throw new SecurityException('Database table validation failed.');
        }
    }

    private function validateSchema(string $table, string $schema): bool
    {
        if (strlen($schema) < 20 || strlen($schema) > 4 * 1024 * 1024 || str_contains($schema, "\0")) {
            return false;
        }
        $quoted = preg_quote($table, '/');
        return preg_match('/^CREATE TABLE [`"]?' . $quoted . '[`"]?\s*\(/i', ltrim($schema)) === 1;
    }
}
