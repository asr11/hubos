<?php

declare(strict_types=1);

namespace HubOS\Core;

use PDO;

/**
 * Session-based authentication with Argon2id hashing (bcrypt fallback),
 * session-id regeneration on login, idle timeout and login throttling.
 */
final class Auth
{
    private const MAX_FAILS_PER_EMAIL = 5;
    private const MAX_FAILS_PER_IP = 20;
    private const THROTTLE_WINDOW = '-15 minutes';

    /** @var array<string, mixed>|null */
    private ?array $user = null;
    private bool $resolved = false;
    private static ?string $dummyHash = null;

    /** @param array<string, mixed> $config */
    public function __construct(private PDO $pdo, private array $config)
    {
    }

    // ---- password hashing -------------------------------------------------

    public static function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, self::algo());
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo());
    }

    // ---- session ----------------------------------------------------------

    /** @param array<string, mixed> $config */
    public static function startSession(array $config, bool $https): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = match ((string) $config['cookie_secure']) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => $https,
        };
        $path = (string) $config['session_path'];
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Cannot create session directory');
        }
        ini_set('session.save_path', $path);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) (int) $config['session_idle_seconds']);

        session_name($secure ? '__Host-hubos' : 'hubos_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * @return array{ok:bool, error?:string, user?:array<string,mixed>}
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        if ($this->isThrottled($email, $ip)) {
            return ['ok' => false, 'error' => 'throttled'];
        }
        $user = $this->verifyCredentials($email, $password);
        $this->recordAttempt($email, $ip, $user !== null);
        if ($user === null) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $this->login($user);
        return ['ok' => true, 'user' => $user];
    }

    /** @return array<string, mixed>|null user row without password_hash */
    public function verifyCredentials(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 254 || $password === '' || strlen($password) > 1024) {
            return null;
        }
        $row = DB::run(
            $this->pdo,
            'SELECT u.*, t.status AS tenant_status FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.email = ?',
            [$email]
        )->fetch();

        // Always run a hash verification to keep timing similar for unknown emails.
        $hash = is_array($row) ? (string) $row['password_hash'] : self::dummyHash();
        $valid = password_verify($password, $hash);

        if (!is_array($row) || !$valid || $row['status'] !== 'active' || $row['tenant_status'] !== 'active') {
            return null;
        }
        if (self::needsRehash($hash)) {
            DB::run($this->pdo, 'UPDATE users SET password_hash = ? WHERE id = ?', [self::hashPassword($password), (int) $row['id']]);
        }
        unset($row['password_hash']);
        return $row;
    }

    public function isThrottled(string $email, string $ip): bool
    {
        $byEmail = (int) DB::run(
            $this->pdo,
            "SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at >= strftime('%Y-%m-%dT%H:%M:%fZ', 'now', ?)",
            [strtolower($email), self::THROTTLE_WINDOW]
        )->fetchColumn();
        if ($byEmail >= self::MAX_FAILS_PER_EMAIL) {
            return true;
        }
        $byIp = (int) DB::run(
            $this->pdo,
            "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= strftime('%Y-%m-%dT%H:%M:%fZ', 'now', ?)",
            [$ip, self::THROTTLE_WINDOW]
        )->fetchColumn();
        return $byIp >= self::MAX_FAILS_PER_IP;
    }

    public function recordAttempt(string $email, string $ip, bool $success): void
    {
        DB::run($this->pdo, 'INSERT INTO login_attempts (email, ip, success) VALUES (?, ?, ?)', [
            substr(strtolower($email), 0, 254), substr($ip, 0, 64), $success ? 1 : 0,
        ]);
    }

    /** @param array<string, mixed> $user */
    public function login(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION = [
            'uid'       => (int) $user['id'],
            'tid'       => (int) $user['tenant_id'],
            'login_at'  => time(),
            'last_seen' => time(),
        ];
        Csrf::rotate();
        DB::run($this->pdo, "UPDATE users SET last_login_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?", [(int) $user['id']]);
        $tenant = new Tenant($this->pdo, (int) $user['tenant_id']);
        Events::record($tenant, (int) $user['id'], 'user', (int) $user['id'],
            $user['client_id'] !== null ? (int) $user['client_id'] : null, 'auth.login');
        $this->user = null;
        $this->resolved = false;
    }

    public function logout(): void
    {
        $current = $this->user;
        $this->user = null;
        $this->resolved = true;
        if ($current !== null) {
            Events::record(new Tenant($this->pdo, (int) $current['tenant_id']), (int) $current['id'], 'user',
                (int) $current['id'], $current['client_id'] !== null ? (int) $current['client_id'] : null, 'auth.logout');
        }
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
        session_destroy();
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $uid = $_SESSION['uid'] ?? null;
        $tid = $_SESSION['tid'] ?? null;
        if (!is_int($uid) || !is_int($tid)) {
            return null;
        }
        $idle = (int) ($this->config['session_idle_seconds'] ?? 7200);
        if ((int) ($_SESSION['last_seen'] ?? 0) < time() - $idle) {
            $this->logout();
            return null;
        }
        $row = DB::run(
            $this->pdo,
            'SELECT u.id, u.tenant_id, u.email, u.name, u.role, u.client_id, u.status,
                    t.name AS tenant_name, t.status AS tenant_status
               FROM users u JOIN tenants t ON t.id = u.tenant_id
              WHERE u.id = ? AND u.tenant_id = ?',
            [$uid, $tid]
        )->fetch();
        if (!is_array($row) || $row['status'] !== 'active' || $row['tenant_status'] !== 'active') {
            $this->logout();
            return null;
        }
        $_SESSION['last_seen'] = time();
        return $this->user = $row;
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash(bin2hex(random_bytes(16)), self::algo());
    }
}
