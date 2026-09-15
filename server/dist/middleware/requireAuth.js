import jwt from 'jsonwebtoken';
function readBearerToken(req) {
    const h = req.headers.authorization;
    if (typeof h !== 'string' || !h.startsWith('Bearer '))
        return null;
    const token = h.slice('Bearer '.length).trim();
    return token || null;
}
export async function requireAuth(req, reply) {
    const token = readBearerToken(req);
    if (!token) {
        reply.code(401).send({
            ok: false,
            error: 'unauthenticated',
            message: 'Missing Authorization: Bearer <token>',
        });
        return;
    }
    try {
        const decoded = jwt.verify(token, process.env.JWT_SECRET || 'super-secret');
        req.authUser = {
            uid: decoded.uid,
            email: decoded.email,
            role: decoded.role,
        };
    }
    catch {
        reply.code(401).send({
            ok: false,
            error: 'invalid_token',
            message: 'Invalid or expired token',
        });
    }
}
//# sourceMappingURL=requireAuth.js.map