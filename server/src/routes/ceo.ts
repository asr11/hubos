/**
 * ceo.ts — typed Digital-CEO action queue router over the `ceo_actions` table.
 *
 * Mount AFTER the worker JWT middleware and BEFORE the legacy `/api/*` fallthrough:
 *   workerRouter.route('/api', createCeoRouter())
 *
 * Table: ceo_actions(action_id PK, action_type, status, reasoning, proposed_payload JSON,
 *                    ledger JSON DEFAULT '[]', created_at, updated_at)
 *
 * State machine (preserved from the legacy ceoActions.ts):
 *   PROPOSED -> APPROVED | REJECTED   (human gate — implemented here)
 *   APPROVED -> EXECUTED -> VERIFIED | FAILED   (NOT exposed here: 501)
 *
 * HUMAN GATE / BOARD GATE:
 *   - approve/reject are pure status transitions + ledger append. The proposed payload is NEVER
 *     executed here (no side effects, no outbound communication).
 *   - only roles ceo/admin/superadmin (fresh from users table) may approve/reject => 403 otherwise.
 *   - the ledger actor is the authenticated uid (body-supplied approved_by/rejected_by is ignored).
 *
 * Response shape for the list (src/pages/DigitalCeo.tsx reads `data.data` and uses
 * action_id / action_type / status / proposed_payload (string) / created_at / updated_at).
 * Aliases id/title/description/payload are included for newer consumers.
 */
import { Hono } from 'hono'
import {
  ADMIN_ROLES,
  type JsonObject,
  type TypedCtx,
  type TypedEnv,
  effectiveRole,
  forbidden,
  getAuthUser,
  internalError,
  parseJsonArray,
  readBody,
  unauthorized,
} from './typedShared.js'

export const CEO_ACTION_STATUSES = ['PROPOSED', 'APPROVED', 'REJECTED', 'EXECUTING', 'EXECUTED', 'VERIFIED', 'FAILED'] as const

interface CeoActionRow {
  action_id: string
  action_type: string
  status: string
  reasoning: string | null
  proposed_payload: string | null
  ledger: string | null
  created_at: string | null
  updated_at: string | null
}

const MAX_REASON_LENGTH = 2000

function parsePayload(raw: string | null): unknown {
  if (raw === null || raw === '') return null
  try {
    return JSON.parse(raw) as unknown
  } catch {
    return raw
  }
}

function humanTitle(actionType: string): string {
  return actionType
    .split('_')
    .filter((p) => p !== '')
    .map((p) => p.charAt(0) + p.slice(1).toLowerCase())
    .join(' ')
}

function toApi(row: CeoActionRow, includeLedger: boolean): JsonObject {
  const payload = parsePayload(row.proposed_payload)
  const out: JsonObject = {
    action_id: row.action_id,
    action_type: row.action_type,
    status: row.status,
    reasoning: row.reasoning,
    proposed_payload: row.proposed_payload ?? '',
    created_at: row.created_at,
    updated_at: row.updated_at,
    // aliases
    id: row.action_id,
    title: humanTitle(row.action_type),
    description: row.reasoning ?? '',
    payload,
  }
  if (includeLedger) out.ledger = parseJsonArray(row.ledger)
  return out
}

const SELECT_COLS = 'action_id, action_type, status, reasoning, proposed_payload, ledger, created_at, updated_at'

function notImplemented(c: TypedCtx): Response {
  return c.json(
    { ok: false, error: 'not_implemented', message: 'Only list/get/approve/reject are available. Execution is not exposed via the API (Board Gate).' },
    501,
  )
}

export function createCeoRouter(): Hono<TypedEnv> {
  const r = new Hono<TypedEnv>()

  r.get('/ceo/actions', async (c) => {
    if (!getAuthUser(c)) return unauthorized(c)
    const statusQ = (c.req.query('status') ?? '').trim().toUpperCase()
    if (statusQ !== '' && statusQ !== 'ALL' && !(CEO_ACTION_STATUSES as readonly string[]).includes(statusQ)) {
      return c.json({ ok: false, error: 'bad_request', message: `unknown status "${statusQ}"` }, 400)
    }
    try {
      const stmt = statusQ !== '' && statusQ !== 'ALL'
        ? c.env.DB.prepare(`SELECT ${SELECT_COLS} FROM ceo_actions WHERE status = ? ORDER BY created_at DESC`).bind(statusQ)
        : c.env.DB.prepare(`SELECT ${SELECT_COLS} FROM ceo_actions ORDER BY created_at DESC`)
      const { results } = await stmt.all<CeoActionRow>()
      const data = (results ?? []).map((row) => toApi(row, false))
      return c.json({ ok: true, count: data.length, data }, 200)
    } catch (e) {
      return internalError(c, e)
    }
  })

  r.get('/ceo/actions/:id', async (c) => {
    if (!getAuthUser(c)) return unauthorized(c)
    try {
      const row = await c.env.DB
        .prepare(`SELECT ${SELECT_COLS} FROM ceo_actions WHERE action_id = ?`)
        .bind(c.req.param('id'))
        .first<CeoActionRow>()
      if (!row) return c.json({ ok: false, error: 'not_found' }, 404)
      return c.json({ ok: true, action: toApi(row, true) }, 200)
    } catch (e) {
      return internalError(c, e)
    }
  })

  const transition = (to: 'APPROVED' | 'REJECTED') => async (c: TypedCtx): Promise<Response> => {
    const auth = getAuthUser(c)
    if (!auth) return unauthorized(c)
    const b = await readBody(c, true)
    if (!b.ok) return b.res
    let reason: string | null = null
    if (to === 'REJECTED') {
      const raw = b.body.reason
      if (raw !== undefined && raw !== null && typeof raw !== 'string') {
        return c.json({ ok: false, error: 'bad_request', message: 'reason must be a string' }, 400)
      }
      reason = typeof raw === 'string' ? raw.slice(0, MAX_REASON_LENGTH) : null
    }
    try {
      const role = await effectiveRole(c.env.DB, auth)
      if (!ADMIN_ROLES.has(role)) return forbidden(c, 'only ceo/admin/superadmin may approve or reject CEO actions')

      const id = c.req.param('id') ?? ''
      const row = await c.env.DB
        .prepare(`SELECT ${SELECT_COLS} FROM ceo_actions WHERE action_id = ?`)
        .bind(id)
        .first<CeoActionRow>()
      if (!row) return c.json({ ok: false, error: 'not_found' }, 404)
      if (row.status !== 'PROPOSED') {
        return c.json({ ok: false, error: 'invalid_status_transition', from: row.status, to }, 400)
      }

      const ledger = parseJsonArray(row.ledger)
      ledger.push({
        status: to,
        timestamp: new Date().toISOString(),
        actor: auth.uid,
        data: to === 'APPROVED' ? parsePayload(row.proposed_payload) : { reason },
      })

      // Guarded update: only transitions if still PROPOSED (prevents double-approve races).
      const res = await c.env.DB
        .prepare("UPDATE ceo_actions SET status = ?, ledger = ?, updated_at = CURRENT_TIMESTAMP WHERE action_id = ? AND status = 'PROPOSED'")
        .bind(to, JSON.stringify(ledger), id)
        .run()
      if ((res.meta?.changes ?? 0) === 0) {
        return c.json({ ok: false, error: 'conflict', message: 'action status changed concurrently' }, 409)
      }
      return c.json({ ok: true, action_id: id, status: to }, 200)
    } catch (e) {
      return internalError(c, e)
    }
  }

  r.post('/ceo/actions/:id/approve', transition('APPROVED'))
  r.post('/ceo/actions/:id/reject', transition('REJECTED'))

  // Shadow the broken legacy (sync better-sqlite3 style) handlers with explicit 501s.
  r.post('/ceo/actions', (c) => (getAuthUser(c) ? notImplemented(c) : unauthorized(c)))
  r.post('/ceo/actions/:id/execute', (c) => (getAuthUser(c) ? notImplemented(c) : unauthorized(c)))
  r.post('/ceo/actions/:id/verify', (c) => (getAuthUser(c) ? notImplemented(c) : unauthorized(c)))

  return r
}
