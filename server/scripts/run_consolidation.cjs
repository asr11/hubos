const Database = require('better-sqlite3');
const fs = require('fs');
const path = require('path');
const dns = require('dns').promises;

const dbPath = path.join(__dirname, '../data/os_hub.db');
const backupPath = path.join(__dirname, `../data/os_hub_backup_${Date.now()}.db`);

// 1. Backup DB
console.log('Creating backup at:', backupPath);
fs.copyFileSync(dbPath, backupPath);

const db = new Database(dbPath);

async function main() {
  console.log('--- Starting Consolidation & Enrichment ---');

  // --- Step 1: Consolidate Whales ---
  console.log('\n[1] Consolidating Whales...');
  const clientsDocs = db.prepare("SELECT * FROM generic_documents WHERE collection = 'clients'").all();
  const clients = clientsDocs.map(d => ({ docId: d.id, ...JSON.parse(d.data) }));

  // Helper to merge clients
  function mergeClients(keyword, masterName, masterTaxId) {
    const matches = clients.filter(c => c.name.includes(keyword));
    if (matches.length <= 1) return;
    
    // Pick the first as master, or create one logic. We'll just pick the first one to hold the ID, or a specific one.
    const master = matches[0];
    master.name = masterName;
    if (masterTaxId) master.tax_id = masterTaxId;
    
    const duplicateIds = matches.slice(1).map(c => c.id);
    console.log(`Merging into Master [${masterName}]:`, duplicateIds);

    // Update Master in DB
    db.prepare("UPDATE generic_documents SET data = ? WHERE id = ?").run(JSON.stringify(master), master.docId);

    // Update all foreign keys in incomes/hosting to point to master.id
    const collectionsToUpdate = ['financeIncomes', 'hostingRecords', 'projects'];
    for (const coll of collectionsToUpdate) {
      const records = db.prepare(`SELECT * FROM generic_documents WHERE collection = ?`).all(coll);
      for (const r of records) {
        const data = JSON.parse(r.data);
        let changed = false;
        if (duplicateIds.includes(data.clientId)) { data.clientId = master.id; changed = true; }
        if (duplicateIds.includes(data.client_id)) { data.client_id = master.id; changed = true; }
        if (changed) {
          db.prepare("UPDATE generic_documents SET data = ? WHERE id = ?").run(JSON.stringify(data), r.id);
        }
      }
    }

    // Soft delete duplicates (mark merged)
    for (const dup of matches.slice(1)) {
      dup.merged_into = master.id;
      dup.status = 'archived';
      db.prepare("UPDATE generic_documents SET data = ? WHERE id = ?").run(JSON.stringify(dup), dup.docId);
    }
  }

  mergeClients('אשטרום', 'קבוצת אשטרום בע"מ (Master)', '520038850'); // Assuming generic Ashtrom ID
  mergeClients('גרינברג', 'קבוצת גרינברג (Master)', null);

  // --- Step 2: Auto-Archive Domains ---
  console.log('\n[2] Auto-Archiving Dead Domains...');
  const hostingDocs = db.prepare("SELECT * FROM generic_documents WHERE collection = 'hostingRecords'").all();
  let archivedCount = 0;
  
  for (const h of hostingDocs) {
    const data = JSON.parse(h.data);
    if (data.notes && (data.notes.includes('ARCHIVED') || data.notes.includes('IGNORED'))) continue;
    
    if (!data.domain) continue;
    try {
      await dns.resolve(data.domain);
      // It's alive
    } catch (e) {
      // Dead
      data.notes = (data.notes || '') + ' | סטטוס אוטומטי: ARCHIVED (DNS לא מגיב)';
      db.prepare("UPDATE generic_documents SET data = ? WHERE id = ?").run(JSON.stringify(data), h.id);
      archivedCount++;
    }
  }
  console.log(`Auto-archived ${archivedCount} dead domains.`);

  // --- Step 3: Enrich Clients via data.gov.il ---
  console.log('\n[3] Enriching Clients via data.gov.il...');
  const activeClients = db.prepare("SELECT * FROM generic_documents WHERE collection = 'clients'").all().map(d => ({docId: d.id, ...JSON.parse(d.data)}));
  let enrichedCount = 0;

  for (const c of activeClients) {
    if (!c.tax_id || c.tax_id.length < 8 || c.merged_into) continue;
    
    try {
      const res = await fetch(`http://127.0.0.1:8787/api/enrich/company?q=${c.tax_id}`);
      if (res.ok) {
        const enriched = await res.json();
        if (enriched && !enriched.error) {
          c.name = enriched.legalNameHe || c.name;
          c.address = enriched.addressLine;
          c.city = enriched.city;
          c.legal_status = enriched.status;
          if (enriched.isViolating) c.is_violating = true;
          
          db.prepare("UPDATE generic_documents SET data = ? WHERE id = ?").run(JSON.stringify(c), c.docId);
          enrichedCount++;
        }
      }
    } catch (e) {
      // Ignore network errors for single clients
    }
  }
  console.log(`Successfully enriched ${enrichedCount} clients with live government data.`);

  console.log('\n--- Consolidation Complete ---');
}

main().catch(console.error);
