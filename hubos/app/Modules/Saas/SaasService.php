<?php

declare(strict_types=1);

namespace HubOS\Modules\Saas;

use HubOS\Core\DB;
use HubOS\Core\Events;
use HubOS\Core\HttpException;
use HubOS\Core\Provisioning;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Core\Vault;
use InvalidArgumentException;
use PDO;

/**
 * Multi-Tenant SaaS Onboarding, Per-Tenant Encrypted Morning Read-Only Credentials,
 * and Subscription Plan Management.
 */
final class SaasService
{
    public const PLANS = [
        'trial'      => 'ניסיון 14 יום (Trial)',
        'pro'        => 'עסקי (Pro — ₪290/חודש)',
        'enterprise' => 'סוכנות / ארגון (Enterprise — ₪690/חודש)',
    ];

    public function __construct(
        private PDO $pdo,
        private Tenant $tenant,
        private ?Vault $vault = null,
    ) {
        $this->vault ??= Vault::fromEnv();
    }

    /** @param array<string, mixed> $user */
    public static function isPlatformOwner(array $user): bool
    {
        return (int) ($user['tenant_id'] ?? 0) === 1 && ($user['role'] ?? '') === 'owner';
    }

    /**
     * Save AES-256-GCM encrypted Read-Only Morning credentials for the current tenant.
     *
     * @param array<string, mixed> $actor
     */
    public function saveMorningCredentials(array $actor, string $keyId, string $keySecret): void
    {
        if (($actor['role'] ?? '') !== 'owner') {
            throw new HttpException(403, 'רק בעלים של הארגון יכול להגדיר מפתחות Morning');
        }
        $keyId = trim($keyId);
        $keySecret = trim($keySecret);
        if ($keyId === '' || strlen($keyId) > 120) {
            throw new InvalidArgumentException('חובה להזין מזהה מפתח (API Key ID) תקין מ-Morning');
        }
        if ($keySecret === '' || strlen($keySecret) > 250) {
            throw new InvalidArgumentException('חובה להזין סוד מפתח (Secret) תקין מ-Morning');
        }
        if ($this->vault === null) {
            throw new InvalidArgumentException('מפתח הצפנת שרת (HUBOS_VAULT_KEY) אינו מוגדר');
        }

        $cipher = $this->vault->encrypt($keySecret);
        $this->tenant->transaction(function (Tenant $t) use ($actor, $keyId, $cipher): void {
            self::upsertSetting($t, 'morning_key_id', $keyId);
            self::upsertSetting($t, 'morning_secret_cipher', $cipher);
            self::upsertSetting($t, 'morning_configured_at', gmdate('Y-m-d\TH:i:s\Z'));
            Events::record($t, (int) $actor['id'], 'tenant_setting', null, null, 'integration.morning_saved', null, [
                'morning_key_id_prefix' => substr($keyId, 0, 8) . '…',
                'encryption'            => 'aes-256-gcm',
            ]);
        });
    }

    /**
     * Decrypt Read-Only Morning credentials for a given tenant (never exposed in UI).
     *
     * @return array{key_id: string, key_secret: string}|null
     */
    public function getDecryptedMorningCredentials(): ?array
    {
        $idRow = $this->tenant->selectOne("SELECT value FROM tenant_settings WHERE tenant_id = :tenant_id AND key = 'morning_key_id'");
        $secRow = $this->tenant->selectOne("SELECT value FROM tenant_settings WHERE tenant_id = :tenant_id AND key = 'morning_secret_cipher'");
        if ($idRow === null || $secRow === null || empty($idRow['value']) || empty($secRow['value']) || $this->vault === null) {
            return null;
        }
        return [
            'key_id'     => (string) $idRow['value'],
            'key_secret' => $this->vault->decrypt((string) $secRow['value']),
        ];
    }

    /**
     * Return safe status metadata for the current tenant's Morning integration & subscription plan.
     *
     * @return array<string, mixed>
     */
    public function currentTenantMeta(): array
    {
        $rows = $this->tenant->select('SELECT key, value, updated_at FROM tenant_settings WHERE tenant_id = :tenant_id');
        $map = [];
        foreach ($rows as $r) {
            if ($r['key'] === 'morning_secret_cipher') {
                $map['morning_has_secret'] = !empty($r['value']);
                continue;
            }
            $map[(string) $r['key']] = $r['value'];
        }
        $plan = (string) ($map['saas_plan'] ?? ((int) $this->tenant->id() === 1 ? 'enterprise' : 'trial'));
        return [
            'morning_configured'    => !empty($map['morning_key_id']) && !empty($map['morning_has_secret']),
            'morning_key_id_masked' => !empty($map['morning_key_id']) ? substr((string) $map['morning_key_id'], 0, 8) . '…' : null,
            'morning_configured_at' => $map['morning_configured_at'] ?? null,
            'plan'                  => $plan,
            'plan_label'            => self::PLANS[$plan] ?? $plan,
            'billing_url'           => $map['saas_billing_url'] ?? null,
        ];
    }

    /**
     * Platform-owner list of all tenants on this HubOS instance.
     *
     * @param array<string, mixed> $actor
     * @return list<array<string, mixed>>
     */
    public function listTenants(array $actor): array
    {
        if (!self::isPlatformOwner($actor)) {
            throw new HttpException(403, 'גישה לניהול ארגוני SaaS שמורה לבעלי הפלטפורמה בלבד');
        }
        $tenants = DB::run(
            $this->pdo,
            'SELECT t.*,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS users_count,
                    (SELECT COUNT(*) FROM clients c WHERE c.tenant_id = t.id) AS clients_count,
                    (SELECT value FROM tenant_settings s WHERE s.tenant_id = t.id AND s.key = \'saas_plan\') AS plan,
                    (SELECT value FROM tenant_settings s WHERE s.tenant_id = t.id AND s.key = \'saas_billing_url\') AS billing_url,
                    (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM tenant_settings s WHERE s.tenant_id = t.id AND s.key = \'morning_secret_cipher\' AND s.value IS NOT NULL) AS morning_ready
               FROM tenants t
           ORDER BY t.id ASC'
        )->fetchAll();

        foreach ($tenants as &$t) {
            $p = (string) ($t['plan'] ?? ((int) $t['id'] === 1 ? 'enterprise' : 'trial'));
            $t['plan'] = $p;
            $t['plan_label'] = self::PLANS[$p] ?? $p;
        }
        unset($t);
        return $tenants;
    }

    /**
     * Provision a brand-new isolated Tenant + its Owner account.
     *
     * @param array<string, mixed>|null $actor null when self-serve signup is enabled
     * @param array<string, mixed> $input
     * @return array{tenant_id: int, owner_user_id: int}
     */
    public function provisionTenant(?array $actor, array $input): array
    {
        if ($actor !== null && !self::isPlatformOwner($actor)) {
            throw new HttpException(403, 'רק בעלי הפלטפורמה יכול להקים ארגון חדש ידנית');
        }
        $companyName = Str::clean((string) ($input['company_name'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $ownerName = Str::clean((string) ($input['owner_name'] ?? ''));
        $ownerEmail = strtolower(trim((string) ($input['owner_email'] ?? '')));
        $password = (string) ($input['owner_password'] ?? '');
        $plan = trim((string) ($input['plan'] ?? 'trial'));
        $billingUrl = trim((string) ($input['billing_url'] ?? ''));

        if ($companyName === '' || Str::len($companyName) > 160) {
            throw new InvalidArgumentException('חובה להזין שם חברה / סוכנות (עד 160 תווים)');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug) !== 1) {
            throw new InvalidArgumentException('מזהה הארגון (Slug) חייב להיות באנגלית קטנה ומקפים בלבד (2–63 תווים)');
        }
        if (DB::run($this->pdo, 'SELECT id FROM tenants WHERE slug = ?', [$slug])->fetchColumn() !== false) {
            throw new InvalidArgumentException('מזהה הארגון (Slug) כבר תפוס במערכת');
        }
        if (DB::run($this->pdo, 'SELECT id FROM users WHERE email = ?', [$ownerEmail])->fetchColumn() !== false) {
            throw new InvalidArgumentException('כתובת האימייל כבר רשומה במערכת');
        }
        if (!isset(self::PLANS[$plan])) {
            $plan = 'trial';
        }
        if ($billingUrl !== '' && (!str_starts_with($billingUrl, 'https://') || strlen($billingUrl) > 500)) {
            throw new InvalidArgumentException('קישור סליקה חייב להתחיל ב-https://');
        }

        $tid = Provisioning::ensureTenant($this->pdo, $slug, $companyName);
        $uid = Provisioning::createUser($this->pdo, $tid, $ownerEmail, $ownerName, 'owner', $password, null, null);

        $newTenant = new Tenant($this->pdo, $tid);
        self::upsertSetting($newTenant, 'saas_plan', $plan);
        if ($billingUrl !== '') {
            self::upsertSetting($newTenant, 'saas_billing_url', $billingUrl);
        }

        if ($actor !== null) {
            Events::record(new Tenant($this->pdo, 1), (int) $actor['id'], 'tenant', $tid, null, 'saas.tenant_provisioned', null, [
                'slug'        => $slug,
                'company'     => $companyName,
                'owner_email' => $ownerEmail,
                'plan'        => $plan,
            ]);
        }

        return ['tenant_id' => $tid, 'owner_user_id' => $uid];
    }

    /**
     * Update a tenant's plan, billing link, or active/suspended status (Platform Owner only).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function updateTenantPlan(array $actor, int $targetTenantId, array $input): void
    {
        if (!self::isPlatformOwner($actor)) {
            throw new HttpException(403, 'אין הרשאה');
        }
        $row = DB::run($this->pdo, 'SELECT * FROM tenants WHERE id = ?', [$targetTenantId])->fetch();
        if (!is_array($row)) {
            throw new HttpException(404, 'ארגון לא נמצא');
        }
        $plan = trim((string) ($input['plan'] ?? 'trial'));
        if (!isset(self::PLANS[$plan])) {
            throw new InvalidArgumentException('חבילה לא תקינה');
        }
        $status = trim((string) ($input['status'] ?? 'active'));
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new InvalidArgumentException('סטטוס לא תקין');
        }
        if ($targetTenantId === 1 && $status !== 'active') {
            throw new InvalidArgumentException('לא ניתן להשעות את ארגון הליבה הראשי');
        }
        $billingUrl = trim((string) ($input['billing_url'] ?? ''));
        if ($billingUrl !== '' && (!str_starts_with($billingUrl, 'https://') || strlen($billingUrl) > 500)) {
            throw new InvalidArgumentException('קישור סליקה חייב להתחיל ב-https://');
        }

        DB::run($this->pdo, 'UPDATE tenants SET status = ? WHERE id = ?', [$status, $targetTenantId]);
        $target = new Tenant($this->pdo, $targetTenantId);
        self::upsertSetting($target, 'saas_plan', $plan);
        self::upsertSetting($target, 'saas_billing_url', $billingUrl !== '' ? $billingUrl : null);

        Events::record(new Tenant($this->pdo, 1), (int) $actor['id'], 'tenant', $targetTenantId, null, 'saas.tenant_updated', null, [
            'plan'        => $plan,
            'status'      => $status,
            'billing_url' => $billingUrl !== '' ? $billingUrl : null,
        ]);
    }

    public function isPublicSignupEnabled(): bool
    {
        $row = DB::run($this->pdo, "SELECT value FROM tenant_settings WHERE tenant_id = 1 AND key = 'saas_public_signup'")->fetch();
        return is_array($row) && ($row['value'] ?? '0') === '1';
    }

    /** @param array<string, mixed> $actor */
    public function setPublicSignup(array $actor, bool $enabled): void
    {
        if (!self::isPlatformOwner($actor)) {
            throw new HttpException(403, 'אין הרשאה');
        }
        $rootTenant = new Tenant($this->pdo, 1);
        self::upsertSetting($rootTenant, 'saas_public_signup', $enabled ? '1' : '0');
        Events::record($rootTenant, (int) $actor['id'], 'tenant_setting', null, null, 'saas.public_signup_toggled', null, [
            'enabled' => $enabled,
        ]);
    }

    private static function upsertSetting(Tenant $t, string $key, ?string $value): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if ($t->count('tenant_settings', ['key' => $key]) > 0) {
            $t->updateWhere('tenant_settings', ['value' => $value, 'updated_at' => $now], ['key' => $key]);
        } else {
            $t->insert('tenant_settings', ['key' => $key, 'value' => $value, 'updated_at' => $now]);
        }
    }
}
