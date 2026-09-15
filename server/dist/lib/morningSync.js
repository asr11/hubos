// @ts-nocheck
import { env } from '../config/env.js';
import { db } from './db.js';
import { MorningClient } from './morningClient.js';
import { loadMorningCredentials, writeSyncState, } from './morningCredentials.js';
import { INCOME_DOC_TYPES, applyPartialPaidToMappedIncome, mapMorningDocument, sumLinkedPaymentDocuments, } from './documentMapper.js';
import { mapMorningClient } from './clientMapper.js';
import { mapMorningRecurringPayment, mapMorningRetainer, } from './morningRecurringMapper.js';
const INCOMES_COLLECTION = 'financeIncomes';
const CLIENTS_COLLECTION = 'clients';
const GI_DOC_PREFIX = 'gi_';
function todayYmd() {
    return new Date().toISOString().slice(0, 10);
}
function normalizeTaxId(raw) {
    return raw.replace(/\D/g, '');
}
function normalizeName(raw) {
    return raw.trim().toLowerCase().replace(/\s+/g, ' ');
}
/** טוען את כל הלקוחות ובונה אינדקס להתאמה לפי מזהה GI / ח.פ / אימייל / שם */
async function buildClientIndex() {
    const stmt = db.prepare('SELECT id, data FROM generic_documents WHERE collection = ?');
    const rows = stmt.all(CLIENTS_COLLECTION);
    const byGreenInvoiceId = new Map();
    const byTaxId = new Map();
    const byEmail = new Map();
    const byName = new Map();
    for (const row of rows) {
        const docId = row.id;
        const d = JSON.parse(row.data);
        const giId = String(d.greenInvoiceId ?? '').trim();
        if (giId)
            byGreenInvoiceId.set(giId, docId);
        const taxId = normalizeTaxId(String(d.taxId ?? d.companyId ?? d.businessId ?? d.vatId ?? ''));
        if (taxId)
            byTaxId.set(taxId, docId);
        const email = String(d.email ?? '').trim().toLowerCase();
        if (email)
            byEmail.set(email, docId);
        const name = normalizeName(String(d.name ?? d.companyName ?? ''));
        if (name)
            byName.set(name, docId);
    }
    return { byGreenInvoiceId, byTaxId, byEmail, byName };
}
function matchClient(index, m) {
    const giId = m.clientGreenInvoiceId.trim();
    if (giId && index.byGreenInvoiceId.has(giId)) {
        return index.byGreenInvoiceId.get(giId);
    }
    const taxId = normalizeTaxId(m.clientTaxId);
    if (taxId && index.byTaxId.has(taxId))
        return index.byTaxId.get(taxId);
    const email = m.clientEmail.trim().toLowerCase();
    if (email && index.byEmail.has(email))
        return index.byEmail.get(email);
    const name = normalizeName(m.clientName);
    if (name && index.byName.has(name))
        return index.byName.get(name);
    return '';
}
/**
 * משיכת לקוחות מחשבונית ירוקה בלבד — בלי כתיבה ל-Firestore.
 * השמירה מתבצעת בדפדפן עם המשתמש המחובר (לא Admin SDK).
 */
export async function fetchMorningClients() {
    const creds = await loadMorningCredentials();
    if (!creds)
        throw new Error('Morning credentials not configured');
    const client = new MorningClient(creds);
    const [activeRaw, inactiveRaw] = await Promise.all([
        client.searchAllClients(true),
        client.searchAllClients(false),
    ]);
    const byId = new Map();
    for (const raw of [...activeRaw, ...inactiveRaw]) {
        const m = mapMorningClient(raw);
        if (m)
            byId.set(m.greenInvoiceId, m);
    }
    return [...byId.values()];
}
/**
 * משיכת לקוחות + הכנסות מחשבונית ירוקה — בלי כתיבה ל-Firestore.
 */
export async function fetchMorningSyncPayload(options) {
    const creds = await loadMorningCredentials();
    if (!creds)
        throw new Error('Morning credentials not configured');
    const api = new MorningClient(creds);
    const [activeRaw, inactiveRaw] = await Promise.all([
        api.searchAllClients(true),
        api.searchAllClients(false),
    ]);
    const byId = new Map();
    for (const raw of [...activeRaw, ...inactiveRaw]) {
        const m = mapMorningClient(raw);
        if (m)
            byId.set(m.greenInvoiceId, m);
    }
    const clients = [...byId.values()];
    const fromDate = (options?.fromDate ?? env.sync.fromDate).slice(0, 10);
    const toDate = todayYmd();
    const docs = await api.searchAllDocuments({
        fromDate,
        toDate,
        types: INCOME_DOC_TYPES,
        pageSize: 100,
    });
    const incomes = [];
    for (const raw of docs) {
        const m = mapMorningDocument(raw);
        if (m)
            incomes.push(m);
    }
    // תשלומים חלקיים בחשבונות עסקה פתוחים — ממסמכים מקושרים ב-GI
    await enrichOpenTransactionPartials(api, incomes);
    let recurringPayments = [];
    let retainers = [];
    let recurringFetchError;
    let retainersFetchError;
    try {
        const rawRecurring = await api.searchAllRecurringPayments();
        recurringPayments = rawRecurring
            .map(mapMorningRecurringPayment)
            .filter((x) => x != null);
        console.info(`[morning] recurring payments: fetched=${rawRecurring.length} active=${recurringPayments.length}`);
    }
    catch (e) {
        recurringFetchError =
            e instanceof Error ? e.message : 'recurring payments fetch failed';
        console.warn('[morning] recurring payments fetch failed:', e);
    }
    try {
        const rawRetainers = await api.searchAllRetainers();
        retainers = rawRetainers
            .map(mapMorningRetainer)
            .filter((x) => x != null);
        console.info(`[morning] retainers: fetched=${rawRetainers.length} active=${retainers.length}`);
    }
    catch (e) {
        retainersFetchError =
            e instanceof Error ? e.message : 'retainers fetch failed';
        console.warn('[morning] retainers fetch failed:', e);
    }
    return {
        ok: true,
        mode: 'client_write',
        fromDate,
        toDate,
        clients,
        incomes,
        recurringPayments,
        retainers,
        recurringFetchError,
        retainersFetchError,
    };
}
/**
 * לחשבונות עסקה שעדיין פתוחים ב-GI: מושך מסמכים מקושרים (קבלה / מס+קבלה)
 * ומעדכן paidAmount + status=partial. לא משנה הכנסות/סגירה.
 */
async function enrichOpenTransactionPartials(api, incomes) {
    for (let i = 0; i < incomes.length; i += 1) {
        const m = incomes[i];
        if (m.cancelled)
            continue;
        if (m.documentKind !== 'transaction_account')
            continue;
        if (m.status === 'paid')
            continue;
        const giStatus = m.greenInvoiceStatus;
        if (giStatus === 1 || giStatus === 2 || giStatus === 3 || giStatus === 4) {
            continue;
        }
        const linked = await api.getLinkedDocuments(m.greenInvoiceId);
        const linkedPaid = sumLinkedPaymentDocuments(linked);
        if (linkedPaid <= 0)
            continue;
        incomes[i] = applyPartialPaidToMappedIncome(m, linkedPaid);
    }
}
/**
 * סנכרון כל הלקוחות מחשבונית ירוקה ל-Firestore.
 * - מושך פעילים + לא פעילים.
 * - מתאים ללקוח קיים לפי מזהה GI / ח.פ / אימייל / שם (לא יוצר כפילות).
 * - על לקוח קיים: מעדכן רק שדות מ-GI שאינם ריקים + סטטוס, ושומר שדות Hub (סוכן, עובדים, מחלקה).
 * - לקוח חדש: נוצר עם מזהה gi_<id>.
 */
export async function syncClients() {
    const startedAt = Date.now();
    const creds = await loadMorningCredentials();
    if (!creds)
        throw new Error('Morning credentials not configured');
    const client = new MorningClient(creds);
    const [activeRaw, inactiveRaw] = await Promise.all([
        client.searchAllClients(true),
        client.searchAllClients(false),
    ]);
    // מיזוג ייחודי לפי מזהה GI (אם פילוח active לא נתמך והוחזר הכל פעמיים)
    const byId = new Map();
    for (const raw of [...activeRaw, ...inactiveRaw]) {
        const m = mapMorningClient(raw);
        if (m)
            byId.set(m.greenInvoiceId, m);
    }
    const mapped = [...byId.values()];
    const index = await buildClientIndex();
    const claimed = new Set();
    let created = 0;
    let updated = 0;
    let active = 0;
    let inactive = 0;
    const list = mapped;
    for (let i = 0; i < list.length; i += 400) {
        const slice = list.slice(i, i + 400);
        const insertStmt = db.prepare(`
      INSERT INTO generic_documents (id, collection, data) 
      VALUES (?, ?, ?)
      ON CONFLICT(id) DO UPDATE SET data = excluded.data
    `);
        const getStmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?');
        db.transaction(() => {
            for (const m of slice) {
                if (m.active)
                    active += 1;
                else
                    inactive += 1;
                // איתור לקוח קיים: מזהה GI → ח.פ → אימייל → שם
                const taxId = normalizeTaxId(m.taxId);
                const email = m.email.trim().toLowerCase();
                const name = normalizeName(m.name);
                let existingId = index.byGreenInvoiceId.get(m.greenInvoiceId) ??
                    (taxId ? index.byTaxId.get(taxId) : undefined) ??
                    (email ? index.byEmail.get(email) : undefined) ??
                    (name ? index.byName.get(name) : undefined);
                // לא נדרוס לקוח קיים שכבר שויך ל-GI אחר בריצה זו
                if (existingId && claimed.has(existingId))
                    existingId = undefined;
                if (existingId) {
                    claimed.add(existingId);
                    const patch = {
                        greenInvoiceId: m.greenInvoiceId,
                        status: m.active ? 'active' : 'inactive',
                    };
                    if (m.name)
                        patch.name = m.name;
                    if (m.email)
                        patch.email = m.email;
                    if (m.phone)
                        patch.phone = m.phone;
                    if (m.contact)
                        patch.contact = m.contact;
                    if (m.taxId)
                        patch.taxId = m.taxId;
                    if (m.addressLine)
                        patch.addressLine = m.addressLine;
                    if (m.city)
                        patch.city = m.city;
                    const rowData = getStmt.get(CLIENTS_COLLECTION, existingId);
                    const existingData = rowData ? JSON.parse(rowData.data) : {};
                    const mergedData = { ...existingData, ...patch };
                    insertStmt.run(existingId, CLIENTS_COLLECTION, JSON.stringify(mergedData));
                    updated += 1;
                }
                else {
                    const newId = GI_DOC_PREFIX + m.greenInvoiceId;
                    claimed.add(newId);
                    const row = {
                        name: m.name,
                        contact: m.contact,
                        phone: m.phone,
                        email: m.email,
                        status: m.active ? 'active' : 'inactive',
                        greenInvoiceId: m.greenInvoiceId,
                        source: 'green_invoice',
                        createdAt: new Date().toISOString(),
                    };
                    if (m.taxId)
                        row.taxId = m.taxId;
                    if (m.addressLine)
                        row.addressLine = m.addressLine;
                    if (m.city)
                        row.city = m.city;
                    const rowData2 = getStmt.get(CLIENTS_COLLECTION, newId);
                    const existingData2 = rowData2 ? JSON.parse(rowData2.data) : {};
                    const mergedData2 = { ...existingData2, ...row };
                    insertStmt.run(newId, CLIENTS_COLLECTION, JSON.stringify(mergedData2));
                    created += 1;
                }
            }
        })();
    }
    return {
        ok: true,
        fetched: mapped.length,
        created,
        updated,
        active,
        inactive,
        durationMs: Date.now() - startedAt,
    };
}
/**
 * משיכת כל מסמכי ההכנסה מ-Morning והעלאתם ל-Firestore.
 * idempotent — מזהה המסמך ב-Firestore הוא gi_<greenInvoiceId>, כך שריצה חוזרת מעדכנת ולא משכפלת.
 * אם syncClientsFirst=true (ברירת מחדל) — מסנכרן קודם את כל הלקוחות, כדי שההכנסות יקושרו במלואן.
 */
export async function runMorningSync(options) {
    const startedAt = Date.now();
    const db = getAdminDb();
    if (!db)
        throw new Error('Firebase Admin not configured');
    const creds = await loadMorningCredentials();
    if (!creds)
        throw new Error('Morning credentials not configured');
    // סנכרון לקוחות תחילה — חשבונית ירוקה מקור האמת גם ללקוחות
    let clientsResult;
    if (options?.syncClientsFirst !== false) {
        clientsResult = await syncClients();
    }
    const fromDate = (options?.fromDate ?? env.sync.fromDate).slice(0, 10);
    const toDate = todayYmd();
    const client = new MorningClient(creds);
    const docs = await client.searchAllDocuments({
        fromDate,
        toDate,
        types: INCOME_DOC_TYPES,
        pageSize: 100,
    });
    const clientIndex = await buildClientIndex();
    let imported = 0;
    let updated = 0;
    let removed = 0;
    let unmatchedClients = 0;
    // עיבוד באצוות של 400 (מגבלת writeBatch = 500)
    const mapped = [];
    for (const raw of docs) {
        const m = mapMorningDocument(raw);
        if (m)
            mapped.push(m);
    }
    await enrichOpenTransactionPartials(client, mapped);
    for (let i = 0; i < mapped.length; i += 400) {
        const slice = mapped.slice(i, i + 400);
        const insertStmt = db.prepare(`
      INSERT INTO generic_documents (id, collection, data) 
      VALUES (?, ?, ?)
      ON CONFLICT(id) DO UPDATE SET data = excluded.data
    `);
        const getStmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?');
        db.transaction(() => {
            for (const m of slice) {
                const newId = GI_DOC_PREFIX + m.greenInvoiceId;
                if (m.cancelled) {
                    deleteStmt.run(INCOMES_COLLECTION, newId);
                    removed += 1;
                    continue;
                }
                const matchedClientId = matchClient(clientIndex, m);
                if (!matchedClientId)
                    unmatchedClients += 1;
                const row = {
                    clientId: matchedClientId,
                    amount: m.amount,
                    amountIncludesVat: m.amountIncludesVat,
                    documentKind: m.documentKind,
                    invoiceNumber: m.invoiceNumber,
                    invoiceDate: m.invoiceDate,
                    status: m.status,
                    source: m.source,
                    greenInvoiceId: m.greenInvoiceId,
                    greenInvoiceType: m.greenInvoiceType,
                    greenInvoiceNumber: m.greenInvoiceNumber,
                    greenInvoiceClientName: m.clientName,
                    ...(m.greenInvoiceStatus !== undefined
                        ? { greenInvoiceStatus: m.greenInvoiceStatus }
                        : {}),
                    syncedAt: new Date().toISOString(),
                    createdAt: new Date().toISOString(),
                };
                if (m.vatAmount !== undefined)
                    row.vatAmount = m.vatAmount;
                if (m.description)
                    row.description = m.description;
                if (m.status === 'paid') {
                    row.paidAmount = m.amount;
                    row.paidDate = m.invoiceDate;
                    row.paymentMethod = 'bank_transfer';
                }
                else if (m.status === 'partial' &&
                    typeof m.paidAmount === 'number' &&
                    m.paidAmount > 0) {
                    row.paidAmount = m.paidAmount;
                }
                else {
                    // מונע יתרה שגויה מ-paidAmount ישן אחרי סנכרון מחדש
                    row.paidAmount = null;
                }
                // merge: שומר createdAt מקורי אם כבר קיים
                const rowData2 = getStmt.get(CLIENTS_COLLECTION, newId);
                const existingData2 = rowData2 ? JSON.parse(rowData2.data) : {};
                const mergedData2 = { ...existingData2, ...row };
                insertStmt.run(newId, CLIENTS_COLLECTION, JSON.stringify(mergedData2));
                imported += 1;
            }
        })();
    }
    const result = {
        ok: true,
        fromDate,
        toDate,
        fetched: docs.length,
        imported,
        updated,
        removed,
        unmatchedClients,
        durationMs: Date.now() - startedAt,
        ...(clientsResult ? { clients: clientsResult } : {}),
    };
    await writeSyncState({
        lastSyncAt: new Date().toISOString(),
        lastFromDate: fromDate,
        lastToDate: toDate,
        lastFetched: docs.length,
        lastImported: imported,
        lastRemoved: removed,
        lastUnmatchedClients: unmatchedClients,
        ...(clientsResult
            ? {
                lastClientsFetched: clientsResult.fetched,
                lastClientsCreated: clientsResult.created,
                lastClientsUpdated: clientsResult.updated,
                lastClientsActive: clientsResult.active,
                lastClientsInactive: clientsResult.inactive,
            }
            : {}),
    });
    return result;
}
/** מחיקת כל ההכנסות מהמערכת (איפוס לפני סנכרון מלא מ-Morning) */
export async function resetAllIncomes() {
    const getStmt = db.prepare('SELECT id FROM generic_documents WHERE collection = ?');
    const ids = getStmt.all(INCOMES_COLLECTION);
    const delStmt = db.prepare('DELETE FROM generic_documents WHERE collection = ?');
    delStmt.run(INCOMES_COLLECTION);
    return ids.length;
}
//# sourceMappingURL=morningSync.js.map