/** מינימום שדות להתאמה — משותף לשרת */
export type ReconcileExpense = {
    id: string;
    type?: string;
    amount: number;
    amountBeforeVat?: number;
    vatAmount?: number;
    billingMonth: string;
    paidDate?: string;
    bankRef?: string;
    source?: string;
    greenInvoiceId?: string;
    greenInvoiceNumber?: string;
    supplierName?: string;
    greenInvoiceSupplierName?: string;
    greenInvoiceSupplierTaxId?: string;
    supplierTaxId?: string;
    name?: string;
    description?: string;
    supplierInvoiceReceived?: boolean;
    matchedExpenseId?: string;
    linkedGreenInvoiceId?: string;
};
export type ExpensePair = {
    bank: ReconcileExpense;
    gi: ReconcileExpense;
    score: number;
};
export declare function isGiOnlyRecord(exp: ReconcileExpense): boolean;
export declare function isBankExpense(exp: ReconcileExpense): boolean;
export declare function isGiExpense(exp: ReconcileExpense): boolean;
export declare function requiresGiDocument(exp: ReconcileExpense): boolean;
export declare function computeExpensePairs(expenses: ReconcileExpense[]): ExpensePair[];
//# sourceMappingURL=expenseReconcileCore.d.ts.map