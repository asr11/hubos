#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Import a Cloudflare D1 SQL dump (old OS HUB) into HubOS. LOCAL ONLY.
 *
 * Usage: php bin/import_d1.php <dump.sql> [--db=path] [--report=path]
 *   --db      target SQLite (default: HUBOS_DB_PATH or storage/hubos.sqlite)
 *   --report  reconciliation report (default: storage/import_report.md)
 *
 * Vault secrets are stored only if HUBOS_VAULT_KEY is set (AES-256-GCM);
 * otherwise metadata only. Secrets are never printed.
 * Idempotent: re-running with the same dump yields the same data.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';

use HubOS\Core\DB;
use HubOS\Core\Migrator;
use HubOS\Core\Vault;
use HubOS\Modules\Import\D1Importer;

$dump = null;
$db = $config['db_path'];
$report = HUBOS_ROOT . '/storage/import_report.md';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $db = substr($arg, 5);
    } elseif (str_starts_with($arg, '--report=')) {
        $report = substr($arg, 9);
    } elseif ($dump === null && !str_starts_with($arg, '--')) {
        $dump = $arg;
    } else {
        fwrite(STDERR, "Unknown argument\n");
        exit(2);
    }
}
if ($dump === null) {
    fwrite(STDERR, "Usage: php bin/import_d1.php <dump.sql> [--db=path] [--report=path]\n");
    exit(2);
}

try {
    $pdo = DB::connect($db);
    (new Migrator($pdo, HUBOS_ROOT . '/database/migrations'))->run();
    $vault = Vault::fromEnv();
    $summary = (new D1Importer($pdo, $vault))->run($dump, $report);

    fwrite(STDOUT, 'DB: ' . $db . "\nTenant id: " . $summary['tenant_id'] . "\n");
    fwrite(STDOUT, sprintf("%-20s %8s %8s %8s %8s\n", 'entity', 'source', 'imported', 'merged', 'skipped'));
    foreach ($summary['stats'] as $entity => $st) {
        fwrite(STDOUT, sprintf("%-20s %8d %8d %8d %8d\n", $entity, $st['source'], $st['imported'], $st['merged'], count($st['skipped']) - $st['merged']));
    }
    fwrite(STDOUT, "DB counts: " . json_encode($summary['counts']) . "\n");
    fwrite(STDOUT, "Income by year (rule): " . json_encode($summary['finance']['income_by_year']) . "\n");
    fwrite(STDOUT, 'Vault: ' . ($vault === null ? 'no HUBOS_VAULT_KEY -> metadata only, secrets not stored' : 'secrets encrypted (aes-256-gcm)') . "\n");
    fwrite(STDOUT, 'Report: ' . $report . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Import error: ' . $e->getMessage() . "\n");
    exit(1);
}
