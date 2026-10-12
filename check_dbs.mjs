import Database from 'better-sqlite3';

function check(path) {
  try {
    const db = new Database(path, { fileMustExist: true });
    const row = db.prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='people'").get();
    console.log(path, "has people table:", !!row);
    
    // While we're here, remove seeds if they exist
    db.exec("DELETE FROM tasks WHERE id LIKE 'emp_task_%'");
    db.exec("DELETE FROM users WHERE id IN ('user_natalie', 'user_israel', 'user_opel', 'user_david')");
  } catch(e) {
    console.log(path, "error:", e.message);
  }
}

check('data/os_hub.db');
check('server/data/os_hub.db');
check('../data/os_hub.db');
