import Database from 'better-sqlite3';
const db = new Database('/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db');

const rows = db.prepare(`SELECT data FROM generic_documents WHERE collection = 'invoices'`).all();
const services = {};
let totalRevenue = 0;

for (const row of rows) {
  const doc = JSON.parse(row.data);
  if (doc.status === 'paid' && doc.paidAmount) {
    totalRevenue += doc.paidAmount;
    const desc = doc.description || 'ללא תיאור';
    // Normalize description slightly to group similar ones (e.g. months)
    const baseDesc = desc.replace(/[-–]?\s*(ינואר|פברואר|מרץ|אפריל|מאי|יוני|יולי|אוגוסט|ספטמבר|אוקטובר|נובמבר|דצמבר)\s*202\d/g, '').trim();
    if (!services[baseDesc]) services[baseDesc] = { revenue: 0, count: 0 };
    services[baseDesc].revenue += doc.paidAmount;
    services[baseDesc].count += 1;
  }
}

const sorted = Object.entries(services)
  .sort((a, b) => b[1].revenue - a[1].revenue)
  .slice(0, 15);

console.log(`Total Paid Revenue: ${totalRevenue}`);
console.log('Top Services:');
for (const [name, stats] of sorted) {
  console.log(`- ${name}: ${stats.revenue} ILS (${stats.count} invoices)`);
}
