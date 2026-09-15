import { db, logSystemEvent } from './db.js';
import { MorningClient } from './morningClient.js';
import { loadMorningCredentials } from './morningCredentials.js';
export async function syncReadonlyMaterialsFromMorning() {
    const creds = await loadMorningCredentials();
    if (!creds) {
        throw new Error('Morning credentials not configured');
    }
    const client = new MorningClient(creds);
    const verifyResult = await client.verifyConnection();
    if (!verifyResult.ok) {
        throw new Error('Failed to authenticate with Morning API (GET)');
    }
    // 1. שליפת לקוחות (GET Read-Only)
    const rawClients = await client.searchClients({ pageSize: 100 });
    const insertClientStmt = db.prepare(`
    INSERT OR REPLACE INTO clients (id, green_invoice_id, name, tax_id, email, phone, status)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `);
    let clientsSynced = 0;
    for (const c of rawClients.items) {
        const id = String(c.id ?? `gi_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`);
        insertClientStmt.run(id, String(c.id ?? ''), String(c.name ?? 'לקוח ללא שם'), String(c.taxId ?? c.businessId ?? ''), String(c.email ?? ''), String(c.phone ?? ''), c.active === false ? 'inactive' : 'active');
        clientsSynced++;
    }
    logSystemEvent('SYNC_READONLY', 'MORNING', `Synced ${clientsSynced} clients into local SQLite`);
    return { ok: true, clientsSynced };
}
//# sourceMappingURL=syncReadonlyMaterials.js.map