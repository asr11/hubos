import { db } from '../src/lib/db';

const [,, actionId, action] = process.argv;

if (!actionId || !action) {
  console.error("Usage: tsx process_action.ts <action_id> <APPROVE|REJECT>");
  process.exit(1);
}

const row = db.prepare("SELECT * FROM ceo_actions WHERE action_id = ?").get(actionId) as any;
if (!row) {
  console.error("Action not found.");
  process.exit(1);
}

const newStatus = action === 'APPROVE' ? 'APPROVED' : 'REJECTED';
const ledger = JSON.parse(row.ledger || '[]');

ledger.push({
  status: newStatus,
  timestamp: new Date().toISOString(),
  actor: 'human_admin',
  note: `Manually ${newStatus} via script`
});

db.prepare("UPDATE ceo_actions SET status = ?, ledger = ?, updated_at = CURRENT_TIMESTAMP WHERE action_id = ?")
  .run(newStatus, JSON.stringify(ledger), actionId);

console.log(`Action ${actionId} has been ${newStatus}.`);
