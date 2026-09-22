import fs from 'node:fs';
import path from 'node:path';
import Database from 'better-sqlite3';

const db = new Database(path.resolve(process.cwd(), 'data/os_hub.db'));

const csvPath = path.resolve(process.cwd(), '../../hub-ssot-engine/data/master_ssot_dashboard.csv');
const csvText = fs.readFileSync(csvPath, 'utf8');

const rows = csvText.split('\n').filter(r => r.trim() !== '');
const header = rows[0].split(',');

let count = 0;
for (let i = 1; i < rows.length; i++) {
  const columns = rows[i].split(',');
  if (columns.length < 2) continue;

  const domain = columns[1];
  const clientName = columns[2] || '';
  const status = columns[3] || '';
  
  // Try to find clientId based on clientName in the DB
  let clientId = '';
  if (clientName && clientName !== 'לא בטוח') {
    const clients = db.prepare(`SELECT id, data FROM generic_documents WHERE collection = 'clients'`).all();
    for (const c of clients) {
       const cData = JSON.parse(c.data);
       if (cData.name && cData.name.includes(clientName)) {
           clientId = c.id;
           break;
       }
    }
  }

  const projectData = {
    name: domain,
    domain: domain,
    clientName: clientName,
    clientId: clientId,
    status: status,
    source: 'master_ssot_csv',
    importedAt: new Date().toISOString()
  };

  const id = 'proj_' + Math.random().toString(36).substr(2, 9);
  
  db.prepare(`INSERT OR REPLACE INTO generic_documents (collection, id, data) VALUES (?, ?, ?)`).run(
    'projects', id, JSON.stringify(projectData)
  );
  count++;
}

console.log(`Imported ${count} projects successfully from master_ssot_dashboard!`);
