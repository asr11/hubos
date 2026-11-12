<?php
declare(strict_types=1);

$c = require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!str_starts_with((string) $c['db_path'], '/tmp/hubos_sim_')) {
    fwrite(STDERR, "REFUSING: db_path is not a sim copy\n");
    exit(1);
}
$pdo = \HubOS\Core\DB::connect($c['db_path']);
$tid = 1;

$mk = static function (string $role, string $key, ?int $client = null) use ($pdo, $tid): array {
    $email = "sim_{$key}@example.com";
    $pw = bin2hex(random_bytes(12));
    $id = \HubOS\Core\Provisioning::createUser($pdo, $tid, $email, 'SIM ' . $key, $role, $pw, null, $client);
    return ['id' => $id, 'email' => $email, 'pass' => $pw];
};

$q = static fn (string $sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$t286 = $q('SELECT id, client_visible FROM tasks WHERE client_id = 286 ORDER BY id');
$internal286 = null;
$visible286 = null;
foreach ($t286 as $t) {
    if ((int) $t['client_visible'] === 0 && $internal286 === null) {
        $internal286 = (int) $t['id'];
    }
    if ((int) $t['client_visible'] === 1 && $visible286 === null) {
        $visible286 = (int) $t['id'];
    }
}
$anyTasks = array_map('intval', array_column($q('SELECT id FROM tasks ORDER BY id DESC LIMIT 4'), 'id'));
$asset286 = $q('SELECT id FROM assets WHERE client_id = 286 ORDER BY id LIMIT 1')[0]['id'] ?? null;
$names = [];
foreach ($q('SELECT id, name FROM clients WHERE id IN (286, 304)') as $r) {
    $names[(int) $r['id']] = (string) $r['name'];
}

echo json_encode([
    'users' => [
        'owner'    => $mk('owner', 'owner'),
        'admin'    => $mk('admin', 'admin'),
        'staff1'   => $mk('staff', 'staff1'),
        'staff2'   => $mk('staff', 'staff2'),
        'agent'    => $mk('agent', 'agent'),
        'viewer'   => $mk('client_viewer', 'viewer286', 286),
        'attacker' => $mk('client_viewer', 'attacker304', 304),
    ],
    'tasks'        => $anyTasks,
    'task286'      => $visible286 ?? ($t286[0]['id'] ?? null),
    'internal286'  => $internal286,
    'asset286'     => $asset286 !== null ? (int) $asset286 : null,
    'name286'      => $names[286] ?? '',
    'name304'      => $names[304] ?? '',
    'baseline'     => [
        'time_entries' => (int) $pdo->query('SELECT COUNT(*) FROM time_entries')->fetchColumn(),
        'events'       => (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
    ],
], JSON_UNESCAPED_UNICODE);
