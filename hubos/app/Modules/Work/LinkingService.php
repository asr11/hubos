<?php

declare(strict_types=1);

namespace HubOS\Modules\Work;

use HubOS\Core\Events;
use HubOS\Core\HebrewText;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Tenant;

/**
 * "Needs linking" queue: imported tasks/projects without client_id.
 * Client SUGGESTIONS are computed by fuzzy matching domains / client names in
 * the title. A suggestion is never applied automatically: link() runs only on
 * an explicit admin click and is logged (task.linked / project.linked).
 */
final class LinkingService
{
    /** Words that say nothing about which client it is. */
    private const STOPWORDS = [
        'בעמ', 'בע', 'מ', 'ltd', 'inc', 'co', 'il', 'com', 'org', 'net', 'www',
        'קבוצת', 'חברת', 'group', 'the', 'של', 'עם', 'את', 'על', 'כל', 'או',
        'אתר', 'בניית', 'שדרוג', 'קידום', 'תדמית', 'מערכת', 'פגישה', 'בדיקה',
        'טיפול', 'חדש', 'חדשה', 'פרויקט', 'פרוייקט', 'ניהול', 'לקוח', 'דומיין',
        'דומיינים', 'חיפה', 'קריות', 'ישראל', 'פתרונות', 'שירותי', 'מתקדמות', 'תעשיות',
    ];

    public function __construct(private Tenant $tenant, private Permissions $perms)
    {
    }

    /**
     * @param array<string, mixed> $user
     * @return array{tasks:list<array<string,mixed>>, projects:list<array<string,mixed>>}
     */
    public function queue(array $user): array
    {
        $this->perms->authorize($user, 'work.link');
        $candidates = $this->candidates();
        $tasks = $this->tenant->select(
            'SELECT t.id, t.title, t.description, t.status, t.project_id, p.name AS project_name, t.created_at
               FROM tasks t LEFT JOIN projects p ON p.tenant_id = t.tenant_id AND p.id = t.project_id
              WHERE t.tenant_id = :tenant_id AND t.client_id IS NULL AND t.archived_at IS NULL ORDER BY t.id'
        );
        foreach ($tasks as &$t) {
            $blob = (string) $t['title'] . ' ' . (string) ($t['description'] ?? '') . ' ' . (string) ($t['project_name'] ?? '');
            $t['suggestions'] = self::suggest($blob, $candidates);
        }
        unset($t);
        $projects = $this->tenant->select(
            'SELECT id, name, description, status, created_at FROM projects WHERE tenant_id = :tenant_id AND client_id IS NULL AND archived_at IS NULL ORDER BY id'
        );
        foreach ($projects as &$p) {
            $p['suggestions'] = self::suggest((string) $p['name'] . ' ' . (string) ($p['description'] ?? ''), $candidates);
        }
        unset($p);
        return ['tasks' => $tasks, 'projects' => $projects];
    }

    /**
     * Resolve a raw client input ("213", "#213 — שם לקוח", or exact client name) into a valid client_id.
     */
    public function resolveClientId(string $raw): ?int
    {
        $clean = trim($raw);
        if ($clean === '') {
            return null;
        }
        if (ctype_digit($clean)) {
            return (int) $clean > 0 ? (int) $clean : null;
        }
        if (preg_match('/^#?(\d+)\s*[—\-·:]/u', $clean, $m) === 1 || preg_match('/\(#?(\d+)\)\s*$/u', $clean, $m) === 1) {
            return (int) $m[1] > 0 ? (int) $m[1] : null;
        }
        $rows = $this->tenant->select(
            "SELECT id FROM clients WHERE tenant_id = :tenant_id AND status <> 'archived' AND name = :n LIMIT 2",
            ['n' => $clean]
        );
        return count($rows) === 1 ? (int) $rows[0]['id'] : null;
    }

    /**
     * Link ONE unlinked task/project to a client (explicit click only).
     *
     * @param array<string, mixed> $user
     */
    public function link(array $user, string $type, int $id, int $clientId): void
    {
        $this->perms->authorize($user, 'work.link');
        $table = match ($type) {
            'task'    => 'tasks',
            'project' => 'projects',
            default   => throw new HttpException(404, 'סוג לא נתמך'),
        };
        $this->tenant->transaction(function (Tenant $t) use ($user, $type, $table, $id, $clientId): void {
            $row = $t->find($table, $id);
            if ($row === null) {
                throw new HttpException(404, 'הרשומה לא נמצאה');
            }
            if ($row['client_id'] !== null) {
                throw new HttpException(409, 'הרשומה כבר משויכת ללקוח');
            }
            $client = $t->find('clients', $clientId);
            if ($client === null) {
                throw new HttpException(422, 'הלקוח לא נמצא');
            }
            $suggested = array_map(static fn (array $s): int => $s['client_id'],
                self::suggest((string) ($row['title'] ?? $row['name'] ?? '') . ' ' . (string) ($row['description'] ?? ''), $this->candidates()));
            $t->update($table, $id, ['client_id' => $clientId, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
            Events::record($t, (int) $user['id'], $type, $id, $clientId, $type . '.linked',
                ['client_id' => null],
                ['client_id' => $clientId, 'client_name' => $client['name'], 'suggested_client_ids' => $suggested,
                 'used_suggestion' => in_array($clientId, $suggested, true)]);
        });
    }

    /**
     * Fuzzy client suggestions for a free-text title (top 3, best first).
     *
     * @param list<array{id:int, name:string, domains:list<string>}> $candidates
     * @return list<array{client_id:int, name:string, score:int, reason:string}>
     */
    public static function suggest(string $text, array $candidates): array
    {
        $lower = strtolower($text);
        preg_match_all('/(?<![a-z0-9-])(?:https?:\/\/)?(?:www\.)?([a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)+)/', $lower, $m);
        $domains = array_values(array_unique(array_map(static fn (string $d): string => rtrim($d, '.-'), $m[1])));
        $labels = [];
        foreach ($domains as $d) {
            $labels[] = explode('.', $d)[0];
        }
        preg_match_all('/[a-z0-9][a-z0-9-]{2,}/', $lower, $mw);
        $bare = array_values(array_unique($mw[0]));

        $words = HebrewText::tokens($text);
        $wordSet = [];
        foreach ($words as $w) {
            $wordSet[$w] = true;
            if (($s = HebrewText::stripPrefix($w)) !== null) {
                $wordSet[$s] = true;
            }
        }

        $out = [];
        foreach ($candidates as $c) {
            $best = 0;
            $reason = '';
            foreach ($c['domains'] as $cd) {
                $label = explode('.', $cd)[0];
                if (in_array($cd, $domains, true)) {
                    [$best, $reason] = $best < 100 ? [100, 'דומיין זהה: ' . $cd] : [$best, $reason];
                } elseif (strlen($label) >= 3 && in_array($label, $labels, true)) {
                    [$best, $reason] = $best < 80 ? [80, 'שם דומיין דומה: ' . $cd] : [$best, $reason];
                } elseif (strlen($label) >= 3 && in_array($label, $bare, true)) {
                    [$best, $reason] = $best < 70 ? [70, 'שם הדומיין מופיע: ' . $label] : [$best, $reason];
                }
            }
            $nameTokens = array_values(array_filter(HebrewText::tokens($c['name']),
                static fn (string $t): bool => !in_array($t, self::STOPWORDS, true) && mb_strlen($t, 'UTF-8') >= 2));
            if ($best < 50 && $nameTokens !== [] && mb_strlen(implode('', $nameTokens), 'UTF-8') >= 3) {
                $matched = [];
                foreach ($nameTokens as $nt) {
                    if (isset($wordSet[$nt])) {
                        $matched[] = $nt;
                    }
                }
                if (count($matched) === count($nameTokens)) {
                    $best = 50 + min(count($nameTokens) * 5, 20);
                    $reason = 'שם הלקוח מופיע בכותרת';
                } elseif ($matched !== []) {
                    $strong = array_values(array_filter($matched, static fn (string $t): bool => mb_strlen($t, 'UTF-8') >= 3));
                    if ($strong !== []) {
                        $best = 35 + min(count($strong) * 8, 14);
                        $reason = 'התאמת מילת מפתח: ' . implode(', ', $strong);
                    }
                }
            }
            if ($best > 0) {
                $out[] = ['client_id' => $c['id'], 'name' => $c['name'], 'score' => $best, 'reason' => $reason];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);
        return array_slice($out, 0, 3);
    }

    /** @return list<array{id:int, name:string, domains:list<string>}> clients with their domain + asset domains */
    public function candidates(): array
    {
        $rows = [];
        foreach ($this->tenant->select("SELECT id, name, domain FROM clients WHERE tenant_id = :tenant_id AND status <> 'archived' ORDER BY id") as $c) {
            $rows[(int) $c['id']] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'domains' => self::normDomains([(string) ($c['domain'] ?? '')])];
        }
        foreach ($this->tenant->select("SELECT client_id, domain FROM assets WHERE tenant_id = :tenant_id AND status <> 'archived' AND client_id IS NOT NULL AND domain IS NOT NULL AND domain <> ''") as $a) {
            $cid = (int) $a['client_id'];
            if (isset($rows[$cid])) {
                $rows[$cid]['domains'] = array_values(array_unique([...$rows[$cid]['domains'], ...self::normDomains([(string) $a['domain']])]));
            }
        }
        return array_values($rows);
    }

    /**
     * @param list<string> $raw
     * @return list<string>
     */
    private static function normDomains(array $raw): array
    {
        $out = [];
        foreach ($raw as $d) {
            $d = strtolower(trim($d));
            $d = (string) preg_replace('#^https?://#', '', $d);
            $d = (string) preg_replace('#^www\.#', '', $d);
            $d = rtrim(explode('/', $d)[0], '.');
            if ($d !== '' && preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/', $d) === 1) {
                $out[] = $d;
            }
        }
        return $out;
    }
}
