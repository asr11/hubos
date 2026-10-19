<?php

declare(strict_types=1);

namespace HubOS\Modules\Clients;

use HubOS\Core\Context;
use HubOS\Core\Http\NativeHttpClient;
use HubOS\Core\HttpException;
use HubOS\Core\Response;
use HubOS\Core\Str;
use HubOS\Core\ValidationException;

final class ClientsController
{
    public function index(Context $ctx): Response
    {
        return $this->renderList($ctx, [], ['name' => '', 'email' => '', 'phone' => '']);
    }

    public function store(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $input = ['name' => $ctx->input('name'), 'email' => $ctx->input('email'), 'phone' => $ctx->input('phone')];
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
            'canEnrich'     => $card['internal'] && $ctx->can('clients.update'),
            'suggestion'    => is_array($registry) ? $registry : null,
            'registryError' => is_string($registryError) ? $registryError : null,
        ]);
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

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderList(Context $ctx, array $errors, array $old, int $status = 200): Response
    {
        $user = $ctx->requireUser();
        $service = new ClientService($ctx->tenant(), $ctx->permissions());
        $q = is_string($_GET['q'] ?? null) ? Str::limit(Str::clean($_GET['q']), 100) : '';
        return $ctx->render('clients/index', [
            'title'     => 'לקוחות',
            'active'    => 'clients',
            'clients'   => $service->search($user, $q),
            'q'         => $q,
            'canCreate' => $ctx->can('clients.create'),
            'errors'    => $errors,
            'old'       => $old,
        ], $status);
    }
}
