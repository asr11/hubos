import Database from 'better-sqlite3';
const db = new Database('/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db');
const rows = db.prepare(`SELECT data FROM generic_documents WHERE collection = 'invoices'`).all();
const rawDocs = rows.map(r => JSON.parse(r.data));

function getDocName(type, kind) {
  if (kind === 'transaction_account' || type === 300) return 'charge';
  if (kind === 'tax_invoice' || type === 305) return 'charge';
  if (kind === 'tax_invoice_receipt' || type === 320) return 'payment';
  if (kind === 'receipt' || type === 400) return 'payment';
  if (kind === 'credit_note' || type === 330) return 'payment';
  return 'other';
}

const clientBalances = {};
for (const doc of rawDocs) {
  if (doc.status === 'cancelled') continue;
  
  const clientName = doc.greenInvoiceClientName || 'Unknown';
  if (!clientBalances[clientName]) clientBalances[clientName] = { charge: 0, payment: 0 };
  
  const type = doc.greenInvoiceType || parseInt(doc.type);
  const cat = getDocName(type, doc.documentKind);
  
  if (cat === 'charge') clientBalances[clientName].charge += (doc.amount || 0);
  if (cat === 'payment') clientBalances[clientName].payment += (doc.amount || 0);
}

let trueVerifiedDebt = 0;
for (const [client, b] of Object.entries(clientBalances)) {
  const diff = b.charge - b.payment;
  if (diff > 0) {
    trueVerifiedDebt += diff;
  }
}

console.log(`Global sum of (Client Charges - Client Payments, > 0): ${trueVerifiedDebt.toFixed(2)} ILS`);
