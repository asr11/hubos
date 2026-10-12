import { db } from './server/src/lib/db.js';
const res = db.prepare("SELECT COUNT(*) as count FROM clients WHERE phone IS NOT NULL AND phone != ''").get();
console.log('COUNT=', (res as any).count);
