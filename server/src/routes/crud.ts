import { requireAuth } from '../middleware/requireAuth.js'
import type { FastifyInstance } from 'fastify'
import { db } from '../lib/db.js'
import crypto from 'node:crypto'

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

export async function registerCrudRoutes(app: FastifyInstance): Promise<void> {
  // Get all documents for a collection
  app.get('/api/:collection', { preHandler: requireAuth }, async (req, _reply) => {
    const { collection } = req.params as { collection: string }
    const stmt = db.prepare('SELECT id, data FROM generic_documents WHERE collection = ?')
    let rows = (await fetchAll(stmt, collection)) as { id: string; data: string }[]
    if (rows.length === 0 && collection === 'financeIncomes') {
      const stmtInvoices = db.prepare('SELECT id, data FROM generic_documents WHERE collection = ?')
      rows = (await fetchAll(stmtInvoices, 'invoices')) as { id: string; data: string }[]
    }
    const results = rows.map(r => ({ id: r.id, ...safeParseJson(r.data) }))
    return results
  })

  // Get a single document
  app.get('/api/:collection/:id', { preHandler: requireAuth }, async (req, reply) => {
    const { collection, id } = req.params as { collection: string; id: string }
    const stmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
    let row = (await fetchFirst(stmt, collection, id)) as { data: string } | undefined
    if (!row && collection === 'financeIncomes') {
      const stmtInvoices = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
      row = (await fetchFirst(stmtInvoices, 'invoices', id)) as { data: string } | undefined
    }
    if (!row) {
      reply.code(404).send({ error: 'Not found' })
      return
    }
    return { id, ...safeParseJson(row.data) }
  })

  // Create a document
  app.post('/api/:collection', { preHandler: requireAuth }, async (req, _reply) => {
    const { collection } = req.params as { collection: string }
    const body = req.body as any
    const id = body.id || crypto.randomUUID()
    
    // Remove id from the JSON body if it exists
    const dataToSave = { ...body }
    delete dataToSave.id
    
    const stmt = db.prepare('INSERT INTO generic_documents (id, collection, data) VALUES (?, ?, ?)')
    await executeRun(stmt, id, collection, JSON.stringify(dataToSave))
    return { id, ...dataToSave }
  })

  // Update a document (PUT)
  app.put('/api/:collection/:id', { preHandler: requireAuth }, async (req, _reply) => {
    const { collection, id } = req.params as { collection: string; id: string }
    const body = req.body as any
    
    const dataToSave = { ...body }
    delete dataToSave.id
    
    // Upsert logic
    const stmt = db.prepare(`
      INSERT INTO generic_documents (id, collection, data) 
      VALUES (?, ?, ?)
      ON CONFLICT(id) DO UPDATE SET data = excluded.data
    `)
    await executeRun(stmt, id, collection, JSON.stringify(dataToSave))
    return { id, ...dataToSave }
  })
  
  // Update a document (PATCH)
  app.patch('/api/:collection/:id', { preHandler: requireAuth }, async (req, _reply) => {
    const { collection, id } = req.params as { collection: string; id: string }
    const body = req.body as any
    
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
    return { id, ...dataToSave }
  })

  // Delete a document
  app.delete('/api/:collection/:id', { preHandler: requireAuth }, async (req, _reply) => {
    const { collection, id } = req.params as { collection: string; id: string }
    const stmt = db.prepare('DELETE FROM generic_documents WHERE collection = ? AND id = ?')
    await executeRun(stmt, collection, id)
    return { success: true }
  })
}
