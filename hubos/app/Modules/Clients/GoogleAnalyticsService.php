<?php

declare(strict_types=1);

namespace HubOS\Modules\Clients;

use DateTimeImmutable;
use DateTimeZone;
use HubOS\Core\Events;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Core\Vault;
use InvalidArgumentException;

/**
 * M11 — Admin-Only Google Search Console & Google Analytics (GA4) Integration.
 *
 * Supports BOTH:
 *   1. Agency Service Account JSON (encrypted AES-256-GCM in tenant_settings)
 *   2. Google OAuth 2.0 Refresh Token (encrypted AES-256-GCM in tenant_settings)
 *
 * Security & Anti-Hallucination Invariants:
 *   - Credentials and raw sync errors are strictly Admin-only (NEVER exposed to client_viewer).
 *   - Zero fabricated metrics: only verified Google API responses populate snapshot_json.
 *   - Zero external PHP dependencies: uses native OpenSSL RS256 JWT + cURL HTTPS.
 */
final class GoogleAnalyticsService
{
    private const SETTING_SA_ENC    = 'google_sa_json_enc';
    private const SETTING_SA_EMAIL  = 'google_sa_client_email';
    private const SETTING_OAUTH_ENC = 'google_oauth_json_enc';

    public function __construct(private Tenant $tenant, private Permissions $perms)
    {
    }

    /**
     * Save Agency-wide Google Service Account JSON (encrypted AES-256-GCM).
     *
     * @param array<string, mixed> $actor
     */
    public function saveServiceAccountJson(array $actor, string $rawJson): string
    {
        $this->perms->authorize($actor, 'users.manage');
        $decoded = json_decode(trim($rawJson), true);
        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new InvalidArgumentException('קובץ Service Account JSON לא תקין — חסר client_email או private_key');
        }
        $email = strtolower(trim((string) $decoded['client_email']));
        $vault = Vault::fromEnv();
        if (!$vault->hasKey()) {
            throw new InvalidArgumentException('חסר מפתח הצפנה HUBOS_VAULT_KEY בשרת לשמירת מפתחות Google');
        }
        $enc = $vault->encrypt(json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->tenant->transaction(function (Tenant $t) use ($actor, $enc, $email): void {
            $this->upsertSetting($t, self::SETTING_SA_ENC, $enc);
            $this->upsertSetting($t, self::SETTING_SA_EMAIL, $email);
            Events::record($t, (int) $actor['id'], 'tenant', $t->id(), null, 'integration.google_sa_saved', null, [
                'service_account_email' => $email,
            ]);
        });
        return $email;
    }

    /**
     * Save Agency-wide Google OAuth 2.0 credentials (Client ID, Client Secret, Refresh Token) encrypted AES-256-GCM.
     *
     * @param array<string, mixed> $actor
     */
    public function saveOAuthCredentials(array $actor, string $clientId, string $clientSecret, string $refreshToken): void
    {
        $this->perms->authorize($actor, 'users.manage');
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        $refreshToken = trim($refreshToken);
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new InvalidArgumentException('חובה להזין Client ID, Client Secret ו-Refresh Token עבור חיבור OAuth');
        }
        $vault = Vault::fromEnv();
        if (!$vault->hasKey()) {
            throw new InvalidArgumentException('חסר מפתח הצפנה HUBOS_VAULT_KEY בשרת');
        }
        $enc = $vault->encrypt(json_encode([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ], JSON_UNESCAPED_SLASHES));

        $this->tenant->transaction(function (Tenant $t) use ($actor, $enc): void {
            $this->upsertSetting($t, self::SETTING_OAUTH_ENC, $enc);
            Events::record($t, (int) $actor['id'], 'tenant', $t->id(), null, 'integration.google_oauth_saved', null, [
                'configured' => true,
            ]);
        });
    }

    /**
     * Admin-only status of agency-level Google credentials + per-client connection.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function adminConnectionState(array $actor, int $clientId): array
    {
        $this->perms->authorize($actor, 'clients.view');
        if (!Client360Service::isInternal($actor)) {
            throw new HttpException(403, 'אזור זה מיועד למנהלים בלבד');
        }
        $saEmail = $this->getSetting(self::SETTING_SA_EMAIL);
        $hasSa = $this->getSetting(self::SETTING_SA_ENC) !== null;
        $hasOAuth = $this->getSetting(self::SETTING_OAUTH_ENC) !== null;

        $row = $this->tenant->selectOne(
            'SELECT * FROM client_analytics_connections WHERE tenant_id = :tenant_id AND client_id = :cid',
            ['cid' => $clientId]
        );
        $snapshot = null;
        if (is_array($row) && is_string($row['snapshot_json'] ?? null)) {
            $dec = json_decode($row['snapshot_json'], true);
            $snapshot = is_array($dec) ? $dec : null;
        }

        return [
            'has_service_account'   => $hasSa,
            'service_account_email' => $saEmail,
            'has_oauth'             => $hasOAuth,
            'connection'            => $row,
            'snapshot'              => $snapshot,
        ];
    }

    /**
     * Client-safe sanitized analytics payload for the Client Portal & PDF Report.
     * NEVER exposes credentials, service account emails, or raw API error traces.
     *
     * @return array<string, mixed>|null
     */
    public function portalSummary(int $clientId): ?array
    {
        $row = $this->tenant->selectOne(
            'SELECT gsc_site_url, ga4_property_id, ga4_measurement_id, status, last_synced_at, snapshot_json
               FROM client_analytics_connections
              WHERE tenant_id = :tenant_id AND client_id = :cid',
            ['cid' => $clientId]
        );
        if (!is_array($row)) {
            return null;
        }
        $snap = is_string($row['snapshot_json'] ?? null) ? json_decode($row['snapshot_json'], true) : null;
        return [
            'status'             => (string) ($row['status'] ?? 'pending'),
            'has_gsc_target'     => !empty($row['gsc_site_url']),
            'has_ga4_target'     => !empty($row['ga4_property_id']) || !empty($row['ga4_measurement_id']),
            'ga4_measurement_id' => $row['ga4_measurement_id'] ?? null,
            'last_synced_at'     => $row['last_synced_at'] ?? null,
            'metrics'            => is_array($snap) ? $snap : null,
        ];
    }

    /**
     * Save per-client Search Console site URL & GA4 property/measurement IDs (Admin only).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function saveClientConnection(array $actor, int $clientId, array $input): void
    {
        $this->perms->authorize($actor, 'clients.update');
        if (!Client360Service::isInternal($actor)) {
            throw new HttpException(403, 'רק מנהל יכול להגדיר חיבורי אנליטיקס וקונסול');
        }
        if ($this->tenant->find('clients', $clientId) === null) {
            throw new HttpException(404, 'הלקוח לא נמצא');
        }

        $gscUrl = Str::limit(trim((string) ($input['gsc_site_url'] ?? '')), 255);
        $ga4Prop = preg_replace('/[^0-9]/', '', (string) ($input['ga4_property_id'] ?? '')) ?? '';
        $ga4Meas = strtoupper(trim((string) ($input['ga4_measurement_id'] ?? '')));
        if ($ga4Meas !== '' && preg_match('/^G-[A-Z0-9]{4,16}$/', $ga4Meas) !== 1) {
            throw new InvalidArgumentException('מזהה מדידה GA4 חייב להיות בפורמט G-XXXXXXXXXX');
        }
        $authMode = in_array($input['auth_mode'] ?? '', ['service_account', 'oauth'], true)
            ? (string) $input['auth_mode']
            : 'service_account';

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $existing = $this->tenant->selectOne(
            'SELECT id, status FROM client_analytics_connections WHERE tenant_id = :tenant_id AND client_id = :cid',
            ['cid' => $clientId]
        );

        $this->tenant->transaction(function (Tenant $t) use ($actor, $clientId, $existing, $gscUrl, $ga4Prop, $ga4Meas, $authMode, $now): void {
            $payload = [
                'gsc_site_url'       => $gscUrl !== '' ? $gscUrl : null,
                'ga4_property_id'    => $ga4Prop !== '' ? $ga4Prop : null,
                'ga4_measurement_id' => $ga4Meas !== '' ? $ga4Meas : null,
                'auth_mode'          => $authMode,
                'updated_at'         => $now,
            ];
            if (is_array($existing)) {
                $t->update('client_analytics_connections', (int) $existing['id'], $payload);
                $id = (int) $existing['id'];
            } else {
                $payload['client_id'] = $clientId;
                $payload['status'] = 'pending';
                $payload['created_at'] = $now;
                $id = $t->insert('client_analytics_connections', $payload);
            }
            Events::record($t, (int) $actor['id'], 'client', $clientId, $clientId, 'client.analytics_configured', null, [
                'connection_id'      => $id,
                'gsc_site_url'       => $payload['gsc_site_url'],
                'ga4_property_id'    => $payload['ga4_property_id'],
                'ga4_measurement_id' => $payload['ga4_measurement_id'],
                'auth_mode'          => $authMode,
            ]);
        });
    }

    /**
     * Pull real 28-day Search Console & GA4 data from Google Read-Only APIs (Admin only).
     *
     * @param array<string, mixed> $actor
     * @return array{ok: bool, message: string}
     */
    public function syncClient(array $actor, int $clientId): array
    {
        $this->perms->authorize($actor, 'clients.update');
        if (!Client360Service::isInternal($actor)) {
            throw new HttpException(403, 'רק מנהל יכול לסנכרן נתוני Google');
        }
        $conn = $this->tenant->selectOne(
            'SELECT * FROM client_analytics_connections WHERE tenant_id = :tenant_id AND client_id = :cid',
            ['cid' => $clientId]
        );
        if (!is_array($conn) || (empty($conn['gsc_site_url']) && empty($conn['ga4_property_id']))) {
            throw new InvalidArgumentException('יש להגדיר תחילה נכס Search Console (למשל sc-domain:example.com) או GA4 Property ID');
        }

        $token = $this->obtainGoogleAccessToken((string) ($conn['auth_mode'] ?? 'service_account'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $endCur = $now->modify('-2 days')->format('Y-m-d');
        $startCur = $now->modify('-29 days')->format('Y-m-d');
        $endPrev = $now->modify('-30 days')->format('Y-m-d');
        $startPrev = $now->modify('-57 days')->format('Y-m-d');

        $snapshot = [
            'period_days' => 28,
            'range_start' => $startCur,
            'range_end'   => $endCur,
        ];
        $errors = [];

        if (!empty($conn['gsc_site_url'])) {
            try {
                $gsc = $this->fetchSearchConsole((string) $conn['gsc_site_url'], $token, $startCur, $endCur, $startPrev, $endPrev);
                $snapshot['gsc'] = $gsc;
                $this->syncKeywordRanksFromGsc($actor, $clientId, $gsc['top_queries'] ?? []);
            } catch (\Throwable $e) {
                $errors[] = 'Search Console: ' . $e->getMessage();
            }
        }

        if (!empty($conn['ga4_property_id'])) {
            try {
                $snapshot['ga4'] = $this->fetchGa4((string) $conn['ga4_property_id'], $token, $startCur, $endCur, $startPrev, $endPrev);
            } catch (\Throwable $e) {
                $errors[] = 'GA4: ' . $e->getMessage();
            }
        }

        $ok = isset($snapshot['gsc']) || isset($snapshot['ga4']);
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        $this->tenant->transaction(function (Tenant $t) use ($actor, $clientId, $conn, $ok, $snapshot, $errors, $nowIso): void {
            $upd = [
                'status'     => $ok ? 'connected' : 'error',
                'last_error' => $errors !== [] ? implode(' | ', $errors) : null,
                'updated_at' => $nowIso,
            ];
            if ($ok) {
                $upd['last_synced_at'] = $nowIso;
                $upd['snapshot_json'] = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $t->update('client_analytics_connections', (int) $conn['id'], $upd);
            Events::record($t, (int) $actor['id'], 'client', $clientId, $clientId, 'client.analytics_synced', null, [
                'ok'      => $ok,
                'has_gsc' => isset($snapshot['gsc']),
                'has_ga4' => isset($snapshot['ga4']),
            ]);
        });

        if (!$ok) {
            return ['ok' => false, 'message' => implode(' · ', $errors)];
        }
        $msg = 'סנכרון Google הושלם בהצלחה!';
        if ($errors !== []) {
            $msg .= ' (הערה חלקית: ' . implode(' · ', $errors) . ')';
        }
        return ['ok' => true, 'message' => $msg];
    }

    private function obtainGoogleAccessToken(string $mode): string
    {
        $vault = Vault::fromEnv();
        if (!$vault->hasKey()) {
            throw new InvalidArgumentException('חסר מפתח הצפנה HUBOS_VAULT_KEY בשרת');
        }

        if ($mode === 'oauth') {
            $enc = $this->getSetting(self::SETTING_OAUTH_ENC);
            if ($enc === null) {
                throw new InvalidArgumentException('טרם הוגדרו פרטי חיבור Google OAuth לסוכנות — הגדירו למטה בלחיצה');
            }
            $creds = json_decode($vault->decrypt($enc), true);
            $res = self::httpRequest('POST', 'https://oauth2.googleapis.com/token', http_build_query([
                'grant_type'    => 'refresh_token',
                'client_id'     => $creds['client_id'] ?? '',
                'client_secret' => $creds['client_secret'] ?? '',
                'refresh_token' => $creds['refresh_token'] ?? '',
            ]), ['Content-Type: application/x-www-form-urlencoded']);
            if (empty($res['access_token'])) {
                throw new InvalidArgumentException('חידוש טוקן OAuth מול Google נכשל — בדקו הרשאות ו-Refresh Token');
            }
            return (string) $res['access_token'];
        }

        $enc = $this->getSetting(self::SETTING_SA_ENC);
        if ($enc === null) {
            throw new InvalidArgumentException('טרם הוגדר מפתח Google Service Account JSON לסוכנות — הדביקו את ה-JSON בחלונית ההגדרה למטה והוסיפו את המייל כצופה ב-Search Console / Analytics');
        }
        $sa = json_decode($vault->decrypt($enc), true);
        $now = time();
        $header = self::b64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64Url(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], JSON_UNESCAPED_SLASHES));
        $unsigned = $header . '.' . $claims;
        $sig = '';
        if (!openssl_sign($unsigned, $sig, (string) $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('חתימת מפתח ה-Service Account נכשלה');
        }
        $jwt = $unsigned . '.' . self::b64Url($sig);
        $res = self::httpRequest('POST', 'https://oauth2.googleapis.com/token', http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        if (empty($res['access_token'])) {
            throw new InvalidArgumentException('אימות Google Service Account נכשל');
        }
        return (string) $res['access_token'];
    }

    /** @return array<string, mixed> */
    private function fetchSearchConsole(string $siteUrl, string $token, string $sCur, string $eCur, string $sPrev, string $ePrev): array
    {
        $base = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';
        $hdr = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];

        $curTot = self::httpRequest('POST', $base, json_encode(['startDate' => $sCur, 'endDate' => $eCur]), $hdr);
        $prevTot = self::httpRequest('POST', $base, json_encode(['startDate' => $sPrev, 'endDate' => $ePrev]), $hdr);
        $queriesRes = self::httpRequest('POST', $base, json_encode(['startDate' => $sCur, 'endDate' => $eCur, 'dimensions' => ['query'], 'rowLimit' => 12]), $hdr);
        $pagesRes = self::httpRequest('POST', $base, json_encode(['startDate' => $sCur, 'endDate' => $eCur, 'dimensions' => ['page'], 'rowLimit' => 8]), $hdr);

        $r0 = $curTot['rows'][0] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
        $p0 = $prevTot['rows'][0] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];

        $queries = [];
        foreach (($queriesRes['rows'] ?? []) as $r) {
            $queries[] = [
                'query'       => (string) ($r['keys'][0] ?? ''),
                'clicks'      => (int) round((float) ($r['clicks'] ?? 0)),
                'impressions' => (int) round((float) ($r['impressions'] ?? 0)),
                'position'    => round((float) ($r['position'] ?? 0), 1),
            ];
        }
        $pages = [];
        foreach (($pagesRes['rows'] ?? []) as $r) {
            $pages[] = [
                'url'         => (string) ($r['keys'][0] ?? ''),
                'clicks'      => (int) round((float) ($r['clicks'] ?? 0)),
                'impressions' => (int) round((float) ($r['impressions'] ?? 0)),
            ];
        }

        return [
            'clicks'           => (int) round((float) ($r0['clicks'] ?? 0)),
            'impressions'      => (int) round((float) ($r0['impressions'] ?? 0)),
            'ctr_pct'          => round(((float) ($r0['ctr'] ?? 0)) * 100, 1),
            'avg_position'     => round((float) ($r0['position'] ?? 0), 1),
            'prev_clicks'      => (int) round((float) ($p0['clicks'] ?? 0)),
            'prev_impressions' => (int) round((float) ($p0['impressions'] ?? 0)),
            'top_queries'      => $queries,
            'top_pages'        => $pages,
        ];
    }

    /** @return array<string, mixed> */
    private function fetchGa4(string $propertyId, string $token, string $sCur, string $eCur, string $sPrev, string $ePrev): array
    {
        $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($propertyId) . ':runReport';
        $hdr = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];

        $totRes = self::httpRequest('POST', $url, json_encode([
            'dateRanges' => [
                ['startDate' => $sCur, 'endDate' => $eCur],
                ['startDate' => $sPrev, 'endDate' => $ePrev],
            ],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews'],
            ],
        ]), $hdr);

        $curVals = $totRes['rows'][0]['metricValues'] ?? [];
        $prevVals = $totRes['rows'][1]['metricValues'] ?? [];

        $pagesRes = self::httpRequest('POST', $url, json_encode([
            'dateRanges' => [['startDate' => $sCur, 'endDate' => $eCur]],
            'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
            'metrics'    => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
            'limit'      => 8,
        ]), $hdr);

        $topPages = [];
        foreach (($pagesRes['rows'] ?? []) as $r) {
            $topPages[] = [
                'path'  => (string) ($r['dimensionValues'][0]['value'] ?? '/'),
                'title' => (string) ($r['dimensionValues'][1]['value'] ?? ''),
                'views' => (int) ($r['metricValues'][0]['value'] ?? 0),
                'users' => (int) ($r['metricValues'][1]['value'] ?? 0),
            ];
        }

        return [
            'users'         => (int) ($curVals[0]['value'] ?? 0),
            'sessions'      => (int) ($curVals[1]['value'] ?? 0),
            'page_views'    => (int) ($curVals[2]['value'] ?? 0),
            'prev_users'    => (int) ($prevVals[0]['value'] ?? 0),
            'prev_sessions' => (int) ($prevVals[1]['value'] ?? 0),
            'top_pages'     => $topPages,
        ];
    }

    /**
     * Automatically enrich tracked SEO keywords with verified average Google position when exact match appears in GSC.
     *
     * @param array<string, mixed> $actor
     * @param list<array<string, mixed>> $topQueries
     */
    private function syncKeywordRanksFromGsc(array $actor, int $clientId, array $topQueries): void
    {
        if ($topQueries === []) {
            return;
        }
        $byQuery = [];
        foreach ($topQueries as $q) {
            $k = mb_strtolower_safe((string) ($q['query'] ?? ''));
            if ($k !== '' && ($q['position'] ?? 0) > 0) {
                $byQuery[$k] = max(1, (int) round((float) $q['position']));
            }
        }
        $seoSvc = new ClientSeoService($this->tenant, $this->perms);
        foreach ($seoSvc->listForClient($clientId) as $kw) {
            $k = mb_strtolower_safe((string) ($kw['keyword'] ?? ''));
            if (isset($byQuery[$k])) {
                $rank = $byQuery[$k];
                $stage = $rank <= 3 ? 'top3' : ($rank <= 10 ? 'top10' : 'tracking');
                $seoSvc->store($actor, $clientId, [
                    'keyword'      => (string) $kw['keyword'],
                    'target_url'   => (string) ($kw['target_url'] ?? ''),
                    'stage'        => $stage,
                    'current_rank' => (string) $rank,
                    'notes'        => (string) ($kw['notes'] ?? ''),
                ]);
            }
        }
    }

    private function getSetting(string $key): ?string
    {
        $row = $this->tenant->selectOne(
            'SELECT value FROM tenant_settings WHERE tenant_id = :tenant_id AND key = :k',
            ['k' => $key]
        );
        return is_array($row) && isset($row['value']) && $row['value'] !== '' ? (string) $row['value'] : null;
    }

    private function upsertSetting(Tenant $t, string $key, string $value): void
    {
        $exists = $t->selectOne('SELECT key FROM tenant_settings WHERE tenant_id = :tenant_id AND key = :k', ['k' => $key]);
        if ($exists !== null) {
            $t->updateWhere('tenant_settings', ['value' => $value, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')], ['key' => $key]);
        } else {
            $t->insert('tenant_settings', ['key' => $key, 'value' => $value]);
        }
    }

    private static function b64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private static function httpRequest(string $method, string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 25,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            throw new InvalidArgumentException('שגיאת תקשורת מול Google API: ' . $err);
        }
        $json = json_decode((string) $res, true);
        if ($code < 200 || $code >= 300) {
            $gErr = is_array($json) ? ($json['error']['message'] ?? ($json['error_description'] ?? ('HTTP ' . $code))) : ('HTTP ' . $code);
            throw new InvalidArgumentException((string) $gErr);
        }
        return is_array($json) ? $json : [];
    }
}

function mb_strtolower_safe(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower(trim($s), 'UTF-8') : strtolower(trim($s));
}
