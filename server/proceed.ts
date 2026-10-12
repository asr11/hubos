import { db } from './src/lib/db.js';
import { generateDraftReminders } from './src/services/collectionEngine.js';

async function run() {
  console.log("Before: Drafts=", db.prepare('SELECT count(*) as c FROM collection_drafts').get().c);
  db.prepare('DELETE FROM collection_drafts').run();
  db.prepare("DELETE FROM generic_documents WHERE collection='collection_drafts'").run();
  console.log("Deleted old drafts.");
  
  const res = await generateDraftReminders();
  console.log("Scan complete:", res);
  
  console.log("After: Drafts=", db.prepare('SELECT count(*) as c FROM collection_drafts').get().c);
  console.log("After: Generic=", db.prepare("SELECT count(*) as c FROM generic_documents WHERE collection='collection_drafts'").get().c);
  
  const samples = db.prepare('SELECT message_body FROM collection_drafts LIMIT 3').all();
  console.log("Samples:");
  samples.forEach((s, i) => console.log(`--- Sample ${i+1} ---\n${s.message_body}\n`));
}
run().catch(console.error);
