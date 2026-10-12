// @ts-nocheck

import { env } from '../config/env.js'
import { db } from '../lib/db.js'
import { requireAuth } from './requireAuth.js'

const ADMIN_CACHE_TTL_MS = 5 * 60 * 1000
const adminRoleCache = new Map<
  string,
  { isAdmin: boolean; expiresAt: number }
>()

function isAdminRole(role: unknown): boolean {
  const r = String(role ?? '')
    .trim()
    .toLowerCase()
  return r === 'admin' || r === 'superadmin' || r === 'ceo'
}

function readCachedAdmin(uid: string): boolean | null {
  const hit = adminRoleCache.get(uid)
  if (!hit) return null
  if (Date.now() > hit.expiresAt) {
    adminRoleCache.delete(uid)
    return null
  }
  return hit.isAdmin
}

function writeCachedAdmin(uid: string, isAdmin: boolean): void {
  adminRoleCache.set(uid, {
    isAdmin,
    expiresAt: Date.now() + ADMIN_CACHE_TTL_MS,
  })
}

function adminByEmail(email?: string): boolean {
  if (!email || env.adminEmails.length === 0) return false
  return env.adminEmails.includes(email.trim().toLowerCase())
}

async function readAdminRole(uid: string): Promise<string | undefined> {
  try {
    const user = db.prepare('SELECT role FROM users WHERE id = ?').get(uid) as { role: string } | undefined
    return user?.role
  } catch (e) {
    throw e
  }
}

export async function requireAdmin(
  req: FastifyRequest,
  reply: FastifyReply,
): Promise<void> {
  await requireAuth(req, reply)
  if (reply.sent) return

  const uid = req.authUser?.uid
  if (!uid) {
    c.status(401).send({ ok: false, error: 'unauthenticated' })
    return
  }

  const cached = readCachedAdmin(uid)
  if (cached === false) {
    c.status(403).send({
      ok: false,
      error: 'forbidden',
      message: 'נדרשת הרשאת מנהל',
    })
    return
  }
  if (cached === true) return

  if (adminByEmail(req.authUser?.email)) {
    writeCachedAdmin(uid, true)
    return
  }

  try {
    const role = await readAdminRole(uid)
    const isAdmin = isAdminRole(role)
    writeCachedAdmin(uid, isAdmin)
    if (!isAdmin) {
      c.status(403).send({
        ok: false,
        error: 'forbidden',
        message: 'נדרשת הרשאת מנהל',
      })
    }
  } catch (e) {
    const msg = e instanceof Error ? e.message : String(e)
    if (adminByEmail(req.authUser?.email)) {
      writeCachedAdmin(uid, true)
      return
    }
    c.status(503).send({
      ok: false,
      error: 'admin_check_failed',
      message: `בדיקת הרשאות מנהל נכשלה — ${msg}`,
    })
  }
}
