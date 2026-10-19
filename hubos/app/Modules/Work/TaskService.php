<?php

declare(strict_types=1);

namespace HubOS\Modules\Work;

use HubOS\Core\Events;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Core\ValidationException;

/**
 * Tasks. client_id is REQUIRED on every create/edit. client_viewer sees only
 * client-visible, non-archived tasks of its own client and cannot write.
 */
final class TaskService
{
    public const STATUSES = ['todo' => 'לביצוע', 'doing' => 'בתהליך', 'done' => 'בוצע'];
    /** Imported legacy values shown with the matching label. */
    public const STATUS_ALIASES = ['in_progress' => 'doing'];
    public const PRIORITIES = ['low' => 'נמוכה', 'normal' => 'רגילה', 'high' => 'גבוהה', 'urgent' => 'דחופה'];

    public function __construct(private Tenant $tenant, private Permissions $perms)
    {
    }

    public static function statusKey(string $status): string
    {
        return self::STATUS_ALIASES[$status] ?? $status;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters status, client_id, q, archived, assignee_user_id
     * @return list<array<string, mixed>>
     */
    public function list(array $user, array $filters = []): array
    {
        $this->perms->authorize($user, 'work.view');
        $where = ['t.tenant_id = :tenant_id'];
        $params = [];
        $viewer = ($user['role'] ?? '') === 'client_viewer';
        if ($viewer) {
            $where[] = 't.client_id = :vcid AND t.client_visible = 1 AND t.archived_at IS NULL';
            $params['vcid'] = $user['client_id'] !== null ? (int) $user['client_id'] : -1;
        } else {
            $where[] = (($filters['archived'] ?? '') === '1') ? 't.archived_at IS NOT NULL' : 't.archived_at IS NULL';
            if (($cid = WorkRefs::intOrNull($filters['client_id'] ?? null)) !== null) {
                $where[] = 't.client_id = :cid';
                $params['cid'] = $cid;
            }
            if (($aid = WorkRefs::intOrNull($filters['assignee_user_id'] ?? null)) !== null) {
                $where[] = 't.assignee_user_id = :aid';
                $params['aid'] = $aid;
            }
        }
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : '';
        if (isset(self::STATUSES[$status])) {
            $where[] = $status === 'doing' ? "t.status IN ('doing', 'in_progress')" : 't.status = :st';
            if ($status !== 'doing') {
                $params['st'] = $status;
            }
        }
        $rows = $this->tenant->select(
            'SELECT t.id, t.client_id, t.project_id, t.title, t.description, t.status, t.priority, t.assignee, t.assignee_user_id,
                    t.due_at, t.client_visible, t.archived_at, t.recurring_template_id, t.recurring_period, t.created_at, t.updated_at,
                    c.name AS client_name, p.name AS project_name, u.name AS assignee_name
               FROM tasks t
          LEFT JOIN clients c  ON c.tenant_id = t.tenant_id AND c.id = t.client_id
          LEFT JOIN projects p ON p.tenant_id = t.tenant_id AND p.id = t.project_id
          LEFT JOIN users u    ON u.tenant_id = t.tenant_id AND u.id = t.assignee_user_id
              WHERE ' . implode(' AND ', $where) . "
           ORDER BY t.status = 'done', t.due_at IS NULL, t.due_at, t.id DESC LIMIT 1000",
            $params
        );
        $q = is_string($filters['q'] ?? null) ? Str::clean($filters['q']) : '';
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool =>
                \HubOS\Core\HebrewText::score($q, (string) $r['title'] . ' ' . (string) ($r['client_name'] ?? '')) > 0));
        }
        if ($viewer) {
            foreach ($rows as &$r) {
                unset($r['assignee'], $r['assignee_user_id'], $r['assignee_name']);
            }
            unset($r);
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function get(array $user, int $id): array
    {
        $this->perms->authorize($user, 'work.view');
        $task = $this->tenant->find('tasks', $id);
        if ($task === null) {
            throw new HttpException(404, 'המשימה לא נמצאה');
        }
        if (($user['role'] ?? '') === 'client_viewer'
            && ((int) ($task['client_id'] ?? 0) !== (int) ($user['client_id'] ?? -1) || (int) $task['client_visible'] !== 1 || $task['archived_at'] !== null)) {
            throw new HttpException(403, 'אין הרשאה לצפות במשימה זו');
        }
        return WorkRefs::strip($task);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     */
    public function create(array $user, array $input, string $source = 'manual'): int
    {
        $this->perms->authorize($user, 'work.create');
        $data = $this->validate($input);
        return $this->tenant->transaction(function (Tenant $t) use ($user, $data, $source): int {
            $id = $t->insert('tasks', $data);
            $after = WorkRefs::strip((array) $t->find('tasks', $id)) + ['source' => $source];
            Events::record($t, (int) $user['id'], 'task', $id, (int) $data['client_id'], 'task.created', null, $after);
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
        $before = $this->tenant->find('tasks', $id);
        if ($before === null) {
            throw new HttpException(404, 'המשימה לא נמצאה');
        }
        $data = $this->validate($input) + ['updated_at' => gmdate('Y-m-d\TH:i:s\Z')];
        $this->tenant->transaction(function (Tenant $t) use ($user, $id, $before, $data): void {
            $t->update('tasks', $id, $data);
            Events::record($t, (int) $user['id'], 'task', $id, (int) $data['client_id'], 'task.updated',
                WorkRefs::strip($before), WorkRefs::strip((array) $t->find('tasks', $id)));
        });
    }

    /** @param array<string, mixed> $user */
    public function setArchived(array $user, int $id, bool $archived): void
    {
        $this->perms->authorize($user, 'work.archive');
        $task = $this->tenant->find('tasks', $id);
        if ($task === null) {
            throw new HttpException(404, 'המשימה לא נמצאה');
        }
        $value = $archived ? gmdate('Y-m-d\TH:i:s\Z') : null;
        $this->tenant->transaction(function (Tenant $t) use ($user, $id, $task, $value, $archived): void {
            $t->update('tasks', $id, ['archived_at' => $value, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
            Events::record($t, (int) $user['id'], 'task', $id, $task['client_id'] !== null ? (int) $task['client_id'] : null,
                $archived ? 'task.archived' : 'task.unarchived', ['archived_at' => $task['archived_at']], ['archived_at' => $value]);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $refs = WorkRefs::resolve($this->tenant, WorkRefs::intOrNull($input['client_id'] ?? null), WorkRefs::intOrNull($input['project_id'] ?? null), null);
        $errors = $refs['errors'];
        if (WorkRefs::intOrNull($input['client_id'] ?? null) === null) {
            $errors['client_id'] = 'חובה לשייך משימה ללקוח';
        }
        $title = Str::clean(is_string($input['title'] ?? null) ? $input['title'] : '');
        if ($title === '' || Str::len($title) > 300) {
            $errors['title'] = 'כותרת היא שדה חובה (עד 300 תווים)';
        }
        $description = trim(is_string($input['description'] ?? null) ? $input['description'] : '');
        if (Str::len($description) > 5000) {
            $errors['description'] = 'תיאור ארוך מדי';
        }
        $status = is_string($input['status'] ?? null) && $input['status'] !== '' ? self::statusKey($input['status']) : 'todo';
        if (!isset(self::STATUSES[$status])) {
            $errors['status'] = 'סטטוס לא תקין';
        }
        $priority = is_string($input['priority'] ?? null) && $input['priority'] !== '' ? $input['priority'] : 'normal';
        if (!isset(self::PRIORITIES[$priority])) {
            $errors['priority'] = 'עדיפות לא תקינה';
        }
        $due = trim(is_string($input['due_at'] ?? null) ? $input['due_at'] : '');
        if ($due !== '' && (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
            $errors['due_at'] = 'תאריך יעד לא תקין';
        }
        $assignee = WorkRefs::intOrNull($input['assignee_user_id'] ?? null);
        if ($assignee !== null) {
            $u = $this->tenant->find('users', $assignee);
            if ($u === null || $u['role'] === 'client_viewer' || $u['status'] !== 'active') {
                $errors['assignee_user_id'] = 'אחראי לא תקין';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'client_id'        => (int) $refs['client']['id'],
            'project_id'       => $refs['project'] !== null ? (int) $refs['project']['id'] : null,
            'title'            => $title,
            'description'      => $description === '' ? null : $description,
            'status'           => $status,
            'priority'         => $priority,
            'due_at'           => $due === '' ? null : $due,
            'assignee_user_id' => $assignee,
            'client_visible'   => in_array($input['client_visible'] ?? null, ['1', 1, true], true) ? 1 : 0,
        ];
    }
}
