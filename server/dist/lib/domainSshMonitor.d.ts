export declare function checkSshConnectivity(domainName: string, host: string, port?: number): Promise<{
    ok: boolean;
    message: string;
}>;
export declare function registerDomainRecord(domainName: string, registrar?: string, expiryDate?: string): {
    ok: boolean;
    id: string;
    domainName: string;
};
//# sourceMappingURL=domainSshMonitor.d.ts.map