import type { MorningDocument } from './morningClient.js';
/** סוגי מסמך הוצאה ב-Morning */
export declare const GI_EXPENSE_DOC_TYPE: {
    readonly INVOICE: 10;
    readonly RECEIPT: 20;
    readonly INVOICE_RECEIPT: 30;
    readonly OTHER: 40;
    /** זיכוי / החזר */
    readonly CREDIT_NOTE: 330;
};
export type MappedExpense = {
    greenInvoiceId: string;
    greenInvoiceDocType: number;
    greenInvoiceDocKind: 'invoice' | 'receipt' | 'invoice_receipt' | 'other';
    greenInvoiceNumber: string;
    amount: number;
    amountIncludesVat: boolean;
    vatAmount?: number;
    amountBeforeVat?: number;
    documentDate: string;
    /** תאריך דיווח ב-GI (לרוב חודש המסמך לצורכי התאמה) */
    reportingDate?: string;
    paidDate?: string;
    paymentMethod?: 'bank_transfer' | 'credit_card';
    supplierName: string;
    supplierTaxId: string;
    supplierGreenInvoiceId: string;
    description?: string;
    /** 10 פתוח · 20 דווח */
    greenInvoiceStatus?: number;
    source: 'green_invoice';
    supplierInvoiceReceived: true;
};
/** ממפה הוצאה מ-Morning לשדות FinanceExpense */
export declare function mapMorningExpense(doc: MorningDocument): MappedExpense | null;
//# sourceMappingURL=expenseMapper.d.ts.map