// @ts-nocheck
import type { Hono } from 'hono'
import { db } from '../lib/db.js'
import { requireAuth } from '../middleware/requireAuth.js'
import crypto from 'node:crypto'

export function registerTasksRoutes(app: Hono<any>): void {
  // GET /api/tasks
  app.get('/api/tasks', requireAuth, async (c: any) => {
    try {
      const authUser = c.get('authUser') || (c.req as any).authUser
      const isEmployee = authUser?.role === 'employee'
      const requestedAssignee = c.c.req.query()('assignee')

      let rows: any[] = []

      if (isEmployee) {
        const employeeName = authUser?.name || ''
        const employeeId = authUser?.uid || ''
        const employeeEmail = authUser?.email || ''

        const aliases: string[] = [employeeName, employeeId, employeeEmail].filter(Boolean)
        if (employeeName === 'נטלי' || employeeEmail.includes('natalie')) {
          aliases.push('נטלי', 'Natalie', 'natalie', 'user_natalie')
        } else if (employeeName === 'ישראל' || employeeEmail.includes('israel')) {
          aliases.push('ישראל', 'Israel', 'israel', 'user_israel')
        } else if (employeeName === 'אופל' || employeeEmail.includes('opel')) {
          aliases.push('אופל', 'Opel', 'opel', 'user_opel')
        } else if (employeeName === 'דוד' || employeeEmail.includes('david')) {
          aliases.push('דוד', 'David', 'david', 'user_david')
        }

        const uniqueAliases = Array.from(new Set(aliases))
        const placeholders = uniqueAliases.map(() => '?').join(', ')
        const likeClauses = uniqueAliases.map(() => 'assignee LIKE ?').join(' OR ')
        const likeParams = uniqueAliases.map(a => `%${a}%`)

        rows = await db.prepare(`
          SELECT * FROM tasks 
          WHERE assignee IN (${placeholders}) OR ${likeClauses}
          ORDER BY created_at DESC
        `).bind(...uniqueAliases, ...likeParams).all() as any[]
      } else {
        if (requestedAssignee) {
          rows = await db.prepare(`
            SELECT * FROM tasks 
            WHERE assignee = ? OR assignee LIKE ?
            ORDER BY created_at DESC
          `).bind(requestedAssignee, `%${requestedAssignee}%`).all() as any[]
        } else {
          rows = await db.prepare('SELECT * FROM tasks ORDER BY created_at DESC').all() as any[]
        }
      }

      const mapped = rows.map(r => {
        let st = r.status
        if (st === 'לביצוע') st = 'todo'
        if (st === 'בתהליך') st = 'in_progress'
        if (st === 'הושלם') st = 'done'
        return {
          id: r.id,
          title: r.title,
          description: r.description,
          status: st,
          projectId: r.project_id,
          assignee: r.assignee,
          assigneeId: r.assignee,
          dueDate: r.due_at || null,
          createdAt: r.created_at,
          created_at: r.created_at,
        }
      })
      return c.json(mapped, 200)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })

  // GET /api/tasks/:id
  app.get('/api/tasks/:id', requireAuth, async (c: any) => {
    try {
      const id = c.req.param('id')
      const r = await db.prepare('SELECT * FROM tasks WHERE id = ?').bind(id).first()
      if (!r) {
        return c.json({ ok: false, error: 'not_found', message: 'משימה לא נמצאה' }, 404)
      }
      let st = r.status
      if (st === 'לביצוע') st = 'todo'
      if (st === 'בתהליך') st = 'in_progress'
      if (st === 'הושלם') st = 'done'
      return c.json({
        id: r.id,
        title: r.title,
        description: r.description,
        status: st,
        projectId: r.project_id,
        assignee: r.assignee,
        assigneeId: r.assignee,
        dueDate: r.due_at || null,
        createdAt: r.created_at,
        created_at: r.created_at,
      }, 200)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })

  // POST /api/tasks
  app.post('/api/tasks', requireAuth, async (c: any) => {
    try {
      const body = (await c.req.json().catch(() => ({}))) as any
      const authUser = c.get('authUser') || (c.req as any).authUser
      const id = body.id || `task_${crypto.randomUUID().slice(0, 12)}`
      const title = body.title || 'משימה חדשה'
      const description = body.description || ''
      const status = body.status || 'todo'
      const projectId = body.projectId || body.project_id || null
      const assignee = body.assignee || body.assigneeId || (authUser?.role === 'employee' ? authUser.name : null)
      const dueAt = body.dueDate || body.due_at || null

      await db.prepare(`
        INSERT INTO tasks (id, title, description, status, project_id, assignee, due_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
      `).bind(id, title, description, status, projectId, assignee, dueAt).run()

      return c.json({
        id,
        title,
        description,
        status,
        projectId,
        assignee,
        assigneeId: assignee,
        dueDate: dueAt,
        created_at: new Date().toISOString()
      }, 201)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })

  // PATCH /api/tasks/:id
  app.patch('/api/tasks/:id', requireAuth, async (c: any) => {
    try {
      const id = c.req.param('id')
      const body = (await c.req.json().catch(() => ({}))) as any
      const existing = await db.prepare('SELECT * FROM tasks WHERE id = ?').bind(id).first()
      if (!existing) {
        return c.json({ ok: false, error: 'not_found', message: 'משימה לא נמצאה' }, 404)
      }

      const updates: string[] = []
      const params: any[] = []

      if (body.title !== undefined) {
        updates.push('title = ?')
        params.push(body.title)
      }
      if (body.description !== undefined) {
        updates.push('description = ?')
        params.push(body.description)
      }
      if (body.status !== undefined) {
        updates.push('status = ?')
        params.push(body.status)
      }
      if (body.projectId !== undefined || body.project_id !== undefined) {
        updates.push('project_id = ?')
        params.push(body.projectId ?? body.project_id)
      }
      if (body.assignee !== undefined || body.assigneeId !== undefined) {
        updates.push('assignee = ?')
        params.push(body.assignee ?? body.assigneeId)
      }
      if (body.dueDate !== undefined || body.due_at !== undefined) {
        updates.push('due_at = ?')
        params.push(body.dueDate ?? body.due_at)
      }

      if (updates.length > 0) {
        params.push(id)
        await db.prepare(`UPDATE tasks SET ${updates.join(', ')} WHERE id = ?`).bind(...params).run()
      }

      const updated = await db.prepare('SELECT * FROM tasks WHERE id = ?').bind(id).first()
      let st = updated.status
      if (st === 'לביצוע') st = 'todo'
      if (st === 'בתהליך') st = 'in_progress'
      if (st === 'הושלם') st = 'done'
      return c.json({
        id: updated.id,
        title: updated.title,
        description: updated.description,
        status: st,
        projectId: updated.project_id,
        assignee: updated.assignee,
        assigneeId: updated.assignee,
        dueDate: updated.due_at || null,
        createdAt: updated.created_at,
        created_at: updated.created_at
      }, 200)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })

  // DELETE /api/tasks/:id
  app.delete('/api/tasks/:id', requireAuth, async (c: any) => {
    try {
      const id = c.req.param('id')
      const existing = await db.prepare('SELECT id FROM tasks WHERE id = ?').bind(id).first()
      if (!existing) {
        return c.json({ ok: false, error: 'not_found', message: 'משימה לא נמצאה' }, 404)
      }
      await db.prepare('DELETE FROM tasks WHERE id = ?').bind(id).run()
      return c.json({ ok: true, id }, 200)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })

  // Activity sub-routes
  app.get('/api/tasks/:id/activity', requireAuth, async (c: any) => {
    try {
      const id = c.req.param('id')
      const rows = await db.prepare('SELECT id, data, updated_at FROM generic_documents WHERE collection = ? ORDER BY updated_at DESC').bind(`tasks/${id}/activity`).all()
      const list = rows.map(r => {
        let d: any = {}
        try { d = JSON.parse(r.data) } catch {}
        return c.json({ id: r.id, ...d, createdAt: d.createdAt || r.updated_at })
      })
      return c.json(list, 200)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })

  app.post('/api/tasks/:id/activity', requireAuth, async (c: any) => {
    try {
      const id = c.req.param('id')
      const body = (await c.req.json().catch(() => ({}))) as any
      const actId = body.id || `act_${crypto.randomUUID().slice(0, 10)}`
      const dataToSave = {
        ...body,
        taskId: id,
        createdAt: body.createdAt || new Date().toISOString(),
      }
      await db.prepare('INSERT INTO generic_documents (collection, id, data) VALUES (?, ?, ?)')
        .bind(`tasks/${id}/activity`, actId, JSON.stringify(dataToSave)).run()
      return c.json({ ok: true, id: actId, ...dataToSave }, 201)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })

  // Task notes sub-routes
  app.get('/api/tasks/:id/taskNotes', requireAuth, async (c: any) => {
    try {
      const id = c.req.param('id')
      const rows = await db.prepare('SELECT id, data, updated_at FROM generic_documents WHERE collection = ? ORDER BY updated_at DESC').bind(`tasks/${id}/taskNotes`).all()
      const list = rows.map(r => {
        let d: any = {}
        try { d = JSON.parse(r.data) } catch {}
        return c.json({ id: r.id, ...d, createdAt: d.createdAt || r.updated_at })
      })
      return c.json(list, 200)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })

  app.post('/api/tasks/:id/taskNotes', requireAuth, async (c: any) => {
    try {
      const id = c.req.param('id')
      const body = (await c.req.json().catch(() => ({}))) as any
      const noteId = body.id || `note_${crypto.randomUUID().slice(0, 10)}`
      const dataToSave = {
        ...body,
        taskId: id,
        createdAt: body.createdAt || new Date().toISOString(),
      }
      await db.prepare('INSERT INTO generic_documents (collection, id, data) VALUES (?, ?, ?)')
        .bind(`tasks/${id}/taskNotes`, noteId, JSON.stringify(dataToSave)).run()
      return c.json({ ok: true, id: noteId, ...dataToSave }, 201)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })
}
