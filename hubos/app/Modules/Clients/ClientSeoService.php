<?php

declare(strict_types=1);

namespace HubOS\Modules\Clients;

use HubOS\Core\Events;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use InvalidArgumentException;

/**
 * M9 — Transparent SEO & GEO Keywords Tracker per Client.
 * Never fabricates rankings: current_rank stays null ("במדידה") until verified data is recorded.
 */
final class ClientSeoService
{
    public const STAGES = [
        'research'   => 'מחקר ואפיון תוכן',
        'optimizing' => 'באופטימיזציה פעילה',
        'tracking'   => 'במעקב מיקומים',
        'top10'      => 'עמוד ראשון בגוגל',
        'top3'       => 'טופ 3 בגוגל',
    ];

    public function __construct(
        private Tenant $tenant,
        private Permissions $perms,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listForClient(int $clientId): array
    {
        return $this->tenant->select(
            "SELECT id, client_id, keyword, target_url, stage, current_rank, previous_rank, notes, updated_at
               FROM client_seo_keywords
              WHERE tenant_id = :tenant_id AND client_id = :cid
           ORDER BY CASE stage WHEN 'top3' THEN 0 WHEN 'top10' THEN 1 WHEN 'tracking' THEN 2 WHEN 'optimizing' THEN 3 ELSE 4 END,
                    current_rank IS NULL ASC, current_rank ASC, id ASC",
            ['cid' => $clientId]
        );
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function store(array $actor, int $clientId, array $input): int
    {
        $this->perms->authorize($actor, 'clients.update');
        if ($this->tenant->find('clients', $clientId) === null) {
            throw new HttpException(404, 'לקוח לא נמצא');
        }
        $kw = Str::clean((string) ($input['keyword'] ?? ''));
        if ($kw === '' || Str::len($kw) > 200) {
            throw new InvalidArgumentException('חובה להזין ביטוי / מילת מפתח (עד 200 תווים)');
        }
        $stage = trim((string) ($input['stage'] ?? 'optimizing'));
        if (!isset(self::STAGES[$stage])) {
            $stage = 'optimizing';
        }
        $targetUrl = trim((string) ($input['target_url'] ?? ''));
        if ($targetUrl !== '' && !str_starts_with($targetUrl, 'https://') && !str_starts_with($targetUrl, 'http://') && !str_starts_with($targetUrl, '/')) {
            $targetUrl = 'https://' . $targetUrl;
        }
        $rankRaw = trim((string) ($input['current_rank'] ?? ''));
        $rank = ($rankRaw !== '' && ctype_digit($rankRaw) && (int) $rankRaw >= 1 && (int) $rankRaw <= 100) ? (int) $rankRaw : null;
        $notes = Str::limit(Str::clean((string) ($input['notes'] ?? '')), 500);

        return $this->tenant->transaction(static function (Tenant $t) use ($actor, $clientId, $kw, $targetUrl, $stage, $rank, $notes): int {
            $existing = $t->selectOne(
                'SELECT * FROM client_seo_keywords WHERE tenant_id = :tenant_id AND client_id = :cid AND keyword = :kw',
                ['cid' => $clientId, 'kw' => $kw]
            );
            $now = gmdate('Y-m-d\TH:i:s\Z');
            if ($existing !== null) {
                $id = (int) $existing['id'];
                $prevRank = ($rank !== null && $existing['current_rank'] !== null && (int) $existing['current_rank'] !== $rank)
                    ? (int) $existing['current_rank']
                    : ($existing['previous_rank'] !== null ? (int) $existing['previous_rank'] : null);
                $t->update('client_seo_keywords', $id, [
                    'target_url'    => $targetUrl !== '' ? $targetUrl : $existing['target_url'],
                    'stage'         => $stage,
                    'current_rank'  => $rank,
                    'previous_rank' => $prevRank,
                    'notes'         => $notes !== '' ? $notes : $existing['notes'],
                    'updated_at'    => $now,
                ]);
                Events::record($t, (int) $actor['id'], 'client_seo_keyword', $id, $clientId, 'seo.keyword_updated', null, [
                    'keyword' => $kw, 'stage' => $stage, 'current_rank' => $rank,
                ]);
                return $id;
            }
            $id = $t->insert('client_seo_keywords', [
                'client_id'    => $clientId,
                'keyword'      => $kw,
                'target_url'   => $targetUrl !== '' ? $targetUrl : null,
                'stage'        => $stage,
                'current_rank' => $rank,
                'notes'        => $notes !== '' ? $notes : null,
                'updated_at'   => $now,
            ]);
            Events::record($t, (int) $actor['id'], 'client_seo_keyword', $id, $clientId, 'seo.keyword_created', null, [
                'keyword' => $kw, 'stage' => $stage, 'current_rank' => $rank,
            ]);
            return $id;
        });
    }

    /** @param array<string, mixed> $actor */
    public function delete(array $actor, int $clientId, int $kwId): void
    {
        $this->perms->authorize($actor, 'clients.update');
        $row = $this->tenant->find('client_seo_keywords', $kwId);
        if ($row === null || (int) $row['client_id'] !== $clientId) {
            throw new HttpException(404, 'מילת המפתח לא נמצאה');
        }
        $this->tenant->transaction(static function (Tenant $t) use ($actor, $clientId, $kwId, $row): void {
            $t->deleteWhere('client_seo_keywords', ['id' => $kwId, 'client_id' => $clientId]);
            Events::record($t, (int) $actor['id'], 'client_seo_keyword', $kwId, $clientId, 'seo.keyword_deleted',
                ['keyword' => $row['keyword']], null);
        });
    }
}
