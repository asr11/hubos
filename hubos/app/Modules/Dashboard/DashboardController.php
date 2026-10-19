<?php

declare(strict_types=1);

namespace HubOS\Modules\Dashboard;

use HubOS\Core\Context;
use HubOS\Core\Response;

final class DashboardController
{
    public function home(Context $ctx): Response
    {
        return Response::redirect($ctx->user() !== null ? '/dashboard' : '/login');
    }

    public function index(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $svc = new DashboardService($ctx->tenant(), $ctx->permissions());

        if (($user['role'] ?? '') === 'client_viewer') {
            $portal = $svc->portal($user);
            return $ctx->render('dashboard/portal', [
                'title'  => 'פורטל לקוח · ' . ($portal['client']['name'] ?? 'ללא לקוח'),
                'active' => 'dashboard',
                'portal' => $portal,
            ]);
        }

        $data = $svc->ceo($user);
        return $ctx->render('dashboard/index', $data + [
            'title'  => 'מסך הבוקר · לוח בקרה למנכ״ל',
            'active' => 'dashboard',
        ]);
    }
}
