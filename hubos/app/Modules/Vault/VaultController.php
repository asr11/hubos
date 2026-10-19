<?php

declare(strict_types=1);

namespace HubOS\Modules\Vault;

use HubOS\Core\Context;
use HubOS\Core\Response;
use HubOS\Core\ValidationException;
use HubOS\Core\Vault;

final class VaultController
{
    private const FIELDS = ['client_id', 'asset_id', 'title', 'service', 'url', 'username', 'secret', 'status', 'notes'];

    public function index(Context $ctx): Response
    {
        return $this->renderIndex($ctx, [], [
            'client_id' => is_string($_GET['client_id'] ?? null) ? $_GET['client_id'] : '',
            'asset_id'  => is_string($_GET['asset_id'] ?? null) ? $_GET['asset_id'] : '',
            'status'    => 'active',
        ]);
    }

    public function store(Context $ctx): Response
    {
        $input = $this->input($ctx, self::FIELDS);
        try {
            $id = (new VaultService($ctx->tenant(), $ctx->permissions()))->create($ctx->requireUser(), $input);
        } catch (ValidationException $e) {
            unset($input['secret']);
            return $this->renderIndex($ctx, $e->errors, $input, 422);
        }
        $ctx->flash('רשומת הכספת נוצרה (#' . $id . '), הסוד הוצפן ב-AES-256-GCM ונרשם ביומן האירועים');
        return Response::redirect($this->back($ctx, '/vault'));
    }

    public function show(Context $ctx, array $params): Response
    {
        $svc = new VaultService($ctx->tenant(), $ctx->permissions());
        $item = $svc->get($ctx->requireUser(), (int) $params['id']);
        return $this->renderShow($ctx, $item, [], [], null);
    }

    public function update(Context $ctx, array $params): Response
    {
        $id = (int) $params['id'];
        $svc = new VaultService($ctx->tenant(), $ctx->permissions());
        $input = $this->input($ctx, self::FIELDS);
        try {
            $svc->update($ctx->requireUser(), $id, $input);
        } catch (ValidationException $e) {
            unset($input['secret']);
            return $this->renderShow($ctx, $svc->get($ctx->requireUser(), $id), $e->errors, $input, null, 422);
        }
        $ctx->flash('רשומת הכספת עודכנה ונרשמה ביומן האירועים');
        return Response::redirect('/vault/' . $id);
    }

    public function reveal(Context $ctx, array $params): Response
    {
        $id = (int) $params['id'];
        $svc = new VaultService($ctx->tenant(), $ctx->permissions());
        $user = $ctx->requireUser();
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || $ctx->input('_format') === 'json';

        try {
            $secret = $svc->reveal($user, $id);
        } catch (ValidationException $e) {
            if ($wantsJson) {
                return Response::json(['ok' => false, 'errors' => $e->errors], 422);
            }
            return $this->renderShow($ctx, $svc->get($user, $id), $e->errors, [], null, 422);
        }

        $item = $svc->get($user, $id);
        if ($wantsJson) {
            return Response::json([
                'ok'       => true,
                'id'       => $id,
                'title'    => $item['title'],
                'username' => $item['username'],
                'secret'   => $secret,
            ]);
        }

        return $this->renderShow($ctx, $item, [], [], $secret);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderIndex(Context $ctx, array $errors, array $old, int $status = 200): Response
    {
        $user = $ctx->requireUser();
        $filters = [];
        foreach (['client_id', 'asset_id', 'q'] as $k) {
            $filters[$k] = is_string($_GET[$k] ?? null) ? $_GET[$k] : null;
        }
        $items = (new VaultService($ctx->tenant(), $ctx->permissions()))->list($user, $filters);

        return $ctx->render('vault/index', [
            'title'        => 'כספת גישות מוצפנת (AES-256-GCM)',
            'active'       => 'vault',
            'items'        => $items,
            'filters'      => $filters,
            'canCreate'    => $ctx->can('vault.create'),
            'canReveal'    => $ctx->can('vault.reveal'),
            'hasVaultKey'  => Vault::fromEnv() !== null,
            'clients'      => $ctx->tenant()->select('SELECT id, name FROM clients WHERE tenant_id = :tenant_id ORDER BY name LIMIT 1000'),
            'assetsList'   => $ctx->tenant()->select('SELECT id, domain, kind, client_id FROM assets WHERE tenant_id = :tenant_id ORDER BY domain LIMIT 1000'),
            'errors'       => $errors,
            'old'          => $old,
        ], $status);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderShow(Context $ctx, array $item, array $errors, array $old, ?string $revealedSecret, int $status = 200): Response
    {
        $old = $old !== [] ? $old : array_map(static fn ($v): string => (string) $v, array_intersect_key($item, array_flip(self::FIELDS)));
        unset($old['secret']);

        return $ctx->render('vault/show', [
            'title'          => 'כספת: ' . ($item['title'] ?? ('#' . (int) $item['id'])),
            'active'         => 'vault',
            'item'           => $item,
            'revealedSecret' => $revealedSecret,
            'canUpdate'      => $ctx->can('vault.update'),
            'canReveal'      => $ctx->can('vault.reveal'),
            'hasVaultKey'    => Vault::fromEnv() !== null,
            'clients'        => $ctx->tenant()->select('SELECT id, name FROM clients WHERE tenant_id = :tenant_id ORDER BY name LIMIT 1000'),
            'assetsList'     => $ctx->tenant()->select('SELECT id, domain, kind, client_id FROM assets WHERE tenant_id = :tenant_id ORDER BY domain LIMIT 1000'),
            'errors'         => $errors,
            'old'            => $old,
        ], $status);
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

    private function back(Context $ctx, string $default): string
    {
        $r = $ctx->input('_return');
        return preg_match('#^/(?!/)[A-Za-z0-9/_\-?=&%.\#]*$#', $r) === 1 ? $r : $default;
    }
}
