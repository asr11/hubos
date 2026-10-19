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

    public function syncD1(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $prov = (new \HubOS\Modules\Import\SyncService($ctx->tenant(), $ctx->permissions(), $ctx->pdo))->syncNow($user);
        $ctx->flash(sprintf(
            'סנכרון ואימות הושלמו בהצלחה · מקור: %s · זמן שליפה: %s · חתימת SHA-256: %s',
            (string) $prov['source_label'],
            (string) $prov['fetched_at_il'],
            (string) $prov['sha256_short']
        ));
        $returnTo = $ctx->input('_return');
        return Response::redirect($returnTo !== '' ? $returnTo : '/dashboard');
    }
}
