// @ts-nocheck

import { db } from '../lib/db.js'
import { requireAuth } from '../middleware/requireAuth.js'
import { requireAdmin } from '../middleware/requireAdmin.js'
import bcrypt from 'bcryptjs'
import crypto from 'node:crypto'

export async function registerUsersRoutes(app: Hono<any>) {
  // GET /api/users & GET /api/auth/users
  app.get('/api/users', requireAuth , async (c: any) => {
    const users = db.prepare('SELECT id, email, role, created_at FROM users').all()
    return c.json(users.map((u: any) => ({
      id: u.id,
      uid: u.id,
      email: u.email,
      name: u.email ? u.email.split('@')[0] : 'משתמש',
      role: u.role || 'user',
      active: true
    })))
  })

  app.get('/api/auth/users', requireAuth , async (c: any) => {
    const users = db.prepare('SELECT id, email, role, created_at FROM users').all()
    return c.json({ ok: true, users })
  })

  // POST /api/auth/users
  app.post('/api/auth/users', requireAdmin , async (c: any) => {
    const { email, password, role } = await c.req.json() as any
    if (!email || !password) {
      return c.json({ ok: false, error: 'missing_fields' }, 400)
    }

    try {
      const hash = bcrypt.hashSync(password, 10)
      const id = crypto.randomUUID()
      db.prepare('INSERT INTO users (id, email, password_hash, role) VALUES (?, ?, ?, ?)').run(
        id, email, hash, role || 'user'
      )
      return c.json({ ok: true, user: { id, email, role: role || 'user' } })
    } catch (e: any) {
      if (e.message.includes('UNIQUE constraint failed')) {
        return c.json({ ok: false, error: 'email_exists' }, 409)
      }
      throw e
    }
  })

  // PATCH /api/auth/users/:id
  app.patch('/api/auth/users/:id', requireAdmin , async (c: any) => {
    const { id } = c.req.param() as any
    const { email, password, role } = await c.req.json() as any
    
    let query = 'UPDATE users SET '
    const params: any[] = []
    const updates: string[] = []

    if (email) {
      updates.push('email = ?')
      params.push(email)
    }
    if (password) {
      updates.push('password_hash = ?')
      params.push(bcrypt.hashSync(password, 10))
    }
    if (role) {
      updates.push('role = ?')
      params.push(role)
    }

    if (updates.length === 0) {
      return c.json({ ok: false, error: 'no_updates_provided' }, 400)
    }

    query += updates.join(', ') + ' WHERE id = ?'
    params.push(id)

    try {
      const info = db.prepare(query).run(...params)
      if (info.changes === 0) {
        return c.json({ ok: false, error: 'user_not_found' }, 404)
      }
      return c.json({ ok: true })
    } catch (e: any) {
      if (e.message.includes('UNIQUE constraint failed')) {
        return c.json({ ok: false, error: 'email_exists' }, 409)
      }
      throw e
    }
  })

  // DELETE /api/auth/users/:id
  app.delete('/api/auth/users/:id', requireAdmin , async (c: any) => {
    const { id } = c.req.param() as any
    const info = db.prepare('DELETE FROM users WHERE id = ?').run(id)
    if (info.changes === 0) {
      return c.json({ ok: false, error: 'user_not_found' }, 404)
    }
    return c.json({ ok: true })
  })
}
