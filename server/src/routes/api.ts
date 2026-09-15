import type { FastifyInstance } from 'fastify'
import { requireAuth } from '../middleware/requireAuth.js'

import { syncReadonlyMaterialsFromMorning } from '../lib/syncReadonlyMaterials.js'

import { registerDomainRecord, checkSshConnectivity } from '../lib/domainSshMonitor.js'
import { createQuoteRecord, signQuoteRecord, getQuoteRecord } from '../lib/quoteSignModule.js'

export async function registerApiRoutes(app: FastifyInstance): Promise<void> {
  app.get('/api/me', { preHandler: requireAuth }, async (req) => ({
    ok: true,
    uid: req.authUser!.uid,
    email: req.authUser!.email ?? null,
  }))

  app.get('/api/integrations/status', { preHandler: requireAuth }, async () => {
    return {
      ok: true,
      integrations: {
        morning: 'pending',
        clockify: 'pending',
      },
    }
  })

  app.post('/api/sync/readonly-materials', { preHandler: requireAuth }, async () => {
    const result = await syncReadonlyMaterialsFromMorning()
    return result
  })

  app.post('/api/domains/register', { preHandler: requireAuth }, async (req) => {
    const body = req.body as { domainName: string; registrar?: string; expiryDate?: string }
    return registerDomainRecord(body.domainName, body.registrar, body.expiryDate)
  })

  app.post('/api/ssh/check', { preHandler: requireAuth }, async (req) => {
    const body = req.body as { domainName: string; host: string; port?: number }
    return checkSshConnectivity(body.domainName, body.host, body.port ?? 22)
  })

  app.post('/api/quotes/create', { preHandler: requireAuth }, async (req) => {
    const body = req.body as { clientId: string; totalAmount: number }
    return createQuoteRecord(body.clientId, body.totalAmount)
  })

  app.post('/api/quotes/sign', { preHandler: requireAuth }, async (req) => {
    const body = req.body as { quoteId: string; signatureData: string }
    return signQuoteRecord(body.quoteId, body.signatureData)
  })

  app.get('/api/quotes/:id', { preHandler: requireAuth }, async (req) => {
    const params = req.params as { id: string }
    return getQuoteRecord(params.id) ?? { ok: false, error: 'not_found' }
  })

  app.post('/api/admin/backup', { preHandler: requireAuth }, async () => {
    const { exec } = await import('child_process')
    const { promisify } = await import('util')
    const execAsync = promisify(exec)
    try {
      const { stdout } = await execAsync('python3 /home/asr11/02_HUB_Dev/os.hub.co.il/scripts/backup_engine.py')
      return JSON.parse(stdout)
    } catch (err: any) {
      return { ok: false, error: err.message }
    }
  })
}
