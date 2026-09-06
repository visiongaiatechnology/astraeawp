<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Database;

/**
 * AstraeaOS Database Migration Contract.
 *
 * Each schema migration must be atomic, idempotent, and reversible.
 *
 * @package Astraea\Database
 */
interface MigrationInterface {

    /**
     * Unique migration identifier (e.g. '001_initial_schema').
     */
    public function getVersion(): string;

    /**
     * Human-readable migration description.
     */
    public function getDescription(): string;

    /**
     * Apply schema changes.
     *
     * @param Connection $connection
     */
    public function up(Connection $connection): void;

    /**
     * Revert schema changes.
     *
     * @param Connection $connection
     */
    public function down(Connection $connection): void;
}
