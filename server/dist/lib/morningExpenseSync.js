// @ts-nocheck
import { db } from './db.js';
import { computeExpensePairs, isBankExpense, isGiOnlyRecord, requiresGiDocument, } from './expenseReconcileCore.js';
const EXPENSES_COLLECTION = 'financeExpenses';
/** Firestore Admin SDK rejects `undefined` field values */
function stripUndefined(obj) {
    const out = { ...obj };
    for (const key of Object.keys(out)) {
        if (out[key] === undefined)
            delete out[key];
    }
    return out;
}
function mapStoredExpenseDoc(d) {
    const x = d.data();
    return {
        id: d.id,
        amount: typeof x.amount === 'number' ? x.amount : 0,
        amountBeforeVat: typeof x.amountBeforeVat === 'number' ? x.amountBeforeVat : undefined,
        vatAmount: typeof x.vatAmount === 'number' ? x.vatAmount : undefined,
        type: typeof x.type === 'string' ? x.type : undefined,
        paidDate: typeof x.paidDate === 'string' && x.paidDate.trim()
            ? x.paidDate.trim().slice(0, 10)
            : undefined,
        billingMonth: typeof x.billingMonth === 'string' ? x.billingMonth.slice(0, 7) : '',
        bankRef: typeof x.bankRef === 'string' && x.bankRef.trim()
            ? x.bankRef.trim()
            : undefined,
        source: typeof x.source === 'string' ? x.source : undefined,
        greenInvoiceId: typeof x.greenInvoiceId === 'string' ? x.greenInvoiceId : undefined,
        greenInvoiceNumber: typeof x.greenInvoiceNumber === 'string'
            ? x.greenInvoiceNumber
            : undefined,
        supplierName: typeof x.supplierName === 'string' ? x.supplierName : undefined,
        greenInvoiceSupplierName: typeof x.greenInvoiceSupplierName === 'string'
            ? x.greenInvoiceSupplierName
            : undefined,
        greenInvoiceSupplierTaxId: typeof x.greenInvoiceSupplierTaxId === 'string'
            ? x.greenInvoiceSupplierTaxId
            : undefined,
        supplierTaxId: typeof x.supplierTaxId === 'string' ? x.supplierTaxId : undefined,
        name: typeof x.name === 'string' ? x.name : undefined,
        description: typeof x.description === 'string' ? x.description : undefined,
        supplierInvoiceReceived: x.supplierInvoiceReceived === true,
    };
}
/** טעינה בעמודים — מונעת RESOURCE_EXHAUSTED על אוסף גדול */
async function loadStoredExpenses() {
    const stmt = db.prepare('SELECT id, data FROM generic_documents WHERE collection = ?');
    const rows = stmt.all(EXPENSES_COLLECTION);
    return rows.map(r => {
        const doc = { id: r.id, data: () => JSON.parse(r.data) };
        return mapStoredExpenseDoc(doc);
    });
}
function asFinanceExpense(row) {
    return {
        ...row,
        type: row.type ?? 'supplier',
        recurring: false,
        createdAt: '',
    };
}
/** שומר התאמות ל-Firestore — בנק + GI */
async function persistExpenseReconciliation(expenses) {
    const pairs = computeExpensePairs(expenses.map(asFinanceExpense));
    const matchedBank = new Set(pairs.map((p) => p.bank.id));
    const matchedGi = new Set(pairs.map((p) => p.gi.id));
    const updates = [];
    for (const pair of pairs) {
        updates.push({
            id: pair.bank.id,
            patch: stripUndefined({
                matchedExpenseId: pair.gi.id,
                matchedBankExpenseId: pair.bank.id,
                reconciliationStatus: 'matched',
                supplierInvoiceReceived: true,
                ...(pair.gi.greenInvoiceId
                    ? { linkedGreenInvoiceId: pair.gi.greenInvoiceId }
                    : {}),
                ...(pair.gi.greenInvoiceNumber
                    ? { greenInvoiceNumber: pair.gi.greenInvoiceNumber }
                    : {}),
            }),
        });
        updates.push({
            id: pair.gi.id,
            patch: stripUndefined({
                matchedExpenseId: pair.bank.id,
                matchedBankExpenseId: pair.bank.id,
                reconciliationStatus: 'matched',
                ...(pair.bank.id ? { linkedBankExpenseId: pair.bank.id } : {}),
            }),
        });
    }
    for (const exp of expenses) {
        if (isBankExpense(asFinanceExpense(exp)) && !matchedBank.has(exp.id)) {
            const needsDoc = requiresGiDocument(exp);
            updates.push({
                id: exp.id,
                patch: needsDoc
                    ? {
                        reconciliationStatus: 'bank_missing_document',
                        supplierInvoiceReceived: false,
                        matchedExpenseId: null,
                        matchedBankExpenseId: null,
                        linkedGreenInvoiceId: null,
                        greenInvoiceNumber: null,
                    }
                    : {
                        reconciliationStatus: 'document_not_required',
                        supplierInvoiceReceived: false,
                        matchedExpenseId: null,
                        matchedBankExpenseId: null,
                        linkedGreenInvoiceId: null,
                        greenInvoiceNumber: null,
                    },
            });
        }
        else if (isGiOnlyRecord(exp) && !matchedGi.has(exp.id)) {
            updates.push({
                id: exp.id,
                patch: {
                    reconciliationStatus: 'gi_unpaid',
                    matchedExpenseId: null,
                    matchedBankExpenseId: null,
                    linkedBankExpenseId: null,
                },
            });
        }
    }
    const insertStmt = db.prepare(`
      INSERT INTO generic_documents (id, collection, data) 
      VALUES (?, ?, ?)
      ON CONFLICT(id) DO UPDATE SET data = excluded.data
    `);
    const getStmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?');
    db.transaction(() => {
        for (const { id, patch } of updates) {
            const rowData = getStmt.get(EXPENSES_COLLECTION, id);
            const existingData = rowData ? JSON.parse(rowData.data) : {};
            const mergedData = { ...existingData, ...patch };
            // remove null fields
            for (const k of Object.keys(mergedData)) {
                if (mergedData[k] === null && patch[k] === null)
                    delete mergedData[k];
            }
            insertStmt.run(id, EXPENSES_COLLECTION, JSON.stringify(mergedData));
        }
    })();
    let bankMissingDocument = 0;
    let giUnpaid = 0;
    for (const exp of expenses) {
        const fe = asFinanceExpense(exp);
        if (isBankExpense(fe) && !matchedBank.has(exp.id) && requiresGiDocument(exp)) {
            bankMissingDocument += 1;
        }
        if (isGiOnlyRecord(fe) && !matchedGi.has(exp.id))
            giUnpaid += 1;
    }
    return { matched: pairs.length, bankMissingDocument, giUnpaid };
}
export async function runExpenseReconcile() {
    const startedAt = Date.now();
    const expenses = await loadStoredExpenses();
    const result = await persistExpenseReconciliation(expenses);
    return { ok: true, ...result, durationMs: Date.now() - startedAt };
}
/*
 * משיכת ההוצאות מחשבונית ירוקה הוסרה במכוון.
 *
 * חשבונית ירוקה היא מקור האמת להכנסות בלבד. הוצאות נכנסות דרך הסוכן,
 * שמתייק את המסמך ושואל את השאלות החסרות, ורק אחרי אישור המשתמש הן
 * נספרות ברווח. שני מקורות מקבילים לאותה הוצאה יצרו כפילויות וסתירות.
 */
//# sourceMappingURL=morningExpenseSync.js.map