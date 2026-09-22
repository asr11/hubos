import Database from 'better-sqlite3';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const dbPath = path.resolve(__dirname, 'data/os_hub.db');

const db = new Database(dbPath);

db.exec(`
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
`);

const stmt = db.prepare(`
  INSERT INTO ai_agent_logs (id, agent_id, prompt_tokens, completion_tokens, execution_time_ms, estimated_cost_usd, business_value_score, context_summary)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?)
`);

stmt.run('m1', 'hub-ceo-bot', 1200, 300, 4500, 0.005, 5, 'Analyzed Q3 finances');
stmt.run('m2', 'hub-sales-bot', 800, 150, 2000, 0.002, 2, 'Drafted email to client X');
stmt.run('m3', 'hub-pm-bot', 2500, 800, 12000, 0.015, 8, 'Generated full project spec');

console.log('Mock data injected successfully.');
