export type MorningEnv = 'sandbox' | 'production';
export type MorningCredentials = {
    keyId: string;
    keySecret: string;
    env: MorningEnv;
};
/**
 * מקור המפתחות (בסדר עדיפות):
 * 1. משתני סביבה (MORNING_KEY_ID/SECRET)
 * 2. קובץ מקומי על השרת (נפח Docker) — בלי Firestore
 * 3. Firestore (גיבוי שקט אם המכסה מאפשרת; לא מפיל את הנתיב)
 */
export declare function loadMorningCredentials(): Promise<MorningCredentials | null>;
/** שמירה לקובץ השרת — מקור האמת מעתה. Firestore הוא best-effort בלבד. */
export declare function saveMorningCredentials(creds: MorningCredentials): Promise<void>;
/** האם המפתחות הגיעו ממשתני סביבה (לא ניתנים לעריכה מהאפליקציה) */
export declare function credentialsFromEnv(): boolean;
/** קריאת מצב הסנכרון האחרון — קודם דיסק השרת */
export declare function readSyncState(): Promise<Record<string, unknown> | null>;
/** עדכון מצב הסנכרון האחרון — דיסק השרת הוא העוגן */
export declare function writeSyncState(state: Record<string, unknown>): Promise<void>;
//# sourceMappingURL=morningCredentials.d.ts.map