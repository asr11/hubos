import { type App } from 'firebase-admin/app';
export type FirebaseAdminState = {
    status: 'ready';
    app: App;
} | {
    status: 'not_configured';
    reason: string;
};
export declare function getFirebaseAdmin(): FirebaseAdminState;
/**
 * אימות אמיתי שהאישורים מאפשרים גישת Firestore (קריאה זעירה).
 * חיוני כי initializeApp מצליח גם עם אישורים שלא תקפים ל-Firestore.
 */
export declare function verifyFirebaseAdmin(): Promise<{
    ok: boolean;
    reason?: string;
}>;
export declare function getAdminAuth(): any;
export declare function getAdminDb(): any;
//# sourceMappingURL=firebaseAdmin.d.ts.map