import type { FastifyInstance } from 'fastify'
import bcrypt from 'bcryptjs'
import jwt from 'jsonwebtoken'
import { db } from '../lib/db.js'

export async function registerAuthRoutes(app: FastifyInstance) {
  app.post('/api/auth/login', async (request, reply) => {
    const { email, password } = request.body as Record<string, string>

    if (!email || !password) {
      return reply.code(400).send({ ok: false, error: 'missing_credentials' })
    }

    const user = db.prepare('SELECT * FROM users WHERE email = ?').get(email) as any

    if (!user) {
      return reply.code(401).send({ ok: false, error: 'invalid_credentials' })
    }

    const match = bcrypt.compareSync(password, user.password_hash)
    if (!match) {
      return reply.code(401).send({ ok: false, error: 'invalid_credentials' })
    }

    // Usually use env.JWT_SECRET, but since we are just scaffolding this and no secret is in env, fallback
    const token = jwt.sign({ uid: user.id, role: user.role, email: user.email }, process.env.JWT_SECRET || 'super-secret', {
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
