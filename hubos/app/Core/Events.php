<?php

declare(strict_types=1);

namespace HubOS\Core;

/** Append-only event log (the history behind Client 360). */
final class Events
{
    /** Keys that must never be written into the log. */
    private const REDACT = ['password', 'password_hash', 'secret', 'token', 'api_key'];

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function record(
        Tenant $tenant,
        ?int $actorUserId,
        string $entityType,
        ?int $entityId,
        ?int $clientId,
        string $action,
        ?array $before = null,
        ?array $after = null,
    ): int {
        if (preg_match('/^[a-z_]{1,40}$/', $entityType) !== 1 || preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $action) !== 1) {
            throw new \InvalidArgumentException('Invalid event entity type or action');
        }
        return $tenant->insert('events', [
            'actor_user_id' => $actorUserId,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'client_id'     => $clientId,
            'action'        => $action,
            'before_json'   => self::encode($before),
            'after_json'    => self::encode($after),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function recent(Tenant $tenant, int $limit = 10, ?int $clientId = null): array
    {
        $sql = 'SELECT e.*, u.name AS actor_name
                  FROM events e
             LEFT JOIN users u ON u.id = e.actor_user_id AND u.tenant_id = e.tenant_id
                 WHERE e.tenant_id = :tenant_id'
            . ($clientId !== null ? ' AND e.client_id = :client_id' : '')
            . ' ORDER BY e.id DESC LIMIT :lim';
        $params = ['lim' => max(1, min($limit, 500))];
        if ($clientId !== null) {
            $params['client_id'] = $clientId;
        }
        return $tenant->select($sql, $params);
    }

    /** @param array<string, mixed>|null $data */
    private static function encode(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        foreach (self::REDACT as $key) {
            unset($data[$key]);
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
