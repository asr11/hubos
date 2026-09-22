import Database from 'better-sqlite3';
const db = new Database('/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db');

const rows = db.prepare(`SELECT data FROM generic_documents WHERE collection = 'invoices'`).all();
let unpaid = [];

for (const row of rows) {
  const doc = JSON.parse(row.data);
  if (doc.status !== 'paid' && doc.status !== 'cancelled' && doc.amount) {
    unpaid.push({
      client: doc.greenInvoiceClientName || 'Unknown',
      amount: doc.amount,
      date: doc.invoiceDate
    });
  }
}

unpaid.sort((a, b) => b.amount - a.amount);
console.log('Top 10 Unpaid Invoices:');
unpaid.slice(0, 10).forEach(u => console.log(`- ${u.client}: ${u.amount} ILS (${u.date})`));
