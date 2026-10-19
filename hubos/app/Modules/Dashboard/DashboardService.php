<?php

declare(strict_types=1);

namespace HubOS\Modules\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use HubOS\Core\Events;
use HubOS\Core\Permissions;
use HubOS\Core\Tenant;
use HubOS\Modules\Assets\AssetService;
use HubOS\Modules\Clients\Client360Service;
use HubOS\Modules\Clients\ClientService;
use HubOS\Modules\Time\HoursBankService;

/**
 * M6 — CEO Morning Screen (60-second executive view for Asher & Dima)
 * and Client Portal view for `client_viewer` users.
 */
final class DashboardService
{
    public function __construct(
        private Tenant $tenant,
        private Permissions $perms,
    ) {
    }

    /**
     * Build the CEO Morning Dashboard payload.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function ceo(array $user, ?DateTimeImmutable $now = null): array
    {
        $this->perms->authorize($user, 'dashboard.view');
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Jerusalem'));
        $today = $now->format('Y-m-d');
        $year = $now->format('Y');
        $month = $now->format('Y-m');
        $d7 = $now->modify('-7 days')->format('Y-m-d');
        $d30 = $now->modify('-30 days')->format('Y-m-d');

        $canClients = $this->perms->can($user, 'clients.view');
        $canEvents = $this->perms->can($user, 'events.view');
        $canWork = $this->perms->can($user, 'work.view');
        $canTime = $this->perms->can($user, 'time.view');
        $canAssets = $this->perms->can($user, 'assets.view');

        // ------------------------------------------------------------------ 1. Urgent today (מה דחוף היום)
        $overdueTasks = [];
        $highTasks = [];
        $needsLinkTasks = 0;
        $needsLinkProjects = 0;
        if ($canWork) {
            $overdueTasks = $this->tenant->select(
                "SELECT t.id, t.client_id, t.project_id, t.title, t.status, t.priority, t.due_at,
                        c.name AS client_name, u.name AS assignee_name
                 FROM tasks t
                 LEFT JOIN clients c ON c.tenant_id = t.tenant_id AND c.id = t.client_id
                 LEFT JOIN users u   ON u.tenant_id = t.tenant_id AND u.id = t.assignee_user_id
                 WHERE t.tenant_id = :tenant_id
                   AND t.archived_at IS NULL
                   AND t.status NOT IN ('done', 'cancelled', 'closed')
                   AND t.due_at IS NOT NULL AND t.due_at != ''
                   AND substr(t.due_at, 1, 10) < :today
                 ORDER BY t.due_at ASC, t.id DESC LIMIT 15",
                ['today' => $today]
            );
            $highTasks = $this->tenant->select(
                "SELECT t.id, t.client_id, t.project_id, t.title, t.status, t.priority, t.due_at,
                        c.name AS client_name, u.name AS assignee_name
                 FROM tasks t
                 LEFT JOIN clients c ON c.tenant_id = t.tenant_id AND c.id = t.client_id
                 LEFT JOIN users u   ON u.tenant_id = t.tenant_id AND u.id = t.assignee_user_id
                 WHERE t.tenant_id = :tenant_id
                   AND t.archived_at IS NULL
                   AND t.status NOT IN ('done', 'cancelled', 'closed')
                   AND t.priority IN ('urgent', 'high')
                 ORDER BY CASE t.priority WHEN 'urgent' THEN 0 ELSE 1 END, t.due_at IS NULL, t.due_at ASC, t.id DESC LIMIT 15"
            );
            $needsLinkTasks = (int) ($this->tenant->selectOne(
                'SELECT COUNT(*) AS n FROM tasks WHERE tenant_id = :tenant_id AND archived_at IS NULL AND client_id IS NULL'
            )['n'] ?? 0);
            $needsLinkProjects = (int) ($this->tenant->selectOne(
                'SELECT COUNT(*) AS n FROM projects WHERE tenant_id = :tenant_id AND archived_at IS NULL AND client_id IS NULL'
            )['n'] ?? 0);
        }

        $openDemandsSummary = ['count' => 0, 'amount' => 0.0, 'top_clients' => [], 'recent' => []];
        if ($canClients) {
            $odRow = $this->tenant->selectOne(
                "SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
                 FROM finance_documents
                 WHERE tenant_id = :tenant_id AND counts_as_income = 0 AND income_reason = 'proforma_open'
                   AND COALESCE(LOWER(status), '') != 'paid'"
            );
            $openDemandsSummary['count'] = (int) ($odRow['n'] ?? 0);
            $openDemandsSummary['amount'] = (float) ($odRow['total'] ?? 0.0);
            $openDemandsSummary['top_clients'] = $this->tenant->select(
                "SELECT f.client_id, COALESCE(c.name, f.client_name_raw, 'ללא שיוך') AS client_name,
                        COUNT(*) AS docs_count, SUM(f.amount) AS total_amount,
                        MAX(f.doc_date) AS latest_date
                 FROM finance_documents f
                 LEFT JOIN clients c ON c.tenant_id = f.tenant_id AND c.id = f.client_id
                 WHERE f.tenant_id = :tenant_id AND f.counts_as_income = 0 AND f.income_reason = 'proforma_open'
                   AND COALESCE(LOWER(f.status), '') != 'paid'
                 GROUP BY f.client_id, COALESCE(c.name, f.client_name_raw, 'ללא שיוך')
                 ORDER BY total_amount DESC LIMIT 8"
            );
            $openDemandsSummary['recent'] = $this->tenant->select(
                "SELECT f.id, f.client_id, COALESCE(c.name, f.client_name_raw, 'ללא שיוך') AS client_name,
                        f.doc_number, f.doc_date, f.amount, f.currency
                 FROM finance_documents f
                 LEFT JOIN clients c ON c.tenant_id = f.tenant_id AND c.id = f.client_id
                 WHERE f.tenant_id = :tenant_id AND f.counts_as_income = 0 AND f.income_reason = 'proforma_open'
                   AND COALESCE(LOWER(f.status), '') != 'paid'
                 ORDER BY f.doc_date DESC, f.id DESC LIMIT 8"
            );
        }

        // ------------------------------------------------------------------ 2. What's about to break (מה הולך להישבר)
        $breakingAssets = [];
        $assetKpis = ['total' => 0, 'expired' => 0, 'expiring_30d' => 0, 'expiring_60d' => 0, 'expiring_90d' => 0, 'ssl_issues' => 0];
        if ($canAssets) {
            $assetList = (new AssetService($this->tenant, $this->perms))->list($user, [], $now);
            $assetKpis = $assetList['kpis'];
            foreach ($assetList['rows'] as $a) {
                if (in_array($a['expiry_state'], ['expired', '30d', '60d'], true) || in_array($a['ssl_state'], ['expired', '30d'], true)) {
                    $breakingAssets[] = $a;
                }
            }
            $breakingAssets = array_slice($breakingAssets, 0, 12);
        }

        $alertBanks = [];
        if ($canTime) {
            $bankSvc = new HoursBankService($this->tenant, $this->perms);
            $rawBanks = $this->tenant->select(
                "SELECT hb.*, c.name AS client_name
                 FROM hours_banks hb
                 LEFT JOIN clients c ON c.tenant_id = hb.tenant_id AND c.id = hb.client_id
                 WHERE hb.tenant_id = :tenant_id AND hb.status = 'active'
                 ORDER BY hb.id ASC"
            );
            foreach ($rawBanks as $rb) {
                $legacyUsed = (float) ($rb['used_hours'] ?? 0.0);
                $sum = $bankSvc->summarize($rb, $month);
                if ($sum['used_hours'] == 0.0 && $legacyUsed > 0.0) {
                    $sum['used_hours'] = $legacyUsed;
                    $sum['remaining_hours'] = $sum['allowance_hours'] - $legacyUsed;
                    $sum['pct'] = $sum['allowance_hours'] > 0
                        ? (int) floor($legacyUsed / $sum['allowance_hours'] * 100)
                        : 100;
                    $sum['level'] = HoursBankService::level($legacyUsed, (float) $sum['allowance_hours']);
                    $sum['low'] = $sum['level'] !== 'ok';
                }
                if ($sum['low'] || $sum['remaining_hours'] <= 0.0) {
                    $alertBanks[] = $sum;
                }
            }
            usort($alertBanks, static fn (array $a, array $b): int => $a['remaining_hours'] <=> $b['remaining_hours']);
            $alertBanks = array_slice($alertBanks, 0, 12);
        }

        // ------------------------------------------------------------------ 3. Money (כמה כסף - הכנסה ממומשת מול דרישות פתוחות)
        $money = [
            'year'              => $year,
            'month'             => $month,
            'income_this_year'  => 0.0,
            'income_this_month' => 0.0,
            'income_total'      => 0.0,
            'by_year'           => [],
            'top_clients'       => [],
            'separation'        => [
                'invoice'                    => ['count' => 0, 'amount' => 0.0],
                'receipt_unmatched'          => ['count' => 0, 'amount' => 0.0],
                'receipt_matched'            => ['count' => 0, 'amount' => 0.0],
                'receipt_matched_to_invoice' => ['count' => 0, 'amount' => 0.0],
                'proforma_open'              => ['count' => 0, 'amount' => 0.0],
                'proforma_matched'           => ['count' => 0, 'amount' => 0.0],
                'proforma_paid_in_morning'   => ['count' => 0, 'amount' => 0.0],
            ],
        ];
        if ($canClients) {
            $byYearRows = $this->tenant->select(
                "SELECT COALESCE( NULLIF(substr(doc_date, 1, 4), ''), '—' ) AS yr,
                        COUNT(*) AS docs_count,
                        COALESCE(SUM(amount), 0) AS income
                 FROM finance_documents
                 WHERE tenant_id = :tenant_id AND counts_as_income = 1
                 GROUP BY yr
                 ORDER BY yr DESC"
            );
            $money['by_year'] = $byYearRows;
            foreach ($byYearRows as $yrRow) {
                $inc = (float) $yrRow['income'];
                $money['income_total'] += $inc;
                if ((string) $yrRow['yr'] === $year) {
                    $money['income_this_year'] = $inc;
                }
            }
            $mRow = $this->tenant->selectOne(
                "SELECT COALESCE(SUM(amount), 0) AS m_inc
                 FROM finance_documents
                 WHERE tenant_id = :tenant_id AND counts_as_income = 1 AND substr(doc_date, 1, 7) = :ym",
                ['ym' => $month]
            );
            $money['income_this_month'] = (float) ($mRow['m_inc'] ?? 0.0);

            $sepRows = $this->tenant->select(
                "SELECT COALESCE(income_reason, 'other') AS reason, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
                 FROM finance_documents
                 WHERE tenant_id = :tenant_id
                 GROUP BY reason"
            );
            foreach ($sepRows as $sr) {
                $rKey = (string) $sr['reason'];
                if ($rKey === 'receipt_matched_to_invoice') {
                    $rKey = 'receipt_matched';
                }
                if (isset($money['separation'][$rKey])) {
                    $money['separation'][$rKey]['count'] += (int) $sr['cnt'];
                    $money['separation'][$rKey]['amount'] += (float) $sr['total'];
                }
            }
            $money['separation']['receipt_matched_to_invoice'] = $money['separation']['receipt_matched'];

            $money['top_clients'] = $this->tenant->select(
                "SELECT f.client_id, COALESCE(c.name, f.client_name_raw, 'ללא שיוך') AS client_name,
                        COUNT(*) AS docs_count,
                        SUM(f.amount) AS income_total,
                        SUM(CASE WHEN substr(f.doc_date, 1, 4) = :yr THEN f.amount ELSE 0 END) AS income_year
                 FROM finance_documents f
                 LEFT JOIN clients c ON c.tenant_id = f.tenant_id AND c.id = f.client_id
                 WHERE f.tenant_id = :tenant_id AND f.counts_as_income = 1
                 GROUP BY f.client_id, COALESCE(c.name, f.client_name_raw, 'ללא שיוך')
                 ORDER BY income_year DESC, income_total DESC
                 LIMIT 8",
                ['yr' => $year]
            );
        }

        // ------------------------------------------------------------------ 4. What the team did (מה הצוות עשה)
        $team = [
            'hours_today'   => 0.0,
            'hours_7d'      => 0.0,
            'hours_30d'     => 0.0,
            'hours_total'   => 0.0,
            'entries_count' => 0,
            'active_timers' => [],
            'by_user'       => [],
            'by_client'     => [],
        ];
        if ($canTime) {
            $tSummary = $this->tenant->selectOne(
                "SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(duration_seconds), 0) AS total_secs,
                        COALESCE(SUM(CASE WHEN substr(COALESCE(entry_date, started_at, created_at), 1, 10) = :today THEN duration_seconds ELSE 0 END), 0) AS today_secs,
                        COALESCE(SUM(CASE WHEN substr(COALESCE(entry_date, started_at, created_at), 1, 10) >= :d7 THEN duration_seconds ELSE 0 END), 0) AS d7_secs,
                        COALESCE(SUM(CASE WHEN substr(COALESCE(entry_date, started_at, created_at), 1, 10) >= :d30 THEN duration_seconds ELSE 0 END), 0) AS d30_secs
                 FROM time_entries
                 WHERE tenant_id = :tenant_id",
                ['today' => $today, 'd7' => $d7, 'd30' => $d30]
            );
            $team['entries_count'] = (int) ($tSummary['cnt'] ?? 0);
            $team['hours_total'] = round(((int) ($tSummary['total_secs'] ?? 0)) / 3600, 2);
            $team['hours_today'] = round(((int) ($tSummary['today_secs'] ?? 0)) / 3600, 2);
            $team['hours_7d'] = round(((int) ($tSummary['d7_secs'] ?? 0)) / 3600, 2);
            $team['hours_30d'] = round(((int) ($tSummary['d30_secs'] ?? 0)) / 3600, 2);

            $team['active_timers'] = $this->tenant->select(
                "SELECT tm.*,
                        u.name AS user_name, c.name AS client_name, t.title AS task_title
                 FROM active_timers tm
                 LEFT JOIN users u   ON u.tenant_id = tm.tenant_id AND u.id = tm.user_id
                 LEFT JOIN clients c ON c.tenant_id = tm.tenant_id AND c.id = tm.client_id
                 LEFT JOIN tasks t   ON t.tenant_id = tm.tenant_id AND t.id = tm.task_id
                 WHERE tm.tenant_id = :tenant_id
                 ORDER BY tm.id DESC"
            );

            $team['by_user'] = $this->tenant->select(
                "SELECT te.user_id, COALESCE(u.name, 'ייבוא היסטורי (ללא משויך)') AS user_name,
                        COUNT(*) AS entries_count,
                        ROUND(SUM(te.duration_seconds) / 3600.0, 2) AS hours_total,
                        ROUND(SUM(CASE WHEN substr(COALESCE(te.entry_date, te.started_at, te.created_at), 1, 10) >= :d30 THEN te.duration_seconds ELSE 0 END) / 3600.0, 2) AS hours_30d
                 FROM time_entries te
                 LEFT JOIN users u ON u.tenant_id = te.tenant_id AND u.id = te.user_id
                 WHERE te.tenant_id = :tenant_id
                 GROUP BY te.user_id, COALESCE(u.name, 'ייבוא היסטורי (ללא משויך)')
                 ORDER BY hours_30d DESC, hours_total DESC",
                ['d30' => $d30]
            );

            $team['by_client'] = $this->tenant->select(
                "SELECT te.client_id, COALESCE(c.name, 'ללא שיוך') AS client_name,
                        COUNT(*) AS entries_count,
                        ROUND(SUM(te.duration_seconds) / 3600.0, 2) AS hours_total,
                        ROUND(SUM(CASE WHEN substr(COALESCE(te.entry_date, te.started_at, te.created_at), 1, 10) >= :d30 THEN te.duration_seconds ELSE 0 END) / 3600.0, 2) AS hours_30d
                 FROM time_entries te
                 LEFT JOIN clients c ON c.tenant_id = te.tenant_id AND c.id = te.client_id
                 WHERE te.tenant_id = :tenant_id
                 GROUP BY te.client_id, COALESCE(c.name, 'ללא שיוך')
                 ORDER BY hours_30d DESC, hours_total DESC
                 LIMIT 8",
                ['d30' => $d30]
            );
        }

        return [
            'provenance'   => (new \HubOS\Modules\Import\SyncService($this->tenant, $this->perms))->latestProvenance($now),
            'clientsCount' => $canClients ? (new ClientService($this->tenant, $this->perms))->count($user) : null,
            'eventsCount'  => $canEvents ? $this->tenant->count('events') : null,
            'modules'      => $this->tenant->all('tenant_modules', ['enabled' => 1], 'module', 'ASC'),
            'events'       => $canEvents ? Events::recent($this->tenant, 15) : [],
            'urgent'       => [
                'overdue_tasks'          => $overdueTasks,
                'high_tasks'             => $highTasks,
                'needs_linking_tasks'    => $needsLinkTasks,
                'needs_linking_projects' => $needsLinkProjects,
                'needs_linking_total'    => $needsLinkTasks + $needsLinkProjects,
                'open_demands'           => $openDemandsSummary,
            ],
            'breaking'     => [
                'assets'     => $breakingAssets,
                'asset_kpis' => $assetKpis,
                'banks'      => $alertBanks,
            ],
            'money'        => $money,
            'team'         => $team,
        ];
    }

    /**
     * Build the Client Portal payload for `client_viewer` users.
     * Strictly scoped to the viewer's linked client (`$user['client_id']`).
     * Never exposes vault items, internal tasks (`client_visible = 0`), or other clients.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function portal(array $user, ?DateTimeImmutable $now = null): array
    {
        $this->perms->authorize($user, 'dashboard.view');
        $clientId = isset($user['client_id']) && $user['client_id'] !== null ? (int) $user['client_id'] : 0;
        if ($clientId <= 0) {
            return [
                'client'      => null,
                'projects'    => [],
                'tasks'       => [],
                'open_tasks'  => [],
                'hours_banks' => [],
                'own_bank'    => null,
                'assets'      => [],
                'metrics'     => [],
                'alerts'      => [],
                'vault'       => [],
            ];
        }
        $card = (new Client360Service($this->tenant, $this->perms))->build($user, $clientId, $now);
        // Hard guarantee: vault is always empty in Client Portal.
        $card['vault'] = [];
        return $card;
    }
}
