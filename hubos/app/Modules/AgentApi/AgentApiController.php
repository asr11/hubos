<?php

declare(strict_types=1);

namespace HubOS\Modules\AgentApi;

use HubOS\Core\Auth;
use HubOS\Core\Context;
use HubOS\Core\Response;

/**
 * M7 — HTTP Controller for the Digital-CEO Agent API (`/api/agent/*`).
 */
final class AgentApiController
{
    public function status(Context $ctx): Response
    {
        $svc = new AgentApiService($ctx->pdo, $ctx->config);
        $auth = $this->authenticate($ctx, $svc);
        return Response::json($svc->status($auth));
    }

    public function morning(Context $ctx): Response
    {
        $svc = new AgentApiService($ctx->pdo, $ctx->config);
        $auth = $this->authenticate($ctx, $svc);
        return Response::json($svc->morning($auth));
    }

    public function clients(Context $ctx): Response
    {
        $svc = new AgentApiService($ctx->pdo, $ctx->config);
        $auth = $this->authenticate($ctx, $svc);
        $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        return Response::json($svc->clients($auth, $q));
    }

    /** @param array<string, string> $params */
    public function client360(Context $ctx, array $params): Response
    {
        $svc = new AgentApiService($ctx->pdo, $ctx->config);
        $auth = $this->authenticate($ctx, $svc);
        return Response::json($svc->client360($auth, (int) ($params['id'] ?? 0)));
    }

    public function recordEvent(Context $ctx): Response
    {
        $svc = new AgentApiService($ctx->pdo, $ctx->config);
        $auth = $this->authenticate($ctx, $svc);

        $input = $_POST;
        if ($input === []) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $input = $decoded;
                }
            }
        }
        return Response::json($svc->recordEvent($auth, $input), 201);
    }

    /**
     * @return array{user: array<string, mixed>, tenant: \HubOS\Core\Tenant, perms: \HubOS\Core\Permissions}
     */
    private function authenticate(Context $ctx, AgentApiService $svc): array
    {
        $header = self::authorizationHeader();
        $sessionUser = null;
        if ($header === null) {
            Auth::startSession($ctx->config, false);
            $sessionUser = $ctx->user();
        }
        return $svc->authenticate($header, $sessionUser);
    }

    private static function authorizationHeader(): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                return $_SERVER[$key];
            }
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (is_string($k) && strtolower($k) === 'authorization' && is_string($v)) {
                    return $v;
                }
            }
        }
        return null;
    }
}
