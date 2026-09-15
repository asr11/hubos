import { env } from '../config/env.js';
import { db } from '../lib/db.js';
import { requireAuth } from './requireAuth.js';
const ADMIN_CACHE_TTL_MS = 5 * 60 * 1000;
const adminRoleCache = new Map();
function isAdminRole(role) {
    const r = String(role ?? '')
        .trim()
        .toLowerCase();
    return r === 'admin' || r === 'superadmin' || r === 'ceo';
}
function readCachedAdmin(uid) {
    const hit = adminRoleCache.get(uid);
    if (!hit)
        return null;
    if (Date.now() > hit.expiresAt) {
        adminRoleCache.delete(uid);
        return null;
    }
    return hit.isAdmin;
}
function writeCachedAdmin(uid, isAdmin) {
    adminRoleCache.set(uid, {
        isAdmin,
        expiresAt: Date.now() + ADMIN_CACHE_TTL_MS,
    });
}
function adminByEmail(email) {
    if (!email || env.adminEmails.length === 0)
        return false;
    return env.adminEmails.includes(email.trim().toLowerCase());
}
async function readAdminRole(uid) {
    try {
        const user = db.prepare('SELECT role FROM users WHERE id = ?').get(uid);
        return user?.role;
    }
    catch (e) {
        throw e;
    }
}
export async function requireAdmin(req, reply) {
    await requireAuth(req, reply);
    if (reply.sent)
        return;
    const uid = req.authUser?.uid;
    if (!uid) {
        reply.code(401).send({ ok: false, error: 'unauthenticated' });
        return;
    }
    const cached = readCachedAdmin(uid);
    if (cached === false) {
        reply.code(403).send({
            ok: false,
            error: 'forbidden',
            message: 'נדרשת הרשאת מנהל',
        });
        return;
    }
    if (cached === true)
        return;
    if (adminByEmail(req.authUser?.email)) {
        writeCachedAdmin(uid, true);
        return;
    }
    try {
        const role = await readAdminRole(uid);
        const isAdmin = isAdminRole(role);
        writeCachedAdmin(uid, isAdmin);
        if (!isAdmin) {
            reply.code(403).send({
                ok: false,
                error: 'forbidden',
                message: 'נדרשת הרשאת מנהל',
            });
        }
    }
    catch (e) {
        const msg = e instanceof Error ? e.message : String(e);
        if (adminByEmail(req.authUser?.email)) {
            writeCachedAdmin(uid, true);
            return;
        }
        reply.code(503).send({
            ok: false,
            error: 'admin_check_failed',
            message: `בדיקת הרשאות מנהל נכשלה — ${msg}`,
        });
    }
}
//# sourceMappingURL=requireAdmin.js.map