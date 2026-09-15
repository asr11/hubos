import { type MappedIncome } from './documentMapper.js';
import { type MappedClient } from './clientMapper.js';
import { type MappedGiRecurringPayment, type MappedGiRetainer } from './morningRecurringMapper.js';
export type ClientSyncResult = {
    ok: true;
    fetched: number;
    created: number;
    updated: number;
    active: number;
    inactive: number;
    durationMs: number;
};
export type MorningSyncPayload = {
    ok: true;
    mode: 'client_write';
    fromDate: string;
    toDate: string;
    clients: MappedClient[];
    incomes: MappedIncome[];
    recurringPayments: MappedGiRecurringPayment[];
    retainers: MappedGiRetainer[];
    recurringFetchError?: string;
    retainersFetchError?: string;
};
export type MorningClientsPayload = {
    ok: true;
    mode: 'client_write';
    clients: MappedClient[];
};
/**
 * משיכת לקוחות מחשבונית ירוקה בלבד — בלי כתיבה ל-Firestore.
 * השמירה מתבצעת בדפדפן עם המשתמש המחובר (לא Admin SDK).
 */
export declare function fetchMorningClients(): Promise<MappedClient[]>;
/**
 * משיכת לקוחות + הכנסות מחשבונית ירוקה — בלי כתיבה ל-Firestore.
 */
export declare function fetchMorningSyncPayload(options?: {
    fromDate?: string;
}): Promise<MorningSyncPayload>;
/**
 * סנכרון כל הלקוחות מחשבונית ירוקה ל-Firestore.
 * - מושך פעילים + לא פעילים.
 * - מתאים ללקוח קיים לפי מזהה GI / ח.פ / אימייל / שם (לא יוצר כפילות).
 * - על לקוח קיים: מעדכן רק שדות מ-GI שאינם ריקים + סטטוס, ושומר שדות Hub (סוכן, עובדים, מחלקה).
 * - לקוח חדש: נוצר עם מזהה gi_<id>.
 */
export declare function syncClients(): Promise<ClientSyncResult>;
export type SyncResult = {
    ok: true;
    fromDate: string;
    toDate: string;
    fetched: number;
    imported: number;
    updated: number;
    removed: number;
    unmatchedClients: number;
    durationMs: number;
    clients?: ClientSyncResult;
};
/**
 * משיכת כל מסמכי ההכנסה מ-Morning והעלאתם ל-Firestore.
 * idempotent — מזהה המסמך ב-Firestore הוא gi_<greenInvoiceId>, כך שריצה חוזרת מעדכנת ולא משכפלת.
 * אם syncClientsFirst=true (ברירת מחדל) — מסנכרן קודם את כל הלקוחות, כדי שההכנסות יקושרו במלואן.
 */
export declare function runMorningSync(options?: {
    fromDate?: string;
    syncClientsFirst?: boolean;
}): Promise<SyncResult>;
/** מחיקת כל ההכנסות מהמערכת (איפוס לפני סנכרון מלא מ-Morning) */
export declare function resetAllIncomes(): Promise<number>;
//# sourceMappingURL=morningSync.d.ts.map