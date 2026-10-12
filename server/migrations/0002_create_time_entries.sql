-- Migration number: 0002 	 2026-10-05T11:56:23.696Z
-- Table: time_entries (Hours tracking / Bank of hours)

CREATE TABLE IF NOT EXISTS time_entries (
  id TEXT PRIMARY KEY,
  project_id TEXT,
  client_id TEXT,
  task_id TEXT,
  user_id TEXT,
  description TEXT,
  duration_seconds INTEGER DEFAULT 0,
  duration REAL DEFAULT 0,
  start_time DATETIME,
  end_time DATETIME,
  date DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(project_id) REFERENCES projects(id),
  FOREIGN KEY(client_id) REFERENCES clients(id),
  FOREIGN KEY(task_id) REFERENCES tasks(id)
);

CREATE INDEX IF NOT EXISTS idx_time_entries_project ON time_entries(project_id);
CREATE INDEX IF NOT EXISTS idx_time_entries_client ON time_entries(client_id);
CREATE INDEX IF NOT EXISTS idx_time_entries_task ON time_entries(task_id);
CREATE INDEX IF NOT EXISTS idx_time_entries_user ON time_entries(user_id);
CREATE INDEX IF NOT EXISTS idx_time_entries_date ON time_entries(date DESC);
