import type { MorningDocument } from './morningClient.js';
/**
 * מיפוי לקוח חשבונית ירוקה → שדות Client (כפי שנשמר ב-Firestore).
 * סלחני לשמות שדות שונים שה-API עשוי להחזיר.
 */
export type MappedClient = {
    greenInvoiceId: string;
    name: string;
    active: boolean;
    email: string;
    phone: string;
    contact: string;
    taxId: string;
    addressLine: string;
    city: string;
};
/** ממפה לקוח Morning לשדות Client. מחזיר null אם אין מזהה/שם */
export declare function mapMorningClient(c: MorningDocument): MappedClient | null;
//# sourceMappingURL=clientMapper.d.ts.map