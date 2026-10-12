import type { Context, MiddlewareHandler, Next } from 'hono'
import { verify } from 'hono/jwt'

/**
 * Legacy-app auth middleware (Hono).
 *
 * The legacy app is invoked via `backendApp.fetch(c.req.raw, c.env, ...)` from worker.ts, which
 * creates a NEW Hono context — the worker-level `jwtPayload` is not visible here, so the Bearer
 * token is re-verified with the same `JWT_SECRET` binding (same env object) and the same algorithm
 * (HS256) used by `/api/auth/login` in worker.ts.
 *
 * - Missing/invalid token => 401 JSON (never 200).
 * - `await next()` runs OUTSIDE the verification try/catch so downstream handler errors surface
 *   as real errors (app.onError => 500) instead of being masked as `invalid_token`.
 */

export type AuthUser = {
  uid: string
  email?: string
  role?: string
}

type AuthEnv = {
  Bindings: { JWT_SECRET?: string }
  Variables: { authUser: AuthUser }
}

function readBearerToken(c: Context): string | null {
  const h = c.req.header('authorization')
  if (typeof h !== 'string' || !h.startsWith('Bearer ')) return null
  const token = h.slice('Bearer '.length).trim()
  return token || null
}

function readSecret(c: Context): string | null {
  const env = c.env as { JWT_SECRET?: unknown } | undefined
  const s = env?.JWT_SECRET
  return typeof s === 'string' && s.length > 0 ? s : null
}

function str(v: unknown): string | undefined {
  return typeof v === 'string' && v.length > 0 ? v : v == null ? undefined : String(v)
}

/** Verifies the Bearer token; returns the AuthUser or null. Never throws. */
export async function verifyAuthUser(c: Context): Promise<AuthUser | null> {
  const token = readBearerToken(c)
  const secret = readSecret(c)
  if (!token || !secret) return null
  try {
    const payload = (await verify(token, secret, 'HS256')) as Record<string, unknown>
    const uid = str(payload.uid)
    if (!uid) return null
    return { uid, email: str(payload.email), role: str(payload.role) }
  } catch {
    return null
  }
}

export const requireAuth: MiddlewareHandler<AuthEnv> = async (c, next: Next) => {
  if (!readSecret(c)) {
    return c.json({ ok: false, error: 'server_misconfigured', message: 'JWT_SECRET missing' }, 500)
  }
  if (!readBearerToken(c)) {
    return c.json(
      { ok: false, error: 'unauthenticated', message: 'Missing Authorization: Bearer <token>' },
      401,
    )
  }
  const user = await verifyAuthUser(c)
  if (!user) {
    return c.json({ ok: false, error: 'invalid_token', message: 'Invalid or expired token' }, 401)
  }
  c.set('authUser', user)
  await next()
}

/** Role gate; must run after requireAuth. Comparison is case-insensitive. */
export function requireRole(roles: string[]): MiddlewareHandler<AuthEnv> {
  const allowed = new Set(roles.map((r) => r.trim().toLowerCase()))
  return async (c, next: Next) => {
    const role = String(c.get('authUser')?.role ?? '').trim().toLowerCase()
    if (!allowed.has(role)) {
      return c.json({ ok: false, error: 'forbidden', message: 'אין הרשאה לפעולה זו' }, 403)
    }
    await next()
  }
}
