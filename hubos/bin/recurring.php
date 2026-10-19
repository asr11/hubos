#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Create this month's recurring maintenance tasks from active templates.
 * Idempotent: running it again for the same month creates nothing new.
 *   php bin/recurring.php [--month=YYYY-MM] [--tenant=slug]
 * DB path from HUBOS_DB_PATH (default storage/hubos.sqlite). Suitable for cron (1st of month).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';

use HubOS\Core\DB;
use HubOS\Core\Tenant;
use HubOS\Modules\Work\RecurringService;

$opts = getopt('', ['month:', 'tenant:']);
$month = is_string($opts['month'] ?? null) ? $opts['month']
    : (new DateTimeImmutable('now', new DateTimeZone('Asia/Jerusalem')))->format('Y-m');
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
    fwrite(STDERR, "Invalid --month (YYYY-MM)\n");
    exit(2);
}

try {
    $pdo = DB::connect($config['db_path']);
    $sql = "SELECT id, slug FROM tenants WHERE status = 'active'" . (isset($opts['tenant']) ? ' AND slug = ?' : '') . ' ORDER BY id';
    $tenants = DB::run($pdo, $sql, isset($opts['tenant']) ? [(string) $opts['tenant']] : [])->fetchAll();
    $total = 0;
    foreach ($tenants as $t) {
        $r = (new RecurringService(new Tenant($pdo, (int) $t['id'])))->generate($month);
        $total += count($r['created']);
        fwrite(STDOUT, sprintf("tenant %s: month %s created=%d skipped(existing)=%d\n", $t['slug'], $month, count($r['created']), $r['skipped']));
    }
    fwrite(STDOUT, "done: {$total} task(s) created\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'recurring error: ' . $e->getMessage() . "\n");
    exit(1);
}
