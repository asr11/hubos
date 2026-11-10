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
    public function mcp(Context $ctx): Response
    {
        $svc = new AgentApiService($ctx->pdo, $ctx->config);
        $auth = $this->authenticate($ctx, $svc);

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return Response::json([
                'ok' => true,
                'protocol' => 'mcp-http-jsonrpc-2.0',
                'server' => 'hubos-digital-ceo-mcp',
                'version' => '1.0.0',
                'company' => 'האב מערכות מתקדמות בע"מ — מערכות לא גיבורים',
            ]);
        }

        $raw = file_get_contents('php://input');
        $req = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (!is_array($req)) {
            $req = [];
        }
        $id = $req['id'] ?? null;
        $method = (string) ($req['method'] ?? '');
        $params = is_array($req['params'] ?? null) ? $req['params'] : [];

        if ($method === 'initialize') {
            return Response::json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => (object) []],
                    'serverInfo' => [
                        'name' => 'hubos-digital-ceo-mcp',
                        'version' => '1.0.0',
                    ],
                ],
            ]);
        }

        if ($method === 'notifications/initialized') {
            return Response::json(['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) []]);
        }

        if ($method === 'tools/list') {
            return Response::json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'tools' => [
                        [
                            'name' => 'hubos_status',
                            'description' => 'Check Digital CEO & HubOS sovereign status (0 tokens)',
                            'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                        ],
                        [
                            'name' => 'hubos_morning_brief',
                            'description' => 'Get daily executive brief: open tasks, projects, alerts (0 tokens)',
                            'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                        ],
                        [
                            'name' => 'hubos_clients_search',
                            'description' => 'Search or list clients in HubOS SSOT (0 tokens)',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'query' => ['type' => 'string', 'description' => 'Search query'],
                                ],
                            ],
                        ],
                        [
                            'name' => 'hubos_client_360',
                            'description' => 'Get full Client 360 profile (tasks, projects, assets, safe vault metadata)',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'client_id' => ['type' => 'integer', 'description' => 'Client ID'],
                                ],
                                'required' => ['client_id'],
                            ],
                        ],
                        [
                            'name' => 'hubos_log_event',
                            'description' => 'Record an append-only audit event in HubOS Board Gate log',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'action' => ['type' => 'string', 'description' => 'Event action slug (e.g. agent.proposal)'],
                                    'client_id' => ['type' => 'integer', 'description' => 'Optional client ID'],
                                    'summary' => ['type' => 'string', 'description' => 'Event summary'],
                                ],
                                'required' => ['action', 'summary'],
                            ],
                        ],
                    ],
                ],
            ]);
        }

        if ($method === 'tools/call') {
            $toolName = (string) ($params['name'] ?? '');
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            try {
                $data = match ($toolName) {
                    'hubos_status' => $svc->status($auth),
                    'hubos_morning_brief' => $svc->morning($auth),
                    'hubos_clients_search' => $svc->clients($auth, (string) ($args['query'] ?? '')),
                    'hubos_client_360' => $svc->client360($auth, (int) ($args['client_id'] ?? 0)),
                    'hubos_log_event' => $svc->recordEvent($auth, $args),
                    default => ['error' => 'Unknown tool: ' . $toolName],
                };
                return Response::json([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [
                            ['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                return Response::json([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'isError' => true,
                        'content' => [
                            ['type' => 'text', 'text' => 'Error: ' . $e->getMessage()],
                        ],
                    ],
                ]);
            }
        }

        return Response::json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method],
        ]);
    }

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
                if (is_string($k) && strtolower($k) === 'authorization' && is_string($v) && trim($v) !== '') {
                    return $v;
                }
            }
        }
        if (!empty($_GET['token']) && is_string($_GET['token'])) {
            return 'Bearer ' . trim($_GET['token']);
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (($qPos = strpos($uri, '?')) !== false) {
            parse_str(substr($uri, $qPos + 1), $qParams);
            if (!empty($qParams['token']) && is_string($qParams['token'])) {
                return 'Bearer ' . trim($qParams['token']);
            }
        }
        return null;
    }
}
