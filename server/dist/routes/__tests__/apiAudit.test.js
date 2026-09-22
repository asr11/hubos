import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { buildApp } from '../../app.js';
describe('Backend API End-to-End Audit', () => {
    let app;
    let token;
    beforeAll(async () => {
        app = await buildApp();
        await app.ready();
        const loginRes = await app.inject({
            method: 'POST',
            url: '/api/auth/login',
            payload: { email: 'a0527777817@gmail.com', password: '123456' },
        });
        expect(loginRes.statusCode).toBe(200);
        const body = JSON.parse(loginRes.body);
        expect(body.ok).toBe(true);
        token = body.token;
    });
    afterAll(async () => {
        await app.close();
    });
    it('GET /api/health should return 200 OK', async () => {
        const res = await app.inject({ method: 'GET', url: '/api/health' });
        expect(res.statusCode).toBe(200);
        const body = JSON.parse(res.body);
        expect(body.ok).toBe(true);
        expect(body.service).toBe('hub-pos-server');
    });
    it('GET /api/me should return authenticated user details', async () => {
        const res = await app.inject({
            method: 'GET',
            url: '/api/me',
            headers: { authorization: `Bearer ${token}` },
        });
        expect(res.statusCode).toBe(200);
        const body = JSON.parse(res.body);
        expect(body.ok).toBe(true);
        expect(body.email).toBe('a0527777817@gmail.com');
    });
    it('GET /api/clients should return clients from generic_documents', async () => {
        const res = await app.inject({
            method: 'GET',
            url: '/api/clients',
            headers: { authorization: `Bearer ${token}` },
        });
        expect(res.statusCode).toBe(200);
        const items = JSON.parse(res.body);
        expect(Array.isArray(items)).toBe(true);
    });
    it('Finance Agent endpoints should return 200 OK', async () => {
        const contractRes = await app.inject({
            method: 'GET',
            url: '/api/finance-agent/contract',
            headers: { authorization: `Bearer ${token}` },
        });
        expect(contractRes.statusCode).toBe(200);
        expect(JSON.parse(contractRes.body).ok).toBe(true);
        const invRes = await app.inject({
            method: 'GET',
            url: '/api/finance-agent/inventory',
            headers: { authorization: `Bearer ${token}` },
        });
        expect(invRes.statusCode).toBe(200);
        const invBody = JSON.parse(invRes.body);
        expect(invBody.ok).toBe(true);
        expect(Array.isArray(invBody.months)).toBe(true);
        const expRes = await app.inject({
            method: 'GET',
            url: '/api/finance-agent/expenses?limit=5',
            headers: { authorization: `Bearer ${token}` },
        });
        expect(expRes.statusCode).toBe(200);
        expect(JSON.parse(expRes.body).ok).toBe(true);
        // Write attempts must return 405 Method Not Allowed
        const postRes = await app.inject({
            method: 'POST',
            url: '/api/finance-agent/expenses',
            headers: { authorization: `Bearer ${token}` },
            payload: { amount: 100 },
        });
        expect(postRes.statusCode).toBe(405);
    });
    it('Bot API endpoints should return 200 OK via SQLite adapter', async () => {
        const catalogRes = await app.inject({
            method: 'GET',
            url: '/api/bot-data?meta=schema',
            headers: { authorization: `Bearer ${token}` },
        });
        expect(catalogRes.statusCode).toBe(200);
        const catalogBody = JSON.parse(catalogRes.body);
        expect(catalogBody.ok).toBe(true);
        const botClientsRes = await app.inject({
            method: 'GET',
            url: '/api/bot-data?entity=clients&limit=5',
            headers: { authorization: `Bearer ${token}` },
        });
        expect(botClientsRes.statusCode).toBe(200);
        const clientsBody = JSON.parse(botClientsRes.body);
        expect(clientsBody.ok).toBe(true);
        expect(clientsBody.entity).toBe('clients');
    });
    it('Quote flow (create, sign, get) should work with 200 OK', async () => {
        const createRes = await app.inject({
            method: 'POST',
            url: '/api/quotes/create',
            headers: { authorization: `Bearer ${token}` },
            payload: { totalAmount: 1200 },
        });
        expect(createRes.statusCode).toBe(200);
        const quote = JSON.parse(createRes.body);
        expect(quote.ok).toBe(true);
        expect(quote.id).toBeDefined();
        const signRes = await app.inject({
            method: 'POST',
            url: '/api/quotes/sign',
            headers: { authorization: `Bearer ${token}` },
            payload: { quoteId: quote.id, signatureData: 'sig-data' },
        });
        expect(signRes.statusCode).toBe(200);
        expect(JSON.parse(signRes.body).ok).toBe(true);
        const getRes = await app.inject({
            method: 'GET',
            url: `/api/quotes/${quote.id}`,
            headers: { authorization: `Bearer ${token}` },
        });
        expect(getRes.statusCode).toBe(200);
        const fetched = JSON.parse(getRes.body);
        expect(fetched.id).toBe(quote.id);
        expect(fetched.status).toBe('signed');
    });
    it('Neural agents endpoints should return 200 OK', async () => {
        const nodesRes = await app.inject({
            method: 'GET',
            url: '/api/neural-agents/nodes',
        });
        expect(nodesRes.statusCode).toBe(200);
        expect(JSON.parse(nodesRes.body).ok).toBe(true);
        const logsRes = await app.inject({
            method: 'GET',
            url: '/api/neural-agents/logs',
        });
        expect(logsRes.statusCode).toBe(200);
        expect(JSON.parse(logsRes.body).ok).toBe(true);
    });
});
//# sourceMappingURL=apiAudit.test.js.map