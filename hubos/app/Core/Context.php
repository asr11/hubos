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

    public function flashError(string $message): void
    {
        $_SESSION['_flash_error'] = $message;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], int $status = 200, string $layout = 'layouts/app'): Response
    {
        $flash = $_SESSION['_flash'] ?? null;
        $flashError = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
        $user = $layout === 'layouts/app' ? $this->user() : null;
        $base = [
            'appName'    => (string) $this->config['app_name'],
            'user'       => $user,
            'flash'      => is_string($flash) ? $flash : null,
            'flashError' => is_string($flashError) ? $flashError : null,
            'ctx'        => $this,
            'timerBox'   => $user !== null ? $this->timerBox($user) : null,
        ];
        return Response::html($this->view->render($template, $data + $base, $layout), $status);
    }

    /**
     * Global header timer (M3): the running timer, or the start form options.
     * Null for users who cannot track time (e.g. client_viewer).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public function timerBox(array $user): ?array
    {
        if (!$this->can('time.track')) {
            return null;
        }
        try {
            $timer = (new \HubOS\Modules\Time\TimerService($this->tenant(), $this->permissions()))->current($user);
            if ($timer !== null) {
                return ['timer' => $timer, 'clients' => [], 'tasks' => []];
            }
            return [
                'timer'   => null,
                'clients' => $this->tenant()->select("SELECT id, name FROM clients WHERE tenant_id = :tenant_id AND status <> 'archived' ORDER BY name LIMIT 1000"),
                'tasks'   => $this->tenant()->select("SELECT id, title, client_id FROM tasks WHERE tenant_id = :tenant_id AND client_id IS NOT NULL
                                                       AND archived_at IS NULL AND status <> 'done' ORDER BY title LIMIT 1000"),
            ];
        } catch (\PDOException) {
            return null; // schema not migrated yet: the page still renders without the widget
        }
    }
}
