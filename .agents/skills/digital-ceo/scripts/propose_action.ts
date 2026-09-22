import { parseArgs } from 'node:util';
import { buildApp } from '../../../../server/src/app.js';

async function main() {
  const { values } = parseArgs({
    options: {
      type: { type: 'string' },
      reasoning: { type: 'string' },
      payload: { type: 'string' },
    },
    strict: false
  });

  const { type, reasoning, payload } = values;

  if (!type || !reasoning || !payload) {
    console.error('Usage: tsx propose_action.ts --type <TYPE> --reasoning <REASONING> --payload <JSON>');
    process.exit(1);
  }

  let parsedPayload;
  try {
    parsedPayload = JSON.parse(payload as string);
  } catch (e) {
    console.error('Error parsing payload JSON');
    process.exit(1);
  }

  const action_id = 'act_' + Math.random().toString(36).substring(2, 11) + '_' + Date.now();

  const app = await buildApp();
  const response = await app.inject({
    method: 'POST',
    url: '/api/ceo/actions',
    payload: {
      action_id,
      action_type: type,
      reasoning,
      proposed_payload: parsedPayload,
      proposed_by: 'antigravity-agent'
    }
  });

  if (response.statusCode >= 400) {
    console.error('Failed to propose action:', response.statusCode, response.json());
    await app.close();
    process.exit(1);
  }

  const result = response.json();
  console.log('\\n[SUCCESS] Action Proposed Successfully.');
  console.log(`ACTION_ID: ${result.action_id}`);
  console.log(`STATUS: ${result.status}`);
  console.log('\\nStop now and wait for HUMAN CEO approval before proceeding.');
  
  await app.close();
}

main().catch(console.error);

