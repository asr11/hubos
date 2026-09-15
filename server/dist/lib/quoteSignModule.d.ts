export declare function createQuoteRecord(clientId?: string | null, totalAmount?: number): {
    ok: boolean;
    id: string;
    quoteNumber: string;
    totalAmount: number;
    status: string;
};
export declare function signQuoteRecord(quoteId: string, signatureData: string): {
    ok: boolean;
    quoteId: string;
    status: string;
};
export declare function getQuoteRecord(quoteId: string): unknown;
//# sourceMappingURL=quoteSignModule.d.ts.map