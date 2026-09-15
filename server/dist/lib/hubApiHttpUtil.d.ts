export type Actor = {
    userId: string;
    userName?: string;
};
export type ValidationResult = {
    ok: true;
    data: Record<string, unknown>;
} | {
    ok: false;
    errors: string[];
};
export interface ApiResult {
    status: number;
    body: Record<string, unknown>;
}
export declare function ok(status: number, body: Record<string, unknown>): ApiResult;
export declare function badRequest(errors: string[] | string, extra?: Record<string, unknown>): ApiResult;
export declare function unauthorized(): ApiResult;
export declare function notFound(entity: string, id: string): ApiResult;
export declare function readApiKeyFromHeaders(headers: Record<string, string | string[] | undefined>): string;
export declare function isValidApiKey(headers: Record<string, string | string[] | undefined>): boolean;
export declare function parseEntity(query: Record<string, unknown>): string;
export declare function isSchemaCatalogRequest(query: Record<string, unknown>): boolean;
export declare function parseId(query: Record<string, unknown>): string;
export declare function parseSub(query: Record<string, unknown>): string;
export declare function parseLimit(query: Record<string, unknown>, max?: number, fallback?: number): number;
export declare function parseBody(body: unknown): Record<string, unknown>;
export declare function parseDataPayload(body: Record<string, unknown>): Record<string, unknown>;
export declare function parseActor(body: Record<string, unknown>): Actor | null;
export declare function stripUndefined(obj: Record<string, unknown>): Record<string, unknown>;
export declare function serializeDoc(id: string, data: any): Record<string, unknown>;
export declare function requireString(data: Record<string, unknown>, field: string, label?: string): {
    value?: string;
    errors: string[];
};
export declare function optionalString(data: Record<string, unknown>, field: string): string | undefined;
export declare function requireEnum<T extends string>(data: Record<string, unknown>, field: string, allowed: readonly T[], label?: string): {
    value?: T;
    errors: string[];
};
export declare function requireYmd(data: Record<string, unknown>, field: string, label?: string): {
    value?: string;
    errors: string[];
};
export declare function requireNumber(data: Record<string, unknown>, field: string, label?: string, min?: number): {
    value?: number;
    errors: string[];
};
export declare function mergeErrors(...parts: Array<{
    errors: string[];
}>): string[];
export declare function okData(data: Record<string, unknown>): ValidationResult;
export declare function failErrors(errors: string[]): ValidationResult;
//# sourceMappingURL=hubApiHttpUtil.d.ts.map