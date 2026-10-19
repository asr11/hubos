<?php

declare(strict_types=1);

namespace HubOS\Modules\Import;

/**
 * Resolves legacy references to new client ids.
 * Order: legacy id -> Morning (GreenInvoice) client id -> tax id -> domain -> exact name.
 * Fallback keys (tax id / domain / name) are used only when they are UNIQUE
 * among clients; ambiguous keys never link (no guessing).
 */
final class ClientResolver
{
    /** @var array<string, int> */
    private array $byLegacy = [];
    /** @var array<string, list<int>> */
    private array $byGi = [];
    /** @var array<string, list<int>> */
    private array $byTax = [];
    /** @var array<string, list<int>> */
    private array $byDomain = [];
    /** @var array<string, list<int>> */
    private array $byName = [];

    public function add(int $id, string $legacyId, ?string $giId = null, ?string $taxId = null, ?string $domain = null, ?string $name = null): void
    {
        $this->byLegacy[$legacyId] = $id;
        if (($k = self::normGi($giId)) !== null) {
            $this->byGi[$k][] = $id;
        }
        if (($k = self::normTax($taxId)) !== null) {
            $this->byTax[$k][] = $id;
        }
        if (($k = self::normDomain($domain)) !== null) {
            $this->byDomain[$k][] = $id;
        }
        if (($k = self::normName($name)) !== null) {
            $this->byName[$k][] = $id;
        }
    }

    /**
     * @param array{legacy?:?string, gi?:?string, tax?:?string, domain?:?string, name?:?string} $ref
     * @return array{0:?int, 1:?string} [client id, method]
     */
    public function resolve(array $ref): array
    {
        $legacy = isset($ref['legacy']) ? trim((string) $ref['legacy']) : '';
        if ($legacy !== '' && isset($this->byLegacy[$legacy])) {
            return [$this->byLegacy[$legacy], 'legacy_id'];
        }
        $checks = [
            ['gi', $this->byGi, self::normGi($ref['gi'] ?? null), 'morning_client_id'],
            ['tax', $this->byTax, self::normTax($ref['tax'] ?? null), 'tax_id'],
            ['domain', $this->byDomain, self::normDomain($ref['domain'] ?? null), 'domain'],
            ['name', $this->byName, self::normName($ref['name'] ?? null), 'name_exact'],
        ];
        foreach ($checks as [, $map, $key, $method]) {
            if ($key !== null && isset($map[$key]) && count(array_unique($map[$key])) === 1) {
                return [$map[$key][0], $method];
            }
        }
        return [null, null];
    }

    public function hasLegacy(string $legacyId): bool
    {
        return isset($this->byLegacy[$legacyId]);
    }

    public function idForLegacy(string $legacyId): ?int
    {
        return $this->byLegacy[$legacyId] ?? null;
    }

    public static function normGi(?string $v): ?string
    {
        $v = strtolower(trim((string) $v));
        $v = (string) preg_replace('/^gi_/', '', $v);
        return preg_match('/^[0-9a-f-]{8,}$/', $v) === 1 ? $v : null;
    }

    public static function normTax(?string $v): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $v);
        if ($digits === '' || strlen($digits) < 5 || preg_match('/^0+$/', $digits) === 1) {
            return null;
        }
        return ltrim($digits, '0');
    }

    public static function normDomain(?string $v): ?string
    {
        $v = strtolower(trim((string) $v));
        $v = (string) preg_replace('#^[a-z]+://#', '', $v);
        $v = (string) preg_replace('#[/?\#].*$#', '', $v);
        $v = (string) preg_replace('/^www\./', '', $v);
        $v = trim($v, '. ');
        return preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $v) === 1 ? $v : null;
    }

    public static function normName(?string $v): ?string
    {
        $v = (string) preg_replace('/["\'״׳`]+/u', '', (string) $v);
        $v = (string) preg_replace('/\s+/u', ' ', $v);
        $v = strtolower(trim($v));
        return $v === '' ? null : $v;
    }
}
