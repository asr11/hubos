import type { FastifyInstance, FastifyReply, FastifyRequest } from 'fastify';
/**
 * חשבוניות הספק נשמרות על השרת ולא בשירות אחסון חיצוני.
 *
 * ההעלאה דורשת הרשאת מנהל. ההורדה פתוחה, אבל שם הקובץ אקראי ולכן הקישור
 * אינו ניתן לניחוש — כמו קישורי ההורדה של Firebase Storage שהוחלפו כאן.
 */
/** מיוצא בנפרד מהרישום כדי שאפשר יהיה לבדוק את ההעלאה בלי שכבת ההרשאות */
export declare function handleDocumentUpload(req: FastifyRequest, reply: FastifyReply): Promise<never>;
export declare function registerFinanceDocumentRoutes(app: FastifyInstance): Promise<void>;
//# sourceMappingURL=financeDocuments.d.ts.map