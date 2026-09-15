import { type ApiResult } from './hubApiHttpUtil.js';
export declare class HubApiDbNotReadyError extends Error {
}
export type EntityName = 'tasks' | 'projects' | 'leads' | 'clients' | 'suppliers' | 'campaigns' | 'meetings' | 'invoices' | 'deals' | 'collectiontracking' | 'hostingrecords' | 'docs' | 'recurringtasktemplates' | 'agentcommissionpayments' | 'domainrecords' | 'users' | 'activitylogs';
export declare function normalizeEntity(raw: string): EntityName | null;
export declare function apiCatalog(): Record<string, unknown>;
export declare function handleGet(entity: EntityName, id: string, reqQuery: Record<string, unknown>): Promise<ApiResult>;
export declare function handlePost(entity: EntityName, id: string, body: Record<string, unknown>, sub: string): Promise<ApiResult>;
export declare function handlePatch(entity: EntityName, id: string, body: Record<string, unknown>): Promise<ApiResult>;
export declare function handleDelete(entity: EntityName, id: string): Promise<ApiResult>;
//# sourceMappingURL=hubApiRegistry.d.ts.map