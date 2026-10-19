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
use HubOS\Modules\Clients\Client360Service;
use HubOS\Modules\Clients\ClientService;
use HubOS\Modules\Clients\CompanyRegistry;
use HubOS\Modules\Import\AccountingRule;
use HubOS\Modules\Import\ClientResolver;
use HubOS\Modules\Import\D1Importer;

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
        $doc('ta300', '300', 300.0, 'c:3', '2025-03-01'),
        $doc('tir320', '320', 200.0, 'c:3', '2025-03-02'),
        ['key' => 'unknown330', 'kind' => AccountingRule::kindForCode('330'), 'amount' => 10.0, 'client_key' => 'c:3', 'date' => '2025-03-03'],
    ]);
    eq(true, $r['inv305']['counts'], '305 tax invoice is income');
    eq(false, $r['rc_match']['counts'], 'receipt with matching invoice (same client+amount) is NOT income');
    eq('inv305', $r['rc_match']['matched']);
    eq(true, $r['rc_second']['counts'], 'second receipt has no free invoice => income');
    eq(true, $r['rc_other_amount']['counts'], 'different amount => no match => income');
    eq(true, $r['rc_other_client']['counts'], 'different client => no match => income');
    eq(true, $r['rc_no_client']['counts'], 'receipt without client cannot be matched => income');
    eq(true, $r['ta300']['counts'], '300 is income');
    eq(true, $r['tir320']['counts'], '320 (tax invoice/receipt, per data) is income');
    eq(false, $r['unknown330']['counts'], 'unmapped code is flagged, not counted');
    eq('unknown_type', $r['unknown330']['reason']);
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
        if (in_array($table, ['events', 'users', 'tenant_modules', 'tenant_settings', 'user_permissions'], true)) {
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
    eq('320', $fd('zz-api')['doc_type'], 'explicit code from invoices collection wins over inferred label');
    eq($cid('gi_zz_child'), (int) $fd('zz-api')['client_id']);
    eq('name_exact', $fd('zz-name')['link_method']);
    eq('kind', $fd('zz-name')['doc_type_source']);
    eq(['2025' => 2840.0, '2026' => 641.0], array_map('floatval', $s['finance']['income_by_year']));

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
    $p = $ta->insert('clients', ['name' => 'ZZ_Parent אוונגרד', 'tax_id' => '513028068']);
    $c = $ta->insert('clients', ['name' => 'ZZ_Child קבוצת מנו ספנות', 'tax_id' => '513028068', 'parent_client_id' => $p]);
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
    $ta->insert('client_contacts', ['client_id' => $c, 'name' => 'ZZ_כפיר', 'is_primary' => 1]);
    $ta->insert('assets', ['client_id' => $c, 'kind' => 'hosting', 'domain' => 'zz-av-re.test']);
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
    ok(in_array('ZZ_Parent אוונגרד', array_column($child['finance'], 'origin_name'), true));
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
    eq('מנו', HebrewText::stripPrefix('למנו'));
    eq(null, HebrewText::stripPrefix('מנו'), 'no strip when < 3 letters remain');
    eq(null, HebrewText::stripPrefix('abc'));
    ok(HebrewText::score('מנו', 'קבוצת מנו ספנות') > 0);
    ok(HebrewText::score('למנו', 'קבוצת מנו ספנות') > 0, 'query prefix ל stripped');
    ok(HebrewText::score('הספנות', 'קבוצת מנו ספנות') > 0, 'query prefix ה stripped');
    ok(HebrewText::score('ספנות', 'קבוצת מנו והספנות') > 0, 'haystack word prefix stripped');
    ok(HebrewText::score('מנו ספנ', 'קבוצת מנו ספנות') > 0, 'all tokens must match (prefix)');
    eq(0, HebrewText::score('מנו רכב', 'קבוצת מנו ספנות'), 'every token must match');
    eq(0, HebrewText::score('', 'x'));
    ok(HebrewText::score('מנו', 'קבוצת מנו ספנות') > HebrewText::score('מנו', 'מנוהלים'), 'exact word outranks prefix');

    $w = family360();
    $cs = new ClientService($w['ta'], new Permissions($w['ta']));
    foreach (['מנו', 'למנו', 'הספנות', 'ספנות', '513028068'] as $q) {
        ok(in_array($w['c'], array_map('intval', array_column($cs->search($w['ownerA'], $q), 'id')), true), "search '{$q}' finds the Mano client");
    }
    eq($w['c'], (int) $cs->search($w['ownerA'], 'מנו')[0]['id'], 'best match first');
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
        ['מספר חברה' => 513028069, 'שם חברה' => 'ZZ_אחרת בע~מ'],
        ['מספר חברה' => 513028068, 'שם חברה' => 'ZZ_אוונגרד רזידנס בע~מ', 'שם באנגלית' => 'ZZ AVANTGARDE', 'סטטוס חברה' => 'פעילה',
         'סוג תאגיד' => 'ישראלית חברה פרטית', 'תאריך התאגדות' => '31/10/2000', 'שם עיר' => 'חיפה', 'שם רחוב' => 'פלי~ם', 'מספר בית' => '2', 'מיקוד' => 3309502],
    ]]], JSON_UNESCAPED_UNICODE);
    $http = new ZzMockHttp(['status' => 200, 'body' => (string) $body]);
    $reg = new CompanyRegistry($http, 'https://registry.invalid/api?resource_id=x');
    $r = $reg->lookup('51-302806-8');
    ok($r['ok'], 'lookup ok');
    eq(1, count($http->urls));
    ok(str_contains($http->urls[0], '&q=513028068'), 'tax id sent as q');
    eq('ZZ_אוונגרד רזידנס בע"מ', $r['suggestion']['name'], 'exact tax-id record chosen; ~ decoded');
    eq('פלי"ם 2', $r['suggestion']['address']);
    eq('חיפה', $r['suggestion']['city']);
    eq('2000-10-31', $r['suggestion']['incorporation_date']);
    eq('פעילה', $r['suggestion']['status']);

    $fail = (new CompanyRegistry(new ZzMockHttp(new HttpClientException('down'))))->lookup('513028068');
    ok(!$fail['ok'] && str_contains($fail['error'], 'לא ניתן להתחבר'), 'network failure is graceful');
    ok(!(new CompanyRegistry(new ZzMockHttp(['status' => 503, 'body' => ''])))->lookup('513028068')['ok']);
    ok(!(new CompanyRegistry(new ZzMockHttp(['status' => 200, 'body' => 'not json'])))->lookup('513028068')['ok']);
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
