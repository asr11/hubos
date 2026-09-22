import { db } from '../src/lib/db.js';
const actions = db.prepare(`SELECT action_id, action_type, proposed_payload, status FROM ceo_actions WHERE status = 'PROPOSED'`).all();
console.log(JSON.stringify(actions, null, 2));
