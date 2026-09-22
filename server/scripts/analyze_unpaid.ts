import Database from 'better-sqlite3';
const db = new Database('/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db');

const rows = db.prepare(`SELECT data FROM generic_documents WHERE collection = 'invoices'`).all();
let unpaidRevenue = 0;
let unpaidCount = 0;

for (const row of rows) {
  const doc = JSON.parse(row.data);
  if (doc.status !== 'paid' && doc.status !== 'cancelled' && doc.amount) {
    unpaidRevenue += doc.amount;
    unpaidCount++;
  }
}

console.log(`Total Unpaid Revenue: ${unpaidRevenue} ILS`);
console.log(`Unpaid Count: ${unpaidCount}`);
