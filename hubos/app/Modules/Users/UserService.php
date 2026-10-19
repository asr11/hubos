<?php

declare(strict_types=1);

namespace HubOS\Modules\Users;

use HubOS\Core\Auth;
use HubOS\Core\Events;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Provisioning;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use InvalidArgumentException;
use PDO;

/**
 * M6 — Users, Roles, Per-User Permission Overrides & Tenant Module Switches.
 * Every change is written to the append-only `events` table.
 */
final class UserService
{
    public const STATUSES = ['active' => 'פעיל', 'disabled' => 'מושבת'];

    public function __construct(
        private PDO $pdo,
        private Tenant $tenant,
        private Permissions $perms,
    ) {
    }

    /**
     * @param array<string, mixed> $actor
     * @return list<array<string, mixed>>
     */
    public function list(array $actor): array
    {
        $this->perms->authorize($actor, 'users.view');
        $users = $this->tenant->select(
            'SELECT u.id, u.tenant_id, u.client_id, u.email, u.name, u.role, u.status, u.last_login_at, u.created_at, u.updated_at,
                    c.name AS client_name
             FROM users u
             LEFT JOIN clients c ON c.tenant_id = u.tenant_id AND c.id = u.client_id
             WHERE u.tenant_id = :tenant_id
             ORDER BY CASE u.role WHEN \'owner\' THEN 0 WHEN \'admin\' THEN 1 WHEN \'staff\' THEN 2 WHEN \'agent\' THEN 3 ELSE 4 END, u.name ASC'
        );
        $overrideRows = $this->tenant->select(
            'SELECT user_id, permission, granted FROM user_permissions WHERE tenant_id = :tenant_id'
        );
        $byUser = [];
        foreach ($overrideRows as $r) {
            $byUser[(int) $r['user_id']][(string) $r['permission']] = (int) $r['granted'] === 1;
        }
        foreach ($users as &$u) {
            $u['overrides'] = $byUser[(int) $u['id']] ?? [];
        }
        unset($u);
        return $users;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function create(array $actor, array $input): int
    {
        $this->perms->authorize($actor, 'users.manage');
        $role = Str::clean((string) ($input['role'] ?? 'staff'));
        if (!in_array($role, Permissions::ROLES, true)) {
            throw new InvalidArgumentException('תפקיד לא תקין');
        }
        if ($role === 'owner' && ($actor['role'] ?? '') !== 'owner') {
            throw new HttpException(403, 'רק בעלים יכול ליצור משתמש בעלים');
        }
        $clientId = isset($input['client_id']) && $input['client_id'] !== '' ? (int) $input['client_id'] : null;
        if ($role === 'client_viewer' && ($clientId === null || $clientId <= 0)) {
            throw new InvalidArgumentException('משתמש צופה (לקוח) חייב להיות משויך ללקוח');
        }
        return Provisioning::createUser(
            $this->pdo,
            $this->tenant->id(),
            (string) ($input['email'] ?? ''),
            (string) ($input['name'] ?? ''),
            $role,
            (string) ($input['password'] ?? ''),
            (int) $actor['id'],
            $clientId
        );
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function update(array $actor, int $userId, array $input): void
    {
        $this->perms->authorize($actor, 'users.manage');
        $before = $this->tenant->find('users', $userId);
        if ($before === null) {
            throw new HttpException(404, 'משתמש לא נמצא');
        }
        if ($before['role'] === 'owner' && ($actor['role'] ?? '') !== 'owner') {
            throw new HttpException(403, 'רק בעלים יכול לערוך משתמש בעלים');
        }

        $name = array_key_exists('name', $input) ? Str::clean((string) $input['name']) : (string) $before['name'];
        if ($name === '' || Str::len($name) > 120) {
            throw new InvalidArgumentException('שם המשתמש הוא שדה חובה (עד 120 תווים)');
        }
        $role = array_key_exists('role', $input) ? Str::clean((string) $input['role']) : (string) $before['role'];
        if (!in_array($role, Permissions::ROLES, true)) {
            throw new InvalidArgumentException('תפקיד לא תקין');
        }
        if ($role === 'owner' && ($actor['role'] ?? '') !== 'owner') {
            throw new HttpException(403, 'רק בעלים יכול להעניק תפקיד בעלים');
        }
        $status = array_key_exists('status', $input) ? Str::clean((string) $input['status']) : (string) $before['status'];
        if (!isset(self::STATUSES[$status])) {
            throw new InvalidArgumentException('סטטוס משתמש לא תקין');
        }

        $clientId = array_key_exists('client_id', $input)
            ? ($input['client_id'] !== null && $input['client_id'] !== '' ? (int) $input['client_id'] : null)
            : ($before['client_id'] !== null ? (int) $before['client_id'] : null);
        if ($clientId !== null && $this->tenant->find('clients', $clientId) === null) {
            throw new InvalidArgumentException('לקוח לא נמצא');
        }
        if ($role === 'client_viewer' && ($clientId === null || $clientId <= 0)) {
            throw new InvalidArgumentException('משתמש צופה (לקוח) חייב להיות משויך ללקוח');
        }

        if ($before['role'] === 'owner' && ($role !== 'owner' || $status !== 'active')) {
            $activeOwners = (int) ($this->tenant->selectOne(
                "SELECT COUNT(*) AS n FROM users WHERE tenant_id = :tenant_id AND role = 'owner' AND status = 'active' AND id != :uid",
                ['uid' => $userId]
            )['n'] ?? 0);
            if ($activeOwners === 0) {
                throw new InvalidArgumentException('לא ניתן להשבית או לשנות תפקיד לבעלים הפעיל היחיד במערכת');
            }
        }

        $update = [
            'name'       => $name,
            'role'       => $role,
            'status'     => $status,
            'client_id'  => $clientId,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $passwordChanged = false;
        if (!empty($input['password'])) {
            $pw = (string) $input['password'];
            if (strlen($pw) < Provisioning::MIN_PASSWORD || strlen($pw) > 1024) {
                throw new InvalidArgumentException('הסיסמה חייבת להכיל לפחות ' . Provisioning::MIN_PASSWORD . ' תווים');
            }
            $update['password_hash'] = Auth::hashPassword($pw);
            $passwordChanged = true;
        }

        $this->tenant->transaction(function (Tenant $t) use ($actor, $userId, $before, $update, $clientId, $passwordChanged): void {
            $t->update('users', $userId, $update);
            $safeBefore = [
                'name'      => $before['name'],
                'role'      => $before['role'],
                'status'    => $before['status'],
                'client_id' => $before['client_id'],
            ];
            $safeAfter = [
                'name'             => $update['name'],
                'role'             => $update['role'],
                'status'           => $update['status'],
                'client_id'        => $update['client_id'],
                'password_changed' => $passwordChanged,
            ];
            Events::record($t, (int) $actor['id'], 'user', $userId, $clientId, 'user.updated', $safeBefore, $safeAfter);
        });
    }

    /**
     * @param array<string, mixed> $actor
     * @return list<array{module: string, label: string, enabled: bool}>
     */
    public function modulesStatus(array $actor): array
    {
        $this->perms->authorize($actor, 'users.view');
        $out = [];
        foreach (Permissions::SWITCHABLE_MODULES as $mod => $label) {
            $out[] = [
                'module'  => $mod,
                'label'   => $label,
                'enabled' => $this->perms->moduleEnabled($mod),
            ];
        }
        return $out;
    }
}
