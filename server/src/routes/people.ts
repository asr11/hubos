// @ts-nocheck
import type { Hono } from 'hono'
import { requireAuth } from '../middleware/requireAuth.js'
import { db, logSystemEvent } from '../lib/db.js'

function normalizeIsraeliPhone(phone: string): string {
  if (!phone) return ''
  let cleaned = phone.replace(/\D/g, '')
  if (cleaned.startsWith('0')) {
    cleaned = '972' + cleaned.slice(1)
  }
  if (!cleaned.startsWith('+')) {
    cleaned = '+' + cleaned
  }
  return c.json(cleaned)
}

export function registerPeopleRoutes(app: Hono<any>): void {
  // 1. Candidate count endpoints - MUST be registered BEFORE /api/people/:id
  const getCandidateCountHandler = async (c: any) => {
    try {
      const row: any = await db.prepare("SELECT COUNT(*) as count FROM clients WHERE phone IS NOT NULL AND phone != ''").first()
      let candidateCount = 0
      if (typeof row === 'number') {
        candidateCount = row
      } else if (row && typeof row.count !== 'undefined') {
        candidateCount = Number(row.count)
      } else if (row && typeof row['COUNT(*)'] !== 'undefined') {
        candidateCount = Number(row['COUNT(*)'])
      } else if (row && Object.values(row).length > 0) {
        candidateCount = Number(Object.values(row)[0]) || 0
      }
      return c.json({ ok: true, candidateCount })
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  }

  app.get('/api/people/candidates', getCandidateCountHandler)
  app.get('/api/candidates', getCandidateCountHandler)

  // Candidates list endpoint
  app.get('/api/people/candidates/list', requireAuth, async (c: any) => {
    try {
      const rows = await db.prepare("SELECT id, name, phone, email FROM clients WHERE phone IS NOT NULL AND phone != '' ORDER BY name ASC").all()
      const list = Array.isArray(rows) ? rows : ((rows as any)?.results || [])
      return c.json({ ok: true, candidates: list })
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  })

  // 2. People list
  app.get('/api/people', requireAuth, async (c: any) => {
    try {
      const rows = await db.prepare('SELECT * FROM people ORDER BY created_at DESC').all()
      const list = Array.isArray(rows) ? rows : ((rows as any)?.results || [])
      return c.json({ ok: true, people: list })
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  })

  // 3. Single person by ID
  app.get('/api/people/:id', requireAuth, async (c: any) => {
    const id = c.req.param('id')
    try {
      const row = await db.prepare('SELECT * FROM people WHERE id = ?').bind(id).first()
      if (!row) return c.json({ ok: false, error: 'not_found' }, 404)
      return c.json({ ok: true, person: row })
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  })

  // 4. Create person
  app.post('/api/people', requireAuth, async (c: any) => {
    const body = (await c.req.json().catch(() => ({}))) as any
    const phone = normalizeIsraeliPhone(body.phone || '')
    const id = body.id || crypto.randomUUID()
    const now = new Date().toISOString()
    
    // Check duplicates
    if (phone) {
      const existing = await db.prepare('SELECT id FROM people WHERE phone = ?').bind(phone).first()
      if (existing) return c.json({ ok: false, error: 'phone_exists' }, 400)
    }
    if (body.telegram_user_id) {
      const existing = await db.prepare('SELECT id FROM people WHERE telegram_user_id = ?').bind(body.telegram_user_id).first()
      if (existing) return c.json({ ok: false, error: 'telegram_user_id_exists' }, 400)
    }

    try {
      await db.prepare(`
        INSERT INTO people (id, full_name, phone, created_at, updated_at, telegram_user_id, primary_email, role, referred_by_partner_id, morning_client_id, client_id, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      `).bind(
        id,
        body.full_name || 'ללא שם',
        phone,
        now,
        now,
        body.telegram_user_id || null,
        body.primary_email || null,
        body.role || null,
        body.referred_by_partner_id || null,
        body.morning_client_id || null,
        body.client_id || null,
        body.user_id || null
      ).run()
      await logSystemEvent('PEOPLE_CREATED', id, 'Created person ' + id)
      return c.json({ ok: true, id }, 201)
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  })

  // 5. Update person
  app.patch('/api/people/:id', requireAuth, async (c: any) => {
    const id = c.req.param('id')
    const body = (await c.req.json().catch(() => ({}))) as any
    const now = new Date().toISOString()
    try {
      const setClauses: string[] = []
      const values: any[] = []
      
      for (const [key, value] of Object.entries(body)) {
        if (key === 'phone') {
          setClauses.push(key + ' = ?')
          values.push(normalizeIsraeliPhone(value as string))
        } else if (key !== 'id' && key !== 'created_at') {
          setClauses.push(key + ' = ?')
          values.push(value)
        }
      }
      setClauses.push('updated_at = ?')
      values.push(now)
      values.push(id)

      if (setClauses.length === 1) return c.json({ ok: true })

      await db.prepare('UPDATE people SET ' + setClauses.join(', ') + ' WHERE id = ?').bind(...values).run()
      await logSystemEvent('PEOPLE_UPDATED', id || '', 'Updated person ' + id)
      return c.json({ ok: true })
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  })

  // 6. Delete person
  app.delete('/api/people/:id', requireAuth, async (c: any) => {
    const id = c.req.param('id')
    try {
      await db.prepare('DELETE FROM people WHERE id = ?').bind(id).run()
      await logSystemEvent('PEOPLE_DELETED', id || '', 'Deleted person ' + id)
      return c.json({ ok: true })
    } catch (err: any) {
      return c.json({ ok: false, error: err.message }, 500)
    }
  })
}
