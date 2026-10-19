<?php

declare(strict_types=1);

namespace HubOS\Modules\Import;

use HubOS\Core\DB;
use HubOS\Core\Events;
use HubOS\Core\Lang;
use HubOS\Core\Provisioning;
use HubOS\Core\Tenant;
use HubOS\Core\Vault;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Imports a Cloudflare D1 SQL dump (old OS HUB) into the clean HubOS schema.
 *
 * 1. The dump is loaded into a throw-away staging SQLite DB with foreign keys
 *    OFF (the dump is not in FK order).
 * 2. Every entity is read from its SSOT (generic_documents collection or
 *    relational table), linked to clients via legacy ids (+ unique fallbacks),
 *    and upserted by (tenant_id, legacy_id) => idempotent.
 * 3. Every source table/collection is accounted for: imported, merged, or
 *    skipped with a reason. Nothing is dropped silently.
 */
final class D1Importer
{
    public const TENANT_SLUG = 'hub';
    public const TENANT_NAME = 'האב מערכות מתקדמות';

    private const SYSTEM_TABLES = ['d1_migrations', 'sqlite_sequence', 'sessions', 'system_logs', 'ai_agent_logs', 'ceo_actions', '_cf_KV', '_cf_METADATA'];
    private const LEGACY_TABLES = ['legacy_customers', 'legacy_domains', 'legacy_credentials_vault', 'legacy_financeIncomes', 'legacy_invoices', 'legacy_infrastructure_servers'];
    private const SECRET_KEYS = '/^(password|pass|pwd|secret|token|api_?key|private_?key)$/i';

    private PDO $stage;
    private string $stagePath = '';
    private Tenant $t;
    private ClientResolver $clients;

    /** @var array<string, array{source:int, imported:int, merged:int, skipped:list<array{0:string,1:string}>, linkable:int, linked:int, methods:array<string,int>}> */
    private array $stats = [];
    /** @var array<string, array{count:int, disposition:string}> */
    private array $inventory = [];
    /** @var list<string> */
    private array $flags = [];
    /** @var array<string, mixed> */
    private array $finance = [];
    /** @var array<string, int> legacy => id */
    private array $projectIds = [];
    /** @var array<string, ?int> project legacy => client id */
    private array $projectClient = [];
    /** @var array<string, int> */
    private array $taskIds = [];
    /** @var array<string, ?int> */
    private array $taskClient = [];
    /** @var array<string, int> */
    private array $bankIds = [];
    private int $vaultEncrypted = 0;
    private int $vaultSecretsNotStored = 0;

    public function __construct(private PDO $target, private ?Vault $vault = null)
    {
    }

    /** @return array<string, mixed> summary */
    public function run(string $dumpPath, ?string $reportPath = null, ?int $actorUserId = null): array
    {
        $startedAtUtc = gmdate('Y-m-d\TH:i:s\Z');
        $this->loadStaging($dumpPath);
        $sourceSha256 = (string) hash_file('sha256', $dumpPath);
        try {
            $tenantId = Provisioning::ensureTenant($this->target, self::TENANT_SLUG, self::TENANT_NAME);
            $this->t = new Tenant($this->target, $tenantId);
            $this->clients = new ClientResolver();

            $this->t->transaction(function () use ($dumpPath, $startedAtUtc, $sourceSha256, $actorUserId): void {
                $this->importClients();
                $this->importContacts();
                $this->importSuppliers();
                $this->importProjects();
                $this->importTasks();
                $this->importHoursBanks();
                $this->importTimeEntries();
                $this->importLeads();
                $this->importQuotes();
                $this->importFinanceDocuments();
                $this->importExpenses();
                $this->importAssets();
                $this->importVault();
                $this->importInteractions();
                $this->buildInventory();
                $counts = $this->targetCounts();
                Events::record($this->t, $actorUserId, 'import', null, null, 'import.completed', null, [
                    'dump'          => basename($dumpPath),
                    'source_sha256' => $sourceSha256,
                    'counts'        => $counts,
                ]);

                $completedAtUtc = gmdate('Y-m-d\TH:i:s\Z');
                $totalRead = 0;
                $totalImported = 0;
                foreach ($this->stats as $st) {
                    $totalRead += (int) ($st['source'] ?? 0);
                    $totalImported += (int) ($st['imported'] ?? 0);
                }
                DB::run(
                    $this->target,
                    'UPDATE clients SET source_system = ?, source_sha256 = ?, fetched_at = COALESCE(fetched_at, updated_at, created_at) WHERE tenant_id = ?',
                    [SyncService::SOURCE_SYSTEM, $sourceSha256, $this->t->id()]
                );
                DB::run(
                    $this->target,
                    'UPDATE finance_documents SET source_system = ?, source_sha256 = ?, fetched_at = COALESCE(fetched_at, created_at) WHERE tenant_id = ?',
                    [SyncService::SOURCE_SYSTEM, $sourceSha256, $this->t->id()]
                );
                SyncService::recordRun(
                    $this->t,
                    $actorUserId,
                    SyncService::SOURCE_SYSTEM,
                    $startedAtUtc,
                    $completedAtUtc,
                    $totalRead,
                    $totalImported,
                    $sourceSha256,
                    ['dump' => basename($dumpPath), 'counts' => $counts]
                );
            });

            $summary = [
                'tenant_id'     => $tenantId,
                'source_sha256' => $sourceSha256,
                'counts'        => $this->targetCounts(),
                'stats'         => $this->stats,
                'finance'       => $this->finance,
                'flags'         => $this->flags,
                'inventory'     => $this->inventory,
            ];
            if ($reportPath !== null) {
                $this->writeReport($reportPath, $summary);
            }
            return $summary;
        } finally {
            $this->dropStaging();
        }
    }

    // ------------------------------------------------------------ staging

    private function loadStaging(string $dumpPath): void
    {
        if (!is_file($dumpPath) || !is_readable($dumpPath)) {
            throw new RuntimeException('Dump file not readable');
        }
        $sql = file_get_contents($dumpPath);
        if ($sql === false) {
            throw new RuntimeException('Cannot read dump');
        }
        $path = tempnam(sys_get_temp_dir(), 'hubos_stage_');
        if ($path === false) {
            throw new RuntimeException('Cannot create staging DB');
        }
        $this->stagePath = $path;
        $this->stage = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->stage->exec('PRAGMA foreign_keys = OFF');
        $this->stage->exec('PRAGMA journal_mode = OFF');
        $this->stage->exec('PRAGMA synchronous = OFF');
        $sql = (string) preg_replace('/^\s*PRAGMA\b[^\n]*\n/mi', '', $sql);
        $this->stage->exec('BEGIN');
        $this->stage->exec($sql);
        $this->stage->exec('COMMIT');
    }

    private function dropStaging(): void
    {
        unset($this->stage);
        if ($this->stagePath !== '' && is_file($this->stagePath)) {
            unlink($this->stagePath);
        }
    }

    private function hasTable(string $table): bool
    {
        return (bool) DB::run($this->stage, "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table): array
    {
        if (!$this->hasTable($table)) {
            return [];
        }
        return $this->stage->query('SELECT * FROM "' . str_replace('"', '', $table) . '" ORDER BY rowid')->fetchAll();
    }

    /** @return list<array{id:string, data:array<string, mixed>}> */
    private function generic(string $collection): array
    {
        if (!$this->hasTable('generic_documents')) {
            return [];
        }
        $out = [];
        foreach (DB::run($this->stage, 'SELECT id, data FROM generic_documents WHERE collection = ? ORDER BY id', [$collection])->fetchAll() as $r) {
            $data = json_decode((string) $r['data'], true);
            $out[] = ['id' => (string) $r['id'], 'data' => is_array($data) ? $data : ['__invalid_json' => true]];
        }
        return $out;
    }

    // ------------------------------------------------------------ stats helpers

    private function stat(string $entity): void
    {
        $this->stats[$entity] ??= ['source' => 0, 'imported' => 0, 'merged' => 0, 'skipped' => [], 'linkable' => 0, 'linked' => 0, 'methods' => []];
    }

    private function src(string $entity, int $n = 1): void
    {
        $this->stat($entity);
        $this->stats[$entity]['source'] += $n;
    }

    private function ok(string $entity): void
    {
        $this->stats[$entity]['imported']++;
    }

    private function merged(string $entity): void
    {
        $this->stats[$entity]['merged']++;
    }

    private function skip(string $entity, string $legacyId, string $reason): void
    {
        $this->stat($entity);
        $this->stats[$entity]['skipped'][] = [$legacyId, $reason];
    }

    private function link(string $entity, ?string $method): void
    {
        $this->stats[$entity]['linkable']++;
        if ($method !== null) {
            $this->stats[$entity]['linked']++;
        }
        $key = $method ?? 'unlinked';
        $this->stats[$entity]['methods'][$key] = ($this->stats[$entity]['methods'][$key] ?? 0) + 1;
    }

    // ------------------------------------------------------------ value helpers

    private static function s(mixed $v): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private static function f(mixed $v): ?float
    {
        if ($v === null || $v === '' || is_array($v) || is_bool($v)) {
            return null;
        }
        return is_numeric($v) ? round((float) $v, 2) : null;
    }

    private static function iso(mixed $v): ?string
    {
        $s = self::s($v);
        if ($s === null) {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function date(mixed $v): ?string
    {
        $s = self::s($v);
        return ($s !== null && preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m) === 1) ? $m[1] : null;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        foreach (array_keys($data) as $k) {
            if (is_string($k) && preg_match(self::SECRET_KEYS, $k) === 1) {
                unset($data[$k]);
            }
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, scalar|null> $data */
    private function put(string $table, array $data): int
    {
        if (array_key_exists('created_at', $data) && $data['created_at'] === null) {
            unset($data['created_at']);
        }
        return $this->t->upsert($table, $data);
    }

    private static function normStatus(?string $s, string $default): string
    {
        $map = [
            'active' => 'active', 'פעיל' => 'active', 'open' => 'todo', 'todo' => 'todo', 'in_progress' => 'in_progress',
            'done' => 'done', 'completed' => 'done', 'הושלם' => 'done', 'paused' => 'paused', 'מושהה' => 'paused',
        ];
        return $s === null ? $default : ($map[$s] ?? $s);
    }

    // ------------------------------------------------------------ clients

    private function importClients(): void
    {
        $this->stat('clients');
        $relational = [];
        foreach ($this->rows('clients') as $r) {
            $relational[(string) $r['id']] = $r;
        }
        $docs = $this->generic('clients');
        $seen = [];
        foreach ($docs as $doc) {
            $seen[$doc['id']] = true;
        }
        // Relational-only rows (not in the generic SSOT) are imported too.
        foreach ($relational as $id => $r) {
            if (!isset($seen[$id])) {
                $docs[] = ['id' => $id, 'data' => [
                    'name' => $r['name'], 'taxId' => $r['tax_id'], 'email' => $r['email'], 'phone' => $r['phone'],
                    'greenInvoiceId' => $r['green_invoice_id'], 'createdAt' => $r['created_at'], '_source' => 'relational_clients',
                ]];
            }
        }

        foreach ($docs as $doc) {
            $this->src('clients');
            $d = $doc['data'];
            $legacy = $doc['id'];
            $name = self::s($d['name'] ?? null) ?? self::s($relational[$legacy]['name'] ?? null);
            if ($name === null) {
                $this->skip('clients', $legacy, 'missing_name');
                continue;
            }
            $status = self::s($d['status'] ?? null);
            $id = $this->put('clients', [
                'legacy_id'  => $legacy,
                'name'       => $name,
                'email'      => self::s($d['email'] ?? null),
                'phone'      => self::s($d['phone'] ?? null),
                'status'     => in_array($status, ['active', 'inactive', 'archived'], true) ? $status : 'active',
                'tax_id'     => self::s($d['taxId'] ?? null),
                'address'    => self::s($d['addressLine'] ?? null) ?? self::s($d['address'] ?? null),
                'city'       => self::s($d['city'] ?? null),
                'domain'     => ClientResolver::normDomain(self::s($d['domain'] ?? null)) ?? self::s($d['domain'] ?? null),
                'notes'      => self::s($d['notes'] ?? null),
                'data_json'  => self::json($d),
                'created_at' => self::iso($d['createdAt'] ?? null) ?? self::iso($relational[$legacy]['created_at'] ?? null),
            ]);
            $this->ok('clients');
            $this->clients->add(
                $id,
                $legacy,
                self::s($d['greenInvoiceId'] ?? null) ?? self::s($relational[$legacy]['green_invoice_id'] ?? null) ?? (str_starts_with($legacy, 'gi_') ? $legacy : null),
                self::s($d['taxId'] ?? null),
                self::s($d['domain'] ?? null),
                $name,
            );
        }

        // Second pass: parent companies (parentCompanyId -> parent_client_id).
        foreach ($docs as $doc) {
            $childId = $this->clients->idForLegacy($doc['id']);
            if ($childId === null) {
                continue;
            }
            $parentLegacy = self::s($doc['data']['parentCompanyId'] ?? null);
            $parentId = $parentLegacy === null ? null : $this->clients->idForLegacy($parentLegacy);
            if ($parentLegacy !== null && $parentId === null) {
                $this->flags[] = "clients: parentCompanyId of {$doc['id']} not found ({$parentLegacy})";
            }
            $this->t->update('clients', $childId, ['parent_client_id' => $parentId]);
        }

        foreach ($relational as $id => $r) {
            if (isset($seen[$id])) {
                continue;
            }
            $this->flags[] = "clients: relational-only client {$id} imported (not in generic SSOT)";
        }
    }

    private function importContacts(): void
    {
        $this->stat('client_contacts');
        foreach ($this->generic('clients') as $doc) {
            $clientId = $this->clients->idForLegacy($doc['id']);
            if ($clientId === null) {
                continue;
            }
            $d = $doc['data'];
            $list = [];
            if (isset($d['contacts']) && is_array($d['contacts']) && $d['contacts'] !== []) {
                foreach ($d['contacts'] as $c) {
                    $list[] = is_array($c) ? $c : ['name' => $c];
                }
            } elseif (self::s($d['contact'] ?? null) !== null) {
                $list[] = ['name' => $d['contact'], 'phone' => $d['phone'] ?? null, 'email' => $d['email'] ?? null, 'isPrimary' => true];
            }
            foreach ($list as $i => $c) {
                $legacy = $doc['id'] . '#contact' . $i;
                $this->src('client_contacts');
                $name = self::s($c['name'] ?? null);
                if ($name === null) {
                    $this->skip('client_contacts', $legacy, 'missing_name');
                    continue;
                }
                $this->put('client_contacts', [
                    'legacy_id'  => $legacy,
                    'client_id'  => $clientId,
                    'name'       => $name,
                    'role'       => self::s($c['role'] ?? null),
                    'phone'      => self::s($c['phone'] ?? null),
                    'mobile'     => self::s($c['mobile'] ?? null),
                    'email'      => self::s($c['email'] ?? null),
                    'is_primary' => !empty($c['isPrimary']) ? 1 : 0,
                    'notes'      => self::s($c['note'] ?? null),
                    'data_json'  => self::json($c),
                ]);
                $this->ok('client_contacts');
                $this->link('client_contacts', 'legacy_id');
            }
        }
    }

    // ------------------------------------------------------------ suppliers

    private function importSuppliers(): void
    {
        $this->stat('suppliers');
        $names = [];
        foreach ($this->rows('suppliers') as $r) {
            $this->src('suppliers');
            $legacy = (string) $r['id'];
            $name = self::s($r['name']);
            if ($name === null) {
                $this->skip('suppliers', $legacy, 'missing_name');
                continue;
            }
            $info = self::s($r['contact_info']);
            $this->put('suppliers', [
                'legacy_id'    => $legacy,
                'name'         => $name,
                'email'        => ($info !== null && filter_var($info, FILTER_VALIDATE_EMAIL) !== false) ? $info : null,
                'contact_info' => $info,
                'data_json'    => self::json($r),
                'created_at'   => self::iso($r['created_at']),
            ]);
            $this->ok('suppliers');
            $names[ClientResolver::normName($name) ?? ''] = $legacy;
        }
        foreach (array_count_values(array_map(static fn (array $r): string => (string) ClientResolver::normName((string) $r['name']), $this->rows('suppliers'))) as $c) {
            if ($c > 1) {
                $this->flags[] = "suppliers: {$c} relational rows share the same name (all kept; expenses with that name stay unlinked)";
            }
        }
        // Generic 'suppliers' collection: duplicates of relational rows (same id or name) are merged.
        $relIds = array_column($this->rows('suppliers'), 'id');
        foreach ($this->generic('suppliers') as $doc) {
            $this->src('suppliers');
            $name = self::s($doc['data']['name'] ?? null);
            if (in_array($doc['id'], $relIds, true) || ($name !== null && isset($names[ClientResolver::normName($name) ?? '']))) {
                $this->merged('suppliers');
                $this->skip('suppliers', $doc['id'], 'duplicate_of_relational_supplier');
                continue;
            }
            if ($name === null) {
                $this->skip('suppliers', $doc['id'], 'missing_name');
                continue;
            }
            $this->put('suppliers', [
                'legacy_id' => 'generic:' . $doc['id'],
                'name'      => $name,
                'email'     => self::s($doc['data']['email'] ?? null),
                'phone'     => self::s($doc['data']['phone'] ?? null),
                'status'    => (($doc['data']['active'] ?? true) === false) ? 'inactive' : 'active',
                'data_json' => self::json($doc['data']),
            ]);
            $this->ok('suppliers');
        }
    }

    // ------------------------------------------------------------ work

    private function importProjects(): void
    {
        $this->stat('projects');
        $overlay = [];
        $scan = [];
        foreach ($this->generic('projects') as $doc) {
            if (($doc['data']['source'] ?? null) === 'master_ssot_csv') {
                $scan[$doc['id']] = true;
            } else {
                $overlay[$doc['id']] = $doc['data'];
            }
        }
        $relIds = [];
        foreach ($this->rows('projects') as $r) {
            $legacy = (string) $r['id'];
            $relIds[$legacy] = true;
            $this->src('projects');
            if (isset($scan[$legacy])) {
                $this->skip('projects', $legacy, 'dns_infrastructure_scan (master_ssot_csv; domain covered by assets)');
                continue;
            }
            $name = self::s($r['name']);
            if ($name === null) {
                $this->skip('projects', $legacy, 'missing_name');
                continue;
            }
            $ov = $overlay[$legacy] ?? [];
            [$clientId, $method] = $this->clients->resolve(['legacy' => self::s($r['client_id']) ?? self::s($ov['clientId'] ?? null)]);
            $id = $this->put('projects', [
                'legacy_id'  => $legacy,
                'client_id'  => $clientId,
                'name'       => $name,
                'status'     => self::normStatus(self::s($ov['status'] ?? null) ?? self::s($r['status']), 'active'),
                'data_json'  => self::json(['relational' => $r, 'overlay' => $ov]),
                'created_at' => self::iso($r['created_at']),
            ]);
            $this->ok('projects');
            $this->link('projects', $method);
            $this->projectIds[$legacy] = $id;
            $this->projectClient[$legacy] = $clientId;
        }
        foreach ($scan as $legacy => $_) {
            if (!isset($relIds[$legacy])) {
                $this->src('projects');
                $this->skip('projects', $legacy, 'dns_infrastructure_scan (generic only)');
            }
        }
        foreach ($overlay as $legacy => $d) {
            if (isset($relIds[$legacy])) {
                continue;
            }
            $this->src('projects');
            $name = self::s($d['name'] ?? null);
            if ($name === null) {
                $this->skip('projects', $legacy, 'generic status-only doc without project row/name');
                continue;
            }
            [$clientId, $method] = $this->clients->resolve(['legacy' => self::s($d['clientId'] ?? null)]);
            $id = $this->put('projects', [
                'legacy_id' => $legacy,
                'client_id' => $clientId,
                'name'      => $name,
                'status'    => self::normStatus(self::s($d['status'] ?? null), 'active'),
                'data_json' => self::json($d),
            ]);
            $this->ok('projects');
            $this->link('projects', $method);
            $this->projectIds[$legacy] = $id;
            $this->projectClient[$legacy] = $clientId;
        }
    }

    private function importTasks(): void
    {
        $this->stat('tasks');
        foreach ($this->rows('tasks') as $r) {
            $legacy = (string) $r['id'];
            $this->src('tasks');
            $title = self::s($r['title']);
            if ($title === null) {
                $this->skip('tasks', $legacy, 'missing_title');
                continue;
            }
            $projLegacy = self::s($r['project_id']);
            $projectId = $projLegacy === null ? null : ($this->projectIds[$projLegacy] ?? null);
            if ($projLegacy !== null && $projectId === null) {
                $this->flags[] = "tasks: {$legacy} references project {$projLegacy} which was not imported";
            }
            $clientId = $projLegacy === null ? null : ($this->projectClient[$projLegacy] ?? null);
            $id = $this->put('tasks', [
                'legacy_id'   => $legacy,
                'client_id'   => $clientId,
                'project_id'  => $projectId,
                'title'       => $title,
                'description' => self::s($r['description']),
                'status'      => self::normStatus(self::s($r['status']), 'todo'),
                'assignee'    => self::s($r['assignee']),
                'due_at'      => self::iso($r['due_at']),
                'data_json'   => self::json($r),
                'created_at'  => self::iso($r['created_at']),
            ]);
            $this->ok('tasks');
            $this->link('tasks', $clientId !== null ? 'via_project' : null);
            $this->taskIds[$legacy] = $id;
            $this->taskClient[$legacy] = $clientId;
        }
    }

    // ------------------------------------------------------------ time

    private function importHoursBanks(): void
    {
        $this->stat('hours_banks');
        foreach ($this->generic('hours_banks') as $doc) {
            $d = $doc['data'];
            $this->src('hours_banks');
            [$clientId, $method] = $this->clients->resolve(['legacy' => self::s($d['clientId'] ?? null)]);
            $id = $this->put('hours_banks', [
                'legacy_id'   => $doc['id'],
                'client_id'   => $clientId,
                'name'        => self::s($d['name'] ?? null) ?? $doc['id'],
                'total_hours' => self::f($d['totalHours'] ?? null) ?? 0.0,
                'rate'        => self::f($d['rate'] ?? null),
                'type'        => self::s($d['type'] ?? null),
                'status'      => self::s($d['status'] ?? null) ?? 'active',
                'notes'       => self::s($d['notes'] ?? null),
                'data_json'   => self::json($d),
                'created_at'  => self::iso($d['createdAt'] ?? null),
            ]);
            $this->ok('hours_banks');
            $this->link('hours_banks', $method);
            $this->bankIds[$doc['id']] = $id;
        }
    }

    private function importTimeEntries(): void
    {
        $this->stat('time_entries');
        foreach ($this->rows('time_entries') as $r) {
            $legacy = (string) $r['id'];
            $this->src('time_entries');
            $projLegacy = self::s($r['project_id']);
            $taskLegacy = self::s($r['task_id']);
            [$clientId, $method] = $this->clients->resolve(['legacy' => self::s($r['client_id'])]);
            if ($clientId === null && $projLegacy !== null && ($this->projectClient[$projLegacy] ?? null) !== null) {
                [$clientId, $method] = [$this->projectClient[$projLegacy], 'via_project'];
            }
            if ($taskLegacy !== null && !isset($this->taskIds[$taskLegacy])) {
                $this->flags[] = "time_entries: {$legacy} references task {$taskLegacy} not present in source";
            }
            $secs = (int) ($r['duration_seconds'] ?? 0);
            if ($secs <= 0 && is_numeric($r['duration'] ?? null)) {
                $secs = (int) round((float) $r['duration'] * 3600);
            }
            $this->put('time_entries', [
                'legacy_id'        => $legacy,
                'client_id'        => $clientId,
                'project_id'       => $projLegacy === null ? null : ($this->projectIds[$projLegacy] ?? null),
                'task_id'          => $taskLegacy === null ? null : ($this->taskIds[$taskLegacy] ?? null),
                'legacy_user_ref'  => self::s($r['user_id']),
                'description'      => self::s($r['description']),
                'entry_date'       => self::date($r['date']) ?? self::date($r['start_time']),
                'started_at'       => self::iso($r['start_time']),
                'ended_at'         => self::iso($r['end_time']),
                'duration_seconds' => max(0, $secs),
                'source'           => 'relational_time_entries',
                'data_json'        => self::json($r),
                'created_at'       => self::iso($r['created_at']),
            ]);
            $this->ok('time_entries');
            $this->link('time_entries', $method);
        }
        foreach (['time_logs', 'time_entries'] as $collection) {
            foreach ($this->generic($collection) as $doc) {
                $d = $doc['data'];
                $this->src('time_entries');
                [$clientId, $method] = $this->clients->resolve(['legacy' => self::s($d['clientId'] ?? null)]);
                $bank = self::s($d['bankId'] ?? null);
                $hours = self::f($d['hours'] ?? null) ?? 0.0;
                $this->put('time_entries', [
                    'legacy_id'        => $collection . ':' . $doc['id'],
                    'client_id'        => $clientId,
                    'hours_bank_id'    => $bank === null ? null : ($this->bankIds[$bank] ?? null),
                    'description'      => self::s($d['description'] ?? null),
                    'entry_date'       => self::date($d['date'] ?? null),
                    'duration_seconds' => max(0, (int) round($hours * 3600)),
                    'source'           => 'generic_' . $collection,
                    'data_json'        => self::json($d),
                    'created_at'       => self::iso($d['createdAt'] ?? null),
                ]);
                $this->ok('time_entries');
                $this->link('time_entries', $method);
            }
        }
    }

    // ------------------------------------------------------------ sales

    private function importLeads(): void
    {
        $this->stat('leads');
        foreach ($this->rows('leads') as $r) {
            $legacy = (string) $r['id'];
            $this->src('leads');
            $name = self::s($r['name']);
            if ($name === null) {
                $this->skip('leads', $legacy, 'missing_name');
                continue;
            }
            $this->put('leads', [
                'legacy_id'  => $legacy,
                'name'       => $name,
                'email'      => self::s($r['email']),
                'phone'      => self::s($r['phone']),
                'status'     => self::s($r['status']) ?? 'new',
                'data_json'  => self::json($r),
                'created_at' => self::iso($r['created_at']),
            ]);
            $this->ok('leads');
        }
    }

    private function importQuotes(): void
    {
        $this->stat('quotes');
        foreach ($this->rows('quotes') as $r) {
            $legacy = (string) $r['id'];
            $this->src('quotes');
            [$clientId, $method] = $this->clients->resolve(['legacy' => self::s($r['client_id'])]);
            unset($r['signature_data']);
            $this->put('quotes', [
                'legacy_id'    => $legacy,
                'client_id'    => $clientId,
                'quote_number' => self::s($r['quote_number']),
                'total_amount' => self::f($r['total_amount']) ?? 0.0,
                'status'       => self::s($r['status']) ?? 'draft',
                'signed_at'    => self::iso($r['signed_at']),
                'data_json'    => self::json($r),
                'created_at'   => self::iso($r['created_at']),
            ]);
            $this->ok('quotes');
            $this->link('quotes', $method);
        }
    }

    // ------------------------------------------------------------ money

    private function importFinanceDocuments(): void
    {
        $this->stat('finance_documents');
        /** @var array<string, array{data:array<string,mixed>, sources:list<string>}> $docs */
        $docs = [];
        $kindConflicts = [];
        foreach (['financeIncomes', 'invoices'] as $collection) {
            foreach ($this->generic($collection) as $doc) {
                $this->src('finance_documents');
                $key = self::s($doc['data']['greenInvoiceId'] ?? null) ?? $doc['id'];
                if (isset($docs[$key])) {
                    // Same Morning document in both collections: merge. The record that
                    // carries an explicit numeric greenInvoiceType is primary.
                    $prev = $docs[$key]['data'];
                    $prevKind = self::s($prev['documentKind'] ?? null);
                    $newKind = self::s($doc['data']['documentKind'] ?? null);
                    if ($prevKind !== null && $newKind !== null && $prevKind !== $newKind) {
                        $label = "{$docs[$key]['sources'][0]}={$prevKind} vs {$collection}={$newKind}";
                        $kindConflicts[$label] = ($kindConflicts[$label] ?? 0) + 1;
                    }
                    if (self::s($prev['greenInvoiceType'] ?? null) === null && self::s($doc['data']['greenInvoiceType'] ?? null) !== null) {
                        $docs[$key]['data'] = $doc['data'] + $prev;
                    } else {
                        $docs[$key]['data'] = $prev + $doc['data'];
                    }
                    $docs[$key]['sources'][] = $collection;
                    $this->merged('finance_documents');
                    $this->skip('finance_documents', $collection . ':' . $doc['id'], 'same_morning_document_merged (greenInvoiceId)');
                    continue;
                }
                $docs[$key] = ['data' => $doc['data'], 'sources' => [$collection]];
            }
        }
        ksort($docs, SORT_STRING);
        foreach ($kindConflicts as $label => $n) {
            $this->flags[] = "finance: {$n} docs labelled differently across collections ({$label}); the record with the explicit greenInvoiceType code was used";
        }

        $mapping = [];
        $ruleInput = [];
        $ids = [];
        foreach ($docs as $key => $entry) {
            $d = $entry['data'];
            $code = self::s($d['greenInvoiceType'] ?? null);
            $kindRaw = self::s($d['documentKind'] ?? null);
            $kindFromCode = AccountingRule::kindForCode($code);
            $source = 'code';
            if ($code !== null && $kindRaw !== null && $kindFromCode !== $kindRaw) {
                $this->flags[] = "finance: doc {$key} code {$code} conflicts with documentKind {$kindRaw} — not counted";
                $kind = null;
            } elseif ($code !== null) {
                $kind = $kindFromCode ?? $kindRaw;
            } else {
                $kind = $kindRaw;
                $code = AccountingRule::codeForKind($kindRaw);
                $source = $code === null ? 'unknown' : 'kind';
            }
            $mapKey = ($code ?? '?') . ' | ' . ($kindRaw ?? '?') . ' | ' . $source;
            $mapping[$mapKey] = ($mapping[$mapKey] ?? 0) + 1;

            $rawName = self::s($d['clientName'] ?? null) ?? self::s($d['greenInvoiceClientName'] ?? null);
            [$clientId, $method] = $this->clients->resolve([
                'legacy' => self::s($d['clientId'] ?? null),
                'gi'     => self::s($d['clientGreenInvoiceId'] ?? null),
                'tax'    => self::s($d['clientTaxId'] ?? null),
                'name'   => $rawName,
            ]);
            $amount = self::f($d['amount'] ?? null) ?? 0.0;
            $date = self::date($d['invoiceDate'] ?? null);
            $id = $this->put('finance_documents', [
                'legacy_id'           => $key,
                'client_id'           => $clientId,
                'doc_type'            => $code ?? 'unknown',
                'doc_type_source'     => $source,
                'doc_kind'            => $kindRaw,
                'doc_number'          => self::s($d['greenInvoiceNumber'] ?? null) ?? self::s($d['invoiceNumber'] ?? null),
                'doc_date'            => $date,
                'amount'              => $amount,
                'vat'                 => self::f($d['vatAmount'] ?? null),
                'currency'            => self::s($d['currency'] ?? null) ?? 'ILS',
                'status'              => self::s($d['status'] ?? null),
                'paid_amount'         => self::f($d['paidAmount'] ?? null),
                'paid_date'           => self::date($d['paidDate'] ?? null),
                'description'         => self::s($d['description'] ?? null),
                'client_name_raw'     => $rawName,
                'link_method'         => $method,
                'counts_as_income'    => 0,
                'income_reason'       => null,
                'matched_document_id' => null,
                'source'              => implode('+', $entry['sources']),
                'data_json'           => self::json($d),
                'created_at'          => self::iso($d['createdAt'] ?? null) ?? self::iso($d['syncedAt'] ?? null),
            ]);
            $this->ok('finance_documents');
            $this->link('finance_documents', $method);
            $ids[$key] = $id;
            $clientKey = $clientId !== null ? 'c:' . $clientId : (($n = ClientResolver::normName($rawName)) !== null ? 'n:' . $n : null);
            $ruleInput[] = ['key' => $key, 'kind' => $kind, 'amount' => $amount, 'client_key' => $clientKey, 'date' => $date, 'code' => $code];
        }

        $result = AccountingRule::classify(array_map(static fn (array $r): array => array_diff_key($r, ['code' => 1]), $ruleInput));
        foreach ($result as $key => $res) {
            $this->t->update('finance_documents', $ids[$key], [
                'counts_as_income'    => $res['counts'] ? 1 : 0,
                'income_reason'       => $res['reason'],
                'matched_document_id' => $res['matched'] === null ? null : $ids[$res['matched']],
            ]);
        }

        // --- Reporting: totals per year + sensitivity checks (not stored).
        $byYear = [];
        $reasons = [];
        foreach ($ruleInput as $r) {
            $res = $result[$r['key']];
            $reasons[$res['reason']] = ($reasons[$res['reason']] ?? 0) + 1;
            if ($res['counts']) {
                $y = $r['date'] !== null ? substr($r['date'], 0, 4) : 'unknown';
                $byYear[$y] = round(($byYear[$y] ?? 0) + $r['amount'], 2);
            }
        }
        ksort($byYear);

        // Alternative A: literal "exists" semantics (any matching invoice, no 1:1 pairing).
        $invoiceKeys = [];
        foreach ($ruleInput as $r) {
            if (in_array($r['kind'], AccountingRule::INVOICE_KINDS, true) && $r['client_key'] !== null) {
                $invoiceKeys[$r['client_key'] . '|' . AccountingRule::cents($r['amount'])] = true;
            }
        }
        $altExists = [];
        foreach ($ruleInput as $r) {
            if ($r['kind'] === 'receipt' && $result[$r['key']]['counts'] && $r['client_key'] !== null && isset($invoiceKeys[$r['client_key'] . '|' . AccountingRule::cents($r['amount'])])) {
                $y = substr((string) $r['date'], 0, 4);
                $altExists[$y] = round(($altExists[$y] ?? 0) - $r['amount'], 2);
            }
        }
        // Alternative B: treat 320 as a receipt (as the written rule says) instead of tax-invoice/receipt.
        $altB = AccountingRule::classify(array_map(static function (array $r): array {
            $kind = $r['code'] === '320' ? 'receipt' : $r['kind'];
            return ['key' => $r['key'], 'kind' => $kind, 'amount' => $r['amount'], 'client_key' => $r['client_key'], 'date' => $r['date']];
        }, $ruleInput));
        $byYearB = [];
        foreach ($ruleInput as $r) {
            if ($altB[$r['key']]['counts']) {
                $y = $r['date'] !== null ? substr($r['date'], 0, 4) : 'unknown';
                $byYearB[$y] = round(($byYearB[$y] ?? 0) + $r['amount'], 2);
            }
        }
        ksort($byYearB);
        // Risk C: 300 (transaction account) later closed by a 320 for same client+amount => both counted.
        $pool300 = [];
        foreach ($ruleInput as $r) {
            if ($r['code'] === '300' && $r['client_key'] !== null) {
                $pool300[$r['client_key'] . '|' . AccountingRule::cents($r['amount'])][] = $r['key'];
            }
        }
        $dup = [];
        foreach ($ruleInput as $r) {
            $b = $r['client_key'] === null ? null : $r['client_key'] . '|' . AccountingRule::cents($r['amount']);
            if ($r['code'] === '320' && $b !== null && !empty($pool300[$b])) {
                array_pop($pool300[$b]);
                $y = substr((string) $r['date'], 0, 4);
                $dup[$y] = ['n' => ($dup[$y]['n'] ?? 0) + 1, 'amount' => round(($dup[$y]['amount'] ?? 0) + $r['amount'], 2)];
            }
        }
        ksort($dup);
        $negative = count(array_filter($ruleInput, static fn (array $r): bool => $r['amount'] < 0));

        $this->finance = [
            'mapping'          => $mapping,
            'reasons'          => $reasons,
            'income_by_year'   => $byYear,
            'alt_exists_delta' => $altExists,
            'alt_320_receipt'  => $byYearB,
            'risk_300_320'     => $dup,
            'negative_docs'    => $negative,
        ];
        if ($negative > 0) {
            $this->flags[] = "finance: {$negative} documents with negative amount (refund receipts) — counted per rule as-is";
        }
    }

    private function importExpenses(): void
    {
        $this->stat('expenses');
        $suppliers = [];
        foreach ($this->t->select('SELECT id, name FROM suppliers WHERE tenant_id = :tenant_id') as $s) {
            $suppliers[ClientResolver::normName((string) $s['name']) ?? ''][] = (int) $s['id'];
        }
        $linked = ['linked' => 0, 'total' => 0];
        foreach ($this->generic('financeExpenses') as $doc) {
            $d = $doc['data'];
            $this->src('expenses');
            $sname = self::s($d['supplierName'] ?? null) ?? self::s($d['name'] ?? null);
            $cands = $sname === null ? [] : array_values(array_unique($suppliers[ClientResolver::normName($sname) ?? ''] ?? []));
            $supplierId = count($cands) === 1 ? $cands[0] : null;
            $linked['total']++;
            $linked['linked'] += $supplierId !== null ? 1 : 0;
            $this->put('expenses', [
                'legacy_id'         => $doc['id'],
                'supplier_id'       => $supplierId,
                'supplier_name_raw' => $sname,
                'category'          => self::s($d['type'] ?? null),
                'description'       => self::s($d['description'] ?? null),
                'amount'            => self::f($d['amount'] ?? null) ?? 0.0,
                'vat'               => self::f($d['vatAmount'] ?? null),
                'amount_before_vat' => self::f($d['amountBeforeVat'] ?? null),
                'currency'          => self::s($d['currency'] ?? null) ?? 'ILS',
                'billing_month'     => self::s($d['billingMonth'] ?? null),
                'paid_date'         => self::date($d['paidDate'] ?? null),
                'doc_number'        => self::s($d['invoiceNumber'] ?? null),
                'recurring'         => !empty($d['recurring']) ? 1 : 0,
                'vat_class'         => self::s($d['vatClass'] ?? null),
                'source'            => self::s($d['source'] ?? null),
                'data_json'         => self::json($d),
                'created_at'        => self::iso($d['createdAt'] ?? null),
            ]);
            $this->ok('expenses');
        }
        $this->finance['expense_supplier_link'] = $linked;
    }

    // ------------------------------------------------------------ assets

    private function importAssets(): void
    {
        $this->stat('assets');
        /** @var array<string, array{legacy:string, data:array<string,mixed>, merged:list<array<string,mixed>>}> $byKey */
        $byKey = [];
        $add = function (string $legacy, array $data, string $origin) use (&$byKey): void {
            $this->src('assets');
            $domain = ClientResolver::normDomain(self::s($data['domain'] ?? null) ?? self::s($data['domain_name'] ?? null));
            $key = $domain ?? 'legacy:' . $legacy;
            $data['_origin'] = $origin;
            if (isset($byKey[$key])) {
                $byKey[$key]['merged'][] = $data + ['_legacy_id' => $legacy];
                $this->merged('assets');
                $this->skip('assets', $legacy, 'duplicate_domain_merged_into ' . $byKey[$key]['legacy']);
                return;
            }
            $byKey[$key] = ['legacy' => $legacy, 'data' => $data, 'merged' => []];
        };
        foreach ($this->generic('hostingRecords') as $doc) {
            $add($doc['id'], $doc['data'], 'generic_hostingRecords');
        }
        foreach (['hosting', 'hosting_records'] as $c) {
            foreach ($this->generic($c) as $doc) {
                $add($c . ':' . $doc['id'], $doc['data'], 'generic_' . $c);
            }
        }
        foreach ($this->rows('domains') as $r) {
            $add('domains:' . $r['id'], $r, 'relational_domains');
        }

        foreach ($byKey as $entry) {
            $d = $entry['data'];
            $all = array_merge([$d], $entry['merged']);
            $legacyClient = null;
            $expiry = null;
            $ip = null;
            foreach ($all as $x) {
                $legacyClient ??= self::s($x['clientId'] ?? null);
                $expiry ??= self::date($x['expiry_date'] ?? null) ?? self::date($x['expiryDate'] ?? null);
                $ip ??= self::s($x['ipAddress'] ?? null);
            }
            $domainRaw = self::s($d['domain'] ?? null) ?? self::s($d['domain_name'] ?? null);
            [$clientId, $method] = $this->clients->resolve(['legacy' => $legacyClient, 'domain' => $domainRaw]);
            $this->put('assets', [
                'legacy_id'   => $entry['legacy'],
                'client_id'   => $clientId,
                'kind'        => ($d['_origin'] === 'relational_domains') ? 'domain' : 'hosting',
                'domain'      => ClientResolver::normDomain($domainRaw) ?? $domainRaw,
                'provider'    => self::s($d['serverProvider'] ?? null) ?? self::s($d['provider'] ?? null) ?? self::s($d['platform'] ?? null) ?? self::s($d['registrar'] ?? null),
                'ip_address'  => $ip,
                'expiry_date' => $expiry,
                'status'      => self::s($d['status'] ?? null),
                'notes'       => self::s($d['notes'] ?? null),
                'link_method' => $method,
                'data_json'   => self::json($d + ($entry['merged'] !== [] ? ['merged' => $entry['merged']] : [])),
                'created_at'  => self::iso($d['createdAt'] ?? null) ?? self::iso($d['updated_at'] ?? null),
            ]);
            $this->ok('assets');
            $this->link('assets', $method);
        }
        $withExpiry = (int) ($this->t->select("SELECT COUNT(*) AS n FROM assets WHERE tenant_id = :tenant_id AND expiry_date IS NOT NULL")[0]['n'] ?? 0);
        if ($withExpiry === 0) {
            $this->flags[] = 'assets: no expiry_date exists in any source (hostingRecords/hosting/domains) — field left empty';
        }
    }

    // ------------------------------------------------------------ access

    private function importVault(): void
    {
        $this->stat('vault_items');
        foreach ($this->generic('passwords_vault') as $doc) {
            $d = $doc['data'];
            $this->src('vault_items');
            $secret = is_scalar($d['password'] ?? null) ? (string) $d['password'] : '';
            [$clientId, $method] = $this->clients->resolve(['legacy' => self::s($d['clientId'] ?? null), 'name' => self::s($d['clientName'] ?? null)]);
            $cipher = null;
            if ($secret !== '' && $this->vault !== null) {
                $existing = $this->t->select('SELECT secret_ciphertext FROM vault_items WHERE tenant_id = :tenant_id AND legacy_id = :l', ['l' => $doc['id']]);
                $old = $existing[0]['secret_ciphertext'] ?? null;
                if (is_string($old)) {
                    try {
                        $cipher = hash_equals($this->vault->decrypt($old), $secret) ? $old : null;
                    } catch (Throwable) {
                        $cipher = null;
                    }
                }
                $cipher ??= $this->vault->encrypt($secret);
                $this->vaultEncrypted++;
            } elseif ($secret !== '') {
                $this->vaultSecretsNotStored++;
            }
            $this->put('vault_items', [
                'legacy_id'         => $doc['id'],
                'client_id'         => $clientId,
                'title'             => self::s($d['title'] ?? null) ?? self::s($d['service'] ?? null),
                'service'           => self::s($d['service'] ?? null),
                'url'               => self::s($d['url'] ?? null) ?? self::s($d['loginUrl'] ?? null),
                'username'          => self::s($d['username'] ?? null),
                'has_secret'        => $secret !== '' ? 1 : 0,
                'secret_ciphertext' => $cipher,
                'encryption'        => $cipher !== null ? 'aes-256-gcm' : 'none',
                'status'            => self::s($d['status'] ?? null),
                'notes'             => self::s($d['notes'] ?? null),
                'data_json'         => self::json($d),
                'created_at'        => self::iso($d['createdAt'] ?? null) ?? self::iso($d['sourceDate'] ?? null),
            ]);
            $this->ok('vault_items');
            $this->link('vault_items', $method);
        }
        unset($secret);
        if ($this->vault === null && $this->vaultSecretsNotStored > 0) {
            $this->flags[] = Lang::t('import.vault_no_key', ['n' => $this->vaultSecretsNotStored]);
        } elseif ($this->vaultEncrypted > 0) {
            $this->flags[] = Lang::t('import.vault_key', ['n' => $this->vaultEncrypted]);
        }
    }

    // ------------------------------------------------------------ interactions

    private function importInteractions(): void
    {
        $this->stat('interactions');
        foreach ($this->generic('notes') as $doc) {
            $d = $doc['data'];
            $this->src('interactions');
            [$clientId, $method] = $this->clients->resolve(['legacy' => self::s($d['clientId'] ?? null)]);
            $this->put('interactions', [
                'legacy_id'   => 'notes:' . $doc['id'],
                'client_id'   => $clientId,
                'kind'        => 'note',
                'occurred_at' => self::iso($d['createdAt'] ?? null),
                'title'       => self::s($d['title'] ?? null),
                'summary'     => self::s($d['content'] ?? null),
                'data_json'   => self::json($d),
                'created_at'  => self::iso($d['createdAt'] ?? null),
            ]);
            $this->ok('interactions');
            $this->link('interactions', $method);
        }
        if (!$this->hasTable('generic_documents')) {
            return;
        }
        $subs = $this->stage->query("SELECT DISTINCT collection FROM generic_documents WHERE collection LIKE 'tasks/%/%' ORDER BY collection")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($subs as $collection) {
            $parts = explode('/', (string) $collection);
            $kind = ($parts[2] ?? '') === 'taskNotes' ? 'task_note' : 'task_activity';
            foreach ($this->generic((string) $collection) as $doc) {
                $d = $doc['data'];
                $this->src('interactions');
                $taskLegacy = self::s($d['taskId'] ?? null) ?? ($parts[1] ?? '');
                $taskId = $this->taskIds[$taskLegacy] ?? null;
                if ($taskId === null) {
                    $this->flags[] = "interactions: {$collection} references task {$taskLegacy} not present in source tasks";
                }
                $clientId = $this->taskClient[$taskLegacy] ?? null;
                $this->put('interactions', [
                    'legacy_id'   => $collection . ':' . $doc['id'],
                    'client_id'   => $clientId,
                    'task_id'     => $taskId,
                    'kind'        => $kind,
                    'occurred_at' => self::iso($d['createdAt'] ?? null),
                    'summary'     => self::s($d['note'] ?? null) ?? trim((self::s($d['action'] ?? null) ?? '') . ' ' . (self::s($d['to'] ?? null) ?? '')),
                    'data_json'   => self::json($d),
                    'created_at'  => self::iso($d['createdAt'] ?? null),
                ]);
                $this->ok('interactions');
                $this->link('interactions', $clientId !== null ? 'via_task' : null);
            }
        }
    }

    // ------------------------------------------------------------ inventory

    private function buildInventory(): void
    {
        $tableDisp = [
            'clients'       => 'subset of generic clients (SSOT) — relational-only rows imported',
            'invoices'      => 'skipped: not SSOT (generic financeIncomes/invoices are SSOT)',
            'projects'      => 'imported → projects (DNS scans skipped)',
            'tasks'         => 'imported → tasks',
            'leads'         => 'imported → leads',
            'suppliers'     => 'imported → suppliers',
            'time_entries'  => 'imported → time_entries',
            'quotes'        => 'imported → quotes',
            'domains'       => 'imported/merged → assets (dedup by domain)',
            'generic_documents' => 'see collections below',
            'tenants'       => 'replaced by target tenant ' . self::TENANT_NAME,
            'users'         => 'skipped: users are provisioned in HubOS (passwords never imported)',
            'people'        => 'skipped: system person record (role admin), out of M1 scope',
        ];
        $collDisp = [
            'clients'         => 'imported → clients (+ client_contacts)',
            'financeIncomes'  => 'imported → finance_documents',
            'invoices'        => 'merged → finance_documents (same greenInvoiceId)',
            'financeExpenses' => 'imported → expenses',
            'projects'        => 'master_ssot_csv DNS scans skipped; others = status overlay on relational projects',
            'hostingRecords'  => 'imported → assets',
            'hosting'         => 'imported/merged → assets',
            'hosting_records' => 'imported/merged → assets',
            'passwords_vault' => 'imported → vault_items (secret encrypted or not stored)',
            'suppliers'       => 'merged: duplicates of relational suppliers',
            'time_logs'       => 'imported → time_entries',
            'time_entries'    => 'imported → time_entries',
            'hours_banks'     => 'imported → hours_banks',
            'notes'           => 'imported → interactions',
            'users'           => 'skipped: users are provisioned in HubOS',
            'integrations'    => 'skipped: sync state (system data)',
        ];
        foreach ($this->stage->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $table = (string) $table;
            $n = (int) $this->stage->query('SELECT COUNT(*) FROM "' . str_replace('"', '', $table) . '"')->fetchColumn();
            $disp = $tableDisp[$table]
                ?? (in_array($table, self::LEGACY_TABLES, true) ? 'skipped: legacy_* table, not SSOT' : null)
                ?? (in_array($table, self::SYSTEM_TABLES, true) ? 'skipped: system/ops data, out of M1 scope' : null)
                ?? ($n === 0 ? 'empty' : 'UNHANDLED');
            if ($disp === 'UNHANDLED') {
                $this->flags[] = "inventory: table {$table} ({$n} rows) has no import rule";
            }
            $this->inventory['table:' . $table] = ['count' => $n, 'disposition' => $disp];
        }
        if ($this->hasTable('generic_documents')) {
            foreach ($this->stage->query('SELECT collection, COUNT(*) AS n FROM generic_documents GROUP BY collection ORDER BY collection')->fetchAll() as $r) {
                $c = (string) $r['collection'];
                $disp = $collDisp[$c] ?? (preg_match('#^tasks/[^/]+/(activity|taskNotes)$#', $c) === 1 ? 'imported → interactions' : 'UNHANDLED');
                if ($disp === 'UNHANDLED') {
                    $this->flags[] = "inventory: collection {$c} ({$r['n']} docs) has no import rule";
                }
                $this->inventory['generic:' . $c] = ['count' => (int) $r['n'], 'disposition' => $disp];
            }
        }
    }

    /** @return array<string, int> */
    public function targetCounts(): array
    {
        $out = [];
        foreach (['clients', 'client_contacts', 'suppliers', 'projects', 'tasks', 'hours_banks', 'time_entries', 'active_timers', 'finance_documents', 'expenses', 'leads', 'quotes', 'assets', 'vault_items', 'interactions'] as $table) {
            $out[$table] = $this->t->count($table);
        }
        return $out;
    }

    // ------------------------------------------------------------ report

    /** @param array<string, mixed> $s */
    private function writeReport(string $path, array $s): void
    {
        $L = [];
        $L[] = '<div dir="rtl">';
        $L[] = '';
        $L[] = '# ' . Lang::t('import.title');
        $L[] = '';
        $L[] = '- ' . Lang::t('import.tenant') . ': ' . self::TENANT_NAME . ' (id ' . $s['tenant_id'] . ')';
        $L[] = '- Generated (UTC): ' . gmdate('Y-m-d H:i:s');
        $L[] = '';
        $L[] = '## Reconciliation';
        $L[] = '';
        $L[] = '| entity | ' . Lang::t('import.source') . ' | ' . Lang::t('import.imported') . ' | merged | ' . Lang::t('import.skipped') . ' | in DB | balanced |';
        $L[] = '|---|---:|---:|---:|---:|---:|:---:|';
        foreach ($s['stats'] as $entity => $st) {
            $skippedOnly = count($st['skipped']) - $st['merged'];
            $balanced = $st['source'] === $st['imported'] + $st['merged'] + $skippedOnly ? '✅' : '❌';
            $L[] = "| {$entity} | {$st['source']} | {$st['imported']} | {$st['merged']} | {$skippedOnly} | " . ($s['counts'][$entity] ?? '-') . " | {$balanced} |";
        }
        $L[] = '';
        $L[] = '## ' . Lang::t('import.coverage');
        $L[] = '';
        $L[] = '| entity | linked / total | % | methods |';
        $L[] = '|---|---:|---:|---|';
        foreach ($s['stats'] as $entity => $st) {
            if ($st['linkable'] === 0) {
                continue;
            }
            $pct = round(100 * $st['linked'] / $st['linkable'], 1);
            $m = [];
            foreach ($st['methods'] as $k => $v) {
                $m[] = "{$k}={$v}";
            }
            $L[] = "| {$entity} | {$st['linked']} / {$st['linkable']} | {$pct}% | " . implode(', ', $m) . ' |';
        }
        $sl = $s['finance']['expense_supplier_link'] ?? null;
        if (is_array($sl)) {
            $L[] = "| expenses→supplier | {$sl['linked']} / {$sl['total']} | " . ($sl['total'] > 0 ? round(100 * $sl['linked'] / $sl['total'], 1) : 0) . '% | name_exact |';
        }
        $L[] = '';
        $L[] = '## ' . Lang::t('import.doc_mapping');
        $L[] = '';
        $L[] = '| code | documentKind | type source | docs |';
        $L[] = '|---|---|---|---:|';
        foreach ($s['finance']['mapping'] ?? [] as $k => $n) {
            [$code, $kind, $src] = explode(' | ', $k);
            $label = Lang::has('doc_kind.' . $kind) ? $kind . ' (' . Lang::t('doc_kind.' . $kind) . ')' : $kind;
            $L[] = "| {$code} | {$label} | {$src} | {$n} |";
        }
        $L[] = '';
        $L[] = 'Rule outcome: ' . json_encode($s['finance']['reasons'] ?? [], JSON_UNESCAPED_UNICODE);
        $L[] = '';
        $L[] = '## ' . Lang::t('import.income_years');
        $L[] = '';
        $L[] = '| year | income (gross, ILS, per rule) | if 320 treated as receipt | Δ if receipts use literal "exists" | 300↔320 same client+amount (possible double count) |';
        $L[] = '|---|---:|---:|---:|---:|';
        $years = array_unique(array_merge(array_keys($s['finance']['income_by_year'] ?? []), array_keys($s['finance']['alt_320_receipt'] ?? [])));
        sort($years);
        foreach ($years as $y) {
            $a = $s['finance']['income_by_year'][$y] ?? 0;
            $b = $s['finance']['alt_320_receipt'][$y] ?? 0;
            $c = $s['finance']['alt_exists_delta'][$y] ?? 0;
            $d = $s['finance']['risk_300_320'][$y] ?? ['n' => 0, 'amount' => 0];
            $L[] = "| {$y} | " . number_format((float) $a, 2) . ' | ' . number_format((float) $b, 2) . ' | ' . number_format((float) $c, 2) . " | {$d['n']} docs / " . number_format((float) $d['amount'], 2) . ' |';
        }
        $L[] = '';
        $L[] = '## ' . Lang::t('import.flags');
        $L[] = '';
        foreach (array_values(array_unique($s['flags'])) as $f) {
            $L[] = '- ' . $f;
        }
        $L[] = '';
        $L[] = '## ' . Lang::t('import.inventory');
        $L[] = '';
        $L[] = '| source | rows | disposition |';
        $L[] = '|---|---:|---|';
        foreach ($s['inventory'] as $k => $v) {
            $L[] = "| {$k} | {$v['count']} | {$v['disposition']} |";
        }
        $L[] = '';
        $L[] = '## Skipped / merged rows (legacy ids only)';
        foreach ($s['stats'] as $entity => $st) {
            if ($st['skipped'] === []) {
                continue;
            }
            $L[] = '';
            $L[] = "### {$entity} (" . count($st['skipped']) . ')';
            $L[] = '';
            foreach ($st['skipped'] as [$id, $reason]) {
                $L[] = "- `{$id}` — {$reason}";
            }
        }
        $L[] = '';
        $L[] = '</div>';
        $L[] = '';
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create report directory');
        }
        if (file_put_contents($path, implode("\n", $L)) === false) {
            throw new RuntimeException('Cannot write report');
        }
        chmod($path, 0600);
    }
}
