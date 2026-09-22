import Database from 'better-sqlite3';
const db = new Database('data/os_hub.db');
db.prepare("DELETE FROM ceo_actions").run();
console.log("ceo_actions cleared.");
