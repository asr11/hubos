import { db } from '../src/lib/db.js';
db.prepare("DELETE FROM ceo_actions WHERE proposed_payload LIKE '%DupAlert%'").run();
console.log('Cleaned DupAlert records.');
