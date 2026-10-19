<?php

declare(strict_types=1);

namespace HubOS\Core;

use InvalidArgumentException;

/**
 * Authorization: tenant module switch -> per-user override -> role default.
 * Every change to modules/permissions is written to events.
 */
final class Permissions
{
    public const ROLES = ['owner', 'admin', 'staff', 'agent', 'client_viewer'];

    public const ROLE_LABELS = [
        'owner'         => 'בעלים',
        'admin'         => 'מנהל',
        'staff'         => 'צוות',
        'agent'         => 'סוכן',
        'client_viewer' => 'צופה (לקוח)',
    ];

    private const ROLE_PERMISSIONS = [
        'owner'         => ['*'],
        'admin'         => ['dashboard.view', 'clients.view', 'clients.create', 'clients.update', 'clients.archive',
                            'events.view', 'users.view', 'users.manage', 'permissions.manage',
                            'time.view', 'time.track', 'time.manage',
                            'work.view', 'work.create', 'work.update', 'work.archive', 'work.link'],
        'staff'         => ['dashboard.view', 'clients.view', 'clients.create', 'clients.update', 'events.view',
                            'time.view', 'time.track', 'work.view', 'work.create', 'work.update', 'work.archive'],
        'agent'         => ['dashboard.view', 'clients.view', 'clients.create', 'clients.update',
                            'time.view', 'time.track', 'work.view', 'work.create', 'work.update'],
        // Read-only, scoped to the linked client (client-visible tasks + time totals only).
        'client_viewer' => ['dashboard.view', 'clients.view', 'time.view', 'work.view'],
    ];

    /** Modules that are always on (not switchable per tenant). */
    private const CORE_MODULES = ['dashboard', 'events', 'users', 'permissions'];

    public function __construct(private Tenant $tenant)
    {
    }

    /** @param array<string, mixed> $user */
    public function can(array $user, string $permission): bool
    {
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $permission) !== 1) {
            return false;
        }
        if ((int) ($user['tenant_id'] ?? 0) !== $this->tenant->id() || ($user['status'] ?? '') !== 'active') {
            return false;
        }
        $module = substr($permission, 0, (int) strpos($permission, '.'));
        if (!in_array($module, self::CORE_MODULES, true) && !$this->moduleEnabled($module)) {
            return false;
        }

        $override = $this->tenant->selectOne(
            'SELECT granted FROM user_permissions WHERE tenant_id = :tenant_id AND user_id = :uid AND permission = :perm',
            ['uid' => (int) ($user['id'] ?? 0), 'perm' => $permission]
        );
        if ($override !== null) {
            return (int) $override['granted'] === 1;
        }

        $perms = self::ROLE_PERMISSIONS[$user['role'] ?? ''] ?? [];
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /** @param array<string, mixed> $user */
    public function authorize(array $user, string $permission): void
    {
        if (!$this->can($user, $permission)) {
            throw new HttpException(403, 'אין הרשאה לפעולה זו');
        }
    }

    public static function isWrite(string $permission): bool
    {
        return !str_ends_with($permission, '.view');
    }

    public function moduleEnabled(string $module): bool
    {
        $row = $this->tenant->selectOne(
            'SELECT enabled FROM tenant_modules WHERE tenant_id = :tenant_id AND module = :module',
            ['module' => $module]
        );
        return $row !== null && (int) $row['enabled'] === 1;
    }

    /** @param array<string, mixed> $actor */
    public function setModule(array $actor, string $module, bool $enabled): void
    {
        $this->authorize($actor, 'permissions.manage');
        if (preg_match('/^[a-z_]{2,40}$/', $module) !== 1 || in_array($module, self::CORE_MODULES, true)) {
            throw new InvalidArgumentException('Invalid module');
        }
        $this->tenant->transaction(function (Tenant $t) use ($actor, $module, $enabled): void {
            $before = $this->moduleEnabled($module);
            $exists = $t->count('tenant_modules', ['module' => $module]) > 0;
            if ($exists) {
                $t->updateWhere('tenant_modules', [
                    'enabled'    => $enabled ? 1 : 0,
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ], ['module' => $module]);
            } else {
                $t->insert('tenant_modules', ['module' => $module, 'enabled' => $enabled ? 1 : 0]);
            }
            Events::record($t, (int) $actor['id'], 'tenant_module', null, null,
                $enabled ? 'module.enabled' : 'module.disabled',
                ['module' => $module, 'enabled' => $before], ['module' => $module, 'enabled' => $enabled]);
        });
    }

    /**
     * Grant (true), revoke (false) or clear (null) a per-user override.
     *
     * @param array<string, mixed> $actor
     */
    public function setUserPermission(array $actor, int $userId, string $permission, ?bool $granted): void
    {
        $this->authorize($actor, 'permissions.manage');
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $permission) !== 1) {
            throw new InvalidArgumentException('Invalid permission');
        }
        $target = $this->tenant->find('users', $userId);
        if ($target === null) {
            throw new HttpException(404, 'משתמש לא נמצא');
        }
        if ($target['role'] === 'owner' && ($actor['role'] ?? '') !== 'owner') {
            throw new HttpException(403, 'רק בעלים יכול לשנות הרשאות של בעלים');
        }

        $this->tenant->transaction(function (Tenant $t) use ($actor, $userId, $permission, $granted, $target): void {
            $row = $t->selectOne(
                'SELECT granted FROM user_permissions WHERE tenant_id = :tenant_id AND user_id = :uid AND permission = :perm',
                ['uid' => $userId, 'perm' => $permission]
            );
            $before = $row === null ? null : ((int) $row['granted'] === 1);
            $t->deleteWhere('user_permissions', ['user_id' => $userId, 'permission' => $permission]);
            if ($granted !== null) {
                $t->insert('user_permissions', ['user_id' => $userId, 'permission' => $permission, 'granted' => $granted ? 1 : 0]);
            }
            $action = $granted === null ? 'permission.cleared' : ($granted ? 'permission.granted' : 'permission.revoked');
            Events::record($t, (int) $actor['id'], 'user', $userId, $target['client_id'] !== null ? (int) $target['client_id'] : null,
                $action, ['permission' => $permission, 'granted' => $before], ['permission' => $permission, 'granted' => $granted]);
        });
    }
}
