<?php

declare(strict_types=1);

namespace HubOS\Modules\Work;

use HubOS\Core\Events;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Core\ValidationException;

/** Projects. client_id is REQUIRED on create/edit. Viewers: client-visible projects of their client only. */
final class ProjectService
{
    public const STATUSES = ['active' => 'פעיל', 'paused' => 'מושהה', 'done' => 'הסתיים'];

    public function __construct(private Tenant $tenant, private Permissions $perms)
    {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters status, client_id, archived
     * @return list<array<string, mixed>>
     */
    public function list(array $user, array $filters = []): array
    {
        $this->perms->authorize($user, 'work.view');
        $where = ['p.tenant_id = :tenant_id'];
        $params = [];
        if (($user['role'] ?? '') === 'client_viewer') {
            $where[] = 'p.client_id = :vcid AND p.client_visible = 1 AND p.archived_at IS NULL';
            $params['vcid'] = $user['client_id'] !== null ? (int) $user['client_id'] : -1;
        } else {
            $where[] = (($filters['archived'] ?? '') === '1') ? 'p.archived_at IS NOT NULL' : 'p.archived_at IS NULL';
            if (($cid = WorkRefs::intOrNull($filters['client_id'] ?? null)) !== null) {
                $where[] = 'p.client_id = :cid';
                $params['cid'] = $cid;
            }
        }
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : '';
        if (isset(self::STATUSES[$status])) {
            $where[] = 'p.status = :st';
            $params['st'] = $status;
        }
        return $this->tenant->select(
            "SELECT p.id, p.client_id, p.name, p.description, p.status, p.client_visible, p.archived_at, p.created_at, p.updated_at,
                    c.name AS client_name,
                    (SELECT COUNT(*) FROM tasks t WHERE t.tenant_id = p.tenant_id AND t.project_id = p.id AND t.archived_at IS NULL) AS task_count
               FROM projects p
          LEFT JOIN clients c ON c.tenant_id = p.tenant_id AND c.id = p.client_id
              WHERE " . implode(' AND ', $where) . "
           ORDER BY p.status = 'done', p.name ASC LIMIT 1000",
            $params
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function get(array $user, int $id): array
    {
        $this->perms->authorize($user, 'work.view');
        $p = $this->tenant->find('projects', $id);
        if ($p === null) {
            throw new HttpException(404, 'הפרויקט לא נמצא');
        }
        if (($user['role'] ?? '') === 'client_viewer'
            && ((int) ($p['client_id'] ?? 0) !== (int) ($user['client_id'] ?? -1) || (int) $p['client_visible'] !== 1 || $p['archived_at'] !== null)) {
            throw new HttpException(403, 'אין הרשאה לצפות בפרויקט זה');
        }
        return WorkRefs::strip($p);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     */
    public function create(array $user, array $input): int
    {
        $this->perms->authorize($user, 'work.create');
        $data = $this->validate($input);
        return $this->tenant->transaction(function (Tenant $t) use ($user, $data): int {
            $id = $t->insert('projects', $data);
            Events::record($t, (int) $user['id'], 'project', $id, (int) $data['client_id'], 'project.created', null, WorkRefs::strip((array) $t->find('projects', $id)));
            return $id;
        });
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     */
    public function update(array $user, int $id, array $input): void
    {
        $this->perms->authorize($user, 'work.update');
        $before = $this->tenant->find('projects', $id);
        if ($before === null) {
            throw new HttpException(404, 'הפרויקט לא נמצא');
        }
        $data = $this->validate($input) + ['updated_at' => gmdate('Y-m-d\TH:i:s\Z')];
        $this->tenant->transaction(function (Tenant $t) use ($user, $id, $before, $data): void {
            $t->update('projects', $id, $data);
            Events::record($t, (int) $user['id'], 'project', $id, (int) $data['client_id'], 'project.updated',
                WorkRefs::strip($before), WorkRefs::strip((array) $t->find('projects', $id)));
        });
    }

    /** @param array<string, mixed> $user */
    public function setArchived(array $user, int $id, bool $archived): void
    {
        $this->perms->authorize($user, 'work.archive');
        $p = $this->tenant->find('projects', $id);
        if ($p === null) {
            throw new HttpException(404, 'הפרויקט לא נמצא');
        }
        $value = $archived ? gmdate('Y-m-d\TH:i:s\Z') : null;
        $this->tenant->transaction(function (Tenant $t) use ($user, $id, $p, $value, $archived): void {
            $t->update('projects', $id, ['archived_at' => $value, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
            Events::record($t, (int) $user['id'], 'project', $id, $p['client_id'] !== null ? (int) $p['client_id'] : null,
                $archived ? 'project.archived' : 'project.unarchived', ['archived_at' => $p['archived_at']], ['archived_at' => $value]);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $errors = [];
        $cid = WorkRefs::intOrNull($input['client_id'] ?? null);
        $client = $cid !== null ? $this->tenant->find('clients', $cid) : null;
        if ($client === null) {
            $errors['client_id'] = 'חובה לשייך פרויקט ללקוח';
        }
        $name = Str::clean(is_string($input['name'] ?? null) ? $input['name'] : '');
        if ($name === '' || Str::len($name) > 200) {
            $errors['name'] = 'שם פרויקט הוא שדה חובה (עד 200 תווים)';
        }
        $status = is_string($input['status'] ?? null) && $input['status'] !== '' ? $input['status'] : 'active';
        if (!isset(self::STATUSES[$status])) {
            $errors['status'] = 'סטטוס לא תקין';
        }
        $description = trim(is_string($input['description'] ?? null) ? $input['description'] : '');
        if (Str::len($description) > 5000) {
            $errors['description'] = 'תיאור ארוך מדי';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'client_id'      => (int) $cid,
            'name'           => $name,
            'status'         => $status,
            'description'    => $description === '' ? null : $description,
            'client_visible' => in_array($input['client_visible'] ?? null, ['1', 1, true], true) ? 1 : 0,
        ];
    }
}
