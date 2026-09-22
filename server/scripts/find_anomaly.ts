import Database from 'better-sqlite3';

const db = new Database('server/data/legacy_hub_os.db');

console.log('\\n--- DEALS ---');
try {
  const deals = db.prepare(`SELECT * FROM deals LIMIT 5`).all();
  console.log(`Total deals: ${db.prepare('SELECT COUNT(*) as c FROM deals').get().c}`);
  if (deals.length > 0) console.log(deals[0]);
} catch(e) {}

console.log('\\n--- REAL CLIENTS ---');
try {
  const clients = db.prepare(`SELECT * FROM clients WHERE name NOT LIKE '%test%' AND name NOT LIKE '%demo%' LIMIT 5`).all();
  console.log(`Found ${clients.length} real clients.`);
  if (clients.length > 0) console.log(clients);
} catch(e) {}

console.log('\\n--- REAL LEADS ---');
try {
  const leads = db.prepare(`SELECT * FROM leads WHERE name NOT LIKE '%test%' AND name NOT LIKE '%demo%' AND name NOT LIKE '%dup%' LIMIT 5`).all();
  console.log(`Found ${leads.length} real leads.`);
  if (leads.length > 0) console.log(leads);
} catch(e) {}







