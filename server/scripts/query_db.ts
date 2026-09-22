import Database from 'better-sqlite3';

const db = new Database('/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db');

const tables = db.prepare("SELECT name FROM sqlite_master WHERE type='table'").all();
console.log('Tables:', tables.map(t => t.name));

const checkTable = (name) => {
  try {
    const count = db.prepare(`SELECT COUNT(*) as c FROM ${name}`).get();
    console.log(`${name} count: ${count.c}`);
    if (count.c > 0) {
      const sample = db.prepare(`SELECT * FROM ${name} LIMIT 3`).all();
      console.log(`Sample of ${name}:`, sample);
    }
  } catch (e) {}
};

for (const t of tables) {
  checkTable(t.name);
}

const collections = db.prepare("SELECT DISTINCT collection FROM generic_documents").all();
console.log('\nCollections in generic_documents:', collections.map(c => c.collection));

const checkCollection = (name) => {
  const count = db.prepare("SELECT COUNT(*) as c FROM generic_documents WHERE collection = ?").get(name);
  console.log(`${name} generic_documents count: ${count.c}`);
  if (count.c > 0) {
    const sample = db.prepare("SELECT data FROM generic_documents WHERE collection = ? LIMIT 3").all(name);
    console.log(`Sample of ${name}:`, sample.map(s => JSON.parse(s.data)));
  }
}

for (const c of collections) {
  checkCollection(c.collection);
}
const invoiceDescriptions = db.prepare(`
  SELECT json_extract(data, '$.description') as desc, count(*) as c 
  FROM generic_documents 
  WHERE collection = 'invoices' 
  GROUP BY desc 
  ORDER BY c DESC 
  LIMIT 20
`).all();

console.log('\nTop 20 Invoice Descriptions:');
for (const row of invoiceDescriptions) {
  console.log(`- [${row.c}] ${row.desc}`);
}
