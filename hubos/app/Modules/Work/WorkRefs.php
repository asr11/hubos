<?php

declare(strict_types=1);

namespace HubOS\Modules\Work;

use HubOS\Core\Tenant;

/**
 * Validates client / project / task references inside the current tenant and
 * that they agree with each other (a task or project of another client is rejected).
 */
final class WorkRefs
{
    /**
     * @return array{client:array<string,mixed>, project:?array<string,mixed>, task:?array<string,mixed>, errors:array<string,string>}
     */
    public static function resolve(Tenant $tenant, ?int $clientId, ?int $projectId, ?int $taskId): array
    {
        $errors = [];
        $task = $taskId !== null ? $tenant->find('tasks', $taskId) : null;
        if ($taskId !== null && $task === null) {
            $errors['task_id'] = 'המשימה לא נמצאה';
        }
        $project = $projectId !== null ? $tenant->find('projects', $projectId) : null;
        if ($projectId !== null && $project === null) {
            $errors['project_id'] = 'הפרויקט לא נמצא';
        }
        if ($task !== null && $project === null && $task['project_id'] !== null) {
            $project = $tenant->find('projects', (int) $task['project_id']);
        }
        // Derive the client from the task / project when not given explicitly.
        if ($clientId === null && $task !== null) {
            $clientId = $task['client_id'] !== null ? (int) $task['client_id'] : ($project['client_id'] ?? null);
            $clientId = $clientId !== null ? (int) $clientId : null;
        }
        $client = $clientId !== null ? $tenant->find('clients', $clientId) : null;
        if ($client === null) {
            $errors['client_id'] = 'חובה לבחור לקוח';
        }
        if ($client !== null && $task !== null) {
            $taskClient = $task['client_id'] ?? ($project['client_id'] ?? null);
            if ($taskClient === null || (int) $taskClient !== (int) $client['id']) {
                $errors['task_id'] = 'המשימה אינה שייכת ללקוח שנבחר';
            }
        }
        if ($client !== null && $project !== null && $project['client_id'] !== null && (int) $project['client_id'] !== (int) $client['id']) {
            $errors['project_id'] = 'הפרויקט אינו שייך ללקוח שנבחר';
        }
        return ['client' => $client ?? [], 'project' => $project, 'task' => $task, 'errors' => $errors];
    }

    public static function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (is_string($v) && preg_match('/^\d{1,10}$/', trim($v)) === 1 && (int) $v > 0) {
            return (int) $v;
        }
        return null;
    }

    /** @param array<string, mixed> $row */
    public static function strip(array $row): array
    {
        unset($row['data_json'], $row['tenant_id']);
        return $row;
    }
}
