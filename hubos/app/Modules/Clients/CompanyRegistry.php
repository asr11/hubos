<?php

declare(strict_types=1);

namespace HubOS\Modules\Clients;

use HubOS\Core\Http\HttpClient;
use HubOS\Core\Http\HttpClientException;

/**
 * Israeli company registry (רשם החברות) via data.gov.il CKAN datastore.
 * Returns SUGGESTIONS only — nothing is stored here. Network failures are
 * turned into a friendly error result, never an exception.
 */
final class CompanyRegistry
{
    public const DEFAULT_URL = 'https://data.gov.il/api/3/action/datastore_search?resource_id=f004176c-b85f-4542-8901-7b3176f9a054';

    public function __construct(private HttpClient $http, private string $baseUrl = self::DEFAULT_URL)
    {
    }

    public static function normalizeTaxId(?string $taxId): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $taxId);
        return (strlen($digits) >= 5 && strlen($digits) <= 9) ? $digits : null;
    }

    /**
     * @return array{ok:true, suggestion:array<string, string|null>}|array{ok:false, error:string}
     */
    public function lookup(?string $taxId): array
    {
        $id = self::normalizeTaxId($taxId);
        if ($id === null) {
            return ['ok' => false, 'error' => 'אין ח.פ תקין ללקוח (נדרשות 5–9 ספרות)'];
        }
        $url = $this->baseUrl . (str_contains($this->baseUrl, '?') ? '&' : '?') . 'q=' . rawurlencode($id);
        try {
            $res = $this->http->get($url, 8);
        } catch (HttpClientException) {
            return ['ok' => false, 'error' => 'לא ניתן להתחבר למאגר רשם החברות (data.gov.il). נסו שוב מאוחר יותר.'];
        }
        if ($res['status'] !== 200) {
            return ['ok' => false, 'error' => 'מאגר רשם החברות החזיר שגיאה (HTTP ' . $res['status'] . ')'];
        }
        $json = json_decode($res['body'], true);
        if (!is_array($json) || ($json['success'] ?? false) !== true || !is_array($json['result']['records'] ?? null)) {
            return ['ok' => false, 'error' => 'תשובה לא תקינה ממאגר רשם החברות'];
        }
        foreach ($json['result']['records'] as $rec) {
            if (is_array($rec) && ltrim((string) ($rec['מספר חברה'] ?? ''), '0') === ltrim($id, '0')) {
                return ['ok' => true, 'suggestion' => self::map($rec, $id)];
            }
        }
        return ['ok' => false, 'error' => 'ח.פ ' . $id . ' לא נמצא במאגר רשם החברות'];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, string|null>
     */
    private static function map(array $r, string $id): array
    {
        $s = static function (string $key) use ($r): ?string {
            $v = $r[$key] ?? null;
            if (!is_scalar($v)) {
                return null;
            }
            // The dataset encodes the Hebrew double quote (בע"מ) as '~'.
            $v = trim(str_replace('~', '"', (string) $v));
            return $v === '' ? null : $v;
        };
        $street = trim(implode(' ', array_filter([$s('שם רחוב'), $s('מספר בית')])));
        $city = $s('שם עיר');
        $date = $s('תאריך התאגדות');
        if ($date !== null && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $date, $m) === 1) {
            $date = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return [
            'tax_id'             => $id,
            'name'               => $s('שם חברה'),
            'name_en'            => $s('שם באנגלית'),
            'status'             => $s('סטטוס חברה'),
            'company_type'       => $s('סוג תאגיד'),
            'address'            => $street === '' ? null : $street,
            'city'               => $city,
            'zip'                => $s('מיקוד'),
            'incorporation_date' => $date,
            'source'             => 'data.gov.il / רשם החברות',
            'fetched_at'         => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }
}
