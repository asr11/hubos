// @ts-nocheck
import { Hono } from 'hono'
import { requireAuth } from '../middleware/requireAuth.js'

import { syncReadonlyMaterialsFromMorning } from '../lib/syncReadonlyMaterials.js'
import { registerDomainRecord, checkSshConnectivity } from '../lib/domainSshMonitor.js'
import { createQuoteRecord, signQuoteRecord, getQuoteRecord } from '../lib/quoteSignModule.js'
import { registerVaultRoutes } from './vault.js'

// NOTE: /api/ceo/actions is served by the typed worker-level router (routes/ceo.ts); the legacy
// ceoActions.ts (sync better-sqlite3 calls, broken on D1) is no longer registered.
// /api/me is served by routes/core.ts; the handler below is a shadowed fallback only.

function fail(c: any, err: unknown, status = 500) {
  return c.json({ ok: false, error: 'internal_error', message: err instanceof Error ? err.message : String(err) }, status)
}

export async function registerApiRoutes(app: Hono<any>) {
  // Register specific modules
  await registerVaultRoutes(app)

  app.get('/api/me', requireAuth, async (c: any) => {
    const user = c.get('authUser') as { uid: string; email?: string } | undefined
    return c.json({ ok: true, uid: user?.uid ?? null, email: user?.email ?? null })
  })

  app.get('/api/integrations/status', requireAuth, async (c: any) => {
    return c.json({
      ok: true,
      integrations: {
        morning: 'pending',
        clockify: 'pending',
      },
    })
  })

  app.post('/api/sync/readonly-materials', requireAuth, async (c: any) => {
    try {
      const result = await syncReadonlyMaterialsFromMorning()
      return c.json(result)
    } catch (err) {
      return fail(c, err)
    }
  })

  app.post('/api/domains/register', requireAuth, async (c: any) => {
    try {
      const body = await c.req.json() as { domainName: string; registrar?: string; expiryDate?: string }
      return c.json(await registerDomainRecord(body.domainName, body.registrar, body.expiryDate))
    } catch (err) {
      return fail(c, err)
    }
  })

  app.post('/api/ssh/check', requireAuth, async (c: any) => {
    try {
      const body = await c.req.json() as { domainName: string; host: string; port?: number }
      return c.json(await checkSshConnectivity(body.domainName, body.host, body.port ?? 22))
    } catch (err) {
      return fail(c, err)
    }
  })

  app.post('/api/quotes/create', requireAuth, async (c: any) => {
    try {
      const body = await c.req.json() as { clientId: string; totalAmount: number }
      return c.json(await createQuoteRecord(body.clientId, body.totalAmount))
    } catch (err) {
      return fail(c, err)
    }
  })

  app.post('/api/quotes/sign', requireAuth, async (c: any) => {
    try {
      const body = await c.req.json() as { quoteId: string; signatureData: string }
      return c.json(await signQuoteRecord(body.quoteId, body.signatureData))
    } catch (err) {
      return fail(c, err)
    }
  })

  app.get('/api/quotes/:id', requireAuth, async (c: any) => {
    try {
      const params = c.req.param()
      const record = await getQuoteRecord(params.id)
      if (!record) return c.json({ ok: false, error: 'not_found' }, 404)
      return c.json(record)
    } catch (err) {
      return fail(c, err)
    }
  })

  // child_process / local python scripts are not available in the Cloudflare Worker runtime.
  app.post('/api/admin/backup', requireAuth, async (c: any) => {
    return c.json({
      ok: false,
      error: 'not_implemented',
      message: 'Backup via child_process is not available in the Cloudflare Worker runtime',
    }, 501)
  })
}
