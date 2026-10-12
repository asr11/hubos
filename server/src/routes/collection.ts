/**
 * collection.ts — typed Collection Engine (debt reminders) router over `collection_drafts`.
 *
 * Mount AFTER the worker JWT middleware and BEFORE the legacy `/api/*` fallthrough:
 *   workerRouter.route('/api', createCollectionRouter())
 *
 * Replaces the never-registered legacy collectionRoutes.ts, whose service
 * (services/collectionEngine.ts) is an empty file.
 *
 * BOARD GATE: this router NEVER sends any communication (email/SMS/WhatsApp) to clients and makes
 * no external calls. "approve" only marks a draft as approved in D1 (dry-run queue); nothing is sent.
 *   - POST /api/collection/scan => 501 not_implemented (the original generator does not exist).
 *
 * Frontend contracts: src/services/api.ts collectionApi + src/components/DebtApprovalQueue.tsx
 *   GET  /api/collection/status                 => { ok, service, dryRun, safetyEnforced, directEmailApis, message }
 *   GET  /api/collection/summary                => { ok, summary: CollectionSummary }
 *   GET  /api/collection/drafts?status=         => { ok, dryRun, count, drafts }
 *   POST|PUT /api/collection/drafts/:id/approve => { ok, id, status: 'approved', dryRun }
 *   POST|PUT /api/collection/drafts/:id/dismiss => { ok, id, status: 'dismissed' }
 */
import { Hono } from 'hono'
import {
  ADMIN_ROLES,
  type TypedCtx,
  type TypedEnv,
  type AuthUser,
  effectiveRole,
  forbidden,
  getAuthUser,
  internalError,
  readBody,
  unauthorized,
} from './typedShared.js'

/** Hard dry-run: there is no sending path in this codebase. */
export const COLLECTION_DRY_RUN = true

const DRAFT_STATUSES = ['draft', 'approved', 'dismissed', 'sent'] as const
type DraftStatus = (typeof DRAFT_STATUSES)[number]

/** Original legacy gates: read = admin/employee/ceo, write = admin/ceo (+ superadmin as admin-level). */
const READ_ROLES: ReadonlySet<string> = new Set([...ADMIN_ROLES, 'employee'])
const WRITE_ROLES: ReadonlySet<string> = ADMIN_ROLES

const MAX_REASON_LENGTH = 1000

interface DraftRow {
  id: string
  client_id: string | null
  client_name: string
  client_email: string | null
  invoice_id: string
  invoice_number: string | null
  invoice_date: string | null
  due_date: string
  amount: number
  paid_amount: number | null
  open_amount: number
  days_overdue: number
  reminder_type: string | null
  subject: string | null
  message_body: string | null
  status: string
  approved_at: string | null
  dismissed_at: string | null
  created_at: string | null
  updated_at: string | null
}

interface SummaryRow {
  totalDrafts: number | null
  openDraftsCount: number | null
  approvedCount: number | null
  dismissedCount: number | null
  totalOpenDebt: number | null
  overdue30DaysDebt: number | null
}

type RoleGate = { ok: true; auth: AuthUser } | { ok: false; res: Response }

async function requireRoles(c: TypedCtx, allowed: ReadonlySet<string>): Promise<RoleGate> {
  const auth = getAuthUser(c)
  if (!auth) return { ok: false, res: unauthorized(c) }
  const role = await effectiveRole(c.env.DB, auth)
  if (!allowed.has(role)) return { ok: false, res: forbidden(c, 'insufficient role for collection engine') }
  return { ok: true, auth }
}

function isDraftStatus(v: string): v is DraftStatus {
  return (DRAFT_STATUSES as readonly string[]).includes(v)
}

export function createCollectionRouter(): Hono<TypedEnv> {
  const r = new Hono<TypedEnv>()

  r.get('/collection/status', async (c) => {
    try {
      const g = await requireRoles(c, READ_ROLES)
      if (!g.ok) return g.res
      return c.json({
        ok: true,
        service: 'HUB OS Collection Engine',
        dryRun: COLLECTION_DRY_RUN,
        safetyEnforced: true,
        directEmailApis: 'DISABLED',
        scanAvailable: false,
        message: 'מנוע הגבייה פועל במצב DRY_RUN קשיח (אימות ואגירה בתור בלבד, ללא שליחה)',
      }, 200)
    } catch (e) {
      return internalError(c, e)
    }
  })

  r.get('/collection/summary', async (c) => {
    try {
      const g = await requireRoles(c, READ_ROLES)
      if (!g.ok) return g.res
      const row = await c.env.DB.prepare(`
        SELECT
          COUNT(*) AS totalDrafts,
          SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS openDraftsCount,
          SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approvedCount,
          SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) AS dismissedCount,
          SUM(CASE WHEN status IN ('draft', 'approved') THEN open_amount ELSE 0 END) AS totalOpenDebt,
          SUM(CASE WHEN status IN ('draft', 'approved') AND days_overdue > 30 THEN open_amount ELSE 0 END) AS overdue30DaysDebt
        FROM collection_drafts
      `).first<SummaryRow>()
      return c.json({
        ok: true,
        summary: {
          totalDrafts: row?.totalDrafts ?? 0,
          openDraftsCount: row?.openDraftsCount ?? 0,
          approvedCount: row?.approvedCount ?? 0,
          dismissedCount: row?.dismissedCount ?? 0,
          totalOpenDebt: row?.totalOpenDebt ?? 0,
          overdue30DaysDebt: row?.overdue30DaysDebt ?? 0,
          isDryRun: COLLECTION_DRY_RUN,
        },
      }, 200)
    } catch (e) {
      return c.json({ ok: false, error: 'summary_failed', message: e instanceof Error ? e.message : String(e) }, 500)
    }
  })

  r.get('/collection/drafts', async (c) => {
    try {
      const g = await requireRoles(c, READ_ROLES)
      if (!g.ok) return g.res
      const statusQ = (c.req.query('status') ?? '').trim().toLowerCase()
      if (statusQ !== '' && statusQ !== 'all' && !isDraftStatus(statusQ)) {
        return c.json({ ok: false, error: 'bad_request', message: `unknown status "${statusQ}"` }, 400)
      }
      const stmt = statusQ !== '' && statusQ !== 'all'
        ? c.env.DB.prepare('SELECT * FROM collection_drafts WHERE status = ? ORDER BY days_overdue DESC, created_at DESC').bind(statusQ)
        : c.env.DB.prepare('SELECT * FROM collection_drafts ORDER BY days_overdue DESC, created_at DESC')
      const { results } = await stmt.all<DraftRow>()
      const drafts = results ?? []
      return c.json({ ok: true, dryRun: COLLECTION_DRY_RUN, count: drafts.length, drafts }, 200)
    } catch (e) {
      return c.json({ ok: false, error: 'fetch_failed', message: e instanceof Error ? e.message : String(e) }, 500)
    }
  })

  r.post('/collection/scan', async (c) => {
    try {
      const g = await requireRoles(c, WRITE_ROLES)
      if (!g.ok) return g.res
      return c.json({
        ok: false,
        error: 'not_implemented',
        message: 'סריקת חובות אינה זמינה: מנוע יצירת הטיוטות לא מומש בשרת (services/collectionEngine.ts ריק).',
      }, 501)
    } catch (e) {
      return internalError(c, e)
    }
  })

  const handleApprove = async (c: TypedCtx): Promise<Response> => {
    try {
      const g = await requireRoles(c, WRITE_ROLES)
      if (!g.ok) return g.res
      const id = c.req.param('id') ?? ''
      const row = await c.env.DB.prepare('SELECT id, status FROM collection_drafts WHERE id = ?').bind(id).first<{ id: string; status: string }>()
      if (!row) return c.json({ ok: false, error: 'not_found', message: 'טיוטה לא נמצאה' }, 404)
      if (row.status !== 'draft') {
        return c.json({ ok: false, error: 'invalid_status_transition', from: row.status, to: 'approved' }, 400)
      }
      const res = await c.env.DB
        .prepare("UPDATE collection_drafts SET status = 'approved', approved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'draft'")
        .bind(id)
        .run()
      if ((res.meta?.changes ?? 0) === 0) return c.json({ ok: false, error: 'conflict', message: 'status changed concurrently' }, 409)
      return c.json({
        ok: true,
        id,
        status: 'approved',
        dryRun: COLLECTION_DRY_RUN,
        message: 'הטיוטה אושרה ונשמרה בתור (DRY_RUN — לא נשלחה שום הודעה ללקוח)',
      }, 200)
    } catch (e) {
      return c.json({ ok: false, error: 'approve_failed', message: e instanceof Error ? e.message : String(e) }, 500)
    }
  }

  const handleDismiss = async (c: TypedCtx): Promise<Response> => {
    try {
      const g = await requireRoles(c, WRITE_ROLES)
      if (!g.ok) return g.res
      const b = await readBody(c, true)
      if (!b.ok) return b.res
      const rawReason = b.body.reason
      if (rawReason !== undefined && rawReason !== null && typeof rawReason !== 'string') {
        return c.json({ ok: false, error: 'bad_request', message: 'reason must be a string' }, 400)
      }
      const id = c.req.param('id') ?? ''
      const row = await c.env.DB.prepare('SELECT id, status FROM collection_drafts WHERE id = ?').bind(id).first<{ id: string; status: string }>()
      if (!row) return c.json({ ok: false, error: 'not_found', message: 'טיוטה לא נמצאה' }, 404)
      if (row.status !== 'draft' && row.status !== 'approved') {
        return c.json({ ok: false, error: 'invalid_status_transition', from: row.status, to: 'dismissed' }, 400)
      }
      const res = await c.env.DB
        .prepare("UPDATE collection_drafts SET status = 'dismissed', dismissed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('draft', 'approved')")
        .bind(id)
        .run()
      if ((res.meta?.changes ?? 0) === 0) return c.json({ ok: false, error: 'conflict', message: 'status changed concurrently' }, 409)
      const reason = typeof rawReason === 'string' ? rawReason.slice(0, MAX_REASON_LENGTH) : null
      return c.json({ ok: true, id, status: 'dismissed', reason }, 200)
    } catch (e) {
      return c.json({ ok: false, error: 'dismiss_failed', message: e instanceof Error ? e.message : String(e) }, 500)
    }
  }

  r.post('/collection/drafts/:id/approve', handleApprove)
  r.put('/collection/drafts/:id/approve', handleApprove)
  r.post('/collection/drafts/:id/dismiss', handleDismiss)
  r.put('/collection/drafts/:id/dismiss', handleDismiss)

  return r
}
