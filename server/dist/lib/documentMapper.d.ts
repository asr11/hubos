import type { MorningDocument } from './morningClient.js';
/**
 * מיפוי מסמך חשבונית ירוקה → שדות FinanceIncome (כפי שנשמר ב-Firestore).
 * המיפוי סלחני לשמות שדות (ה-API מחזיר וריאציות) ומנרמל לתאריך YYYY-MM-DD.
 */
/** קודי סוגי מסמך ב-Morning */
export declare const MORNING_DOC_TYPE: {
    readonly PRICE_QUOTE: 10;
    readonly ORDER: 100;
    readonly DELIVERY_NOTE: 200;
    readonly RETURN_DELIVERY: 210;
    readonly TRANSACTION_ACCOUNT: 300;
    readonly TAX_INVOICE: 305;
    readonly TAX_INVOICE_RECEIPT: 320;
    readonly CREDIT_INVOICE: 330;
    readonly RECEIPT: 400;
    readonly DONATION_RECEIPT: 405;
};
/** סוגי מסמך שנחשבים הכנסה ומיובאים */
export declare const INCOME_DOC_TYPES: number[];
export type MappedIncome = {
    greenInvoiceId: string;
    greenInvoiceType: number;
    greenInvoiceNumber: string;
    documentKind: 'tax_invoice_receipt' | 'tax_invoice' | 'receipt' | 'transaction_account' | 'credit_invoice';
    amount: number;
    amountIncludesVat: boolean;
    vatAmount?: number;
    amountBeforeVat?: number;
    invoiceNumber: string;
    invoiceDate: string;
    status: 'paid' | 'pending' | 'partial';
    /** סכום ששולם בפועל על המסמך (תשלומים חלקיים בתוך חשבון עסקה) */
    paidAmount?: number;
    /** קוד סטטוס המסמך ב-GI — 0 פתוח · 1 סגור · 2 נסגר ידנית · 3/4 מבוטל */
    greenInvoiceStatus?: number;
    description?: string;
    clientName: string;
    clientTaxId: string;
    clientEmail: string;
    source: 'green_invoice';
    /** מזהה הלקוח בחשבונית ירוקה — לקישור מהימן ללקוח שסונכרן */
    clientGreenInvoiceId: string;
    /** האם המסמך מבוטל (יש להסיר מהמאגר) */
    cancelled: boolean;
};
/** סכום מערך payment של מסמך Morning (אם קיים בתוצאת search/get) */
export declare function sumDocumentPaymentArray(doc: MorningDocument): number;
/** סכום תקבולים ממסמכים מקושרים (קבלה / חשבונית מס קבלה) */
export declare function sumLinkedPaymentDocuments(linked: MorningDocument[]): number;
/**
 * מעדכן חשבון עסקה פתוח עם סכום ששולם (תשלום חלקי).
 * לא סוגר את המסמך — סטטוס GI נשאר מקור האמת לפתיחה/סגירה.
 */
export declare function applyPartialPaidToMappedIncome(income: MappedIncome, paidCandidate: number): MappedIncome;
/**
 * ממפה מסמך Morning לשדות הכנסה.
 * מחזיר null אם זה לא סוג מסמך הכנסה (הצעת מחיר, הזמנה וכו׳).
 */
export declare function mapMorningDocument(doc: MorningDocument): MappedIncome | null;
//# sourceMappingURL=documentMapper.d.ts.map