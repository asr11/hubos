import type { MorningDocument } from './morningClient.js';
export type MappedGiRecurringPayment = {
    greenInvoiceId: string;
    clientName: string;
    clientGreenInvoiceId: string;
    amount: number;
    intervalMonths: number;
    status: string;
    nextChargeDate?: string;
    startDate?: string;
    endDate?: string;
};
export type MappedGiRetainer = {
    greenInvoiceId: string;
    clientName: string;
    clientGreenInvoiceId: string;
    amount: number;
    interval: string;
    status: string;
    nextJobDate?: string;
    startDate?: string;
    endDate?: string;
};
export declare function mapMorningRecurringPayment(raw: MorningDocument): MappedGiRecurringPayment | null;
export declare function mapMorningRetainer(raw: MorningDocument): MappedGiRetainer | null;
//# sourceMappingURL=morningRecurringMapper.d.ts.map