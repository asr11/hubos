import Database from 'better-sqlite3';
const osDb = new Database('data/os_hub.db', { readonly: true });

function queryOs(sql: string) {
    try {
        return osDb.prepare(sql).all();
    } catch (e: any) {
        return e.message;
    }
}

console.log("--- OS DB Business State ---");

console.log("\n1. Unpaid Invoices:");
console.log(queryOs("SELECT COUNT(*) as count, SUM(amount) as total FROM invoices WHERE status = 'unpaid'"));

console.log("\n2. Quotes Pending:");
console.log(queryOs("SELECT COUNT(*) as count, SUM(total_amount) as total FROM quotes WHERE status = 'sent'"));

console.log("\n3. Active Projects:");
console.log(queryOs("SELECT COUNT(*) as count FROM projects WHERE status = 'active'"));
