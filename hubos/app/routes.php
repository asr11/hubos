<?php

declare(strict_types=1);

use HubOS\Core\Router;
use HubOS\Modules\Auth\AuthController;
use HubOS\Modules\Clients\ClientsController;
use HubOS\Modules\Dashboard\DashboardController;
use HubOS\Modules\Time\TimeController;
use HubOS\Modules\Work\WorkController;

/*
 * Route table. opts: auth => requires login, perm => required permission.
 * All POST routes are CSRF-protected by the kernel.
 */
return static function (Router $r): void {
    $r->get('/', [DashboardController::class, 'home']);

    $r->get('/login', [AuthController::class, 'showLogin']);
    $r->post('/login', [AuthController::class, 'login']);
    $r->post('/logout', [AuthController::class, 'logout'], ['auth' => true]);

    $r->get('/dashboard', [DashboardController::class, 'index'], ['auth' => true, 'perm' => 'dashboard.view']);

    $r->get('/clients', [ClientsController::class, 'index'], ['auth' => true, 'perm' => 'clients.view']);
    $r->post('/clients', [ClientsController::class, 'store'], ['auth' => true, 'perm' => 'clients.create']);
    $r->get('/clients/{id:\d+}', [ClientsController::class, 'show'], ['auth' => true, 'perm' => 'clients.view']);
    $r->post('/clients/{id:\d+}/enrich', [ClientsController::class, 'enrich'], ['auth' => true, 'perm' => 'clients.update']);
    $r->post('/clients/{id:\d+}/enrich/apply', [ClientsController::class, 'applyEnrichment'], ['auth' => true, 'perm' => 'clients.update']);

    // M3 — time, live timer, hours banks
    $r->get('/time', [TimeController::class, 'index'], ['auth' => true, 'perm' => 'time.view']);
    $r->post('/time/entries', [TimeController::class, 'storeEntry'], ['auth' => true, 'perm' => 'time.track']);
    $r->get('/time/entries/{id:\d+}', [TimeController::class, 'editEntry'], ['auth' => true, 'perm' => 'time.track']);
    $r->post('/time/entries/{id:\d+}', [TimeController::class, 'updateEntry'], ['auth' => true, 'perm' => 'time.track']);
    $r->post('/time/entries/{id:\d+}/delete', [TimeController::class, 'deleteEntry'], ['auth' => true, 'perm' => 'time.track']);
    $r->post('/hours-banks/{id:\d+}/settings', [TimeController::class, 'bankSettings'], ['auth' => true, 'perm' => 'time.manage']);
    $r->get('/timer', [TimeController::class, 'state'], ['auth' => true, 'perm' => 'time.view']);
    $r->post('/timer/start', [TimeController::class, 'start'], ['auth' => true, 'perm' => 'time.track']);
    $r->post('/timer/pause', [TimeController::class, 'pause'], ['auth' => true, 'perm' => 'time.track']);
    $r->post('/timer/resume', [TimeController::class, 'resume'], ['auth' => true, 'perm' => 'time.track']);
    $r->post('/timer/stop', [TimeController::class, 'stop'], ['auth' => true, 'perm' => 'time.track']);
    $r->post('/timer/discard', [TimeController::class, 'discard'], ['auth' => true, 'perm' => 'time.track']);

    // M4 — tasks & projects (client link required), needs-linking queue, recurring templates
    $r->get('/tasks', [WorkController::class, 'tasks'], ['auth' => true, 'perm' => 'work.view']);
    $r->post('/tasks', [WorkController::class, 'storeTask'], ['auth' => true, 'perm' => 'work.create']);
    $r->get('/tasks/{id:\d+}', [WorkController::class, 'showTask'], ['auth' => true, 'perm' => 'work.view']);
    $r->post('/tasks/{id:\d+}', [WorkController::class, 'updateTask'], ['auth' => true, 'perm' => 'work.update']);
    $r->post('/tasks/{id:\d+}/archive', [WorkController::class, 'archiveTask'], ['auth' => true, 'perm' => 'work.archive']);
    $r->get('/projects', [WorkController::class, 'projects'], ['auth' => true, 'perm' => 'work.view']);
    $r->post('/projects', [WorkController::class, 'storeProject'], ['auth' => true, 'perm' => 'work.create']);
    $r->get('/projects/{id:\d+}', [WorkController::class, 'showProject'], ['auth' => true, 'perm' => 'work.view']);
    $r->post('/projects/{id:\d+}', [WorkController::class, 'updateProject'], ['auth' => true, 'perm' => 'work.update']);
    $r->post('/projects/{id:\d+}/archive', [WorkController::class, 'archiveProject'], ['auth' => true, 'perm' => 'work.archive']);
    $r->get('/work/linking', [WorkController::class, 'linking'], ['auth' => true, 'perm' => 'work.link']);
    $r->post('/work/linking/{type:task|project}/{id:\d+}', [WorkController::class, 'link'], ['auth' => true, 'perm' => 'work.link']);
    $r->get('/work/recurring', [WorkController::class, 'recurring'], ['auth' => true, 'perm' => 'work.create']);
    $r->post('/work/recurring', [WorkController::class, 'storeRecurring'], ['auth' => true, 'perm' => 'work.create']);
    $r->post('/work/recurring/{id:\d+}/active', [WorkController::class, 'toggleRecurring'], ['auth' => true, 'perm' => 'work.update']);
};
