import Database from 'better-sqlite3';

const dbPath = '/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db';
const db = new Database(dbPath, { readonly: true });

console.log("--- Query 1: Income Sample ---");
const q1a = db.prepare(`SELECT data FROM generic_documents WHERE collection = 'financeIncomes' LIMIT 1`).get();
console.log(q1a.data);

console.log("\n--- Query 1: Expense Sample ---");
const q1b = db.prepare(`SELECT data FROM generic_documents WHERE collection = 'financeExpenses' LIMIT 1`).get();
console.log(q1b.data);

console.log("\n--- Query 2: Income by Kind & Status ---");
const q2 = db.prepare(`SELECT json_extract(data, '$.documentKind') as kind, json_extract(data, '$.status') as status, count(*) as count, round(sum(coalesce(json_extract(data, '$.amount'), json_extract(data, '$.totalAmount'), 0)), 2) as total FROM generic_documents WHERE collection = 'financeIncomes' GROUP BY 1, 2`).all();
console.table(q2);

console.log("\n--- Query 3: Shadow IT Expenses (Hetzner, Hostinger, SPD) ---");
const q3 = db.prepare(`SELECT json_extract(data, '$.supplierName') as supplier, count(*) as count, round(sum(coalesce(json_extract(data, '$.amount'), json_extract(data, '$.totalAmount'), 0)), 2) as total FROM generic_documents WHERE collection = 'financeExpenses' AND (json_extract(data, '$.supplierName') LIKE '%Hetzner%' OR json_extract(data, '$.supplierName') LIKE '%Hostinger%' OR json_extract(data, '$.supplierName') LIKE '%אס.פי.די%') GROUP BY 1`).all();
console.table(q3);

console.log("\n--- Query 4: Total Expenses ---");
const q4 = db.prepare(`SELECT count(*) as count, round(sum(coalesce(json_extract(data, '$.amount'), json_extract(data, '$.totalAmount'), 0)), 2) as total FROM generic_documents WHERE collection = 'financeExpenses'`).all();
console.table(q4);

db.close();
