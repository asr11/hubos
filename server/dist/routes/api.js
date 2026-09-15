import { requireAuth } from '../middleware/requireAuth.js';
import { syncReadonlyMaterialsFromMorning } from '../lib/syncReadonlyMaterials.js';
import { registerDomainRecord, checkSshConnectivity } from '../lib/domainSshMonitor.js';
import { createQuoteRecord, signQuoteRecord, getQuoteRecord } from '../lib/quoteSignModule.js';
export async function registerApiRoutes(app) {
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
}
//# sourceMappingURL=api.js.map