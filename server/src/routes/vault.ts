// @ts-nocheck
import { Hono } from 'hono'
import { requireAuth } from '../middleware/requireAuth.js'
import { db } from '../lib/db.js'
import { encrypt, decrypt } from '../lib/encryption.js'

export async function registerVaultRoutes(app: Hono<any>) {
  const collection = 'passwords_vault'

  // GET all passwords
  app.get('/api/passwords_vault', requireAuth , async (c: any) => {
    const rows = db.prepare('SELECT id, data, created_at, updated_at FROM generic_documents WHERE collection = ?').all(collection)
    const uid = (c.get('authUser') as any).uid
    const isAdmin = (c.get('authUser') as any).role === 'admin'

    return rows.map((r: any) => {
      const data = JSON.parse(r.data)
      const visibleUsers = data.visibleUserIds || []
      
      const canView = isAdmin || visibleUsers.includes(uid)
      
      return {
        id: r.id,
        ...data,
        password: canView && data.password ? decrypt(data.password) : '***',
        createdAt: r.created_at,
        updatedAt: r.updated_at,
      }
    })
  })

  // POST new password
  app.post('/api/passwords_vault', requireAuth , async (c: any) => {
    const data = await c.req.json<any>() /* any */
    
    if (data.password) {
      data.password = encrypt(data.password)
    }

    const id = `vault_${Math.random().toString(36).substr(2, 9)}`
    
    try {
      db.prepare('INSERT INTO generic_documents (collection, id, data) VALUES (?, ?, ?)')
        .run(collection, id, JSON.stringify(data))
      return c.json({ ok: true, id })
    } catch (e: any) {
      return c.json({}, 500)
      return c.json({ ok: false, error: e.message })
    }
  })

  // PUT update password
  app.put('/api/passwords_vault/:id', requireAuth , async (c: any) => {
    const { id } = c.req.param()
    const data = await c.req.json<any>() /* any */
    const uid = (c.get('authUser') as any).uid
    const isAdmin = (c.get('authUser') as any).role === 'admin'

    // Check permissions before update
    const row = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?').get(collection, id) as any
    if (!row) {
      return c.json({}, 404)
      return c.json({ ok: false, error: 'Not found' })
    }

    const existingData = JSON.parse(row.data)
    const canEdit = isAdmin || (existingData.visibleUserIds && existingData.visibleUserIds.includes(uid))

    if (!canEdit) {
      return c.json({}, 403)
      return c.json({ ok: false, error: 'Permission denied' })
    }

    // Only encrypt if it's a new password being set (not empty or just stars)
    if (data.password && data.password !== '***') {
      data.password = encrypt(data.password)
    } else {
      data.password = existingData.password // keep existing encrypted password
    }

    try {
      db.prepare('UPDATE generic_documents SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE collection = ? AND id = ?')
        .run(JSON.stringify(data), collection, id)
      return c.json({ ok: true })
    } catch (e: any) {
      return c.json({}, 500)
      return c.json({ ok: false, error: e.message })
    }
  })

  // DELETE password
  app.delete('/api/passwords_vault/:id', requireAuth , async (c: any) => {
    const { id } = c.req.param()
    const uid = (c.get('authUser') as any).uid
    const isAdmin = (c.get('authUser') as any).role === 'admin'

    const row = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?').get(collection, id) as any
    if (!row) return { ok: true }

    const existingData = JSON.parse(row.data)
    const canEdit = isAdmin || (existingData.visibleUserIds && existingData.visibleUserIds.includes(uid))

    if (!canEdit) {
      return c.json({}, 403)
      return c.json({ ok: false, error: 'Permission denied' })
    }

    try {
      db.prepare('DELETE FROM generic_documents WHERE collection = ? AND id = ?').run(collection, id)
      return c.json({ ok: true })
    } catch (e: any) {
      return c.json({}, 500)
      return c.json({ ok: false, error: e.message })
    }
  })
}
