const Database = require('better-sqlite3');
const db = new Database('data/os_hub.db');
const rows = db.prepare("SELECT id, data FROM generic_documents WHERE collection='invoices' LIMIT 3;").all();
console.log(JSON.stringify(rows, null, 2));
