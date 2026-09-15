// @ts-nocheck
import { timingSafeEqual } from 'node:crypto';
import { env } from '../config/env.js';
export function ok(status, body) {
    return { status, body };
}
export function badRequest(errors, extra) {
    const list = Array.isArray(errors) ? errors : [errors];
    return { status: 400, body: { ok: false, error: 'validation_error', errors: list, ...extra } };
}
export function unauthorized() {
    return { status: 401, body: { ok: false, error: 'unauthorized' } };
}
export function notFound(entity, id) {
    return { status: 404, body: { ok: false, error: 'not_found', entity, id } };
}
function headerFirstValue(headers, name) {
    const raw = headers[name.toLowerCase()];
    if (typeof raw === 'string' && raw.trim())
        return raw.trim();
    if (Array.isArray(raw)) {
        for (const part of raw) {
            if (typeof part === 'string' && part.trim())
                return part.trim();
        }
    }
    return '';
}
export function readApiKeyFromHeaders(headers) {
    const custom = headerFirstValue(headers, 'x-hub-api-key');
    if (custom)
        return custom;
    const auth = headerFirstValue(headers, 'authorization');
    if (!auth)
        return '';
    const bearer = auth.match(/^Bearer\s+(.+)$/i);
    if (bearer?.[1]?.trim())
        return bearer[1].trim();
    return auth;
}
import jwt from 'jsonwebtoken';
export function isValidApiKey(headers) {
    const provided = readApiKeyFromHeaders(headers);
    if (!provided)
        return false;
    const expected = env.botApiKey || process.env.BOT_API_KEY;
    if (expected) {
        const a = Buffer.from(provided, 'utf8');
        const b = Buffer.from(expected, 'utf8');
        if (a.length === b.length && timingSafeEqual(a, b)) {
            return true;
        }
    }
    // Also accept valid JWT bearer token from logged-in admin / CEO
    try {
        const secret = process.env.JWT_SECRET || 'super-secret';
        const decoded = jwt.verify(provided, secret);
        if (decoded && decoded.uid) {
            return true;
        }
    }
    catch {
        // not a valid JWT
    }
    return false;
}
export function parseEntity(query) {
    const raw = (typeof query.entity === 'string' && query.entity) || (typeof query.resource === 'string' && query.resource) || '';
    return raw.trim().toLowerCase();
}
export function isSchemaCatalogRequest(query) {
    const meta = query.meta;
    return typeof meta === 'string' && meta.trim().toLowerCase() === 'schema';
}
export function parseId(query) {
    return typeof query.id === 'string' ? query.id.trim() : '';
}
export function parseSub(query) {
    return typeof query.sub === 'string' ? query.sub.trim() : '';
}
export function parseLimit(query, max = 200, fallback = 100) {
    const raw = query.limit;
    if (typeof raw === 'number' && Number.isFinite(raw))
        return Math.max(1, Math.min(max, Math.floor(raw)));
    if (typeof raw === 'string') {
        const n = Number.parseInt(raw.trim(), 10);
        if (Number.isFinite(n))
            return Math.max(1, Math.min(max, n));
    }
    return fallback;
}
export function parseBody(body) {
    if (body && typeof body === 'object' && !Array.isArray(body))
        return body;
    return {};
}
export function parseDataPayload(body) {
    const data = body.data;
    if (data && typeof data === 'object' && !Array.isArray(data))
        return data;
    return body;
}
export function parseActor(body) {
    const a = body.actor;
    if (a && typeof a === 'object' && !Array.isArray(a)) {
        const o = a;
        const userId = typeof o.userId === 'string' ? o.userId.trim() : '';
        if (userId) {
            return { userId, userName: typeof o.userName === 'string' ? o.userName.trim() : undefined };
        }
    }
    const uid = typeof body.actorUserId === 'string' ? body.actorUserId.trim() : '';
    if (uid) {
        return { userId: uid, userName: typeof body.actorUserName === 'string' ? body.actorUserName.trim() : undefined };
    }
    return null;
}
export function stripUndefined(obj) {
    const out = {};
    for (const [k, v] of Object.entries(obj)) {
        if (v !== undefined)
            out[k] = v;
    }
    return out;
}
export function serializeDoc(id, data) {
    const out = { id };
    if (!data || typeof data !== 'object')
        return out;
    for (const [k, v] of Object.entries(data)) {
        if (v && typeof v.toDate === 'function') {
            out[k] = v.toDate().toISOString();
        }
        else if (v instanceof Date) {
            out[k] = v.toISOString();
        }
        else {
            out[k] = v;
        }
    }
    return out;
}
export function requireString(data, field, label) {
    const v = data[field];
    if (typeof v !== 'string' || !v.trim()) {
        return { errors: [`${label ?? field} — שדה חובה (מחרוזת לא ריקה)`] };
    }
    return { value: v.trim(), errors: [] };
}
export function optionalString(data, field) {
    const v = data[field];
    return typeof v === 'string' && v.trim() ? v.trim() : undefined;
}
export function requireEnum(data, field, allowed, label) {
    const v = data[field];
    if (typeof v !== 'string' || !allowed.includes(v)) {
        return { errors: [`${label ?? field} — חייב להיות אחד מ: ${allowed.join(', ')}`] };
    }
    return { value: v, errors: [] };
}
export function requireYmd(data, field, label) {
    const r = requireString(data, field, label);
    if (r.errors.length || !r.value)
        return r;
    if (!/^\d{4}-\d{2}-\d{2}/.test(r.value)) {
        return { errors: [`${label ?? field} — פורמט תאריך חובה YYYY-MM-DD`] };
    }
    return { value: r.value.slice(0, 10), errors: [] };
}
export function requireNumber(data, field, label, min = 0) {
    const v = data[field];
    const n = typeof v === 'number' ? v : typeof v === 'string' ? Number(v) : Number.NaN;
    if (!Number.isFinite(n) || n < min) {
        return { errors: [`${label ?? field} — מספר חובה${min > 0 ? ` (מינימום ${min})` : ''}`] };
    }
    return { value: n, errors: [] };
}
export function mergeErrors(...parts) {
    return parts.flatMap((p) => p.errors);
}
export function okData(data) {
    return { ok: true, data };
}
export function failErrors(errors) {
    return { ok: false, errors };
}
//# sourceMappingURL=hubApiHttpUtil.js.map