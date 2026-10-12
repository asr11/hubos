// @ts-nocheck
import { Hono } from 'hono'
import { db } from '../lib/db.js'

function appendLedgerEvent(actionId: string, status: string, actorId: string, data: any) {
  const row = db.prepare(`SELECT ledger FROM ceo_actions WHERE action_id = ?`).get(actionId) as any
  if (!row) throw new Error('Action not found')
  const ledger = JSON.parse(row.ledger || '[]')
  ledger.push({
    status,
    timestamp: new Date().toISOString(),
    actor: actorId,
    data
  })
  db.prepare(`UPDATE ceo_actions SET ledger = ?, updated_at = CURRENT_TIMESTAMP WHERE action_id = ?`).run(JSON.stringify(ledger), actionId)
}

export async function registerCeoActionsRoutes(app: Hono<any>) {
  // PROPOSE
  app.post('/api/ceo/actions', async (c: any) => {
    const { action_id, action_type, reasoning, proposed_payload, proposed_by } = await c.req.json<any>() /* any */
    const status = 'PROPOSED'
    const initialLedger = JSON.stringify([{
      status,
      timestamp: new Date().toISOString(),
      actor: proposed_by,
      data: proposed_payload
    }])
    
    db.prepare(`
      INSERT INTO ceo_actions (action_id, action_type, status, reasoning, proposed_payload, ledger)
      VALUES (?, ?, ?, ?, ?, ?)
    `).run(action_id, action_type, status, reasoning, JSON.stringify(proposed_payload), initialLedger)
    
    return c.json({ ok: true, action_id, status })
  })

  // APPROVE
  app.post('/api/ceo/actions/:id/approve', async (c: any) => {
    const { id } = c.req.param()
    const { approved_by } = await c.req.json() as { approved_by: string }
    
    // HUMAN GATE ENFORCEMENT
    const row = db.prepare(`SELECT status, proposed_payload FROM ceo_actions WHERE action_id = ?`).get(id) as any
    if (!row) return c.json({ error: 'not_found' }, 404)
    if (row.status !== 'PROPOSED') return c.json({ error: 'invalid_status_transition', from: row.status, to: 'APPROVED' }, 400)
    
    db.prepare(`UPDATE ceo_actions SET status = 'APPROVED' WHERE action_id = ?`).run(id)
    appendLedgerEvent(id, 'APPROVED', approved_by, JSON.parse(row.proposed_payload))
    return c.json({ ok: true, status: 'APPROVED' })
  })

  // REJECT
  app.post('/api/ceo/actions/:id/reject', async (c: any) => {
    const { id } = c.req.param()
    const { rejected_by, reason } = await c.req.json<any>() /* any */
    
    // HUMAN GATE ENFORCEMENT
    const row = db.prepare(`SELECT status, proposed_payload FROM ceo_actions WHERE action_id = ?`).get(id) as any
    if (!row) return c.json({ error: 'not_found' }, 404)
    if (row.status !== 'PROPOSED') return c.json({ error: 'invalid_status_transition', from: row.status, to: 'REJECTED' }, 400)
    
    db.prepare(`UPDATE ceo_actions SET status = 'REJECTED' WHERE action_id = ?`).run(id)
    appendLedgerEvent(id, 'REJECTED', rejected_by, { reason })
    return c.json({ ok: true, status: 'REJECTED' })
  })

  // EXECUTE
  app.post('/api/ceo/actions/:id/execute', async (c: any) => {
    const { id } = c.req.param()
    const { tool_id, execution_result } = await c.req.json<any>() /* any */
    
    // HUMAN GATE ENFORCEMENT
    const row = db.prepare(`SELECT status FROM ceo_actions WHERE action_id = ?`).get(id) as any
    if (!row) return c.json({ error: 'not_found' }, 404)
    if (row.status !== 'APPROVED') return c.json({ error: 'blocked_by_human_gate', message: 'Cannot execute without APPROVED status', current_status: row.status }, 403)
    
    // Execute phase
    db.prepare(`UPDATE ceo_actions SET status = 'EXECUTED' WHERE action_id = ?`).run(id)
    appendLedgerEvent(id, 'EXECUTED', tool_id, execution_result)
    return c.json({ ok: true, status: 'EXECUTED' })
  })

  // VERIFY
  app.post('/api/ceo/actions/:id/verify', async (c: any) => {
    const { id } = c.req.param()
    const { verifier_id, verification_proof, success } = await c.req.json<any>() /* any */
    
    const row = db.prepare(`SELECT status FROM ceo_actions WHERE action_id = ?`).get(id) as any
    if (!row) return c.json({ error: 'not_found' }, 404)
    if (row.status !== 'EXECUTED') return c.json({ error: 'invalid_status_transition' }, 400)
    
    const nextStatus = success ? 'VERIFIED' : 'FAILED'
    db.prepare(`UPDATE ceo_actions SET status = ? WHERE action_id = ?`).run(nextStatus, id)
    appendLedgerEvent(id, nextStatus, verifier_id, verification_proof)
    return c.json({ ok: true, status: nextStatus })
  })

  // GET ACTIONS
  app.get('/api/ceo/actions/:id', async (c: any) => {
    const { id } = c.req.param()
    const row = db.prepare(`SELECT * FROM ceo_actions WHERE action_id = ?`).get(id)
    if (!row) return c.json({ error: 'not_found' }, 404)
    return c.json({ ok: true, action: { ...row, ledger: JSON.parse((row as any).ledger) } })
  })
}
