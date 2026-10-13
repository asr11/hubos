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
use HubOS\Core\HttpException;
use HubOS\Core\Migrator;
use HubOS\Core\Permissions;
use HubOS\Core\Provisioning;
use HubOS\Core\Router;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Core\ValidationException;
use HubOS\Core\View;
use HubOS\Modules\Clients\ClientService;

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
