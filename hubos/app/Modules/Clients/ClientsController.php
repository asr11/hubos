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
        $service = new Client360Service($ctx->tenant(), $ctx->permissions());

        // M11: client_viewer NEVER opens the internal 11-tab Client 360 card; render clean Service Portal ( while preserving 403/404 on foreign clients)
        if (($user['role'] ?? '') === 'client_viewer') {
            $card = $service->build($user, $id);
            $card['vault'] = [];
            return $ctx->render('dashboard/portal', [
                'title'  => 'פורטל לקוח · ' . (string) ($card['client']['name'] ?? ''),
                'active' => 'dashboard',
                'portal' => $card,
            ]);
        }

        $tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'overview';
        if (!array_key_exists($tab, Client360Service::TABS)) {
            $tab = 'overview';
        }
        if (in_array($tab, ['finance', 'collection'], true) && ($user['role'] ?? '') !== 'owner') {
            $tab = 'overview';
        }
        $card = $service->build($user, $id);

        if ($tab === 'access' && $card['internal']) {
            $service->logVaultView($user, $id, $card['vault']);
        }

        $registry = $_SESSION['_registry'][$id] ?? null;
        $registryError = $_SESSION['_registry_error'][$id] ?? null;
        unset($_SESSION['_registry_error'][$id]);

        return $ctx->render('clients/show', [
            'title'            => (string) $card['client']['name'],
            'active'           => 'clients',
            'card'             => $card,
            'tab'              => $tab,
            'canEnrich'        => $card['internal'] && $ctx->can('clients.update'),
            'canAssignPartner' => $card['internal'] && $ctx->can('users.manage'),
            'suggestion'       => is_array($registry) ? $registry : null,
            'registryError'    => is_string($registryError) ? $registryError : null,
            // M3/M4: live timer + work actions on the card (never for client_viewer)
            'canTrack'         => $card['internal'] && $ctx->can('time.track'),
            'canWork'          => $card['internal'] && $ctx->can('work.create'),
            'canUpdateTask'    => $card['internal'] && $ctx->can('work.update'),
            'timer'            => $card['internal'] && $ctx->can('time.track')
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

    /** @param array<string, string> $params */
    public function storeService(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $id = (int) $params['id'];
        $widgets = $_POST['widgets'] ?? [];
        try {
            (new ClientServicesEngine($ctx->tenant(), $ctx->permissions()))->store($user, $id, [
                'service_id'     => $ctx->input('service_id'),
                'service_type'   => $ctx->input('service_type'),
                'title'          => $ctx->input('title'),
                'scope_summary'  => $ctx->input('scope_summary'),
                'billing_cycle'  => $ctx->input('billing_cycle'),
                'status'         => $ctx->input('status'),
                'widgets'        => is_array($widgets) ? $widgets : [],
                'live_url'       => $ctx->input('live_url'),
                'metric_1_label' => $ctx->input('metric_1_label'),
                'metric_1_value' => $ctx->input('metric_1_value'),
                'metric_2_label' => $ctx->input('metric_2_label'),
                'metric_2_value' => $ctx->input('metric_2_value'),
                'metric_3_label' => $ctx->input('metric_3_label'),
                'metric_3_value' => $ctx->input('metric_3_value'),
                'note'           => $ctx->input('note'),
            ]);
            $ctx->flash('חבילת השירות נשמרה ועודכנה בפורטל הלקוח');
        } catch (\InvalidArgumentException|HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/clients/' . $id . '#admin-client-services-card');
    }

    /** @param array<string, string> $params */
    public function deleteService(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $id = (int) $params['id'];
        $svcId = (int) $params['svc'];
        try {
            (new ClientServicesEngine($ctx->tenant(), $ctx->permissions()))->delete($user, $id, $svcId);
            $ctx->flash('חבילת השירות הוסרה מפורטל הלקוח');
        } catch (HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/clients/' . $id . '#admin-client-services-card');
    }

    /** @param array<string, string> $params */
    public function saveAnalytics(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $id = (int) $params['id'];
        try {
            (new GoogleAnalyticsService($ctx->tenant(), $ctx->permissions()))->saveClientConnection($user, $id, [
                'gsc_site_url'       => $ctx->input('gsc_site_url'),
                'ga4_property_id'    => $ctx->input('ga4_property_id'),
                'ga4_measurement_id' => $ctx->input('ga4_measurement_id'),
                'auth_mode'          => $ctx->input('auth_mode'),
            ]);
            $ctx->flash('הגדרות Search Console ו-Analytics נשמרו לתיק הלקוח');
        } catch (\InvalidArgumentException|HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/clients/' . $id . '#admin-google-analytics-card');
    }

    /** @param array<string, string> $params */
    public function syncAnalytics(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $id = (int) $params['id'];
        try {
            $res = (new GoogleAnalyticsService($ctx->tenant(), $ctx->permissions()))->syncClient($user, $id);
            if ($res['ok']) {
                $ctx->flash($res['message']);
            } else {
                $ctx->flashError($res['message']);
            }
        } catch (\InvalidArgumentException|HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/clients/' . $id . '#admin-google-analytics-card');
    }

    public function saveGoogleIntegration(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $mode = $ctx->input('mode');
        $returnTo = $ctx->input('_return');
        $ga = new GoogleAnalyticsService($ctx->tenant(), $ctx->permissions());
        try {
            if ($mode === 'oauth') {
                $ga->saveOAuthCredentials($user, $ctx->input('client_id'), $ctx->input('client_secret'), $ctx->input('refresh_token'));
                $ctx->flash('פרטי Google OAuth הוצפנו ב-AES-256-GCM ונשמרו בהצלחה');
            } else {
                $email = $ga->saveServiceAccountJson($user, $ctx->input('sa_json'));
                $ctx->flash('מפתח Google Service Account הוצפן ונשמר (' . $email . ') — הוסיפו אימייל זה כצופה בקונסול/אנליטיקס של הלקוח');
            }
        } catch (\InvalidArgumentException|HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        if ($returnTo !== '' && preg_match('#^/clients/\d+$#', $returnTo) === 1) {
            return Response::redirect($returnTo . '#admin-google-analytics-card');
        }
        return Response::redirect('/settings/users');
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
