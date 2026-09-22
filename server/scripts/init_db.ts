import { initLocalDatabase, db } from '../src/lib/db';
initLocalDatabase();
db.prepare("DELETE FROM ceo_actions").run();
console.log("Database initialized and ceo_actions cleared.");
