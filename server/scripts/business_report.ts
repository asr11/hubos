import Database from 'better-sqlite3';
const legacyDb = new Database('data/legacy_hub_os.db', { readonly: true });
const osDb = new Database('data/os_hub.db', { readonly: true });

function queryLegacy(sql: string) {
    try {
        return legacyDb.prepare(sql).all();
    } catch (e: any) {
        return e.message;
    }
}
function queryOs(sql: string) {
    try {
        return osDb.prepare(sql).all();
    } catch (e: any) {
        return e.message;
    }
}

console.log("--- HUB Business State ---");

console.log("\n1. Legacy Unpaid Invoices:");
console.log(queryLegacy("SELECT COUNT(*) as count, SUM(amount) as total FROM invoices WHERE status = 'unpaid'"));

console.log("\n2. Legacy Neglected Leads:");
console.log(queryLegacy("SELECT COUNT(*) as count FROM leads WHERE status IN ('new', 'contacted')"));

console.log("\n3. Legacy Overdue Tasks:");
console.log(queryLegacy("SELECT COUNT(*) as count FROM tasks WHERE status != 'done' AND due_at < datetime('now')"));

console.log("\n4. Active Projects:");
console.log(queryLegacy("SELECT COUNT(*) as count FROM projects WHERE status = 'active'"));

console.log("\n5. CEO Actions (OS DB):");
console.log(queryOs("SELECT action_type, status, COUNT(*) as count FROM ceo_actions GROUP BY action_type, status"));

console.log("\n6. System Errors / API issues:");
// Looking if there is an error log
console.log(queryOs("SELECT * FROM system_logs WHERE event_type LIKE '%error%' LIMIT 5"));

