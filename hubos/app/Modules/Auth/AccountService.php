<?php

declare(strict_types=1);

namespace HubOS\Modules\Auth;

use HubOS\Core\Auth;
use HubOS\Core\DB;
use HubOS\Core\Events;
use HubOS\Core\Provisioning;
use HubOS\Core\Tenant;
use HubOS\Core\ValidationException;
use PDO;

/**
 * M10 — Self-service account actions available to EVERY authenticated role
 * (owner, staff, client_viewer, partner). Never logs or stores plaintext passwords.
 */
final class AccountService
{
    public function __construct(private PDO $pdo, private Tenant $tenant)
    {
    }

    /**
     * Change the logged-in user's own password.
     *
     * @param array<string, mixed> $user
     * @return bool false when the CURRENT password was wrong (caller records the failed attempt for throttling)
     * @throws ValidationException on invalid new password
     */
    public function changePassword(array $user, string $current, string $new, string $confirm): bool
    {
        $uid = (int) ($user['id'] ?? 0);
        $hash = DB::run($this->pdo, 'SELECT password_hash FROM users WHERE id = ? AND tenant_id = ?', [$uid, $this->tenant->id()])->fetchColumn();
        if (!is_string($hash) || $hash === '' || !Auth::verifyPassword($current, $hash)) {
            return false;
        }

        $errors = [];
        if (strlen($new) < Provisioning::MIN_PASSWORD) {
            $errors['new_password'] = 'הסיסמה החדשה חייבת להכיל לפחות ' . Provisioning::MIN_PASSWORD . ' תווים';
        } elseif (strlen($new) > 1024) {
            $errors['new_password'] = 'הסיסמה החדשה ארוכה מדי';
        } elseif ($new === $current) {
            $errors['new_password'] = 'הסיסמה החדשה חייבת להיות שונה מהנוכחית';
        }
        if ($new !== $confirm) {
            $errors['confirm_password'] = 'אישור הסיסמה אינו תואם לסיסמה החדשה';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $clientId = isset($user['client_id']) && $user['client_id'] !== null ? (int) $user['client_id'] : null;
        $this->tenant->transaction(function (Tenant $t) use ($uid, $new, $clientId): void {
            $t->update('users', $uid, ['password_hash' => Auth::hashPassword($new)]);
            Events::record($t, $uid, 'user', $uid, $clientId, 'user.password_changed', null, ['password_changed' => true, 'self_service' => true]);
        });
        return true;
    }

    /**
     * Number of partner-submitted leads still awaiting first handling (status 'new').
     * Drives the live 🔔 badge in the header for managers.
     */
    public function newPartnerLeadsCount(): int
    {
        $row = $this->tenant->selectOne(
            "SELECT COUNT(*) AS n FROM leads WHERE tenant_id = :tenant_id AND partner_user_id IS NOT NULL AND status = 'new'"
        );
        return (int) ($row['n'] ?? 0);
    }
}
