<?php

declare(strict_types=1);

/*
 * Zero-dependency test runner:  php tests/run.php
 * Each test gets helpers ok()/eq()/throws(). Temporary DBs are deleted at the end.
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

require dirname(__DIR__) . '/app/bootstrap.php';

use HubOS\Core\Auth;
use HubOS\Core\Csrf;
use HubOS\Core\DB;
use HubOS\Core\Events;
use HubOS\Core\HebrewText;
use HubOS\Core\Http\HttpClient;
use HubOS\Core\Http\HttpClientException;
use HubOS\Core\HttpException;
use HubOS\Core\Migrator;
use HubOS\Core\Permissions;
use HubOS\Core\Provisioning;
use HubOS\Core\Router;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Core\ValidationException;
use HubOS\Core\Vault;
use HubOS\Core\View;
use HubOS\Modules\AgentApi\AgentApiService;
use HubOS\Modules\Assets\AssetInspector;
use HubOS\Modules\Assets\AssetService;
use HubOS\Modules\Assets\NativeAssetInspector;
use HubOS\Modules\Clients\Client360Service;
use HubOS\Modules\Clients\ClientService;
use HubOS\Modules\Clients\CompanyRegistry;
use HubOS\Modules\Dashboard\DashboardService;
use HubOS\Modules\Import\AccountingRule;
use HubOS\Modules\Import\ClientResolver;
use HubOS\Modules\Import\D1Importer;
use HubOS\Modules\Time\HoursBankService;
use HubOS\Modules\Time\TimeEntryService;
use HubOS\Modules\Time\TimerService;
use HubOS\Modules\Users\UserService;
use HubOS\Modules\Vault\VaultService;
use HubOS\Modules\Work\LinkingService;
use HubOS\Modules\Work\ProjectService;
use HubOS\Modules\Work\RecurringService;
use HubOS\Modules\Work\TaskService;

final class T
{
    /** @var array<string, callable> */
    public static array $tests = [];
    /** @var list<string> */
    public static array $tmp = [];
    public static int $assertions = 0;
}

function test(string $name, callable $fn): void
{
    T::$tests[$name] = $fn;
}

function ok(bool $cond, string $msg = 'assertion failed'): void
{
    T::$assertions++;
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    T::$assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function throws(callable $fn, string $class = Throwable::class): Throwable
{
    T::$assertions++;
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new RuntimeException("expected {$class}, got " . $e::class . ': ' . $e->getMessage());
    }
    throw new RuntimeException("expected {$class} to be thrown");
}

function freshDb(): PDO
{
    $path = tempnam(sys_get_temp_dir(), 'hubos_test_');
    T::$tmp[] = $path;
    $pdo = DB::connect($path);
    (new Migrator($pdo, HUBOS_ROOT . '/database/migrations'))->run();
    return $pdo;
}

/** @return array<string, mixed> */
function userRow(PDO $pdo, int $id): array
{
    $row = DB::run($pdo, 'SELECT * FROM users WHERE id = ?', [$id])->fetch();
    unset($row['password_hash']);
    return $row;
}

/** Two tenants with an owner each, plus helpers. */
function world(): array
{
    $pdo = freshDb();
    $ta = Provisioning::ensureTenant($pdo, 'zz-a', 'ZZ_Tenant A');
    $tb = Provisioning::ensureTenant($pdo, 'zz-b', 'ZZ_Tenant B');
    $ownerA = userRow($pdo, Provisioning::createUser($pdo, $ta, 'zz_owner_a@hubos.test', 'ZZ_Owner A', 'owner', 'zz-password-123A'));
    $ownerB = userRow($pdo, Provisioning::createUser($pdo, $tb, 'zz_owner_b@hubos.test', 'ZZ_Owner B', 'owner', 'zz-password-123B'));
    return [$pdo, new Tenant($pdo, $ta), new Tenant($pdo, $tb), $ownerA, $ownerB];
}

// ---------------------------------------------------------------- Router

test('router: static, params, 404, 405, HEAD, trailing slash', function (): void {
    $r = new Router();
    $r->get('/dashboard', 'dash');
    $r->post('/login', 'doLogin');
    $r->get('/login', 'showLogin');
    $r->get('/clients/{id:\d+}', 'showClient');
    $r->get('/files/{name}', 'file');

    $m = $r->match('GET', '/dashboard');
    eq(200, $m['status']);
    eq('dash', $m['handler']);
    eq('showLogin', $r->match('GET', '/login')['handler']);
    eq('doLogin', $r->match('POST', '/login')['handler']);

    $m = $r->match('GET', '/clients/42');
    eq('showClient', $m['handler']);
    eq(['id' => '42'], $m['params']);
    eq(404, $r->match('GET', '/clients/abc')['status'], 'regex constraint');
    eq(['name' => 'a.txt'], $r->match('GET', '/files/a.txt')['params']);
    eq(404, $r->match('GET', '/files/a/b')['status'], 'default param excludes slash');

    $m = $r->match('POST', '/dashboard');
    eq(405, $m['status']);
    eq(['GET'], $m['allow']);
    eq(200, $r->match('HEAD', '/dashboard')['status']);
    eq(200, $r->match('GET', '/dashboard/')['status']);
    eq(404, $r->match('GET', '/nope')['status']);
    eq(404, $r->match('GET', '/dashboardX')['status'], 'anchored');
});

test('router: app route table protects routes with auth/perm', function (): void {
    $r = new Router();
    (require HUBOS_ROOT . '/app/routes.php')($r);
    eq(200, $r->match('GET', '/login')['status']);
    ok(($r->match('GET', '/dashboard')['opts']['auth'] ?? false) === true, '/dashboard requires auth');
    eq('clients.view', $r->match('GET', '/clients')['opts']['perm'] ?? null);
    eq('clients.create', $r->match('POST', '/clients')['opts']['perm'] ?? null);
    eq(405, $r->match('GET', '/logout')['status'], 'logout is POST-only');
});

// ---------------------------------------------------------------- CSRF

test('csrf: token generation, verification, rotation', function (): void {
    $_SESSION = [];
    ok(!Csrf::verify(null), 'no token in session -> reject');
    $t = Csrf::token();
    eq(1, preg_match('/^[0-9a-f]{64}$/', $t), 'token is 64 hex chars');
    eq($t, Csrf::token(), 'token stable within session');
    ok(Csrf::verify($t), 'valid token accepted');
    ok(!Csrf::verify(''), 'empty rejected');
    ok(!Csrf::verify(null), 'null rejected');
    ok(!Csrf::verify(['x']), 'array rejected');
    ok(!Csrf::verify(substr($t, 0, 63) . ($t[63] === 'a' ? 'b' : 'a')), 'tampered rejected');
    ok(str_contains(Csrf::field(), 'value="' . $t . '"'), 'hidden field contains token');

    $_POST = ['_csrf' => $t];
    eq($t, Csrf::fromRequest());
    $_POST = [];

    Csrf::rotate();
    $t2 = Csrf::token();
    ok($t2 !== $t, 'rotated token differs');
    ok(!Csrf::verify($t), 'old token rejected after rotation');
    $_SESSION = [];
});

// ---------------------------------------------------------------- Passwords

test('password: hash/verify (Argon2id when available)', function (): void {
    $h = Auth::hashPassword('correct horse battery');
    if (defined('PASSWORD_ARGON2ID')) {
        ok(str_starts_with($h, '$argon2id$'), 'uses argon2id');
    }
    ok(Auth::verifyPassword('correct horse battery', $h), 'correct verifies');
    ok(!Auth::verifyPassword('wrong horse battery', $h), 'wrong rejected');
    ok(!Auth::needsRehash($h), 'fresh hash needs no rehash');
    ok($h !== Auth::hashPassword('correct horse battery'), 'salted');
});

test('auth: credentials, disabled user, throttling', function (): void {
    [$pdo, $ta, , $ownerA] = world();
    $auth = new Auth($pdo, ['session_idle_seconds' => 7200]);
    $u = $auth->verifyCredentials('ZZ_OWNER_A@hubos.test', 'zz-password-123A');
    ok($u !== null, 'valid login (case-insensitive email)');
    ok(!array_key_exists('password_hash', $u), 'hash never returned');
    eq(null, $auth->verifyCredentials('zz_owner_a@hubos.test', 'nope-nope-nope'), 'wrong password');
    eq(null, $auth->verifyCredentials('nobody@hubos.test', 'zz-password-123A'), 'unknown email');

    $ta->update('users', (int) $ownerA['id'], ['status' => 'disabled']);
    eq(null, $auth->verifyCredentials('zz_owner_a@hubos.test', 'zz-password-123A'), 'disabled user rejected');

    ok(!$auth->isThrottled('x@hubos.test', '10.0.0.1'));
    for ($i = 0; $i < 5; $i++) {
        $auth->recordAttempt('x@hubos.test', '10.0.0.1', false);
    }
    ok($auth->isThrottled('x@hubos.test', '10.0.0.1'), 'throttled after 5 failures');
});

// ---------------------------------------------------------------- Tenant isolation

test('tenant isolation: tenant A cannot read or modify tenant B rows', function (): void {
    [$pdo, $ta, $tb, $ownerA, $ownerB] = world();
    $sa = new ClientService($ta, new Permissions($ta));
    $sb = new ClientService($tb, new Permissions($tb));
    $a = $sa->create($ownerA, ['name' => 'ZZ_Client A']);
    $b = $sb->create($ownerB, ['name' => 'ZZ_Client B']);

    eq(null, $ta->find('clients', $b), 'find() cross-tenant returns null');
    ok($ta->find('clients', $a) !== null, 'own row visible');
    eq(['ZZ_Client A'], array_column($ta->all('clients'), 'name'), 'all() only own rows');
    eq(1, $ta->count('clients'));
    eq(['ZZ_Client A'], array_column($sa->list($ownerA), 'name'), 'service list only own rows');
    eq(['ZZ_Client A'], array_column($ta->select('SELECT name FROM clients WHERE tenant_id = :tenant_id'), 'name'));
    eq(['ZZ_Client A'], array_column($ta->all('clients', ['tenant_id' => $tb->id()]), 'name'), 'where tenant_id override ignored (still scoped to A)');

    eq(0, $ta->update('clients', $b, ['name' => 'HACKED']), 'cross-tenant update affects 0 rows');
    eq('ZZ_Client B', $tb->find('clients', $b)['name'], 'B row unchanged');
    eq(0, $ta->deleteWhere('user_permissions', ['user_id' => (int) $ownerB['id']]), 'cross-tenant delete affects 0 rows');

    $id = $ta->insert('clients', ['name' => 'ZZ_Sneaky', 'tenant_id' => $tb->id()]);
    eq($ta->id(), (int) $ta->find('clients', $id)['tenant_id'], 'insert forces own tenant_id');

    foreach ($ta->select('SELECT * FROM events WHERE tenant_id = :tenant_id') as $ev) {
        eq($ta->id(), (int) $ev['tenant_id'], 'events scoped');
    }
    eq(null, $ta->find('users', (int) $ownerB['id']), 'users scoped');

    throws(fn () => $ta->select('SELECT * FROM clients'), InvalidArgumentException::class);
    throws(fn () => $ta->select('SELECT * FROM clients WHERE tenant_id = :tenant_id', ['tenant_id' => $tb->id()]), InvalidArgumentException::class);
    throws(fn () => $ta->select('DELETE FROM clients WHERE tenant_id = :tenant_id'), InvalidArgumentException::class);
    throws(fn () => $ta->find('tenants', 1), InvalidArgumentException::class);
    throws(fn () => $ta->all('clients', ['name; DROP TABLE clients' => 'x']), InvalidArgumentException::class);
    throws(fn () => $ta->all('clients', [], 'id DESC; --'), InvalidArgumentException::class);
    throws(fn () => new Tenant($pdo, 0), InvalidArgumentException::class);

    $permsA = new Permissions($ta);
    ok(!$permsA->can($ownerB, 'clients.view'), 'user of tenant B has no rights in tenant A');
});

// ---------------------------------------------------------------- Events

test('events: create writes an append-only event with before/after', function (): void {
    [$pdo, $ta, , $ownerA] = world();
    $before = $ta->count('events');
    $id = (new ClientService($ta, new Permissions($ta)))->create($ownerA, ['name' => 'ZZ_לקוח בדיקה', 'email' => 'ZZ@Example.test']);
    eq($before + 1, $ta->count('events'), 'exactly one event written');

    $ev = $ta->all('events', ['action' => 'client.created'], 'id', 'DESC', 1)[0];
    eq('client', $ev['entity_type']);
    eq($id, (int) $ev['entity_id']);
    eq($id, (int) $ev['client_id']);
    eq((int) $ownerA['id'], (int) $ev['actor_user_id']);
    eq($ta->id(), (int) $ev['tenant_id']);
    eq(null, $ev['before_json']);
    $after = json_decode((string) $ev['after_json'], true, 512, JSON_THROW_ON_ERROR);
    eq('ZZ_לקוח בדיקה', $after['name']);
    eq('zz@example.test', $after['email']);
    eq(1, count(Events::recent($ta, 10, $id)), 'recent() by client');

    throws(fn () => DB::run($pdo, 'UPDATE events SET action = ? WHERE id = ?', ['x.y', (int) $ev['id']]), PDOException::class);
    throws(fn () => DB::run($pdo, 'DELETE FROM events WHERE id = ?', [(int) $ev['id']]), PDOException::class);

    foreach ($ta->all('events', ['action' => 'user.created']) as $u) {
        ok(!str_contains((string) $u['after_json'], 'password'), 'no password data in events');
    }
});

test('events: failed validation writes nothing', function (): void {
    [, $ta, , $ownerA] = world();
    $n = $ta->count('events');
    $e = throws(fn () => (new ClientService($ta, new Permissions($ta)))->create($ownerA, ['name' => '  ', 'email' => 'bad']), ValidationException::class);
    ok(isset($e->errors['name'], $e->errors['email']));
    eq($n, $ta->count('events'));
    eq(0, $ta->count('clients'));
});

// ---------------------------------------------------------------- Permissions

test('permissions: client_viewer is read-only; overrides & modules are logged', function (): void {
    [$pdo, $ta, , $ownerA] = world();
    $perms = new Permissions($ta);
    $svc = new ClientService($ta, $perms);
    $clientId = $svc->create($ownerA, ['name' => 'ZZ_Client']);
    $other = $svc->create($ownerA, ['name' => 'ZZ_Other']);
    $viewer = userRow($pdo, Provisioning::createUser($pdo, $ta->id(), 'zz_viewer@hubos.test', 'ZZ_Viewer', 'client_viewer', 'zz-password-123V', (int) $ownerA['id'], $clientId));
    $staff = userRow($pdo, Provisioning::createUser($pdo, $ta->id(), 'zz_staff@hubos.test', 'ZZ_Staff', 'staff', 'zz-password-123S'));

    ok($perms->can($viewer, 'clients.view'), 'viewer can view');
    foreach (['clients.create', 'clients.update', 'clients.archive', 'users.manage', 'permissions.manage'] as $p) {
        ok(Permissions::isWrite($p));
        ok(!$perms->can($viewer, $p), "viewer cannot {$p}");
    }
    $n = $ta->count('clients');
    $e = throws(fn () => $svc->create($viewer, ['name' => 'ZZ_Nope']), HttpException::class);
    eq(403, $e->status);
    eq($n, $ta->count('clients'), 'no row created by viewer');
    eq([$clientId], array_map('intval', array_column($svc->list($viewer), 'id')), 'viewer sees only own client');
    ok(!in_array($other, array_column($svc->list($viewer), 'id'), true));

    ok($perms->can($ownerA, 'permissions.manage'), 'owner can all');
    ok($perms->can($staff, 'clients.create'), 'staff can create');
    ok(!$perms->can($staff, 'permissions.manage'), 'staff cannot manage permissions');
    throws(fn () => $perms->setUserPermission($viewer, (int) $viewer['id'], 'clients.create', true), HttpException::class);
    throws(fn () => $perms->setUserPermission($staff, (int) $viewer['id'], 'clients.create', true), HttpException::class);

    $perms->setUserPermission($ownerA, (int) $viewer['id'], 'clients.create', true);
    ok($perms->can($viewer, 'clients.create'), 'grant override works');
    eq(1, $ta->count('events', ['action' => 'permission.granted']), 'grant logged');
    $perms->setUserPermission($ownerA, (int) $staff['id'], 'clients.create', false);
    ok(!$perms->can($staff, 'clients.create'), 'revoke override works');
    eq(1, $ta->count('events', ['action' => 'permission.revoked']), 'revoke logged');
    $perms->setUserPermission($ownerA, (int) $viewer['id'], 'clients.create', null);
    ok(!$perms->can($viewer, 'clients.create'), 'cleared override falls back to role');

    $perms->setModule($ownerA, 'clients', false);
    ok(!$perms->can($ownerA, 'clients.view'), 'disabled module denies even owner');
    eq(1, $ta->count('events', ['action' => 'module.disabled']), 'module change logged');
    $perms->setModule($ownerA, 'clients', true);
    ok($perms->can($ownerA, 'clients.view'));

    $ta->update('users', (int) $staff['id'], ['status' => 'disabled']);
    ok(!$perms->can(userRow($pdo, (int) $staff['id']), 'clients.view'), 'disabled user denied');
    ok(!$perms->can($ownerA, 'bad perm'), 'malformed permission denied');
});

// ---------------------------------------------------------------- Misc

test('view: e() escapes HTML and templates are path-safe', function (): void {
    eq('&lt;script&gt;&quot;x&quot;&amp;&apos;', e('<script>"x"&\''));
    eq('', e(null));
    eq('5', e(5));
    $v = new View(HUBOS_ROOT . '/views');
    throws(fn () => $v->render('../app/config'), InvalidArgumentException::class);
});

test('db + migrations: WAL, foreign keys, idempotent', function (): void {
    $pdo = freshDb();
    eq('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
    eq(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    eq([], (new Migrator($pdo, HUBOS_ROOT . '/database/migrations'))->run(), 'second run applies nothing');
    throws(fn () => DB::run($pdo, 'INSERT INTO clients (tenant_id, name) VALUES (?, ?)', [999, 'ZZ_orphan']), PDOException::class);
});

test('str: UTF-8 helpers work without mbstring', function (): void {
    eq(4, Str::len('שלום'));
    eq('ש', Str::first('שלום'));
    eq('', Str::clean("\xff\xfe"));
    eq('ab', Str::clean(" a\x00b "));
});

// ---------------------------------------------------------------- M1: accounting rule

test('accounting rule: invoice=income, receipt only if no matching invoice (1:1)', function (): void {
    $doc = static fn (string $key, string $code, float $amount, ?string $client, string $date): array => [
        'key' => $key, 'kind' => AccountingRule::kindForCode($code), 'amount' => $amount, 'client_key' => $client, 'date' => $date,
    ];
    $r = AccountingRule::classify([
        $doc('inv305', '305', 1170.0, 'c:1', '2025-01-10'),
        $doc('rc_match', '400', 1170.0, 'c:1', '2025-01-20'),
        $doc('rc_second', '400', 1170.0, 'c:1', '2025-02-20'),   // invoice already paired
        $doc('rc_other_amount', '400', 1000.0, 'c:1', '2025-01-20'),
        $doc('rc_other_client', '400', 1170.0, 'c:2', '2025-01-20'),
        $doc('rc_no_client', '400', 50.0, null, '2025-01-20'),
        $doc('ta300_open', '300', 300.0, 'c:3', '2025-03-01'),
        $doc('ta300_matched', '300', 200.0, 'c:3', '2025-03-01'),
        $doc('tir320', '320', 200.0, 'c:3', '2025-03-02'),
        $doc('tir330', '330', 55.0, 'c:3', '2025-03-03'),
        ['key' => 'unknown999', 'kind' => AccountingRule::kindForCode('999'), 'amount' => 10.0, 'client_key' => 'c:3', 'date' => '2025-03-03'],
    ]);
    eq(true, $r['inv305']['counts'], '305 tax invoice is income');
    eq(false, $r['rc_match']['counts'], 'receipt with matching invoice (same client+amount) is NOT income');
    eq('inv305', $r['rc_match']['matched']);
    eq(true, $r['rc_second']['counts'], 'second receipt has no free invoice => income');
    eq(true, $r['rc_other_amount']['counts'], 'different amount => no match => income');
    eq(true, $r['rc_other_client']['counts'], 'different client => no match => income');
    eq(true, $r['rc_no_client']['counts'], 'receipt without client cannot be matched => income');
    eq(false, $r['ta300_open']['counts'], '300 is a proforma / payment request, NEVER realized income');
    eq('proforma_open', $r['ta300_open']['reason'], 'unmatched 300 is an open demand');
    eq(null, $r['ta300_open']['matched']);
    eq(false, $r['ta300_matched']['counts'], 'matched 300 is also NOT income');
    eq('proforma_matched', $r['ta300_matched']['reason']);
    eq('tir320', $r['ta300_matched']['matched'], '300 paired 1:1 with matching 320 of same client & amount');
    eq(true, $r['tir320']['counts'], '320 (tax invoice/receipt, per data) is income');
    eq(true, $r['tir330']['counts'], '330 (tax invoice/receipt) is income');
    eq(false, $r['unknown999']['counts'], 'unmapped code is flagged, not counted');
    eq('unknown_type', $r['unknown999']['reason']);
    eq('receipt', AccountingRule::kindForCode('400'));
    eq('320', AccountingRule::codeForKind('tax_invoice_receipt'));
    eq(117000, AccountingRule::cents(1170.0));
    eq(AccountingRule::cents(0.1 + 0.2), AccountingRule::cents(0.3), 'cent comparison is float-safe');
});

// ---------------------------------------------------------------- M1: linking

test('linking: legacy id, Morning id, unique tax id, domain, exact name; ambiguous never links', function (): void {
    $r = new ClientResolver();
    $r->add(1, 'gi_aaaa1111-0000', 'aaaa1111-0000', '511111111', 'https://www.alpha.test/x', 'אלפא בע"מ');
    $r->add(2, 'xl_b', null, '522222222', null, 'Beta');
    $r->add(3, 'xl_c', null, '522222222', 'alpha.test', 'Beta');
    eq([1, 'legacy_id'], $r->resolve(['legacy' => 'gi_aaaa1111-0000']));
    eq([1, 'morning_client_id'], $r->resolve(['legacy' => 'gone', 'gi' => 'AAAA1111-0000']));
    eq([1, 'tax_id'], $r->resolve(['tax' => '51-111111-1']));
    eq([null, null], $r->resolve(['tax' => '522222222']), 'duplicate tax id is ambiguous');
    eq([null, null], $r->resolve(['domain' => 'alpha.test']), 'duplicate domain is ambiguous');
    eq([1, 'name_exact'], $r->resolve(['name' => '  אלפא  בע״מ ']), 'name normalised (quotes/spaces)');
    eq([null, null], $r->resolve(['name' => 'Beta']), 'duplicate name is ambiguous');
    eq('alpha.test', ClientResolver::normDomain('HTTPS://WWW.Alpha.test/path?q=1'));
    eq(null, ClientResolver::normTax('000'));
});

// ---------------------------------------------------------------- M1: importer

/** @return array{0:PDO, 1:array<string,mixed>, 2:string} */
function runImport(?PDO $pdo = null, ?Vault $vault = null): array
{
    $pdo ??= freshDb();
    $report = tempnam(sys_get_temp_dir(), 'hubos_report_');
    T::$tmp[] = $report;
    $summary = (new D1Importer($pdo, $vault))->run(HUBOS_ROOT . '/tests/fixtures/d1_mini.sql', $report);
    return [$pdo, $summary, $report];
}

function dbFingerprint(PDO $pdo): string
{
    $h = '';
    foreach (Tenant::TABLES as $table) {
        if (in_array($table, ['events', 'sync_runs', 'users', 'tenant_modules', 'tenant_settings', 'user_permissions'], true)) {
            continue;
        }
        $h .= $table . ':' . md5(json_encode($pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(), JSON_THROW_ON_ERROR)) . ';';
    }
    return $h;
}

test('importer: fixture dump (FK-out-of-order) imports, links and applies the accounting rule', function (): void {
    [$pdo, $s] = runImport();
    $t = new Tenant($pdo, (int) $s['tenant_id']);
    eq(D1Importer::TENANT_NAME, (string) $pdo->query('SELECT name FROM tenants WHERE id = ' . (int) $s['tenant_id'])->fetchColumn());
    eq(6, $s['counts']['clients'], '5 generic + 1 relational-only client');
    eq(8, $s['counts']['finance_documents'], 'financeIncomes+invoices merged by greenInvoiceId');
    eq(1, $s['counts']['projects'], 'DNS scan project skipped');
    eq(2, $s['counts']['assets'], 'duplicate domain merged');
    foreach ($s['stats'] as $entity => $st) {
        eq($st['source'], $st['imported'] + count($st['skipped']), "{$entity}: source = imported + skipped/merged (no silent drops)");
    }
    foreach ($s['inventory'] as $src => $inv) {
        ok($inv['disposition'] !== 'UNHANDLED', "inventory {$src} handled");
    }

    $cid = static fn (string $legacy): int => (int) $t->all('clients', ['legacy_id' => $legacy])[0]['id'];
    $child = $t->all('clients', ['legacy_id' => 'gi_zz_child'])[0];
    eq($cid('gi_zz_parent'), (int) $child['parent_client_id'], 'parentCompanyId -> parent_client_id');
    eq('inactive', $child['status']);
    eq(1, $t->count('client_contacts', ['client_id' => (int) $child['id']]));

    $fd = static fn (string $legacy): array => $t->all('finance_documents', ['legacy_id' => $legacy])[0];
    eq(1, (int) $fd('zz-305')['counts_as_income']);
    eq(0, (int) $fd('zz-400a')['counts_as_income'], 'receipt matched to 305');
    eq((int) $fd('zz-305')['id'], (int) $fd('zz-400a')['matched_document_id']);
    eq(1, (int) $fd('zz-400b')['counts_as_income'], 'second receipt, invoice already paired');
    eq(1, (int) $fd('zz-400c')['counts_as_income']);
    eq('tax_id', $fd('zz-320')['link_method']);
    eq($cid('gi_zz_parent'), (int) $fd('zz-320')['client_id']);
    eq(null, $fd('zz-300')['client_id'], 'ambiguous tax id is not linked');
    eq(0, (int) $fd('zz-300')['counts_as_income'], '300 is proforma_open, not income');
    eq('proforma_open', $fd('zz-300')['income_reason']);
    eq('320', $fd('zz-api')['doc_type'], 'explicit code from invoices collection wins over inferred label');
    eq($cid('gi_zz_child'), (int) $fd('zz-api')['client_id']);
    eq('name_exact', $fd('zz-name')['link_method']);
    eq('kind', $fd('zz-name')['doc_type_source']);
    eq(['2025' => 2840.0, '2026' => 341.0], array_map('floatval', $s['finance']['income_by_year']));

    $task = $t->all('tasks', ['legacy_id' => 't1'])[0];
    eq($cid('gi_zz_parent'), (int) $task['client_id'], 'task client via project');
    eq('todo', $task['status']);
    eq('done', $t->all('projects', ['legacy_id' => 'p_real'])[0]['status'], 'generic status overlay applied');
    $h1 = $t->all('assets', ['legacy_id' => 'h1'])[0];
    eq($cid('gi_zz_dom'), (int) $h1['client_id'], 'hosting linked by domain');
    eq('domain', $h1['link_method']);
    eq('2027-01-31', $h1['expiry_date'], 'expiry taken from merged relational domain');
    eq(1, $t->count('time_entries', ['duration_seconds' => 9000]));
    eq(1, $t->count('expenses', ['supplier_id' => (int) $t->all('suppliers')[0]['id']]));
});

test('importer: idempotent — second run yields identical data', function (): void {
    [$pdo] = runImport();
    $first = dbFingerprint($pdo);
    runImport($pdo);
    eq($first, dbFingerprint($pdo), 'all business tables byte-identical after re-run');
    eq(1, (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn(), 'single tenant');
});

test('importer: vault never stores plaintext; encrypts with HUBOS_VAULT_KEY', function (): void {
    $secret = 'ZZ_fake_secret_value_123';
    [$pdo, $s, $report] = runImport();
    $v = $pdo->query("SELECT * FROM vault_items WHERE legacy_id = 'v1'")->fetch();
    eq(1, (int) $v['has_secret']);
    eq(null, $v['secret_ciphertext'], 'no key => secret not stored');
    eq('none', $v['encryption']);
    $dbPath = (string) $pdo->query('PRAGMA database_list')->fetch()['file'];
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    ok(!str_contains((string) file_get_contents($dbPath), $secret), 'secret absent from DB file');
    ok(!str_contains((string) file_get_contents($report), $secret), 'secret absent from report');
    ok(!str_contains((string) $v['data_json'], 'password'), 'password key stripped from data_json');

    $vault = Vault::fromKeyString(bin2hex(random_bytes(32)));
    [$pdo2] = runImport(null, $vault);
    $v2 = $pdo2->query("SELECT * FROM vault_items WHERE legacy_id = 'v1'")->fetch();
    eq('aes-256-gcm', $v2['encryption']);
    ok(!str_contains((string) $v2['secret_ciphertext'], $secret));
    eq($secret, $vault->decrypt((string) $v2['secret_ciphertext']), 'round-trip');
    runImport($pdo2, $vault);
    eq($v2['secret_ciphertext'], $pdo2->query("SELECT secret_ciphertext FROM vault_items WHERE legacy_id = 'v1'")->fetchColumn(), 'ciphertext stable on re-run');
    throws(fn () => Vault::fromKeyString(bin2hex(random_bytes(32)))->decrypt((string) $v2['secret_ciphertext']), RuntimeException::class);
    throws(fn () => Vault::fromKeyString('short'), RuntimeException::class);
});

// ---------------------------------------------------------------- M1: tenant isolation

test('tenant isolation: every M1 table is scoped; cross-tenant references are rejected by FK', function (): void {
    [$pdo, $ta, $tb, $ownerA] = world();
    $clientA = $ta->insert('clients', ['name' => 'ZZ_A']);
    $rows = [
        'client_contacts'   => ['client_id' => $clientA, 'name' => 'ZZ_c'],
        'suppliers'         => ['name' => 'ZZ_s'],
        'projects'          => ['name' => 'ZZ_p', 'client_id' => $clientA],
        'tasks'             => ['title' => 'ZZ_t', 'client_id' => $clientA],
        'hours_banks'       => ['name' => 'ZZ_hb'],
        'time_entries'      => ['description' => 'ZZ_te'],
        'active_timers'     => ['user_id' => (int) $ownerA['id']],
        'finance_documents' => ['doc_type' => '305', 'amount' => 1.0],
        'expenses'          => ['amount' => 1.0],
        'leads'             => ['name' => 'ZZ_l'],
        'quotes'            => ['quote_number' => 'ZZ_q'],
        'assets'            => ['domain' => 'zz.test'],
        'vault_items'       => ['title' => 'ZZ_v'],
        'interactions'      => ['kind' => 'note'],
    ];
    foreach ($rows as $table => $row) {
        ok(in_array($table, Tenant::TABLES, true), "{$table} registered");
        $id = $ta->insert($table, $row + ['tenant_id' => $tb->id()]);
        eq($ta->id(), (int) $ta->find($table, $id)['tenant_id'], "{$table}: insert forces own tenant");
        eq(null, $tb->find($table, $id), "{$table}: invisible to other tenant");
        eq(0, $tb->count($table), "{$table}: other tenant count 0");
        eq(0, $tb->update($table, $id, $table === 'active_timers' ? ['note' => 'x'] : ['legacy_id' => 'HACK']), "{$table}: cross-tenant update 0 rows");
        $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
        ok(in_array('tenant_id', $cols, true), "{$table}: has tenant_id");
    }
    throws(fn () => $tb->insert('tasks', ['title' => 'ZZ_x', 'client_id' => $clientA]), PDOException::class);
    throws(fn () => $tb->insert('client_contacts', ['name' => 'ZZ_x', 'client_id' => $clientA]), PDOException::class);
    throws(fn () => $tb->insert('active_timers', ['user_id' => (int) $ownerA['id']]), PDOException::class);
    $ta->upsert('leads', ['legacy_id' => 'L1', 'name' => 'ZZ_one']);
    $tb->upsert('leads', ['legacy_id' => 'L1', 'name' => 'ZZ_other']);
    eq('ZZ_one', $ta->all('leads', ['legacy_id' => 'L1'])[0]['name'], 'same legacy_id in two tenants stays separate');
    eq(1, $tb->count('leads'));
});

// ---------------------------------------------------------------- M2: Client 360

/** Tenant A with a family (parent P ← child C), an unrelated client U, and data for each. */
function family360(): array
{
    [$pdo, $ta, $tb, $ownerA, $ownerB] = world();
    $p = $ta->insert('clients', ['name' => 'ZZ_Parent אלפא אחזקות', 'tax_id' => '511111118']);
    $c = $ta->insert('clients', ['name' => 'ZZ_Child קבוצת בטא טכנולוגיות', 'tax_id' => '511111118', 'parent_client_id' => $p]);
    $u = $ta->insert('clients', ['name' => 'ZZ_Unrelated']);
    $doc = static fn (int $cid, string $code, string $kind, float $amt, int $income, string $date, string $status = 'paid') => $ta->insert('finance_documents', [
        'client_id' => $cid, 'doc_type' => $code, 'doc_kind' => $kind, 'amount' => $amt, 'counts_as_income' => $income,
        'doc_date' => $date, 'status' => $status, 'doc_number' => 'ZZ' . $code . $cid . (int) $amt,
    ]);
    $inv = $doc($p, '305', 'tax_invoice', 1000, 1, '2025-03-01');
    $rcpt = $doc($p, '400', 'receipt', 1000, 0, '2025-03-10');
    $ta->update('finance_documents', $rcpt, ['matched_document_id' => $inv]);
    $doc($c, '320', 'tax_invoice_receipt', 500, 1, '2026-02-01');
    $doc($c, '305', 'tax_invoice', 200, 1, '2026-05-01', 'unpaid');
    $doc($u, '320', 'tax_invoice_receipt', 999, 1, '2026-01-01');
    $ta->insert('client_contacts', ['client_id' => $c, 'name' => 'ZZ_Contact', 'is_primary' => 1]);
    $ta->insert('assets', ['client_id' => $c, 'kind' => 'hosting', 'domain' => 'zz-alpha.test']);
    $bank = $ta->insert('hours_banks', ['client_id' => $c, 'name' => 'ZZ_Bank', 'total_hours' => 10, 'status' => 'active']);
    $ta->insert('time_entries', ['client_id' => $c, 'hours_bank_id' => $bank, 'entry_date' => '2026-10-05', 'duration_seconds' => 5400]);
    $ta->insert('time_entries', ['client_id' => $c, 'entry_date' => '2026-09-05', 'duration_seconds' => 3600]);
    $ta->insert('vault_items', [
        'client_id' => $c, 'title' => 'ZZ_wp-admin', 'url' => 'https://zz.test/wp-admin', 'username' => 'zz_admin',
        'has_secret' => 1, 'encryption' => 'aes-256-gcm', 'secret_ciphertext' => 'v1:ZZ_CIPHERTEXT_LEAK',
        'notes' => 'ZZ_SECRET_NOTE_LEAK', 'data_json' => json_encode(['notes' => 'ZZ_SECRET_JSON_LEAK']),
    ]);
    $cb = $tb->insert('clients', ['name' => 'ZZ_TenantB child', 'parent_client_id' => null]);
    $tb->insert('finance_documents', ['client_id' => $cb, 'doc_type' => '305', 'doc_kind' => 'tax_invoice', 'amount' => 7777, 'counts_as_income' => 1, 'doc_date' => '2026-01-01']);
    return compact('pdo', 'ta', 'tb', 'ownerA', 'ownerB', 'p', 'c', 'u', 'cb');
}

test('client360: family roll-up, origin labels, accounting-rule income, tenant isolation', function (): void {
    $w = family360();
    $svc = new Client360Service($w['ta'], new Permissions($w['ta']));
    $now = new DateTimeImmutable('2026-10-19 10:00', new DateTimeZone('Asia/Jerusalem'));

    $child = $svc->build($w['ownerA'], $w['c'], $now);
    eq('family', $child['scope']);
    eq($w['p'], (int) $child['parent']['id'], 'child sees its parent');
    eq([$w['c'], $w['p']], array_keys($child['family']));
    eq(4, count($child['finance']), 'child card rolls up the parent docs (and not the unrelated client)');
    eq(2, count(array_filter($child['finance'], static fn (array $d): bool => $d['origin_client_id'] === $w['p'])), 'parent docs labeled with parent');
    ok(in_array('ZZ_Parent אלפא אחזקות', array_column($child['finance'], 'origin_name'), true));
    eq(1700.0, $child['metrics']['income_total'], 'income uses counts_as_income (receipt not double counted; naive sum would be 2700)');
    eq(700.0, $child['metrics']['income_this_year']);
    eq(['2026' => 700.0, '2025' => 1000.0], $child['metrics']['income_by_year']);
    eq(1, $child['metrics']['open_docs_count']);
    eq(false, $child['metrics']['balance_reliable'], 'balance is declared not reliably computable');
    eq(1.5, round($child['metrics']['hours_this_month'], 4));
    eq(8.5, round($child['metrics']['bank_remaining'], 4));
    eq(4, $child['counts']['finance']);
    eq(1, $child['counts']['access']);
    foreach (array_keys(Client360Service::TABS) as $tab) {
        ok(array_key_exists($tab, $child['counts']), "count for tab {$tab}");
    }

    $parent = $svc->build($w['ownerA'], $w['p'], $now);
    eq([$w['c']], array_map(static fn (array $r): int => (int) $r['id'], $parent['children']), 'parent lists its child');
    eq(4, count($parent['finance']), 'parent card rolls up the child docs');
    ok(!in_array(999.0, array_map('floatval', array_column($parent['finance'], 'amount')), true), 'unrelated client excluded');
    ok(!in_array(7777.0, array_map('floatval', array_column($parent['finance'], 'amount')), true), 'other tenant excluded');

    $e = throws(fn () => $svc->build($w['ownerA'], $w['cb']), HttpException::class);
    eq(404, $e->status, 'tenant A cannot open a tenant B client');
    $svcB = new Client360Service($w['tb'], new Permissions($w['tb']));
    eq(404, throws(fn () => $svcB->build($w['ownerB'], $w['c']), HttpException::class)->status);
});

test('client360: vault secrets never leave the service or the HTML; views are logged', function (): void {
    $w = family360();
    $svc = new Client360Service($w['ta'], new Permissions($w['ta']));
    $card = $svc->build($w['ownerA'], $w['p']);
    $json = json_encode($card, JSON_UNESCAPED_UNICODE);
    foreach (['ZZ_CIPHERTEXT_LEAK', 'ZZ_SECRET_NOTE_LEAK', 'ZZ_SECRET_JSON_LEAK', 'secret_ciphertext'] as $needle) {
        ok(!str_contains((string) $json, $needle), "service output leaks {$needle}");
    }
    eq(1, count($card['vault']));
    $allowed = array_merge(Client360Service::VAULT_COLUMNS, ['origin_client_id', 'origin_name', 'is_own']);
    eq([], array_values(array_diff(array_keys($card['vault'][0]), $allowed)), 'vault row has metadata columns only');
    eq('zz_admin', $card['vault'][0]['username']);

    $view = new View(HUBOS_ROOT . '/views');
    foreach (array_keys(Client360Service::TABS) as $tab) {
        $html = $view->render('clients/show', ['card' => $card, 'tab' => $tab, 'canEnrich' => false, 'suggestion' => null, 'registryError' => null]);
        foreach (['ZZ_CIPHERTEXT_LEAK', 'ZZ_SECRET_NOTE_LEAK', 'ZZ_SECRET_JSON_LEAK'] as $needle) {
            ok(!str_contains($html, $needle), "HTML tab {$tab} leaks {$needle}");
        }
        if ($tab === 'access') {
            ok(str_contains($html, 'ZZ_wp-admin'), 'access tab shows vault metadata');
        }
    }

    $svc->logVaultView($w['ownerA'], $w['p'], $card['vault']);
    $ev = $w['ta']->all('events', ['action' => 'vault.metadata_viewed']);
    eq(1, count($ev), 'vault view logged');
    eq($w['p'], (int) $ev[0]['client_id']);
    ok(!str_contains((string) $ev[0]['after_json'], 'LEAK'));
});

test('client360: client_viewer sees only its own client (no roll-up, no vault, no audit)', function (): void {
    $w = family360();
    $perms = new Permissions($w['ta']);
    $viewer = userRow($w['pdo'], Provisioning::createUser($w['pdo'], $w['ta']->id(), 'zz_viewer360@hubos.test', 'ZZ_Viewer', 'client_viewer', 'zz-password-123V', (int) $w['ownerA']['id'], $w['c']));
    $staff = userRow($w['pdo'], Provisioning::createUser($w['pdo'], $w['ta']->id(), 'zz_staff360@hubos.test', 'ZZ_Staff', 'staff', 'zz-password-123S'));
    $svc = new Client360Service($w['ta'], $perms);

    $own = $svc->build($viewer, $w['c']);
    eq('self', $own['scope']);
    eq([$w['c']], array_keys($own['family']), 'no family roll-up for viewer');
    eq(2, count($own['finance']), 'only own docs');
    eq([], $own['vault'], 'no vault for viewer');
    eq([], $own['events'], 'no audit events for viewer');
    eq(null, $own['parent']);
    eq(403, throws(fn () => $svc->build($viewer, $w['p']), HttpException::class)->status, 'viewer cannot open parent');
    eq(403, throws(fn () => $svc->build($viewer, $w['u']), HttpException::class)->status, 'viewer cannot open other client');
    eq(403, throws(fn () => (new ClientService($w['ta'], $perms))->applyRegistry($viewer, $w['c'], ['name' => 'X'], ['name']), HttpException::class)->status);
    $cs = new ClientService($w['ta'], $perms);
    eq([$w['c']], array_map('intval', array_column($cs->search($viewer, ''), 'id')));
    eq([], $cs->search($viewer, 'ZZ_Unrelated'), 'viewer search cannot reach other clients');

    $s = $svc->build($staff, $w['u']);
    eq('family', $s['scope'], 'staff sees any tenant client');
});

test('hebrew search: normalization, one-letter prefix stripping, ranking', function (): void {
    eq('בעמ', HebrewText::normalize('בע"מ'));
    eq('בעמ', HebrewText::normalize('בע״מ'));
    eq('שלומ', HebrewText::normalize('שָׁלוֹם'), 'niqqud removed, final mem normalized');
    eq('אבג דה', HebrewText::normalize('  אבג - דה! '));
    eq('בטא', HebrewText::stripPrefix('לבטא'));
    eq(null, HebrewText::stripPrefix('בטא'), 'no strip when < 3 letters remain');
    eq(null, HebrewText::stripPrefix('abc'));
    ok(HebrewText::score('בטא', 'קבוצת בטא טכנולוגיות') > 0);
    ok(HebrewText::score('לבטא', 'קבוצת בטא טכנולוגיות') > 0, 'query prefix ל stripped');
    ok(HebrewText::score('הטכנולוגיות', 'קבוצת בטא טכנולוגיות') > 0, 'query prefix ה stripped');
    ok(HebrewText::score('טכנולוגיות', 'קבוצת בטא והטכנולוגיות') > 0, 'haystack word prefix stripped');
    ok(HebrewText::score('בטא טכנ', 'קבוצת בטא טכנולוגיות') > 0, 'all tokens must match (prefix)');
    eq(0, HebrewText::score('בטא רכב', 'קבוצת בטא טכנולוגיות'), 'every token must match');
    eq(0, HebrewText::score('', 'x'));
    ok(HebrewText::score('בטא', 'קבוצת בטא טכנולוגיות') > HebrewText::score('בטא', 'בטאון'), 'exact word outranks prefix');

    $w = family360();
    $cs = new ClientService($w['ta'], new Permissions($w['ta']));
    foreach (['בטא', 'לבטא', 'הטכנולוגיות', 'טכנולוגיות', '511111118'] as $q) {
        ok(in_array($w['c'], array_map('intval', array_column($cs->search($w['ownerA'], $q), 'id')), true), "search '{$q}' finds the Beta client");
    }
    eq($w['c'], (int) $cs->search($w['ownerA'], 'בטא')[0]['id'], 'best match first');
    eq(3, count($cs->search($w['ownerA'], '')), 'empty query lists all');
});

final class ZzMockHttp implements HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    /** @param array{status:int, body:string}|Throwable $reply */
    public function __construct(private array|Throwable $reply)
    {
    }

    public function get(string $url, int $timeoutSeconds = 8): array
    {
        $this->urls[] = $url;
        if ($this->reply instanceof Throwable) {
            throw $this->reply;
        }
        return $this->reply;
    }
}

test('registry enrichment: mocked HTTP, suggestions only, explicit apply is stored + logged, failures graceful', function (): void {
    $body = json_encode(['success' => true, 'result' => ['records' => [
        ['מספר חברה' => 511111119, 'שם חברה' => 'ZZ_אחרת בע~מ'],
        ['מספר חברה' => 511111118, 'שם חברה' => 'ZZ_אלפא אחזקות בע~מ', 'שם באנגלית' => 'ZZ ALPHA HOLDINGS', 'סטטוס חברה' => 'פעילה',
         'סוג תאגיד' => 'ישראלית חברה פרטית', 'תאריך התאגדות' => '31/10/2000', 'שם עיר' => 'חיפה', 'שם רחוב' => 'פלי~ם', 'מספר בית' => '2', 'מיקוד' => 3309502],
    ]]], JSON_UNESCAPED_UNICODE);
    $http = new ZzMockHttp(['status' => 200, 'body' => (string) $body]);
    $reg = new CompanyRegistry($http, 'https://registry.invalid/api?resource_id=x');
    $r = $reg->lookup('51-111111-8');
    ok($r['ok'], 'lookup ok');
    eq(1, count($http->urls));
    ok(str_contains($http->urls[0], '&q=511111118'), 'tax id sent as q');
    eq('ZZ_אלפא אחזקות בע"מ', $r['suggestion']['name'], 'exact tax-id record chosen; ~ decoded');
    eq('פלי"ם 2', $r['suggestion']['address']);
    eq('חיפה', $r['suggestion']['city']);
    eq('2000-10-31', $r['suggestion']['incorporation_date']);
    eq('פעילה', $r['suggestion']['status']);

    $fail = (new CompanyRegistry(new ZzMockHttp(new HttpClientException('down'))))->lookup('511111118');
    ok(!$fail['ok'] && str_contains($fail['error'], 'לא ניתן להתחבר'), 'network failure is graceful');
    ok(!(new CompanyRegistry(new ZzMockHttp(['status' => 503, 'body' => ''])))->lookup('511111118')['ok']);
    ok(!(new CompanyRegistry(new ZzMockHttp(['status' => 200, 'body' => 'not json'])))->lookup('511111118')['ok']);
    ok(!(new CompanyRegistry(new ZzMockHttp(['status' => 200, 'body' => (string) $body])))->lookup('999999999')['ok'], 'no exact match → not found');
    $noCall = new ZzMockHttp(['status' => 200, 'body' => '']);
    ok(!(new CompanyRegistry($noCall))->lookup(null)['ok']);
    eq([], $noCall->urls, 'invalid tax id never calls the network');

    $w = family360();
    $cs = new ClientService($w['ta'], new Permissions($w['ta']));
    $before = $w['ta']->find('clients', $w['p']);
    eq(0, $w['ta']->count('events', ['action' => 'client.registry_applied']), 'lookup alone stores nothing');
    $cs->applyRegistry($w['ownerA'], $w['p'], $r['suggestion'], ['address', 'city', 'bogus']);
    $after = $w['ta']->find('clients', $w['p']);
    eq($before['name'], $after['name'], 'unchecked field (name) untouched');
    eq('פלי"ם 2', $after['address']);
    eq('חיפה', $after['city']);
    $reg = json_decode((string) $after['data_json'], true)['registry'];
    eq('פעילה', $reg['status']);
    eq('2000-10-31', $reg['incorporation_date']);
    eq(['address', 'city'], $reg['applied_fields']);
    $ev = $w['ta']->all('events', ['action' => 'client.registry_applied']);
    eq(1, count($ev), 'apply logged');
    eq($w['p'], (int) $ev[0]['entity_id']);
});

test('router: M2 client card routes are protected', function (): void {
    $r = new Router();
    (require HUBOS_ROOT . '/app/routes.php')($r);
    $m = $r->match('GET', '/clients/286');
    eq(200, $m['status']);
    eq('286', $m['params']['id']);
    eq('clients.view', $m['opts']['perm'] ?? null);
    eq('clients.update', $r->match('POST', '/clients/1/enrich')['opts']['perm'] ?? null);
    eq('clients.update', $r->match('POST', '/clients/1/enrich/apply')['opts']['perm'] ?? null);
    eq(404, $r->match('GET', '/clients/abc')['status']);
    eq(405, $r->match('GET', '/clients/1/enrich')['status']);
});

// ---------------------------------------------------------------- M3/M4: timer, banks, tasks, projects

/** Tenant A: client C (active 10h bank), client D (no bank), staff + viewer of C; tenant B with its own client. */
function workWorld(): array
{
    [$pdo, $ta, $tb, $ownerA, $ownerB] = world();
    $c = $ta->insert('clients', ['name' => 'ZZ_Beta קבוצת בטא', 'domain' => 'zz-alpha.co.il']);
    $d = $ta->insert('clients', ['name' => 'ZZ_Other']);
    $bank = $ta->insert('hours_banks', ['client_id' => $c, 'name' => 'ZZ_Bank 10', 'total_hours' => 10, 'status' => 'active', 'created_at' => '2026-08-01T00:00:00Z']);
    $staff = userRow($pdo, Provisioning::createUser($pdo, $ta->id(), 'zz_staff_w@hubos.test', 'ZZ_Staff', 'staff', 'zz-password-123S'));
    $viewer = userRow($pdo, Provisioning::createUser($pdo, $ta->id(), 'zz_viewer_w@hubos.test', 'ZZ_Viewer', 'client_viewer', 'zz-password-123V', null, $c));
    $cb = $tb->insert('clients', ['name' => 'ZZ_TenantB client']);
    $perms = new Permissions($ta);
    return compact('pdo', 'ta', 'tb', 'ownerA', 'ownerB', 'c', 'd', 'bank', 'staff', 'viewer', 'cb', 'perms');
}

test('timer: one active timer per user, survives reload, pause/resume accumulate', function (): void {
    $w = workWorld();
    $now = 1_790_000_000;
    $clock = static function () use (&$now): int { return $now; };
    $svc = new TimerService($w['ta'], $w['perms'], $clock);
    $svc->start($w['ownerA'], ['client_id' => (string) $w['c']]);
    eq(409, throws(fn () => $svc->start($w['ownerA'], ['client_id' => (string) $w['d']]), HttpException::class)->status, 'second timer rejected');
    eq(1, $w['ta']->count('active_timers', ['user_id' => (int) $w['ownerA']['id']]));
    throws(fn () => $w['ta']->insert('active_timers', ['user_id' => (int) $w['ownerA']['id'], 'client_id' => $w['d']]), PDOException::class); // DB UNIQUE as well
    $svc->start($w['staff'], ['client_id' => (string) $w['d']]);
    eq(2, $w['ta']->count('active_timers'), 'other users have their own timer');

    $now += 600;
    $reloaded = (new TimerService($w['ta'], $w['perms'], $clock))->current($w['ownerA']); // "refresh": fresh service, state from DB
    eq(600, $reloaded['elapsed_seconds']);
    ok($reloaded['running']);
    eq($w['c'], (int) $reloaded['client_id']);
    $svc->pause($w['ownerA']);
    $now += 1000; // paused time does not count
    eq(600, $svc->current($w['ownerA'])['elapsed_seconds']);
    $svc->resume($w['ownerA']);
    $now += 60;
    eq(660, $svc->current($w['ownerA'])['elapsed_seconds']);
    foreach (['timer.started', 'timer.paused', 'timer.resumed'] as $a) {
        ok($w['ta']->count('events', ['action' => $a]) >= 1, "{$a} logged");
    }
    // tenant isolation: tenant B owner cannot start a timer on tenant A's client
    $svcB = new TimerService($w['tb'], new Permissions($w['tb']), $clock);
    ok(isset(throws(fn () => $svcB->start($w['ownerB'], ['client_id' => (string) $w['c']]), ValidationException::class)->errors['client_id']));
});

test('timer: stop requires a description, creates the entry and deducts the client bank', function (): void {
    $w = workWorld();
    $now = 1_790_000_000;
    $clock = static function () use (&$now): int { return $now; };
    $svc = new TimerService($w['ta'], $w['perms'], $clock);
    $task = (new TaskService($w['ta'], $w['perms']))->create($w['ownerA'], ['client_id' => (string) $w['c'], 'title' => 'ZZ_task']);
    $svc->start($w['ownerA'], ['task_id' => (string) $task]); // client derived from the task
    $now += 5400;
    throws(fn () => $svc->stop($w['ownerA'], ' '), ValidationException::class);
    eq(1, $w['ta']->count('active_timers'), 'timer still running after rejected stop');
    eq(0, $w['ta']->count('time_entries'));

    $entryId = $svc->stop($w['ownerA'], 'ZZ_תיקון באתר');
    $e = $w['ta']->find('time_entries', $entryId);
    eq(5400, (int) $e['duration_seconds']);
    eq($w['c'], (int) $e['client_id']);
    eq($task, (int) $e['task_id']);
    eq($w['bank'], (int) $e['hours_bank_id'], 'linked to the active bank');
    eq((int) $w['ownerA']['id'], (int) $e['user_id']);
    eq('timer', $e['source']);
    eq(0, $w['ta']->count('active_timers'), 'timer removed');
    $bank = (new HoursBankService($w['ta']))->summarize($w['ta']->find('hours_banks', $w['bank']));
    eq(1.5, $bank['used_hours']);
    eq(8.5, $bank['remaining_hours'], 'bank remaining decreased');
    eq(1, $w['ta']->count('events', ['action' => 'time_entry.created']));
    eq(1, $w['ta']->count('events', ['action' => 'timer.stopped']));
    eq(409, throws(fn () => $svc->stop($w['ownerA'], 'ZZ_again'), HttpException::class)->status, 'no timer to stop');

    // client without a bank: entry still created, no bank link
    $svc->start($w['ownerA'], ['client_id' => (string) $w['d']]);
    $now += 60;
    eq(null, $w['ta']->find('time_entries', $svc->stop($w['ownerA'], 'ZZ_no bank'))['hours_bank_id']);
});

test('hours bank: 80% / 90% thresholds and threshold-crossing events', function (): void {
    eq('ok', HoursBankService::level(7.99, 10));
    eq('warn80', HoursBankService::level(8.0, 10));
    eq('warn80', HoursBankService::level(8.99, 10));
    eq('warn90', HoursBankService::level(9.0, 10));
    eq('over', HoursBankService::level(10.0, 10));
    eq('ok', HoursBankService::level(0, 0));
    eq('over', HoursBankService::level(1, 0));

    $w = workWorld();
    $entries = new TimeEntryService($w['ta'], $w['perms']);
    $entries->create($w['ownerA'], ['client_id' => (string) $w['c'], 'description' => 'ZZ_a', 'duration' => '7:30', 'entry_date' => '2026-10-01']);
    eq(0, $w['ta']->count('events', ['action' => 'hours_bank.threshold_crossed']), '75%: no alert');
    $entries->create($w['ownerA'], ['client_id' => (string) $w['c'], 'description' => 'ZZ_b', 'duration' => '0:45', 'entry_date' => '2026-10-02']);
    $ev = $w['ta']->all('events', ['action' => 'hours_bank.threshold_crossed']);
    eq(1, count($ev), '82.5%: crossed 80');
    eq('warn80', json_decode((string) $ev[0]['after_json'], true)['level']);
    $entries->create($w['ownerA'], ['client_id' => (string) $w['c'], 'description' => 'ZZ_c', 'duration' => '0:45', 'entry_date' => '2026-10-03']);
    eq(2, $w['ta']->count('events', ['action' => 'hours_bank.threshold_crossed']), '90%: crossed 90');
    $s = (new HoursBankService($w['ta']))->summarize($w['ta']->find('hours_banks', $w['bank']));
    eq('warn90', $s['level']);
    eq(90, $s['pct']);
    $card = (new Client360Service($w['ta'], $w['perms']))->build($w['ownerA'], $w['c']);
    ok((bool) array_filter($card['alerts'], static fn (array $a): bool => str_contains($a['text'], '90%')), 'card shows the 90% alert');
    eq('warn90', $card['own_bank']['level']);
});

test('hours bank: monthly rollover computation (carry, cap, no negative carry, off)', function (): void {
    $used = ['2026-08' => 6.0, '2026-09' => 12.0, '2026-10' => 3.0];
    $m = HoursBankService::rollover(10, true, '2026-08', '2026-10', $used);
    eq(['2026-08', '2026-09', '2026-10'], array_column($m, 'month'));
    eq([10.0, 14.0, 12.0], array_map('floatval', array_column($m, 'allowance')), 'unused 4h → Sep, then 2h → Oct');
    eq([4.0, 2.0, 9.0], array_map('floatval', array_column($m, 'remaining')));
    $capped = HoursBankService::rollover(10, true, '2026-08', '2026-10', $used, 1.0);
    eq([10.0, 11.0, 10.0], array_map('floatval', array_column($capped, 'allowance')), 'carry capped at 1h; overuse carries 0');
    $off = HoursBankService::rollover(10, false, '2026-08', '2026-10', $used);
    eq([10.0, 10.0, 10.0], array_map('floatval', array_column($off, 'allowance')));
    eq(-2.0, (float) $off[1]['remaining'], 'overuse shows negative in its own month');
    eq('2027-01', HoursBankService::nextMonth('2026-12'));
    eq(1, count(HoursBankService::rollover(5, true, '2026-12', '2026-10', [])), 'start after current clamps');

    $w = workWorld();
    (new HoursBankService($w['ta'], $w['perms']))->updateSettings($w['ownerA'], $w['bank'], ['period' => 'monthly', 'rollover' => '1', 'starts_on' => '2026-08-01']);
    eq(1, $w['ta']->count('events', ['action' => 'hours_bank.settings_updated']), 'settings change logged');
    $entries = new TimeEntryService($w['ta'], $w['perms']);
    foreach (['2026-08-10' => '6:00', '2026-09-10' => '12:00', '2026-10-10' => '3:00'] as $date => $dur) {
        $entries->create($w['ownerA'], ['client_id' => (string) $w['c'], 'description' => 'ZZ_m', 'duration' => $dur, 'entry_date' => $date]);
    }
    $s = (new HoursBankService($w['ta']))->summarize($w['ta']->find('hours_banks', $w['bank']), '2026-10');
    eq(12.0, (float) $s['allowance_hours']);
    eq(9.0, (float) $s['remaining_hours']);
    eq(403, throws(fn () => (new HoursBankService($w['ta'], $w['perms']))->updateSettings($w['staff'], $w['bank'], ['period' => 'one_time']), HttpException::class)->status, 'staff cannot change bank settings');
});

test('time entries: manual create/edit/delete logged with before/after; own entries only for staff', function (): void {
    $w = workWorld();
    $svc = new TimeEntryService($w['ta'], $w['perms']);
    eq(3600, TimeEntryService::parseDuration('1:00'));
    eq(5400, TimeEntryService::parseDuration('1.5'));
    eq(2700, TimeEntryService::parseDuration('45m'));
    eq(null, TimeEntryService::parseDuration('0'));
    eq(null, TimeEntryService::parseDuration('25:00'));
    $e = throws(fn () => $svc->create($w['ownerA'], ['description' => 'ZZ_x', 'duration' => '1:00']), ValidationException::class);
    ok(isset($e->errors['client_id']), 'client required for time entries');
    $id = $svc->create($w['staff'], ['client_id' => (string) $w['c'], 'description' => 'ZZ_manual', 'duration' => '1:00', 'entry_date' => '2026-10-05']);
    eq($w['bank'], (int) $w['ta']->find('time_entries', $id)['hours_bank_id']);
    $svc->update($w['ownerA'], $id, ['client_id' => (string) $w['c'], 'description' => 'ZZ_manual fixed', 'duration' => '2:00', 'entry_date' => '2026-10-05']);
    $ev = $w['ta']->all('events', ['action' => 'time_entry.updated'])[0];
    eq(3600, json_decode((string) $ev['before_json'], true)['duration_seconds']);
    eq(7200, json_decode((string) $ev['after_json'], true)['duration_seconds']);
    $other = $svc->create($w['ownerA'], ['client_id' => (string) $w['c'], 'description' => 'ZZ_owner', 'duration' => '0:30']);
    eq(403, throws(fn () => $svc->update($w['staff'], $other, ['client_id' => (string) $w['c'], 'description' => 'ZZ_hijack', 'duration' => '9:00']), HttpException::class)->status, 'staff cannot edit others');
    $svc->delete($w['staff'], $id);
    eq(null, $w['ta']->find('time_entries', $id));
    $del = $w['ta']->all('events', ['action' => 'time_entry.deleted'])[0];
    eq('ZZ_manual fixed', json_decode((string) $del['before_json'], true)['description'], 'delete keeps the before snapshot');
    $list = $svc->list($w['ownerA'], ['client_id' => (string) $w['c']]);
    eq(1800, $list['total_seconds']);
});

test('tasks & projects: client_id is required on create and edit; references must agree', function (): void {
    $w = workWorld();
    $tasks = new TaskService($w['ta'], $w['perms']);
    $projects = new ProjectService($w['ta'], $w['perms']);
    $n = $w['ta']->count('tasks');
    $e = throws(fn () => $tasks->create($w['ownerA'], ['title' => 'ZZ_no client']), ValidationException::class);
    ok(isset($e->errors['client_id']));
    eq($n, $w['ta']->count('tasks'), 'nothing created');
    ok(isset(throws(fn () => $projects->create($w['ownerA'], ['name' => 'ZZ_no client']), ValidationException::class)->errors['client_id']));
    ok(isset(throws(fn () => $tasks->create($w['ownerA'], ['title' => 'ZZ_x', 'client_id' => '999999']), ValidationException::class)->errors['client_id']), 'unknown client');
    ok(isset(throws(fn () => $tasks->create($w['ownerA'], ['title' => 'ZZ_x', 'client_id' => (string) $w['cb']]), ValidationException::class)->errors['client_id']), 'other tenant client');

    $pid = $projects->create($w['ownerA'], ['name' => 'ZZ_Proj', 'client_id' => (string) $w['c']]);
    ok(isset(throws(fn () => $tasks->create($w['ownerA'], ['title' => 'ZZ_x', 'client_id' => (string) $w['d'], 'project_id' => (string) $pid]), ValidationException::class)->errors['project_id']), 'project of another client');
    $tid = $tasks->create($w['ownerA'], ['title' => 'ZZ_ok', 'client_id' => (string) $w['c'], 'project_id' => (string) $pid, 'priority' => 'high', 'due_at' => '2026-11-01', 'assignee_user_id' => (string) $w['staff']['id'], 'client_visible' => '1']);
    $t = $w['ta']->find('tasks', $tid);
    eq(['todo', 'high', 1, (int) $w['staff']['id']], [$t['status'], $t['priority'], (int) $t['client_visible'], (int) $t['assignee_user_id']]);
    ok(isset(throws(fn () => $tasks->update($w['ownerA'], $tid, ['title' => 'ZZ_ok', 'client_id' => '']), ValidationException::class)->errors['client_id']), 'edit cannot drop the client');
    ok(isset(throws(fn () => $tasks->create($w['ownerA'], ['title' => 'ZZ_x', 'client_id' => (string) $w['c'], 'assignee_user_id' => (string) $w['viewer']['id']]), ValidationException::class)->errors['assignee_user_id']), 'viewer cannot be assignee');
    $tasks->update($w['ownerA'], $tid, ['title' => 'ZZ_ok2', 'client_id' => (string) $w['c'], 'status' => 'doing']);
    $ev = $w['ta']->all('events', ['action' => 'task.updated'])[0];
    eq('todo', json_decode((string) $ev['before_json'], true)['status']);
    eq('doing', json_decode((string) $ev['after_json'], true)['status']);
    $tasks->setArchived($w['ownerA'], $tid, true);
    eq([], array_values(array_filter($tasks->list($w['ownerA']), static fn (array $r): bool => (int) $r['id'] === $tid)), 'archived hidden from list');
    eq(1, count($tasks->list($w['ownerA'], ['archived' => '1'])));
    foreach (['task.created', 'task.archived', 'project.created'] as $a) {
        eq(1, $w['ta']->count('events', ['action' => $a]), "{$a} logged");
    }
});

test('needs linking: suggestions by domain/name are never auto-applied; explicit link is logged', function (): void {
    $w = workWorld();
    $parent = $w['ta']->insert('clients', ['name' => 'ZZ_AlphaParent', 'domain' => 'www.zz-alpha.co.il']);
    $t1 = $w['ta']->insert('tasks', ['title' => 'בניית תיק לקוח ZZ-ALPHA.CO.IL']);
    $t2 = $w['ta']->insert('tasks', ['title' => 'פגישה עם ZZ_Other מחר']);
    $t3 = $w['ta']->insert('tasks', ['title' => 'משהו כללי']);
    $p1 = $w['ta']->insert('projects', ['name' => 'zz-alpha.com']);
    $svc = new LinkingService($w['ta'], $w['perms']);
    $q = $svc->queue($w['ownerA']);
    $byId = array_column($q['tasks'], null, 'id');
    $sug = array_map('intval', array_column($byId[$t1]['suggestions'], 'client_id'));
    sort($sug);
    eq([$w['c'], $parent], $sug, 'domain match suggests both alpha clients');
    eq(100, $byId[$t1]['suggestions'][0]['score']);
    eq($w['d'], $byId[$t2]['suggestions'][0]['client_id'] ?? null, 'name match');
    eq([], $byId[$t3]['suggestions'], 'no suggestion for generic title');
    eq(80, array_column($q['projects'], null, 'id')[$p1]['suggestions'][0]['score'], 'same domain label, other TLD');
    // NEVER auto-applied: computing suggestions changed nothing
    eq(4, $w['ta']->count('tasks', ['client_id' => null]) + $w['ta']->count('projects', ['client_id' => null]));
    eq(0, $w['ta']->count('events', ['action' => 'task.linked']));
    eq(403, throws(fn () => $svc->queue($w['staff']), HttpException::class)->status, 'admin-only queue');
    eq(403, throws(fn () => $svc->link($w['staff'], 'task', $t1, $w['c']), HttpException::class)->status);

    $svc->link($w['ownerA'], 'task', $t1, $parent);
    eq($parent, (int) $w['ta']->find('tasks', $t1)['client_id']);
    $ev = $w['ta']->all('events', ['action' => 'task.linked'])[0];
    $after = json_decode((string) $ev['after_json'], true);
    ok($after['used_suggestion'], 'logged as suggestion accepted');
    eq(null, json_decode((string) $ev['before_json'], true)['client_id']);
    eq(409, throws(fn () => $svc->link($w['ownerA'], 'task', $t1, $w['c']), HttpException::class)->status, 'already linked');
    $svc->link($w['ownerA'], 'project', $p1, $w['d']);
    ok(!json_decode((string) $w['ta']->all('events', ['action' => 'project.linked'])[0]['after_json'], true)['used_suggestion'], 'manual choice logged');
    $card = (new Client360Service($w['ta'], $w['perms']))->build($w['ownerA'], $parent);
    ok(in_array($t1, array_map('intval', array_column($card['tasks'], 'id')), true), 'linked task appears in the client work tab');
    $svcB = new LinkingService($w['tb'], new Permissions($w['tb']));
    eq(404, throws(fn () => $svcB->link($w['ownerB'], 'task', $t2, $w['cb']), HttpException::class)->status, 'tenant isolation');
});

test('recurring: monthly template generation is idempotent per month', function (): void {
    $w = workWorld();
    $svc = new RecurringService($w['ta'], $w['perms']);
    $tpl = $svc->createTemplate($w['ownerA'], ['client_id' => (string) $w['c'], 'title' => 'ZZ_גיבוי חודשי', 'day_of_month' => '5', 'client_visible' => '1']);
    ok(isset(throws(fn () => $svc->createTemplate($w['ownerA'], ['title' => 'ZZ_x']), ValidationException::class)->errors['client_id']), 'template requires client');
    $r1 = $svc->generate('2026-11');
    eq(1, count($r1['created']));
    $task = $w['ta']->find('tasks', $r1['created'][0]);
    eq([$w['c'], '2026-11-05', $tpl, '2026-11', 1], [(int) $task['client_id'], $task['due_at'], (int) $task['recurring_template_id'], $task['recurring_period'], (int) $task['client_visible']]);
    $r2 = $svc->generate('2026-11');
    eq([[], 1], [$r2['created'], $r2['skipped']], 'second run creates nothing');
    eq(1, $w['ta']->count('tasks', ['recurring_template_id' => $tpl]));
    throws(fn () => $w['ta']->insert('tasks', ['client_id' => $w['c'], 'title' => 'dup', 'recurring_template_id' => $tpl, 'recurring_period' => '2026-11']), PDOException::class); // DB guard
    eq(1, count($svc->generate('2026-12')['created']), 'next month creates one');
    $svc->setActive($w['ownerA'], $tpl, false);
    eq(0, count($svc->generate('2027-01')['created']), 'inactive template skipped');
    eq(2, $w['ta']->count('events', ['action' => 'task.created']), 'both generated tasks (Nov, Dec) are logged');
    throws(fn () => $svc->generate('2026-13'), InvalidArgumentException::class);
});

test('viewer: client-visible tasks and time totals only; cannot start timers or edit', function (): void {
    $w = workWorld();
    $tasks = new TaskService($w['ta'], $w['perms']);
    $visible = $tasks->create($w['ownerA'], ['client_id' => (string) $w['c'], 'title' => 'ZZ_visible', 'client_visible' => '1']);
    $internal = $tasks->create($w['ownerA'], ['client_id' => (string) $w['c'], 'title' => 'ZZ_internal']);
    $tasks->create($w['ownerA'], ['client_id' => (string) $w['d'], 'title' => 'ZZ_other client', 'client_visible' => '1']);
    (new TimeEntryService($w['ta'], $w['perms']))->create($w['ownerA'], ['client_id' => (string) $w['c'], 'description' => 'ZZ_SECRET_INTERNAL_NOTE', 'duration' => '2:00']);
    $v = $w['viewer'];

    eq([$visible], array_map('intval', array_column($tasks->list($v), 'id')), 'viewer list = own client-visible only');
    ok(!array_key_exists('assignee_user_id', $tasks->list($v)[0]), 'no internal fields for viewer');
    eq(403, throws(fn () => $tasks->get($v, $internal), HttpException::class)->status);
    eq(403, throws(fn () => $tasks->create($v, ['client_id' => (string) $w['c'], 'title' => 'ZZ_v']), HttpException::class)->status);
    eq(403, throws(fn () => $tasks->update($v, $visible, ['client_id' => (string) $w['c'], 'title' => 'ZZ_v']), HttpException::class)->status);
    eq(403, throws(fn () => (new TimerService($w['ta'], $w['perms']))->start($v, ['client_id' => (string) $w['c']]), HttpException::class)->status, 'viewer cannot start a timer');
    eq(403, throws(fn () => (new TimeEntryService($w['ta'], $w['perms']))->create($v, ['client_id' => (string) $w['c'], 'description' => 'x', 'duration' => '1:00']), HttpException::class)->status);
    eq(0, $w['ta']->count('active_timers'));

    $list = (new TimeEntryService($w['ta'], $w['perms']))->list($v, ['client_id' => (string) $w['d']]);
    eq([], $list['rows'], 'no entry rows for viewer');
    eq(7200, $list['total_seconds'], 'forced to own client totals');
    eq($w['c'], $list['filters']['client_id']);

    $card = (new Client360Service($w['ta'], $w['perms']))->build($v, $w['c']);
    eq([$visible], array_map('intval', array_column($card['tasks'], 'id')), 'card work tab: visible tasks only');
    eq([], $card['time_entries']);
    eq(2.0, $card['metrics']['hours_total'], 'viewer still sees totals');
    $html = (new View(HUBOS_ROOT . '/views'))->render('clients/show', ['card' => $card, 'tab' => 'time', 'canEnrich' => false, 'suggestion' => null, 'registryError' => null]);
    ok(!str_contains($html, 'ZZ_SECRET_INTERNAL_NOTE'), 'no internal description in viewer HTML');
    ok(!str_contains($html, 'client-timer-form'), 'no timer controls for viewer');
    $html = (new View(HUBOS_ROOT . '/views'))->render('clients/show', ['card' => $card, 'tab' => 'work', 'canEnrich' => false, 'suggestion' => null, 'registryError' => null]);
    ok(!str_contains($html, 'ZZ_internal') && str_contains($html, 'ZZ_visible'));
});

test('router: M3/M4 routes are protected with the right permissions', function (): void {
    $r = new Router();
    (require HUBOS_ROOT . '/app/routes.php')($r);
    $perm = static fn (string $m, string $p): ?string => $r->match($m, $p)['opts']['perm'] ?? null;
    eq('time.view', $perm('GET', '/time'));
    eq('time.view', $perm('GET', '/timer'));
    foreach (['/timer/start', '/timer/pause', '/timer/resume', '/timer/stop', '/timer/discard', '/time/entries', '/time/entries/5', '/time/entries/5/delete'] as $p) {
        eq('time.track', $perm('POST', $p), $p);
    }
    eq('time.manage', $perm('POST', '/hours-banks/1/settings'));
    eq('work.view', $perm('GET', '/tasks'));
    eq('work.create', $perm('POST', '/tasks'));
    eq('work.update', $perm('POST', '/tasks/55'));
    eq('work.archive', $perm('POST', '/projects/3/archive'));
    eq('work.link', $perm('GET', '/work/linking'));
    eq('work.link', $perm('POST', '/work/linking/task/55'));
    eq('work.link', $perm('POST', '/work/linking/project/3'));
    eq(404, $r->match('POST', '/work/linking/client/3')['status']);
    $w = workWorld();
    ok(!$w['perms']->can($w['viewer'], 'time.track') && !$w['perms']->can($w['viewer'], 'work.create'));
    ok($w['perms']->can($w['viewer'], 'time.view') && $w['perms']->can($w['viewer'], 'work.view'));
    ok(!$w['perms']->can($w['staff'], 'work.link') && $w['perms']->can($w['staff'], 'time.track'));
});

// ---------------------------------------------------------------- M5: Assets, Inspector & Encrypted Vault

final class ZzMockInspector implements AssetInspector
{
    public function __construct(private array $result)
    {
    }

    public function inspect(string $domainOrHost): array
    {
        return $this->result + ['host' => AssetService::normHost($domainOrHost)];
    }
}

test('assets: CRUD, expiry windows (expired/30d/60d/90d), DNS/SSL/WHOIS inspection & viewer scoping', function (): void {
    $w = workWorld();
    $now = new DateTimeImmutable('2026-10-19 10:00:00', new DateTimeZone('Asia/Jerusalem'));
    $mock = new ZzMockInspector([
        'ip_address'      => '185.199.108.153',
        'nameservers'     => ['ns1.cloudflare.com', 'ns2.cloudflare.com'],
        'mx'              => ['route1.mx.cloudflare.net'],
        'ssl_status'      => 'valid',
        'ssl_expiry_date' => '2027-01-15',
        'ssl_issuer'      => 'Let\'s Encrypt',
        'registrar'       => 'Box Domains Ltd',
        'expiry_date'     => '2027-08-20',
        'inspected_at'    => '2026-10-19T07:00:00Z',
        'errors'          => [],
    ]);
    $svc = new AssetService($w['ta'], $w['perms'], $mock);

    $aExpired = $svc->create($w['ownerA'], ['client_id' => (string) $w['c'], 'kind' => 'domain', 'domain' => 'https://www.ZZ-EXPIRED.co.il/path', 'expiry_date' => '2026-10-01']);
    $a30 = $svc->create($w['ownerA'], ['client_id' => (string) $w['c'], 'kind' => 'domain', 'domain' => 'zz-30d.co.il', 'expiry_date' => '2026-11-05']);
    $a60 = $svc->create($w['ownerA'], ['client_id' => (string) $w['d'], 'kind' => 'hosting', 'domain' => 'zz-60d.co.il', 'expiry_date' => '2026-12-05']);
    $a90 = $svc->create($w['ownerA'], ['client_id' => (string) $w['d'], 'kind' => 'domain', 'domain' => 'zz-90d.co.il', 'expiry_date' => '2027-01-10']);

    $all = $svc->list($w['ownerA'], [], $now);
    eq(4, $all['kpis']['total']);
    eq(1, $all['kpis']['expired']);
    eq(2, $all['kpis']['expiring_30d'], 'expired + <=30d');
    eq(3, $all['kpis']['expiring_60d']);
    eq(4, $all['kpis']['expiring_90d']);
    eq(1, count($svc->list($w['ownerA'], ['window' => 'expired'], $now)['rows']));
    eq(2, count($svc->list($w['ownerA'], ['window' => '30d'], $now)['rows']));

    // Inspect updates IP, SSL, registrar, expiry_date and logs asset.inspected
    $findings = $svc->inspect($w['ownerA'], $aExpired);
    eq('zz-expired.co.il', $findings['host']);
    $got = $svc->get($w['ownerA'], $aExpired, $now);
    eq('185.199.108.153', $got['ip_address']);
    eq('valid', $got['ssl_status']);
    eq('2027-01-15', $got['ssl_expiry_date']);
    eq('Box Domains Ltd', $got['registrar']);
    eq('2027-08-20', $got['expiry_date']);
    eq(['ns1.cloudflare.com', 'ns2.cloudflare.com'], $got['inspection']['nameservers']);
    eq(1, $w['ta']->count('events', ['action' => 'asset.inspected']));

    // Viewer sees only own client's assets and cannot create/update/inspect
    $vRows = $svc->list($w['viewer'], [], $now)['rows'];
    eq(2, count($vRows), 'viewer sees only client C assets');
    eq(403, throws(fn () => $svc->get($w['viewer'], $a60, $now), HttpException::class)->status, 'viewer blocked from client D asset');
    eq(403, throws(fn () => $svc->create($w['viewer'], ['client_id' => (string) $w['c'], 'domain' => 'x.test']), HttpException::class)->status);
    eq(403, throws(fn () => $svc->inspect($w['viewer'], $aExpired), HttpException::class)->status);
});

test('vault: AES-256-GCM create/update/reveal, plaintext never in list/get, reveal logged, viewer strictly blocked', function (): void {
    $w = workWorld();
    $keyHex = bin2hex(random_bytes(32));
    $vaultCrypto = Vault::fromKeyString($keyHex);
    $assets = new AssetService($w['ta'], $w['perms']);
    $aid = $assets->create($w['ownerA'], ['client_id' => (string) $w['c'], 'kind' => 'hosting', 'domain' => 'zz-vault.co.il']);
    $svc = new VaultService($w['ta'], $w['perms'], $vaultCrypto);

    $secretPlain = 'ZZ_TopSecret_P@ssw0rd_999!';
    $vid = $svc->create($w['ownerA'], [
        'client_id' => (string) $w['c'],
        'asset_id'  => (string) $aid,
        'title'     => 'ZZ_cPanel Login',
        'service'   => 'cPanel',
        'url'       => 'https://zz-vault.co.il:2083',
        'username'  => 'zz_root',
        'secret'    => $secretPlain,
        'notes'     => 'ZZ_InternalNote',
    ]);

    $listJson = (string) json_encode($svc->list($w['ownerA']), JSON_UNESCAPED_UNICODE);
    $getJson = (string) json_encode($svc->get($w['ownerA'], $vid), JSON_UNESCAPED_UNICODE);
    ok(!str_contains($listJson, $secretPlain) && !str_contains($listJson, 'secret_ciphertext'), 'list never leaks secret or ciphertext');
    ok(!str_contains($getJson, $secretPlain) && !str_contains($getJson, 'secret_ciphertext'), 'get never leaks secret or ciphertext');

    $rawRow = $w['ta']->find('vault_items', $vid);
    eq('aes-256-gcm', $rawRow['encryption']);
    eq(1, (int) $rawRow['has_secret']);
    ok(!str_contains((string) $rawRow['secret_ciphertext'], $secretPlain), 'DB stores ciphertext only');

    // Explicit reveal decrypts and writes vault.secret_revealed event without the secret
    $revealed = $svc->reveal($w['ownerA'], $vid);
    eq($secretPlain, $revealed);
    $revEvents = $w['ta']->all('events', ['action' => 'vault.secret_revealed']);
    eq(1, count($revEvents));
    ok(!str_contains((string) $revEvents[0]['after_json'], $secretPlain), 'event log never contains plaintext secret');

    // Update secret
    $svc->update($w['ownerA'], $vid, [
        'client_id' => (string) $w['c'],
        'asset_id'  => (string) $aid,
        'title'     => 'ZZ_cPanel Login v2',
        'secret'    => 'ZZ_RotatedSecret_777!',
    ]);
    eq('ZZ_RotatedSecret_777!', $svc->reveal($w['ownerA'], $vid));
    eq(1, $w['ta']->count('events', ['action' => 'vault.updated']));

    // Hard security invariant: client_viewer can NEVER access or reveal vault items, even if granted override!
    $w['perms']->setUserPermission($w['ownerA'], (int) $w['viewer']['id'], 'vault.view', true);
    $w['perms']->setUserPermission($w['ownerA'], (int) $w['viewer']['id'], 'vault.reveal', true);
    ok(!$w['perms']->can($w['viewer'], 'vault.view'), 'client_viewer blocked from vault.view despite override');
    ok(!$w['perms']->can($w['viewer'], 'vault.reveal'), 'client_viewer blocked from vault.reveal despite override');
    eq(403, throws(fn () => $svc->list($w['viewer']), HttpException::class)->status);
    eq(403, throws(fn () => $svc->reveal($w['viewer'], $vid), HttpException::class)->status);
});

// ---------------------------------------------------------------- M6: Accounting Recalculation, CEO Dashboard, Users & Client Portal

test('accounting recalculate: updates DB rows in place (300 proforma_open vs proforma_matched, 305/320/330/400)', function (): void {
    $w = workWorld();
    $cid = $w['c'];
    $id300Open = $w['ta']->insert('finance_documents', ['client_id' => $cid, 'doc_type' => '300', 'doc_kind' => 'transaction_account', 'amount' => 1500, 'counts_as_income' => 1, 'doc_date' => '2026-03-01']);
    $id300Closed = $w['ta']->insert('finance_documents', ['client_id' => $cid, 'doc_type' => '300', 'doc_kind' => 'transaction_account', 'amount' => 800, 'counts_as_income' => 1, 'doc_date' => '2026-03-02']);
    $id305 = $w['ta']->insert('finance_documents', ['client_id' => $cid, 'doc_type' => '305', 'doc_kind' => 'tax_invoice', 'amount' => 800, 'counts_as_income' => 1, 'doc_date' => '2026-03-05']);
    $id400Matched = $w['ta']->insert('finance_documents', ['client_id' => $cid, 'doc_type' => '400', 'doc_kind' => 'receipt', 'amount' => 800, 'counts_as_income' => 1, 'doc_date' => '2026-03-06']);
    $id400Standalone = $w['ta']->insert('finance_documents', ['client_id' => $cid, 'doc_type' => '400', 'doc_kind' => 'receipt', 'amount' => 450, 'counts_as_income' => 0, 'doc_date' => '2026-03-07']);

    $updated = AccountingRule::recalculate($w['pdo'], $w['ta']->id());
    ok($updated >= 3, 'recalculate updated rows whose classification changed');

    $r300Open = $w['ta']->find('finance_documents', $id300Open);
    eq(0, (int) $r300Open['counts_as_income']);
    eq('proforma_open', $r300Open['income_reason']);
    eq(null, $r300Open['matched_document_id']);

    $r300Closed = $w['ta']->find('finance_documents', $id300Closed);
    eq(0, (int) $r300Closed['counts_as_income']);
    eq('proforma_matched', $r300Closed['income_reason']);
    eq($id305, (int) $r300Closed['matched_document_id']);

    $r305 = $w['ta']->find('finance_documents', $id305);
    eq(1, (int) $r305['counts_as_income']);
    eq('invoice', $r305['income_reason']);

    $r400M = $w['ta']->find('finance_documents', $id400Matched);
    eq(0, (int) $r400M['counts_as_income']);
    eq('receipt_matched', $r400M['income_reason']);
    eq($id305, (int) $r300Closed['matched_document_id']);

    $r400S = $w['ta']->find('finance_documents', $id400Standalone);
    eq(1, (int) $r400S['counts_as_income']);
    eq('receipt_unmatched', $r400S['income_reason']);
});

test('dashboard & portal: CEO morning screen 5 sections + Client Portal strict isolation', function (): void {
    $w = workWorld();
    $now = new DateTimeImmutable('2026-10-19 10:00:00', new DateTimeZone('Asia/Jerusalem'));
    $cid = $w['c'];

    // Seed urgent task, unlinked task, expiring asset, overdrawn bank, realized invoice + open 300 demand, time entry, vault secret
    $tasks = new TaskService($w['ta'], $w['perms']);
    $tOverdue = $tasks->create($w['ownerA'], ['client_id' => (string) $cid, 'title' => 'ZZ_OverdueVisible', 'priority' => 'urgent', 'due_at' => '2026-10-01', 'client_visible' => '1']);
    $tInternal = $tasks->create($w['ownerA'], ['client_id' => (string) $cid, 'title' => 'ZZ_InternalSecretTask', 'priority' => 'high', 'due_at' => '2026-10-02', 'client_visible' => '0']);
    $w['ta']->insert('tasks', ['title' => 'ZZ_NeedsLink', 'client_id' => null]);

    (new AssetService($w['ta'], $w['perms']))->create($w['ownerA'], ['client_id' => (string) $cid, 'kind' => 'domain', 'domain' => 'zz-expiring.co.il', 'expiry_date' => '2026-10-25']);
    (new TimeEntryService($w['ta'], $w['perms']))->create($w['ownerA'], ['client_id' => (string) $cid, 'description' => 'ZZ_Work12h', 'duration' => '12:00', 'entry_date' => '2026-10-19']);

    $w['ta']->insert('finance_documents', ['client_id' => $cid, 'doc_type' => '305', 'doc_kind' => 'tax_invoice', 'amount' => 2500, 'counts_as_income' => 1, 'income_reason' => 'invoice', 'doc_date' => '2026-10-10']);
    $w['ta']->insert('finance_documents', ['client_id' => $cid, 'doc_type' => '300', 'doc_kind' => 'transaction_account', 'amount' => 1800, 'counts_as_income' => 0, 'income_reason' => 'proforma_open', 'doc_date' => '2026-10-12']);
    $w['ta']->insert('vault_items', ['client_id' => $cid, 'title' => 'ZZ_RootSSH', 'username' => 'root', 'has_secret' => 1, 'encryption' => 'aes-256-gcm', 'secret_ciphertext' => 'v1:ZZ_CIPHER']);

    $dashSvc = new DashboardService($w['ta'], $w['perms']);
    $ceo = $dashSvc->ceo($w['ownerA'], $now);

    eq(2, count($ceo['urgent']['overdue_tasks']));
    eq(1, $ceo['urgent']['needs_linking_total']);
    eq(1, $ceo['urgent']['open_demands']['count']);
    eq(1800.0, $ceo['urgent']['open_demands']['amount']);
    eq(1, count($ceo['breaking']['assets']));
    eq(1, count($ceo['breaking']['banks']));
    eq(-2.0, (float) $ceo['breaking']['banks'][0]['remaining_hours'], '10h bank minus 12h entry = -2h overdrawn');
    eq(2500.0, $ceo['money']['income_this_year'], 'only 305 counted as realized income, never 300');
    eq(1800.0, $ceo['money']['separation']['proforma_open']['amount']);
    eq(12.0, $ceo['team']['hours_today']);

    // Client Portal for viewer
    $portal = $dashSvc->portal($w['viewer'], $now);
    eq($cid, (int) $portal['client']['id']);
    eq([$tOverdue], array_map('intval', array_column($portal['tasks'], 'id')), 'portal shows only client_visible=1 tasks');
    eq([], $portal['vault'], 'portal never includes vault items');
    $portalHtml = (new View(HUBOS_ROOT . '/views'))->render('dashboard/portal', ['user' => $w['viewer'], 'portal' => $portal]);
    ok(str_contains($portalHtml, 'ZZ_OverdueVisible'));
    ok(!str_contains($portalHtml, 'ZZ_InternalSecretTask'), 'internal task never rendered in portal');
    ok(!str_contains($portalHtml, 'ZZ_RootSSH') && !str_contains($portalHtml, 'ZZ_CIPHER'), 'vault never rendered in portal');
});

test('users & permissions: UserService create/update, last-owner guard, overrides, and M5/M6 routes', function (): void {
    $w = workWorld();
    $uSvc = new UserService($w['pdo'], $w['ta'], $w['perms']);
    $newUid = $uSvc->create($w['ownerA'], [
        'name'      => 'ZZ_AgentUser',
        'email'     => 'zz_agent@hubos.test',
        'role'      => 'agent',
        'password'  => 'zz-agent-pass-1234',
        'client_id' => '',
    ]);
    $list = $uSvc->list($w['ownerA']);
    ok(in_array($newUid, array_map('intval', array_column($list, 'id')), true));

    // Cannot demote or disable the only active owner
    throws(fn () => $uSvc->update($w['ownerA'], (int) $w['ownerA']['id'], ['role' => 'admin']), InvalidArgumentException::class);
    throws(fn () => $uSvc->update($w['ownerA'], (int) $w['ownerA']['id'], ['status' => 'disabled']), InvalidArgumentException::class);

    // client_viewer requires a client_id
    throws(fn () => $uSvc->create($w['ownerA'], [
        'name' => 'ZZ_NoClientViewer', 'email' => 'zz_ncv@hubos.test', 'role' => 'client_viewer', 'password' => 'zz-pass-123456',
    ]), InvalidArgumentException::class);

    // Route table checks for M5 & M6
    $r = new Router();
    (require HUBOS_ROOT . '/app/routes.php')($r);
    $perm = static fn (string $m, string $p): ?string => $r->match($m, $p)['opts']['perm'] ?? null;
    eq('assets.view', $perm('GET', '/assets'));
    eq('assets.create', $perm('POST', '/assets'));
    eq('assets.view', $perm('GET', '/assets/10'));
    eq('assets.update', $perm('POST', '/assets/10'));
    eq('assets.inspect', $perm('POST', '/assets/10/inspect'));
    eq('vault.view', $perm('GET', '/vault'));
    eq('vault.create', $perm('POST', '/vault'));
    eq('vault.view', $perm('GET', '/vault/10'));
    eq('vault.update', $perm('POST', '/vault/10'));
    eq('vault.reveal', $perm('POST', '/vault/10/reveal'));
    eq('users.view', $perm('GET', '/settings/users'));
    eq('users.manage', $perm('POST', '/settings/users'));
    eq('users.manage', $perm('POST', '/settings/users/5'));
    eq('permissions.manage', $perm('POST', '/settings/users/5/permissions'));
    eq('permissions.manage', $perm('POST', '/settings/modules'));
});

// ---------------------------------------------------------------- M7: Digital-CEO Agent API

test('agent api: Bearer token auth (401 without/wrong token, 200 with valid token), morning JSON, client360 zero vault leakage, event log', function (): void {
    $w = family360();
    $token = 'zz_agent_bearer_token_m7_secret_1234567890';
    $svc = new AgentApiService($w['pdo'], ['agent_token' => $token, 'agent_tenant' => 'zz-a']);

    // 401 when missing or wrong token or viewer session
    eq(401, throws(fn () => $svc->authenticate(null, null), HttpException::class)->status);
    eq(401, throws(fn () => $svc->authenticate('Bearer wrong_token_value_1234567890', null), HttpException::class)->status);

    // Valid Bearer token
    $auth = $svc->authenticate('Bearer ' . $token);
    eq($w['ta']->id(), $auth['tenant']->id());
    eq('agent', $auth['user']['role']);

    $status = $svc->status($auth);
    ok($status['ok'] === true && $status['version'] === 'm7');

    // Morning dashboard JSON
    $now = new DateTimeImmutable('2026-10-19 10:00:00', new DateTimeZone('Asia/Jerusalem'));
    $morning = $svc->morning($auth, $now);
    ok($morning['ok'] === true);
    eq(3, $morning['clientsCount']);
    eq(1699.0, (float) $morning['money']['income_this_year']);
    eq(2699.0, (float) $morning['money']['income_total']);
    ok(isset($morning['provenance']['source_sha256']), 'morning JSON includes verified provenance');

    // Clients search
    $search = $svc->clients($auth, 'לבטא');
    ok($search['ok'] === true && $search['count'] === 1);
    eq($w['c'], (int) $search['clients'][0]['id']);

    // Client 360 JSON — verify zero vault secret leakage
    $c360 = $svc->client360($auth, $w['c'], $now);
    ok($c360['ok'] === true);
    eq($w['c'], (int) $c360['client']['id']);
    eq(1, count($c360['vault']));
    $rawJson = (string) json_encode($c360, JSON_UNESCAPED_UNICODE);
    foreach (['ZZ_CIPHERTEXT_LEAK', 'ZZ_SECRET_NOTE_LEAK', 'ZZ_SECRET_JSON_LEAK', 'secret_ciphertext', 'data_json'] as $forbidden) {
        ok(!str_contains($rawJson, $forbidden), "Agent API client360 must never leak {$forbidden}");
    }

    // Record agent event
    $evRes = $svc->recordEvent($auth, ['action' => 'agent.morning_brief', 'client_id' => $w['c'], 'summary' => 'ZZ_Daily digest sent']);
    ok($evRes['ok'] === true && $evRes['event_id'] > 0);
    eq(1, $w['ta']->count('events', ['action' => 'agent.morning_brief']));
});

test('data provenance: D1Importer & SyncService record sync_runs, exact timestamp, source_sha256, and sync.completed event', function (): void {
    [$pdo, $s] = runImport();
    $t = new Tenant($pdo, (int) $s['tenant_id']);
    $perms = new Permissions($t);
    $expectedSha = (string) hash_file('sha256', HUBOS_ROOT . '/tests/fixtures/d1_mini.sql');

    eq($expectedSha, $s['source_sha256']);
    eq(1, $t->count('sync_runs'));
    eq(1, $t->count('events', ['action' => 'sync.completed']));

    $syncSvc = new \HubOS\Modules\Import\SyncService($t, $perms, $pdo);
    $prov = $syncSvc->latestProvenance(new DateTimeImmutable('2026-10-19 14:30:00', new DateTimeZone('Asia/Jerusalem')));
    ok($prov['verified'] === true);
    eq('Cloudflare D1 (hub-os-d1)', $prov['source_label']);
    eq($expectedSha, $prov['source_sha256']);
    eq(substr($expectedSha, 0, 12), $prov['sha256_short']);
    eq(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $prov['fetched_at_il']), 'exact YYYY-MM-DD HH:MM:SS format');

    $owner = userRow($pdo, Provisioning::createUser($pdo, $t->id(), 'zz_prov_owner@hubos.test', 'ZZ_ProvOwner', 'owner', 'zz-password-123P'));
    $cid = (int) $t->all('clients')[0]['id'];
    $viewer = userRow($pdo, Provisioning::createUser($pdo, $t->id(), 'zz_prov_viewer@hubos.test', 'ZZ_ProvViewer', 'client_viewer', 'zz-password-123V', null, $cid));

    eq(403, throws(fn () => $syncSvc->syncNow($viewer), HttpException::class)->status, 'viewer cannot trigger sync');

    $nowSync = new DateTimeImmutable('2026-10-19 15:00:00', new DateTimeZone('Asia/Jerusalem'));
    $afterSync = $syncSvc->syncNow($owner, null, $nowSync);
    eq('2026-10-19 15:00:00', $afterSync['fetched_at_il']);
    eq(2, $t->count('sync_runs'));
    eq(2, $t->count('events', ['action' => 'sync.completed']));
});

// ---------------------------------------------------------------- Run

$pass = 0;
$fail = 0;
$start = microtime(true);
foreach (T::$tests as $name => $fn) {
    try {
        $fn();
        $pass++;
        fwrite(STDOUT, "  \033[32m✔\033[0m {$name}\n");
    } catch (Throwable $e) {
        $fail++;
        fwrite(STDOUT, "  \033[31m✘\033[0m {$name}\n      " . $e::class . ': ' . $e->getMessage()
            . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    }
}
foreach (T::$tmp as $path) {
    foreach (['', '-wal', '-shm'] as $suffix) {
        if (is_file($path . $suffix)) {
            unlink($path . $suffix);
        }
    }
}
fwrite(STDOUT, sprintf("\n%d passed, %d failed, %d assertions (%.2fs)\n", $pass, $fail, T::$assertions, microtime(true) - $start));
exit($fail === 0 ? 0 : 1);
