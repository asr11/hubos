<?php

declare(strict_types=1);

namespace HubOS\Modules\Assets;

use HubOS\Core\Context;
use HubOS\Core\Response;
use HubOS\Core\ValidationException;
use HubOS\Modules\Clients\Client360Service;

final class AssetsController
{
    private const FIELDS = ['client_id', 'kind', 'domain', 'provider', 'registrar', 'ip_address', 'expiry_date', 'ssl_status', 'ssl_expiry_date', 'status', 'notes'];

    public function index(Context $ctx): Response
    {
        return $this->renderIndex($ctx, [], [
            'client_id' => is_string($_GET['client_id'] ?? null) ? $_GET['client_id'] : '',
            'kind'      => 'domain',
            'status'    => 'active',
        ]);
    }

    public function store(Context $ctx): Response
    {
        $input = $this->input($ctx, self::FIELDS);
        try {
            $id = (new AssetService($ctx->tenant(), $ctx->permissions()))->create($ctx->requireUser(), $input);
        } catch (ValidationException $e) {
            return $this->renderIndex($ctx, $e->errors, $input, 422);
        }
        $ctx->flash('הנכס נוצר (#' . $id . ') ונרשם ביומן האירועים');
        return Response::redirect($this->back($ctx, '/assets'));
    }

    public function show(Context $ctx, array $params): Response
    {
        $svc = new AssetService($ctx->tenant(), $ctx->permissions());
        $asset = $svc->get($ctx->requireUser(), (int) $params['id']);
        return $this->renderShow($ctx, $asset, [], []);
    }

    public function update(Context $ctx, array $params): Response
    {
        $id = (int) $params['id'];
        $svc = new AssetService($ctx->tenant(), $ctx->permissions());
        $input = $this->input($ctx, self::FIELDS);
        try {
            $svc->update($ctx->requireUser(), $id, $input);
        } catch (ValidationException $e) {
            return $this->renderShow($ctx, $svc->get($ctx->requireUser(), $id), $e->errors, $input, 422);
        }
        $ctx->flash('הנכס עודכן ונרשם ביומן האירועים (לפני/אחרי)');
        return Response::redirect('/assets/' . $id);
    }

    public function inspect(Context $ctx, array $params): Response
    {
        $id = (int) $params['id'];
        $svc = new AssetService($ctx->tenant(), $ctx->permissions());
        try {
            $findings = $svc->inspect($ctx->requireUser(), $id);
            $ctx->flash('בדיקת DNS/SSL/WHOIS הושלמה עבור ' . $findings['host'] . ' ונרשמה ביומן האירועים');
        } catch (ValidationException $e) {
            $ctx->flashError(implode(' · ', $e->errors));
        }
        return Response::redirect($this->back($ctx, '/assets/' . $id));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderIndex(Context $ctx, array $errors, array $old, int $status = 200): Response
    {
        $user = $ctx->requireUser();
        $internal = Client360Service::isInternal($user);
        $filters = [];
        foreach (['client_id', 'kind', 'window', 'q'] as $k) {
            $filters[$k] = is_string($_GET[$k] ?? null) ? $_GET[$k] : null;
        }
        $result = (new AssetService($ctx->tenant(), $ctx->permissions()))->list($user, $filters);

        return $ctx->render('assets/index', [
            'title'      => 'נכסים, דומיינים ו-SSL',
            'active'     => 'assets',
            'assets'     => $result['rows'],
            'kpis'       => $result['kpis'],
            'filters'    => $filters,
            'internal'   => $internal,
            'canCreate'  => $ctx->can('assets.create'),
            'canInspect' => $ctx->can('assets.inspect'),
            'clients'    => $internal ? $ctx->tenant()->select('SELECT id, name FROM clients WHERE tenant_id = :tenant_id ORDER BY name LIMIT 1000') : [],
            'errors'     => $errors,
            'old'        => $old,
        ], $status);
    }

    /**
     * @param array<string, mixed> $asset
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderShow(Context $ctx, array $asset, array $errors, array $old, int $status = 200): Response
    {
        $user = $ctx->requireUser();
        $internal = Client360Service::isInternal($user);
        $old = $old !== [] ? $old : array_map(static fn ($v): string => (string) $v, array_intersect_key($asset, array_flip(self::FIELDS)));

        return $ctx->render('assets/show', [
            'title'      => 'נכס: ' . ($asset['domain'] ?? ('#' . (int) $asset['id'])),
            'active'     => 'assets',
            'asset'      => $asset,
            'internal'   => $internal,
            'canUpdate'  => $ctx->can('assets.update'),
            'canInspect' => $ctx->can('assets.inspect'),
            'canVault'   => $ctx->can('vault.view'),
            'clients'    => $internal ? $ctx->tenant()->select('SELECT id, name FROM clients WHERE tenant_id = :tenant_id ORDER BY name LIMIT 1000') : [],
            'errors'     => $errors,
            'old'        => $old,
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
