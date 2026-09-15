import { type Database as DatabaseType } from 'better-sqlite3';
export declare const db: DatabaseType;
export declare function initLocalDatabase(): void;
export declare function logSystemEvent(eventType: string, target: string, details?: string): void;
export declare function seedSuperAdmin(): void;
//# sourceMappingURL=db.d.ts.map