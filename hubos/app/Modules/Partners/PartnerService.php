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
}
