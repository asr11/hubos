<?php

declare(strict_types=1);

namespace HubOS\Modules\Clients;

use HubOS\Core\Context;
use HubOS\Core\Http\NativeHttpClient;
use HubOS\Core\HttpException;
use HubOS\Core\Response;
use HubOS\Core\Str;
use HubOS\Core\ValidationException;
use HubOS\Modules\Time\TimerService;

final class ClientsController
{
    public function index(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        if (($user['role'] ?? '') === 'client_viewer') {
            return Response::redirect($user['client_id'] !== null ? '/clients/' . (int) $user['client_id'] : '/dashboard');
        }
        return $this->renderList($ctx, [], ['name' => '', 'email' => '', 'phone' => '', 'initial_hours' => '', 'bank_type' => 'one_time', 'source' => '']);
    }

    public function store(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $input = [
            'name'          => $ctx->input('name'),
            'email'         => $ctx->input('email'),
            'phone'         => $ctx->input('phone'),
            'initial_hours' => $ctx->input('initial_hours'),
            'bank_type'     => $ctx->input('bank_type'),
            'source'        => $ctx->input('source'),
        ];
        try {
            (new ClientService($ctx->tenant(), $ctx->permissions()))->create($user, $input);
        } catch (ValidationException $e) {
            return $this->renderList($ctx, $e->errors, $input, 422);
        }
        $ctx->flash('הלקוח נוצר ונרשם ביומן האירועים');
        return Response::redirect('/clients');
    }

    /** GET /clients/{id}?tab=… — the Client 360 card (server-rendered tabs). */
    public function show(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $id = (int) ($params['id'] ?? 0);
        $tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'overview';
        if (!array_key_exists($tab, Client360Service::TABS)) {
            $tab = 'overview';
        }
        if (in_array($tab, ['finance', 'collection'], true) && ($user['role'] ?? '') !== 'owner') {
            $tab = 'overview';
        }
        $service = new Client360Service($ctx->tenant(), $ctx->permissions());
        $card = $service->build($user, $id);

        if ($tab === 'access' && $card['internal']) {
            $service->logVaultView($user, $id, $card['vault']);
        }

        $registry = $_SESSION['_registry'][$id] ?? null;
        $registryError = $_SESSION['_registry_error'][$id] ?? null;
        unset($_SESSION['_registry_error'][$id]);

        return $ctx->render('clients/show', [
            'title'         => (string) $card['client']['name'],
            'active'        => 'clients',
            'card'          => $card,
            'tab'           => $tab,
            'canEnrich'        => $card['internal'] && $ctx->can('clients.update'),
            'canAssignPartner' => $card['internal'] && $ctx->can('users.manage'),
            'suggestion'       => is_array($registry) ? $registry : null,
            'registryError' => is_string($registryError) ? $registryError : null,
            // M3/M4: live timer + work actions on the card (never for client_viewer)
            'canTrack'      => $card['internal'] && $ctx->can('time.track'),
            'canWork'       => $card['internal'] && $ctx->can('work.create'),
            'canUpdateTask' => $card['internal'] && $ctx->can('work.update'),
            'timer'         => $card['internal'] && $ctx->can('time.track')
                ? (new TimerService($ctx->tenant(), $ctx->permissions()))->current($user) : null,
        ]);
    }

    /** GET /clients/{id}/report — Print & PDF-ready RTL Hebrew status and hours report. */
    public function report(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $id = (int) ($params['id'] ?? 0);
        $period = is_string($_GET['period'] ?? null) ? trim($_GET['period']) : 'all';
        $scope = is_string($_GET['scope'] ?? null) ? trim($_GET['scope']) : 'client';
        $report = (new Client360Service($ctx->tenant(), $ctx->permissions()))->buildReport($user, $id, $period, $scope);

        return $ctx->render('clients/report', [
            'title'      => 'דוח סטטוס ושעות · ' . (string) $report['client']['name'],
            'report'     => $report,
            'viewerUser' => $user,
        ], 200, 'layouts/report');
    }

    /** POST /clients/{id}/enrich — fetch registry SUGGESTIONS (nothing is stored on the client). */
    public function enrich(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $id = (int) ($params['id'] ?? 0);
        $client = (new Client360Service($ctx->tenant(), $ctx->permissions()))->authorizeClient($user, $id);
        if (!Client360Service::isInternal($user)) {
            throw new HttpException(403, 'אין הרשאה לפעולה זו');
        }
        $registry = new CompanyRegistry(new NativeHttpClient(), (string) $ctx->config['registry_url']);
        $result = $registry->lookup(is_string($client['tax_id'] ?? null) ? $client['tax_id'] : null);
        if ($result['ok']) {
            $_SESSION['_registry'][$id] = $result['suggestion'];
            unset($_SESSION['_registry_error'][$id]);
        } else {
            unset($_SESSION['_registry'][$id]);
            $_SESSION['_registry_error'][$id] = $result['error'];
        }
        return Response::redirect('/clients/' . $id . '#registry');
    }

    /** POST /clients/{id}/enrich/apply — store chosen suggestion fields after an explicit click. */
    public function applyEnrichment(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $id = (int) ($params['id'] ?? 0);
        (new Client360Service($ctx->tenant(), $ctx->permissions()))->authorizeClient($user, $id);
        $suggestion = $_SESSION['_registry'][$id] ?? null;
        if (!is_array($suggestion)) {
            $_SESSION['_registry_error'][$id] = 'אין הצעה פעילה מרשם החברות. לחצו שוב על "העשרה מרשם החברות".';
            return Response::redirect('/clients/' . $id . '#registry');
        }
        $fields = $_POST['fields'] ?? [];
        $fields = is_array($fields) ? array_values(array_filter($fields, 'is_string')) : [];
        (new ClientService($ctx->tenant(), $ctx->permissions()))->applyRegistry($user, $id, $suggestion, $fields);
        unset($_SESSION['_registry'][$id]);
        $ctx->flash('נתוני רשם החברות נשמרו ונרשמו ביומן האירועים');
        return Response::redirect('/clients/' . $id);
    }

    /** @param array<string, string> $params */
    public function storeSeoKeyword(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $id = (int) $params['id'];
        try {
            (new ClientSeoService($ctx->tenant(), $ctx->permissions()))->store($user, $id, [
                'keyword'      => $ctx->input('keyword'),
                'target_url'   => $ctx->input('target_url'),
                'stage'        => $ctx->input('stage'),
                'current_rank' => $ctx->input('current_rank'),
                'notes'        => $ctx->input('notes'),
            ]);
            $ctx->flash('מילת המפתח נשמרה ותוצג בפורטל הלקוח');
        } catch (\InvalidArgumentException|\HubOS\Core\HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/clients/' . $id . '#seo-keywords-card');
    }

    /** @param array<string, string> $params */
    public function deleteSeoKeyword(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $id = (int) $params['id'];
        $kwId = (int) $params['kw'];
        try {
            (new ClientSeoService($ctx->tenant(), $ctx->permissions()))->delete($user, $id, $kwId);
            $ctx->flash('מילת המפתח הוסרה ממעקב הלקוח');
        } catch (\HubOS\Core\HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/clients/' . $id . '#seo-keywords-card');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderList(Context $ctx, array $errors, array $old, int $status = 200): Response
    {
        $user = $ctx->requireUser();
        $service = new ClientService($ctx->tenant(), $ctx->permissions());
        $statusFilter = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['active', 'inactive', 'all'], true)
            ? $_GET['status']
            : 'active'; // Default to active clients!
        $q = is_string($_GET['q'] ?? null) ? Str::limit(Str::clean($_GET['q']), 100) : '';
        $effectiveFilter = ($q !== '' && !isset($_GET['status'])) ? 'all' : $statusFilter;
        $clients = $service->search($user, $q, $effectiveFilter === 'all' ? null : $effectiveFilter);
        $counts = $service->countsByStatus($user);

        return $ctx->render('clients/index', [
            'title'        => 'לקוחות',
            'active'       => 'clients',
            'clients'      => $clients,
            'q'            => $q,
            'statusFilter' => $statusFilter,
            'counts'       => $counts,
            'canCreate'    => $ctx->can('clients.create'),
            'canFinance'   => ($user['role'] ?? '') === 'owner',
            'errors'       => $errors,
            'old'          => $old,
        ], $status);
    }
}
