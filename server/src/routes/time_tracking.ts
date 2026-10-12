// @ts-nocheck
import type { Hono } from 'hono'
import { requireAuth } from '../middleware/requireAuth.js'
import { db } from '../lib/db.js'
import crypto from 'node:crypto'

export function registerTimeTrackingRoutes(app: Hono<any>): void {
  // GET /api/time-entries
  app.get('/api/time-entries', requireAuth, async (c: any) => {
    try {
      const authUser = c.get('authUser') || (c.req as any).authUser
      const projectId = c.c.req.query()('project_id')
      const userIdParam = c.c.req.query()('user_id')

      let sql = `
        SELECT 
          t.*,
          p.name AS project_name,
          tsk.title AS task_title,
          c.name AS client_name
        FROM time_entries t
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN tasks tsk ON t.task_id = tsk.id
        LEFT JOIN clients c ON (t.client_id = c.id OR p.client_id = c.id)
      `
      const where: string[] = []
      const params: any[] = []

      if (projectId) {
        where.push('t.project_id = ?')
        params.push(projectId)
      }

      const targetUserId = userIdParam || (authUser?.role === 'employee' ? authUser.uid : undefined)
      if (targetUserId) {
        where.push('(t.user_id = ? OR t.user_id = ?)')
        params.push(targetUserId, authUser?.name || targetUserId)
      }

      if (where.length > 0) {
        sql += ' WHERE ' + where.join(' AND ')
      }
      sql += ' ORDER BY COALESCE(t.start_time, t.date, t.created_at) DESC'

      const rows = await db.prepare(sql).bind(...params).all()
      const list = Array.isArray(rows) ? rows : (rows as any)?.results || []
      return c.json(list, 200)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })

  // POST /api/time-entries
  app.post('/api/time-entries', requireAuth, async (c: any) => {
    try {
      const body = (await c.req.json().catch(() => ({}))) as any
      const id = body.id || crypto.randomUUID()
      const authUser = c.get('authUser') || (c.req as any).authUser
      const userId = body.user_id || authUser?.uid || authUser?.name || null

      let projectId = body.project_id || body.projectId
      const taskId = body.task_id || body.taskId || null

      if (!projectId && taskId) {
        const task = await db.prepare('SELECT project_id FROM tasks WHERE id = ?').bind(taskId).first()
        if (task?.project_id) projectId = task.project_id
      }

      if (projectId) {
        const exists = await db.prepare('SELECT id FROM projects WHERE id = ?').bind(projectId).first()
        if (!exists) projectId = null
      }

      if (!projectId) {
        const fallback = await db.prepare('SELECT id FROM projects LIMIT 1').first()
        projectId = fallback ? fallback.id : 'proj_test_1'
      }

      let clientId = body.client_id || body.clientId || null
      if (!clientId && projectId) {
        const proj = await db.prepare('SELECT client_id FROM projects WHERE id = ?').bind(projectId).first()
        if (proj?.client_id) clientId = proj.client_id
      }

      const durationSeconds = Number(body.duration_seconds || body.durationSeconds || (body.duration ? Number(body.duration) * 3600 : 0))
      const durationHours = durationSeconds > 0 ? durationSeconds / 3600 : Number(body.duration || 0)
      const startTime = body.start_time || body.startTime || null
      const endTime = body.end_time || body.endTime || null

      await db.prepare(`
        INSERT INTO time_entries (id, project_id, client_id, user_id, duration_seconds, duration, description, task_id, start_time, end_time, date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))
      `).bind(
        id,
        projectId,
        clientId,
        userId,
        durationSeconds,
        durationHours,
        body.description || '',
        taskId,
        startTime,
        endTime,
        body.date || null
      ).run()

      return c.json({
        id,
        project_id: projectId,
        client_id: clientId,
        user_id: userId,
        task_id: taskId,
        duration_seconds: durationSeconds,
        duration: durationHours,
        description: body.description || '',
        start_time: startTime,
        end_time: endTime,
        date: body.date || new Date().toISOString()
      }, 201)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })

  // POST /api/time-entries/heartbeat
  app.post('/api/time-entries/heartbeat', requireAuth, async (c: any) => {
    return c.json({ ok: true }, 200)
  })

  // DELETE /api/time-entries/:id
  app.delete('/api/time-entries/:id', requireAuth, async (c: any) => {
    try {
      const id = c.req.param('id')
      const existing = await db.prepare('SELECT id FROM time_entries WHERE id = ?').bind(id).first()
      if (!existing) {
        return c.json({ ok: false, error: 'not_found', message: 'רשומת זמן לא נמצאה' }, 404)
      }
      await db.prepare('DELETE FROM time_entries WHERE id = ?').bind(id).run()
      return c.json({ ok: true, id }, 200)
    } catch (e: any) {
      return c.json({ ok: false, error: 'internal_error', message: e.message || 'שגיאת שרת' }, 500)
    }
  })
}
