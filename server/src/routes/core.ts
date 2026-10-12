/**
 * core.ts — typed core routes that replace broken legacy handlers
 * (api.ts /api/me, users.ts /api/users, vault.ts /api/passwords_vault, health.ts /api/health).
 *
 * Mount AFTER the JWT middleware and BEFORE the legacy `/api/*` fallthrough:
 *   workerRouter.route('/api', createCoreRouter())
 *
 * Data sources:
 *   - users:           relational `users` table (core) + generic_documents collection 'users' (profile extras)
 *   - passwords_vault: generic_documents collection 'passwords_vault'
 *     (generic_documents columns: id, collection, data, updated_at — there is NO created_at)
 *
 * Security:
 *   - password_hash (and any key containing "password") is NEVER returned for users.
 *   - Vault passwords are masked ('***') unless the caller is a vault admin or listed in visibleUserIds.
 *   - Optional VAULT_ENCRYPTION_KEY (64 hex chars) enables AES-256-CBC, wire-compatible with
 *     the legacy Node implementation in lib/encryption.ts ("<ivHex>:<cipherHex>").
 */
import { Hono } from 'hono'
import type { Context } from 'hono'
import type { D1Like, D1Value } from './entities.js'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------
export type CoreBindings = {
  DB: D1Like
  VAULT_ENCRYPTION_KEY?: string
}

export type CoreEnv = {
  Bindings: CoreBindings
  Variables: { jwtPayload: unknown }
}

type Ctx = Context<CoreEnv>
type JsonObject = Record<string, unknown>

interface AuthUser {
  uid: string
  email: string | null
  role: string
}

interface UserRow {
  id: string
  email: string
  role: string | null
  display_name: string | null
  rate: number | null
  tenant_id: string | null
}

interface DocRow {
  id: string
  collection: string
  data: unknown
  updated_at: unknown
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function isPlainObject(v: unknown): v is JsonObject {
  return typeof v === 'object' && v !== null && !Array.isArray(v)
}

function errMessage(e: unknown): string {
  return e instanceof Error ? e.message : String(e)
}

function parseData(raw: unknown): JsonObject {
  if (isPlainObject(raw)) return { ...raw }
  if (typeof raw !== 'string' || raw === '') return {}
  try {
    const parsed: unknown = JSON.parse(raw)
    return isPlainObject(parsed) ? parsed : {}
  } catch {
    return {}
  }
}

function internalError(c: Ctx, where: string, e: unknown): Response {
  console.error(`[core] ${where}:`, e)
  return c.json({ ok: false, error: 'internal_error', message: errMessage(e) }, 500)
}

function getAuthUser(c: Ctx): AuthUser | null {
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

function unauthorized(c: Ctx): Response {
  return c.json({ ok: false, error: 'unauthorized' }, 401)
}

type BodyResult = { ok: true; body: JsonObject } | { ok: false; res: Response }

async function readBody(c: Ctx): Promise<BodyResult> {
  let parsed: unknown
  try {
    parsed = await c.req.json()
  } catch {
    return { ok: false, res: c.json({ ok: false, error: 'bad_json', message: 'Request body must be valid JSON' }, 400) }
  }
  if (!isPlainObject(parsed)) {
    return { ok: false, res: c.json({ ok: false, error: 'bad_json', message: 'Request body must be a JSON object' }, 400) }
  }
  return { ok: true, body: parsed }
}

function normRole(role: unknown): string {
  return String(role ?? '').trim().toLowerCase()
}

/** Same set as the frontend isAdminRole() (src/components/RequireAdmin.tsx). */
const ADMIN_ROLES = new Set(['ceo', 'admin', 'superadmin'])
/** Frontend ClientDetail.tsx also treats these as vault admins. */
const VAULT_ADMIN_ROLES = new Set([...ADMIN_ROLES, 'technical_admin', 'vault_admin'])

const KNOWN_ROLES = new Set([
  'ceo', 'admin', 'superadmin', 'finance', 'project_manager', 'sales',
  'user', 'employee', 'technical_admin', 'vault_admin',
])

/** Role from the DB (fresh) with fallback to the JWT claim. */
async function effectiveRole(db: D1Like, auth: AuthUser): Promise<string> {
  const row = await db.prepare('SELECT role FROM users WHERE id = ?').bind(auth.uid).first<{ role: string | null }>()
  return normRole(row?.role ?? auth.role)
}

function isSensitiveKey(key: string): boolean {
  return key.toLowerCase().includes('password')
}

function stripSensitive(obj: JsonObject): JsonObject {
  const out: JsonObject = {}
  for (const [k, v] of Object.entries(obj)) {
    if (!isSensitiveKey(k)) out[k] = v
  }
  return out
}

async function selectDoc(db: D1Like, id: string): Promise<DocRow | null> {
  return db.prepare('SELECT id, collection, data, updated_at FROM generic_documents WHERE id = ?').bind(id).first<DocRow>()
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------
function userToApi(row: UserRow, profileRaw: unknown): JsonObject {
  const profile = stripSensitive(parseData(profileRaw))
  delete profile.id
  const fallbackName = row.email ? row.email.split('@')[0] : 'משתמש'
  const name =
    (typeof row.display_name === 'string' && row.display_name.trim() !== '' ? row.display_name : null) ??
    (typeof profile.name === 'string' && profile.name.trim() !== '' ? profile.name : null) ??
    fallbackName
  return {
    ...profile,
    id: row.id,
    uid: row.id,
    email: row.email,
    role: row.role || 'user',
    name,
    rate: row.rate ?? 0,
    tenantId: row.tenant_id ?? null,
    avatar: typeof profile.avatar === 'string' && profile.avatar !== '' ? profile.avatar : name.slice(0, 2),
    color: typeof profile.color === 'string' ? profile.color : '',
    active: typeof profile.active === 'boolean' ? profile.active : true,
  }
}

type ProfileCheck = (v: unknown) => boolean
const isNullableString: ProfileCheck = (v) => v === null || typeof v === 'string'
const isNullableNumber: ProfileCheck = (v) => v === null || (typeof v === 'number' && Number.isFinite(v))

/** Profile fields stored in generic_documents('users'), with validators. */
const PROFILE_FIELDS: Readonly<Record<string, ProfileCheck>> = {
  avatar: (v) => typeof v === 'string',
  color: (v) => typeof v === 'string',
  active: (v) => typeof v === 'boolean',
  scheduleDayStart: isNullableString,
  scheduleDayEnd: isNullableString,
  scheduleBreakMinutes: isNullableNumber,
  monthlyWorkHours: isNullableNumber,
  isAgent: (v) => typeof v === 'boolean',
  agentCommissionPercent: (v) => v === null || (typeof v === 'number' && Number.isFinite(v) && v >= 0 && v <= 100),
}

async function loadUser(db: D1Like, uid: string): Promise<JsonObject | null> {
  const row = await db
    .prepare('SELECT id, email, role, display_name, rate, tenant_id FROM users WHERE id = ?')
    .bind(uid)
    .first<UserRow>()
  if (!row) return null
  const doc = await db
    .prepare("SELECT data FROM generic_documents WHERE collection = 'users' AND id = ?")
    .bind(uid)
    .first<{ data: unknown }>()
  return userToApi(row, doc?.data)
}

function registerUsers(app: Hono<CoreEnv>): void {
  app.get('/users', async (c: Ctx): Promise<Response> => {
    if (!getAuthUser(c)) return unauthorized(c)
    try {
      const { results: users } = await c.env.DB
        .prepare('SELECT id, email, role, display_name, rate, tenant_id FROM users ORDER BY created_at ASC')
        .all<UserRow>()
      if (!Array.isArray(users)) throw new Error('D1 returned no results array for users')
      const { results: docs } = await c.env.DB
        .prepare("SELECT id, data FROM generic_documents WHERE collection = 'users'")
        .all<{ id: string; data: unknown }>()
      if (!Array.isArray(docs)) throw new Error('D1 returned no results array for generic_documents(users)')
      const profiles = new Map<string, unknown>()
      for (const d of docs) profiles.set(d.id, d.data)
      return c.json(users.map((u) => userToApi(u, profiles.get(u.id))), 200)
    } catch (e) {
      return internalError(c, 'GET /users', e)
    }
  })

  app.patch('/users/:uid', async (c: Ctx): Promise<Response> => {
    const auth = getAuthUser(c)
    if (!auth) return unauthorized(c)
    const targetId = c.req.param('uid')
    if (!targetId) return c.json({ ok: false, error: 'bad_request', message: 'missing uid' }, 400)
    const parsed = await readBody(c)
    if (!parsed.ok) return parsed.res
    const body = parsed.body
    const db = c.env.DB

    try {
      const callerRole = await effectiveRole(db, auth)
      const callerIsAdmin = ADMIN_ROLES.has(callerRole)

      // Non-admins may only edit their own profile.
      if (!callerIsAdmin && targetId !== auth.uid) {
        return c.json({ ok: false, error: 'forbidden', message: 'only admins may edit other users' }, 403)
      }
      // Only admins may change role / active.
      if (!callerIsAdmin && ('role' in body || 'active' in body)) {
        return c.json({ ok: false, error: 'forbidden', message: 'only ceo/admin/superadmin may change role or active' }, 403)
      }

      const existing = await db.prepare('SELECT id, role FROM users WHERE id = ?').bind(targetId).first<{ id: string; role: string | null }>()
      if (!existing) return c.json({ ok: false, error: 'not_found', message: `user ${targetId} not found` }, 404)

      // ---- validate ----
      const coreSets: string[] = []
      const coreVals: D1Value[] = []
      const profilePatch: JsonObject = {}
      const ignored: string[] = []

      for (const [key, value] of Object.entries(body)) {
        if (key === 'role') {
          const r = normRole(value)
          if (!KNOWN_ROLES.has(r)) return c.json({ ok: false, error: 'bad_request', message: `unknown role "${String(value)}"` }, 400)
          if (targetId === auth.uid && r !== normRole(existing.role)) {
            return c.json({ ok: false, error: 'forbidden', message: 'cannot change your own role' }, 403)
          }
          coreSets.push('role = ?')
          coreVals.push(r)
          continue
        }
        if (key === 'name' || key === 'displayName' || key === 'display_name') {
          if (typeof value !== 'string' || value.trim() === '') {
            return c.json({ ok: false, error: 'bad_request', message: '"name" must be a non-empty string' }, 400)
          }
          coreSets.push('display_name = ?')
          coreVals.push(value.trim())
          continue
        }
        const check = PROFILE_FIELDS[key]
        if (check) {
          if (!check(value)) return c.json({ ok: false, error: 'bad_request', message: `invalid value for "${key}"` }, 400)
          profilePatch[key] = value
          continue
        }
        ignored.push(key)
      }

      if (coreSets.length === 0 && Object.keys(profilePatch).length === 0) {
        return c.json({ ok: false, error: 'bad_request', message: 'no updatable fields provided', ignored }, 400)
      }

      // ---- profile doc upsert (generic_documents, collection 'users') ----
      if (Object.keys(profilePatch).length > 0) {
        const doc = await selectDoc(db, targetId)
        if (doc && doc.collection !== 'users') {
          return c.json({ ok: false, error: 'conflict', message: `id ${targetId} is used by another collection` }, 409)
        }
        const merged: JsonObject = { ...stripSensitive(parseData(doc?.data)), ...profilePatch }
        delete merged.id
        if (doc) {
          await db
            .prepare("UPDATE generic_documents SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE collection = 'users' AND id = ?")
            .bind(JSON.stringify(merged), targetId)
            .run()
        } else {
          await db
            .prepare("INSERT INTO generic_documents (id, collection, data, updated_at) VALUES (?, 'users', ?, CURRENT_TIMESTAMP)")
            .bind(targetId, JSON.stringify(merged))
            .run()
        }
      }

      // ---- core columns ----
      if (coreSets.length > 0) {
        await db.prepare(`UPDATE users SET ${coreSets.join(', ')} WHERE id = ?`).bind(...coreVals, targetId).run()
      }

      const user = await loadUser(db, targetId)
      if (!user) throw new Error(`user ${targetId} vanished during PATCH`)
      return c.json({ ok: true, user, ignored }, 200)
    } catch (e) {
      return internalError(c, 'PATCH /users/:uid', e)
    }
  })
}

// ---------------------------------------------------------------------------
// Vault crypto (optional; AES-256-CBC, format "<ivHex>:<cipherHex>")
// ---------------------------------------------------------------------------
type VaultKey = Awaited<ReturnType<typeof crypto.subtle.importKey>>
const ENC_FORMAT = /^[0-9a-f]{32}:(?:[0-9a-f]{32})+$/

function hexToBytes(hex: string): Uint8Array {
  const out = new Uint8Array(hex.length / 2)
  for (let i = 0; i < out.length; i++) out[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16)
  return out
}

function bytesToHex(bytes: Uint8Array): string {
  let s = ''
  for (const b of bytes) s += b.toString(16).padStart(2, '0')
  return s
}

async function getVaultKey(env: CoreBindings): Promise<VaultKey | null> {
  const k = env.VAULT_ENCRYPTION_KEY
  if (!k) return null
  if (!/^[0-9a-fA-F]{64}$/.test(k)) throw new Error('VAULT_ENCRYPTION_KEY is invalid (expected 64 hex chars)')
  return crypto.subtle.importKey('raw', hexToBytes(k.toLowerCase()), { name: 'AES-CBC' }, false, ['encrypt', 'decrypt'])
}

async function encryptPassword(key: VaultKey | null, plain: string): Promise<string> {
  if (!key || plain === '') return plain
  const iv = crypto.getRandomValues(new Uint8Array(16))
  const ct = await crypto.subtle.encrypt({ name: 'AES-CBC', iv }, key, new TextEncoder().encode(plain))
  return `${bytesToHex(iv)}:${bytesToHex(new Uint8Array(ct))}`
}

/** Returns plaintext when possible; otherwise the stored value with `encrypted: true`. */
async function decryptPassword(key: VaultKey | null, stored: string): Promise<{ value: string; encrypted: boolean }> {
  if (!ENC_FORMAT.test(stored)) return { value: stored, encrypted: false }
  if (!key) return { value: stored, encrypted: true }
  const [ivHex, ctHex] = stored.split(':')
  try {
    const pt = await crypto.subtle.decrypt({ name: 'AES-CBC', iv: hexToBytes(ivHex) }, key, hexToBytes(ctHex))
    return { value: new TextDecoder().decode(pt), encrypted: false }
  } catch {
    return { value: stored, encrypted: true }
  }
}

// ---------------------------------------------------------------------------
// Passwords vault
// ---------------------------------------------------------------------------
const VAULT = 'passwords_vault'
const MASK = '***'

function visibleIds(data: JsonObject): string[] {
  const v = data.visibleUserIds
  return Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : []
}

function canAccessEntry(isVaultAdmin: boolean, uid: string, data: JsonObject): boolean {
  return isVaultAdmin || visibleIds(data).includes(uid)
}

async function selectVaultDoc(db: D1Like, id: string): Promise<DocRow | null> {
  return db
    .prepare('SELECT id, collection, data, updated_at FROM generic_documents WHERE collection = ? AND id = ?')
    .bind(VAULT, id)
    .first<DocRow>()
}

/** Strips identity keys from a client body. */
function cleanVaultBody(body: JsonObject): JsonObject {
  const out: JsonObject = { ...body }
  delete out.id
  delete out.updatedAt
  delete out.passwordEncrypted
  return out
}

/** Legacy rule: a new password is written only when non-empty and not the mask. */
async function resolvePassword(key: VaultKey | null, incoming: unknown, existing: unknown): Promise<unknown> {
  if (typeof incoming === 'string' && incoming !== '' && incoming !== MASK) return encryptPassword(key, incoming)
  return existing
}

function registerVault(app: Hono<CoreEnv>): void {
  const base = `/${VAULT}`
  const item = `/${VAULT}/:id`

  async function callerContext(c: Ctx): Promise<{ auth: AuthUser; isVaultAdmin: boolean } | null> {
    const auth = getAuthUser(c)
    if (!auth) return null
    const role = await effectiveRole(c.env.DB, auth)
    return { auth, isVaultAdmin: VAULT_ADMIN_ROLES.has(role) }
  }

  app.get(base, async (c: Ctx): Promise<Response> => {
    try {
      const ctx = await callerContext(c)
      if (!ctx) return unauthorized(c)
      const key = await getVaultKey(c.env)
      const { results } = await c.env.DB
        .prepare('SELECT id, data, updated_at FROM generic_documents WHERE collection = ? ORDER BY updated_at DESC')
        .bind(VAULT)
        .all<{ id: string; data: unknown; updated_at: unknown }>()
      if (!Array.isArray(results)) throw new Error('D1 returned no results array for passwords_vault')
      const list: JsonObject[] = []
      for (const r of results) {
        const data = parseData(r.data)
        const canView = canAccessEntry(ctx.isVaultAdmin, ctx.auth.uid, data)
        const entry: JsonObject = { ...data, id: r.id, createdAt: data.createdAt ?? r.updated_at ?? null, updatedAt: r.updated_at ?? null }
        const stored = typeof data.password === 'string' ? data.password : ''
        if (!canView) {
          entry.password = MASK
        } else if (stored === '') {
          entry.password = ''
        } else {
          const dec = await decryptPassword(key, stored)
          entry.password = dec.value
          if (dec.encrypted) entry.passwordEncrypted = true
        }
        list.push(entry)
      }
      return c.json(list, 200)
    } catch (e) {
      return internalError(c, `GET ${base}`, e)
    }
  })

  app.post(base, async (c: Ctx): Promise<Response> => {
    const parsed = await readBody(c)
    if (!parsed.ok) return parsed.res
    try {
      const ctx = await callerContext(c)
      if (!ctx) return unauthorized(c)
      const key = await getVaultKey(c.env)
      const data = cleanVaultBody(parsed.body)
      data.password = typeof data.password === 'string' && data.password !== MASK ? await encryptPassword(key, data.password) : ''
      let vis = [...new Set(visibleIds(data))]
      if (!ctx.isVaultAdmin && !vis.includes(ctx.auth.uid)) vis = [...vis, ctx.auth.uid]
      data.visibleUserIds = vis
      if (typeof data.createdAt !== 'string' || data.createdAt === '') data.createdAt = new Date().toISOString()
      const id = `vault_${crypto.randomUUID()}`
      await c.env.DB
        .prepare('INSERT INTO generic_documents (id, collection, data, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)')
        .bind(id, VAULT, JSON.stringify(data))
        .run()
      return c.json({ ok: true, id }, 201)
    } catch (e) {
      return internalError(c, `POST ${base}`, e)
    }
  })

  async function update(c: Ctx, mode: 'merge' | 'replace'): Promise<Response> {
    const id = c.req.param('id')
    if (!id) return c.json({ ok: false, error: 'bad_request', message: 'missing id' }, 400)
    const parsed = await readBody(c)
    if (!parsed.ok) return parsed.res
    try {
      const ctx = await callerContext(c)
      if (!ctx) return unauthorized(c)
      const row = await selectVaultDoc(c.env.DB, id)
      if (!row) return c.json({ ok: false, error: 'not_found' }, 404)
      const existing = parseData(row.data)
      if (!canAccessEntry(ctx.isVaultAdmin, ctx.auth.uid, existing)) {
        return c.json({ ok: false, error: 'forbidden', message: 'permission denied' }, 403)
      }
      const key = await getVaultKey(c.env)
      const incoming = cleanVaultBody(parsed.body)
      const next: JsonObject = mode === 'merge' ? { ...existing, ...incoming } : { ...incoming }
      next.password = await resolvePassword(key, incoming.password, existing.password)
      if (next.password === undefined) delete next.password
      if (next.createdAt === undefined && existing.createdAt !== undefined) next.createdAt = existing.createdAt
      await c.env.DB
        .prepare('UPDATE generic_documents SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE collection = ? AND id = ?')
        .bind(JSON.stringify(next), VAULT, id)
        .run()
      return c.json({ ok: true, id }, 200)
    } catch (e) {
      return internalError(c, `${mode === 'merge' ? 'PATCH' : 'PUT'} ${item}`, e)
    }
  }

  app.patch(item, (c: Ctx) => update(c, 'merge'))
  app.put(item, (c: Ctx) => update(c, 'replace'))

  app.delete(item, async (c: Ctx): Promise<Response> => {
    const id = c.req.param('id')
    if (!id) return c.json({ ok: false, error: 'bad_request', message: 'missing id' }, 400)
    try {
      const ctx = await callerContext(c)
      if (!ctx) return unauthorized(c)
      const row = await selectVaultDoc(c.env.DB, id)
      if (!row) return c.json({ ok: false, error: 'not_found' }, 404)
      if (!canAccessEntry(ctx.isVaultAdmin, ctx.auth.uid, parseData(row.data))) {
        return c.json({ ok: false, error: 'forbidden', message: 'permission denied' }, 403)
      }
      await c.env.DB.prepare('DELETE FROM generic_documents WHERE collection = ? AND id = ?').bind(VAULT, id).run()
      return c.json({ ok: true, id, deleted: true }, 200)
    } catch (e) {
      return internalError(c, `DELETE ${item}`, e)
    }
  })
}

// ---------------------------------------------------------------------------
// Public factory
// ---------------------------------------------------------------------------
export function createCoreRouter(): Hono<CoreEnv> {
  const app = new Hono<CoreEnv>()

  app.get('/health', (c: Ctx) => c.json({ ok: true, ts: new Date().toISOString() }, 200))

  app.get('/me', (c: Ctx) => {
    const auth = getAuthUser(c)
    if (!auth) return unauthorized(c)
    return c.json({ ok: true, uid: auth.uid, email: auth.email, role: auth.role }, 200)
  })

  registerUsers(app)
  registerVault(app)
  return app
}
