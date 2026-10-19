<?php

declare(strict_types=1);

namespace HubOS\Modules\Import;

use HubOS\Core\DB;
use HubOS\Core\Tenant;
use PDO;

/**
 * Accounting rule (חוק המסמך החשבונאי — מאושר בעלים).
 *
 * Document semantics from Morning (GreenInvoice):
 *  - 300 (transaction_account / חשבון עסקה): payment request / proforma — NEVER realized income
 *    (counts_as_income = 0). Paired 1:1 against realized closing documents (305/320/330 or
 *    standalone 400) of the same client & amount; unmatched 300s are open demands ('proforma_open').
 *  - 305 (tax_invoice / חשבונית מס) and 320/330 (tax_invoice_receipt / חשבונית מס/קבלה):
 *    realized transaction => income (counts_as_income = 1, reason = 'invoice').
 *  - 400 (receipt / קבלה): confirms payment. Counts as income ONLY when no invoice-type
 *    document (305/320/330) of the same client with the same amount exists to pair with it (1:1,
 *    nearest date first).
 *  - Any other / unknown kind is NOT counted and is flagged for review.
 */
final class AccountingRule
{
    /** Code => kind, as observed in Morning / GreenInvoice. */
    public const CODE_TO_KIND = [
        '300' => 'transaction_account',
        '305' => 'tax_invoice',
        '320' => 'tax_invoice_receipt',
        '330' => 'tax_invoice_receipt',
        '400' => 'receipt',
    ];

    public const INVOICE_KINDS = ['tax_invoice', 'tax_invoice_receipt'];
    public const PROFORMA_KINDS = ['transaction_account'];
    public const RECEIPT_KINDS = ['receipt'];

    public static function kindForCode(?string $code): ?string
    {
        return $code === null ? null : (self::CODE_TO_KIND[$code] ?? null);
    }

    public static function codeForKind(?string $kind): ?string
    {
        if ($kind === null) {
            return null;
        }
        $code = array_search($kind, self::CODE_TO_KIND, true);
        return $code === false ? null : (string) $code;
    }

    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public static function isPaidInMorning(array $doc): bool
    {
        $st = strtolower(trim((string) ($doc['status'] ?? '')));
        if ($st === 'paid') {
            return true;
        }
        $amt = (float) ($doc['amount'] ?? 0.0);
        $paidRaw = $doc['paid_amount'] ?? $doc['paidAmount'] ?? null;
        if ($paidRaw !== null && $paidRaw !== '' && is_numeric($paidRaw)) {
            $paid = (float) $paidRaw;
            if ($amt > 0.0 && self::cents($paid) >= self::cents($amt)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{key:string, kind:?string, amount:float, client_key:?string, date:?string, status?:?string, paid_amount?:?float}> $docs
     * @return array<string, array{counts:bool, reason:string, matched:?string}>
     */
    public static function classify(array $docs): array
    {
        $out = [];
        $invoicePool = []; // client_key|cents => list of [key, date] (305/320/330)
        $closingPool = []; // client_key|cents => list of [key, date] (305/320/330 + unmatched 400)
        $receipts = [];
        $proformas = [];

        foreach ($docs as $d) {
            $kind = $d['kind'];
            if (in_array($kind, self::INVOICE_KINDS, true)) {
                $out[$d['key']] = ['counts' => true, 'reason' => 'invoice', 'matched' => null];
                if ($d['client_key'] !== null) {
                    $bucket = $d['client_key'] . '|' . self::cents($d['amount']);
                    $invoicePool[$bucket][] = [$d['key'], (string) $d['date']];
                    $closingPool[$bucket][] = [$d['key'], (string) $d['date']];
                }
            } elseif (in_array($kind, self::RECEIPT_KINDS, true)) {
                $receipts[] = $d;
            } elseif (in_array($kind, self::PROFORMA_KINDS, true)) {
                $proformas[] = $d;
            } else {
                $out[$d['key']] = ['counts' => false, 'reason' => 'unknown_type', 'matched' => null];
            }
        }

        usort($receipts, static fn (array $a, array $b): int => [(string) $a['date'], $a['key']] <=> [(string) $b['date'], $b['key']]);
        foreach ($receipts as $r) {
            $bucket = $r['client_key'] === null ? null : $r['client_key'] . '|' . self::cents($r['amount']);
            if ($bucket === null || empty($invoicePool[$bucket])) {
                $out[$r['key']] = ['counts' => true, 'reason' => 'receipt_unmatched', 'matched' => null];
                if ($bucket !== null) {
                    $closingPool[$bucket][] = [$r['key'], (string) $r['date']];
                }
                continue;
            }
            $matched = self::takeNearest($invoicePool[$bucket], (string) $r['date']);
            $out[$r['key']] = ['counts' => false, 'reason' => 'receipt_matched', 'matched' => $matched];
        }

        usort($proformas, static fn (array $a, array $b): int => [(string) $a['date'], $a['key']] <=> [(string) $b['date'], $b['key']]);
        foreach ($proformas as $p) {
            $bucket = $p['client_key'] === null ? null : $p['client_key'] . '|' . self::cents($p['amount']);
            if ($bucket === null || empty($closingPool[$bucket])) {
                $reason = self::isPaidInMorning($p) ? 'proforma_paid_in_morning' : 'proforma_open';
                $out[$p['key']] = ['counts' => false, 'reason' => $reason, 'matched' => null];
                continue;
            }
            $matched = self::takeNearest($closingPool[$bucket], (string) $p['date']);
            $out[$p['key']] = ['counts' => false, 'reason' => 'proforma_matched', 'matched' => $matched];
        }

        return $out;
    }

    /**
     * Recalculate counts_as_income, income_reason and matched_document_id on stored finance_documents.
     */
    public static function recalculate(PDO $pdo, ?int $tenantId = null): int
    {
        $hasTable = (bool) DB::run($pdo, "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'finance_documents'")->fetchColumn();
        if (!$hasTable) {
            return 0;
        }
        $tenantIds = $tenantId !== null
            ? [$tenantId]
            : array_map('intval', $pdo->query('SELECT id FROM tenants ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

        $updated = 0;
        foreach ($tenantIds as $tid) {
            $t = new Tenant($pdo, $tid);
            $rows = $t->select(
                "SELECT id, legacy_id, client_id, client_name_raw, doc_type, doc_type_source, doc_kind, amount, doc_date,
                        status, paid_amount,
                        CASE WHEN data_json IS NOT NULL AND json_valid(data_json) THEN json_extract(data_json, '$.status') ELSE NULL END AS json_status,
                        CASE WHEN data_json IS NOT NULL AND json_valid(data_json) THEN json_extract(data_json, '$.paidAmount') ELSE NULL END AS json_paid_amount,
                        counts_as_income, income_reason, matched_document_id
                   FROM finance_documents
                  WHERE tenant_id = :tenant_id
                  ORDER BY COALESCE(legacy_id, CAST(id AS TEXT)) ASC, id ASC"
            );
            if ($rows === []) {
                continue;
            }
            $input = [];
            $idMap = [];
            foreach ($rows as $r) {
                $key = (string) ($r['legacy_id'] ?? ('id:' . $r['id']));
                $idMap[$key] = (int) $r['id'];
                $code = $r['doc_type'] !== 'unknown' ? (string) $r['doc_type'] : null;
                $kindRaw = is_string($r['doc_kind'] ?? null) ? $r['doc_kind'] : null;
                $kindFromCode = self::kindForCode($code);
                if ($code !== null && $kindRaw !== null && $kindFromCode !== null && $kindFromCode !== $kindRaw && ($r['doc_type_source'] ?? 'code') === 'code') {
                    $kind = null;
                } elseif ($code !== null) {
                    $kind = $kindFromCode ?? $kindRaw;
                } else {
                    $kind = $kindRaw;
                }
                $clientKey = $r['client_id'] !== null
                    ? 'c:' . (int) $r['client_id']
                    : (($n = ClientResolver::normName(is_string($r['client_name_raw'] ?? null) ? $r['client_name_raw'] : null)) !== null ? 'n:' . $n : null);
                $statusVal = is_string($r['status'] ?? null) && trim($r['status']) !== ''
                    ? trim($r['status'])
                    : (is_string($r['json_status'] ?? null) && trim($r['json_status']) !== '' ? trim($r['json_status']) : null);
                $paidVal = ($r['paid_amount'] ?? null) !== null && is_numeric($r['paid_amount'])
                    ? (float) $r['paid_amount']
                    : (($r['json_paid_amount'] ?? null) !== null && is_numeric($r['json_paid_amount']) ? (float) $r['json_paid_amount'] : null);
                $input[] = [
                    'key'         => $key,
                    'kind'        => $kind,
                    'amount'      => (float) $r['amount'],
                    'client_key'  => $clientKey,
                    'date'        => is_string($r['doc_date'] ?? null) ? $r['doc_date'] : null,
                    'status'      => $statusVal,
                    'paid_amount' => $paidVal,
                ];
            }
            $classified = self::classify($input);
            $t->transaction(static function (Tenant $tx) use ($rows, $classified, $idMap, &$updated): void {
                foreach ($rows as $r) {
                    $key = (string) ($r['legacy_id'] ?? ('id:' . $r['id']));
                    $res = $classified[$key];
                    $newCounts = $res['counts'] ? 1 : 0;
                    $newReason = $res['reason'];
                    $newMatched = $res['matched'] === null ? null : ($idMap[$res['matched']] ?? null);
                    if ((int) $r['counts_as_income'] !== $newCounts
                        || ($r['income_reason'] ?? null) !== $newReason
                        || ($r['matched_document_id'] !== null ? (int) $r['matched_document_id'] : null) !== $newMatched
                    ) {
                        $tx->update('finance_documents', (int) $r['id'], [
                            'counts_as_income'    => $newCounts,
                            'income_reason'       => $newReason,
                            'matched_document_id' => $newMatched,
                        ]);
                        $updated++;
                    }
                }
            });
        }
        return $updated;
    }

    /**
     * Filter a list of finance_documents rows down to open 300 (transaction_account) demands.
     * Supports both classified DB rows and raw rows inserted in tests without income_reason.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function openDemands(array $rows): array
    {
        $knownReasons = ['proforma_open', 'proforma_matched', 'proforma_paid_in_morning'];
        $needsClassify = false;
        foreach ($rows as $r) {
            $is300 = ($r['doc_type'] ?? '') === '300' || ($r['doc_kind'] ?? '') === 'transaction_account';
            if ($is300 && !in_array($r['income_reason'] ?? null, $knownReasons, true)) {
                $needsClassify = true;
                break;
            }
        }
        $classified = [];
        if ($needsClassify) {
            $input = [];
            foreach ($rows as $i => $r) {
                $key = (string) ($r['id'] ?? $i);
                $kind = $r['doc_kind'] ?? self::kindForCode((string) ($r['doc_type'] ?? ''));
                $cid = $r['client_id'] ?? $r['origin_client_id'] ?? null;
                $input[] = [
                    'key'         => $key,
                    'kind'        => is_string($kind) ? $kind : null,
                    'amount'      => (float) ($r['amount'] ?? 0),
                    'client_key'  => $cid !== null ? 'c:' . (int) $cid : null,
                    'date'        => is_string($r['doc_date'] ?? null) ? $r['doc_date'] : null,
                    'status'      => is_string($r['status'] ?? null) ? $r['status'] : null,
                    'paid_amount' => isset($r['paid_amount']) && is_numeric($r['paid_amount']) ? (float) $r['paid_amount'] : null,
                ];
            }
            $classified = self::classify($input);
        }

        $out = [];
        foreach ($rows as $i => $r) {
            $is300 = ($r['doc_type'] ?? '') === '300' || ($r['doc_kind'] ?? '') === 'transaction_account';
            if (!$is300 || self::isPaidInMorning($r)) {
                continue;
            }
            $reason = $r['income_reason'] ?? null;
            if (in_array($reason, $knownReasons, true)) {
                if ($reason === 'proforma_open' && ($r['matched_document_id'] ?? null) === null) {
                    $out[] = $r;
                }
                continue;
            }
            $key = (string) ($r['id'] ?? $i);
            if (($classified[$key]['reason'] ?? '') === 'proforma_open') {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * @param list<array{0:string, 1:string}> $bucket
     */
    private static function takeNearest(array &$bucket, string $targetDate): string
    {
        $best = null;
        $bestDist = PHP_INT_MAX;
        foreach ($bucket as $i => [$candKey, $candDate]) {
            $dist = self::dayDistance($targetDate, $candDate);
            if ($dist < $bestDist || ($dist === $bestDist && $best !== null && $candKey < $bucket[$best][0])) {
                $best = $i;
                $bestDist = $dist;
            }
        }
        $matched = $bucket[$best][0];
        array_splice($bucket, $best, 1);
        return $matched;
    }

    private static function dayDistance(string $a, string $b): int
    {
        $ta = strtotime(substr($a, 0, 10));
        $tb = strtotime(substr($b, 0, 10));
        if ($ta === false || $tb === false) {
            return PHP_INT_MAX - 1;
        }
        return (int) abs(intdiv($ta - $tb, 86400));
    }
}
