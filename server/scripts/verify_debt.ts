import Database from 'better-sqlite3';
const db = new Database('/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db');

const rows = db.prepare(`SELECT data FROM generic_documents WHERE collection = 'invoices'`).all();
const rawDocs = rows.map(r => JSON.parse(r.data));

const grouped = {};
let totalUnpaid = 0;
let verifiedDebt = 0;

for (const doc of rawDocs) {
  const kind = doc.documentKind || 'unknown';
  const status = doc.status; // mapped status
  const giType = doc.greenInvoiceType; // Morning numeric type
  const amount = doc.amount || 0;
  
  const key = `${kind} | Type: ${giType}`;
  if (!grouped[key]) {
    grouped[key] = { COUNT: 0, TOTAL: 0, PAID: 0, OPEN: 0, CANCELLED: 0, UNKNOWN: 0 };
  }
  
  grouped[key].COUNT++;
  grouped[key].TOTAL += amount;
  
  if (status === 'paid') grouped[key].PAID += amount;
  else if (status === 'cancelled') grouped[key].CANCELLED += amount;
  else if (status === 'pending' || status === 'unpaid') grouped[key].OPEN += amount;
  else grouped[key].UNKNOWN += amount;

  if (status !== 'paid' && status !== 'cancelled') {
    totalUnpaid += amount;
  }
}

console.log('--- DOCUMENT DISTRIBUTION ---');
console.log('DOCUMENT_TYPE | GI_TYPE | COUNT | TOTAL | PAID | OPEN | CANCELLED | UNKNOWN');
for (const [key, stats] of Object.entries(grouped)) {
  const parts = key.split(' | Type: ');
  console.log(`${parts[0].padEnd(25)} | ${parts[1].padEnd(7)} | ${stats.COUNT.toString().padEnd(5)} | ${stats.TOTAL.toFixed(2).padEnd(10)} | ${stats.PAID.toFixed(2).padEnd(10)} | ${stats.OPEN.toFixed(2).padEnd(10)} | ${stats.CANCELLED.toFixed(2).padEnd(10)} | ${stats.UNKNOWN.toFixed(2)}`);
}

// How to define VERIFIED_DEBT?
// Typically, 'transaction_account' (חשבון עסקה, type 300) is a payment demand.
// 'tax_invoice' (חשבונית מס, type 305) is a real invoice that might be unpaid.
// 'tax_invoice_receipt' (חשבונית מס קבלה, type 320) is ALREADY PAID by definition.
// 'receipt' (קבלה, type 400) is ALREADY PAID.
// 'credit_note' (חשבונית זיכוי, type 330) is a refund/credit.

console.log('\n--- VERIFIED DEBT CALCULATION ---');
for (const doc of rawDocs) {
  if (doc.status !== 'paid' && doc.status !== 'cancelled') {
    // Only certain types should be considered collectible debt.
    // 300 = חשבון עסקה (Pro-forma)
    // 305 = חשבונית מס (Tax Invoice)
    if (doc.greenInvoiceType === 300 || doc.greenInvoiceType === 305) {
      verifiedDebt += (doc.amount || 0);
    }
  }
}

console.log(`Original UNPAID calculation: ${totalUnpaid.toFixed(2)} ILS`);
console.log(`VERIFIED_DEBT_TOTAL:         ${verifiedDebt.toFixed(2)} ILS`);
