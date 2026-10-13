<?php

declare(strict_types=1);

use HubOS\Core\Router;
use HubOS\Modules\Auth\AuthController;
use HubOS\Modules\Clients\ClientsController;
use HubOS\Modules\Dashboard\DashboardController;

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
};
