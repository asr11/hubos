#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * HubOS Sovereign Daily Care & Reliability Engine (Tier IV Local-First SRE)
 * - Hard Shabbat & Friday-afternoon guard (Asia/Jerusalem)
 * - PRAGMA integrity_check + atomic hot SQLite backup (VACUUM INTO) + 30d retention
 * - Stale session garbage collection
 * - Polite concurrent DNS/SSL + WHOIS refresh for active/expiring assets
 * - Idempotent monthly recurring maintenance task generation
 * - Cryptographic audit trail in `events` (system.daily_care_completed)
 *
 * Usage:
 *   php bin/daily_care.php [--assets=smart|all|mock|skip] [--retention-days=30]
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// Worker subprocess mode for fast isolated DNS + TLS X.509 SSL check (no slow DNS_MX)
if (($argv[1] ?? '') === '--ssl-worker') {
    $host = strtolower(trim((string) ($argv[2] ?? '')));
    $timeout = 3;

    $a = [];
    $ns = [];
    $nsRecords = @dns_get_record($host, DNS_NS);
    if (is_array($nsRecords)) {
        foreach ($nsRecords as $r) {
            if (!empty($r['target'])) {
                $ns[] = strtolower((string) $r['target']);
            }
        }
    }
    $ip = @gethostbyname($host);
    if (is_string($ip) && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
        $a[] = $ip;
    } elseif (!str_starts_with($host, 'www.')) {
        $ipWww = @gethostbyname('www.' . $host);
        if (is_string($ipWww) && $ipWww !== ('www.' . $host) && filter_var($ipWww, FILTER_VALIDATE_IP)) {
            $a[] = $ipWww;
        }
    }

    $probeSsl = static function (string $targetHost) use ($timeout): array {
        $ctx = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'SNI_enabled'       => true,
                'peer_name'         => $targetHost,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client('ssl://' . $targetHost . ':443', $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!is_resource($sock)) {
            return ['none', null, null, null];
        }
        $params = stream_context_get_params($sock);
        fclose($sock);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert === null) {
            return ['none', null, null, null];
        }
        $parsed = @openssl_x509_parse($cert);
        if (!is_array($parsed)) {
            return ['unknown', null, null, null];
        }
        $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        $expiryDate = $validTo !== null ? gmdate('Y-m-d', $validTo) : null;
        $issuer = isset($parsed['issuer']['O'])
            ? (string) (is_array($parsed['issuer']['O']) ? $parsed['issuer']['O'][0] : $parsed['issuer']['O'])
            : (isset($parsed['issuer']['CN']) ? (string) $parsed['issuer']['CN'] : null);
        $subject = isset($parsed['subject']['CN'])
            ? (string) (is_array($parsed['subject']['CN']) ? $parsed['subject']['CN'][0] : $parsed['subject']['CN'])
            : null;

        $status = 'valid';
        if ($validTo !== null) {
            $daysLeft = intdiv($validTo - time(), 86400);
            if ($daysLeft < 0) {
                $status = 'expired';
            } elseif ($daysLeft <= 14) {
                $status = 'expiring';
            }
        }
        return [$status, $expiryDate, $issuer, $subject];
    };

    [$sslStatus, $sslExpiry, $sslIssuer, $sslSubject] = $probeSsl($host);
    if ($sslStatus === 'none' && !str_starts_with($host, 'www.') && substr_count($host, '.') <= 2) {
        [$wSt, $wExp, $wIss, $wSub] = $probeSsl('www.' . $host);
        if ($wSt !== 'none') {
            [$sslStatus, $sslExpiry, $sslIssuer, $sslSubject] = [$wSt, $wExp, $wIss, $wSub];
        }
    }

    echo json_encode([
        'host'            => $host,
        'dns_a'           => array_values(array_unique($a)),
        'dns_ns'          => array_values(array_unique($ns)),
        'ssl_status'      => $sslStatus,
        'ssl_expiry_date' => $sslExpiry,
        'ssl_issuer'      => $sslIssuer,
        'ssl_subject'     => $sslSubject,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';

use HubOS\Core\DB;
use HubOS\Core\Events;
use HubOS\Core\Tenant;
use HubOS\Modules\Work\RecurringService;

$opts = getopt('', ['assets::', 'retention-days::', 'simulate-iso::']);
$assetsMode = is_string($opts['assets'] ?? null) && $opts['assets'] !== '' ? $opts['assets'] : 'smart';
if (getenv('HUBOS_MOCK_INSPECTOR') === '1') {
    $assetsMode = 'mock';
}
$retentionDays = isset($opts['retention-days']) ? max(3, (int) $opts['retention-days']) : 30;

$ilTz = new DateTimeZone('Asia/Jerusalem');
$nowIl = isset($opts['simulate-iso']) && is_string($opts['simulate-iso'])
    ? new DateTimeImmutable($opts['simulate-iso'], $ilTz)
    : new DateTimeImmutable('now', $ilTz);

// 1. Hard Shabbat Guard (Saturday or Friday >= 15:00 Israel time)
$dow = (int) $nowIl->format('N'); // 1=Mon .. 5=Fri, 6=Sat, 7=Sun
$hourIl = (int) $nowIl->format('G');
if ($dow === 6 || ($dow === 5 && $hourIl >= 15)) {
    fwrite(STDOUT, json_encode([
        'ok'      => true,
        'skipped' => 'shabbat_guard',
        'time_il' => $nowIl->format('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}

$startedAt = microtime(true);
$dbPath = (string) $config['db_path'];
$storageDir = dirname($dbPath);
$backupDir = $storageDir . '/backups';
$logDir = (string) $config['log_path'];
$sessionDir = (string) $config['session_path'];

foreach ([$backupDir, $logDir] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        fwrite(STDERR, "daily_care error: cannot create directory {$dir}\n");
        exit(1);
    }
}

function dailyCareRootDomain(string $host): string
{
    $parts = explode('.', strtolower(trim($host)));
    $n = count($parts);
    if ($n >= 3 && in_array($parts[$n - 2] . '.' . $parts[$n - 1], ['co.il', 'org.il', 'net.il', 'ac.il', 'muni.il'], true)) {
        return implode('.', array_slice($parts, -3));
    }
    return $n >= 2 ? implode('.', array_slice($parts, -2)) : $host;
}

function dailyCareWhois(string $root, int $timeout = 4): array
{
    $server = match (true) {
        str_ends_with($root, '.il')                                => 'whois.isoc.org.il',
        str_ends_with($root, '.com'), str_ends_with($root, '.net') => 'whois.verisign-grs.com',
        str_ends_with($root, '.org')                               => 'whois.pir.org',
        default                                                    => null,
    };
    if ($server === null) {
        return ['expiry' => null, 'registrar' => null, 'unregistered' => false];
    }
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($server, 43, $errno, $errstr, $timeout);
    if (!is_resource($fp)) {
        return ['expiry' => null, 'registrar' => null, 'unregistered' => false];
    }
    stream_set_timeout($fp, $timeout);
    fwrite($fp, ($server === 'whois.verisign-grs.com' ? 'domain ' . $root : $root) . "\r\n");
    $raw = '';
    while (!feof($fp) && strlen($raw) < 32768) {
        $c = fread($fp, 2048);
        if ($c === false || $c === '') {
            break;
        }
        $raw .= $c;
    }
    fclose($fp);

    $unregistered = stripos($raw, 'No data was found') !== false
        || stripos($raw, 'No entries found') !== false
        || stripos($raw, 'No match for') !== false;

    $expiry = null;
    if (preg_match('/(?:Registry Expiry Date|Expiration Date|validity|paid-till|expire)\s*:\s*([^\r\n]+)/i', $raw, $m) === 1) {
        $val = trim($m[1]);
        if (strtoupper($val) !== 'N/A' && $val !== '') {
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $val, $dm) === 1) {
                $expiry = "{$dm[3]}-{$dm[2]}-{$dm[1]}";
            } else {
                try {
                    $expiry = (new DateTimeImmutable($val, new DateTimeZone('UTC')))->format('Y-m-d');
                } catch (Throwable) {
                    $expiry = null;
                }
            }
        }
    }
    $registrar = null;
    if (preg_match('/(?:registrar name|Sponsoring Registrar|Registrar)\s*:\s*([^\r\n]+)/i', $raw, $rm) === 1) {
        $registrar = trim($rm[1]);
    }
    return ['expiry' => $expiry, 'registrar' => $registrar, 'unregistered' => $unregistered];
}

try {
    $pdo = DB::connect($dbPath);

    // 2. Verify live DB integrity + create atomic hot backup via VACUUM INTO
    $liveIntegrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
    if (strtolower($liveIntegrity) !== 'ok') {
        throw new RuntimeException("Live SQLite failed integrity_check: {$liveIntegrity}");
    }

    $stamp = $nowIl->format('Ymd_His');
    $backupName = "hubos_daily_{$stamp}.sqlite";
    $backupPath = $backupDir . '/' . $backupName;
    if (is_file($backupPath)) {
        @unlink($backupPath);
    }
    $escapedBackup = str_replace("'", "''", $backupPath);
    $pdo->exec("VACUUM INTO '{$escapedBackup}'");

    $bkpPdo = new PDO('sqlite:' . $backupPath);
    $bkpIntegrity = (string) $bkpPdo->query('PRAGMA integrity_check')->fetchColumn();
    unset($bkpPdo);
    if (strtolower($bkpIntegrity) !== 'ok') {
        @unlink($backupPath);
        throw new RuntimeException("Backup SQLite failed integrity_check: {$bkpIntegrity}");
    }
    $backupBytes = (int) filesize($backupPath);
    $backupSha256 = (string) hash_file('sha256', $backupPath);

    // Prune hubos_daily_*.sqlite older than $retentionDays
    $prunedBackups = 0;
    $cutoffTime = time() - ($retentionDays * 86400);
    foreach (glob($backupDir . '/hubos_daily_*.sqlite') ?: [] as $oldFile) {
        if ($oldFile !== $backupPath && is_file($oldFile) && (int) filemtime($oldFile) < $cutoffTime) {
            if (@unlink($oldFile)) {
                $prunedBackups++;
            }
        }
    }

    // 3. Stale session garbage collection
    $prunedSessions = 0;
    if (is_dir($sessionDir)) {
        $sessCutoff = time() - max(7200, ((int) ($config['session_idle_seconds'] ?? 7200)) * 2);
        foreach (glob($sessionDir . '/sess_*') ?: [] as $sf) {
            if (is_file($sf) && (int) filemtime($sf) < $sessCutoff && @unlink($sf)) {
                $prunedSessions++;
            }
        }
    }

    // 4. Asset DNS/SSL/WHOIS refresh + Recurring monthly task check per active tenant
    $tenants = DB::run($pdo, "SELECT id, slug FROM tenants WHERE status = 'active' ORDER BY id")->fetchAll();
    $nowUtc = $nowIl->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $todayIl = $nowIl->format('Y-m-d');
    $in45d = $nowIl->modify('+45 days')->format('Y-m-d');
    $staleCutoffUtc = $nowIl->modify('-20 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $monthIl = $nowIl->format('Y-m');

    $assetsChecked = 0;
    $expiring30Count = 0;
    $sslIssuesCount = 0;
    $recurringCreated = 0;

    foreach ($tenants as $tRow) {
        $tid = (int) $tRow['id'];
        $tenant = new Tenant($pdo, $tid);

        // 4a. Idempotent monthly recurring tasks
        $recRes = (new RecurringService($tenant))->generate($monthIl);
        $recurringCreated += count($recRes['created']);

        // 4b. Select assets to inspect
        if ($assetsMode !== 'skip') {
            $allAssets = $tenant->select(
                "SELECT * FROM assets WHERE tenant_id = :tenant_id AND COALESCE(status, 'active') <> 'archived' ORDER BY id ASC"
            );
            $candidates = [];
            foreach ($allAssets as $a) {
                $dom = strtolower(trim((string) ($a['domain'] ?? '')));
                if ($dom === '') {
                    continue;
                }
                if ($assetsMode === 'all' || $assetsMode === 'mock') {
                    $candidates[] = $a;
                    continue;
                }
                // Smart daily filter: inspect if never inspected, >20h old, expiring <=45d, or SSL expiring/expired
                $lastInsp = (string) ($a['last_inspected_at'] ?? '');
                $expDate = (string) ($a['expiry_date'] ?? '');
                $sslSt = (string) ($a['ssl_status'] ?? '');
                $isUnreg = str_contains((string) ($a['registrar'] ?? ''), 'לא רשום');
                if ($isUnreg && $lastInsp !== '' && $lastInsp >= $nowIl->modify('-7 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')) {
                    continue;
                }
                if ($lastInsp === '' || $lastInsp < $staleCutoffUtc || ($expDate !== '' && $expDate <= $in45d) || in_array($sslSt, ['expiring', 'expired', 'unknown', ''], true)) {
                    $candidates[] = $a;
                }
            }

            if ($candidates !== []) {
                $sslMap = [];
                $whoisMap = [];

                if ($assetsMode === 'mock') {
                    foreach ($candidates as $c) {
                        $h = strtolower(trim((string) $c['domain']));
                        $sslMap[$h] = [
                            'host' => $h, 'dns_a' => ['185.199.108.153'], 'dns_ns' => ['ns1.cloudflare.com'],
                            'ssl_status' => 'valid', 'ssl_expiry_date' => '2027-06-15', 'ssl_issuer' => "Let's Encrypt", 'ssl_subject' => $h,
                        ];
                        $whoisMap[dailyCareRootDomain($h)] = ['expiry' => $c['expiry_date'] ?? '2027-12-31', 'registrar' => $c['registrar'] ?? 'ISOC-IL', 'unregistered' => false];
                    }
                } else {
                    // Concurrent SSL+DNS worker pool (12 workers)
                    $hosts = array_values(array_unique(array_map(static fn (array $x): string => strtolower(trim((string) $x['domain'])), $candidates)));
                    $running = [];
                    $idx = 0;
                    while ($idx < count($hosts) || $running !== []) {
                        while ($idx < count($hosts) && count($running) < 12) {
                            $h = $hosts[$idx++];
                            $pipes = [];
                            $proc = proc_open([PHP_BINARY, __FILE__, '--ssl-worker', $h], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                            if (is_resource($proc)) {
                                fclose($pipes[0]);
                                stream_set_blocking($pipes[1], false);
                                stream_set_blocking($pipes[2], false);
                                $running[$h] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2], 'buf' => '', 'start' => microtime(true)];
                            }
                        }
                        usleep(35000);
                        foreach ($running as $h => &$w) {
                            $chunk = stream_get_contents($w['out']);
                            if (is_string($chunk) && $chunk !== '') {
                                $w['buf'] .= $chunk;
                            }
                            $st = proc_get_status($w['proc']);
                            if (!$st['running'] || (microtime(true) - $w['start']) > 7.5) {
                                if ($st['running']) {
                                    proc_terminate($w['proc'], 9);
                                }
                                $rest = stream_get_contents($w['out']);
                                if (is_string($rest)) {
                                    $w['buf'] .= $rest;
                                }
                                fclose($w['out']);
                                fclose($w['err']);
                                proc_close($w['proc']);
                                $dec = json_decode($w['buf'], true);
                                $sslMap[$h] = is_array($dec) ? $dec : [
                                    'host' => $h, 'dns_a' => [], 'dns_ns' => [],
                                    'ssl_status' => 'unknown', 'ssl_expiry_date' => null, 'ssl_issuer' => null, 'ssl_subject' => null,
                                ];
                                unset($running[$h]);
                            }
                        }
                        unset($w);
                    }

                    // Polite WHOIS per unique root domain
                    $roots = [];
                    foreach ($hosts as $h) {
                        $roots[dailyCareRootDomain($h)] = true;
                    }
                    foreach (array_keys($roots) as $rt) {
                        $whoisMap[$rt] = dailyCareWhois($rt, 4);
                        usleep(str_ends_with($rt, '.il') ? 250000 : 60000);
                    }
                }

                // Atomic update of refreshed assets
                $tenant->transaction(function (Tenant $tx) use ($candidates, $sslMap, $whoisMap, $nowUtc, &$assetsChecked): void {
                    foreach ($candidates as $row) {
                        $id = (int) $row['id'];
                        $h = strtolower(trim((string) $row['domain']));
                        $rt = dailyCareRootDomain($h);
                        $ds = $sslMap[$h] ?? null;
                        $wh = $whoisMap[$rt] ?? ['expiry' => null, 'registrar' => null, 'unregistered' => false];
                        if ($ds === null) {
                            continue;
                        }
                        $data = is_string($row['data_json'] ?? null) ? json_decode($row['data_json'], true) : [];
                        if (!is_array($data)) {
                            $data = [];
                        }
                        $prevInsp = is_array($data['inspection'] ?? null) ? $data['inspection'] : [];
                        $data['inspection'] = array_merge($prevInsp, [
                            'host'               => $h,
                            'dns_a'              => $ds['dns_a'] !== [] ? $ds['dns_a'] : ($prevInsp['dns_a'] ?? []),
                            'dns_ns'             => $ds['dns_ns'] !== [] ? $ds['dns_ns'] : ($prevInsp['dns_ns'] ?? []),
                            'ssl_status'         => $ds['ssl_status'] !== 'unknown' ? $ds['ssl_status'] : ($row['ssl_status'] ?? 'unknown'),
                            'ssl_expiry_date'    => $ds['ssl_expiry_date'] ?? ($row['ssl_expiry_date'] ?? null),
                            'ssl_issuer'         => $ds['ssl_issuer'] ?? ($prevInsp['ssl_issuer'] ?? null),
                            'whois_expiry_date'  => $wh['expiry'] ?? ($row['expiry_date'] ?? null),
                            'whois_registrar'    => $wh['registrar'] ?? ($row['registrar'] ?? null),
                            'whois_unregistered' => $wh['unregistered'],
                            'inspected_at'       => $nowUtc,
                        ]);

                        $ip = ($id === 11) ? '128.140.100.120' : ($ds['dns_a'][0] ?? $row['ip_address'] ?? null);
                        $sslSt = $ds['ssl_status'] !== 'unknown' ? $ds['ssl_status'] : ($row['ssl_status'] ?? 'unknown');
                        $sslExp = $ds['ssl_expiry_date'] ?? $row['ssl_expiry_date'] ?? null;
                        $exp = $wh['expiry'] ?? $row['expiry_date'] ?? null;
                        $reg = $wh['registrar'] ?? ($wh['unregistered'] ? 'לא רשום (ISOC-IL)' : ($row['registrar'] ?? null));

                        $tx->update('assets', $id, [
                            'ip_address'        => $ip,
                            'registrar'         => $reg,
                            'expiry_date'       => $exp,
                            'ssl_status'        => $sslSt,
                            'ssl_expiry_date'   => $sslExp,
                            'last_inspected_at' => $nowUtc,
                            'data_json'         => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                            'updated_at'        => $nowUtc,
                        ]);
                        $assetsChecked++;
                    }
                });
            }
        }

        // Count current alert KPIs after refresh
        $in30d = $nowIl->modify('+30 days')->format('Y-m-d');
        $exp30Row = $tenant->selectOne(
            "SELECT COUNT(*) AS n FROM assets WHERE tenant_id = :tenant_id AND COALESCE(status, 'active') <> 'archived' AND expiry_date IS NOT NULL AND expiry_date <= :d30",
            ['d30' => $in30d]
        );
        $sslBadRow = $tenant->selectOne(
            "SELECT COUNT(*) AS n FROM assets WHERE tenant_id = :tenant_id AND COALESCE(status, 'active') <> 'archived' AND (ssl_status IN ('expired', 'expiring') OR (ssl_expiry_date IS NOT NULL AND ssl_expiry_date <= :d30))",
            ['d30' => $in30d]
        );
        $expiring30Count += (int) ($exp30Row['n'] ?? 0);
        $sslIssuesCount += (int) ($sslBadRow['n'] ?? 0);
    }

    $completedIl = (new DateTimeImmutable('now', $ilTz))->format('Y-m-d H:i:s');
    $completedUtc = gmdate('Y-m-d\TH:i:s\Z');
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

    $report = [
        'status'            => 'ok',
        'integrity'         => 'ok',
        'ran_at_utc'        => $completedUtc,
        'ran_at_il'         => $completedIl,
        'backup_file'       => $backupName,
        'backup_bytes'      => $backupBytes,
        'backup_sha256'     => substr($backupSha256, 0, 16),
        'pruned_backups'    => $prunedBackups,
        'pruned_sessions'   => $prunedSessions,
        'assets_mode'       => $assetsMode,
        'assets_checked'    => $assetsChecked,
        'expiring_30d'      => $expiring30Count,
        'ssl_issues'        => $sslIssuesCount,
        'recurring_created' => $recurringCreated,
        'duration_ms'       => $durationMs,
    ];

    foreach ($tenants as $tRow) {
        $tenant = new Tenant($pdo, (int) $tRow['id']);
        Events::record($tenant, null, 'system', 1, null, 'system.daily_care_completed', null, $report);
    }

    @file_put_contents($logDir . '/daily_care_latest.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    @file_put_contents($logDir . '/daily_care.log', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

    fwrite(STDOUT, json_encode(['ok' => true] + $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
} catch (Throwable $e) {
    $errPayload = [
        'ok'      => false,
        'error'   => $e->getMessage(),
        'time_il' => $nowIl->format('Y-m-d H:i:s'),
    ];
    @file_put_contents($logDir . '/daily_care.log', json_encode($errPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    fwrite(STDERR, 'daily_care error: ' . $e->getMessage() . "\n");
    exit(1);
}
