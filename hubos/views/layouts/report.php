<?php
/**
 * Dedicated RTL Print/PDF document layout.
 * Zero inline styles/scripts for strict CSP compliance.
 *
 * @var string $content
 * @var string $appName
 * @var string|null $title
 * @var array<string, mixed> $report
 */
$c = $report['client'] ?? [];
$cid = (int) ($c['id'] ?? 0);
$isInternalActor = !empty($report['is_internal_actor']);
$backHref = $isInternalActor ? '/clients/' . $cid : '/dashboard';
$backLabel = $isInternalActor ? '← חזרה לכרטיס הלקוח 360' : '← חזרה לפורטל הלקוח';
$selectedPeriod = (string) ($report['selected_period'] ?? 'all');
$selectedScope = (string) ($report['scope'] ?? 'client');
$months = $report['available_months'] ?? [];
$hiddenInternal = (int) ($report['hidden_internal_tasks'] ?? 0);
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'דוח סטטוס ושעות') ?> · <?= e($appName ?? 'OS HUB') ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;700;800&amp;display=swap">
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="/assets/app.css?v=12">
    <script src="/assets/timer.js?v=7" defer></script>
</head>
<body class="report-body">
<header class="report-toolbar no-print">
    <div class="report-toolbar-inner">
        <div class="report-toolbar-nav">
            <a class="btn btn-ghost btn-sm" id="report-back-link" href="<?= e($backHref) ?>"><?= e($backLabel) ?></a>
            <?php if ($isInternalActor): ?>
                <span class="report-scope-badge <?= $selectedScope === 'internal' ? 'is-internal' : 'is-client' ?>">
                    <?= $selectedScope === 'internal' ? '🔒 תצוגת עבודה פנימית' : '✔ גרסת לקוח נקייה (למסירה)' ?>
                </span>
            <?php endif; ?>
        </div>

        <search aria-label="סינון תקופת דוח" class="report-filter-search">
            <form method="get" action="/clients/<?= e($cid) ?>/report" class="report-filter-form" id="report-filter-form">
                <label for="report-period" class="report-filter-label">תקופת שעות:</label>
                <select id="report-period" name="period">
                    <option value="all" <?= $selectedPeriod === 'all' ? 'selected' : '' ?>>כל התקופות (מצטבר)</option>
                    <?php foreach ($months as $ym): ?>
                        <option value="<?= e($ym) ?>" <?= $selectedPeriod === $ym ? 'selected' : '' ?>>
                            <?= e(\HubOS\Modules\Clients\Client360Service::hebrewMonthLabel((string) $ym)) ?> (<?= e($ym) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($isInternalActor): ?>
                    <label for="report-scope" class="report-filter-label">תכולה:</label>
                    <select id="report-scope" name="scope">
                        <option value="client" <?= $selectedScope === 'client' ? 'selected' : '' ?>>גרסת לקוח נקייה (למסירה)</option>
                        <option value="internal" <?= $selectedScope === 'internal' ? 'selected' : '' ?>>תצוגת עבודה מלאה (פנימי)</option>
                    </select>
                <?php endif; ?>

                <button type="submit" class="btn btn-ghost btn-sm" id="report-filter-submit">עדכון תצוגה</button>
            </form>
        </search>

        <div class="report-toolbar-actions">
            <button type="button" class="btn btn-primary btn-sm" id="report-print-btn" data-print-report="1">🖨 הדפסה / שמירה כ-PDF</button>
        </div>
    </div>
    <?php if ($isInternalActor && $selectedScope === 'client' && $hiddenInternal > 0): ?>
        <p class="report-internal-hint">
            בגרסת הלקוח מוצגות משימות המסומנות כ-<strong>גלוי ללקוח</strong> בלבד (<?= e($hiddenInternal) ?> משימות עבודה פנימיות מוסתרות מהמסמך).
            להדפסת דוח פנימי מלא בחרו <strong>תצוגת עבודה מלאה (פנימי)</strong> ולחצו עדכון תצוגה.
        </p>
    <?php endif; ?>
</header>

<main class="report-main" id="report-main">
    <?= $content ?>
</main>

<footer class="report-doc-foot">
    <span>מסמך רשמי מופק מתוך מערכת <strong>OS HUB</strong> · האב מערכות מתקדמות בע״מ</span>
    <span dir="ltr"><?= e($report['generated_at_il'] ?? '') ?></span>
</footer>
</body>
</html>
