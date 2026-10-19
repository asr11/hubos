<?php

declare(strict_types=1);

namespace HubOS\Modules\Clients;

use DateTimeImmutable;
use DateTimeZone;
use HubOS\Core\Events;
use HubOS\Core\HttpException;
use HubOS\Core\Lang;
use HubOS\Core\Permissions;
use HubOS\Core\Tenant;
use HubOS\Modules\Time\HoursBankService;

/**
 * Client 360: one call returns everything about a client, rolled up over its
 * family (ancestors via parent_client_id + all descendants). Every row is
 * labeled with the client it originates from (origin_client_id/origin_name).
 *
 * Rules:
 *  - Income uses finance_documents.counts_as_income (accounting rule), never naive sums.
 *  - Vault: metadata columns only (VAULT_COLUMNS). Secrets/notes/data_json never leave the DB.
 *  - data_json is stripped from every row (free-form legacy blobs may hold anything).
 *  - client_viewer: own linked client only, no family roll-up, no vault, no audit events.
 */
final class Client360Service
{
    public const TABS = [
        'overview'     => 'סקירה',
        'contacts'     => 'אנשי קשר',
        'interactions' => 'שיחות ותקשורת',
        'work'         => 'עבודה',
        'time'         => 'זמנים',
        'finance'      => 'כספים',
        'collection'   => 'גבייה',
        'sales'        => 'מכירות',
        'assets'       => 'נכסים',
        'access'       => 'גישות',
        'timeline'     => 'התפתחות וציר זמן',
    ];

    /** Vault columns that are safe to expose. NEVER secret_ciphertext, notes, data_json. */
    public const VAULT_COLUMNS = ['id', 'client_id', 'title', 'service', 'url', 'username', 'has_secret', 'encryption', 'status', 'created_at', 'updated_at'];

    /** Morning statuses that mean "not fully paid" on the document itself. */
    public const OPEN_STATUSES = ['unpaid', 'pending', 'partial'];

    private const MAX_DEPTH = 6;

    public function __construct(private Tenant $tenant, private Permissions $perms)
    {
    }

    /** @param array<string, mixed> $user */
    public static function isInternal(array $user): bool
    {
        return ($user['role'] ?? '') !== 'client_viewer';
    }

    /**
     * Authorize and load a client: 404 outside the tenant, 403 for a viewer's foreign client.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function authorizeClient(array $user, int $clientId): array
    {
        $this->perms->authorize($user, 'clients.view');
        $client = $this->tenant->find('clients', $clientId);
        if ($client === null) {
            throw new HttpException(404, 'הלקוח לא נמצא');
        }
        if (!self::isInternal($user) && (int) ($user['client_id'] ?? 0) !== $clientId) {
            throw new HttpException(403, 'אין הרשאה לצפות בלקוח זה');
        }
        return $client;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function build(array $user, int $clientId, ?DateTimeImmutable $now = null): array
    {
        $client = $this->authorizeClient($user, $clientId);
        $internal = self::isInternal($user);
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Jerusalem'));
        $year = $now->format('Y');
        $month = $now->format('Y-m');

        // ---------------------------------------------------------- family
        $ancestors = $internal ? $this->ancestors($client) : [];
        $descendants = $internal ? $this->descendants($clientId) : [];
        $children = array_values(array_filter($descendants, static fn (array $c): bool => (int) $c['parent_client_id'] === $clientId));
        $names = [$clientId => (string) $client['name']];
        foreach ([...$ancestors, ...$descendants] as $c) {
            $names[(int) $c['id']] = (string) $c['name'];
        }
        $ids = array_keys($names);

        // ---------------------------------------------------------- rows
        $contacts = $this->label($this->rows('client_contacts', $ids, 'is_primary DESC, name ASC'), $names, $clientId);
        $interactions = $this->label($this->rows('interactions', $ids, 'COALESCE(occurred_at, created_at) DESC'), $names, $clientId);
        $projects = $this->label($this->rows('projects', $ids, 'created_at DESC'), $names, $clientId);
        $tasks = $this->label($this->tasks($ids, array_map(static fn (array $p): int => (int) $p['id'], $projects)), $names, $clientId);
        // M4: archived work is hidden; viewers see only client-visible items.
        $visible = static fn (array $r): bool => $r['archived_at'] === null && ($internal || (int) $r['client_visible'] === 1);
        $projects = array_values(array_filter($projects, $visible));
        $tasks = array_values(array_filter($tasks, $visible));
        $timeEntries = $this->label($this->rows('time_entries', $ids, 'COALESCE(entry_date, started_at, created_at) DESC'), $names, $clientId);
        $banks = $this->label($this->rows('hours_banks', $ids, 'status ASC, id ASC'), $names, $clientId);
        $finance = $this->label($this->rows('finance_documents', $ids, 'doc_date DESC, id DESC'), $names, $clientId);
        $leads = $this->label($this->rows('leads', $ids, 'created_at DESC'), $names, $clientId);
        $quotes = $this->label($this->rows('quotes', $ids, 'created_at DESC'), $names, $clientId);
        $assets = $this->label($this->rows('assets', $ids, 'expiry_date IS NULL, expiry_date ASC, id ASC'), $names, $clientId);
        $vault = $internal ? $this->label($this->rows('vault_items', $ids, 'id ASC', self::VAULT_COLUMNS), $names, $clientId) : [];
        $events = $internal ? $this->label($this->events($ids), $names, $clientId) : [];

        foreach ($finance as &$doc) {
            $doc['kind_label'] = self::docKindLabel($doc['doc_kind'] ?? null, (string) $doc['doc_type']);
            $doc['year'] = substr((string) ($doc['doc_date'] ?? ''), 0, 4) ?: '—';
        }
        unset($doc);

        // ---------------------------------------------------------- time
        // M3: usage / thresholds (80%, 90%) / monthly rollover via HoursBankService.
        $bankService = new HoursBankService($this->tenant);
        $bankRemaining = null;
        $ownActiveBank = null;
        foreach ($banks as &$bank) {
            $bank = $bankService->summarize($bank, $month);
            if (($bank['status'] ?? '') === 'active') {
                $bankRemaining = ($bankRemaining ?? 0.0) + $bank['remaining_hours'];
                if ($bank['is_own'] && $ownActiveBank === null) {
                    $ownActiveBank = $bank;
                }
            }
        }
        unset($bank);
        $hoursThisMonth = 0.0;
        $hoursTotal = 0.0;
        foreach ($timeEntries as $te) {
            $h = (int) $te['duration_seconds'] / 3600;
            $hoursTotal += $h;
            $date = (string) ($te['entry_date'] ?? substr((string) ($te['started_at'] ?? ''), 0, 10));
            if (str_starts_with($date, $month)) {
                $hoursThisMonth += $h;
            }
        }
        $timeEntryCount = count($timeEntries);
        if (!$internal) {
            $timeEntries = []; // client_viewer: totals only, never internal descriptions
        }

        // ---------------------------------------------------------- money (accounting rule)
        $incomeByYear = [];
        $incomeByOrigin = [];
        $collection = [];
        $openDocs = [];
        foreach ($finance as $doc) {
            $y = $doc['year'];
            $amount = (float) $doc['amount'];
            if ((int) $doc['counts_as_income'] === 1) {
                $incomeByYear[$y] = ($incomeByYear[$y] ?? 0.0) + $amount;
                $incomeByOrigin[$doc['origin_name']] = ($incomeByOrigin[$doc['origin_name']] ?? 0.0) + $amount;
            }
            $bucket = match ($doc['doc_kind'] ?? null) {
                'transaction_account', 'tax_invoice' => 'invoices',
                'tax_invoice_receipt'                => 'invoice_receipts',
                'receipt'                            => 'receipts',
                default                              => 'other',
            };
            $collection[$y] ??= ['invoices' => [0, 0.0], 'invoice_receipts' => [0, 0.0], 'receipts' => [0, 0.0], 'other' => [0, 0.0],
                'receipts_income' => 0, 'receipts_matched' => 0, 'income' => 0.0];
            $collection[$y][$bucket][0]++;
            $collection[$y][$bucket][1] += $amount;
            if ($bucket === 'receipts') {
                $collection[$y]['receipts_income'] += (int) $doc['counts_as_income'];
                $collection[$y]['receipts_matched'] += $doc['matched_document_id'] !== null ? 1 : 0;
            }
            if ((int) $doc['counts_as_income'] === 1) {
                $collection[$y]['income'] += $amount;
            }
            if ($bucket !== 'receipts' && in_array((string) $doc['status'], self::OPEN_STATUSES, true)) {
                $doc['open_amount'] = max(0.0, $amount - (float) ($doc['paid_amount'] ?? 0));
                $openDocs[] = $doc;
            }
        }
        krsort($incomeByYear);
        krsort($collection);
        arsort($incomeByOrigin);
        $openAmount = array_sum(array_map(static fn (array $d): float => $d['open_amount'], $openDocs));

        // ---------------------------------------------------------- overview + timeline
        $openTasks = array_values(array_filter($tasks, static fn (array $t): bool => !in_array($t['status'], ['done', 'cancelled', 'closed'], true)));
        $alerts = $this->alerts($assets, $banks, $openDocs, $now);
        $timeline = $this->timeline($events, $interactions, $finance, $projects, $tasks, $leads, $quotes);

        $counts = [
            'overview'     => count($alerts) + count($openTasks),
            'contacts'     => count($contacts),
            'interactions' => count($interactions),
            'work'         => count($projects) + count($tasks),
            'time'         => $timeEntryCount,
            'finance'      => count($finance),
            'collection'   => count($openDocs),
            'sales'        => count($leads) + count($quotes),
            'assets'       => count($assets),
            'access'       => count($vault),
            'timeline'     => count($timeline),
        ];

        unset($client['data_json']);
        return [
            'client'       => $client,
            'registry'     => $this->registryData($clientId),
            'scope'        => $internal ? 'family' : 'self',
            'internal'     => $internal,
            'parent'       => $ancestors[0] ?? null,
            'ancestors'    => $ancestors,
            'children'     => $children,
            'descendants'  => $descendants,
            'family'       => $names,
            'contacts'     => $contacts,
            'interactions' => $interactions,
            'projects'     => $projects,
            'tasks'        => $tasks,
            'open_tasks'   => $openTasks,
            'time_entries' => $timeEntries,
            'hours_banks'  => $banks,
            'own_bank'     => $ownActiveBank,
            'finance'      => $finance,
            'open_docs'    => $openDocs,
            'collection'   => $collection,
            'leads'        => $leads,
            'quotes'       => $quotes,
            'assets'       => $assets,
            'vault'        => $vault,
            'events'       => $events,
            'timeline'     => $timeline,
            'alerts'       => $alerts,
            'counts'       => $counts,
            'metrics'      => [
                'year'               => $year,
                'month'              => $month,
                'income_this_year'   => $incomeByYear[$year] ?? 0.0,
                'income_by_year'     => $incomeByYear,
                'income_by_origin'   => $incomeByOrigin,
                'income_total'       => array_sum($incomeByYear),
                'hours_this_month'   => $hoursThisMonth,
                'hours_total'        => $hoursTotal,
                'bank_remaining'     => $bankRemaining,
                'open_docs_count'    => count($openDocs),
                'open_docs_amount'   => $openAmount,
                // A reliable debt balance needs a full invoice↔receipt link, which the data does not have.
                'balance_reliable'   => false,
            ],
        ];
    }

    /**
     * Log that vault METADATA was shown (no secrets are ever shown).
     *
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $items
     */
    public function logVaultView(array $user, int $clientId, array $items): void
    {
        Events::record($this->tenant, (int) $user['id'], 'client', $clientId, $clientId, 'vault.metadata_viewed', null, [
            'item_ids' => array_map(static fn (array $i): int => (int) $i['id'], $items),
            'count'    => count($items),
        ]);
    }

    public static function docKindLabel(?string $kind, string $code): string
    {
        $key = 'doc_kind.' . (string) $kind;
        $label = ($kind !== null && Lang::has($key)) ? Lang::t($key) : 'סוג לא ידוע';
        return $label . ' (' . $code . ')';
    }

    // ================================================================== internals

    /**
     * @param array<string, mixed> $client
     * @return list<array<string, mixed>> nearest parent first
     */
    private function ancestors(array $client): array
    {
        $out = [];
        $seen = [(int) $client['id'] => true];
        $parentId = $client['parent_client_id'];
        for ($i = 0; $i < self::MAX_DEPTH && $parentId !== null; $i++) {
            $pid = (int) $parentId;
            if (isset($seen[$pid])) {
                break; // cycle guard
            }
            $p = $this->tenant->selectOne('SELECT id, name, status, tax_id, parent_client_id FROM clients WHERE tenant_id = :tenant_id AND id = :id', ['id' => $pid]);
            if ($p === null) {
                break;
            }
            $seen[$pid] = true;
            $out[] = $p;
            $parentId = $p['parent_client_id'];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> breadth-first */
    private function descendants(int $clientId): array
    {
        $out = [];
        $seen = [$clientId => true];
        $frontier = [$clientId];
        for ($i = 0; $i < self::MAX_DEPTH && $frontier !== []; $i++) {
            [$in, $params] = self::in($frontier, 'p');
            $rows = $this->tenant->select(
                "SELECT id, name, status, tax_id, parent_client_id FROM clients WHERE tenant_id = :tenant_id AND parent_client_id IN ({$in}) ORDER BY name",
                $params
            );
            $frontier = [];
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $out[] = $r;
                    $frontier[] = $id;
                }
            }
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @param list<string>|null $columns
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, array $ids, string $order, ?array $columns = null): array
    {
        if (!in_array($table, Tenant::TABLES, true)) {
            throw new \InvalidArgumentException('Invalid table');
        }
        $cols = $columns === null ? '*' : implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $columns));
        [$in, $params] = self::in($ids, 'c');
        return $this->tenant->select(
            "SELECT {$cols} FROM \"{$table}\" WHERE tenant_id = :tenant_id AND client_id IN ({$in}) ORDER BY {$order} LIMIT 2000",
            $params
        );
    }

    /**
     * Tasks of the family, plus tasks with no client that belong to a family project.
     *
     * @param list<int> $ids
     * @param list<int> $projectIds
     * @return list<array<string, mixed>>
     */
    private function tasks(array $ids, array $projectIds): array
    {
        [$in, $params] = self::in($ids, 'c');
        $sql = "SELECT t.*, COALESCE(t.client_id, p.client_id) AS client_id, p.name AS project_name
                  FROM tasks t LEFT JOIN projects p ON p.tenant_id = t.tenant_id AND p.id = t.project_id
                 WHERE t.tenant_id = :tenant_id AND (t.client_id IN ({$in})";
        if ($projectIds !== []) {
            [$pin, $pparams] = self::in($projectIds, 'p');
            $sql .= " OR (t.client_id IS NULL AND t.project_id IN ({$pin}))";
            $params += $pparams;
        }
        $sql .= ') ORDER BY t.status = \'done\', t.due_at IS NULL, t.due_at, t.id DESC LIMIT 2000';
        return $this->tenant->select($sql, $params);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function events(array $ids): array
    {
        [$in, $params] = self::in($ids, 'c');
        return $this->tenant->select(
            "SELECT e.id, e.client_id, e.entity_type, e.entity_id, e.action, e.created_at, u.name AS actor_name
               FROM events e LEFT JOIN users u ON u.tenant_id = e.tenant_id AND u.id = e.actor_user_id
              WHERE e.tenant_id = :tenant_id AND e.client_id IN ({$in})
              ORDER BY e.id DESC LIMIT 300",
            $params
        );
    }

    /** @return array<string, mixed>|null registry data previously applied by a user */
    private function registryData(int $clientId): ?array
    {
        $row = $this->tenant->selectOne(
            "SELECT json_extract(data_json, '$.registry') AS r FROM clients WHERE tenant_id = :tenant_id AND id = :id",
            ['id' => $clientId]
        );
        $data = is_string($row['r'] ?? null) ? json_decode($row['r'], true) : null;
        return is_array($data) ? $data : null;
    }

    /**
     * Strip blobs and label each row with its origin client.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, string> $names
     * @return list<array<string, mixed>>
     */
    private function label(array $rows, array $names, int $selfId): array
    {
        foreach ($rows as &$r) {
            unset($r['data_json'], $r['secret_ciphertext'], $r['tenant_id']);
            $origin = $r['client_id'] !== null ? (int) $r['client_id'] : null;
            $r['origin_client_id'] = $origin;
            $r['origin_name'] = $origin !== null ? ($names[$origin] ?? ('#' . $origin)) : '—';
            $r['is_own'] = $origin === $selfId;
        }
        unset($r);
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $assets
     * @param list<array<string, mixed>> $banks
     * @param list<array<string, mixed>> $openDocs
     * @return list<array{level:string, text:string}>
     */
    private function alerts(array $assets, array $banks, array $openDocs, DateTimeImmutable $now): array
    {
        $out = [];
        $today = $now->format('Y-m-d');
        $soon = $now->modify('+60 days')->format('Y-m-d');
        foreach ($assets as $a) {
            $exp = (string) ($a['expiry_date'] ?? '');
            if ($exp === '') {
                continue;
            }
            if ($exp < $today) {
                $out[] = ['level' => 'danger', 'text' => 'פג תוקף: ' . ($a['domain'] ?? $a['kind']) . ' (' . $exp . ')'];
            } elseif ($exp <= $soon) {
                $out[] = ['level' => 'warn', 'text' => 'חידוש בקרוב: ' . ($a['domain'] ?? $a['kind']) . ' (' . $exp . ')'];
            }
        }
        foreach ($banks as $b) {
            if (($b['status'] ?? '') !== 'active' || $b['level'] === 'ok') {
                continue;
            }
            $out[] = match ($b['level']) {
                'over'   => ['level' => 'danger', 'text' => 'בנק שעות "' . $b['name'] . '": נוצל במלואו (' . $b['pct'] . '%)'],
                'warn90' => ['level' => 'danger', 'text' => 'בנק שעות "' . $b['name'] . '": נוצלו ' . $b['pct'] . '% (מעל 90%)'],
                default  => ['level' => 'warn', 'text' => 'בנק שעות "' . $b['name'] . '": נוצלו ' . $b['pct'] . '% (מעל 80%)'],
            };
        }
        if ($openDocs !== []) {
            $out[] = ['level' => 'info', 'text' => count($openDocs) . ' מסמכים במצב "לא שולם" לפי Morning'];
        }
        return $out;
    }

    /**
     * @return list<array{date:string, kind:string, text:string, origin:string}>
     */
    private function timeline(array $events, array $interactions, array $finance, array $projects, array $tasks, array $leads, array $quotes): array
    {
        $items = [];
        $add = static function (?string $date, string $kind, string $text, string $origin) use (&$items): void {
            if ($date !== null && $date !== '') {
                $items[] = ['date' => $date, 'kind' => $kind, 'text' => $text, 'origin' => $origin];
            }
        };
        foreach ($events as $e) {
            $add($e['created_at'], 'אירוע', $e['action'] . ($e['actor_name'] ? ' · ' . $e['actor_name'] : ''), $e['origin_name']);
        }
        foreach ($interactions as $i) {
            $add($i['occurred_at'] ?? $i['created_at'], 'תקשורת', (string) ($i['title'] ?? $i['kind']), $i['origin_name']);
        }
        foreach ($finance as $d) {
            $add($d['doc_date'], 'מסמך', $d['kind_label'] . ' #' . ($d['doc_number'] ?? '') . ' · ₪' . number_format((float) $d['amount'], 2), $d['origin_name']);
        }
        foreach ($projects as $p) {
            $add($p['created_at'], 'פרויקט', 'נפתח פרויקט: ' . $p['name'], $p['origin_name']);
        }
        foreach ($tasks as $t) {
            $add($t['created_at'], 'משימה', 'נפתחה משימה: ' . $t['title'], $t['origin_name']);
        }
        foreach ($leads as $l) {
            $add($l['created_at'], 'ליד', 'ליד: ' . $l['name'], $l['origin_name']);
        }
        foreach ($quotes as $q) {
            $add($q['created_at'], 'הצעת מחיר', 'הצעת מחיר ' . ($q['quote_number'] ?? '') . ' · ₪' . number_format((float) $q['total_amount'], 2), $q['origin_name']);
        }
        usort($items, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
        return array_slice($items, 0, 500);
    }

    /**
     * @param list<int> $ids
     * @return array{0:string, 1:array<string, int>}
     */
    private static function in(array $ids, string $prefix): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return ['NULL', []];
        }
        $marks = [];
        $params = [];
        foreach ($ids as $n => $id) {
            $marks[] = ':' . $prefix . $n;
            $params[$prefix . $n] = $id;
        }
        return [implode(', ', $marks), $params];
    }
}
