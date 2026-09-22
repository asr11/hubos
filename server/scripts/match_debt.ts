import Database from 'better-sqlite3';
const db = new Database('/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db');

const rows = db.prepare(`SELECT data FROM generic_documents WHERE collection = 'invoices'`).all();
const rawDocs = rows.map(r => JSON.parse(r.data));

const grouped = {};

// Helper to normalize document types to Morning names as requested
function getHebrewDocName(kind, type) {
  if (kind === 'transaction_account' || type === 300) return 'חשבון עסקה/דרישת תשלום';
  if (kind === 'tax_invoice' || type === 305) return 'חשבונית מס';
  if (kind === 'tax_invoice_receipt' || type === 320) return 'חשבונית מס-קבלה';
  if (kind === 'receipt' || type === 400) return 'קבלה';
  if (kind === 'credit_note' || type === 330) return 'חשבונית זיכוי';
  if (kind === 'price_quote' || type === 10) return 'הצעת מחיר';
  return `אחר (${kind || type || 'לא ידוע'})`;
}

for (const doc of rawDocs) {
  const type = doc.greenInvoiceType || parseInt(doc.type) || doc.documentKind;
  const docName = getHebrewDocName(doc.documentKind, type);
  
  const status = doc.status || 'UNKNOWN';
  const amount = doc.amount || 0;
  
  if (!grouped[docName]) {
    grouped[docName] = { COUNT: 0, TOTAL: 0, PAID: 0, OPEN: 0, CANCELLED: 0, UNKNOWN: 0 };
  }
  
  grouped[docName].COUNT++;
  grouped[docName].TOTAL += amount;
  
  // Status mapping
  if (status === 'paid') grouped[docName].PAID += amount;
  else if (status === 'cancelled') grouped[docName].CANCELLED += amount;
  else if (status === 'pending' || status === 'unpaid') grouped[docName].OPEN += amount;
  else grouped[docName].UNKNOWN += amount;
}

// Print distribution
console.log('DOCUMENT_TYPE | COUNT | TOTAL | PAID | OPEN | CANCELLED | UNKNOWN');
for (const [name, s] of Object.entries(grouped)) {
  console.log(`${name.padEnd(25)} | ${s.COUNT.toString().padEnd(5)} | ${s.TOTAL.toFixed(2).padEnd(10)} | ${s.PAID.toFixed(2).padEnd(10)} | ${s.OPEN.toFixed(2).padEnd(10)} | ${s.CANCELLED.toFixed(2).padEnd(10)} | ${s.UNKNOWN.toFixed(2)}`);
}

// Perform advanced matching
// To prevent double counting and respect payments:
// We look at all charges (300, 305) and subtract payments (320, 400, 330) PER CLIENT.
// Or we just use `doc.amount - doc.paidAmount` for valid charge documents. 
// The safest rule for VERIFIED_DEBT:
// Only look at 300 (חשבון עסקה) and 305 (חשבונית מס) that are NOT cancelled.
// Their open debt is Amount - PaidAmount.
let verifiedDebt = 0;

for (const doc of rawDocs) {
  const type = doc.greenInvoiceType || parseInt(doc.type);
  const kind = doc.documentKind;
  const docName = getHebrewDocName(kind, type);
  
  if (docName === 'חשבון עסקה/דרישת תשלום' || docName === 'חשבונית מס') {
    if (doc.status !== 'cancelled') {
       // A valid charge. Unpaid amount is amount - paidAmount
       // If paidAmount is missing, assume 0 unless status is 'paid' (in which case it's fully paid)
       let paid = doc.paidAmount || 0;
       if (doc.status === 'paid') paid = doc.amount;
       
       let debt = (doc.amount || 0) - paid;
       if (debt > 0) {
         verifiedDebt += debt;
       }
    }
  }
}

console.log(`\nVERIFIED_DEBT_TOTAL: ${verifiedDebt.toFixed(2)} ILS`);
console.log(`Previous Calculation:  350914.00 ILS`);

