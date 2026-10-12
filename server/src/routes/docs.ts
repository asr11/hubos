// @ts-nocheck
import type { Hono } from 'hono'
import { db } from '../lib/db.js'

export async function registerDocsRoutes(app: Hono<any>) {
  app.get('/api/docs', async (c: any) => {
    try {
      const rows = await db.prepare('SELECT * FROM docs').all()
      return c.json({ ok: true, docs: rows })
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  })

  app.get('/api/docs/:id', async (c: any) => {
    const id = c.req.param('id')
    try {
      const doc = await db.prepare('SELECT * FROM docs WHERE id = ?').bind(id).first()
      if (!doc) {
        return c.json({ ok: false, error: 'Not found' }, 404)
      }
      return c.json({ ok: true, doc })
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  })

  app.post('/api/docs', async (c: any) => {
    const body = await c.req.json().catch(() => ({}))
    const id = body.id || crypto.randomUUID()
    const data = JSON.stringify(body)
    try {
      await db.prepare('INSERT INTO docs (id, data) VALUES (?, ?)').bind(id, data).run()
      return c.json({ ok: true, id })
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  })

  app.put('/api/docs/:id', async (c: any) => {
    const id = c.req.param('id')
    const body = await c.req.json().catch(() => ({}))
    const data = JSON.stringify(body)
    try {
      await db.prepare('UPDATE docs SET data = ? WHERE id = ?').bind(data, id).run()
      return c.json({ ok: true })
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  })

  app.delete('/api/docs/:id', async (c: any) => {
    const id = c.req.param('id')
    try {
      await db.prepare('DELETE FROM docs WHERE id = ?').bind(id).run()
      return c.json({ ok: true })
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  })
}
