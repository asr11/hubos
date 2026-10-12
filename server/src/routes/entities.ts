/**
 * entities.ts — Phase 2 SSOT router (DRAFT).
 *
 * One source of truth per entity: the SQL table.
 *   - Core fields live in real SQL columns (filterable / joinable).
 *   - Every other field the UI sends lives in the `data` JSON column
 *     (added by migration 0006_ssot_data_column.sql).
 *
 * Response shape: { ...data, ...coreCamel, id, createdAt }
 *   (a core column that is NULL does not hide a same-named key in `data`;
 *    this is how FK-rejected values, e.g. an unknown clientId, stay visible.)
 *
 * Mount AFTER the JWT middleware and BEFORE the legacy `/api/*` fallthrough:
 *   workerRouter.route('/api', createEntitiesRouter())
 */
import { Hono } from 'hono'
import type { Context } from 'hono'

// ---------------------------------------------------------------------------
// Minimal D1 typings (the project does not depend on @cloudflare/workers-types).
// Structurally compatible with the real D1Database binding.
// ---------------------------------------------------------------------------
export type D1Value = string | number | null

export interface D1RunResult {
  success: boolean
  meta?: { changes?: number } & Record<string, unknown>
}

export interface D1AllResult<T> {
  results: T[]
  success: boolean
}

export interface D1Statement {
  bind(...values: D1Value[]): D1Statement
  first<T = Record<string, unknown>>(): Promise<T | null>
  all<T = Record<string, unknown>>(): Promise<D1AllResult<T>>
  run(): Promise<D1RunResult>
}

export interface D1Like {
  prepare(query: string): D1Statement
}

export type EntitiesEnv = { Bindings: { DB: D1Like } }
type Ctx = Context<EntitiesEnv>

type JsonObject = Record<string, unknown>
type DbRow = { id: string; created_at?: unknown; data?: unknown } & Record<string, unknown>

// ---------------------------------------------------------------------------
// Entity configuration
// ---------------------------------------------------------------------------
interface CoreField {
  /** SQL column name (constant, never user input). */
  column: string
  /** NOT NULL text column that must be a non-empty string on create/update. */
  required?: boolean
  /** Parent table for a FOREIGN KEY on this column (D1 enforces FKs). */
  fkTable?: string
}

export interface EntityConfig {
  /** URL segment under /api, e.g. 'projects'. */
  path: string
  /** SQL table name (constant). */
  table: string
  /** API camelCase key -> column definition. `id`, `created_at`, `data` are implicit. */
  core: Readonly<Record<string, CoreField>>
  /** Maps aliases / legacy values to canonical status. Unknown values pass through unchanged. */
  normalizeStatus?: (raw: string) => string
  /** DELETE sets status='archived' instead of removing the row. */
  softDelete?: boolean
}

/**
 * System keys written by the migration / router (legacyStatus, legacyGenericStatus,
 * auditLabel, statusBeforeArchive) are preserved on every write path because both
 * PATCH and PUT MERGE into the existing `data` object (nothing is ever wiped).
 */

/** Keys never stored in `data` (they are row identity / timestamps). */
const RESERVED_BODY_KEYS = new Set<string>(['id', 'createdAt', 'created_at', 'data'])

function mapNormalizer(map: Readonly<Record<string, string>>): (raw: string) => string {
  return (raw: string): string => {
    const key = raw.trim()
    return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : raw
  }
}

const PROJECT_STATUS: Readonly<Record<string, string>> = {
  active: 'active',
  'פעיל': 'active',
  paused: 'paused',
  'מושהה': 'paused',
  done: 'done',
  completed: 'done',
  'הושלם': 'done',
  archived: 'archived',
}

const TASK_STATUS: Readonly<Record<string, string>> = {
  todo: 'todo',
  open: 'todo',
  'לביצוע': 'todo',
  doing: 'doing',
  in_progress: 'doing',
  'בתהליך': 'doing',
  done: 'done',
  completed: 'done',
  'הושלם': 'done',
  blocked: 'blocked',
}

const LEAD_STATUS: Readonly<Record<string, string>> = {
  new: 'new',
  specification: 'specification',
  'בתהליך אפיון': 'specification',
  quote_sent: 'quote_sent',
  'נשלחה הצעת מחיר': 'quote_sent',
  follow_up: 'follow_up',
  contacted: 'follow_up',
  no_answer: 'no_answer',
  not_relevant: 'not_relevant',
  'לא רלוונטי': 'not_relevant',
  closed: 'closed',
  'נסגרה': 'closed',
}

const CAMPAIGN_STATUS: Readonly<Record<string, string>> = {
  planning: 'planning',
  active: 'active',
  paused: 'paused',
  completed: 'completed',
}

export const ENTITY_CONFIGS: readonly EntityConfig[] = [
  {
    path: 'projects',
    table: 'projects',
    core: {
      name: { column: 'name', required: true },
      clientId: { column: 'client_id', fkTable: 'clients' },
      status: { column: 'status' },
      tenantId: { column: 'tenant_id', fkTable: 'tenants' },
    },
    normalizeStatus: mapNormalizer(PROJECT_STATUS),
    softDelete: true,
  },
  {
    path: 'tasks',
    table: 'tasks',
    core: {
      title: { column: 'title', required: true },
      description: { column: 'description' },
      status: { column: 'status' },
      projectId: { column: 'project_id', fkTable: 'projects' },
      assigneeId: { column: 'assignee' },
      dueDate: { column: 'due_at' },
      tenantId: { column: 'tenant_id', fkTable: 'tenants' },
    },
    normalizeStatus: mapNormalizer(TASK_STATUS),
  },
  {
    path: 'leads',
    table: 'leads',
    core: {
      name: { column: 'name', required: true },
      email: { column: 'email' },
      phone: { column: 'phone' },
      status: { column: 'status' },
      tenantId: { column: 'tenant_id', fkTable: 'tenants' },
    },
    normalizeStatus: mapNormalizer(LEAD_STATUS),
  },
  {
    path: 'suppliers',
    table: 'suppliers',
    core: {
      name: { column: 'name', required: true },
      contactInfo: { column: 'contact_info' },
    },
  },
  {
    path: 'campaigns',
    table: 'campaigns',
    core: {
      name: { column: 'name', required: true },
      status: { column: 'status' },
      tenantId: { column: 'tenant_id', fkTable: 'tenants' },
    },
    normalizeStatus: mapNormalizer(CAMPAIGN_STATUS),
  },
]

// Identifiers are interpolated into SQL, so assert they are plain constants.
const IDENT = /^[a-z_]+$/
function assertConfig(cfg: EntityConfig): void {
  const idents = [cfg.table, ...Object.values(cfg.core).flatMap((f) => [f.column, f.fkTable ?? 'x'])]
  for (const ident of idents) {
    if (!IDENT.test(ident)) throw new Error(`entities.ts: unsafe SQL identifier "${ident}" in ${cfg.path}`)
  }
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

function toDbValue(v: unknown): D1Value {
  if (v === undefined || v === null) return null
  if (typeof v === 'string') return v
  if (typeof v === 'number') return Number.isFinite(v) ? v : null
  if (typeof v === 'boolean') return v ? 1 : 0
  if (typeof v === 'bigint') return v.toString()
  return JSON.stringify(v)
}

function rowToApi(cfg: EntityConfig, row: DbRow): JsonObject {
  const data = parseData(row.data)
  const out: JsonObject = { ...data }
  for (const [apiKey, field] of Object.entries(cfg.core)) {
    const value = row[field.column] ?? null
    if (value !== null || !(apiKey in data)) out[apiKey] = value
  }
  out.id = row.id
  out.createdAt = row.created_at ?? null
  return out
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

interface PreparedWrite {
  /** column -> value to write */
  columns: Map<string, D1Value>
  /** non-core fields to merge into `data` */
  extras: JsonObject
  /** keys to drop from existing `data` (a core column now owns them) */
  dataRemovals: string[]
}

type PrepareResult = { ok: true; write: PreparedWrite } | { ok: false; status: 400; message: string }

async function fkExists(db: D1Like, table: string, id: string): Promise<boolean> {
  const row = await db.prepare(`SELECT 1 AS ok FROM ${table} WHERE id = ? LIMIT 1`).bind(id).first<{ ok: number }>()
  return row !== null
}

/**
 * Splits a request body into core columns and `data` extras.
 * - status: normalized; the raw value is kept in extras.legacyStatus when it changes.
 * - FK columns: '' -> NULL; an id missing from the parent table is NOT written to the
 *   column (D1 would reject it) and is kept in `data` under the same API key instead.
 */
async function prepareWrite(db: D1Like, cfg: EntityConfig, body: JsonObject, isInsert: boolean): Promise<PrepareResult> {
  const columns = new Map<string, D1Value>()
  const extras: JsonObject = {}
  const dataRemovals: string[] = []

  for (const [key, value] of Object.entries(body)) {
    if (RESERVED_BODY_KEYS.has(key)) continue
    const field = cfg.core[key]
    if (!field) {
      extras[key] = value
      continue
    }

    if (field.required) {
      if (typeof value !== 'string' || value.trim() === '') {
        return { ok: false, status: 400, message: `"${key}" must be a non-empty string` }
      }
      columns.set(field.column, value)
      dataRemovals.push(key)
      continue
    }

    if (key === 'status' && cfg.normalizeStatus && typeof value === 'string') {
      const normalized = cfg.normalizeStatus(value)
      if (normalized !== value) extras.legacyStatus = value
      columns.set(field.column, normalized)
      dataRemovals.push(key)
      continue
    }

    if (field.fkTable) {
      const ref = toDbValue(value)
      if (ref === null || ref === '') {
        columns.set(field.column, null)
        dataRemovals.push(key)
      } else if (await fkExists(db, field.fkTable, String(ref))) {
        columns.set(field.column, String(ref))
        dataRemovals.push(key)
      } else {
        columns.set(field.column, null)
        extras[key] = value
      }
      continue
    }

    columns.set(field.column, toDbValue(value))
    dataRemovals.push(key)
  }

  if (isInsert) {
    for (const [key, field] of Object.entries(cfg.core)) {
      if (field.required && !columns.has(field.column)) {
        return { ok: false, status: 400, message: `"${key}" is required` }
      }
    }
  }
  return { ok: true, write: { columns, extras, dataRemovals } }
}

async function selectById(db: D1Like, cfg: EntityConfig, id: string): Promise<DbRow | null> {
  return db.prepare(`SELECT * FROM ${cfg.table} WHERE id = ?`).bind(id).first<DbRow>()
}

async function insertRow(db: D1Like, cfg: EntityConfig, id: string, write: PreparedWrite, createdAt: unknown): Promise<void> {
  const cols: string[] = ['id']
  const vals: D1Value[] = [id]
  for (const [col, val] of write.columns) {
    cols.push(col)
    vals.push(val)
  }
  cols.push('data')
  vals.push(JSON.stringify(write.extras))
  if (typeof createdAt === 'string' && createdAt.trim() !== '') {
    cols.push('created_at')
    vals.push(createdAt)
  }
  const placeholders = cols.map(() => '?').join(', ')
  await db.prepare(`INSERT INTO ${cfg.table} (${cols.join(', ')}) VALUES (${placeholders})`).bind(...vals).run()
}

async function updateRow(db: D1Like, cfg: EntityConfig, existing: DbRow, write: PreparedWrite): Promise<void> {
  const data = parseData(existing.data)
  for (const key of write.dataRemovals) delete data[key]
  // keep the FIRST original legacyStatus (written by the migration) if present
  const extras: JsonObject = { ...write.extras }
  if ('legacyStatus' in extras && data.legacyStatus !== undefined && data.legacyStatus !== null) {
    delete extras.legacyStatus
  }
  const merged: JsonObject = { ...data, ...extras }

  const sets: string[] = []
  const vals: D1Value[] = []
  for (const [col, val] of write.columns) {
    sets.push(`${col} = ?`)
    vals.push(val)
  }
  sets.push('data = ?')
  vals.push(JSON.stringify(merged))
  vals.push(existing.id)
  await db.prepare(`UPDATE ${cfg.table} SET ${sets.join(', ')} WHERE id = ?`).bind(...vals).run()
}

function isConstraintError(e: unknown, kind: 'UNIQUE' | 'FOREIGN KEY'): boolean {
  return errMessage(e).toUpperCase().includes(`${kind} CONSTRAINT`)
}

function internalError(c: Ctx, where: string, e: unknown): Response {
  console.error(`[entities] ${where}:`, e)
  return c.json({ ok: false, error: 'internal_error', message: errMessage(e) }, 500)
}

function requireIdParam(c: Ctx): string | null {
  const id = c.req.param('id')
  return typeof id === 'string' && id !== '' ? id : null
}

// ---------------------------------------------------------------------------
// Route registration
// ---------------------------------------------------------------------------
function registerEntity(app: Hono<EntitiesEnv>, cfg: EntityConfig): void {
  assertConfig(cfg)
  const base = `/${cfg.path}`
  const item = `/${cfg.path}/:id`

  // GET list
  app.get(base, async (c: Ctx): Promise<Response> => {
    try {
      const includeArchived = ['1', 'true'].includes(c.req.query('includeArchived') ?? '')
      const where = cfg.softDelete && !includeArchived ? ` WHERE status IS NULL OR status <> 'archived'` : ''
      const { results } = await c.env.DB.prepare(`SELECT * FROM ${cfg.table}${where} ORDER BY created_at DESC`).all<DbRow>()
      if (!Array.isArray(results)) throw new Error(`D1 returned no results array for ${cfg.table}`)
      return c.json(results.map((r) => rowToApi(cfg, r)), 200)
    } catch (e) {
      return internalError(c, `GET ${base}`, e)
    }
  })

  // GET one (archived rows are still readable by id)
  app.get(item, async (c: Ctx): Promise<Response> => {
    const id = requireIdParam(c)
    if (!id) return c.json({ ok: false, error: 'bad_request', message: 'missing id' }, 400)
    try {
      const row = await selectById(c.env.DB, cfg, id)
      if (!row) return c.json({ ok: false, error: 'not_found' }, 404)
      return c.json(rowToApi(cfg, row), 200)
    } catch (e) {
      return internalError(c, `GET ${item}`, e)
    }
  })

  // POST create
  app.post(base, async (c: Ctx): Promise<Response> => {
    const parsed = await readBody(c)
    if (!parsed.ok) return parsed.res
    const body = parsed.body
    try {
      const id = typeof body.id === 'string' && body.id.trim() !== '' ? body.id.trim() : crypto.randomUUID()
      if (await selectById(c.env.DB, cfg, id)) {
        return c.json({ ok: false, error: 'conflict', message: `${cfg.path}/${id} already exists` }, 409)
      }
      const prep = await prepareWrite(c.env.DB, cfg, body, true)
      if (!prep.ok) return c.json({ ok: false, error: 'bad_request', message: prep.message }, 400)
      await insertRow(c.env.DB, cfg, id, prep.write, body.createdAt)
      const row = await selectById(c.env.DB, cfg, id)
      if (!row) throw new Error(`insert into ${cfg.table} did not persist id ${id}`)
      return c.json(rowToApi(cfg, row), 201)
    } catch (e) {
      if (isConstraintError(e, 'UNIQUE')) return c.json({ ok: false, error: 'conflict', message: errMessage(e) }, 409)
      return internalError(c, `POST ${base}`, e)
    }
  })

  // PUT upsert: insert when missing, otherwise MERGE (never wipes existing data keys)
  app.put(item, async (c: Ctx): Promise<Response> => {
    const id = requireIdParam(c)
    if (!id) return c.json({ ok: false, error: 'bad_request', message: 'missing id' }, 400)
    const parsed = await readBody(c)
    if (!parsed.ok) return parsed.res
    try {
      const existing = await selectById(c.env.DB, cfg, id)
      const prep = await prepareWrite(c.env.DB, cfg, parsed.body, existing === null)
      if (!prep.ok) return c.json({ ok: false, error: 'bad_request', message: prep.message }, 400)
      if (existing) {
        await updateRow(c.env.DB, cfg, existing, prep.write)
      } else {
        await insertRow(c.env.DB, cfg, id, prep.write, parsed.body.createdAt)
      }
      const row = await selectById(c.env.DB, cfg, id)
      if (!row) throw new Error(`upsert into ${cfg.table} did not persist id ${id}`)
      return c.json(rowToApi(cfg, row), existing ? 200 : 201)
    } catch (e) {
      return internalError(c, `PUT ${item}`, e)
    }
  })

  // PATCH partial update: merge `data`, update only provided core columns
  app.patch(item, async (c: Ctx): Promise<Response> => {
    const id = requireIdParam(c)
    if (!id) return c.json({ ok: false, error: 'bad_request', message: 'missing id' }, 400)
    const parsed = await readBody(c)
    if (!parsed.ok) return parsed.res
    try {
      const existing = await selectById(c.env.DB, cfg, id)
      if (!existing) return c.json({ ok: false, error: 'not_found' }, 404)
      const prep = await prepareWrite(c.env.DB, cfg, parsed.body, false)
      if (!prep.ok) return c.json({ ok: false, error: 'bad_request', message: prep.message }, 400)
      await updateRow(c.env.DB, cfg, existing, prep.write)
      const row = await selectById(c.env.DB, cfg, id)
      if (!row) throw new Error(`row ${cfg.table}/${id} vanished during PATCH`)
      return c.json(rowToApi(cfg, row), 200)
    } catch (e) {
      return internalError(c, `PATCH ${item}`, e)
    }
  })

  // DELETE: soft (status='archived') for projects, hard delete otherwise
  app.delete(item, async (c: Ctx): Promise<Response> => {
    const id = requireIdParam(c)
    if (!id) return c.json({ ok: false, error: 'bad_request', message: 'missing id' }, 400)
    try {
      const existing = await selectById(c.env.DB, cfg, id)
      if (!existing) return c.json({ ok: false, error: 'not_found' }, 404)
      if (cfg.softDelete) {
        const data = parseData(existing.data)
        const prev = existing.status ?? null
        if (prev !== 'archived') {
          data.statusBeforeArchive = prev
          if (data.legacyStatus === undefined || data.legacyStatus === null) data.legacyStatus = prev
        }
        await c.env.DB.prepare(`UPDATE ${cfg.table} SET status = 'archived', data = ? WHERE id = ?`)
          .bind(JSON.stringify(data), id)
          .run()
        return c.json({ ok: true, success: true, id, archived: true }, 200)
      }
      await c.env.DB.prepare(`DELETE FROM ${cfg.table} WHERE id = ?`).bind(id).run()
      return c.json({ ok: true, success: true, id, deleted: true }, 200)
    } catch (e) {
      if (isConstraintError(e, 'FOREIGN KEY')) {
        return c.json({ ok: false, error: 'conflict', message: `${cfg.path}/${id} is referenced by other rows` }, 409)
      }
      return internalError(c, `DELETE ${item}`, e)
    }
  })
}

// ---------------------------------------------------------------------------
// Task sub-collections: /tasks/:id/activity and /tasks/:id/taskNotes
// Stored in generic_documents, collection = `tasks/{taskId}/{kind}`.
// generic_documents.id is a GLOBAL primary key -> ids are full UUIDs.
// ---------------------------------------------------------------------------
type TaskSubKind = 'activity' | 'taskNotes'

function registerTaskSubcollection(app: Hono<EntitiesEnv>, kind: TaskSubKind): void {
  const path = `/tasks/:id/${kind}`
  const prefix = kind === 'activity' ? 'act' : 'note'

  app.get(path, async (c: Ctx): Promise<Response> => {
    const taskId = requireIdParam(c)
    if (!taskId) return c.json({ ok: false, error: 'bad_request', message: 'missing id' }, 400)
    try {
      const { results } = await c.env.DB
        .prepare('SELECT id, data, updated_at FROM generic_documents WHERE collection = ? ORDER BY updated_at DESC')
        .bind(`tasks/${taskId}/${kind}`)
        .all<{ id: string; data: unknown; updated_at: unknown }>()
      if (!Array.isArray(results)) throw new Error('D1 returned no results array for generic_documents')
      const list = results.map((r) => {
        const d = parseData(r.data)
        return { ...d, id: r.id, createdAt: d.createdAt ?? r.updated_at ?? null }
      })
      return c.json(list, 200)
    } catch (e) {
      return internalError(c, `GET ${path}`, e)
    }
  })

  app.post(path, async (c: Ctx): Promise<Response> => {
    const taskId = requireIdParam(c)
    if (!taskId) return c.json({ ok: false, error: 'bad_request', message: 'missing id' }, 400)
    const parsed = await readBody(c)
    if (!parsed.ok) return parsed.res
    try {
      const task = await c.env.DB.prepare('SELECT 1 AS ok FROM tasks WHERE id = ?').bind(taskId).first<{ ok: number }>()
      if (!task) return c.json({ ok: false, error: 'not_found', message: `task ${taskId} not found` }, 404)
      const body = parsed.body
      const docId = typeof body.id === 'string' && body.id.trim() !== '' ? body.id.trim() : `${prefix}_${crypto.randomUUID()}`
      const { id: _ignored, ...rest } = body
      void _ignored
      const doc: JsonObject = {
        ...rest,
        taskId,
        createdAt: typeof body.createdAt === 'string' && body.createdAt !== '' ? body.createdAt : new Date().toISOString(),
      }
      await c.env.DB.prepare('INSERT INTO generic_documents (id, collection, data) VALUES (?, ?, ?)')
        .bind(docId, `tasks/${taskId}/${kind}`, JSON.stringify(doc))
        .run()
      return c.json({ ok: true, ...doc, id: docId }, 201)
    } catch (e) {
      if (isConstraintError(e, 'UNIQUE')) return c.json({ ok: false, error: 'conflict', message: 'id already exists' }, 409)
      return internalError(c, `POST ${path}`, e)
    }
  })
}

// ---------------------------------------------------------------------------
// Public factory
// ---------------------------------------------------------------------------
export function createEntitiesRouter(configs: readonly EntityConfig[] = ENTITY_CONFIGS): Hono<EntitiesEnv> {
  const app = new Hono<EntitiesEnv>()
  // sub-collections first (more specific paths)
  registerTaskSubcollection(app, 'activity')
  registerTaskSubcollection(app, 'taskNotes')
  for (const cfg of configs) registerEntity(app, cfg)
  return app
}
