// @ts-nocheck
import Database, { type Database as DatabaseType } from 'better-sqlite3'
import path from 'node:path'
import fs from 'node:fs'
import bcrypt from 'bcryptjs'

export let db: any;

try {
  const DB_DIR = process.env.DATA_DIR
    ? path.resolve(process.env.DATA_DIR)
    : path.resolve(process.cwd(), 'data')
  if (!fs.existsSync(DB_DIR)) {
    fs.mkdirSync(DB_DIR, { recursive: true })
  }

  const dbPath = path.join(DB_DIR, 'os_hub.db')
  db = new Database(dbPath)

  // הפעלת WAL mode ו-Foreign Keys לביצועים מהירים ואמינות
  db.pragma('journal_mode = WAL')
  db.pragma('foreign_keys = ON')
} catch (e) {
  // Ignore in D1 environment
}

export function setD1Database(d1: any) {
  db = d1
}

export function initLocalDatabase() {
  db.exec(`
    -- 1. טבלת לקוחות (Clients)
    CREATE TABLE IF NOT EXISTS clients (
      id TEXT PRIMARY KEY,
      green_invoice_id TEXT UNIQUE,
      name TEXT NOT NULL,
      tax_id TEXT,
      email TEXT,
      phone TEXT,
      status TEXT DEFAULT 'active',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    -- 2. טבלת דומיינים (Domains)
    CREATE TABLE IF NOT EXISTS domains (
      id TEXT PRIMARY KEY,
      domain_name TEXT NOT NULL UNIQUE,
      registrar TEXT,
      expiry_date DATE,
      is_active INTEGER DEFAULT 1,
      status TEXT DEFAULT 'ok',
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    -- 3. טבלת בדיקות SSH (SSH Health Checks)
    CREATE TABLE IF NOT EXISTS ssh_checks (
      id TEXT PRIMARY KEY,
      domain_name TEXT NOT NULL,
      host TEXT NOT NULL,
      port INTEGER DEFAULT 22,
      status TEXT CHECK(status IN ('ok', 'error')) DEFAULT 'ok',
      last_check DATETIME DEFAULT CURRENT_TIMESTAMP,
      error_log TEXT
    );

    -- 4. טבלת הצעות מחיר (Quotes)
    CREATE TABLE IF NOT EXISTS quotes (
      id TEXT PRIMARY KEY,
      quote_number TEXT UNIQUE NOT NULL,
      client_id TEXT,
      total_amount REAL NOT NULL,
      status TEXT CHECK(status IN ('draft', 'sent', 'signed', 'cancelled')) DEFAULT 'draft',
      signature_data TEXT,
      signed_at DATETIME,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY(client_id) REFERENCES clients(id)
    );

    -- 5. טבלת לוגים של המערכת (System Logs)
    CREATE TABLE IF NOT EXISTS system_logs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      event_type TEXT NOT NULL,
      target TEXT NOT NULL,
      details TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    -- 6. טבלת משתמשים (Users)
    CREATE TABLE IF NOT EXISTS users (
      id TEXT PRIMARY KEY,
      email TEXT UNIQUE NOT NULL,
      password_hash TEXT NOT NULL,
      role TEXT DEFAULT 'user',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    -- 7. טבלת פרויקטים (Projects)
    CREATE TABLE IF NOT EXISTS projects (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      client_id TEXT,
      status TEXT DEFAULT 'active',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY(client_id) REFERENCES clients(id)
    );

    -- 8. טבלת לידים (Leads)
    CREATE TABLE IF NOT EXISTS leads (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      email TEXT,
      phone TEXT,
      status TEXT DEFAULT 'new',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    -- 9. טבלת משימות (Tasks)
    CREATE TABLE IF NOT EXISTS tasks (
      id TEXT PRIMARY KEY,
      title TEXT NOT NULL,
      description TEXT,
      status TEXT DEFAULT 'todo',
      project_id TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY(project_id) REFERENCES projects(id)
    );

    -- 10. טבלת פגישות (Meetings)
    CREATE TABLE IF NOT EXISTS meetings (
      id TEXT PRIMARY KEY,
      title TEXT NOT NULL,
      date DATETIME NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    -- 11. טבלת קמפיינים (Campaigns)
    CREATE TABLE IF NOT EXISTS campaigns (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      status TEXT DEFAULT 'active',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    -- 12. טבלת חשבוניות (Invoices)
    CREATE TABLE IF NOT EXISTS invoices (
      id TEXT PRIMARY KEY,
      client_id TEXT,
      amount REAL NOT NULL,
      status TEXT DEFAULT 'unpaid',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY(client_id) REFERENCES clients(id)
    );

    -- 13. טבלת מסמכים גנרית (Generic Documents for NoSQL compatibility)
    CREATE TABLE IF NOT EXISTS generic_documents (
      id TEXT PRIMARY KEY,
      collection TEXT NOT NULL,
      data JSON NOT NULL,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE TABLE IF NOT EXISTS suppliers (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      contact_info TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    -- 14. אינדקסים קריטיים לביצועים מהירים מ-1ms (O(log N))
    CREATE INDEX IF NOT EXISTS idx_gen_docs_coll ON generic_documents(collection);
    CREATE INDEX IF NOT EXISTS idx_gen_docs_coll_id ON generic_documents(collection, id);
    CREATE INDEX IF NOT EXISTS idx_gen_docs_updated ON generic_documents(collection, updated_at DESC);
    CREATE INDEX IF NOT EXISTS idx_quotes_client ON quotes(client_id);
    CREATE INDEX IF NOT EXISTS idx_system_logs_type ON system_logs(event_type, id DESC);
    CREATE INDEX IF NOT EXISTS idx_ssh_checks_domain ON ssh_checks(domain_name, last_check DESC);

    -- 15. טבלת לוגים של סוכני ה-AI (AI Agent Logs)
    CREATE TABLE IF NOT EXISTS ai_agent_logs (
      id TEXT PRIMARY KEY,
      agent_id TEXT NOT NULL,
      timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
      prompt_tokens INTEGER DEFAULT 0,
      completion_tokens INTEGER DEFAULT 0,
      execution_time_ms INTEGER DEFAULT 0,
      estimated_cost_usd REAL DEFAULT 0,
      business_value_score INTEGER DEFAULT 0,
      context_summary TEXT
    );

    -- 16. Digital CEO Actions Ledger
    CREATE TABLE IF NOT EXISTS ceo_actions (
      action_id TEXT PRIMARY KEY,
      action_type TEXT NOT NULL,
      status TEXT NOT NULL,
      reasoning TEXT,
      proposed_payload JSON,
      ledger JSON DEFAULT '[]',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
  `)
}

export function logSystemEvent(eventType: string, target: string, details?: string) {
  const stmt = db.prepare(`
    INSERT INTO system_logs (event_type, target, details)
    VALUES (?, ?, ?)
  `)
  stmt.run(eventType, target, details ?? null)
}

export function seedSuperAdmin() {
  const email = 'a0527777817@gmail.com'
  const password = '123456'
  const hash = bcrypt.hashSync(password, 10)

  // UUID קבוע — אותו ID בכל סביבה (Local, Docker, Production)
  const CEO_ID = '00000000-0000-4000-a000-000000000001'

  // Seed or update CEO
  db.prepare('INSERT OR IGNORE INTO users (id, email, password_hash, role) VALUES (?, ?, ?, ?)').run(
    CEO_ID, email, hash, 'ceo'
  )
  db.prepare("UPDATE users SET role = 'ceo', id = ? WHERE email = ?").run(CEO_ID, email)

  // Seed demo users with fixed IDs
  const demoUsers = [
    { id: '00000000-0000-4000-a000-000000000002', email: 'finance@hub.co.il', role: 'finance' },
    { id: '00000000-0000-4000-a000-000000000003', email: 'pm@hub.co.il', role: 'project_manager' },
    { id: '00000000-0000-4000-a000-000000000004', email: 'sales@hub.co.il', role: 'sales' }
  ]

  for (const user of demoUsers) {
    db.prepare('INSERT OR IGNORE INTO users (id, email, password_hash, role) VALUES (?, ?, ?, ?)').run(
      user.id, user.email, hash, user.role
    )
  }

  console.log('Super Admin and demo users seeded.')
}
