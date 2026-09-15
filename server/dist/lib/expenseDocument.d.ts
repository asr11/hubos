import type { MorningClient } from './morningClient.js';
export type ExpenseFileSource = {
    kind: 'url';
    url: string;
    fileName?: string;
} | {
    kind: 'base64';
    base64: string;
    mimeType: string;
    fileName?: string;
};
/**
 * מורנינג מחזיר הוצאה עם שדות מקוננים ב-data (fileKey וכו')
 * ושדות ברמת השורש (url, id, number). לא מספיק לקחת רק את data.
 */
export declare function mergeGiExpenseRecord(raw: unknown): Record<string, unknown>;
/** @deprecated use mergeGiExpenseRecord */
export declare function unwrapGiPayload(raw: unknown): Record<string, unknown>;
/** מחלץ קישור או base64 למסמך מצורף מהוצאה ב-GI */
export declare function extractExpenseFileSource(raw: unknown): ExpenseFileSource | null;
export declare function fetchExpenseDocumentBuffer(client: MorningClient, greenInvoiceId: string): Promise<{
    buffer: Buffer;
    mimeType: string;
    fileName: string;
}>;
//# sourceMappingURL=expenseDocument.d.ts.map