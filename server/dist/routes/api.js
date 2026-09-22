import { requireAuth } from '../middleware/requireAuth.js';
import { syncReadonlyMaterialsFromMorning } from '../lib/syncReadonlyMaterials.js';
import { registerDomainRecord, checkSshConnectivity } from '../lib/domainSshMonitor.js';
import { createQuoteRecord, signQuoteRecord, getQuoteRecord } from '../lib/quoteSignModule.js';
import { registerVaultRoutes } from './vault.js';
export async function registerApiRoutes(app) {
    // Register specific modules
    await registerVaultRoutes(app);
    app.get('/api/me', { preHandler: requireAuth }, async (req) => ({
        ok: true,
        uid: req.authUser.uid,
        email: req.authUser.email ?? null,
    }));
    app.get('/api/integrations/status', { preHandler: requireAuth }, async () => {
        return {
            ok: true,
            integrations: {
                morning: 'pending',
                clockify: 'pending',
            },
        };
    });
    app.post('/api/sync/readonly-materials', { preHandler: requireAuth }, async () => {
        const result = await syncReadonlyMaterialsFromMorning();
        return result;
    });
    app.post('/api/domains/register', { preHandler: requireAuth }, async (req) => {
        const body = req.body;
        return registerDomainRecord(body.domainName, body.registrar, body.expiryDate);
    });
    app.post('/api/ssh/check', { preHandler: requireAuth }, async (req) => {
        const body = req.body;
        return checkSshConnectivity(body.domainName, body.host, body.port ?? 22);
    });
    app.post('/api/quotes/create', { preHandler: requireAuth }, async (req) => {
        const body = req.body;
        return createQuoteRecord(body.clientId, body.totalAmount);
    });
    app.post('/api/quotes/sign', { preHandler: requireAuth }, async (req) => {
        const body = req.body;
        return signQuoteRecord(body.quoteId, body.signatureData);
    });
    app.get('/api/quotes/:id', { preHandler: requireAuth }, async (req) => {
        const params = req.params;
        return getQuoteRecord(params.id) ?? { ok: false, error: 'not_found' };
    });
    app.post('/api/admin/backup', { preHandler: requireAuth }, async () => {
        const { exec } = await import('child_process');
        const { promisify } = await import('util');
        const execAsync = promisify(exec);
        try {
            const { stdout } = await execAsync('python3 /home/asr11/02_HUB_Dev/os.hub.co.il/scripts/backup_engine.py');
            return JSON.parse(stdout);
        }
        catch (err) {
            return { ok: false, error: err.message };
        }
    });
}
//# sourceMappingURL=api.js.map