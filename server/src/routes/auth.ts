// @ts-nocheck
import { Hono } from 'hono'
import bcrypt from 'bcryptjs'
import jwt from 'jsonwebtoken'
import { db } from '../lib/db.js'

export async function registerAuthRoutes(app: Hono<any>) {
  app.post('/api/auth/login', async (c: any) => {
    const { email, password } = await c.req.json() as Record<string, string>

    if (!email || !password) {
      return c.json({ ok: false, error: 'missing_credentials' }, 400)
    }

    const user = db.prepare('SELECT * FROM users WHERE email = ?').get(email) as any

    if (!user) {
      return c.json({ ok: false, error: 'invalid_credentials' }, 401)
    }

    const match = bcrypt.compareSync(password, user.password_hash)
    if (!match) {
      return c.json({ ok: false, error: 'invalid_credentials' }, 401)
    }

    if (!process.env.JWT_SECRET) return c.json({ ok: false, error: 'server_misconfigured', message: 'JWT_SECRET missing' }, 500);
    const token = jwt.sign({ uid: user.id, role: user.role, email: user.email }, process.env.JWT_SECRET, {
      expiresIn: '7d'
    })

    return {
      ok: true,
      token,
      user: {
        id: user.id,
        email: user.email,
        role: user.role
      }
    }
  })
}
