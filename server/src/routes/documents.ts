/**
 * documents.ts — typed `/api/documents/:collection[/:id]` router over `generic_documents`.
 *
 * Mount AFTER the worker JWT middleware and BEFORE the legacy `/api/*` fallthrough:
 *   workerRouter.route('/api', createDocumentsRouter())
 *
 * Frontend contracts (src/services/api.ts documentsApi/financeApi, SmartCombobox.tsx,
 * TimerContext.tsx):
 *   GET    /api/documents/:collection        => array of { id, ...data }
 *   GET    /api/documents/:collection/:id    => { id, ...data } | 404
 *   POST   /api/documents/:collection        => { id, ...data } (201)
 *   PATCH  /api/documents/:collection/:id    => merged { id, ...data } | 404
 *   PUT    /api/documents/:collection/:id    => same as PATCH (merge)
 *   DELETE /api/documents/:collection/:id    => { ok: true, id } | 404
 *
 * Security:
 *   - Collections are whitelisted. Unknown collection => 400 (no arbitrary collection access,
 *     e.g. 'integrations' which holds Morning credentials, or 'passwords_vault').
 *   - Financial collections + clients are READ-ONLY here (405 on writes): the frontend never writes
 *     them through this path, and the source of truth for invoices is Morning (read-only policy).
 *   - `generic_documents.id` is a global PRIMARY KEY, so every write is scoped by
 *     `collection = ? AND id = ?`; a POST whose id already exists in ANY collection => 409
 *     (never overwrites a document of another collection).
 *
 * Columns: generic_documents(id TEXT PK, collection TEXT, data JSON, updated_at DATETIME).
 */
import { Hono } from 'hono'
import {
  type JsonObject,
  type TypedCtx,
  type TypedEnv,
  getAuthUser,
  internalError,
  parseJsonObject,
  readBody,
  unauthorized,
} from './typedShared.js'

/** Collections the frontend reads via /api/documents (grep src for '/api/documents/'). */
export const DOCUMENT_READ_COLLECTIONS: ReadonlySet<string> = new Set([
  'invoices',
  'expenses',
  'collections',
  'hours_banks',
  'time_logs',
  'clients',
])

/** Collections the frontend writes via /api/documents (TimerContext POST time_logs; hours banks). */
export const DOCUMENT_WRITE_COLLECTIONS: ReadonlySet<string> = new Set(['hours_banks', 'time_logs'])

const MAX_ID_LENGTH = 200

interface DocRow {
  id: string
  data: unknown
}

function toDoc(row: DocRow): JsonObject {
  return { ...parseJsonObject(row.data), id: row.id }
}

type Gate = { ok: true; collection: string } | { ok: false; res: Response }

function gate(c: TypedCtx, write: boolean): Gate {
  if (!getAuthUser(c)) return { ok: false, res: unauthorized(c) }
  const collection = c.req.param('collection') ?? ''
  if (!DOCUMENT_READ_COLLECTIONS.has(collection)) {
    return {
      ok: false,
      res: c.json({ ok: false, error: 'invalid_collection', message: `collection "${collection}" is not allowed` }, 400),
    }
  }
  if (write && !DOCUMENT_WRITE_COLLECTIONS.has(collection)) {
    return {
      ok: false,
      res: c.json({ ok: false, error: 'read_only_collection', message: `collection "${collection}" is read-only via /api/documents` }, 405),
    }
  }
  return { ok: true, collection }
}

function validId(id: unknown): id is string {
  return typeof id === 'string' && id.trim() !== '' && id.length <= MAX_ID_LENGTH
}

/** Strips keys that must never be persisted inside `data`. */
function cleanData(body: JsonObject): JsonObject {
  const out: JsonObject = { ...body }
  delete out.id
  return out
}

export function createDocumentsRouter(): Hono<TypedEnv> {
  const r = new Hono<TypedEnv>()

  r.get('/documents/:collection', async (c) => {
    const g = gate(c, false)
    if (!g.ok) return g.res
    try {
      const { results } = await c.env.DB
        .prepare('SELECT id, data FROM generic_documents WHERE collection = ? ORDER BY updated_at DESC')
        .bind(g.collection)
        .all<DocRow>()
      return c.json((results ?? []).map(toDoc), 200)
    } catch (e) {
      return internalError(c, e)
    }
  })

  r.get('/documents/:collection/:id', async (c) => {
    const g = gate(c, false)
    if (!g.ok) return g.res
    const id = c.req.param('id')
    try {
      const row = await c.env.DB
        .prepare('SELECT id, data FROM generic_documents WHERE collection = ? AND id = ?')
        .bind(g.collection, id)
        .first<DocRow>()
      if (!row) return c.json({ ok: false, error: 'not_found' }, 404)
      return c.json(toDoc(row), 200)
    } catch (e) {
      return internalError(c, e)
    }
  })

  r.post('/documents/:collection', async (c) => {
    const g = gate(c, true)
    if (!g.ok) return g.res
    const b = await readBody(c)
    if (!b.ok) return b.res
    const rawId = b.body.id
    if (rawId !== undefined && rawId !== null && rawId !== '' && !validId(rawId)) {
      return c.json({ ok: false, error: 'bad_request', message: 'id must be a non-empty string (max 200 chars)' }, 400)
    }
    const id = validId(rawId) ? rawId : crypto.randomUUID()
    const data = cleanData(b.body)
    if (typeof data.createdAt !== 'string') data.createdAt = new Date().toISOString()
    try {
      const exists = await c.env.DB.prepare('SELECT collection FROM generic_documents WHERE id = ?').bind(id).first<{ collection: string }>()
      if (exists) return c.json({ ok: false, error: 'conflict', message: 'id already exists' }, 409)
      await c.env.DB
        .prepare('INSERT INTO generic_documents (id, collection, data, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)')
        .bind(id, g.collection, JSON.stringify(data))
        .run()
      return c.json({ ...data, id }, 201)
    } catch (e) {
      return internalError(c, e)
    }
  })

  const update = async (c: TypedCtx): Promise<Response> => {
    const g = gate(c, true)
    if (!g.ok) return g.res
    const id = c.req.param('id') ?? ''
    const b = await readBody(c)
    if (!b.ok) return b.res
    try {
      const row = await c.env.DB
        .prepare('SELECT id, data FROM generic_documents WHERE collection = ? AND id = ?')
        .bind(g.collection, id)
        .first<DocRow>()
      if (!row) return c.json({ ok: false, error: 'not_found' }, 404)
      const merged: JsonObject = { ...parseJsonObject(row.data), ...cleanData(b.body), updatedAt: new Date().toISOString() }
      await c.env.DB
        .prepare('UPDATE generic_documents SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE collection = ? AND id = ?')
        .bind(JSON.stringify(merged), g.collection, id)
        .run()
      return c.json({ ...merged, id }, 200)
    } catch (e) {
      return internalError(c, e)
    }
  }
  r.patch('/documents/:collection/:id', update)
  r.put('/documents/:collection/:id', update)

  r.delete('/documents/:collection/:id', async (c) => {
    const g = gate(c, true)
    if (!g.ok) return g.res
    const id = c.req.param('id')
    try {
      const res = await c.env.DB
        .prepare('DELETE FROM generic_documents WHERE collection = ? AND id = ?')
        .bind(g.collection, id)
        .run()
      const changes = res.meta?.changes ?? 0
      if (changes === 0) return c.json({ ok: false, error: 'not_found' }, 404)
      return c.json({ ok: true, id }, 200)
    } catch (e) {
      return internalError(c, e)
    }
  })

  return r
}
