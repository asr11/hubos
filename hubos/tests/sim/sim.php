<?php
declare(strict_types=1);
/*
 * HubOS multi-persona concurrent simulation (runs ONLY against a throwaway local copy).
 * argv: setup.json  base_url  agent_token
 */
$S = json_decode((string) file_get_contents($argv[1]), true);
$BASE = $argv[2];
$TOKEN = $argv[3];
$today = gmdate('Y-m-d');

final class P
{
    public string $sid = '';
    public string $csrf = '';
    public function __construct(public string $name, public string $email = '', public string $pass = '')
    {
    }
}

function mk(P $p, string $method, string $path, mixed $data = null, array $hdr = [], bool $cookie = true)
{
    global $BASE;
    $h = curl_init($BASE . $path);
    if ($cookie && $p->sid !== '') {
        $hdr[] = 'Cookie: hubos_sid=' . $p->sid;
    }
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $hdr,
        CURLOPT_PATH_AS_IS     => true,
    ]);
    if ($data !== null) {
        curl_setopt($h, CURLOPT_POSTFIELDS, is_string($data) ? $data : http_build_query($data));
    }
    return $h;
}

/** @param list<array<string,mixed>> $jobs */
function runAll(array $jobs): array
{
    $mh = curl_multi_init();
    foreach ($jobs as $j) {
        curl_multi_add_handle($mh, $j['h']);
    }
    do {
        $st = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $st === CURLM_OK);
    $out = [];
    foreach ($jobs as $j) {
        $raw = (string) curl_multi_getcontent($j['h']);
        $info = curl_getinfo($j['h']);
        $hs = (int) $info['header_size'];
        $head = substr($raw, 0, $hs);
        $body = substr($raw, $hs);
        if (preg_match_all('/^Set-Cookie:\s*hubos_sid=([^;\r\n]*)/mi', $head, $m)) {
            $v = end($m[1]);
            if ($v !== '' && $v !== 'deleted' && !empty($j['p']) && !empty($j['cookie'])) {
                $j['p']->sid = $v;
            }
        }
        if (!empty($j['p']) && preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $body, $m)) {
            $j['p']->csrf = $m[1];
        }
        $loc = preg_match('/^Location:\s*(\S+)/mi', $head, $lm) ? $lm[1] : '';
        unset($j['h']);
        $out[] = $j + ['status' => (int) $info['http_code'], 'ms' => round($info['total_time'] * 1000, 1), 'body' => $body, 'loc' => $loc];
    }
    foreach ($jobs as $j) {
        curl_multi_remove_handle($mh, $j['h']);
        curl_close($j['h']);
    }
    curl_multi_close($mh);
    return $out;
}

$U = $S['users'];
$P = [];
foreach ($U as $k => $u) {
    $P[$k] = new P($k, $u['email'], $u['pass']);
}

$log = [];
$record = static function (array $results, string $phase) use (&$log): void {
    foreach ($results as $r) {
        $leaks = [];
        if ($r['status'] === 200) {
            foreach ($r['forbid'] ?? [] as $f) {
                if ($f !== '' && str_contains($r['body'], $f)) {
                    $leaks[] = $f;
                }
            }
        }
        if (!empty($r['notPrefix']) && str_starts_with($r['body'], $r['notPrefix'])) {
            $leaks[] = 'BODY_STARTS_WITH:' . $r['notPrefix'];
        }
        $dbLocked = stripos($r['body'], 'database is locked') !== false;
        $log[] = [
            'phase'   => $phase,
            'persona' => $r['who'],
            'm'       => $r['m'],
            'path'    => $r['path'],
            'expect'  => $r['expect'],
            'status'  => $r['status'],
            'ok'      => in_array($r['status'], $r['expect'], true) && $leaks === [] && !$dbLocked,
            'ms'      => $r['ms'],
            'leaks'   => $leaks,
            'locked'  => $dbLocked,
            'loc'     => $r['loc'],
        ];
    }
};

$job = static function (string $who, P $p, string $m, string $path, mixed $data, array $expect, array $forbid = [], array $flags = []) use ($TOKEN): array {
    $hdr = [];
    $cookie = true;
    if (!empty($flags['json'])) {
        $hdr[] = 'Accept: application/json';
        $hdr[] = 'X-Requested-With: XMLHttpRequest';
    }
    if (!empty($flags['bearer'])) {
        $hdr[] = 'Authorization: Bearer ' . $TOKEN;
        $cookie = false;
    }
    if (!empty($flags['nocookie'])) {
        $cookie = false;
    }
    if (!empty($flags['jsonbody'])) {
        $hdr[] = 'Content-Type: application/json';
    }
    if (is_array($data) && !empty($flags['csrf'])) {
        $data['_csrf'] = $p->csrf;
    }
    if (in_array(302, $expect, true) && !in_array(303, $expect, true)) {
        $expect[] = 303;
    }
    return [
        'who' => $who, 'p' => $p, 'm' => $m, 'path' => $path, 'expect' => $expect,
        'forbid' => $forbid, 'notPrefix' => $flags['notPrefix'] ?? null, 'cookie' => $cookie,
        'h' => mk($p, $m, $path, $data, $hdr, $cookie),
    ];
};

// ---------------- Phase 1: concurrent login (7 personas)
$t0 = microtime(true);
$jobs = [];
foreach ($P as $k => $p) {
    $jobs[] = $job($k, $p, 'GET', '/login', null, [200]);
}
$record(runAll($jobs), 'login_form');
$jobs = [];
foreach ($P as $k => $p) {
    $jobs[] = $job($k, $p, 'POST', '/login', ['email' => $p->email, 'password' => $p->pass], [302], [], ['csrf' => true]);
}
$loginRes = runAll($jobs);
$record($loginRes, 'login');
$jobs = [];
foreach ($P as $k => $p) {
    $jobs[] = $job($k, $p, 'GET', '/dashboard', null, [200]);
}
$record(runAll($jobs), 'post_login_dashboard');

// ---------------- Phase 2: role scenarios (concurrent rounds)
$T = $S['tasks'];
$t286 = $S['task286'];
$i286 = $S['internal286'];
$a286 = $S['asset286'];
$n286 = $S['name286'];
$n304 = $S['name304'];
$secret = ['secret_ciphertext', 'password_hash'];
$te = static fn (string $who, int $i) => ['client_id' => '286', 'description' => "SIM_{$who}_{$i}", 'duration' => '0:05', 'entry_date' => $today];

$scripts = [
    'owner' => [
        ['GET', '/dashboard', null, [200], $secret],
        ['GET', '/clients', null, [200], $secret],
        ['GET', '/clients?q=' . rawurlencode('אשטרום'), null, [200], $secret],
        ['GET', '/clients/286', null, [200], $secret],
        ['GET', '/clients/286?tab=finance', null, [200], $secret],
        ['GET', '/clients/304/report?scope=internal', null, [200], $secret],
        ['GET', '/assets', null, [200], $secret],
        ['GET', '/vault', null, [200], $secret],
        ['GET', '/settings/users', null, [200], $secret],
        ['GET', '/work/linking', null, [200], $secret],
        ['GET', '/time', null, [200], $secret],
        ['POST', "/tasks/{$T[0]}/status", ['status' => 'doing'], [200], [], ['csrf' => true, 'json' => true]],
    ],
    'admin' => [
        ['GET', '/dashboard', null, [200], $secret],
        ['GET', '/clients/286', null, [200], $secret],
        ['GET', '/settings/users', null, [200], $secret],
        ['GET', '/work/linking', null, [200], $secret],
        ['GET', '/vault', null, [200], $secret],
        ['GET', '/tasks', null, [200], $secret],
        ['GET', '/projects', null, [200], $secret],
        ['POST', "/tasks/{$T[1]}/status", ['status' => 'done'], [200], [], ['csrf' => true, 'json' => true]],
    ],
    'staff1' => [
        ['GET', '/tasks', null, [200], $secret],
        ['GET', '/clients/286', null, [200], $secret],
        ['GET', '/time', null, [200], $secret],
        ['GET', '/assets', null, [200], $secret],
        ['GET', '/settings/users', null, [403]],
        ['GET', '/work/linking', null, [403]],
        ['POST', "/tasks/{$T[2]}/status", ['status' => 'doing'], [200], [], ['csrf' => true, 'json' => true]],
        ['POST', '/time/entries', 'TE', [302]],
    ],
    'staff2' => [
        ['GET', '/tasks', null, [200], $secret],
        ['GET', '/clients/304', null, [200], $secret],
        ['GET', '/time', null, [200], $secret],
        ['GET', '/settings/users', null, [403]],
        ['POST', "/tasks/{$T[3]}/status", ['status' => 'done'], [200], [], ['csrf' => true, 'json' => true]],
        ['POST', '/time/entries', 'TE', [302]],
    ],
    'agent' => [
        ['GET', '/dashboard', null, [200], $secret],
        ['GET', '/clients/286', null, [200], $secret],
        ['GET', '/settings/users', null, [403]],
        ['GET', '/api/agent/status', null, [200], $secret, ['bearer' => true]],
        ['GET', '/api/agent/morning', null, [200], array_merge($secret, ['data_json']), ['bearer' => true]],
        ['GET', '/api/agent/clients?q=' . rawurlencode('אשטרום'), null, [200], $secret, ['bearer' => true]],
        ['GET', '/api/agent/clients/286', null, [200], array_merge($secret, ['data_json']), ['bearer' => true]],
        ['POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', [200], [], ['bearer' => true, 'jsonbody' => true]],
    ],
    'viewer' => array_values(array_filter([
        ['GET', '/dashboard', null, [200], array_merge($secret, [$n304])],
        ['GET', '/clients/286', null, [200], array_merge($secret, [$n304])],
        ['GET', '/clients/286/report', null, [200], array_merge($secret, [$n304])],
        ['GET', '/clients/286/report?scope=internal', null, [200], array_merge($secret, [$n304, 'id="report-scope"'])],
        ['GET', '/clients', null, [302]],
        ['GET', '/clients/304', null, [403, 404]],
        ['GET', '/vault', null, [403]],
        ['GET', '/settings/users', null, [403]],
        ['GET', '/assets', null, [200], array_merge($secret, [$n304])],
        ['GET', '/work/linking', null, [403]],
        $t286 ? ['POST', "/tasks/{$t286}/status", ['status' => 'done'], [403], [], ['csrf' => true, 'json' => true]] : null,
        ['GET', '/api/agent/morning', null, [401]],
    ])),
    'attacker' => array_values(array_filter([
        ['GET', '/clients/286', null, [403, 404], [$n286]],
        ['GET', '/clients/286/report?scope=internal', null, [403, 404], [$n286]],
        $i286 ? ['GET', "/tasks/{$i286}", null, [403, 404], [$n286]] : null,
        $t286 ? ['GET', "/tasks/{$t286}", null, [403, 404], [$n286]] : null,
        $a286 ? ['GET', "/assets/{$a286}", null, [403, 404], [$n286]] : null,
        ['GET', '/vault', null, [403]],
        ['GET', '/settings/users', null, [403]],
        ['POST', '/settings/users', ['name' => 'evil', 'email' => 'evil@example.com', 'role' => 'owner', 'password' => 'EvilPassword123456!'], [403], [], ['csrf' => true]],
        $t286 ? ['POST', "/tasks/{$t286}/status", ['status' => 'done'], [403], [], ['json' => true]] : null,
        ['GET', '/api/agent/morning', null, [401], [], ['nocookie' => true]],
        ['GET', '/api/agent/morning?token=wrongwrongwrongwrongwrong', null, [401], [], ['nocookie' => true]],
        ['GET', '/clients?q=' . rawurlencode("' OR 1=1--"), null, [302]],
        ['GET', '/storage/hubos.sqlite', null, [404], [], ['notPrefix' => 'SQLite format']],
        ['GET', '/../storage/hubos.sqlite', null, [404, 400], [], ['notPrefix' => 'SQLite format']],
        ['GET', '/clients/286/report?period=' . rawurlencode('../../etc/passwd'), null, [403, 404], [$n286]],
    ])),
];

$ROUNDS = 24;
for ($r = 0; $r < $ROUNDS; $r++) {
    $jobs = [];
    foreach ($scripts as $who => $list) {
        $s = $list[$r % count($list)];
        $data = $s[2] === 'TE' ? $te($who, $r) : $s[2];
        $flags = $s[5] ?? [];
        if ($s[2] === 'TE') {
            $flags['csrf'] = true;
        }
        $jobs[] = $job($who, $P[$who], $s[0], $s[1], $data, $s[3], $s[4] ?? [], $flags);
    }
    $record(runAll($jobs), 'scenarios');
}

// ---------------- Phase 3: brute-force login throttle (6 wrong passwords on sim owner)
$brute = new P('brute');
$record(runAll([$job('brute', $brute, 'GET', '/login', null, [200])]), 'brute');
for ($i = 1; $i <= 6; $i++) {
    $record(runAll([$job('brute', $brute, 'POST', '/login', ['email' => $U['owner']['email'], 'password' => 'wrong-' . $i], $i <= 5 ? [401] : [429], [], ['csrf' => true])]), 'brute');
}

// ---------------- Phase 4: write burst (60 concurrent writes, contention on one task)
$writers = ['owner', 'admin', 'staff1', 'staff2'];
$jobs = [];
$teSent = 0;
foreach ($writers as $w) {
    for ($i = 0; $i < 15; $i++) {
        if ($i % 2 === 0) {
            $jobs[] = $job($w, $P[$w], 'POST', "/tasks/{$T[0]}/status", ['status' => ['todo', 'doing', 'done'][$i % 3]], [200], [], ['csrf' => true, 'json' => true]);
        } else {
            $teSent++;
            $jobs[] = $job($w, $P[$w], 'POST', '/time/entries', $te('BURST_' . $w, $i), [302], [], ['csrf' => true]);
        }
    }
}
$tb = microtime(true);
$burst = runAll($jobs);
$burstWall = round((microtime(true) - $tb) * 1000);
$record($burst, 'write_burst');

// ---------------- Phase 5: read burst (100 concurrent dashboard/client loads)
$jobs = [];
foreach (['owner', 'admin', 'staff1', 'staff2'] as $w) {
    for ($i = 0; $i < 25; $i++) {
        $jobs[] = $job($w, $P[$w], 'GET', $i % 2 ? '/dashboard' : '/clients/286', null, [200], $secret);
    }
}
$tr = microtime(true);
$record(runAll($jobs), 'read_burst');
$readWall = round((microtime(true) - $tr) * 1000);

// ---------------- Summary
$pct = static function (array $a, float $p): float {
    if ($a === []) {
        return 0.0;
    }
    sort($a);
    return (float) $a[(int) floor(($p / 100) * (count($a) - 1))];
};
$byPersona = [];
$byPhase = [];
foreach ($log as $e) {
    $byPersona[$e['persona']]['n'] = ($byPersona[$e['persona']]['n'] ?? 0) + 1;
    $byPersona[$e['persona']]['fail'] = ($byPersona[$e['persona']]['fail'] ?? 0) + ($e['ok'] ? 0 : 1);
    $byPersona[$e['persona']]['ms'][] = $e['ms'];
    $byPhase[$e['phase']]['n'] = ($byPhase[$e['phase']]['n'] ?? 0) + 1;
    $byPhase[$e['phase']]['fail'] = ($byPhase[$e['phase']]['fail'] ?? 0) + ($e['ok'] ? 0 : 1);
    $byPhase[$e['phase']]['ms'][] = $e['ms'];
}
foreach ([&$byPersona, &$byPhase] as &$grp) {
    foreach ($grp as &$g) {
        $g['p50'] = $pct($g['ms'], 50);
        $g['p95'] = $pct($g['ms'], 95);
        $g['max'] = max($g['ms']);
        unset($g['ms']);
    }
    unset($g);
}
unset($grp);

$fails = [];
foreach ($log as $e) {
    if (!$e['ok']) {
        $key = $e['persona'] . ' ' . $e['m'] . ' ' . $e['path'] . ' => ' . $e['status'] . ' (expect ' . implode('/', $e['expect']) . ')' . ($e['leaks'] ? ' LEAK:' . implode(',', $e['leaks']) : '') . ($e['locked'] ? ' DB_LOCKED' : '') . ($e['loc'] ? ' loc=' . $e['loc'] : '');
        $fails[$key] = ($fails[$key] ?? 0) + 1;
    }
}
$all = array_column($log, 'ms');
$teOk = count(array_filter($burst, static fn ($r) => $r['path'] === '/time/entries' && in_array($r['status'], [302, 303], true)));
$five = count(array_filter($log, static fn ($e) => $e['status'] >= 500 || $e['status'] === 0));
$leaks = count(array_filter($log, static fn ($e) => $e['leaks'] !== []));
$locked = count(array_filter($log, static fn ($e) => $e['locked']));
$totalFailed = count(array_filter($log, static fn ($e) => !$e['ok']));

echo json_encode([
    'total_requests'   => count($log),
    'total_failed'     => $totalFailed,
    'http_5xx_or_0'    => $five,
    'leaks'            => $leaks,
    'db_locked'        => $locked,
    'latency_ms'       => ['p50' => $pct($all, 50), 'p95' => $pct($all, 95), 'max' => max($all)],
    'by_persona'       => $byPersona,
    'by_phase'         => $byPhase,
    'write_burst'      => ['requests' => count($burst), 'wall_ms' => $burstWall, 'time_entries_sent' => $teSent, 'time_entries_ok' => $teOk],
    'read_burst_wall_ms' => $readWall,
    'failures'         => $fails,
    'wall_total_s'     => round(microtime(true) - $t0, 2),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
exit($totalFailed === 0 ? 0 : 1);
