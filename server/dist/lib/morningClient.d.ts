import type { MorningCredentials } from './morningCredentials.js';
/**
 * לקוח API לחשבונית ירוקה (Morning).
 * אימות (API 2.0 / יוני 2026):
 * 1. OAuth IdP — POST api.morning.co/idp/v1/oauth/token עם grant_type=client_credentials
 * 2. נפילה חזרה — POST /account/token על api.greeninvoice.co.il עם id+secret+grant_type
 * @see https://www.greeninvoice.co.il/help-center/api-updates-26/
 *
 * - cache טוקן + רענון אוטומטי
 * - rate limiting ~3 בקשות לשנייה
 * - retry פעם אחת על 401 (טוקן פג)
 */
export type MorningDocument = Record<string, unknown>;
export type SearchDocumentsParams = {
    fromDate: string;
    toDate: string;
    types?: number[];
    page?: number;
    pageSize?: number;
};
export type SearchDocumentsResult = {
    items: MorningDocument[];
    total: number;
    page: number;
    pages: number;
};
export type MorningSearchResult = SearchDocumentsResult;
export declare class MorningClient {
    private readonly baseUrl;
    private readonly authUrl;
    private readonly creds;
    private tokenState;
    private lastRequestAt;
    constructor(creds: MorningCredentials);
    private throttle;
    /** מפרק תשובת טוקן מ-IdP או מ-/account/token */
    private parseTokenResponse;
    /** OAuth 2.0 client_credentials מול IdP של Morning */
    private fetchTokenFromIdp;
    /**
     * נפילה חזרה — POST /account/token על Resource API
     * (מבנה מעודכן: id + secret + grant_type לפי עדכון יוני 2026)
     */
    private fetchTokenFromAccountEndpoint;
    /** מבקש טוקן JWT חדש מ-Morning (IdP תחילה, אחר כך /account/token) */
    private fetchToken;
    /** טוקן תקף (עם מרווח ביטחון של דקה) */
    private getToken;
    /** בקשה אותנטית עם retry יחיד על 401 */
    private authedRequest;
    /** בדיקת חיבור — מאמת מפתחות ומחזיר פרטי העסק */
    verifyConnection(): Promise<{
        ok: true;
        business?: unknown;
    }>;
    /** חיפוש מסמכים בעמוד יחיד */
    searchDocuments(params: SearchDocumentsParams): Promise<SearchDocumentsResult>;
    /** שליפת כל המסמכים בטווח — עובר על כל העמודים */
    searchAllDocuments(params: Omit<SearchDocumentsParams, 'page'>): Promise<MorningDocument[]>;
    /** חיפוש לקוחות בעמוד יחיד */
    searchClients(params: {
        page?: number;
        pageSize?: number;
        active?: boolean;
    }): Promise<SearchDocumentsResult>;
    /** חיפוש הוצאות בעמוד יחיד */
    searchExpenses(params: {
        fromDate: string;
        toDate: string;
        page?: number;
        pageSize?: number;
        types?: number[];
    }): Promise<SearchDocumentsResult>;
    /** שליפת כל ההוצאות בטווח — עובר על כל העמודים */
    searchAllExpenses(params: {
        fromDate: string;
        toDate: string;
        pageSize?: number;
        types?: number[];
    }): Promise<MorningDocument[]>;
    /** שליפת הוצאה בודדת מ-GI */
    getExpense(id: string): Promise<MorningDocument>;
    /** קישור הורדה להוצאה — לעיתים זמין רק בתוצאות search */
    findExpenseDownloadUrl(greenInvoiceId: string): Promise<{
        url: string;
    } | null>;
    /** הורדת קובץ מכתובת חיצונית (קישור חתום מ-GI) */
    fetchBinaryUrl(url: string): Promise<{
        data: ArrayBuffer;
        contentType: string | null;
    }>;
    /** שליפת כל הלקוחות בפילוח active נתון — עובר על כל העמודים */
    searchAllClients(active?: boolean, pageSize?: number): Promise<MorningDocument[]>;
    /**
     * מסמכים מקושרים (למשל קבלות ששולמו כנגד חשבון עסקה).
     * נכשל בשקט → מערך ריק, כדי לא להפיל סנכרון שלם.
     */
    getLinkedDocuments(documentId: string): Promise<MorningDocument[]>;
    /** הוראות קבע — POST search (לא ב-Apiary הרשמי) */
    searchRecurringPayments(params?: {
        page?: number;
        pageSize?: number;
        status?: number[];
    }): Promise<MorningSearchResult>;
    searchAllRecurringPayments(): Promise<MorningDocument[]>;
    /** ריטיינרים — POST search (לא ב-Apiary הרשמי) */
    searchRetainers(params?: {
        page?: number;
        pageSize?: number;
        status?: number[];
    }): Promise<MorningSearchResult>;
    searchAllRetainers(): Promise<MorningDocument[]>;
    private normalizePagedSearch;
}
export declare class MorningError extends Error {
    readonly status: number;
    readonly detail?: string;
    constructor(message: string, status: number, detail?: string);
}
//# sourceMappingURL=morningClient.d.ts.map