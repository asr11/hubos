// @ts-nocheck
import type { Hono } from 'hono'
import { requireAuth } from '../middleware/requireAuth.js'
import { db } from '../lib/db.js'
import { createQuoteRecord, signQuoteRecord, getQuoteRecord } from '../lib/quoteSignModule.js'

export function registerQuotesRoutes(app: Hono<any>): void {
  // GET all quotes
  app.get('/api/quotes', requireAuth, async (c: any) => {
    const { results } = await db.prepare(`
      SELECT q.*, c.name as client_name
      FROM quotes q
      LEFT JOIN clients c ON q.client_id = c.id
      ORDER BY q.created_at DESC
    `).all()
    return c.json({ ok: true, quotes: results })
  })

  // GET single quote with items
  app.get('/api/quotes/:id', async (c: any) => {
    // Note: Public for signing page if needed, or we can use requireAuth.
    // ClientQuoteView uses it, might be public.
    const id = c.req.param('id')
    const quote = await getQuoteRecord(id)
    if (!quote) return c.json({ ok: false, error: 'not_found' }, 404)
    
    const { results: items } = await db.prepare('SELECT * FROM quote_items WHERE quote_id = ?').bind(quote.id).all()
    
    return c.json({ ok: true, quote: { ...quote, items } })
  })

  // POST create quote
  app.post('/api/quotes', requireAuth, async (c: any) => {
    const body = (await c.req.json().catch(() => ({}))) as { clientId: string; totalAmount: number; items?: any[] }
    const res = await createQuoteRecord(body.clientId, body.totalAmount)
    
    if (res.ok && body.items && body.items.length > 0) {
      const stmt = db.prepare('INSERT INTO quote_items (id, quote_id, description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)')
      const batch = body.items.map(i => 
        stmt.bind(`qi_${Date.now()}_${Math.random().toString(36).slice(2,6)}`, res.id, i.description, i.quantity, i.unit_price, i.total_price)
      )
      await db.batch(batch)
    }
    
    return c.json(res)
  })

  // PUT update quote
  app.put('/api/quotes/:id', requireAuth, async (c: any) => {
    const id = c.req.param('id')
    const body = (await c.req.json().catch(() => ({}))) as { status?: string; totalAmount?: number; items?: any[] }
    
    const updates: string[] = []
    const values: any[] = []
    if (body.status) { updates.push('status = ?'); values.push(body.status) }
    if (body.totalAmount !== undefined) { updates.push('total_amount = ?'); values.push(body.totalAmount) }
    
    if (updates.length > 0) {
      values.push(id)
      await db.prepare(`UPDATE quotes SET ${updates.join(', ')} WHERE id = ?`).bind(...values).run()
    }
    
    if (body.items) {
      await db.prepare('DELETE FROM quote_items WHERE quote_id = ?').bind(id).run()
      if (body.items.length > 0) {
        const stmt = db.prepare('INSERT INTO quote_items (id, quote_id, description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)')
        const batch = body.items.map(i => 
          stmt.bind(`qi_${Date.now()}_${Math.random().toString(36).slice(2,6)}`, id, i.description, i.quantity, i.unit_price, i.total_price)
        )
        await db.batch(batch)
      }
    }
    
    return c.json({ ok: true })
  })

  // DELETE quote
  app.delete('/api/quotes/:id', requireAuth, async (c: any) => {
    const id = c.req.param('id')
    await db.prepare('DELETE FROM quote_items WHERE quote_id = ?').bind(id).run()
    await db.prepare('DELETE FROM quotes WHERE id = ?').bind(id).run()
    return c.json({ ok: true })
  })

  // POST sign quote
  app.post('/api/quotes/:id/sign', async (c: any) => {
    const id = c.req.param('id')
    const body = (await c.req.json().catch(() => ({}))) as { signatureData: string }
    const result = await signQuoteRecord(id, body.signatureData)
    return c.json(result)
  })
}
