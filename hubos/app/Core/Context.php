<?php

declare(strict_types=1);

namespace HubOS\Core;

use PDO;

/** Per-request container handed to every controller. */
final class Context
{
    private ?Tenant $tenant = null;
    private ?Permissions $permissions = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        public readonly array $config,
        public readonly PDO $pdo,
        public readonly Auth $auth,
        public readonly View $view,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $this->auth->user();
    }

    /** @return array<string, mixed> */
    public function requireUser(): array
    {
        $user = $this->user();
        if ($user === null) {
            throw new HttpException(401, 'נדרשת התחברות');
        }
        return $user;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ??= new Tenant($this->pdo, (int) $this->requireUser()['tenant_id']);
    }

    public function permissions(): Permissions
    {
        return $this->permissions ??= new Permissions($this->tenant());
    }

    public function can(string $permission): bool
    {
        $user = $this->user();
        return $user !== null && $this->permissions()->can($user, $permission);
    }

    public function input(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    public function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) ? $ip : '';
    }

    public function flash(string $message): void
    {
        $_SESSION['_flash'] = $message;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], int $status = 200, string $layout = 'layouts/app'): Response
    {
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        $user = $layout === 'layouts/app' ? $this->user() : null;
        $base = [
            'appName' => (string) $this->config['app_name'],
            'user'    => $user,
            'flash'   => is_string($flash) ? $flash : null,
            'ctx'     => $this,
        ];
        return Response::html($this->view->render($template, $data + $base, $layout), $status);
    }
}
