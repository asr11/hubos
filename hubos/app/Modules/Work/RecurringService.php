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
 * Monthly recurring maintenance tasks (Scoro idea). A template belongs to a
 * client; generate() creates at most ONE task per template per month
 * (tasks.recurring_template_id + recurring_period, UNIQUE index), so running
 * bin/recurring.php again for the same month creates nothing.
 */
final class RecurringService
{
    public function __construct(private Tenant $tenant, private ?Permissions $perms = null)
    {
    }

    /**
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function templates(array $user, ?int $clientId = null): array
    {
        $this->authorize($user, 'work.view');
        $sql = 'SELECT r.*, c.name AS client_name FROM recurring_task_templates r
                  LEFT JOIN clients c ON c.tenant_id = r.tenant_id AND c.id = r.client_id
                 WHERE r.tenant_id = :tenant_id' . ($clientId !== null ? ' AND r.client_id = :cid' : '') . ' ORDER BY r.active DESC, c.name, r.title';
        return $this->tenant->select($sql, $clientId !== null ? ['cid' => $clientId] : []);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input client_id, title, description, priority, client_visible, day_of_month, project_id, assignee_user_id
     */
    public function createTemplate(array $user, array $input): int
    {
        $this->authorize($user, 'work.create');
        $base = (new TaskService($this->tenant, $this->perms ?? throw new \LogicException('perms')))->validate($input + ['status' => 'todo']);
        $day = (int) (is_string($input['day_of_month'] ?? null) && $input['day_of_month'] !== '' ? $input['day_of_month'] : 1);
        if ($day < 1 || $day > 28) {
            throw new ValidationException(['day_of_month' => 'יום בחודש: 1–28']);
        }
        $data = [
            'client_id'        => $base['client_id'],
            'project_id'       => $base['project_id'],
            'title'            => Str::limit($base['title'], 200),
            'description'      => $base['description'],
            'priority'         => $base['priority'],
            'client_visible'   => $base['client_visible'],
            'assignee_user_id' => $base['assignee_user_id'],
            'day_of_month'     => $day,
        ];
        return $this->tenant->transaction(function (Tenant $t) use ($user, $data): int {
            $id = $t->insert('recurring_task_templates', $data);
            Events::record($t, (int) $user['id'], 'recurring_template', $id, $data['client_id'], 'recurring_template.created', null, $data);
            return $id;
        });
    }

    /** @param array<string, mixed> $user */
    public function setActive(array $user, int $id, bool $active): void
    {
        $this->authorize($user, 'work.update');
        $tpl = $this->tenant->find('recurring_task_templates', $id);
        if ($tpl === null) {
            throw new HttpException(404, 'התבנית לא נמצאה');
        }
        $this->tenant->transaction(function (Tenant $t) use ($user, $id, $tpl, $active): void {
            $t->update('recurring_task_templates', $id, ['active' => $active ? 1 : 0, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
            Events::record($t, (int) $user['id'], 'recurring_template', $id, (int) $tpl['client_id'],
                $active ? 'recurring_template.activated' : 'recurring_template.deactivated', ['active' => (int) $tpl['active']], ['active' => $active ? 1 : 0]);
        });
    }

    /**
     * Create this month's tasks for every active template. Idempotent per (template, month).
     *
     * @return array{created:list<int>, skipped:int}
     */
    public function generate(string $month, ?int $actorUserId = null): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new \InvalidArgumentException('Month must be YYYY-MM');
        }
        $created = [];
        $skipped = 0;
        foreach ($this->tenant->all('recurring_task_templates', ['active' => 1], 'id', 'ASC', 1000) as $tpl) {
            $id = $this->tenant->transaction(function (Tenant $t) use ($tpl, $month, $actorUserId): ?int {
                if ($t->count('tasks', ['recurring_template_id' => (int) $tpl['id'], 'recurring_period' => $month]) > 0) {
                    return null;
                }
                $data = [
                    'client_id'             => (int) $tpl['client_id'],
                    'project_id'            => $tpl['project_id'] !== null ? (int) $tpl['project_id'] : null,
                    'title'                 => $tpl['title'] . ' — ' . $month,
                    'description'           => $tpl['description'],
                    'status'                => 'todo',
                    'priority'              => $tpl['priority'],
                    'client_visible'        => (int) $tpl['client_visible'],
                    'assignee_user_id'      => $tpl['assignee_user_id'] !== null ? (int) $tpl['assignee_user_id'] : null,
                    'due_at'                => sprintf('%s-%02d', $month, (int) $tpl['day_of_month']),
                    'recurring_template_id' => (int) $tpl['id'],
                    'recurring_period'      => $month,
                ];
                $id = $t->insert('tasks', $data);
                Events::record($t, $actorUserId, 'task', $id, $data['client_id'], 'task.created', null, $data + ['source' => 'recurring']);
                return $id;
            });
            if ($id === null) {
                $skipped++;
            } else {
                $created[] = $id;
            }
        }
        return ['created' => $created, 'skipped' => $skipped];
    }

    /** @param array<string, mixed> $user */
    private function authorize(array $user, string $perm): void
    {
        if ($this->perms === null) {
            throw new \LogicException('Permissions required');
        }
        $this->perms->authorize($user, $perm);
        if (($user['role'] ?? '') === 'client_viewer') {
            throw new HttpException(403, 'אין הרשאה לפעולה זו');
        }
    }
}
