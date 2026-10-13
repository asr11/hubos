<?php

declare(strict_types=1);

namespace HubOS\Modules\Clients;

use HubOS\Core\Context;
use HubOS\Core\Response;
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

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderList(Context $ctx, array $errors, array $old, int $status = 200): Response
    {
        $user = $ctx->requireUser();
        $service = new ClientService($ctx->tenant(), $ctx->permissions());
        return $ctx->render('clients/index', [
            'title'     => 'לקוחות',
            'active'    => 'clients',
            'clients'   => $service->list($user),
            'canCreate' => $ctx->can('clients.create'),
            'errors'    => $errors,
            'old'       => $old,
        ], $status);
    }
}
