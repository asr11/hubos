<?php

declare(strict_types=1);

namespace HubOS\Modules\Clients;

use HubOS\Core\Events;
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
    public function list(array $user, int $limit = 200): array
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

    /** @param array<string, mixed> $user */
    public function count(array $user): int
    {
        return count($this->list($user, 1000));
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
