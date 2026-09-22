import Database from 'better-sqlite3';
const db = new Database('data/legacy_hub_os.db');

db.exec(`
  INSERT INTO leads (name, email, phone, status, source, sla_deadline) 
  VALUES ('ישראל ישראלי', 'israel@example.com', '0541234567', 'new', 'website', '2026-09-02 10:00:00');
  
  INSERT INTO tasks (title, description, due_at, status, assigned_to, created_by)
  VALUES ('הכנת חוזה סופי', 'יש להכין חוזה לפני פגישת התנעה', '2026-09-01 10:00:00', 'open', 1, 1);
`);
console.log("Inserted real legacy data.");
