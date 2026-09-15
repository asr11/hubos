export declare class FinanceAgentDbNotReadyError extends Error {
}
export type MonthFilter = {
    /** YYYY-MM — חודש בודד */
    month?: string;
    /** YYYY-MM — תחילת טווח (כולל) */
    from?: string;
    /** YYYY-MM — סוף טווח (כולל) */
    to?: string;
    limit: number;
};
export declare function listExpenses(filter: MonthFilter): Promise<Record<string, unknown>[]>;
export declare function listIncomes(filter: MonthFilter): Promise<Record<string, unknown>[]>;
export type MonthInventory = {
    month: string;
    expenseCount: number;
    expenseTotal: number;
    incomeCount: number;
    incomeTotal: number;
};
/**
 * מצאי גולמי לכל חודש — כמה רשומות יש וכמה הן מסתכמות.
 *
 * זו במכוון אינה תמונת רווח והפסד: הרווח מחושב בכללי הייחוס, המע״מ
 * והפריסה של המערכת, ולכן הוא נמסר מנקודה אחת בלבד. מספר מקביל שהיה
 * מחושב כאן היה יכול לסטות מהדשבורד, וזה בדיוק מה שאסור שיקרה.
 */
export declare function monthInventory(): Promise<MonthInventory[]>;
//# sourceMappingURL=financeAgentStore.d.ts.map