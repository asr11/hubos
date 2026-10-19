<?php

declare(strict_types=1);

namespace HubOS\Modules\Assets;

use DateTimeImmutable;
use DateTimeZone;
use HubOS\Core\Events;
use HubOS\Core\HebrewText;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Core\ValidationException;
use HubOS\Modules\Clients\Client360Service;
use HubOS\Modules\Import\ClientResolver;

final class AssetService
{
    public const KINDS = [
        'domain'  => 'דומיין',
        'hosting' => 'אחסון',
        'server'  => 'שרת',
        'other'   => 'אחר',
    ];

    public const WINDOWS = [
        '90'      => 'פג תוך 90 יום',
        '60'      => 'פג תוך 60 יום',
        '30'      => 'פג תוך 30 יום',
        '14'      => 'פג תוך 14 יום',
        '3'       => 'פג תוך 3 ימים',
        'expired' => 'פג תוקף',
        'missing' => 'ללא תאריך חידוש',
    ];

    public const SSL_STATUSES = [
        'valid'    => 'תקין',
        'expiring' => 'פג בקרוב',
        'expired'  => 'פג תוקף',
        'none'     => 'ללא SSL',
        'unknown'  => 'לא נבדק',
    ];

    public function __construct(
        private Tenant $tenant,
        private Permissions $perms,
        private ?AssetInspector $inspector = null,
    ) {
    }

    public static function normHost(string $domainOrHost): string
    {
        return ClientResolver::normDomain($domainOrHost) ?? strtolower(trim($domainOrHost));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string|null> $filters
     * @return array{rows: list<array<string, mixed>>, kpis: array<string, int>}
     */
    public function list(array $user, array $filters = [], ?DateTimeImmutable $now = null): array
    {
        $this->perms->authorize($user, 'assets.view');
        $internal = Client360Service::isInternal($user);
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Jerusalem'));
        $today = $now->format('Y-m-d');
        $in30 = $now->modify('+30 days')->format('Y-m-d');
        $in60 = $now->modify('+60 days')->format('Y-m-d');
        $in90 = $now->modify('+90 days')->format('Y-m-d');

        $sql = 'SELECT a.id, a.client_id, a.kind, a.domain, a.provider, a.registrar, a.ip_address,
                       a.expiry_date, a.ssl_status, a.ssl_expiry_date, a.last_inspected_at, a.status, a.notes,
                       a.created_at, a.updated_at, c.name AS client_name,
                       (SELECT COUNT(*) FROM vault_items v WHERE v.tenant_id = a.tenant_id AND v.asset_id = a.id) AS vault_count
                  FROM assets a
             LEFT JOIN clients c ON c.tenant_id = a.tenant_id AND c.id = a.client_id
                 WHERE a.tenant_id = :tenant_id';
        $params = [];

        if (!$internal) {
            $viewerClientId = (int) ($user['client_id'] ?? 0);
            if ($viewerClientId < 1) {
                return ['rows' => [], 'kpis' => [
                    'total' => 0, 'expiring_30' => 0, 'expiring_30d' => 0, 'expiring_60d' => 0,
                    'expiring_90d' => 0, 'expired' => 0, 'missing_expiry' => 0, 'ssl_issues' => 0,
                ]];
            }
            $sql .= ' AND a.client_id = :viewer_cid';
            $params['viewer_cid'] = $viewerClientId;
        } elseif (!empty($filters['client_id']) && ctype_digit((string) $filters['client_id'])) {
            $sql .= ' AND a.client_id = :cid';
            $params['cid'] = (int) $filters['client_id'];
        }

        if (!empty($filters['kind']) && isset(self::KINDS[$filters['kind']])) {
            $sql .= ' AND a.kind = :kind';
            $params['kind'] = $filters['kind'];
        }

        $sql .= ' ORDER BY a.expiry_date IS NULL ASC, a.expiry_date ASC, a.domain ASC, a.id DESC LIMIT 1000';
        $all = $this->tenant->select($sql, $params);

        $kpis = [
            'total'          => count($all),
            'expiring_30'    => 0,
            'expiring_30d'   => 0,
            'expiring_60d'   => 0,
            'expiring_90d'   => 0,
            'expired'        => 0,
            'missing_expiry' => 0,
            'ssl_issues'     => 0,
        ];

        $window = (string) preg_replace('/^(\d+)d$/', '$1', (string) ($filters['window'] ?? ''));
        $q = trim((string) ($filters['q'] ?? ''));
        $rows = [];

        foreach ($all as $r) {
            if (!$internal) {
                unset($r['notes'], $r['vault_count']);
            }
            $exp = (string) ($r['expiry_date'] ?? '');
            $daysLeft = null;
            $level = 'muted';
            $state = 'missing';
            $label = 'ללא תאריך חידוש';
            if ($exp === '') {
                $kpis['missing_expiry']++;
            } else {
                $tExp = strtotime($exp . ' 00:00:00 UTC');
                $tToday = strtotime($today . ' 00:00:00 UTC');
                if ($tExp !== false && $tToday !== false) {
                    $daysLeft = intdiv($tExp - $tToday, 86400);
                }
                if ($exp < $today) {
                    $kpis['expired']++;
                    $kpis['expiring_30d']++;
                    $kpis['expiring_60d']++;
                    $kpis['expiring_90d']++;
                    $level = 'danger';
                    $state = 'expired';
                    $label = 'פג תוקף (' . abs((int) $daysLeft) . ' ימים)';
                } elseif ($exp <= $in30) {
                    $kpis['expiring_30']++;
                    $kpis['expiring_30d']++;
                    $kpis['expiring_60d']++;
                    $kpis['expiring_90d']++;
                    $level = ($daysLeft !== null && $daysLeft <= 14) ? 'danger' : 'warn';
                    $state = '30d';
                    $label = 'מתחדש בעוד ' . (int) $daysLeft . ' ימים';
                } elseif ($exp <= $in60) {
                    $kpis['expiring_60d']++;
                    $kpis['expiring_90d']++;
                    $level = 'warn';
                    $state = '60d';
                    $label = 'מתחדש בעוד ' . (int) $daysLeft . ' ימים';
                } elseif ($exp <= $in90) {
                    $kpis['expiring_90d']++;
                    $level = 'ok';
                    $state = '90d';
                    $label = 'מתחדש בעוד ' . (int) $daysLeft . ' ימים';
                } else {
                    $level = 'ok';
                    $state = 'ok';
                    $label = 'תקין (' . (int) $daysLeft . ' ימים)';
                }
            }
            $r['days_left'] = $daysLeft;
            $r['expiry_level'] = $level;
            $r['expiry_state'] = $state;
            $r['expiry_label'] = $label;

            $sslSt = (string) ($r['ssl_status'] ?? '');
            $sslExp = (string) ($r['ssl_expiry_date'] ?? '');
            if ($sslSt === 'expired' || ($sslExp !== '' && $sslExp < $today)) {
                $r['ssl_state'] = 'expired';
                $kpis['ssl_issues']++;
            } elseif ($sslSt === 'expiring' || ($sslExp !== '' && $sslExp <= $in30)) {
                $r['ssl_state'] = '30d';
                $kpis['ssl_issues']++;
            } else {
                $r['ssl_state'] = 'ok';
            }

            if ($window !== '') {
                if ($window === 'missing' && $exp !== '') {
                    continue;
                }
                if ($window === 'expired' && ($exp === '' || $exp >= $today)) {
                    continue;
                }
                if (in_array($window, ['90', '60', '30', '14', '3'], true)) {
                    $limitDate = $now->modify('+' . (int) $window . ' days')->format('Y-m-d');
                    if ($exp === '' || $exp > $limitDate) {
                        continue;
                    }
                }
            }

            if ($q !== '') {
                $haystack = implode(' ', [
                    (string) ($r['domain'] ?? ''),
                    (string) ($r['provider'] ?? ''),
                    (string) ($r['registrar'] ?? ''),
                    (string) ($r['ip_address'] ?? ''),
                    (string) ($r['client_name'] ?? ''),
                ]);
                if (stripos($haystack, $q) === false && HebrewText::score($q, $haystack) === 0) {
                    continue;
                }
            }

            $rows[] = $r;
        }

        return ['rows' => $rows, 'kpis' => $kpis];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function get(array $user, int $id, ?DateTimeImmutable $now = null): array
    {
        $this->perms->authorize($user, 'assets.view');
        $row = $this->tenant->find('assets', $id);
        if ($row === null) {
            throw new HttpException(404, 'הנכס לא נמצא');
        }
        $internal = Client360Service::isInternal($user);
        if (!$internal && (int) ($user['client_id'] ?? 0) !== (int) ($row['client_id'] ?? 0)) {
            throw new HttpException(403, 'אין הרשאה לצפות בנכס זה');
        }
        $data = is_string($row['data_json'] ?? null) ? json_decode($row['data_json'], true) : null;
        unset($row['data_json']);
        $row['inspection'] = is_array($data['inspection'] ?? null) ? $data['inspection'] : null;
        $client = $row['client_id'] !== null ? $this->tenant->find('clients', (int) $row['client_id']) : null;
        $row['client_name'] = $client['name'] ?? null;

        $row['vault_items'] = [];
        if ($internal && $this->perms->can($user, 'vault.view')) {
            $cols = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', Client360Service::VAULT_COLUMNS));
            $row['vault_items'] = $this->tenant->select(
                "SELECT {$cols} FROM vault_items WHERE tenant_id = :tenant_id AND asset_id = :aid ORDER BY id ASC",
                ['aid' => $id]
            );
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function create(array $actor, array $input): int
    {
        $this->perms->authorize($actor, 'assets.create');
        $clean = $this->validate($input);
        return $this->tenant->transaction(function (Tenant $t) use ($actor, $clean): int {
            $id = $t->insert('assets', $clean + ['link_method' => 'manual']);
            Events::record($t, (int) $actor['id'], 'asset', $id, $clean['client_id'], 'asset.created', null, $clean);
            return $id;
        });
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function update(array $actor, int $id, array $input): void
    {
        $this->perms->authorize($actor, 'assets.update');
        $existing = $this->tenant->find('assets', $id);
        if ($existing === null) {
            throw new HttpException(404, 'הנכס לא נמצא');
        }
        $clean = $this->validate($input);
        $before = array_intersect_key($existing, $clean);

        $this->tenant->transaction(function (Tenant $t) use ($actor, $id, $clean, $before): void {
            $t->update('assets', $id, $clean + ['updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
            Events::record($t, (int) $actor['id'], 'asset', $id, $clean['client_id'], 'asset.updated', $before, $clean);
        });
    }

    /**
     * Inspect DNS/SSL/WHOIS for an asset, update its metadata & expiry_date, and log asset.inspected.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed> inspection findings
     */
    public function inspect(array $actor, int $id, ?AssetInspector $inspector = null): array
    {
        $this->perms->authorize($actor, 'assets.inspect');
        $existing = $this->tenant->find('assets', $id);
        if ($existing === null) {
            throw new HttpException(404, 'הנכס לא נמצא');
        }
        $domain = trim((string) ($existing['domain'] ?? ''));
        if ($domain === '') {
            throw new ValidationException(['domain' => 'לנכס אין שם דומיין לבדיקה']);
        }

        $inspector ??= $this->inspector ?? new NativeAssetInspector();
        $findings = $inspector->inspect($domain);
        $findings['dns_a'] ??= isset($findings['ip_address']) && is_string($findings['ip_address']) ? [$findings['ip_address']] : [];
        $findings['dns_ns'] ??= $findings['nameservers'] ?? [];
        $findings['nameservers'] ??= $findings['dns_ns'];

        $data = is_string($existing['data_json'] ?? null) ? json_decode($existing['data_json'], true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $data['inspection'] = $findings;

        $ip = $findings['dns_a'][0] ?? $findings['ip_address'] ?? $existing['ip_address'];
        $registrar = $findings['whois_registrar'] ?? $findings['registrar'] ?? $existing['registrar'];
        $expiry = $findings['whois_expiry_date'] ?? $findings['expiry_date'] ?? $existing['expiry_date'] ?? $findings['ssl_expiry_date'];
        $sslStatus = $findings['ssl_status'] ?? $existing['ssl_status'];
        $sslExpiry = $findings['ssl_expiry_date'] ?? $existing['ssl_expiry_date'];

        $before = [
            'ip_address'      => $existing['ip_address'],
            'registrar'       => $existing['registrar'],
            'expiry_date'     => $existing['expiry_date'],
            'ssl_status'      => $existing['ssl_status'] ?? null,
            'ssl_expiry_date' => $existing['ssl_expiry_date'] ?? null,
        ];
        $after = [
            'ip_address'        => $ip,
            'registrar'         => $registrar,
            'expiry_date'       => $expiry,
            'ssl_status'        => $sslStatus,
            'ssl_expiry_date'   => $sslExpiry,
            'last_inspected_at' => $findings['inspected_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $clientId = $existing['client_id'] !== null ? (int) $existing['client_id'] : null;
        $this->tenant->transaction(function (Tenant $t) use ($actor, $id, $clientId, $before, $after, $data, $findings): void {
            $t->update('assets', $id, $after + [
                'data_json'  => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            Events::record($t, (int) $actor['id'], 'asset', $id, $clientId, 'asset.inspected', $before, $after + ['dns_a' => $findings['dns_a'], 'dns_ns' => $findings['dns_ns']]);
        });

        return $findings;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, scalar|null>
     */
    private function validate(array $input): array
    {
        $errors = [];
        $cidRaw = trim((string) ($input['client_id'] ?? ''));
        $clientId = ctype_digit($cidRaw) && (int) $cidRaw > 0 ? (int) $cidRaw : null;
        if ($clientId === null || $this->tenant->find('clients', $clientId) === null) {
            $errors['client_id'] = 'חובה לשייך נכס ללקוח קיים';
        }

        $kind = trim((string) ($input['kind'] ?? 'domain'));
        if (!isset(self::KINDS[$kind])) {
            $errors['kind'] = 'סוג נכס לא תקין';
        }

        $domainRaw = Str::clean((string) ($input['domain'] ?? ''));
        $domain = ClientResolver::normDomain($domainRaw) ?? $domainRaw;
        if ($domain === '' || Str::len($domain) > 200) {
            $errors['domain'] = 'חובה להזין שם דומיין או מזהה נכס (עד 200 תווים)';
        }

        $expiry = trim((string) ($input['expiry_date'] ?? ''));
        if ($expiry !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) !== 1) {
            $errors['expiry_date'] = 'תאריך חידוש חייב להיות בפורמט YYYY-MM-DD';
        }

        $sslExpiry = trim((string) ($input['ssl_expiry_date'] ?? ''));
        if ($sslExpiry !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sslExpiry) !== 1) {
            $errors['ssl_expiry_date'] = 'תאריך תפוגת SSL חייב להיות בפורמט YYYY-MM-DD';
        }

        $sslStatus = trim((string) ($input['ssl_status'] ?? ''));
        if ($sslStatus !== '' && !isset(self::SSL_STATUSES[$sslStatus])) {
            $errors['ssl_status'] = 'סטטוס SSL לא תקין';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $provider = Str::limit(Str::clean((string) ($input['provider'] ?? '')), 120);
        $registrar = Str::limit(Str::clean((string) ($input['registrar'] ?? '')), 120);
        $ip = Str::limit(Str::clean((string) ($input['ip_address'] ?? '')), 80);
        $status = Str::limit(Str::clean((string) ($input['status'] ?? 'active')), 40);
        $notes = Str::limit(Str::clean((string) ($input['notes'] ?? '')), 2000);

        return [
            'client_id'       => $clientId,
            'kind'            => $kind,
            'domain'          => $domain,
            'provider'        => $provider !== '' ? $provider : null,
            'registrar'       => $registrar !== '' ? $registrar : null,
            'ip_address'      => $ip !== '' ? $ip : null,
            'expiry_date'     => $expiry !== '' ? $expiry : null,
            'ssl_status'      => $sslStatus !== '' ? $sslStatus : null,
            'ssl_expiry_date' => $sslExpiry !== '' ? $sslExpiry : null,
            'status'          => $status !== '' ? $status : 'active',
            'notes'           => $notes !== '' ? $notes : null,
        ];
    }
}
