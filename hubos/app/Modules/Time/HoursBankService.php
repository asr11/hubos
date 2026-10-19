<?php

declare(strict_types=1);

namespace HubOS\Modules\Time;

use HubOS\Core\Events;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Tenant;
use HubOS\Core\ValidationException;

/**
 * Hours banks: usage, 80%/90% thresholds and the monthly rollover computation.
 *
 *  - one_time bank: allowance = total_hours, used = all entries linked to the bank.
 *  - monthly bank:  allowance per month = total_hours (+ carry-in when rollover=1,
 *    optionally capped by rollover_cap_hours); used = entries of that month.
 *    Unused hours carry forward; overuse never carries a negative balance.
 */
final class HoursBankService
{
    public const WARN = 0.8;
    public const CRITICAL = 0.9;

    public function __construct(private Tenant $tenant, private ?Permissions $perms = null)
    {
    }

    /**
     * Threshold level for used/allowance: ok < 80% ≤ warn80 < 90% ≤ warn90 < 100% ≤ over.
     */
    public static function level(float $usedHours, float $allowanceHours): string
    {
        if ($allowanceHours <= 0) {
            return $usedHours > 0 ? 'over' : 'ok';
        }
        $ratio = $usedHours / $allowanceHours;
        return match (true) {
            $ratio >= 1.0            => 'over',
            $ratio >= self::CRITICAL => 'warn90',
            $ratio >= self::WARN     => 'warn80',
            default                  => 'ok',
        };
    }

    /**
     * Month-by-month allowance with optional rollover.
     *
     * @param array<string, float> $usedByMonth 'YYYY-MM' => hours used in that month
     * @return list<array{month:string, base:float, carried_in:float, allowance:float, used:float, remaining:float, carry_out:float}>
     */
    public static function rollover(float $monthlyHours, bool $rollover, string $startMonth, string $currentMonth, array $usedByMonth, ?float $capHours = null): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $startMonth) !== 1 || preg_match('/^\d{4}-\d{2}$/', $currentMonth) !== 1) {
            throw new \InvalidArgumentException('Months must be YYYY-MM');
        }
        if ($startMonth > $currentMonth) {
            $startMonth = $currentMonth;
        }
        $out = [];
        $carry = 0.0;
        $month = $startMonth;
        for ($i = 0; $i < 600 && $month <= $currentMonth; $i++) {
            $used = round((float) ($usedByMonth[$month] ?? 0.0), 6);
            $carriedIn = $rollover ? $carry : 0.0;
            $allowance = $monthlyHours + $carriedIn;
            $remaining = round($allowance - $used, 6);
            $carry = $rollover ? max(0.0, $remaining) : 0.0;
            if ($capHours !== null) {
                $carry = min($carry, $capHours);
            }
            $out[] = [
                'month'      => $month,
                'base'       => $monthlyHours,
                'carried_in' => round($carriedIn, 6),
                'allowance'  => round($allowance, 6),
                'used'       => $used,
                'remaining'  => $remaining,
                'carry_out'  => round($carry, 6),
            ];
            $month = self::nextMonth($month);
        }
        return $out;
    }

    public static function nextMonth(string $month): string
    {
        [$y, $m] = array_map('intval', explode('-', $month));
        $m++;
        if ($m > 12) {
            $m = 1;
            $y++;
        }
        return sprintf('%04d-%02d', $y, $m);
    }

    /**
     * Summary of one bank for "now" (current month in Israel time).
     *
     * @param array<string, mixed> $bank
     * @return array<string, mixed> bank + used_hours, allowance_hours, remaining_hours, pct, level, low, months
     */
    public function summarize(array $bank, ?string $currentMonth = null): array
    {
        $currentMonth ??= self::currentMonth();
        $byMonth = $this->usedByMonth((int) $bank['id']);
        $total = (float) $bank['total_hours'];
        $months = [];
        if (($bank['period'] ?? 'one_time') === 'monthly') {
            $start = self::startMonth($bank, $byMonth, $currentMonth);
            $months = self::rollover($total, (int) ($bank['rollover'] ?? 0) === 1, $start, $currentMonth, $byMonth,
                $bank['rollover_cap_hours'] !== null ? (float) $bank['rollover_cap_hours'] : null);
            $cur = $months[count($months) - 1];
            $used = $cur['used'];
            $allowance = $cur['allowance'];
        } else {
            $used = array_sum($byMonth);
            $allowance = $total;
        }
        $bank['used_hours'] = $used;
        $bank['allowance_hours'] = $allowance;
        $bank['remaining_hours'] = $allowance - $used;
        $bank['pct'] = $allowance > 0 ? (int) floor($used / $allowance * 100) : ($used > 0 ? 100 : 0);
        $bank['level'] = self::level($used, $allowance);
        $bank['low'] = $bank['level'] !== 'ok';
        $bank['months'] = $months;
        return $bank;
    }

    /**
     * The bank time is deducted from: the client's oldest ACTIVE bank (own client only).
     *
     * @return array<string, mixed>|null
     */
    public function activeBankFor(int $clientId): ?array
    {
        return $this->tenant->selectOne(
            "SELECT * FROM hours_banks WHERE tenant_id = :tenant_id AND client_id = :cid AND status = 'active' ORDER BY id ASC LIMIT 1",
            ['cid' => $clientId]
        );
    }

    /**
     * @param list<int> $clientIds
     * @return list<array<string, mixed>>
     */
    public function forClients(array $clientIds): array
    {
        $out = [];
        foreach (array_values(array_unique(array_map('intval', $clientIds))) as $cid) {
            foreach ($this->tenant->all('hours_banks', ['client_id' => $cid], 'id', 'ASC') as $bank) {
                $out[] = $this->summarize($bank);
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['status'] !== 'active', (int) $a['id']] <=> [$b['status'] !== 'active', (int) $b['id']]);
        return $out;
    }

    /**
     * Log a threshold crossing (80/90/100%) caused by a time entry change. Returns the new level.
     *
     * @param array<string, mixed>|null $beforeSummary
     */
    public function recordThreshold(?int $actorId, int $bankId, ?array $beforeSummary): ?string
    {
        $bank = $this->tenant->find('hours_banks', $bankId);
        if ($bank === null) {
            return null;
        }
        $after = $this->summarize($bank);
        $order = ['ok' => 0, 'warn80' => 1, 'warn90' => 2, 'over' => 3];
        $beforeLevel = $beforeSummary['level'] ?? 'ok';
        if ($order[$after['level']] > $order[$beforeLevel]) {
            Events::record($this->tenant, $actorId, 'hours_bank', $bankId, $bank['client_id'] !== null ? (int) $bank['client_id'] : null,
                'hours_bank.threshold_crossed',
                ['level' => $beforeLevel, 'pct' => $beforeSummary['pct'] ?? 0],
                ['level' => $after['level'], 'pct' => $after['pct'], 'used_hours' => round($after['used_hours'], 4), 'allowance_hours' => $after['allowance_hours']]);
        }
        return $after['level'];
    }

    /**
     * Store period / rollover settings (time.manage). Logged with before/after.
     *
     * @param array<string, mixed> $user
     * @param array<string, string> $input
     */
    public function updateSettings(array $user, int $bankId, array $input): void
    {
        if ($this->perms === null) {
            throw new \LogicException('Permissions required');
        }
        $this->perms->authorize($user, 'time.manage');
        $bank = $this->tenant->find('hours_banks', $bankId);
        if ($bank === null) {
            throw new HttpException(404, 'בנק השעות לא נמצא');
        }
        $errors = [];
        $period = (string) ($input['period'] ?? 'one_time');
        if (!in_array($period, ['one_time', 'monthly'], true)) {
            $errors['period'] = 'סוג תקופה לא תקין';
        }
        $rollover = ($input['rollover'] ?? '') === '1' ? 1 : 0;
        $cap = trim((string) ($input['rollover_cap_hours'] ?? ''));
        if ($cap !== '' && (!is_numeric($cap) || (float) $cap < 0 || (float) $cap > 10000)) {
            $errors['rollover_cap_hours'] = 'תקרת גלגול לא תקינה';
        }
        $starts = trim((string) ($input['starts_on'] ?? ''));
        if ($starts !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts) !== 1) {
            $errors['starts_on'] = 'תאריך התחלה לא תקין';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $data = [
            'period'             => $period,
            'rollover'           => $period === 'monthly' ? $rollover : 0,
            'rollover_cap_hours' => $cap === '' ? null : (float) $cap,
            'starts_on'          => $starts === '' ? null : $starts,
            'updated_at'         => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $this->tenant->transaction(function (Tenant $t) use ($user, $bank, $bankId, $data): void {
            $t->update('hours_banks', $bankId, $data);
            $keys = array_flip(['period', 'rollover', 'rollover_cap_hours', 'starts_on']);
            Events::record($t, (int) $user['id'], 'hours_bank', $bankId, $bank['client_id'] !== null ? (int) $bank['client_id'] : null,
                'hours_bank.settings_updated', array_intersect_key($bank, $keys), array_intersect_key($data, $keys));
        });
    }

    /** @return array<string, float> 'YYYY-MM' => hours */
    public function usedByMonth(int $bankId): array
    {
        $out = [];
        foreach ($this->tenant->select(
            "SELECT substr(COALESCE(entry_date, started_at, created_at), 1, 7) AS ym, SUM(duration_seconds) AS secs
               FROM time_entries WHERE tenant_id = :tenant_id AND hours_bank_id = :bid GROUP BY ym ORDER BY ym",
            ['bid' => $bankId]
        ) as $r) {
            $out[(string) $r['ym']] = (int) $r['secs'] / 3600;
        }
        return $out;
    }

    public static function currentMonth(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jerusalem')))->format('Y-m');
    }

    /**
     * @param array<string, mixed> $bank
     * @param array<string, float> $byMonth
     */
    private static function startMonth(array $bank, array $byMonth, string $currentMonth): string
    {
        foreach ([$bank['starts_on'] ?? null, $bank['created_at'] ?? null] as $d) {
            if (is_string($d) && preg_match('/^(\d{4}-\d{2})/', $d, $m) === 1) {
                $first = array_key_first($byMonth);
                return ($first !== null && $first < $m[1] && ($bank['starts_on'] ?? null) === null) ? $first : $m[1];
            }
        }
        return array_key_first($byMonth) ?? $currentMonth;
    }
}
