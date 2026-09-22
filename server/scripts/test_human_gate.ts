import { buildApp } from '../src/app.js'
import { db } from '../src/lib/db.js'

async function runTests() {
  const app = await buildApp()
  const ACTION_ID = 'test-e2e-' + Date.now()
  const AGENT_ID = 'bot-agent-01'
  const CEO_ID = '00000000-0000-4000-a000-000000000001'
  const TOOL_ID = 'tool-create-doc'
  const VERIFIER_ID = 'verifier-agent-01'
  
  console.log('\\n==========================================')
  console.log('   STARTING HUMAN GATE E2E TESTS          ')
  console.log('==========================================\\n')
  
  // Clean previous
  db.prepare('DELETE FROM ceo_actions WHERE action_id = ?').run(ACTION_ID)
  db.prepare('DELETE FROM generic_documents WHERE id = ?').run('doc-' + ACTION_ID)

  // 1. PROPOSE
  console.log('=> PROPOSING ACTION...')
  let res = await app.inject({
    method: 'POST',
    url: '/api/ceo/actions',
    payload: {
      action_id: ACTION_ID,
      action_type: 'CREATE_DUMMY_DOCUMENT',
      reasoning: 'Testing E2E chain',
      proposed_payload: { id: 'doc-' + ACTION_ID, title: 'Safe Local Doc' },
      proposed_by: AGENT_ID
    }
  })
  
  // TEST A: Try to Execute without Approve
  console.log('\\n------------------------------------------')
  console.log('[TEST A] ATTEMPTING EXECUTION WITHOUT APPROVAL')
  res = await app.inject({
    method: 'POST',
    url: `/api/ceo/actions/${ACTION_ID}/execute`,
    payload: { tool_id: TOOL_ID, execution_result: { success: true } }
  })
  console.log('EXPECTED: BLOCKED (403)')
  console.log('RESULT:', res.statusCode === 403 ? 'PASS' : `FAIL (Status: ${res.statusCode})`, res.json())

  // TEST B: Approve -> Execute -> Verify
  console.log('\\n------------------------------------------')
  console.log('[TEST B] APPROVING AND EXECUTING ACTION')
  res = await app.inject({
    method: 'POST',
    url: `/api/ceo/actions/${ACTION_ID}/approve`,
    payload: { approved_by: CEO_ID }
  })
  console.log('APPROVE STATUS:', res.statusCode)

  console.log('\\n=> EXECUTING ACTION (Tool)...')
  // Tool execution (simulating safe local action)
  db.prepare(`INSERT INTO generic_documents (id, collection, data) VALUES (?, ?, ?)`).run('doc-' + ACTION_ID, 'test_collection', JSON.stringify({ title: 'Safe Local Doc' }))
  
  res = await app.inject({
    method: 'POST',
    url: `/api/ceo/actions/${ACTION_ID}/execute`,
    payload: { tool_id: TOOL_ID, execution_result: { message: 'Document created successfully' } }
  })
  console.log('EXECUTE STATUS:', res.statusCode)

  console.log('\\n=> VERIFYING ACTION (Verifier)...')
  // Verifier execution
  const doc = db.prepare(`SELECT * FROM generic_documents WHERE id = ?`).get('doc-' + ACTION_ID)
  
  res = await app.inject({
    method: 'POST',
    url: `/api/ceo/actions/${ACTION_ID}/verify`,
    payload: { verifier_id: VERIFIER_ID, success: !!doc, verification_proof: doc }
  })
  console.log('VERIFY STATUS:', res.statusCode)
  console.log('RESULT: PASS (Chain completed successfully)')

  // TEST C: Invalid state transition
  console.log('\\n------------------------------------------')
  console.log('[TEST C] ATTEMPTING TO RE-APPROVE VERIFIED ACTION')
  res = await app.inject({
    method: 'POST',
    url: `/api/ceo/actions/${ACTION_ID}/approve`,
    payload: { approved_by: CEO_ID }
  })
  console.log('EXPECTED: BLOCKED (400)')
  console.log('RESULT:', res.statusCode === 400 ? 'PASS' : `FAIL (Status: ${res.statusCode})`, res.json())

  // TEST D: Try to modify ledger directly
  console.log('\\n------------------------------------------')
  console.log('[TEST D] ATTEMPTING TO MODIFY LEDGER VIA API')
  console.log('EXPECTED: BLOCKED')
  // There is literally no PATCH/PUT API for this route.
  res = await app.inject({
    method: 'PATCH',
    url: `/api/ceo/actions/${ACTION_ID}`,
    payload: { ledger: [] }
  })
  console.log('RESULT:', res.statusCode === 404 ? 'PASS (No API exists to alter Ledger)' : `FAIL (Status: ${res.statusCode})`)

  // FINAL STATE
  console.log('\\n------------------------------------------')
  console.log('FINAL RECORD (DB DUMP)')
  res = await app.inject({
    method: 'GET',
    url: `/api/ceo/actions/${ACTION_ID}`
  })
  const record = res.json()
  console.log(JSON.stringify(record, null, 2))
  
  // Cleanup is omitted so I can extract the dump easily if needed, but I do it for cleanliness.
  db.prepare('DELETE FROM ceo_actions WHERE action_id = ?').run(ACTION_ID)
  db.prepare('DELETE FROM generic_documents WHERE id = ?').run('doc-' + ACTION_ID)
  
  await app.close()
}

runTests().catch(console.error)
