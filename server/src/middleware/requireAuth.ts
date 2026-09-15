import type { FastifyReply, FastifyRequest } from 'fastify'
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

function readBearerToken(req: FastifyRequest): string | null {
  const h = req.headers.authorization
  if (typeof h !== 'string' || !h.startsWith('Bearer ')) return null
  const token = h.slice('Bearer '.length).trim()
  return token || null
}

export async function requireAuth(req: FastifyRequest, reply: FastifyReply): Promise<void> {
  const token = readBearerToken(req)
  if (!token) {
    reply.code(401).send({
      ok: false,
      error: 'unauthenticated',
      message: 'Missing Authorization: Bearer <token>',
    })
    return
  }

  try {
    const decoded = jwt.verify(token, process.env.JWT_SECRET || 'super-secret') as any
    req.authUser = {
      uid: decoded.uid,
      email: decoded.email,
      role: decoded.role,
    }
  } catch {
    reply.code(401).send({
      ok: false,
      error: 'invalid_token',
      message: 'Invalid or expired token',
    })
  }
}
