#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Apply pending migrations to the configured SQLite DB.
 * Usage: php bin/migrate.php        (DB path from HUBOS_DB_PATH or storage/hubos.sqlite)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';

use HubOS\Core\DB;
use HubOS\Core\Migrator;

try {
    $pdo = DB::connect($config['db_path']);
    $applied = (new Migrator($pdo, HUBOS_ROOT . '/database/migrations'))->run();
    $mode = (string) $pdo->query('PRAGMA journal_mode')->fetchColumn();
    $fk = (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
    fwrite(STDOUT, 'DB: ' . $config['db_path'] . " (journal_mode={$mode}, foreign_keys={$fk})\n");
    fwrite(STDOUT, $applied === [] ? "Nothing to migrate.\n" : 'Applied: ' . implode(', ', $applied) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration error: ' . $e->getMessage() . "\n");
    exit(1);
}
