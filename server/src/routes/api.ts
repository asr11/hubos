// @ts-nocheck
import { Hono } from 'hono'
import { requireAuth } from '../middleware/requireAuth.js'

import { syncReadonlyMaterialsFromMorning } from '../lib/syncReadonlyMaterials.js'
import { registerDomainRecord, checkSshConnectivity } from '../lib/domainSshMonitor.js'
import { createQuoteRecord, signQuoteRecord, getQuoteRecord } from '../lib/quoteSignModule.js'
import { registerVaultRoutes } from './vault.js'
import { registerCeoActionsRoutes } from './ceoActions.js'

export async function registerApiRoutes(app: Hono<any>) {
  // Register specific modules
  await registerVaultRoutes(app)
  await registerCeoActionsRoutes(app)
  
  app.get('/api/me', { preHandler: requireAuth }, async (req) => ({
    ok: true,
    uid: (c.get('authUser') as any).uid,
    email: (c.get('authUser') as any).email ?? null,
  }))

  app.get('/api/integrations/status', requireAuth , async (c: any) => {
    return {
      ok: true,
      integrations: {
        morning: 'pending',
        clockify: 'pending',
      },
    }
  })

  app.post('/api/sync/readonly-materials', requireAuth , async (c: any) => {
    const result = await syncReadonlyMaterialsFromMorning()
    return c.json(result)
  })

  app.post('/api/domains/register', requireAuth , async (c: any) => {
    const body = await c.req.json() as { domainName: string; registrar?: string; expiryDate?: string }
    return registerDomainRecord(body.domainName, body.registrar, body.expiryDate)
  })

  app.post('/api/ssh/check', requireAuth , async (c: any) => {
    const body = await c.req.json() as { domainName: string; host: string; port?: number }
    return checkSshConnectivity(body.domainName, body.host, body.port ?? 22)
  })

  app.post('/api/quotes/create', requireAuth , async (c: any) => {
    const body = await c.req.json() as { clientId: string; totalAmount: number }
    return createQuoteRecord(body.clientId, body.totalAmount)
  })

  app.post('/api/quotes/sign', requireAuth , async (c: any) => {
    const body = await c.req.json() as { quoteId: string; signatureData: string }
    return signQuoteRecord(body.quoteId, body.signatureData)
  })

  app.get('/api/quotes/:id', requireAuth , async (c: any) => {
    const params = c.req.param()
    return getQuoteRecord(params.id) ?? { ok: false, error: 'not_found' }
  })

  app.post('/api/admin/backup', requireAuth , async (c: any) => {
    const { exec } = await import('child_process')
    const { promisify } = await import('util')
    const execAsync = promisify(exec)
    try {
      const { stdout } = await execAsync('python3 /home/asr11/02_HUB_Dev/os.hub.co.il/scripts/backup_engine.py')
      return JSON.parse(stdout)
    } catch (err: any) {
      return c.json({ ok: false, error: err.message })
    }
  })
}
