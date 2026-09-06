<?php
declare(strict_types=1);

namespace Astraea\Tests;

use Astraea\Database\Connection;
use Astraea\Database\MigrationRunner;
use Astraea\Database\Migrations\Migration_001_InitialSchema;
use Astraea\Options\OptionsGuard;

require_once __DIR__ . '/TestCase.php';

/**
 * Mock wpdb for testing database migration logic and parameterization.
 */
class MockWpdb {
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public string $usermeta = 'wp_usermeta';
    public string $options = 'wp_options';
    public string $last_error = '';
    /** @var array<string, mixed> */
    public array $inserted = [];
    /** @var string[] */
    public array $queries = [];

    public function get_charset_collate(): string {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $query, ...$args): string {
        return vsprintf(str_replace(['%s', '%d', '%f'], ["'%s'", '%d', '%f'], $query), $args);
    }

    public function query(string $query): int {
        $this->queries[] = $query;
        return 1;
    }

    public function get_results(string $query, string $output = 'OBJECT'): array {
        $this->queries[] = $query;
        if (str_contains($query, 'SHOW INDEX FROM')) {
            // Simulate that index does not exist initially
            return [];
        }
        if (str_contains($query, 'SELECT version FROM')) {
            return [];
        }
        return [];
    }

    public function get_var(string $query): mixed {
        $this->queries[] = $query;
        return null;
    }

    public function insert(string $table, array $data, ?array $format = null): int {
        $this->inserted[] = ['table' => $table, 'data' => $data];
        return 1;
    }

    public function esc_like(string $text): string {
        return addcslashes($text, '_%\\');
    }
}

final class DatabaseTest extends TestCase {

    public static function run(): void {
        echo "Running DatabaseTest...\n";

        $mockWpdb = new MockWpdb();
        $conn = new Connection($mockWpdb);
        $conn->setProfiling(true);

        // 1. Connection Query & Parameterization
        $rows = $conn->query("SELECT * FROM wp_posts WHERE ID = %d", [42]);
        self::assertEquals([], $rows, 'Query on empty mock should return empty array');
        self::assertEquals(1, count($conn->getQueryLog()), 'Query log should contain 1 entry');
        self::assertEquals('SELECT * FROM wp_posts WHERE ID = %d', $conn->getQueryLog()[0]['sql'], 'Query log must contain SQL template, not materialized values');
        self::assertEquals('[PARAM:integer]', $conn->getQueryLog()[0]['params'][0], 'Bound parameters must be redacted in query log');

        // 2. Transaction Management
        $conn->beginTransaction();
        $conn->execute("UPDATE wp_options SET option_value = %s WHERE option_name = %s", ['val', 'name']);
        $conn->commit();
        self::assertStringContains('START TRANSACTION', $mockWpdb->queries[1], 'Should issue START TRANSACTION');
        self::assertStringContains('COMMIT', end($mockWpdb->queries), 'Should issue COMMIT');

        // 2b. StorageException Opaque Handling (Pattern 1.5.A & 1.5.C)
        $mockWpdb->last_error = 'Access denied for user';
        $caughtStorageEx = false;
        try {
            $conn->query('SELECT 1');
        } catch (\Astraea\Exceptions\StorageException $e) {
            $caughtStorageEx = true;
            self::assertEquals('A server error occurred.', $e->getOpaqueMessage(), 'StorageException must provide opaque client message');
            self::assertTrue(is_subclass_of($e, \Astraea\Exceptions\AppException::class), 'StorageException must inherit from AppException');
        }
        self::assertTrue($caughtStorageEx, 'Database error must throw StorageException');
        $mockWpdb->last_error = '';

        // 3. Migration Runner Idempotency
        $runner = new MigrationRunner($conn);
        $migration = new Migration_001_InitialSchema();
        self::assertEquals('001_initial_schema', $migration->getVersion(), 'Migration version must match');

        $results = $runner->runPending([$migration]);
        self::assertEquals(['001_initial_schema' => 'applied'], $results, 'Migration should report applied');

        // Verify that postmeta index was requested
        $foundPostmetaIndex = false;
        foreach ($mockWpdb->queries as $q) {
            if (str_contains($q, 'ALTER TABLE wp_postmeta ADD INDEX post_id_meta_key')) {
                $foundPostmetaIndex = true;
                break;
            }
        }
        self::assertTrue($foundPostmetaIndex, 'ALTER TABLE postmeta index must be executed');

        // Verify that usermeta index was requested
        $foundUsermetaIndex = false;
        foreach ($mockWpdb->queries as $q) {
            if (str_contains($q, 'ALTER TABLE wp_usermeta ADD INDEX user_id_meta_key')) {
                $foundUsermetaIndex = true;
                break;
            }
        }
        self::assertTrue($foundUsermetaIndex, 'ALTER TABLE usermeta index must be executed');

        // 4. Options Guard Autoload Thresholds
        self::assertEquals(800000, OptionsGuard::AUTOLOAD_BUDGET_BYTES, 'Autoload budget should be 800 KB');
        self::assertEquals(65536, OptionsGuard::GIANT_OPTION_THRESHOLD, 'Giant option threshold should be 64 KB');

        echo "  -> DatabaseTest completed.\n";
    }
}
