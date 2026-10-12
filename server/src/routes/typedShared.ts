/**
 * typedShared.ts — small typed helpers shared by the typed worker-level routers
 * (documents.ts, ceo.ts, collection.ts). These routers are mounted in worker.ts AFTER the
 * worker JWT middleware, so `c.get('jwtPayload')` is already verified.
 */
import type { Context } from 'hono'
import type { D1Like } from './entities.js'

export type JsonObject = Record<string, unknown>

export type TypedEnv = {
  Bindings: { DB: D1Like }
  Variables: { jwtPayload: unknown }
}

export type TypedCtx = Context<TypedEnv>

export interface AuthUser {
  uid: string
  email: string | null
  role: string
}

export function isPlainObject(v: unknown): v is JsonObject {
  return typeof v === 'object' && v !== null && !Array.isArray(v)
}

export function errMessage(e: unknown): string {
  return e instanceof Error ? e.message : String(e)
}

/** Parses a JSON object stored as TEXT; anything else => {}. */
export function parseJsonObject(raw: unknown): JsonObject {
  if (isPlainObject(raw)) return { ...raw }
  if (typeof raw !== 'string' || raw === '') return {}
  try {
    const parsed: unknown = JSON.parse(raw)
    return isPlainObject(parsed) ? parsed : {}
  } catch {
    return {}
  }
}

/** Parses a JSON array stored as TEXT; anything else => []. */
export function parseJsonArray(raw: unknown): unknown[] {
  if (Array.isArray(raw)) return [...raw]
  if (typeof raw !== 'string' || raw === '') return []
  try {
    const parsed: unknown = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

export function getAuthUser(c: TypedCtx): AuthUser | null {
  const payload = c.get('jwtPayload')
  if (!isPlainObject(payload)) return null
  const uid = payload.uid
  if (typeof uid !== 'string' || uid === '') return null
  return {
    uid,
    email: typeof payload.email === 'string' ? payload.email : null,
    role: typeof payload.role === 'string' ? payload.role : '',
  }
}

export function normRole(role: unknown): string {
  return String(role ?? '').trim().toLowerCase()
}

/** Same set as the frontend isAdminRole() (src/components/RequireAdmin.tsx) and core.ts. */
export const ADMIN_ROLES: ReadonlySet<string> = new Set(['ceo', 'admin', 'superadmin'])

/** Role from the DB (fresh) with fallback to the JWT claim. */
export async function effectiveRole(db: D1Like, auth: AuthUser): Promise<string> {
  const row = await db.prepare('SELECT role FROM users WHERE id = ?').bind(auth.uid).first<{ role: string | null }>()
  return normRole(row?.role ?? auth.role)
}

export function unauthorized(c: TypedCtx): Response {
  return c.json({ ok: false, error: 'unauthorized' }, 401)
}

export function forbidden(c: TypedCtx, message: string): Response {
  return c.json({ ok: false, error: 'forbidden', message }, 403)
}

export function internalError(c: TypedCtx, e: unknown): Response {
  return c.json({ ok: false, error: 'internal_error', message: errMessage(e) }, 500)
}

export type BodyResult = { ok: true; body: JsonObject } | { ok: false; res: Response }

/** Reads a JSON object body. `allowEmpty` => missing/empty body becomes {}. */
export async function readBody(c: TypedCtx, allowEmpty = false): Promise<BodyResult> {
  let text: string
  try {
    text = await c.req.text()
  } catch {
    text = ''
  }
  if (text.trim() === '') {
    if (allowEmpty) return { ok: true, body: {} }
    return { ok: false, res: c.json({ ok: false, error: 'bad_json', message: 'Request body must be a JSON object' }, 400) }
  }
  let parsed: unknown
  try {
    parsed = JSON.parse(text)
  } catch {
    return { ok: false, res: c.json({ ok: false, error: 'bad_json', message: 'Request body must be valid JSON' }, 400) }
  }
  if (!isPlainObject(parsed)) {
    return { ok: false, res: c.json({ ok: false, error: 'bad_json', message: 'Request body must be a JSON object' }, 400) }
  }
  return { ok: true, body: parsed }
}
