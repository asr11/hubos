// @ts-nocheck
import type { Hono } from 'hono'
import { requireAuth } from '../middleware/requireAuth.js'
import { db } from '../lib/db.js'
import crypto from 'node:crypto'

export function registerProjectsRoutes(app: Hono<any>): void {
  app.get('/api/projects', requireAuth, async (c: any) => {
    const rows = await db.prepare('SELECT * FROM projects ORDER BY created_at DESC').all()
    return c.json(rows.map(r => {
      let status = r.status
      if (status === 'פעיל') status = 'active'
      else if (status === 'מושהה') status = 'paused'
      else if (status === 'הושלם') status = 'completed'
      
      return {
        ...r,
        clientId: r.client_id,
        budgetType: r.budget_type,
        budgetAmount: r.budget_amount,
        hourlyRate: r.hourly_rate,
        autoInvoiceType: r.auto_invoice_type,
        createdAt: r.created_at,
        status
      }
    }))
  })

  app.get('/api/projects/:id', requireAuth, async (c: any) => {
    const id = c.req.param('id')
    const row = await db.prepare('SELECT * FROM projects WHERE id = ?').bind(id).first()
    if (!row) {
      return c.json({ error: 'Project not found' }, 404)
    }
    return c.json(row)
  })

  app.post('/api/projects', requireAuth, async (c: any) => {
    const body = (await c.req.json().catch(() => ({}))) as any
    const id = body.id || crypto.randomUUID()
    await db.prepare(`
      INSERT INTO projects (id, name, client_id, budget_type, budget_amount, hourly_rate, auto_invoice_type, status)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    `).bind(
      id,
      body.name,
      body.client_id || null,
      body.budget_type || 'no_limit',
      body.budget_amount || null,
      body.hourly_rate || null,
      body.auto_invoice_type || null,
      body.status || 'active'
    ).run()
    return c.json({ id, ...body }, 201)
  })

  app.patch('/api/projects/:id', requireAuth, async (c: any) => {
    const id = c.req.param('id')
    const body = (await c.req.json().catch(() => ({}))) as any
    
    const updates: string[] = []
    const values: any[] = []
    
    if (body.name !== undefined) { updates.push('name = ?'); values.push(body.name) }
    if (body.clientId !== undefined || body.client_id !== undefined) { 
      updates.push('client_id = ?')
      values.push(body.clientId ?? body.client_id) 
    }
    if (body.budgetType !== undefined || body.budget_type !== undefined) { 
      updates.push('budget_type = ?')
      values.push(body.budgetType ?? body.budget_type) 
    }
    if (body.budgetAmount !== undefined || body.budget_amount !== undefined) { 
      updates.push('budget_amount = ?')
      values.push(body.budgetAmount ?? body.budget_amount) 
    }
    if (body.hourlyRate !== undefined || body.hourly_rate !== undefined) { 
      updates.push('hourly_rate = ?')
      values.push(body.hourlyRate ?? body.hourly_rate) 
    }
    if (body.autoInvoiceType !== undefined || body.auto_invoice_type !== undefined) { 
      updates.push('auto_invoice_type = ?')
      values.push(body.autoInvoiceType ?? body.auto_invoice_type) 
    }
    if (body.status !== undefined) { 
      updates.push('status = ?')
      values.push(body.status) 
    }
    
    if (updates.length > 0) {
      values.push(id)
      await db.prepare(`UPDATE projects SET ${updates.join(', ')} WHERE id = ?`).bind(...values).run()
    }
    
    return c.json({ id, ...body })
  })

  app.delete('/api/projects/:id', requireAuth, async (c: any) => {
    const id = c.req.param('id')
    await db.prepare("UPDATE projects SET status = 'archived' WHERE id = ?").bind(id).run()
    return c.json({ ok: true })
  })
}
