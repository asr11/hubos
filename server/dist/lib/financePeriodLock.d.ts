export declare const VAT_REPORT_DAY = 19;
export declare function addMonths(ym: string, delta: number): string;
export declare function monthLockDate(ym: string): string;
export declare function isMonthLocked(ym: string, today: string): boolean;
export declare function openMonth(today: string): string;
export type AttributionOutcome = {
    month: string;
    late: boolean;
    documentMonth: string;
};
export declare function resolveAttributionMonth(documentDateYmd: string, today: string): AttributionOutcome;
//# sourceMappingURL=financePeriodLock.d.ts.map