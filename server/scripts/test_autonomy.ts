import { startAutonomyEngine } from '../src/jobs/index.js';
import { db } from '../src/lib/db.js';

async function main() {
  console.log('--- STARTING AUTONOMY ENGINE TEST ---');
  // Run engine immediately without interval for testing
  startAutonomyEngine(60000); // 60 seconds interval

  // Wait 1 second for the synchronous scans to complete
  await new Promise(resolve => setTimeout(resolve, 1000));

  console.log('\\n--- CEO ACTIONS IN DB ---');
  const actions = db.prepare(`SELECT action_id, action_type, status, proposed_payload FROM ceo_actions WHERE action_type LIKE 'REVIEW_%' OR action_type LIKE 'ESCALATE_%'`).all() as any[];
  console.log(`Found ${actions.length} autonomous actions.`);
  
  if (actions.length > 0) {
    for (const a of actions) {
      console.log(`- ${a.action_type} (${a.status}) for payload: ${a.proposed_payload}`);
    }
  }

  console.log('\\n--- RUNNING SCANNERS AGAIN (TEST DEDUPLICATION) ---');
  // Running a second time should NOT generate duplicates
  const { runScanners } = await import('../src/jobs/autonomyEngine.js');
  runScanners();
  
  await new Promise(resolve => setTimeout(resolve, 1000));

  const actionsAfter = db.prepare(`SELECT action_id FROM ceo_actions WHERE action_type LIKE 'REVIEW_%' OR action_type LIKE 'ESCALATE_%'`).all();
  console.log(`\\nTotal autonomous actions after second run: ${actionsAfter.length}`);
  
  if (actionsAfter.length === actions.length) {
    console.log('[SUCCESS] Deduplication worked! No new duplicate actions were created.');
  } else {
    console.log('[FAIL] Deduplication failed! New actions were created.');
  }

  process.exit(0);
}

main().catch(console.error);
