/**
 * אחסון חשבוניות הספק על השרת עצמו.
 *
 * הקובץ נשמר תחת מזהה ההוצאה ובשם אקראי, והשם המקורי נשמר בבסיס הנתונים
 * לצורך תצוגה בלבד. כך שם הקובץ שמגיע מהמשתמש אינו משתתף בבניית הנתיב,
 * ואי אפשר לצאת בעזרתו מתיקיית האחסון.
 *
 * הקישור הוא ציבורי אך בלתי ניתן לניחוש — אותו מודל אבטחה בדיוק שהיה
 * בקישורי ההורדה של Firebase Storage.
 */
export declare const MAX_DOCUMENT_BYTES: number;
export declare const ACCEPTED_EXTENSIONS: readonly [".pdf", ".jpg", ".jpeg", ".png", ".webp", ".heic"];
export declare function documentExtension(name: string): string;
export declare function isAcceptedDocument(name: string): boolean;
export declare function contentTypeFor(name: string): string;
/** שם האחסון נגזר מהסיומת בלבד — שאר השם המקורי אינו נכנס לנתיב */
export declare function buildStoredName(originalName: string): string;
export declare function isSafeSegment(segment: string): boolean;
export declare class UnsafePathError extends Error {
}
/**
 * נתיב מלא לקובץ בתוך תיקיית האחסון.
 * נזרקת שגיאה כשהמזהים אינם עומדים בכללים, ובנוסף מאומת שהתוצאה
 * נשארה בתוך התיקייה — הגנה כפולה מפני מעבר תיקיות.
 */
export declare function resolveDocumentPath(baseDir: string, expenseId: string, fileName: string): string;
/** הכתובת שנשמרת על ההוצאה — יחסית, כדי שתעבוד בכל סביבה */
export declare function documentUrlFor(expenseId: string, fileName: string): string;
//# sourceMappingURL=financeDocumentStore.d.ts.map