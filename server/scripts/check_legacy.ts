import Database from 'better-sqlite3';
const legacyDb = new Database('data/legacy_hub_os.db', { readonly: true });
console.log("Tasks:");
console.log(legacyDb.prepare("SELECT id, title, due_at, status FROM tasks LIMIT 5").all());
console.log("Leads:");
console.log(legacyDb.prepare("SELECT id, name, status FROM leads LIMIT 5").all());
