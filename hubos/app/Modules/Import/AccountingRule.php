<?php

declare(strict_types=1);

namespace HubOS\Modules\Import;

/**
 * Accounting rule (חוק המסמך החשבונאי).
 *
 * Document semantics come from Morning's `documentKind`, which the data maps
 * 1:1 to codes: 300=transaction_account, 305=tax_invoice,
 * 320=tax_invoice_receipt, 400=receipt (see import report).
 *
 *  - Invoice-type documents (300 / 305 / 320) are the transaction => income.
 *  - A receipt (400) only confirms payment. It counts as income ONLY when no
 *    invoice-type document of the same client with the same amount exists to
 *    pair with it. Pairing is one-to-one (an invoice absorbs at most one
 *    receipt), nearest date first, so N receipts vs 1 invoice => N-1 count.
 *  - Any other / unknown kind is NOT counted and is flagged for review.
 */
final class AccountingRule
{
    /** Code => kind, as observed in the source data. */
    public const CODE_TO_KIND = [
        '300' => 'transaction_account',
        '305' => 'tax_invoice',
        '320' => 'tax_invoice_receipt',
        '400' => 'receipt',
    ];

    public const INVOICE_KINDS = ['transaction_account', 'tax_invoice', 'tax_invoice_receipt'];
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

    /**
     * @param list<array{key:string, kind:?string, amount:float, client_key:?string, date:?string}> $docs
     * @return array<string, array{counts:bool, reason:string, matched:?string}>
     */
    public static function classify(array $docs): array
    {
        $out = [];
        $pool = []; // client_key|cents => list of [key, date]
        $receipts = [];

        foreach ($docs as $d) {
            $kind = $d['kind'];
            if (in_array($kind, self::INVOICE_KINDS, true)) {
                $out[$d['key']] = ['counts' => true, 'reason' => 'invoice', 'matched' => null];
                if ($d['client_key'] !== null) {
                    $pool[$d['client_key'] . '|' . self::cents($d['amount'])][] = [$d['key'], (string) $d['date']];
                }
            } elseif (in_array($kind, self::RECEIPT_KINDS, true)) {
                $receipts[] = $d;
            } else {
                $out[$d['key']] = ['counts' => false, 'reason' => 'unknown_type', 'matched' => null];
            }
        }

        usort($receipts, static fn (array $a, array $b): int => [(string) $a['date'], $a['key']] <=> [(string) $b['date'], $b['key']]);
        foreach ($receipts as $r) {
            $bucket = $r['client_key'] === null ? null : $r['client_key'] . '|' . self::cents($r['amount']);
            if ($bucket === null || empty($pool[$bucket])) {
                $out[$r['key']] = ['counts' => true, 'reason' => 'receipt_unmatched', 'matched' => null];
                continue;
            }
            $best = null;
            $bestDist = PHP_INT_MAX;
            foreach ($pool[$bucket] as $i => [$invKey, $invDate]) {
                $dist = self::dayDistance((string) $r['date'], $invDate);
                if ($dist < $bestDist || ($dist === $bestDist && $best !== null && $invKey < $pool[$bucket][$best][0])) {
                    $best = $i;
                    $bestDist = $dist;
                }
            }
            $matched = $pool[$bucket][$best][0];
            array_splice($pool[$bucket], $best, 1);
            $out[$r['key']] = ['counts' => false, 'reason' => 'receipt_matched', 'matched' => $matched];
        }

        return $out;
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
