<?php

declare(strict_types=1);

namespace HubOS\Modules\Partners;

use HubOS\Core\Events;
use HubOS\Core\HttpException;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Core\ValidationException;

/**
 * Isolated Human Sales Partner Portal ("משולש הערך הנצחי של מיכאל").
 *
 * Security Invariants:
 *  - A partner ONLY sees leads (`leads.partner_user_id = uid`) and clients
 *    (`clients.partner_user_id = uid`) explicitly attributed to them.
 *  - Royalties are computed deterministically from realized paid Morning
 *    documents (`counts_as_income = 1`) on their own referred clients ONLY.
 *  - Zero access to company-wide finances, other clients, or vault items.
 */
final class PartnerService
{
    public const LEAD_STATUSES = [
        'new'         => 'חדש — ממתין לטיפול',
        'contacted'   => 'נוצר קשר',
        'qualified'   => 'בתהליך הצעת מחיר',
        'won'         => 'נסגרה עסקה ✔',
        'lost'        => 'לא רלוונטי',
    ];

    public function __construct(private Tenant $tenant)
    {
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function portal(array $user): array
    {
        if (($user['role'] ?? '') !== 'partner') {
            throw new HttpException(403, 'פורטל זה מיועד לשותפים וסוכני מכירות בלבד');
        }
        $uid = (int) ($user['id'] ?? 0);
        $pct = isset($user['commission_pct']) && is_numeric($user['commission_pct'])
            ? max(0.0, min(100.0, (float) $user['commission_pct']))
            : 10.0;

        $clients = $this->tenant->select(
            'SELECT c.id, c.name, c.status, c.domain, c.created_at,
                    COALESCE(SUM(CASE WHEN f.counts_as_income = 1 THEN f.amount ELSE 0 END), 0) AS realized_gross,
                    COALESCE(SUM(CASE WHEN f.counts_as_income = 1 THEN (f.amount - COALESCE(f.vat, 0)) ELSE 0 END), 0) AS realized_net,
                    COUNT(CASE WHEN f.counts_as_income = 1 THEN 1 END) AS paid_docs_count
               FROM clients c
          LEFT JOIN finance_documents f ON f.tenant_id = c.tenant_id AND f.client_id = c.id
              WHERE c.tenant_id = :tenant_id AND c.partner_user_id = :uid
           GROUP BY c.id
           ORDER BY realized_gross DESC, c.id DESC',
            ['uid' => $uid]
        );

        $totalGross = 0.0;
        $totalNet = 0.0;
        foreach ($clients as &$c) {
            $net = (float) $c['realized_net'];
            $gross = (float) $c['realized_gross'];
            $c['royalty_amount'] = round($net * ($pct / 100.0), 2);
            $totalGross += $gross;
            $totalNet += $net;
        }
        unset($c);

        $leads = $this->tenant->select(
            'SELECT id, name, email, phone, status, data_json, created_at
               FROM leads
              WHERE tenant_id = :tenant_id AND partner_user_id = :uid
              ORDER BY id DESC LIMIT 200',
            ['uid' => $uid]
        );
        foreach ($leads as &$l) {
            $dj = is_string($l['data_json'] ?? null) ? json_decode($l['data_json'], true) : [];
            $l['notes'] = is_array($dj) && isset($dj['notes']) ? (string) $dj['notes'] : '';
            unset($l['data_json']);
        }
        unset($l);

        return [
            'commission_pct' => $pct,
            'clients'        => $clients,
            'leads'          => $leads,
            'metrics'        => [
                'clients_count'   => count($clients),
                'leads_count'     => count($leads),
                'realized_gross'  => round($totalGross, 2),
                'realized_net'    => round($totalNet, 2),
                'royalties_total' => round($totalNet * ($pct / 100.0), 2),
            ],
        ];
    }

    /**
     * Submit a new lead attributed to the logged-in sales partner.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     */
    public function createLead(array $user, array $input): int
    {
        if (($user['role'] ?? '') !== 'partner') {
            throw new HttpException(403, 'רק שותף פעיל יכול להגיש ליד מפורטל השותפים');
        }
        $uid = (int) ($user['id'] ?? 0);
        $name = Str::clean((string) ($input['name'] ?? ''));
        $phone = Str::limit(Str::clean((string) ($input['phone'] ?? '')), 40);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $notes = Str::limit(Str::clean((string) ($input['notes'] ?? '')), 1000);

        $errors = [];
        if ($name === '' || Str::len($name) > 160) {
            $errors['name'] = 'חובה להזין שם לקוח או בית עסק (עד 160 תווים)';
        }
        if ($phone === '' && $email === '') {
            $errors['phone'] = 'חובה להזין מספר טלפון או כתובת אימייל ליצירת קשר';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'כתובת האימייל אינה תקינה';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $meta = ['notes' => $notes, 'partner_name' => (string) ($user['name'] ?? '')];
        return $this->tenant->transaction(static function (Tenant $t) use ($uid, $name, $phone, $email, $meta): int {
            $id = $t->insert('leads', [
                'partner_user_id' => $uid,
                'name'            => $name,
                'phone'           => $phone !== '' ? $phone : null,
                'email'           => $email !== '' ? $email : null,
                'status'          => 'new',
                'data_json'       => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            Events::record($t, $uid, 'lead', $id, null, 'partner.lead_submitted', null, [
                'name'            => $name,
                'phone'           => $phone !== '' ? $phone : null,
                'partner_user_id' => $uid,
            ]);
            return $id;
        });
    }

    /**
     * List active sales partners in the tenant (for Owner/Admin Client 360 selector and dashboard).
     *
     * @return list<array<string, mixed>>
     */
    public function listActivePartners(): array
    {
        return $this->tenant->select(
            "SELECT id, name, email, COALESCE(commission_pct, 10.0) AS commission_pct
               FROM users
              WHERE tenant_id = :tenant_id AND is_partner = 1 AND status = 'active'
              ORDER BY name ASC"
        );
    }

    /**
     * List all partner-submitted leads for internal managers (Owner/Admin).
     *
     * @param array<string, mixed> $actor
     * @return list<array<string, mixed>>
     */
    public function listAllPartnerLeads(array $actor, \HubOS\Core\Permissions $perms): array
    {
        if (!$perms->can($actor, 'clients.create') && !$perms->can($actor, 'users.manage')) {
            throw new HttpException(403, 'אין הרשאה לצפות בלידים של שותפים');
        }
        $rows = $this->tenant->select(
            'SELECT l.id, l.name, l.email, l.phone, l.status, l.partner_user_id, l.data_json, l.created_at,
                    u.name AS partner_name, COALESCE(u.commission_pct, 10.0) AS commission_pct
               FROM leads l
          LEFT JOIN users u ON u.tenant_id = l.tenant_id AND u.id = l.partner_user_id
              WHERE l.tenant_id = :tenant_id AND l.partner_user_id IS NOT NULL
              ORDER BY CASE l.status WHEN \'new\' THEN 0 WHEN \'contacted\' THEN 1 WHEN \'qualified\' THEN 2 WHEN \'won\' THEN 3 ELSE 4 END,
                       l.id DESC
              LIMIT 50'
        );
        foreach ($rows as &$r) {
            $dj = is_string($r['data_json'] ?? null) ? json_decode($r['data_json'], true) : [];
            $r['notes'] = is_array($dj) && isset($dj['notes']) ? (string) $dj['notes'] : '';
            $r['converted_client_id'] = is_array($dj) && isset($dj['converted_client_id']) ? (int) $dj['converted_client_id'] : null;
            unset($r['data_json']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Update a partner lead's status (internal managers only).
     *
     * @param array<string, mixed> $actor
     */
    public function updateLeadStatus(array $actor, \HubOS\Core\Permissions $perms, int $leadId, string $status): void
    {
        $perms->authorize($actor, 'clients.update');
        $status = Str::clean($status);
        if (!isset(self::LEAD_STATUSES[$status])) {
            throw new \InvalidArgumentException('סטטוס ליד לא תקין');
        }
        $lead = $this->tenant->find('leads', $leadId);
        if ($lead === null || empty($lead['partner_user_id'])) {
            throw new HttpException(404, 'ליד שותף לא נמצא');
        }
        $before = (string) ($lead['status'] ?? 'new');
        if ($before === $status) {
            return;
        }
        $this->tenant->transaction(static function (Tenant $t) use ($actor, $leadId, $before, $status): void {
            $t->update('leads', $leadId, ['status' => $status]);
            Events::record($t, (int) $actor['id'], 'lead', $leadId, null, 'partner.lead_status_updated',
                ['status' => $before], ['status' => $status]);
        });
    }

    /**
     * Convert a partner lead into an active client attributed to the referring partner (idempotent).
     *
     * @param array<string, mixed> $actor
     */
    public function convertLeadToClient(array $actor, \HubOS\Core\Permissions $perms, int $leadId): int
    {
        $perms->authorize($actor, 'clients.create');
        $lead = $this->tenant->find('leads', $leadId);
        if ($lead === null || empty($lead['partner_user_id'])) {
            throw new HttpException(404, 'ליד שותף לא נמצא');
        }
        $meta = is_string($lead['data_json'] ?? null) ? json_decode($lead['data_json'], true) : [];
        $meta = is_array($meta) ? $meta : [];
        if (!empty($meta['converted_client_id'])) {
            $existing = $this->tenant->find('clients', (int) $meta['converted_client_id']);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
        }

        $partnerId = (int) $lead['partner_user_id'];
        $name = Str::clean((string) ($lead['name'] ?? ''));
        $phone = Str::clean((string) ($lead['phone'] ?? ''));
        $email = strtolower(trim((string) ($lead['email'] ?? '')));
        $leadNotes = isset($meta['notes']) ? Str::clean((string) $meta['notes']) : '';
        $partnerName = isset($meta['partner_name']) ? Str::clean((string) $meta['partner_name']) : '';
        $notesParts = array_filter([
            $leadNotes !== '' ? $leadNotes : null,
            'הומר מליד שותף #' . $leadId . ($partnerName !== '' ? ' (' . $partnerName . ')' : ''),
        ]);

        return $this->tenant->transaction(static function (Tenant $t) use ($actor, $leadId, $partnerId, $name, $phone, $email, $notesParts, $meta): int {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $cid = $t->insert('clients', [
                'name'            => $name !== '' ? $name : ('לקוח מליד #' . $leadId),
                'phone'           => $phone !== '' ? $phone : null,
                'email'           => $email !== '' ? $email : null,
                'status'          => 'active',
                'partner_user_id' => $partnerId,
                'notes'           => implode(' · ', $notesParts),
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $meta['converted_client_id'] = $cid;
            $meta['converted_at'] = $now;
            $t->update('leads', $leadId, [
                'status'    => 'won',
                'data_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            Events::record($t, (int) $actor['id'], 'client', $cid, $cid, 'client.created', null, [
                'name'            => $name,
                'partner_user_id' => $partnerId,
                'from_lead_id'    => $leadId,
            ]);
            Events::record($t, (int) $actor['id'], 'lead', $leadId, $cid, 'partner.lead_converted',
                ['status' => 'new'], ['status' => 'won', 'client_id' => $cid, 'partner_user_id' => $partnerId]);
            return $cid;
        });
    }
}
