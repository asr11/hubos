import Database from 'better-sqlite3';
const db = new Database('/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db');
const tables = db.prepare("SELECT name FROM sqlite_master WHERE type='table'").all();
for (const t of tables) {
  if (t.name === 'sqlite_sequence') continue;
  const count = db.prepare(`SELECT COUNT(*) as c FROM ${t.name}`).get();
  console.log(`${t.name}: ${count.c}`);
}
