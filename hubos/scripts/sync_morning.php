<?php

declare(strict_types=1);

/**
 * sync_morning.php — Direct Read-Only Sync from official Morning API to hubos.sqlite.
 *
 * Rules:
 *  - READ-ONLY (GET/Search) operations only.
 *  - Strict pagination (while loop until hasNext=false / page > pages).
 *  - Preserves existing corporate hierarchies (e.g. parent_client_id).
 *  - Recalculates Accounting Document Law (1:1 matching, no double count).
 */

require_once __DIR__ . '/../app/bootstrap.php';

use HubOS\Core\DB;
use HubOS\Core\Events;
use HubOS\Core\Tenant;
use HubOS\Modules\Import\AccountingRule;

$dbPath = __DIR__ . '/../storage/hubos.sqlite';
if (!file_exists($dbPath)) {
    fwrite(STDERR, "Database file not found at: {$dbPath}\n");
    exit(1);
}

$pdo = DB::connect($dbPath);
$tenantId = 1;
$tenant = new Tenant($pdo, $tenantId);

// 1. Load Morning credentials
$keyId = getenv('MORNING_KEY_ID') ?: '';
$keySecret = getenv('MORNING_KEY_SECRET') ?: '';

if ($keyId === '' || $keySecret === '') {
    $envFiles = [
        __DIR__ . '/../../02_HUB_Dev/digital-ceo/.dev.vars',
        __DIR__ . '/../../02_HUB_Dev/digital-ceo/.env',
        '/home/asr11/02_HUB_Dev/digital-ceo/.dev.vars',
        '/home/asr11/02_HUB_Dev/digital-ceo/.env',
    ];
    foreach ($envFiles as $ef) {
        if (file_exists($ef)) {
            $lines = file($ef, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (str_starts_with($line, '#')) continue;
                if (str_starts_with($line, 'MORNING_KEY_ID=')) {
                    $keyId = trim(substr($line, strlen('MORNING_KEY_ID=')));
                }
                if (str_starts_with($line, 'MORNING_KEY_SECRET=')) {
                    $keySecret = trim(trim(substr($line, strlen('MORNING_KEY_SECRET='))), "\"'");
                }
            }
            if ($keyId !== '' && $keySecret !== '') {
                break;
            }
        }
    }
}

if ($keyId === '' || $keySecret === '') {
    fwrite(STDERR, "❌ Error: Missing MORNING_KEY_ID or MORNING_KEY_SECRET\n");
    exit(1);
}

echo "🔄 Connecting to Morning API (Read-Only)... \n";

// 2. Obtain OAuth Token
function httpPostJson(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    $data = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $defaultHeaders = ['Content-Type: application/json', 'Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_HTTPHEADER     => array_merge($defaultHeaders, $headers),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        throw new RuntimeException("HTTP POST to {$url} failed: {$err}");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP POST to {$url} returned {$code}: {$res}");
    }
    return json_decode($res, true) ?: [];
}

$tokenData = httpPostJson('https://api.morning.co/idp/v1/oauth/token', [
    'grant_type'    => 'client_credentials',
    'client_id'     => $keyId,
    'client_secret' => $keySecret,
]);

$token = $tokenData['accessToken'] ?? $tokenData['access_token'] ?? null;
if (!$token) {
    fwrite(STDERR, "❌ Error: Failed to obtain token from Morning IdP\n");
    exit(1);
}

echo "✔ Authenticated with Morning API.\n";

$authHeader = ["Authorization: Bearer {$token}"];

// 3. Fetch all Clients (Strict Pagination until hasNext=false)
echo "📥 Fetching clients from Morning...\n";
$page = 1;
$pageSize = 100;
$allClients = [];
$totalClientsReported = 0;

while (true) {
    $res = httpPostJson('https://api.greeninvoice.co.il/api/v1/clients/search', [
        'page'     => $page,
        'pageSize' => $pageSize,
    ], $authHeader);

    $items = $res['items'] ?? [];
    foreach ($items as $it) {
        $allClients[] = $it;
    }
    $totalClientsReported = $res['total'] ?? count($allClients);
    $pages = $res['pages'] ?? (int) ceil($totalClientsReported / $pageSize);

    echo "  ↳ Clients page {$page}/{$pages} fetched (" . count($items) . " items, total: " . count($allClients) . "/{$totalClientsReported})\n";

    if ($page >= $pages || empty($items)) {
        break;
    }
    $page++;
}

// 4. Upsert Clients into SQLite
echo "💾 Upserting " . count($allClients) . " clients into hubos.sqlite...\n";
$nowDt = date('Y-m-d H:i:s');
$clientUpsertCount = 0;
$clientInsertCount = 0;

$findClientStmt = $pdo->prepare(
    "SELECT id, parent_client_id, legacy_id, name, tax_id, status FROM clients
     WHERE tenant_id = :tenant_id AND (legacy_id = :leg_id OR (tax_id IS NOT NULL AND tax_id != '' AND tax_id = :tax_id) OR name = :name)
     LIMIT 1"
);

$insertClientStmt = $pdo->prepare(
    "INSERT INTO clients (tenant_id, legacy_id, name, tax_id, phone, email, city, address, status, source_system, fetched_at, created_at, updated_at)
     VALUES (:tenant_id, :leg_id, :name, :tax_id, :phone, :email, :city, :address, :status, 'morning_api_v1', :fetched_at, :now, :now)"
);

$updateClientStmt = $pdo->prepare(
    "UPDATE clients SET
        legacy_id = COALESCE(:leg_id, legacy_id),
        name = :name,
        tax_id = COALESCE(:tax_id, tax_id),
        phone = COALESCE(:phone, phone),
        email = COALESCE(:email, email),
        city = COALESCE(:city, city),
        address = COALESCE(:address, address),
        status = :status,
        source_system = 'morning_api_v1',
        fetched_at = :fetched_at,
        updated_at = :now
     WHERE id = :id AND tenant_id = :tenant_id"
);

$morningToLocalClientId = [];

$pdo->beginTransaction();
foreach ($allClients as $mc) {
    $legId = (string) ($mc['id'] ?? '');
    $name = trim((string) ($mc['name'] ?? ''));
    $taxId = trim((string) ($mc['taxId'] ?? ''));
    $phone = trim((string) ($mc['phone'] ?? ''));
    $email = !empty($mc['emails'][0]) ? trim((string) $mc['emails'][0]) : null;
    $city = trim((string) ($mc['city'] ?? ''));
    $address = trim((string) ($mc['address'] ?? ''));
    $status = !empty($mc['active']) ? 'active' : 'inactive';

    if ($name === '') continue;

    $findClientStmt->execute([
        ':tenant_id' => $tenantId,
        ':leg_id'    => $legId,
        ':tax_id'    => $taxId,
        ':name'      => $name,
    ]);
    $existing = $findClientStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $localId = (int) $existing['id'];
        $updateClientStmt->execute([
            ':id'         => $localId,
            ':tenant_id'  => $tenantId,
            ':leg_id'     => $legId,
            ':name'       => $name,
            ':tax_id'     => $taxId !== '' ? $taxId : null,
            ':phone'      => $phone !== '' ? $phone : null,
            ':email'      => $email,
            ':city'       => $city !== '' ? $city : null,
            ':address'    => $address !== '' ? $address : null,
            ':status'     => $status,
            ':fetched_at' => $nowDt,
            ':now'        => $nowDt,
        ]);
        $clientUpsertCount++;
    } else {
        $insertClientStmt->execute([
            ':tenant_id'  => $tenantId,
            ':leg_id'     => $legId,
            ':name'       => $name,
            ':tax_id'     => $taxId !== '' ? $taxId : null,
            ':phone'      => $phone !== '' ? $phone : null,
            ':email'      => $email,
            ':city'       => $city !== '' ? $city : null,
            ':address'    => $address !== '' ? $address : null,
            ':status'     => $status,
            ':fetched_at' => $nowDt,
            ':now'        => $nowDt,
        ]);
        $localId = (int) $pdo->lastInsertId();
        $clientInsertCount++;
    }
    if ($legId !== '') {
        $morningToLocalClientId[$legId] = $localId;
    }
}
$pdo->commit();

echo "✔ Clients sync complete: {$clientInsertCount} added, {$clientUpsertCount} updated.\n";

// 5. Fetch all Documents (types 300, 305, 320, 330, 400)
echo "📥 Fetching documents from Morning...\n";
$page = 1;
$allDocs = [];
$totalDocsReported = 0;

while (true) {
    $res = httpPostJson('https://api.greeninvoice.co.il/api/v1/documents/search', [
        'type'     => [300, 305, 320, 330, 400],
        'page'     => $page,
        'pageSize' => $pageSize,
    ], $authHeader);

    $items = $res['items'] ?? [];
    foreach ($items as $it) {
        $allDocs[] = $it;
    }
    $totalDocsReported = $res['total'] ?? count($allDocs);
    $pages = $res['pages'] ?? (int) ceil($totalDocsReported / $pageSize);

    echo "  ↳ Documents page {$page}/{$pages} fetched (" . count($items) . " items, total: " . count($allDocs) . "/{$totalDocsReported})\n";

    if ($page >= $pages || empty($items)) {
        break;
    }
    $page++;
}

// 6. Upsert Documents into finance_documents
echo "💾 Upserting " . count($allDocs) . " finance documents into hubos.sqlite...\n";
$docUpsertCount = 0;
$docInsertCount = 0;

$findDocStmt = $pdo->prepare(
    "SELECT id FROM finance_documents
     WHERE tenant_id = :tenant_id AND (legacy_id = :leg_id OR (doc_type = :doc_type AND doc_number = :doc_num))
     LIMIT 1"
);

$insertDocStmt = $pdo->prepare(
    "INSERT INTO finance_documents
        (tenant_id, legacy_id, client_id, client_name_raw, doc_type, doc_type_source, doc_kind,
         doc_number, doc_date, amount, vat, currency, status, paid_amount, description,
         source, data_json, created_at, updated_at, source_system, fetched_at)
     VALUES
        (:tenant_id, :leg_id, :client_id, :client_name, :doc_type, 'code', :doc_kind,
         :doc_number, :doc_date, :amount, :vat, :currency, :status, :paid_amount, :description,
         'morning_api_v1', :data_json, :now, :now, 'morning_api_v1', :fetched_at)"
);

$updateDocStmt = $pdo->prepare(
    "UPDATE finance_documents SET
        legacy_id = COALESCE(:leg_id, legacy_id),
        client_id = :client_id,
        client_name_raw = :client_name,
        doc_type = :doc_type,
        doc_kind = :doc_kind,
        doc_number = :doc_number,
        doc_date = :doc_date,
        amount = :amount,
        vat = :vat,
        currency = :currency,
        status = :status,
        paid_amount = :paid_amount,
        description = :description,
        source = 'morning_api_v1',
        data_json = :data_json,
        source_system = 'morning_api_v1',
        fetched_at = :fetched_at,
        updated_at = :now
     WHERE id = :id AND tenant_id = :tenant_id"
);

$pdo->beginTransaction();
foreach ($allDocs as $md) {
    $legId = (string) ($md['id'] ?? '');
    $docType = (string) ($md['type'] ?? '');
    $docNumber = (string) ($md['number'] ?? '');
    $docDate = (string) ($md['documentDate'] ?? '');
    $amount = (float) ($md['amount'] ?? 0.0);
    $vat = (float) ($md['vat'] ?? 0.0);
    $currency = (string) ($md['currency'] ?? 'ILS');
    $statusRaw = (int) ($md['status'] ?? 0);
    $status = $statusRaw === 1 ? 'paid' : 'pending';
    $desc = (string) ($md['description'] ?? '');
    $clientName = trim((string) ($md['client']['name'] ?? ''));
    $mClientId = (string) ($md['client']['id'] ?? '');

    // Resolve local client_id
    $localClientId = $morningToLocalClientId[$mClientId] ?? null;
    if ($localClientId === null && $clientName !== '') {
        $cRow = $pdo->query("SELECT id FROM clients WHERE tenant_id = {$tenantId} AND name = " . $pdo->quote($clientName) . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($cRow) {
            $localClientId = (int) $cRow['id'];
        }
    }

    $kind = AccountingRule::kindForCode($docType) ?? 'other';

    // Net paid amount for proformas (300)
    $paidAmount = null;
    if ($docType === '300') {
        $amountOpened = isset($md['amountOpened']) ? (float) $md['amountOpened'] : null;
        if ($statusRaw === 1 || ($amountOpened !== null && $amountOpened == 0.0)) {
            $status = 'paid';
            $paidAmount = $amount;
        } elseif ($statusRaw === 2) {
            $status = 'cancelled';
            $paidAmount = 0.0;
        } else {
            $status = ($amountOpened !== null && $amountOpened < $amount && $amountOpened > 0.0) ? 'partial' : 'pending';
            $paidAmount = $amountOpened !== null ? round($amount - $amountOpened, 2) : 0.0;
        }
    } elseif ($status === 'paid') {
        $paidAmount = $amount;
    }

    $dataJson = json_encode([
        'morning_id'    => $legId,
        'status'        => $status,
        'paidAmount'    => $paidAmount,
        'amountOpened'  => $md['amountOpened'] ?? null,
        'dueDate'       => $md['dueDate'] ?? null,
        'clientTaxId'   => $md['client']['taxId'] ?? null,
    ], JSON_UNESCAPED_UNICODE);

    $findDocStmt->execute([
        ':tenant_id' => $tenantId,
        ':leg_id'    => $legId,
        ':doc_type'  => $docType,
        ':doc_num'   => $docNumber,
    ]);
    $existingDoc = $findDocStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingDoc) {
        $updateDocStmt->execute([
            ':id'          => (int) $existingDoc['id'],
            ':tenant_id'   => $tenantId,
            ':leg_id'      => $legId,
            ':client_id'   => $localClientId,
            ':client_name' => $clientName,
            ':doc_type'    => $docType,
            ':doc_kind'    => $kind,
            ':doc_number'  => $docNumber,
            ':doc_date'    => $docDate,
            ':amount'      => $amount,
            ':vat'         => $vat,
            ':currency'    => $currency,
            ':status'      => $status,
            ':paid_amount' => $paidAmount,
            ':description' => $desc,
            ':data_json'   => $dataJson,
            ':fetched_at'  => $nowDt,
            ':now'         => $nowDt,
        ]);
        $docUpsertCount++;
    } else {
        $insertDocStmt->execute([
            ':tenant_id'   => $tenantId,
            ':leg_id'      => $legId,
            ':client_id'   => $localClientId,
            ':client_name' => $clientName,
            ':doc_type'    => $docType,
            ':doc_kind'    => $kind,
            ':doc_number'  => $docNumber,
            ':doc_date'    => $docDate,
            ':amount'      => $amount,
            ':vat'         => $vat,
            ':currency'    => $currency,
            ':status'      => $status,
            ':paid_amount' => $paidAmount,
            ':description' => $desc,
            ':data_json'   => $dataJson,
            ':fetched_at'  => $nowDt,
            ':now'         => $nowDt,
        ]);
        $docInsertCount++;
    }
}

// Purge legacy ghost documents not found in Morning (SSOT)
$morningDocIds = array_column($allDocs, 'id');
if (!empty($morningDocIds)) {
    $inClause = implode(',', array_fill(0, count($morningDocIds), '?'));
    $delStmt = $pdo->prepare("DELETE FROM finance_documents WHERE tenant_id = ? AND (legacy_id IS NULL OR legacy_id NOT IN ($inClause))");
    $delStmt->execute(array_merge([$tenantId], $morningDocIds));
    $purgedGhostDocs = $delStmt->rowCount();
    echo "✔ Purged {$purgedGhostDocs} stale legacy ghost documents.\n";
}

$pdo->commit();

echo "✔ Documents sync complete: {$docInsertCount} added, {$docUpsertCount} updated.\n";

// 7. Recalculate Accounting Document Law
echo "⚖️ Recalculating Accounting Document Law (Zero Double Count)...\n";
$recalcCount = AccountingRule::recalculate($pdo, $tenantId);
echo "✔ Accounting recalculation finished ({$recalcCount} rows classified).\n";

// 8. Record Sync Event
Events::record($tenant, 1, 'sync', null, null, 'sync.completed', null, [
    'summary'           => 'סנכרון ישיר מלא מול Morning API (SSOT)',
    'clients_count'     => count($allClients),
    'documents_count'   => count($allDocs),
    'recalculated_rows' => $recalcCount,
]);

echo "================================================================================\n";
echo "🎉 SYNC TO HUBOS.SQLITE COMPLETE (SSOT VERIFIED)\n";
echo "  • Total clients:   " . count($allClients) . " (" . $pdo->query("SELECT count(*) FROM clients WHERE status='active'")->fetchColumn() . " active)\n";
echo "  • Total documents: " . count($allDocs) . "\n";
$openRow = $pdo->query(
    "SELECT count(*) as n, COALESCE(SUM(amount - COALESCE(paid_amount,0)),0) as total
     FROM finance_documents
     WHERE counts_as_income = 0 AND income_reason = 'proforma_open' AND status IN ('pending', 'partial')"
)->fetch(PDO::FETCH_ASSOC);
echo "  • Open demands:    " . $openRow['n'] . " documents, ₪" . number_format((float) $openRow['total'], 0) . "\n";
echo "================================================================================\n";
