<?php

declare(strict_types=1);

namespace HubOS\Modules\Vault;

use HubOS\Core\Events;
use HubOS\Core\HebrewText;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Core\ValidationException;
use HubOS\Core\Vault;
use HubOS\Modules\Clients\Client360Service;
use RuntimeException;

final class VaultService
{
    public function __construct(
        private Tenant $tenant,
        private Permissions $perms,
        private ?Vault $vault = null,
    ) {
        $this->vault ??= Vault::fromEnv();
    }

    /**
     * List vault item metadata (NEVER secret_ciphertext or data_json).
     *
     * @param array<string, mixed> $user
     * @param array<string, string|null> $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $user, array $filters = []): array
    {
        if (!Client360Service::isInternal($user)) {
            throw new HttpException(403, 'אין הרשאה לכספת');
        }
        $this->perms->authorize($user, 'vault.view');

        $sql = 'SELECT v.id, v.client_id, v.asset_id, v.title, v.service, v.url, v.username,
                       v.has_secret, v.encryption, v.status, v.created_at, v.updated_at,
                       c.name AS client_name, a.domain AS asset_domain,
                       (SELECT COUNT(*) FROM events e WHERE e.tenant_id = v.tenant_id AND e.entity_type = \'vault_item\' AND e.entity_id = v.id AND e.action = \'vault.secret_revealed\') AS reveal_count
                  FROM vault_items v
             LEFT JOIN clients c ON c.tenant_id = v.tenant_id AND c.id = v.client_id
             LEFT JOIN assets  a ON a.tenant_id = v.tenant_id AND a.id = v.asset_id
                 WHERE v.tenant_id = :tenant_id';
        $params = [];

        if (!empty($filters['client_id']) && ctype_digit((string) $filters['client_id'])) {
            $sql .= ' AND v.client_id = :cid';
            $params['cid'] = (int) $filters['client_id'];
        }
        if (!empty($filters['asset_id']) && ctype_digit((string) $filters['asset_id'])) {
            $sql .= ' AND v.asset_id = :aid';
            $params['aid'] = (int) $filters['asset_id'];
        }

        $sql .= ' ORDER BY v.id DESC LIMIT 1000';
        $rows = $this->tenant->select($sql, $params);

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static function (array $r) use ($q): bool {
                $haystack = implode(' ', [
                    (string) ($r['title'] ?? ''),
                    (string) ($r['service'] ?? ''),
                    (string) ($r['url'] ?? ''),
                    (string) ($r['username'] ?? ''),
                    (string) ($r['client_name'] ?? ''),
                    (string) ($r['asset_domain'] ?? ''),
                ]);
                return stripos($haystack, $q) !== false || HebrewText::score($q, $haystack) > 0;
            }));
        }

        return $rows;
    }

    /**
     * Get a single vault item's metadata + its immutable access audit log.
     * NEVER returns secret_ciphertext or data_json.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function get(array $user, int $id): array
    {
        if (!Client360Service::isInternal($user)) {
            throw new HttpException(403, 'אין הרשאה לכספת');
        }
        $this->perms->authorize($user, 'vault.view');

        $row = $this->tenant->selectOne(
            'SELECT v.id, v.client_id, v.asset_id, v.title, v.service, v.url, v.username,
                    v.has_secret, v.encryption, v.status, v.notes, v.created_at, v.updated_at,
                    c.name AS client_name, a.domain AS asset_domain
               FROM vault_items v
          LEFT JOIN clients c ON c.tenant_id = v.tenant_id AND c.id = v.client_id
          LEFT JOIN assets  a ON a.tenant_id = v.tenant_id AND a.id = v.asset_id
              WHERE v.tenant_id = :tenant_id AND v.id = :id',
            ['id' => $id]
        );
        if ($row === null) {
            throw new HttpException(404, 'רשומת כספת לא נמצאה');
        }

        $row['audit_log'] = $this->tenant->select(
            'SELECT e.id, e.action, e.actor_user_id, e.created_at, e.after_json, u.name AS actor_name
               FROM events e
          LEFT JOIN users u ON u.tenant_id = e.tenant_id AND u.id = e.actor_user_id
              WHERE e.tenant_id = :tenant_id AND e.entity_type = \'vault_item\' AND e.entity_id = :id
              ORDER BY e.id DESC LIMIT 100',
            ['id' => $id]
        );

        return $row;
    }

    /**
     * Create a vault item. Encrypts secret with AES-256-GCM if provided.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function create(array $actor, array $input): int
    {
        if (!Client360Service::isInternal($actor)) {
            throw new HttpException(403, 'אין הרשאה לכספת');
        }
        $this->perms->authorize($actor, 'vault.create');
        [$meta, $secret] = $this->validate($input);

        $cipher = null;
        $encryption = 'none';
        $hasSecret = 0;
        if ($secret !== '') {
            if ($this->vault === null) {
                throw new ValidationException(['secret' => 'HUBOS_VAULT_KEY לא מוגדר בשרת — לא ניתן לשמור סוד ללא הצפנה']);
            }
            $cipher = $this->vault->encrypt($secret);
            $encryption = 'aes-256-gcm';
            $hasSecret = 1;
        }

        return $this->tenant->transaction(function (Tenant $t) use ($actor, $meta, $cipher, $encryption, $hasSecret): int {
            $id = $t->insert('vault_items', $meta + [
                'has_secret'        => $hasSecret,
                'secret_ciphertext' => $cipher,
                'encryption'        => $encryption,
            ]);
            $auditMeta = $meta + ['has_secret' => $hasSecret, 'encryption' => $encryption];
            unset($auditMeta['notes']);
            Events::record($t, (int) $actor['id'], 'vault_item', $id, $meta['client_id'], 'vault.created', null, $auditMeta);
            return $id;
        });
    }

    /**
     * Update a vault item's metadata (and optionally rotate its encrypted secret).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function update(array $actor, int $id, array $input): void
    {
        if (!Client360Service::isInternal($actor)) {
            throw new HttpException(403, 'אין הרשאה לכספת');
        }
        $this->perms->authorize($actor, 'vault.update');
        $existing = $this->tenant->find('vault_items', $id);
        if ($existing === null) {
            throw new HttpException(404, 'רשומת כספת לא נמצאה');
        }

        [$meta, $secret] = $this->validate($input);
        $updateData = $meta + ['updated_at' => gmdate('Y-m-d\TH:i:s\Z')];
        $secretRotated = false;

        if ($secret !== '') {
            if ($this->vault === null) {
                throw new ValidationException(['secret' => 'HUBOS_VAULT_KEY לא מוגדר בשרת — לא ניתן לשמור סוד ללא הצפנה']);
            }
            $updateData['secret_ciphertext'] = $this->vault->encrypt($secret);
            $updateData['encryption'] = 'aes-256-gcm';
            $updateData['has_secret'] = 1;
            $secretRotated = true;
        }

        $beforeMeta = [
            'client_id'  => $existing['client_id'],
            'asset_id'   => $existing['asset_id'] ?? null,
            'title'      => $existing['title'],
            'service'    => $existing['service'],
            'url'        => $existing['url'],
            'username'   => $existing['username'],
            'status'     => $existing['status'],
            'encryption' => $existing['encryption'],
        ];
        $afterMeta = [
            'client_id'      => $meta['client_id'],
            'asset_id'       => $meta['asset_id'],
            'title'          => $meta['title'],
            'service'        => $meta['service'],
            'url'            => $meta['url'],
            'username'       => $meta['username'],
            'status'         => $meta['status'],
            'encryption'     => $updateData['encryption'] ?? $existing['encryption'],
            'secret_rotated' => $secretRotated,
        ];

        $this->tenant->transaction(function (Tenant $t) use ($actor, $id, $meta, $updateData, $beforeMeta, $afterMeta): void {
            $t->update('vault_items', $id, $updateData);
            Events::record($t, (int) $actor['id'], 'vault_item', $id, $meta['client_id'], 'vault.updated', $beforeMeta, $afterMeta);
        });
    }

    /**
     * Reveal a decrypted secret. Requires vault.reveal permission (owner/admin/staff; NEVER client_viewer).
     * Always writes vault.secret_revealed to the immutable events audit log.
     *
     * @param array<string, mixed> $actor
     */
    public function reveal(array $actor, int $id): string
    {
        if (!Client360Service::isInternal($actor)) {
            throw new HttpException(403, 'אין הרשאה לחשוף סוד');
        }
        $this->perms->authorize($actor, 'vault.reveal');

        $row = $this->tenant->find('vault_items', $id);
        if ($row === null) {
            throw new HttpException(404, 'רשומת כספת לא נמצאה');
        }
        if (($row['encryption'] ?? 'none') !== 'aes-256-gcm' || empty($row['secret_ciphertext'])) {
            throw new ValidationException(['secret' => 'לרשומה זו אין סוד מוצפן שמור במערכת']);
        }
        if ($this->vault === null) {
            throw new ValidationException(['secret' => 'HUBOS_VAULT_KEY לא מוגדר בשרת — לא ניתן לפענח סוד']);
        }

        try {
            $plaintext = $this->vault->decrypt((string) $row['secret_ciphertext']);
        } catch (RuntimeException) {
            throw new ValidationException(['secret' => 'פענוח הסוד נכשל (מפתח שגוי או מידע פגום)']);
        }

        $clientId = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        Events::record($this->tenant, (int) $actor['id'], 'vault_item', $id, $clientId, 'vault.secret_revealed', null, [
            'title'    => $row['title'],
            'service'  => $row['service'],
            'username' => $row['username'],
            'asset_id' => $row['asset_id'] ?? null,
        ]);

        return $plaintext;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array<string, scalar|null>, 1: string}
     */
    private function validate(array $input): array
    {
        $errors = [];
        $cidRaw = trim((string) ($input['client_id'] ?? ''));
        $clientId = ctype_digit($cidRaw) && (int) $cidRaw > 0 ? (int) $cidRaw : null;
        if ($clientId === null || $this->tenant->find('clients', $clientId) === null) {
            $errors['client_id'] = 'חובה לשייך רשומת כספת ללקוח קיים';
        }

        $aidRaw = trim((string) ($input['asset_id'] ?? ''));
        $assetId = null;
        if ($aidRaw !== '') {
            if (!ctype_digit($aidRaw) || (int) $aidRaw < 1) {
                $errors['asset_id'] = 'נכס לא תקין';
            } else {
                $asset = $this->tenant->find('assets', (int) $aidRaw);
                if ($asset === null) {
                    $errors['asset_id'] = 'הנכס לא נמצא';
                } elseif ($clientId !== null && $asset['client_id'] !== null && (int) $asset['client_id'] !== $clientId) {
                    $errors['asset_id'] = 'הנכס שנבחר שייך ללקוח אחר';
                } else {
                    $assetId = (int) $aidRaw;
                }
            }
        }

        $title = Str::clean((string) ($input['title'] ?? ''));
        if ($title === '' || Str::len($title) > 150) {
            $errors['title'] = 'חובה להזין כותרת (עד 150 תווים)';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $service = Str::limit(Str::clean((string) ($input['service'] ?? '')), 100);
        $url = Str::limit(Str::clean((string) ($input['url'] ?? '')), 500);
        $username = Str::limit(Str::clean((string) ($input['username'] ?? '')), 150);
        $status = Str::limit(Str::clean((string) ($input['status'] ?? 'active')), 40);
        $notes = Str::limit(Str::clean((string) ($input['notes'] ?? '')), 2000);
        $secret = isset($input['secret']) && is_string($input['secret']) ? trim($input['secret']) : '';

        return [[
            'client_id' => $clientId,
            'asset_id'  => $assetId,
            'title'     => $title,
            'service'   => $service !== '' ? $service : null,
            'url'       => $url !== '' ? $url : null,
            'username'  => $username !== '' ? $username : null,
            'status'    => $status !== '' ? $status : 'active',
            'notes'     => $notes !== '' ? $notes : null,
        ], $secret];
    }
}
