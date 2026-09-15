import Database from 'better-sqlite3';

const dbPath = '/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db';
const db = new Database(dbPath, { readonly: true });

function safeParseJson(data) {
  try {
    return JSON.parse(data);
  } catch {
    return {};
  }
}

const stmt = db.prepare('SELECT id, data FROM generic_documents WHERE collection = ?');
let rows = stmt.all('financeIncomes');
const results = rows.map(r => ({ id: r.id, ...safeParseJson(r.data) }));

const counts = {};
for (const row of results) {
  counts[row.documentKind] = (counts[row.documentKind] || 0) + 1;
}
console.log("Distribution:", counts);
