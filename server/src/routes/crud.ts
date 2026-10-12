// @ts-nocheck
import { requireAuth } from '../middleware/requireAuth.js'
import { Hono } from 'hono'
import { db } from '../lib/db.js'
import crypto from 'node:crypto'

const corsHeaders: Record<string, string> = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type, Authorization',
}

function safeParseJson(data: string): Record<string, unknown> {
  try {
    return JSON.parse(data)
  } catch {
    return {}
  }
}

async function fetchAll(stmt: any, ...params: any[]) {
  const bound = params.length > 0 && typeof stmt.bind === 'function' ? stmt.bind(...params) : stmt;
  const res = await bound.all();
  return (res && Array.isArray(res.results)) ? res.results : (Array.isArray(res) ? res : []);
}

async function fetchFirst(stmt: any, ...params: any[]) {
  const bound = params.length > 0 && typeof stmt.bind === 'function' ? stmt.bind(...params) : stmt;
  let res;
  if (typeof bound.first === 'function') {
    res = await bound.first();
  } else if (typeof bound.get === 'function') {
    res = await bound.get();
  }
  return res;
}

async function executeRun(stmt: any, ...params: any[]) {
  const bound = params.length > 0 && typeof stmt.bind === 'function' ? stmt.bind(...params) : stmt;
  return await bound.run();
}

export async function registerCrudRoutes(app: Hono<any>) {
  // Get all documents for a collection
  app.get('/api/:collection', requireAuth , async (c: any) => {
    try {
      const { collection } = c.req.param()
      const stmt = db.prepare('SELECT id, data FROM generic_documents WHERE collection = ?')
      let rows = (await fetchAll(stmt, collection)) as { id: string; data: string }[]
      if (rows.length === 0 && collection === 'financeIncomes') {
        const stmtInvoices = db.prepare('SELECT id, data FROM generic_documents WHERE collection = ?')
        rows = (await fetchAll(stmtInvoices, 'invoices')) as { id: string; data: string }[]
      }
      const results = rows.map(r => ({ id: r.id, ...safeParseJson(r.data) }))
      return c.json(results)
    } catch (err: any) {
      return c.json({ error: err.message }, 500)
    }
  })

  // Get a single document
  app.get('/api/:collection/:id', requireAuth , async (c: any) => {
    try {
      const { collection, id } = c.req.param()
      const stmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
      let row = (await fetchFirst(stmt, collection, id)) as { data: string } | undefined
      if (!row && collection === 'financeIncomes') {
        const stmtInvoices = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
        row = (await fetchFirst(stmtInvoices, 'invoices', id)) as { data: string } | undefined
      }
      if (!row) {
        return c.json({ error: 'Not found' }, 404)
        return
      }
      return c.json({ id, ...safeParseJson(row.data) })
    } catch (err: any) {
      return c.json({ error: err.message }, 500)
    }
  })

  // Create a document
  app.post('/api/:collection', requireAuth , async (c: any) => {
    try {
      const { collection } = c.req.param()
      const body = await c.req.json<any>() /* any */
      const id = body.id || crypto.randomUUID()
      
      // Remove id from the JSON body if it exists
      const dataToSave = { ...body }
      delete dataToSave.id
      
      const stmt = db.prepare('INSERT INTO generic_documents (id, collection, data) VALUES (?, ?, ?)')
      await executeRun(stmt, id, collection, JSON.stringify(dataToSave))
      return c.json({ id, ...dataToSave })
    } catch (err: any) {
      return c.json({ error: err.message }, 500)
    }
  })

  // Update a document (PUT)
  app.put('/api/:collection/:id', requireAuth , async (c: any) => {
    try {
      const { collection, id } = c.req.param()
      const body = await c.req.json<any>() /* any */
      
      const dataToSave = { ...body }
      delete dataToSave.id
      
      // Upsert logic
      const stmt = db.prepare(`
        INSERT INTO generic_documents (id, collection, data) 
        VALUES (?, ?, ?)
        ON CONFLICT(id) DO UPDATE SET data = excluded.data
      `)
      await executeRun(stmt, id, collection, JSON.stringify(dataToSave))
      return c.json({ id, ...dataToSave })
    } catch (err: any) {
      return c.json({ error: err.message }, 500)
    }
  })
  
  // Update a document (PATCH)
  app.patch('/api/:collection/:id', requireAuth , async (c: any) => {
    try {
      const { collection, id } = c.req.param()
      const body = await c.req.json<any>() /* any */
      
      // Get existing
      const getStmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
      const row = (await fetchFirst(getStmt, collection, id)) as { data: string } | undefined
      
      let dataToSave = { ...body }
      delete dataToSave.id
      
      if (row) {
        dataToSave = { ...JSON.parse(row.data), ...dataToSave }
      }
      
      const stmt = db.prepare(`
        INSERT INTO generic_documents (id, collection, data) 
        VALUES (?, ?, ?)
        ON CONFLICT(id) DO UPDATE SET data = excluded.data
      `)
      await executeRun(stmt, id, collection, JSON.stringify(dataToSave))
      return c.json({ id, ...dataToSave })
    } catch (err: any) {
      return c.json({ error: err.message }, 500)
    }
  })

  // Delete a document
  app.delete('/api/:collection/:id', requireAuth , async (c: any) => {
    try {
      const { collection, id } = c.req.param()
      const stmt = db.prepare('DELETE FROM generic_documents WHERE collection = ? AND id = ?')
      await executeRun(stmt, collection, id)
      return c.json({ success: true })
    } catch (err: any) {
      return c.json({ error: err.message }, 500)
    }
  })
}
