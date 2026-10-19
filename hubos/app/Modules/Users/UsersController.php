<?php

declare(strict_types=1);

namespace HubOS\Modules\Users;

use HubOS\Core\Context;
use HubOS\Core\HttpException;
use HubOS\Core\Response;
use HubOS\Modules\Clients\ClientService;
use InvalidArgumentException;
use PDOException;

final class UsersController
{
    private const USER_FIELDS = ['name', 'email', 'role', 'status', 'client_id', 'password'];

    public function index(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $service = $this->service($ctx);
        $clients = $ctx->can('clients.view')
            ? (new ClientService($ctx->tenant(), $ctx->permissions()))->list($user)
            : [];
        $auditEvents = $ctx->tenant()->select(
            "SELECT e.*, u.name AS actor_name
             FROM events e
             LEFT JOIN users u ON u.id = e.actor_user_id AND u.tenant_id = e.tenant_id
             WHERE e.tenant_id = :tenant_id
               AND (e.entity_type IN ('user', 'tenant_module') OR e.action LIKE 'permission.%' OR e.action LIKE 'module.%' OR e.action LIKE 'user.%')
             ORDER BY e.id DESC LIMIT 20"
        );

        return $ctx->render('users/index', [
            'title'       => 'משתמשים, תפקידים והרשאות',
            'active'      => 'users',
            'users'       => $service->list($user),
            'modules'     => $service->modulesStatus($user),
            'clients'     => $clients,
            'auditEvents' => $auditEvents,
        ]);
    }

    public function store(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        try {
            $this->service($ctx)->create($user, $this->input($ctx, self::USER_FIELDS));
            $ctx->flash('משתמש חדש נוצר ונרשם ביומן האירועים');
        } catch (InvalidArgumentException|HttpException $e) {
            $ctx->flashError($e->getMessage());
        } catch (PDOException) {
            $ctx->flashError('כתובת האימייל כבר קיימת במערכת');
        }
        return Response::redirect('/settings/users');
    }

    /** @param array<string, string> $params */
    public function update(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        try {
            $this->service($ctx)->update($user, (int) $params['id'], $this->input($ctx, self::USER_FIELDS));
            $ctx->flash('פרטי המשתמש עודכנו ונרשמו ביומן');
        } catch (InvalidArgumentException|HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/settings/users');
    }

    /** @param array<string, string> $params */
    public function setPermission(Context $ctx, array $params): Response
    {
        $user = $ctx->requireUser();
        $permission = $ctx->input('permission');
        $state = $ctx->input('state');
        $granted = match ($state) {
            'grant'  => true,
            'revoke' => false,
            default  => null,
        };
        try {
            $ctx->permissions()->setUserPermission($user, (int) $params['id'], $permission, $granted);
            $ctx->flash('הרשאת המשתמש עודכנה ונרשמה ביומן');
        } catch (InvalidArgumentException|HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/settings/users');
    }

    public function setModule(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $module = $ctx->input('module');
        $enabled = $ctx->input('enabled') === '1';
        try {
            $ctx->permissions()->setModule($user, $module, $enabled);
            $ctx->flash('סטטוס המודול עודכן ונרשם ביומן');
        } catch (InvalidArgumentException|HttpException $e) {
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect('/settings/users');
    }

    private function service(Context $ctx): UserService
    {
        return new UserService($ctx->pdo, $ctx->tenant(), $ctx->permissions());
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
}
