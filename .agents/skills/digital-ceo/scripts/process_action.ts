import { parseArgs } from 'node:util';
import { buildApp } from '../../../../server/src/app.js';

async function main() {
  const { values } = parseArgs({
    options: {
      id: { type: 'string' },
      step: { type: 'string' }, // approve, execute, verify, get
      payload: { type: 'string' }
    },
    strict: false
  });

  const { id, step, payload } = values;

  if (!id || !step) {
    console.error('Usage: tsx process_action.ts --id <ID> --step <approve|execute|verify|get> [--payload <JSON>]');
    process.exit(1);
  }

  const app = await buildApp();
  const parsedPayload = payload ? JSON.parse(payload as string) : {};

  let response;

  switch (step) {
    case 'approve':
      response = await app.inject({
        method: 'POST',
        url: `/api/ceo/actions/${id}/approve`,
        payload: { approved_by: parsedPayload.actor || '00000000-0000-4000-a000-000000000001' } // Default CEO ID
      });
      break;
    case 'reject':
      response = await app.inject({
        method: 'POST',
        url: `/api/ceo/actions/${id}/reject`,
        payload: { rejected_by: parsedPayload.actor || '00000000-0000-4000-a000-000000000001', reason: parsedPayload.reason || 'Rejected by Human CEO' }
      });
      break;
    case 'execute':
      response = await app.inject({
        method: 'POST',
        url: `/api/ceo/actions/${id}/execute`,
        payload: { tool_id: parsedPayload.tool_id || 'antigravity-tool', execution_result: parsedPayload.result || { success: true } }
      });
      break;
    case 'verify':
      response = await app.inject({
        method: 'POST',
        url: `/api/ceo/actions/${id}/verify`,
        payload: { verifier_id: parsedPayload.verifier_id || 'antigravity-verifier', success: parsedPayload.success !== false, verification_proof: parsedPayload.proof || { checked: true } }
      });
      break;
    case 'get':
      response = await app.inject({
        method: 'GET',
        url: `/api/ceo/actions/${id}`
      });
      break;
    default:
      console.error('Unknown step');
      await app.close();
      process.exit(1);
  }

  if (response.statusCode >= 400) {
    console.error(`[ERROR] Step ${step} failed:`, response.statusCode, response.json());
    await app.close();
    process.exit(1);
  }

  console.log(`\\n[SUCCESS] Step ${step.toUpperCase()} completed.`);
  console.log(JSON.stringify(response.json(), null, 2));
  
  await app.close();
}

main().catch(console.error);
