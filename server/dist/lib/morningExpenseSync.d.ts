export type ReconcileResult = {
    ok: true;
    matched: number;
    bankMissingDocument: number;
    giUnpaid: number;
    durationMs: number;
};
export declare function runExpenseReconcile(): Promise<ReconcileResult>;
//# sourceMappingURL=morningExpenseSync.d.ts.map