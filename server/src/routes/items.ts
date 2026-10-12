// @ts-nocheck
import type { Hono } from 'hono'
import { requireAuth } from '../middleware/requireAuth.js'
import { db } from '../lib/db.js'
import crypto from 'node:crypto'

export function registerItemsRoutes(app: Hono<any>): void {
  app.get('/api/items', requireAuth, async (c: any) => {
    const rows = await db.prepare('SELECT * FROM items ORDER BY created_at DESC').all()
    return c.json(rows)
  })

  app.get('/api/items/:id', requireAuth, async (c: any) => {
    const id = c.req.param('id')
    const row = await db.prepare('SELECT * FROM items WHERE id = ?').bind(id).first()
    if (!row) {
      return c.json({ error: 'Item not found' }, 404)
    }
    return c.json(row)
  })

  app.post('/api/items', requireAuth, async (c: any) => {
    const body = (await c.req.json().catch(() => ({}))) as any
    const id = body.id || crypto.randomUUID()
    await db.prepare(`
      INSERT INTO items (id, name, description, price, currency)
      VALUES (?, ?, ?, ?, ?)
    `).bind(id, body.name, body.description || '', body.price, body.currency || 'ILS').run()
    return c.json({ id, ...body }, 201)
  })

  app.put('/api/items/:id', requireAuth, async (c: any) => {
    const id = c.req.param('id')
    const body = (await c.req.json().catch(() => ({}))) as any
    await db.prepare(`
      UPDATE items
      SET name = ?, description = ?, price = ?, currency = ?
      WHERE id = ?
    `).bind(body.name, body.description || '', body.price, body.currency || 'ILS', id).run()
    return c.json({ id, ...body })
  })

  app.delete('/api/items/:id', requireAuth, async (c: any) => {
    const id = c.req.param('id')
    await db.prepare('DELETE FROM items WHERE id = ?').bind(id).run()
    return c.json({ ok: true })
  })
}
