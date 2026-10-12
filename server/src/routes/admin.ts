// @ts-nocheck
import type { Hono } from 'hono'
import { requireAuth } from '../middleware/requireAuth.js'
import { db } from '../lib/db.js'
import crypto from 'node:crypto'

export function registerAdminRoutes(app: Hono<any>): void {
  app.get('/api/admin/tenants', requireAuth, async (c: any) => {
    const rows = await db.prepare('SELECT * FROM tenants ORDER BY created_at DESC').all()
    return c.json(rows)
  })

  app.post('/api/admin/tenants', requireAuth, async (c: any) => {
    const body = (await c.req.json().catch(() => ({}))) as any
    if (!body.name) {
      return c.json({ error: 'Name is required' }, 400)
    }
    const id = body.id || crypto.randomUUID()
    await db.prepare(`
      INSERT INTO tenants (id, name)
      VALUES (?, ?)
    `).bind(
      id,
      body.name
    ).run()
    
    return c.json({ id, name: body.name }, 201)
  })
}
