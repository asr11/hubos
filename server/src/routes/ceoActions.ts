import type { FastifyInstance } from 'fastify'
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

export async function registerCeoActionsRoutes(app: FastifyInstance): Promise<void> {
  // PROPOSE
  app.post('/api/ceo/actions', async (req, reply) => {
    const { action_id, action_type, reasoning, proposed_payload, proposed_by } = req.body as any
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
    
    return { ok: true, action_id, status }
  })

  // APPROVE
  app.post('/api/ceo/actions/:id/approve', async (req, reply) => {
    const { id } = req.params as { id: string }
    const { approved_by } = req.body as { approved_by: string }
    
    // HUMAN GATE ENFORCEMENT
    const row = db.prepare(`SELECT status, proposed_payload FROM ceo_actions WHERE action_id = ?`).get(id) as any
    if (!row) return reply.status(404).send({ error: 'not_found' })
    if (row.status !== 'PROPOSED') return reply.status(400).send({ error: 'invalid_status_transition', from: row.status, to: 'APPROVED' })
    
    db.prepare(`UPDATE ceo_actions SET status = 'APPROVED' WHERE action_id = ?`).run(id)
    appendLedgerEvent(id, 'APPROVED', approved_by, JSON.parse(row.proposed_payload))
    return { ok: true, status: 'APPROVED' }
  })

  // REJECT
  app.post('/api/ceo/actions/:id/reject', async (req, reply) => {
    const { id } = req.params as { id: string }
    const { rejected_by, reason } = req.body as any
    
    // HUMAN GATE ENFORCEMENT
    const row = db.prepare(`SELECT status, proposed_payload FROM ceo_actions WHERE action_id = ?`).get(id) as any
    if (!row) return reply.status(404).send({ error: 'not_found' })
    if (row.status !== 'PROPOSED') return reply.status(400).send({ error: 'invalid_status_transition', from: row.status, to: 'REJECTED' })
    
    db.prepare(`UPDATE ceo_actions SET status = 'REJECTED' WHERE action_id = ?`).run(id)
    appendLedgerEvent(id, 'REJECTED', rejected_by, { reason })
    return { ok: true, status: 'REJECTED' }
  })

  // EXECUTE
  app.post('/api/ceo/actions/:id/execute', async (req, reply) => {
    const { id } = req.params as { id: string }
    const { tool_id, execution_result } = req.body as any
    
    // HUMAN GATE ENFORCEMENT
    const row = db.prepare(`SELECT status FROM ceo_actions WHERE action_id = ?`).get(id) as any
    if (!row) return reply.status(404).send({ error: 'not_found' })
    if (row.status !== 'APPROVED') return reply.status(403).send({ error: 'blocked_by_human_gate', message: 'Cannot execute without APPROVED status', current_status: row.status })
    
    // Execute phase
    db.prepare(`UPDATE ceo_actions SET status = 'EXECUTED' WHERE action_id = ?`).run(id)
    appendLedgerEvent(id, 'EXECUTED', tool_id, execution_result)
    return { ok: true, status: 'EXECUTED' }
  })

  // VERIFY
  app.post('/api/ceo/actions/:id/verify', async (req, reply) => {
    const { id } = req.params as { id: string }
    const { verifier_id, verification_proof, success } = req.body as any
    
    const row = db.prepare(`SELECT status FROM ceo_actions WHERE action_id = ?`).get(id) as any
    if (!row) return reply.status(404).send({ error: 'not_found' })
    if (row.status !== 'EXECUTED') return reply.status(400).send({ error: 'invalid_status_transition' })
    
    const nextStatus = success ? 'VERIFIED' : 'FAILED'
    db.prepare(`UPDATE ceo_actions SET status = ? WHERE action_id = ?`).run(nextStatus, id)
    appendLedgerEvent(id, nextStatus, verifier_id, verification_proof)
    return { ok: true, status: nextStatus }
  })

  // GET ACTIONS
  app.get('/api/ceo/actions/:id', async (req, reply) => {
    const { id } = req.params as { id: string }
    const row = db.prepare(`SELECT * FROM ceo_actions WHERE action_id = ?`).get(id)
    if (!row) return reply.status(404).send({ error: 'not_found' })
    return { ok: true, action: { ...row, ledger: JSON.parse((row as any).ledger) } }
  })
}
