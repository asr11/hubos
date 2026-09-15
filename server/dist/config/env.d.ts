import './loadEnv.js';
export declare const env: {
    readonly nodeEnv: string;
    readonly isDev: boolean;
    readonly host: string;
    readonly port: number;
    readonly corsOrigins: string[];
    readonly firebaseServiceAccountPath: string;
    /** JSON גולמי — לפרודקשן ב-xCloud (משתנה סביבה אחד) */
    readonly firebaseServiceAccountJson: string;
    /** Base64 של JSON — נוח להדבקה בפאנל xCloud */
    readonly firebaseServiceAccountB64: string;
    readonly morning: {
        readonly env: "sandbox" | "production";
        readonly keyId: string;
        readonly keySecret: string;
    };
    readonly sync: {
        readonly fromDate: string;
        readonly overlapDays: number;
    };
    /** מיילים שמורשים כמנהל כשקריאת Firestore users נכשלת (מופרד בפסיקים) */
    readonly adminEmails: string[];
    /** מפתח API ל-/api/bot-data (בוטים/סוכנים חיצוניים - למשל ceo-digital-ai) */
    readonly botApiKey: string;
    /**
     * תיקיית חשבוניות הספק. בפרודקשן זהו נפח קבוע של Docker — הקבצים חייבים
     * לשרוד בנייה מחדש של הקונטיינר.
     */
    readonly financeDocsDir: string;
    /**
     * מפתחות + מצב סנכרון של חשבונית ירוקה על דיסק השרת.
     * כך סטטוס/חיבור לא תלויים במכסת Firestore Admin.
     */
    readonly morningDataDir: string;
};
/**
 * Resource API host — מסמכים / לקוחות / הוצאות.
 * החל מיוני 2026: רק api.greeninvoice.co.il (לא www.greeninvoice.co.il).
 * @see https://www.greeninvoice.co.il/help-center/api-updates-26/
 */
export declare const MORNING_BASE_URLS: {
    readonly sandbox: "https://sandbox.d.greeninvoice.co.il/api/v1";
    readonly production: "https://api.greeninvoice.co.il/api/v1";
};
/**
 * OAuth 2.0 IdP host — קבלת access token (נפרד מ-Resource API).
 * לפי דוקומנטציית Morning / מפתחים מעודכנת (API 2.0).
 */
export declare const MORNING_AUTH_URLS: {
    readonly sandbox: "https://api.sandbox.morning.dev/idp/v1/oauth/token";
    readonly production: "https://api.morning.co/idp/v1/oauth/token";
};
/** האם הוגדרו מפתחות Morning במשתני סביבה (fallback ל-Firestore) */
export declare function morningEnvConfigured(): boolean;
/** @deprecated שם ישן — נשמר לתאימות עם health route */
export declare function morningConfigured(): boolean;
//# sourceMappingURL=env.d.ts.map