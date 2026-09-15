/**
 * אחסון מקומי לאינטגרציית חשבונית ירוקה — על דיסק השרת (נפח Docker),
 * בלי תלות ב-Firestore לסטטוס / מפתחות / מצב סנכרון אחרון.
 *
 * כתיבת ההכנסות עצמן ל-Firestore נשארת ב-morningSync כמו שהייתה.
 */
export type LocalMorningEnv = 'sandbox' | 'production';
export type LocalMorningCredentials = {
    keyId: string;
    keySecret: string;
    env: LocalMorningEnv;
};
export declare function readLocalCredentials(): Promise<LocalMorningCredentials | null>;
export declare function writeLocalCredentials(creds: LocalMorningCredentials): Promise<void>;
export declare function readLocalSyncState(): Promise<Record<string, unknown> | null>;
export declare function writeLocalSyncState(state: Record<string, unknown>): Promise<void>;
//# sourceMappingURL=morningLocalStore.d.ts.map