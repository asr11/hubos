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
