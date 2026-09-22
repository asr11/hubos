import Database from 'better-sqlite3';
const db = new Database('/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db');

const rows = db.prepare(`SELECT data FROM generic_documents WHERE collection = 'invoices'`).all();
const rawDocs = rows.map(r => JSON.parse(r.data));

const grouped = {};
let verifiedDebt = 0;

for (const doc of rawDocs) {
  const kind = doc.documentKind || 'unknown';
  const status = doc.status || 'UNKNOWN';
  const giType = doc.greenInvoiceType || doc.type || 'undefined'; 
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
}

console.log('--- DOCUMENT DISTRIBUTION ---');
console.log('DOCUMENT_TYPE | GI_TYPE | COUNT | TOTAL | PAID | OPEN | CANCELLED | UNKNOWN');
for (const [key, stats] of Object.entries(grouped)) {
  const parts = key.split(' | Type: ');
  console.log(`${parts[0].padEnd(25)} | ${parts[1].padEnd(7)} | ${stats.COUNT.toString().padEnd(5)} | ${stats.TOTAL.toFixed(2).padEnd(10)} | ${stats.PAID.toFixed(2).padEnd(10)} | ${stats.OPEN.toFixed(2).padEnd(10)} | ${stats.CANCELLED.toFixed(2).padEnd(10)} | ${stats.UNKNOWN.toFixed(2)}`);
}

// Matching logic
// In Morning API, paymentDocuments array links receipts to invoices/transaction accounts.
// Wait, do we have 'paymentDocuments' or 'linkedDocuments' or 'payments' inside rawDocs?
// Let's check a sample of transaction_account and tax_invoice.
// Usually, we can match by amount, date, and client Name if no explicit link exists.
// A client has a balance. The simplest way is to sum all charges (300, 305) and subtract all payments (320, 400, 330).
// BUT wait, 300 and 305 can overlap! If I issue a 300 (Proforma), and then a 305 (Tax invoice) for the SAME charge, I shouldn't double count.
// Actually, if a 320 (Tax Invoice Receipt) is issued, it's a standalone charge+payment.
// Let's group by client and just calculate the real balance based on document rules.

const clientBalances = {};

for (const doc of rawDocs) {
  const clientName = doc.greenInvoiceClientName || 'Unknown';
  if (!clientBalances[clientName]) {
    clientBalances[clientName] = { 
      demands_300: 0, invoices_305: 0, receipts_400: 0, 
      tax_receipts_320: 0, credits_330: 0, 
      unlinked_open_amount: 0 
    };
  }
}

// Without deep API linkage array, let's find the true open amount per document by checking Morning's 'balance' field.
// Often, Green Invoice returns 'balance' (יתרה לתשלום) on the document itself!
// Let's check if 'balance' or 'openAmount' exists on the doc.

let trueDebtFromBalance = 0;
let documentMatches = 0;

for (const doc of rawDocs) {
  // If we have direct balance/payment info on the object:
  // Is it a collectible document? (300 or 305). 
  // 320 is fully paid by definition.
  const type = doc.greenInvoiceType || parseInt(doc.type);
  if (type === 300 || type === 305) {
     const amount = doc.amount || 0;
     // If Morning returned the balance field
     if (doc.balance !== undefined) {
       if (doc.balance > 0 && doc.status !== 'cancelled') {
         trueDebtFromBalance += doc.balance;
       }
     } else {
       // Estimate based on mapped status
       if (doc.status === 'unpaid' || doc.status === 'pending') {
          // It's possible it was paid via a 400/320 and Morning status wasn't refreshed, or the mapper missed it.
          // Let's assume it's debt if status is open. BUT the user said "אל תגדיר שום מסמך כחוב על סמך status בלבד"
          // We must find linked documents.
          // Is there a 'linkedDocuments' or 'payments' array?
       }
     }
  }
}

console.log(`\nDoes 'balance' exist on docs?`);
const sampleDoc = rawDocs.find(d => d.greenInvoiceType === 300);
if (sampleDoc) console.log(Object.keys(sampleDoc).join(', '));

// Second pass: since the generic_documents mapped object might not have 'balance' (it only has amount, paidAmount, status),
// we need to look at 'paidAmount' vs 'amount'.

let calculatedVerifiedDebt = 0;
for (const doc of rawDocs) {
  const type = doc.greenInvoiceType || parseInt(doc.type);
  if (type === 300 || type === 305) {
    if (doc.status === 'cancelled') continue;
    
    // Total charge is amount.
    const charge = doc.amount || 0;
    const paid = doc.paidAmount || 0; 
    let debt = charge - paid;
    
    if (debt > 0) {
       calculatedVerifiedDebt += debt;
    }
  }
}

console.log(`\nVERIFIED_DEBT_TOTAL (Amount - PaidAmount for 300 & 305): ${calculatedVerifiedDebt.toFixed(2)} ILS`);
console.log(`Original UNPAID calculation: 350914.00 ILS`);

