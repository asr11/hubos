// @ts-nocheck
import type { Hono } from 'hono'
import { requireAuth, requireRole } from '../middleware/requireAuth.js'
import {
  IS_DRY_RUN,
  listDrafts,
  approveDraft,
  dismissDraft,
  generateDraftReminders,
  getCollectionSummary,
} from '../services/collectionEngine.js'

export function registerCollectionRoutes(app: Hono<any>): void {
  /**
   * GET /api/collection/status
   * System safety and engine status
   */
  app.get('/api/collection/status', requireAuth, requireRole(['admin', 'employee', 'ceo']), async (c: any) => {
    return c.json({
      ok: true,
      service: 'HUB OS Collection Engine',
      dryRun: IS_DRY_RUN,
      safetyEnforced: true,
      directEmailApis: 'DISABLED',
      message: IS_DRY_RUN
        ? 'מנוע הגבייה פועל במצב DRY_RUN קשיח (אימות ואגירה בתור בלבד, ללא שליחה)'
        : 'מנוע הגבייה פעיל במצב מבוקר',
    })
  })

  /**
   * GET /api/collection/summary
   * Aggregate statistics for collection queue
   */
  app.get('/api/collection/summary', requireAuth, requireRole(['admin', 'employee', 'ceo']), async (c: any) => {
    try {
      const summary = await getCollectionSummary()
      return c.json({ ok: true, summary })
    } catch (err: any) {
      return c.json({ ok: false, error: 'summary_failed', message: err.message }, 500)
    }
  })

  /**
   * GET /api/collection/drafts
   * List drafts with optional filter: ?status=draft | approved | dismissed
   */
  app.get('/api/collection/drafts', requireAuth, requireRole(['admin', 'employee', 'ceo']), async (c: any) => {
    try {
      const statusParam = c.c.req.query()('status')
      const statusFilter = statusParam && statusParam !== 'all' ? statusParam : undefined
      const drafts = await listDrafts(statusFilter)
      return c.json({
        ok: true,
        dryRun: IS_DRY_RUN,
        count: drafts.length,
        drafts,
      })
    } catch (err: any) {
      return c.json({ ok: false, error: 'fetch_failed', message: err.message }, 500)
    }
  })

  /**
   * POST /api/collection/scan
   * Trigger scan across open invoices to generate/update draft reminders
   */
  app.post('/api/collection/scan', requireAuth, requireRole(['admin', 'ceo']), async (c: any) => {
    try {
      const result = await generateDraftReminders()
      return c.json({
        ok: true,
        dryRun: IS_DRY_RUN,
        ...result,
        message: `סריקה הושלמה: נוצרו ${result.created} טיוטות חדשות, עודכנו ${result.updated} טיוטות קיימות`,
      })
    } catch (err: any) {
      return c.json({ ok: false, error: 'scan_failed', message: err.message }, 500)
    }
  })

  /**
   * POST / PUT /api/collection/drafts/:id/approve
   * Approves a draft and queues it.
   * STRICT SAFETY: When DRY_RUN=true, no external emails are sent.
   */
  const handleApprove = async (c: any) => {
    try {
      const id = c.req.param('id')
      if (!id) {
        return c.json({ ok: false, error: 'missing_id', message: 'חסר מזהה טיוטה' }, 400)
      }

      const result = await approveDraft(id)
      if (!result.ok) {
        return c.json(result, 404)
      }

      return c.json(result, 200)
    } catch (err: any) {
      return c.json({ ok: false, error: 'approve_failed', message: err.message }, 500)
    }
  }

  app.post('/api/collection/drafts/:id/approve', requireAuth, requireRole(['admin', 'ceo']), handleApprove)
  app.put('/api/collection/drafts/:id/approve', requireAuth, requireRole(['admin', 'ceo']), handleApprove)

  /**
   * POST / PUT /api/collection/drafts/:id/dismiss
   * Dismisses a draft reminder from active queue
   */
  const handleDismiss = async (c: any) => {
    try {
      const id = c.req.param('id')
      if (!id) {
        return c.json({ ok: false, error: 'missing_id', message: 'חסר מזהה טיוטה' }, 400)
      }

      const body = (await c.req.json().catch(() => ({}))) as { reason?: string }
      const result = await dismissDraft(id, body?.reason)
      if (!result.ok) {
        return c.json(result, 404)
      }

      return c.json(result, 200)
    } catch (err: any) {
      return c.json({ ok: false, error: 'dismiss_failed', message: err.message }, 500)
    }
  }

  app.post('/api/collection/drafts/:id/dismiss', requireAuth, requireRole(['admin', 'ceo']), handleDismiss)
  app.put('/api/collection/drafts/:id/dismiss', requireAuth, requireRole(['admin', 'ceo']), handleDismiss)
}
