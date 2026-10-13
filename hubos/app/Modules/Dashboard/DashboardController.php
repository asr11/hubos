<?php

declare(strict_types=1);

namespace HubOS\Modules\Dashboard;

use HubOS\Core\Context;
use HubOS\Core\Events;
use HubOS\Core\Response;
use HubOS\Modules\Clients\ClientService;

final class DashboardController
{
    public function home(Context $ctx): Response
    {
        return Response::redirect($ctx->user() !== null ? '/dashboard' : '/login');
    }

    public function index(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $tenant = $ctx->tenant();
        $perms = $ctx->permissions();
        $canEvents = $perms->can($user, 'events.view');
        $canClients = $perms->can($user, 'clients.view');

        return $ctx->render('dashboard/index', [
            'title'        => 'לוח בקרה',
            'active'       => 'dashboard',
            'clientsCount' => $canClients ? (new ClientService($tenant, $perms))->count($user) : null,
            'eventsCount'  => $canEvents ? $tenant->count('events') : null,
            'modules'      => $tenant->all('tenant_modules', ['enabled' => 1], 'module', 'ASC'),
            'events'       => $canEvents ? Events::recent($tenant, 8) : [],
        ]);
    }
}
