// @ts-nocheck
import type { Context, Next } from 'hono'
import { env } from 'hono/adapter'
import jwt from 'jsonwebtoken'

export type AuthUser = {
  uid: string
  email?: string
  role?: string
}

declare module 'fastify' {
  interface FastifyRequest {
    authUser?: AuthUser
  }
}

function readBearerToken(c: Context): string | null {
  const h = c.req.header('authorization')
  if (typeof h !== 'string' || !h.startsWith('Bearer ')) return null
  const token = h.slice('Bearer '.length).trim()
  return token || null
}

export async function requireAuth(c: Context, next: Next): Promise<void> {
  const token = readBearerToken(c)
  if (!token) {
    return c.json({
      ok: false,
      error: 'unauthenticated',
      message: 'Missing Authorization: Bearer <token>',
    })
    return
  }

  try {
    const decoded = jwt.verify(token, process.env.JWT_SECRET || 'super-secret') as any
    c.set('authUser', {
      uid: decoded.uid,
      email: decoded.email,
      role: decoded.role,
    })
    await next()
  } catch {
    return c.json({
      ok: false,
      error: 'invalid_token',
      message: 'Invalid or expired token',
    })
  }
}
