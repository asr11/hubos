import { requireAuth } from '../middleware/requireAuth.js';
import { db } from '../lib/db.js';
import { encrypt, decrypt } from '../lib/encryption.js';
export async function registerVaultRoutes(app) {
    const collection = 'passwords_vault';
    // GET all passwords
    app.get('/api/passwords_vault', { preHandler: requireAuth }, async (req) => {
        const rows = db.prepare('SELECT id, data, created_at, updated_at FROM generic_documents WHERE collection = ?').all(collection);
        const uid = req.authUser.uid;
        const isAdmin = req.authUser.role === 'admin';
        return rows.map((r) => {
            const data = JSON.parse(r.data);
            const visibleUsers = data.visibleUserIds || [];
            const canView = isAdmin || visibleUsers.includes(uid);
            return {
                id: r.id,
                ...data,
                password: canView && data.password ? decrypt(data.password) : '***',
                createdAt: r.created_at,
                updatedAt: r.updated_at,
            };
        });
    });
    // POST new password
    app.post('/api/passwords_vault', { preHandler: requireAuth }, async (req, reply) => {
        const data = req.body;
        if (data.password) {
            data.password = encrypt(data.password);
        }
        const id = `vault_${Math.random().toString(36).substr(2, 9)}`;
        try {
            db.prepare('INSERT INTO generic_documents (collection, id, data) VALUES (?, ?, ?)')
                .run(collection, id, JSON.stringify(data));
            return { ok: true, id };
        }
        catch (e) {
            reply.status(500);
            return { ok: false, error: e.message };
        }
    });
    // PUT update password
    app.put('/api/passwords_vault/:id', { preHandler: requireAuth }, async (req, reply) => {
        const { id } = req.params;
        const data = req.body;
        const uid = req.authUser.uid;
        const isAdmin = req.authUser.role === 'admin';
        // Check permissions before update
        const row = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?').get(collection, id);
        if (!row) {
            reply.status(404);
            return { ok: false, error: 'Not found' };
        }
        const existingData = JSON.parse(row.data);
        const canEdit = isAdmin || (existingData.visibleUserIds && existingData.visibleUserIds.includes(uid));
        if (!canEdit) {
            reply.status(403);
            return { ok: false, error: 'Permission denied' };
        }
        // Only encrypt if it's a new password being set (not empty or just stars)
        if (data.password && data.password !== '***') {
            data.password = encrypt(data.password);
        }
        else {
            data.password = existingData.password; // keep existing encrypted password
        }
        try {
            db.prepare('UPDATE generic_documents SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE collection = ? AND id = ?')
                .run(JSON.stringify(data), collection, id);
            return { ok: true };
        }
        catch (e) {
            reply.status(500);
            return { ok: false, error: e.message };
        }
    });
    // DELETE password
    app.delete('/api/passwords_vault/:id', { preHandler: requireAuth }, async (req, reply) => {
        const { id } = req.params;
        const uid = req.authUser.uid;
        const isAdmin = req.authUser.role === 'admin';
        const row = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?').get(collection, id);
        if (!row)
            return { ok: true };
        const existingData = JSON.parse(row.data);
        const canEdit = isAdmin || (existingData.visibleUserIds && existingData.visibleUserIds.includes(uid));
        if (!canEdit) {
            reply.status(403);
            return { ok: false, error: 'Permission denied' };
        }
        try {
            db.prepare('DELETE FROM generic_documents WHERE collection = ? AND id = ?').run(collection, id);
            return { ok: true };
        }
        catch (e) {
            reply.status(500);
            return { ok: false, error: e.message };
        }
    });
}
//# sourceMappingURL=vault.js.map