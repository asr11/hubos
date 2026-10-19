<?php

declare(strict_types=1);

namespace HubOS\Modules\AgentApi;

use DateTimeImmutable;
use HubOS\Core\DB;
use HubOS\Core\Events;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Modules\Clients\Client360Service;
use HubOS\Modules\Clients\ClientService;
use HubOS\Modules\Dashboard\DashboardService;
use PDO;

/**
 * M7 — Digital-CEO Agent API service.
 *
 * Authenticates via Bearer token (`HUBOS_AGENT_TOKEN` env var) or an active
 * `agent` / `owner` / `admin` user, and exposes read-only executive endpoints
 * (Morning Dashboard, Clients search, Client 360) plus an append-only agent
 * audit event endpoint.
 *
 * Security invariant: Vault secrets (`secret_ciphertext`, plaintext secrets,
 * `notes`, `data_json`, `password_hash`) NEVER leave this service.
 */
final class AgentApiService
{
    private const FORBIDDEN_KEYS = [
        'secret_ciphertext',
        'secret',
        'password',
        'password_hash',
        'data_json',
    ];

    /** @param array<string, mixed> $config */
    public function __construct(
        private PDO $pdo,
        private array $config,
    ) {
    }

    /**
     * Authenticate via `Authorization: Bearer <token>` or an active session user
     * with role `agent`, `owner`, or `admin`.
     *
     * @param array<string, mixed>|null $sessionUser
     * @return array{user: array<string, mixed>, tenant: Tenant, perms: Permissions}
     */
    public function authenticate(?string $authorizationHeader, ?array $sessionUser = null): array
    {
        $bearer = self::extractBearer($authorizationHeader);
        $expected = trim((string) ($this->config['agent_token'] ?? ''));

        if ($bearer !== null) {
            if ($expected === '' || strlen($expected) < 16 || !hash_equals($expected, $bearer)) {
                throw new HttpException(401, 'אסימון סוכן לא תקין או לא מוגדר');
            }
            $tenantId = $this->resolveTenantId();
            $tenant = new Tenant($this->pdo, $tenantId);
            $user = $this->resolveAgentUser($tenantId);
            return [
                'user'   => $user,
                'tenant' => $tenant,
                'perms'  => new Permissions($tenant),
            ];
        }

        if (
            $sessionUser !== null
            && ($sessionUser['status'] ?? '') === 'active'
            && in_array((string) ($sessionUser['role'] ?? ''), ['agent', 'owner', 'admin'], true)
        ) {
            $tenantId = (int) $sessionUser['tenant_id'];
            $tenant = new Tenant($this->pdo, $tenantId);
            return [
                'user'   => $sessionUser,
                'tenant' => $tenant,
                'perms'  => new Permissions($tenant),
            ];
        }

        throw new HttpException(401, 'נדרש אסימון Bearer תקין של סוכן');
    }

    /**
     * @param array{user: array<string, mixed>, tenant: Tenant, perms: Permissions} $auth
     * @return array<string, mixed>
     */
    public function status(array $auth): array
    {
        return [
            'ok'          => true,
            'service'     => 'hubos-agent-api',
            'version'     => 'm7',
            'tenant_id'   => $auth['tenant']->id(),
            'actor'       => (string) ($auth['user']['name'] ?? 'Digital CEO Agent'),
            'role'        => (string) ($auth['user']['role'] ?? 'agent'),
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @param array{user: array<string, mixed>, tenant: Tenant, perms: Permissions} $auth
     * @return array<string, mixed>
     */
    public function morning(array $auth, ?DateTimeImmutable $now = null): array
    {
        $ceo = (new DashboardService($auth['tenant'], $auth['perms']))->ceo($auth['user'], $now);
        /** @var array<string, mixed> $clean */
        $clean = self::scrub($ceo);
        return [
            'ok'           => true,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'morning'      => $clean,
        ] + $clean;
    }

    /**
     * @param array{user: array<string, mixed>, tenant: Tenant, perms: Permissions} $auth
     * @return array<string, mixed>
     */
    public function clients(array $auth, string $query = ''): array
    {
        $svc = new ClientService($auth['tenant'], $auth['perms']);
        $rows = trim($query) === '' ? $svc->list($auth['user'], 500) : $svc->search($auth['user'], $query);
        foreach ($rows as &$r) {
            unset($r['data_json'], $r['tenant_id']);
        }
        unset($r);
        /** @var list<array<string, mixed>> $cleanRows */
        $cleanRows = self::scrub($rows);
        return [
            'ok'      => true,
            'query'   => $query,
            'count'   => count($cleanRows),
            'clients' => $cleanRows,
        ];
    }

    /**
     * @param array{user: array<string, mixed>, tenant: Tenant, perms: Permissions} $auth
     * @return array<string, mixed>
     */
    public function client360(array $auth, int $clientId, ?DateTimeImmutable $now = null): array
    {
        $card = (new Client360Service($auth['tenant'], $auth['perms']))->build($auth['user'], $clientId, $now);
        // Hard invariant: only safe metadata columns in vault items
        if (isset($card['vault']) && is_array($card['vault'])) {
            $allowed = array_flip(array_merge(Client360Service::VAULT_COLUMNS, ['origin_client_id', 'origin_name', 'is_own']));
            foreach ($card['vault'] as &$v) {
                if (is_array($v)) {
                    $v = array_intersect_key($v, $allowed);
                }
            }
            unset($v);
        }
        /** @var array<string, mixed> $clean */
        $clean = self::scrub($card);
        return ['ok' => true] + $clean;
    }

    /**
     * Record an append-only audit event from the Digital-CEO agent.
     *
     * @param array{user: array<string, mixed>, tenant: Tenant, perms: Permissions} $auth
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function recordEvent(array $auth, array $input): array
    {
        $rawAction = Str::clean(is_string($input['action'] ?? null) ? $input['action'] : 'agent.event');
        if (!str_starts_with($rawAction, 'agent.')) {
            $rawAction = 'agent.' . $rawAction;
        }
        if (preg_match('/^agent\.[a-z_]{1,32}$/', $rawAction) !== 1) {
            throw new HttpException(422, 'פעולת סוכן לא תקינה');
        }

        $clientId = null;
        if (isset($input['client_id']) && $input['client_id'] !== '' && $input['client_id'] !== null) {
            $cid = (int) $input['client_id'];
            if ($cid <= 0 || $auth['tenant']->find('clients', $cid) === null) {
                throw new HttpException(404, 'הלקוח לא נמצא');
            }
            $clientId = $cid;
        }

        $summary = Str::clean(is_string($input['summary'] ?? null) ? $input['summary'] : '');
        $actorId = isset($auth['user']['id']) && (int) $auth['user']['id'] > 0 ? (int) $auth['user']['id'] : null;

        $after = array_filter([
            'summary' => $summary !== '' ? Str::limit($summary, 500) : null,
            'source'  => 'digital-ceo',
        ], static fn ($v): bool => $v !== null);

        $eventId = Events::record(
            $auth['tenant'],
            $actorId,
            'agent',
            null,
            $clientId,
            $rawAction,
            null,
            $after !== [] ? $after : ['source' => 'digital-ceo'],
        );

        return [
            'ok'       => true,
            'event_id' => $eventId,
            'action'   => $rawAction,
        ];
    }

    public static function extractBearer(?string $header): ?string
    {
        if (!is_string($header) || trim($header) === '') {
            return null;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private function resolveTenantId(): int
    {
        $slug = trim((string) ($this->config['agent_tenant'] ?? 'hub'));
        if ($slug !== '') {
            $row = DB::run($this->pdo, "SELECT id FROM tenants WHERE slug = ? AND status = 'active' LIMIT 1", [$slug])->fetch();
            if (is_array($row) && isset($row['id'])) {
                return (int) $row['id'];
            }
        }
        $first = DB::run($this->pdo, "SELECT id FROM tenants WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetch();
        if (!is_array($first) || !isset($first['id'])) {
            throw new HttpException(503, 'לא נמצא ארגון פעיל במערכת');
        }
        return (int) $first['id'];
    }

    /** @return array<string, mixed> */
    private function resolveAgentUser(int $tenantId): array
    {
        $row = DB::run(
            $this->pdo,
            "SELECT id, tenant_id, email, name, role, client_id, status
               FROM users
              WHERE tenant_id = ? AND status = 'active' AND role IN ('agent', 'owner')
              ORDER BY CASE role WHEN 'agent' THEN 0 ELSE 1 END, id ASC
              LIMIT 1",
            [$tenantId]
        )->fetch();

        if (is_array($row)) {
            $row['role'] = 'agent';
            $row['client_id'] = null;
            return $row;
        }

        return [
            'id'        => 0,
            'tenant_id' => $tenantId,
            'email'     => 'agent@hubos.local',
            'name'      => 'Digital CEO Agent',
            'role'      => 'agent',
            'client_id' => null,
            'status'    => 'active',
        ];
    }

    private static function scrub(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::FORBIDDEN_KEYS, true)) {
                continue;
            }
            $out[$k] = self::scrub($v);
        }
        return $out;
    }
}
