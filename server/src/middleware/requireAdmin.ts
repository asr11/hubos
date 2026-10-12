import type { MiddlewareHandler, Next } from 'hono'
import { verifyAuthUser, type AuthUser } from './requireAuth.js'

/**
 * Legacy-app admin gate (Hono middleware). Verifies the Bearer token itself (so it can be used
 * standalone or after requireAuth) and allows only admin-level roles taken from the JWT payload
 * (role is written from the users table at login in worker.ts).
 *
 * - Missing/invalid token => 401, non-admin role => 403.
 * - `await next()` runs outside any try/catch so handler errors surface via app.onError.
 *
 * NOTE: routes in morning.ts / financeDocuments.ts still pass Fastify-style
 * `{ preHandler: requireAdmin }` objects as the 2nd argument — those are not valid Hono handlers
 * and must be refactored separately to `app.get(path, requireAdmin, handler)`.
 */

const ADMIN_ROLES = new Set(['ceo', 'admin', 'superadmin', 'technical_admin'])

export function isAdminRole(role: unknown): boolean {
  return ADMIN_ROLES.has(String(role ?? '').trim().toLowerCase())
}

type AdminEnv = {
  Bindings: { JWT_SECRET?: string }
  Variables: { authUser: AuthUser }
}

export const requireAdmin: MiddlewareHandler<AdminEnv> = async (c, next: Next) => {
  const user = c.get('authUser') ?? (await verifyAuthUser(c))
  if (!user) {
    return c.json({ ok: false, error: 'unauthenticated', message: 'Invalid or missing token' }, 401)
  }
  if (!isAdminRole(user.role)) {
    return c.json({ ok: false, error: 'forbidden', message: 'נדרשת הרשאת מנהל' }, 403)
  }
  c.set('authUser', user)
  await next()
}
