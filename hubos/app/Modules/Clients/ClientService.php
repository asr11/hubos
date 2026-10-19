<?php

declare(strict_types=1);

namespace HubOS\Modules\Clients;

use HubOS\Core\Events;
use HubOS\Core\HebrewText;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Core\ValidationException;

/** Clients domain logic. Every method authorizes, every write emits an event. */
final class ClientService
{
    public function __construct(private Tenant $tenant, private Permissions $perms)
    {
    }

    /**
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function list(array $user, int $limit = 1000): array
    {
        $this->perms->authorize($user, 'clients.view');
        if ($user['role'] === 'client_viewer') {
            if ($user['client_id'] === null) {
                return [];
            }
            $row = $this->tenant->find('clients', (int) $user['client_id']);
            return $row === null ? [] : [$row];
        }
        return $this->tenant->all('clients', [], 'name', 'ASC', $limit);
    }

    /**
     * Hebrew-safe search over name, tax id, email, phone, city and domain.
     * Ranked by HebrewText::score (exact word > word prefix > prefix-letter stripped > substring).
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function search(array $user, string $query): array
    {
        $rows = $this->list($user);
        if (HebrewText::tokens($query) === []) {
            return $rows;
        }
        $scored = [];
        foreach ($rows as $row) {
            $haystack = implode(' ', array_filter([
                $row['name'] ?? null, $row['tax_id'] ?? null, $row['email'] ?? null,
                $row['phone'] ?? null, $row['city'] ?? null, $row['domain'] ?? null,
            ], static fn ($v): bool => is_string($v) && $v !== ''));
            $score = HebrewText::score($query, $haystack);
            if ($score > 0) {
                $row['_score'] = $score;
                $scored[] = $row;
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b['_score'], $a['name']] <=> [$a['_score'], $b['name']]);
        return $scored;
    }

    /** @param array<string, mixed> $user */
    public function count(array $user): int
    {
        return count($this->list($user, 1000));
    }

    /**
     * Apply registry SUGGESTIONS to a client after an explicit user click.
     * Only the chosen fields (name/address/city) overwrite columns; the full
     * suggestion is kept under data_json.registry. Logged as client.registry_applied.
     *
     * @param array<string, mixed> $user
     * @param array<string, string|null> $suggestion
     * @param list<string> $fields
     */
    public function applyRegistry(array $user, int $clientId, array $suggestion, array $fields): void
    {
        $this->perms->authorize($user, 'clients.update');
        $this->tenant->transaction(function (Tenant $t) use ($user, $clientId, $suggestion, $fields): void {
            $client = $t->find('clients', $clientId);
            if ($client === null) {
                throw new HttpException(404, 'הלקוח לא נמצא');
            }
            $data = [];
            foreach (array_intersect(['name', 'address', 'city'], $fields) as $f) {
                $v = Str::clean((string) ($suggestion[$f] ?? ''));
                if ($v !== '' && Str::len($v) <= 200) {
                    $data[$f] = $v;
                }
            }
            $json = is_string($client['data_json']) ? json_decode($client['data_json'], true) : null;
            $json = is_array($json) ? $json : [];
            $json['registry'] = array_intersect_key($suggestion, array_flip([
                'tax_id', 'name', 'name_en', 'status', 'company_type', 'address', 'city', 'zip', 'incorporation_date', 'source', 'fetched_at',
            ])) + ['applied_at' => gmdate('Y-m-d\TH:i:s\Z'), 'applied_fields' => array_keys($data)];
            $data['data_json'] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $data['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $t->update('clients', $clientId, $data);

            $before = array_intersect_key($client, array_flip(['name', 'address', 'city']));
            $after = array_intersect_key($data, array_flip(['name', 'address', 'city'])) + ['registry' => $json['registry']];
            Events::record($t, (int) $user['id'], 'client', $clientId, $clientId, 'client.registry_applied', $before, $after);
        });
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     */
    public function create(array $user, array $input): int
    {
        $this->perms->authorize($user, 'clients.create');
        $data = self::validate($input);

        return $this->tenant->transaction(function (Tenant $t) use ($user, $data): int {
            $id = $t->insert('clients', $data);
            $after = $t->find('clients', $id);
            Events::record($t, (int) $user['id'], 'client', $id, $id, 'client.created', null, $after);
            return $id;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name:string, email:?string, phone:?string}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $name = Str::clean(is_string($input['name'] ?? null) ? $input['name'] : '');
        $email = Str::clean(is_string($input['email'] ?? null) ? $input['email'] : '');
        $phone = Str::clean(is_string($input['phone'] ?? null) ? $input['phone'] : '');

        if ($name === '' || Str::len($name) > 200) {
            $errors['name'] = 'שם לקוח הוא שדה חובה (עד 200 תווים)';
        }
        if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = 'כתובת אימייל לא תקינה';
        }
        if ($phone !== '' && preg_match('/^[0-9+\-() ]{6,25}$/', $phone) !== 1) {
            $errors['phone'] = 'מספר טלפון לא תקין';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'name'  => $name,
            'email' => $email === '' ? null : strtolower($email),
            'phone' => $phone === '' ? null : $phone,
        ];
    }
}
