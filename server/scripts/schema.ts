import Database from 'better-sqlite3';
const db = new Database('data/legacy_hub_os.db');
console.log(db.prepare("PRAGMA table_info('tasks')").all());
