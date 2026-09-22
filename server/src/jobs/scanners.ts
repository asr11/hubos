import Database from 'better-sqlite3';
import { db } from '../lib/db.js';

// Connection to the legacy POS for reading anomalies
const legacyDb = new Database('server/data/legacy_hub_os.db', { readonly: true });

function isActionAlreadyProposed(actionType: string, entityId: number | string): boolean {
  // Check if an unresolved action exists for this specific entity
  const existing = db.prepare(`
    SELECT action_id FROM ceo_actions 
    WHERE action_type = ? AND status IN ('PROPOSED', 'APPROVED', 'EXECUTING')
    AND json_extract(proposed_payload, '$.entity_id') = ?
  `).get(actionType, entityId);
  return !!existing;
}

function proposeAction(type: string, reasoning: string, payload: any) {
  const action_id = 'act_' + Math.random().toString(36).substring(2, 11) + '_' + Date.now();
  const status = 'PROPOSED';
  const initialLedger = JSON.stringify([{
    status,
    timestamp: new Date().toISOString(),
    actor: 'autonomy-engine',
    data: payload
  }]);
  
  db.prepare(`
    INSERT INTO ceo_actions (action_id, action_type, status, reasoning, proposed_payload, ledger)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(action_id, type, status, reasoning, JSON.stringify(payload), initialLedger);
  
  console.log(`[AUTONOMY] Proposed Action: ${type} for entity ${payload.entity_id} (${action_id})`);
}

export function scanNeglectedLeads() {
  try {
    const leads = legacyDb.prepare(`
      SELECT id, name, created_at, status FROM leads 
      WHERE (status = 'new' OR status = 'contacted') 
      AND name NOT LIKE '%test%'
      AND name NOT LIKE '%dup%'
      AND name NOT LIKE '%board%'
      AND source != 'test'
      LIMIT 10
    `).all() as any[];

    for (const lead of leads) {
      if (!isActionAlreadyProposed('ESCALATE_NEGLECTED_LEAD', lead.id)) {
        proposeAction('ESCALATE_NEGLECTED_LEAD', `Lead ID ${lead.id} (${lead.name}) is neglected. Status: ${lead.status}.`, {
          entity_id: lead.id,
          entity_type: 'leads',
          name: lead.name,
          recommended_action: 'REASSIGN_TO_HUMAN'
        });
      }
    }
  } catch(e: any) {
    console.error('Error scanning neglected leads:', e.message);
  }
}

export function scanOverdueTasks() {
  try {
    const tasks = legacyDb.prepare(`
      SELECT id, title, due_at, status FROM tasks 
      WHERE status != 'done' 
      AND title NOT LIKE '%test%' 
      AND title NOT LIKE '%dup%' 
      AND title NOT LIKE '%board%'
      AND due_at < datetime('now') 
      LIMIT 10
    `).all() as any[];

    for (const task of tasks) {
      if (!isActionAlreadyProposed('REVIEW_OVERDUE_TASK', task.id)) {
        proposeAction('REVIEW_OVERDUE_TASK', `Task ID ${task.id} ("${task.title}") is overdue since ${task.due_at}.`, {
          entity_id: task.id,
          entity_type: 'tasks',
          title: task.title,
          due_at: task.due_at,
          recommended_action: 'REVIEW_TASK'
        });
      }
    }
  } catch(e: any) {
    console.error('Error scanning overdue tasks:', e.message);
  }
}

export function scanOutstandingBalances() {
  // Disabled as per user request
  // console.log('scanOutstandingBalances is temporarily disabled.');
}
