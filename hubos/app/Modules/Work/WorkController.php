<?php

declare(strict_types=1);

namespace HubOS\Modules\Work;

use HubOS\Core\Context;
use HubOS\Core\Response;
use HubOS\Core\ValidationException;

/** /tasks, /projects, the needs-linking queue and recurring templates. */
final class WorkController
{
    private const TASK_FIELDS = ['client_id', 'project_id', 'title', 'description', 'status', 'priority', 'due_at', 'assignee_user_id', 'client_visible'];
    private const PROJECT_FIELDS = ['client_id', 'name', 'description', 'status', 'client_visible'];

    // ------------------------------------------------------------------ tasks

    public function tasks(Context $ctx): Response
    {
        return $this->renderTasks($ctx, [], ['client_id' => is_string($_GET['client_id'] ?? null) ? $_GET['client_id'] : '']);
    }

    public function storeTask(Context $ctx): Response
    {
        $input = $this->input($ctx, self::TASK_FIELDS);
        try {
            $id = (new TaskService($ctx->tenant(), $ctx->permissions()))->create($ctx->requireUser(), $input);
        } catch (ValidationException $e) {
            return $this->renderTasks($ctx, $e->errors, $input, 422);
        }
        $ctx->flash('המשימה נוצרה (#' . $id . ') ונרשמה ביומן האירועים');
        return Response::redirect($this->back($ctx, '/tasks'));
    }

    public function showTask(Context $ctx, array $params): Response
    {
        $task = (new TaskService($ctx->tenant(), $ctx->permissions()))->get($ctx->requireUser(), (int) $params['id']);
        return $this->renderTask($ctx, $task, [], []);
    }

    public function updateTask(Context $ctx, array $params): Response
    {
        $id = (int) $params['id'];
        $svc = new TaskService($ctx->tenant(), $ctx->permissions());
        $input = $this->input($ctx, self::TASK_FIELDS);
        try {
            $svc->update($ctx->requireUser(), $id, $input);
        } catch (ValidationException $e) {
            return $this->renderTask($ctx, $svc->get($ctx->requireUser(), $id), $e->errors, $input, 422);
        }
        $ctx->flash('המשימה עודכנה ונרשמה ביומן האירועים (לפני/אחרי)');
        return Response::redirect('/tasks/' . $id);
    }

    public function archiveTask(Context $ctx, array $params): Response
    {
        $archive = $ctx->input('archive') !== '0';
        (new TaskService($ctx->tenant(), $ctx->permissions()))->setArchived($ctx->requireUser(), (int) $params['id'], $archive);
        $ctx->flash($archive ? 'המשימה הועברה לארכיון' : 'המשימה הוחזרה מהארכיון');
        return Response::redirect($this->back($ctx, '/tasks'));
    }

    // ------------------------------------------------------------------ projects

    public function projects(Context $ctx): Response
    {
        return $this->renderProjects($ctx, [], ['client_id' => is_string($_GET['client_id'] ?? null) ? $_GET['client_id'] : '']);
    }

    public function storeProject(Context $ctx): Response
    {
        $input = $this->input($ctx, self::PROJECT_FIELDS);
        try {
            $id = (new ProjectService($ctx->tenant(), $ctx->permissions()))->create($ctx->requireUser(), $input);
        } catch (ValidationException $e) {
            return $this->renderProjects($ctx, $e->errors, $input, 422);
        }
        $ctx->flash('הפרויקט נוצר (#' . $id . ') ונרשם ביומן האירועים');
        return Response::redirect($this->back($ctx, '/projects'));
    }

    public function showProject(Context $ctx, array $params): Response
    {
        $p = (new ProjectService($ctx->tenant(), $ctx->permissions()))->get($ctx->requireUser(), (int) $params['id']);
        return $this->renderProject($ctx, $p, [], []);
    }

    public function updateProject(Context $ctx, array $params): Response
    {
        $id = (int) $params['id'];
        $svc = new ProjectService($ctx->tenant(), $ctx->permissions());
        $input = $this->input($ctx, self::PROJECT_FIELDS);
        try {
            $svc->update($ctx->requireUser(), $id, $input);
        } catch (ValidationException $e) {
            return $this->renderProject($ctx, $svc->get($ctx->requireUser(), $id), $e->errors, $input, 422);
        }
        $ctx->flash('הפרויקט עודכן ונרשם ביומן האירועים (לפני/אחרי)');
        return Response::redirect('/projects/' . $id);
    }

    public function archiveProject(Context $ctx, array $params): Response
    {
        $archive = $ctx->input('archive') !== '0';
        (new ProjectService($ctx->tenant(), $ctx->permissions()))->setArchived($ctx->requireUser(), (int) $params['id'], $archive);
        $ctx->flash($archive ? 'הפרויקט הועבר לארכיון' : 'הפרויקט הוחזר מהארכיון');
        return Response::redirect($this->back($ctx, '/projects'));
    }

    // ------------------------------------------------------------------ needs linking

    public function linking(Context $ctx): Response
    {
        $queue = (new LinkingService($ctx->tenant(), $ctx->permissions()))->queue($ctx->requireUser());
        return $ctx->render('work/linking', [
            'title'   => 'ממתין לשיוך ללקוח',
            'active'  => 'linking',
            'queue'   => $queue,
            'clients' => $ctx->tenant()->select('SELECT id, name, domain FROM clients WHERE tenant_id = :tenant_id ORDER BY name LIMIT 1000'),
        ]);
    }

    public function link(Context $ctx, array $params): Response
    {
        $clientId = WorkRefs::intOrNull($ctx->input('client_id'));
        if ($clientId === null) {
            $ctx->flashError('יש לבחור לקוח לשיוך');
            return Response::redirect('/work/linking');
        }
        (new LinkingService($ctx->tenant(), $ctx->permissions()))->link($ctx->requireUser(), (string) $params['type'], (int) $params['id'], $clientId);
        $ctx->flash(($params['type'] === 'task' ? 'המשימה' : 'הפרויקט') . ' #' . (int) $params['id'] . ' שויך ללקוח ונרשם ביומן האירועים');
        return Response::redirect('/work/linking');
    }

    // ------------------------------------------------------------------ recurring

    public function recurring(Context $ctx): Response
    {
        return $this->renderRecurring($ctx, [], []);
    }

    public function storeRecurring(Context $ctx): Response
    {
        $input = $this->input($ctx, ['client_id', 'project_id', 'title', 'description', 'priority', 'client_visible', 'day_of_month', 'assignee_user_id']);
        try {
            (new RecurringService($ctx->tenant(), $ctx->permissions()))->createTemplate($ctx->requireUser(), $input);
        } catch (ValidationException $e) {
            return $this->renderRecurring($ctx, $e->errors, $input, 422);
        }
        $ctx->flash('תבנית המשימה החודשית נשמרה');
        return Response::redirect('/work/recurring');
    }

    public function toggleRecurring(Context $ctx, array $params): Response
    {
        (new RecurringService($ctx->tenant(), $ctx->permissions()))->setActive($ctx->requireUser(), (int) $params['id'], $ctx->input('active') === '1');
        $ctx->flash('התבנית עודכנה');
        return Response::redirect('/work/recurring');
    }

    // ------------------------------------------------------------------ rendering

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderTasks(Context $ctx, array $errors, array $old, int $status = 200): Response
    {
        $user = $ctx->requireUser();
        $filters = [];
        foreach (['status', 'client_id', 'q', 'archived', 'assignee_user_id'] as $k) {
            $filters[$k] = is_string($_GET[$k] ?? null) ? $_GET[$k] : null;
        }
        return $ctx->render('work/tasks', [
            'title'    => 'משימות',
            'active'   => 'tasks',
            'tasks'    => (new TaskService($ctx->tenant(), $ctx->permissions()))->list($user, $filters),
            'filters'  => $filters,
            'errors'   => $errors,
            'old'      => $old,
            'internal' => ($user['role'] ?? '') !== 'client_viewer',
        ] + $this->formData($ctx), $status);
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderTask(Context $ctx, array $task, array $errors, array $old, int $status = 200): Response
    {
        $old = $old !== [] ? $old : array_map(static fn ($v): string => (string) $v, array_intersect_key($task, array_flip(self::TASK_FIELDS)));
        if (isset($old['due_at'])) {
            $old['due_at'] = substr($old['due_at'], 0, 10);
        }
        $user = $ctx->requireUser();
        return $ctx->render('work/task', [
            'title'    => 'משימה #' . (int) $task['id'],
            'active'   => 'tasks',
            'task'     => $task,
            'errors'   => $errors,
            'old'      => $old,
            'internal' => ($user['role'] ?? '') !== 'client_viewer',
            'client'   => $task['client_id'] !== null ? $ctx->tenant()->find('clients', (int) $task['client_id']) : null,
        ] + $this->formData($ctx), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderProjects(Context $ctx, array $errors, array $old, int $status = 200): Response
    {
        $user = $ctx->requireUser();
        $filters = [];
        foreach (['status', 'client_id', 'archived'] as $k) {
            $filters[$k] = is_string($_GET[$k] ?? null) ? $_GET[$k] : null;
        }
        return $ctx->render('work/projects', [
            'title'    => 'פרויקטים',
            'active'   => 'projects',
            'projects' => (new ProjectService($ctx->tenant(), $ctx->permissions()))->list($user, $filters),
            'filters'  => $filters,
            'errors'   => $errors,
            'old'      => $old,
            'internal' => ($user['role'] ?? '') !== 'client_viewer',
        ] + $this->formData($ctx), $status);
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderProject(Context $ctx, array $project, array $errors, array $old, int $status = 200): Response
    {
        $old = $old !== [] ? $old : array_map(static fn ($v): string => (string) $v, array_intersect_key($project, array_flip(self::PROJECT_FIELDS)));
        $user = $ctx->requireUser();
        return $ctx->render('work/project', [
            'title'    => 'פרויקט #' . (int) $project['id'],
            'active'   => 'projects',
            'project'  => $project,
            'tasks'    => array_values(array_filter(
                (new TaskService($ctx->tenant(), $ctx->permissions()))->list($user, ['client_id' => (string) ($project['client_id'] ?? '')]),
                static fn (array $t): bool => (int) ($t['project_id'] ?? 0) === (int) $project['id'],
            )),
            'errors'   => $errors,
            'old'      => $old,
            'internal' => ($user['role'] ?? '') !== 'client_viewer',
        ] + $this->formData($ctx), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderRecurring(Context $ctx, array $errors, array $old, int $status = 200): Response
    {
        return $ctx->render('work/recurring', [
            'title'     => 'משימות תחזוקה חודשיות',
            'active'    => 'recurring',
            'templates' => (new RecurringService($ctx->tenant(), $ctx->permissions()))->templates($ctx->requireUser()),
            'errors'    => $errors,
            'old'       => $old,
        ] + $this->formData($ctx), $status);
    }

    /** @return array<string, mixed> */
    private function formData(Context $ctx): array
    {
        $user = $ctx->requireUser();
        if (($user['role'] ?? '') === 'client_viewer') {
            return ['clients' => [], 'projectsList' => [], 'users' => [], 'canCreate' => false, 'canUpdate' => false, 'canArchive' => false];
        }
        return [
            'clients'      => $ctx->tenant()->select('SELECT id, name FROM clients WHERE tenant_id = :tenant_id ORDER BY name LIMIT 1000'),
            'projectsList' => $ctx->tenant()->select('SELECT id, name, client_id FROM projects WHERE tenant_id = :tenant_id AND archived_at IS NULL AND client_id IS NOT NULL ORDER BY name LIMIT 1000'),
            'users'        => $ctx->tenant()->select("SELECT id, name FROM users WHERE tenant_id = :tenant_id AND role <> 'client_viewer' AND status = 'active' ORDER BY name"),
            'canCreate'    => $ctx->can('work.create'),
            'canUpdate'    => $ctx->can('work.update'),
            'canArchive'   => $ctx->can('work.archive'),
        ];
    }

    /**
     * @param list<string> $keys
     * @return array<string, string>
     */
    private function input(Context $ctx, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $ctx->input($k);
        }
        return $out;
    }

    private function back(Context $ctx, string $default): string
    {
        $r = $ctx->input('_return');
        return preg_match('#^/(?!/)[A-Za-z0-9/_\-?=&%.\#]*$#', $r) === 1 ? $r : $default;
    }
}
