// @ts-nocheck
import { Hono } from 'hono'
import { requireAdmin } from '../middleware/requireAdmin.js'

import { MorningClient, MorningError } from '../lib/morningClient.js'
import {
  credentialsFromEnv,
  loadMorningCredentials,
  readSyncState,
  saveMorningCredentials,
  writeSyncState,
  type MorningEnv,
} from '../lib/morningCredentials.js'
import { runExpenseReconcile } from '../lib/morningExpenseSync.js'
import { fetchExpenseDocumentBuffer } from '../lib/expenseDocument.js'
import {
  fetchMorningClients,
  fetchMorningSyncPayload,
  resetAllIncomes,
} from '../lib/morningSync.js'
import { env } from '../config/env.js'

function normalizeEnv(raw: unknown): MorningEnv {
  return raw === 'sandbox' ? 'sandbox' : 'production'
}

export async function registerMorningRoutes(app: Hono<any>) {
  /**
   * מצב האינטגרציה — מפתחות + syncState מהשרת (דיסק/env), בלי קריאת Firestore.
   * firebaseReady = אפשר לאמת טוקן + לכתוב הכנסות (Admin מוגדר).
   */
  app.get(
    '/api/integrations/morning/status',
    requireAdmin,
    async (c) => {
      const creds = await loadMorningCredentials()
      const syncState = await readSyncState()
      return c.json({
        ok: true,
        firebaseReady: true,
        firebaseReason: null,
        connected: Boolean(creds),
        env: creds?.env ?? null,
        credentialsFromEnv: credentialsFromEnv(),
        fromDate: env.sync.fromDate,
        syncState,
      })
    },
  )

  /** שמירת מפתח API + אימות מול Morning */
  app.post(
    '/api/integrations/morning/connect',
    requireAdmin,
    async (c) => {
      const body = (await c.req.json() ?? {}) as Record<string, unknown>
      const keyId = String(body.keyId ?? '').trim()
      const keySecret = String(body.keySecret ?? '').trim()
      const giEnv = normalizeEnv(body.env)
      if (!keyId || !keySecret) {
        return c.json({
          ok: false,
          error: 'missing_credentials',
          message: 'חסר מזהה מפתח או סוד',
        }, 400)
      }

      const client = new MorningClient({ keyId, keySecret, env: giEnv })
      try {
        await client.verifyConnection()
      } catch (e) {
        const status = e instanceof MorningError ? e.status : 500
        return c.json({
          ok: false,
          error: 'morning_auth_failed',
          message:
            e instanceof MorningError
              ? e.message
              : 'אימות מול חשבונית ירוקה נכשל',
        }, status === 401 ? 400 : 502)
      }

      await saveMorningCredentials({ keyId, keySecret, env: giEnv })
      return c.json({ ok: true, connected: true, env: giEnv })
    },
  )

  /** בדיקת חיבור מהיר ללא שמירה */
  app.post(
    '/api/integrations/morning/test',
    requireAdmin,
    async (c) => {
      const creds = await loadMorningCredentials()
      if (!creds) {
        return c.json({ ok: false, error: 'not_connected' }, 400)
      }
      const client = new MorningClient(creds)
      try {
        await client.verifyConnection()
        return c.json({ ok: true, connected: true })
      } catch (e) {
        return c.json({
          ok: false,
          error: 'morning_unreachable',
          message: e instanceof MorningError ? e.message : 'שגיאת חיבור',
        }, 502)
      }
    },
  )

  /**
   * משיכת לקוחות + הכנסות מחשבונית ירוקה בלבד (בלי כתיבת Admin ל-Firestore).
   * השמירה מתבצעת בדפדפן עם המשתמש המחובר.
   */
  app.post(
    '/api/integrations/morning/sync',
    requireAdmin,
    async (c) => {
      const body = (await c.req.json() ?? {}) as Record<string, unknown>
      const fromDate =
        typeof body.fromDate === 'string' && body.fromDate.trim()
          ? body.fromDate.trim()
          : undefined
      try {
        return c.json(await fetchMorningSyncPayload({ fromDate }))
      } catch (e) {
        const status = e instanceof MorningError ? e.status : 500
        return c.json({
          ok: false,
          error: 'sync_failed',
          message: e instanceof Error ? e.message : 'הסנכרון נכשל',
        }, status >= 400 && status < 500 ? status : 502)
      }
    },
  )

  /** משיכת לקוחות בלבד — בלי כתיבת Admin ל-Firestore */
  app.post(
    '/api/integrations/morning/sync-clients',
    requireAdmin,
    async (c) => {
      try {
        const clients = await fetchMorningClients()
        return c.json({ ok: true, mode: 'client_write' as const, clients })
      } catch (e) {
        const status = e instanceof MorningError ? e.status : 500
        return c.json({
          ok: false,
          error: 'client_sync_failed',
          message: e instanceof Error ? e.message : 'סנכרון הלקוחות נכשל',
        }, status >= 400 && status < 500 ? status : 502)
      }
    },
  )

  /** שמירת סיכום סנכרון על דיסק השרת (אחרי שהדפדפן סיים לכתוב ל-Firestore) */
  app.post(
    '/api/integrations/morning/ack-sync',
    requireAdmin,
    async (c) => {
      const body = (await c.req.json() ?? {}) as Record<string, unknown>
      try {
        await writeSyncState(body)
        return c.json({ ok: true })
      } catch (e) {
        return c.json({
          ok: false,
          error: 'ack_failed',
          message: e instanceof Error ? e.message : 'שמירת מצב הסנכרון נכשלה',
        }, 500)
      }
    },
  )

  // משיכת הוצאות מחשבונית ירוקה הוסרה במכוון: חשבונית ירוקה היא מקור האמת
  // להכנסות בלבד. הוצאות נכנסות דרך הסוכן, אחרי אישור המשתמש.

  /** התאמה מחדש של הוצאות קיימות מול תנועות הבנק */
  app.post(
    '/api/integrations/morning/reconcile-expenses',
    requireAdmin,
    async (c) => {
      try {
        return c.json(await runExpenseReconcile())
      } catch (e) {
        const status = e instanceof MorningError ? e.status : 500
        return c.json({
          ok: false,
          error: 'reconcile_failed',
          message: e instanceof Error ? e.message : 'ההתאמה נכשלה',
        }, status >= 400 && status < 500 ? status : 502)
      }
    },
  )

  /** צפייה / הורדה במסמך הוצאה מצורף ב-GI (PDF) */
  app.get(
    '/api/integrations/morning/expenses/:greenInvoiceId/document',
    requireAdmin,
    async (c) => {
      const greenInvoiceId = String(
        (c.req.param()).greenInvoiceId ?? '',
      ).trim()
      if (!greenInvoiceId) {
        return c.json({ ok: false, message: 'חסר מזהה הוצאה' }, 400)
      }
      const creds = await loadMorningCredentials()
      if (!creds) {
        return c.json({ ok: false, message: 'חיבור GI לא מוגדר' }, 400)
      }
      try {
        const client = new MorningClient(creds)
        const doc = await fetchExpenseDocumentBuffer(client, greenInvoiceId)
        return new Response(doc.buffer, {
          headers: {
            'Content-Type': doc.mimeType,
            'Content-Disposition': `inline; filename="${encodeURIComponent(doc.fileName)}"`,
          },
        })
      } catch (e) {
        const status = e instanceof MorningError ? e.status : 404
        return c.json({
          ok: false,
          message:
            e instanceof Error
              ? e.message
              : 'לא ניתן לטעון את המסמך מחשבונית ירוקה',
        }, status >= 400 && status < 500 ? status : 404)
      }
    },
  )

  /** מחיקת כל ההכנסות הקיימות — איפוס לפני סנכרון מלא */
  app.post(
    '/api/integrations/morning/reset-incomes',
    requireAdmin,
    async (c) => {
      try {
        const deleted = await resetAllIncomes()
        return c.json({ ok: true, deleted })
      } catch (e) {
        return c.json({
          ok: false,
          error: 'reset_failed',
          message: e instanceof Error ? e.message : 'מחיקת ההכנסות נכשלה',
        }, 500)
      }
    },
  )
}
