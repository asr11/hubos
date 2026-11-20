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
 * M11 — Universal Modular Client Services Engine.
 *
 * Covers 100% of HUB's 12 historical service families (verified across 2,092 Morning docs)
 * plus a 'custom' archetype so managers can configure ANY future service without code changes.
 * Only the widgets enabled on a client's active services are rendered in the Client Portal.
 */
final class ClientServicesEngine
{
    /** Reusable visual widgets that can be composed onto any client service. */
    public const WIDGETS = [
        'gsc_ga4'           => 'נתוני Google Search Console ו-Analytics (חשיפות, קליקים, מבקרים ועמודים)',
        'seo_keywords'      => 'טבלת ביטויים ומילות מפתח בקידום אורגני',
        'domain_ssl'        => 'תקינות אתר, דומיין, תשתית ואבטחת SSL',
        'ads_metrics'       => 'ביצועי קמפיינים ממומנים (חשיפות, קליקים והמרות)',
        'leads_counter'     => 'אספקת לידים ופניות חודשית (ללא חשיפת עלויות מדיה)',
        'social_gmb'        => 'נוכחות סושיאל, וידאו ופרופיל Google Business (GMB)',
        'hours_bank'        => 'מאזן בנק שעות ופירוט שעות שבוצעו',
        'system_status'     => 'מערכת B2B / קטלוג / אפליקציה פעילה וקישור ישיר',
        'automation_status' => 'סוכני AI, אוטומציה, חיבורי CRM וטלפוניה',
        'legal_compliance'  => 'תאימות רגולציה: הצהרת נגישות, מדיניות פרטיות (תיקון 13) וקוקיז',
        'portal_listing'    => 'חשיפה ופרסום בפורטלים ונכסי מדיה',
        'deliverables'      => 'סטטוס מסירה, קישורים לתוצרים ומסמכים',
    ];

    /**
     * 12 Verified Universal Service Families + Custom Service.
     *
     * @var array<string, array{label: string, icon: string, default_cycle: string, widgets: list<string>, placeholder_scope: string}>
     */
    public const CATALOG = [
        'seo_organic' => [
            'label'             => 'קידום אורגני בגוגל (SEO & GEO)',
            'icon'              => '🔍',
            'default_cycle'     => 'monthly',
            'widgets'           => ['gsc_ga4', 'seo_keywords'],
            'placeholder_scope' => 'אופטימיזציה שוטפת למנועי חיפוש ובינה מלאכותית, קידום ביטויי מפתח ומעקב ביצועים ב-Search Console ו-Analytics.',
        ],
        'hosting_infra' => [
            'label'             => 'אחסון אתר, שרתים, דומיין ואבטחת SSL',
            'icon'              => '🛡️',
            'default_cycle'     => 'yearly',
            'widgets'           => ['domain_ssl'],
            'placeholder_scope' => 'אירוח אתר מהיר, תעודת אבטחה SSL תקפה וניטור שוטף של זמינות ותוקף הדומיין.',
        ],
        'paid_ads' => [
            'label'             => 'ניהול קמפיינים ממומנים (Google / Meta / Shopping)',
            'icon'              => '📈',
            'default_cycle'     => 'monthly',
            'widgets'           => ['ads_metrics'],
            'placeholder_scope' => 'ניהול, בקרה ואופטימיזציה שוטפת לקמפיינים ממומנים לשיפור חשיפה, תנועה והמרות.',
        ],
        'pay_per_lead' => [
            'label'             => 'אספקת לידים ופניות (Pay-Per-Lead)',
            'icon'              => '🎯',
            'default_cycle'     => 'per_lead',
            'widgets'           => ['leads_counter'],
            'placeholder_scope' => 'אספקת פניות ולידים ממוקדים לעסק במודל חודשי שקוף.',
        ],
        'social_gmb' => [
            'label'             => 'ניהול סושיאל, וידאו ו-Google Business (GMB)',
            'icon'              => '📱',
            'default_cycle'     => 'monthly',
            'widgets'           => ['social_gmb'],
            'placeholder_scope' => 'ניהול נוכחות ברשתות חברתיות, תכנים/רילס וניהול פרופיל העסק בגוגל (GMB).',
        ],
        'hours_bank' => [
            'label'             => 'בנק שעות, תמיכה טכנית ופיתוח',
            'icon'              => '⏱️',
            'default_cycle'     => 'hours',
            'widgets'           => ['hours_bank'],
            'placeholder_scope' => 'ליווי טכני, שדרוגים, תיקונים ופיתוח באתר לפי בנק שעות מאושר.',
        ],
        'systems_b2b' => [
            'label'             => 'מערכות B2B, קטלוגים, אפליקציות וטפסים חכמים',
            'icon'              => '⚙️',
            'default_cycle'     => 'monthly',
            'widgets'           => ['system_status', 'domain_ssl'],
            'placeholder_scope' => 'תפעול וליווי מערכת הזמנות B2B / קטלוג דיגיטלי / אפליקציה או מערכת טפסים ייעודית.',
        ],
        'ai_automation' => [
            'label'             => 'סוכני AI, אוטומציה, חיבורי CRM וטלפוניה',
            'icon'              => '🤖',
            'default_cycle'     => 'monthly',
            'widgets'           => ['automation_status'],
            'placeholder_scope' => 'הפעלת סוכני AI חכמים, אוטומציות דיוור/וואטסאפ, התממשקות ל-CRM ושלוחה וירטואלית.',
        ],
        'web_build' => [
            'label'             => 'בנייה ושדרוג אתרים / דפי נחיתה / מיני-סייט',
            'icon'              => '🌐',
            'default_cycle'     => 'one_time',
            'widgets'           => ['deliverables'],
            'placeholder_scope' => 'אפיון, עיצוב ופיתוח אתר רספונסיבי מהיר והעלאה מסודרת לאוויר.',
        ],
        'legal_compliance' => [
            'label'             => 'תאימות רגולציה: נגישות ותיקון 13 (פרטיות וקוקיז)',
            'icon'              => '⚖️',
            'default_cycle'     => 'one_time',
            'widgets'           => ['legal_compliance'],
            'placeholder_scope' => 'התאמת האתר לתקנות הנגישות ולתיקון 13 לחוק הגנת הפרטיות (מדיניות פרטיות ובאנר קוקיז).',
        ],
        'portal_media' => [
            'label'             => 'פרסום בפורטלים ונכסי מדיה (בקריות / קהילות)',
            'icon'              => '📣',
            'default_cycle'     => 'yearly',
            'widgets'           => ['portal_listing'],
            'placeholder_scope' => 'כרטיס עסק מקודם וחשיפה שיווקית בפורטל העסקים ובנכסי המדיה של HUB.',
        ],
        'consulting_brand' => [
            'label'             => 'ייעוץ אסטרטגי, ליווי טכנולוגי, מיתוג ודוחות',
            'icon'              => '🧭',
            'default_cycle'     => 'monthly',
            'widgets'           => ['deliverables'],
            'placeholder_scope' => 'ליווי אסטרטגי וטכנולוגי, אבחון UX/SEO או תוצרי מיתוג ושפה עיצובית.',
        ],
        'custom' => [
            'label'             => 'שירות מותאם אישית (בחירת בלוקים חופשית)',
            'icon'              => '✨',
            'default_cycle'     => 'monthly',
            'widgets'           => ['deliverables'],
            'placeholder_scope' => 'חבילת שירות מותאמת אישית לפי הגדרת מנהל התיק.',
        ],
    ];

    public const CYCLES = [
        'monthly'  => 'ריטיינר חודשי',
        'yearly'   => 'חידוש שנתי',
        'one_time' => 'פרויקט / חד-פעמי',
        'per_lead' => 'לפי לידים / חודשי',
        'hours'    => 'בנק שעות',
    ];

    public const STATUSES = [
        'active'    => 'פעיל',
        'completed' => 'הושלם / סופק',
        'paused'    => 'מושהה',
    ];

    public function __construct(private Tenant $tenant, private Permissions $perms)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForClient(int $clientId, bool $excludePaused = false): array
    {
        $sql = 'SELECT * FROM client_services WHERE tenant_id = :tenant_id AND client_id = :cid';
        if ($excludePaused) {
            $sql .= " AND status IN ('active', 'completed')";
        }
        $sql .= " ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END, sort_order ASC, id ASC";
        $rows = $this->tenant->select($sql, ['cid' => $clientId]);
        foreach ($rows as &$r) {
            $type = (string) ($r['service_type'] ?? 'custom');
            $def = self::CATALOG[$type] ?? self::CATALOG['custom'];
            $w = is_string($r['widgets_json'] ?? null) ? json_decode($r['widgets_json'], true) : null;
            $r['widgets'] = is_array($w) ? array_values(array_intersect(array_map('strval', $w), array_keys(self::WIDGETS))) : $def['widgets'];
            $m = is_string($r['metrics_json'] ?? null) ? json_decode($r['metrics_json'], true) : null;
            $r['metrics'] = is_array($m) ? $m : [];
            $r['icon'] = $def['icon'];
            $r['type_label'] = $def['label'];
            $r['cycle_label'] = self::CYCLES[$r['billing_cycle'] ?? 'monthly'] ?? 'פעיל';
            $r['status_label'] = self::STATUSES[$r['status'] ?? 'active'] ?? 'פעיל';
            unset($r['widgets_json'], $r['metrics_json']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Compute active widgets union for a list of client services.
     * For 'completed' one-time services (e.g. completed site upgrade), only 'deliverables'/'legal_compliance' stay subtle;
     * active services activate all their configured widgets.
     *
     * @param list<array<string, mixed>> $services
     * @return list<string>
     */
    public static function activeWidgets(array $services): array
    {
        $set = [];
        foreach ($services as $s) {
            $st = (string) ($s['status'] ?? 'active');
            if ($st === 'paused') {
                continue;
            }
            foreach (($s['widgets'] ?? []) as $w) {
                if ($st === 'completed' && in_array($w, ['hours_bank', 'ads_metrics', 'leads_counter'], true)) {
                    continue;
                }
                $set[(string) $w] = true;
            }
        }
        return array_keys($set);
    }

    /**
     * Create or update a client service (internal managers only).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function store(array $actor, int $clientId, array $input): int
    {
        $this->perms->authorize($actor, 'clients.update');
        if ($this->tenant->find('clients', $clientId) === null) {
            throw new HttpException(404, 'הלקוח לא נמצא');
        }

        $type = Str::clean((string) ($input['service_type'] ?? 'seo_organic'));
        if (!isset(self::CATALOG[$type])) {
            throw new InvalidArgumentException('סוג שירות לא תקין');
        }
        $def = self::CATALOG[$type];

        $title = Str::clean((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = $def['label'];
        }
        if (Str::len($title) > 200) {
            throw new InvalidArgumentException('כותרת השירות ארוכה מדי (עד 200 תווים)');
        }

        $scope = Str::limit(Str::clean((string) ($input['scope_summary'] ?? '')), 1000);
        $cycle = Str::clean((string) ($input['billing_cycle'] ?? $def['default_cycle']));
        if (!isset(self::CYCLES[$cycle])) {
            $cycle = $def['default_cycle'];
        }
        $status = Str::clean((string) ($input['status'] ?? 'active'));
        if (!isset(self::STATUSES[$status])) {
            $status = 'active';
        }

        $rawWidgets = $input['widgets'] ?? null;
        if (is_array($rawWidgets) && $rawWidgets !== []) {
            $widgets = array_values(array_intersect(array_map('strval', $rawWidgets), array_keys(self::WIDGETS)));
        } else {
            $widgets = $def['widgets'];
        }

        $metrics = [];
        foreach (['live_url', 'metric_1_label', 'metric_1_value', 'metric_2_label', 'metric_2_value', 'metric_3_label', 'metric_3_value', 'note'] as $mk) {
            $mv = Str::limit(Str::clean((string) ($input[$mk] ?? '')), 300);
            if ($mv !== '') {
                $metrics[$mk] = $mv;
            }
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $serviceId = isset($input['service_id']) && (int) $input['service_id'] > 0 ? (int) $input['service_id'] : 0;

        return $this->tenant->transaction(function (Tenant $t) use ($actor, $clientId, $serviceId, $type, $title, $scope, $cycle, $status, $widgets, $metrics, $now): int {
            $payload = [
                'service_type'  => $type,
                'title'         => $title,
                'scope_summary' => $scope !== '' ? $scope : null,
                'billing_cycle' => $cycle,
                'status'        => $status,
                'widgets_json'  => json_encode($widgets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'metrics_json'  => $metrics !== [] ? json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'updated_at'    => $now,
            ];

            if ($serviceId > 0) {
                $existing = $t->find('client_services', $serviceId);
                if ($existing === null || (int) $existing['client_id'] !== $clientId) {
                    throw new HttpException(404, 'השירות לא נמצא בתיק לקוח זה');
                }
                $t->update('client_services', $serviceId, $payload);
                Events::record($t, (int) $actor['id'], 'client_service', $serviceId, $clientId, 'client.service_updated', null, [
                    'service_type' => $type,
                    'title'        => $title,
                    'status'       => $status,
                ]);
                return $serviceId;
            }

            $payload['client_id'] = $clientId;
            $payload['created_at'] = $now;
            $id = $t->insert('client_services', $payload);
            Events::record($t, (int) $actor['id'], 'client_service', $id, $clientId, 'client.service_added', null, [
                'service_type' => $type,
                'title'        => $title,
                'status'       => $status,
            ]);
            return $id;
        });
    }

    /** @param array<string, mixed> $actor */
    public function delete(array $actor, int $clientId, int $serviceId): void
    {
        $this->perms->authorize($actor, 'clients.update');
        $existing = $this->tenant->find('client_services', $serviceId);
        if ($existing === null || (int) $existing['client_id'] !== $clientId) {
            throw new HttpException(404, 'השירות לא נמצא');
        }
        $this->tenant->transaction(function (Tenant $t) use ($actor, $clientId, $serviceId, $existing): void {
            $t->deleteWhere('client_services', ['id' => $serviceId, 'client_id' => $clientId]);
            Events::record($t, (int) $actor['id'], 'client_service', $serviceId, $clientId, 'client.service_removed', [
                'title' => $existing['title'] ?? '',
            ], null);
        });
    }
}
