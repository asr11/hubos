<?php

declare(strict_types=1);

namespace HubOS\Core;

use InvalidArgumentException;
use PDO;

/**
 * Tenant and user provisioning (CLI + tests). This is the only place that
 * writes to the global `tenants` table.
 */
final class Provisioning
{
    public const DEFAULT_MODULES = ['clients', 'time', 'work'];
    public const MIN_PASSWORD = 12;

    public static function ensureTenant(PDO $pdo, string $slug, string $name): int
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/', $slug) !== 1) {
            throw new InvalidArgumentException('Tenant slug must be 2-64 chars: a-z, 0-9, -');
        }
        $id = DB::run($pdo, 'SELECT id FROM tenants WHERE slug = ?', [$slug])->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $name = Str::clean($name);
        if ($name === '' || Str::len($name) > 200) {
            throw new InvalidArgumentException('Tenant name is required (max 200 chars)');
        }
        DB::run($pdo, 'INSERT INTO tenants (slug, name) VALUES (?, ?)', [$slug, $name]);
        $tenantId = (int) $pdo->lastInsertId();
        $tenant = new Tenant($pdo, $tenantId);
        foreach (self::DEFAULT_MODULES as $module) {
            $tenant->insert('tenant_modules', ['module' => $module, 'enabled' => 1]);
        }
        Events::record($tenant, null, 'tenant', $tenantId, null, 'tenant.created', null,
            ['slug' => $slug, 'name' => $name, 'modules' => self::DEFAULT_MODULES]);
        return $tenantId;
    }

    public static function createUser(
        PDO $pdo,
        int $tenantId,
        string $email,
        string $name,
        string $role,
        string $password,
        ?int $actorUserId = null,
        ?int $clientId = null,
    ): int {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email');
        }
        $name = Str::clean($name);
        if ($name === '' || Str::len($name) > 120) {
            throw new InvalidArgumentException('Name is required (max 120 chars)');
        }
        if (!in_array($role, Permissions::ROLES, true)) {
            throw new InvalidArgumentException('Invalid role');
        }
        if (strlen($password) < self::MIN_PASSWORD || strlen($password) > 1024) {
            throw new InvalidArgumentException('Password must be at least ' . self::MIN_PASSWORD . ' characters');
        }
        $tenant = new Tenant($pdo, $tenantId);
        if ($clientId !== null && $tenant->find('clients', $clientId) === null) {
            throw new InvalidArgumentException('Client does not belong to this tenant');
        }

        return $tenant->transaction(static function (Tenant $t) use ($email, $name, $role, $password, $actorUserId, $clientId): int {
            $id = $t->insert('users', [
                'email'         => $email,
                'name'          => $name,
                'role'          => $role,
                'client_id'     => $clientId,
                'password_hash' => Auth::hashPassword($password),
            ]);
            Events::record($t, $actorUserId, 'user', $id, $clientId, 'user.created', null,
                ['email' => $email, 'name' => $name, 'role' => $role, 'client_id' => $clientId]);
            return $id;
        });
    }
}
