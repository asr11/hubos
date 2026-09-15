import { MORNING_AUTH_URLS, MORNING_BASE_URLS } from '../config/env.js';
const RATE_LIMIT_MS = 350; // ~3 בקשות לשנייה
export class MorningClient {
    baseUrl;
    authUrl;
    creds;
    tokenState = null;
    lastRequestAt = 0;
    constructor(creds) {
        this.creds = creds;
        this.baseUrl = MORNING_BASE_URLS[creds.env];
        this.authUrl = MORNING_AUTH_URLS[creds.env];
    }
    async throttle() {
        const now = Date.now();
        const wait = this.lastRequestAt + RATE_LIMIT_MS - now;
        if (wait > 0)
            await new Promise((r) => setTimeout(r, wait));
        this.lastRequestAt = Date.now();
    }
    /** מפרק תשובת טוקן מ-IdP או מ-/account/token */
    parseTokenResponse(data, source) {
        const token = (typeof data.accessToken === 'string' && data.accessToken) ||
            (typeof data.access_token === 'string' && data.access_token) ||
            (typeof data.token === 'string' && data.token) ||
            '';
        if (!token)
            return null;
        // IdP: expiresAt (unix). Legacy: expires (unix). OAuth: expires_in (שניות).
        let expiresAtMs = Date.now() + 25 * 60 * 1000;
        if (typeof data.expiresAt === 'number' && data.expiresAt > 0) {
            expiresAtMs = data.expiresAt * 1000;
        }
        else if (typeof data.expires_at === 'number' && data.expires_at > 0) {
            expiresAtMs = data.expires_at * 1000;
        }
        else if (typeof data.expires === 'number' && data.expires > 0) {
            expiresAtMs = data.expires * 1000;
        }
        else if (typeof data.expires_in === 'number' && data.expires_in > 0) {
            expiresAtMs = Date.now() + data.expires_in * 1000;
        }
        else if (source === 'idp') {
            // IdP בדרך כלל ~שעה
            expiresAtMs = Date.now() + 55 * 60 * 1000;
        }
        return { token, expiresAtMs };
    }
    /** OAuth 2.0 client_credentials מול IdP של Morning */
    async fetchTokenFromIdp() {
        const res = await fetch(this.authUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                grant_type: 'client_credentials',
                client_id: this.creds.keyId,
                client_secret: this.creds.keySecret,
            }),
        });
        const text = await res.text().catch(() => '');
        let data = {};
        try {
            data = text ? JSON.parse(text) : {};
        }
        catch {
            data = { raw: text };
        }
        if (!res.ok)
            return null;
        return this.parseTokenResponse(data, 'idp');
    }
    /**
     * נפילה חזרה — POST /account/token על Resource API
     * (מבנה מעודכן: id + secret + grant_type לפי עדכון יוני 2026)
     */
    async fetchTokenFromAccountEndpoint() {
        const res = await fetch(`${this.baseUrl}/account/token`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: this.creds.keyId,
                secret: this.creds.keySecret,
                grant_type: 'client_credentials',
            }),
        });
        const text = await res.text().catch(() => '');
        let data = {};
        try {
            data = text ? JSON.parse(text) : {};
        }
        catch {
            data = { raw: text };
        }
        if (!res.ok) {
            throw new MorningError(res.status === 401
                ? 'מפתח API לא תקין — בדקו מזהה/סוד בחשבונית ירוקה (או צרו מפתח חדש בכלי מפתחים)'
                : `שגיאת אימות מול חשבונית ירוקה (${res.status})`, res.status, text);
        }
        const parsed = this.parseTokenResponse(data, 'account');
        if (!parsed) {
            throw new MorningError('לא התקבל טוקן מחשבונית ירוקה', 500, JSON.stringify(data));
        }
        return parsed;
    }
    /** מבקש טוקן JWT חדש מ-Morning (IdP תחילה, אחר כך /account/token) */
    async fetchToken() {
        await this.throttle();
        try {
            const idpToken = await this.fetchTokenFromIdp();
            if (idpToken)
                return idpToken;
        }
        catch {
            // ממשיכים לנפילה חזרה
        }
        return this.fetchTokenFromAccountEndpoint();
    }
    /** טוקן תקף (עם מרווח ביטחון של דקה) */
    async getToken(forceRefresh = false) {
        const safeNow = Date.now() + 60 * 1000;
        if (!forceRefresh && this.tokenState && this.tokenState.expiresAtMs > safeNow) {
            return this.tokenState.token;
        }
        this.tokenState = await this.fetchToken();
        return this.tokenState.token;
    }
    /** בקשה אותנטית עם retry יחיד על 401 */
    async authedRequest(path, init, isRetry = false) {
        await this.throttle();
        const token = await this.getToken(isRetry);
        const res = await fetch(`${this.baseUrl}${path}`, {
            ...init,
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
                ...(init.headers ?? {}),
            },
        });
        if (res.status === 401 && !isRetry) {
            this.tokenState = null;
            return this.authedRequest(path, init, true);
        }
        if (!res.ok) {
            const text = await res.text().catch(() => '');
            throw new MorningError(`שגיאה בקריאה ל-Morning ${path} (${res.status})`, res.status, text);
        }
        return (await res.json());
    }
    /** בדיקת חיבור — מאמת מפתחות ומחזיר פרטי העסק */
    async verifyConnection() {
        await this.getToken(true);
        try {
            const business = await this.authedRequest('/businesses/me', {
                method: 'GET',
            });
            return { ok: true, business };
        }
        catch {
            // אם הטוקן התקבל אך /businesses/me נכשל — עדיין נחשב מחובר
            return { ok: true };
        }
    }
    /** חיפוש מסמכים בעמוד יחיד */
    async searchDocuments(params) {
        const body = {
            fromDate: params.fromDate,
            toDate: params.toDate,
            page: params.page ?? 1,
            pageSize: params.pageSize ?? 100,
            sort: 'documentDate',
        };
        if (params.types && params.types.length > 0)
            body.type = params.types;
        const data = await this.authedRequest('/documents/search', {
            method: 'POST',
            body: JSON.stringify(body),
        });
        return {
            items: Array.isArray(data.items) ? data.items : [],
            total: typeof data.total === 'number' ? data.total : 0,
            page: typeof data.page === 'number' ? data.page : (params.page ?? 1),
            pages: typeof data.pages === 'number' ? data.pages : 1,
        };
    }
    /** שליפת כל המסמכים בטווח — עובר על כל העמודים */
    async searchAllDocuments(params) {
        const pageSize = params.pageSize ?? 100;
        const all = [];
        let page = 1;
        let pages = 1;
        do {
            const result = await this.searchDocuments({ ...params, page, pageSize });
            all.push(...result.items);
            pages = result.pages || 1;
            page += 1;
            if (page > 500)
                break; // הגנה מפני לולאה אינסופית
        } while (page <= pages);
        return all;
    }
    /** חיפוש לקוחות בעמוד יחיד */
    async searchClients(params) {
        const body = {
            page: params.page ?? 1,
            pageSize: params.pageSize ?? 100,
        };
        if (typeof params.active === 'boolean')
            body.active = params.active;
        const data = await this.authedRequest('/clients/search', {
            method: 'POST',
            body: JSON.stringify(body),
        });
        return {
            items: Array.isArray(data.items) ? data.items : [],
            total: typeof data.total === 'number' ? data.total : 0,
            page: typeof data.page === 'number' ? data.page : (params.page ?? 1),
            pages: typeof data.pages === 'number' ? data.pages : 1,
        };
    }
    /** חיפוש הוצאות בעמוד יחיד */
    async searchExpenses(params) {
        const body = {
            fromDate: params.fromDate,
            toDate: params.toDate,
            page: params.page ?? 1,
            pageSize: params.pageSize ?? 100,
            sort: 'documentDate',
        };
        if (params.types && params.types.length > 0)
            body.type = params.types;
        const data = await this.authedRequest('/expenses/search', {
            method: 'POST',
            body: JSON.stringify(body),
        });
        return {
            items: Array.isArray(data.items) ? data.items : [],
            total: typeof data.total === 'number' ? data.total : 0,
            page: typeof data.page === 'number' ? data.page : (params.page ?? 1),
            pages: typeof data.pages === 'number' ? data.pages : 1,
        };
    }
    /** שליפת כל ההוצאות בטווח — עובר על כל העמודים */
    async searchAllExpenses(params) {
        const pageSize = params.pageSize ?? 100;
        const all = [];
        let page = 1;
        let pages = 1;
        do {
            const result = await this.searchExpenses({ ...params, page, pageSize });
            all.push(...result.items);
            pages = result.pages || 1;
            page += 1;
            if (page > 500)
                break;
        } while (page <= pages);
        return all;
    }
    /** שליפת הוצאה בודדת מ-GI */
    async getExpense(id) {
        return this.authedRequest(`/expenses/${encodeURIComponent(id)}`, { method: 'GET' });
    }
    /** קישור הורדה להוצאה — לעיתים זמין רק בתוצאות search */
    async findExpenseDownloadUrl(greenInvoiceId) {
        const now = new Date();
        const toDate = now.toISOString().slice(0, 10);
        const from = new Date(now);
        from.setFullYear(from.getFullYear() - 10);
        const fromDate = from.toISOString().slice(0, 10);
        const result = await this.searchExpenses({
            fromDate,
            toDate,
            page: 1,
            pageSize: 100,
        });
        const hit = result.items.find((item) => {
            const id = item.id ?? item._id;
            return typeof id === 'string' && id === greenInvoiceId;
        });
        const url = typeof hit?.url === 'string' && /^https?:\/\//i.test(hit.url)
            ? hit.url.trim()
            : null;
        return url ? { url } : null;
    }
    /** הורדת קובץ מכתובת חיצונית (קישור חתום מ-GI) */
    async fetchBinaryUrl(url) {
        await this.throttle();
        const token = await this.getToken();
        const res = await fetch(url, {
            headers: { Authorization: `Bearer ${token}` },
        });
        if (!res.ok) {
            const res2 = await fetch(url);
            if (!res2.ok) {
                throw new MorningError(`לא ניתן להוריד את המסמך (${res.status})`, res.status);
            }
            return {
                data: await res2.arrayBuffer(),
                contentType: res2.headers.get('content-type'),
            };
        }
        return {
            data: await res.arrayBuffer(),
            contentType: res.headers.get('content-type'),
        };
    }
    /** שליפת כל הלקוחות בפילוח active נתון — עובר על כל העמודים */
    async searchAllClients(active, pageSize = 100) {
        const all = [];
        let page = 1;
        let pages = 1;
        do {
            const result = await this.searchClients({ page, pageSize, active });
            all.push(...result.items);
            pages = result.pages || 1;
            page += 1;
            if (page > 500)
                break;
        } while (page <= pages);
        return all;
    }
    /**
     * מסמכים מקושרים (למשל קבלות ששולמו כנגד חשבון עסקה).
     * נכשל בשקט → מערך ריק, כדי לא להפיל סנכרון שלם.
     */
    async getLinkedDocuments(documentId) {
        try {
            const data = await this.authedRequest(`/documents/${encodeURIComponent(documentId)}/linked`, { method: 'GET' });
            if (Array.isArray(data))
                return data;
            if (data && typeof data === 'object') {
                const obj = data;
                if (Array.isArray(obj.items))
                    return obj.items;
                if (Array.isArray(obj.documents))
                    return obj.documents;
                if (Array.isArray(obj.linked))
                    return obj.linked;
            }
            return [];
        }
        catch {
            return [];
        }
    }
    /** הוראות קבע — POST search (לא ב-Apiary הרשמי) */
    async searchRecurringPayments(params = {}) {
        const body = {
            page: params.page ?? 1,
            pageSize: params.pageSize ?? 100,
        };
        if (params.status?.length)
            body.status = params.status;
        const data = await this.authedRequest('/payments/recurrings/search', {
            method: 'POST',
            body: JSON.stringify(body),
        });
        return this.normalizePagedSearch(data, params.page ?? 1);
    }
    async searchAllRecurringPayments() {
        const pageSize = 100;
        const all = [];
        let page = 1;
        let pages = 1;
        do {
            const result = await this.searchRecurringPayments({
                page,
                pageSize,
                status: [10, 20],
            });
            all.push(...result.items);
            pages = result.pages || 1;
            page += 1;
            if (page > 500)
                break;
        } while (page <= pages);
        return all;
    }
    /** ריטיינרים — POST search (לא ב-Apiary הרשמי) */
    async searchRetainers(params = {}) {
        const body = {
            page: params.page ?? 1,
            pageSize: params.pageSize ?? 100,
        };
        if (params.status?.length)
            body.status = params.status;
        const data = await this.authedRequest('/retainers/search', {
            method: 'POST',
            body: JSON.stringify(body),
        });
        return this.normalizePagedSearch(data, params.page ?? 1);
    }
    async searchAllRetainers() {
        const pageSize = 100;
        const all = [];
        let page = 1;
        let pages = 1;
        do {
            const result = await this.searchRetainers({
                page,
                pageSize,
                status: [1, 2],
            });
            all.push(...result.items);
            pages = result.pages || 1;
            page += 1;
            if (page > 500)
                break;
        } while (page <= pages);
        return all;
    }
    normalizePagedSearch(data, fallbackPage) {
        const items = Array.isArray(data.items) ? data.items : [];
        const page = typeof data.page === 'number' && Number.isFinite(data.page)
            ? data.page
            : fallbackPage;
        const pages = typeof data.pages === 'number' && data.pages > 0
            ? data.pages
            : typeof data.totalPages === 'number' && data.totalPages > 0
                ? data.totalPages
                : 1;
        const total = typeof data.total === 'number' && Number.isFinite(data.total)
            ? data.total
            : items.length;
        return { items, page, pages, total };
    }
}
export class MorningError extends Error {
    status;
    detail;
    constructor(message, status, detail) {
        super(message);
        this.name = 'MorningError';
        this.status = status;
        this.detail = detail;
    }
}
//# sourceMappingURL=morningClient.js.map