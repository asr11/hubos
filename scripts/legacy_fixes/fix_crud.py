import re

# 1. Update app.ts
with open('server/src/app.ts', 'r') as f:
    app_ts = f.read()

if 'registerCrudRoutes' not in app_ts:
    app_ts = app_ts.replace("import { registerAuthRoutes } from './routes/auth.js'", "import { registerAuthRoutes } from './routes/auth.js'\nimport { registerCrudRoutes } from './routes/crud.js'")
    app_ts = app_ts.replace("await registerAuthRoutes(app)", "await registerAuthRoutes(app)\n  await registerCrudRoutes(app)")
    
with open('server/src/app.ts', 'w') as f:
    f.write(app_ts)

# 2. Update db.ts to add generic_documents table
with open('server/src/lib/db.ts', 'r') as f:
    db_ts = f.read()

if 'generic_documents' not in db_ts:
    table_sql = """
    -- 14. טבלת מסמכים גנרית (Generic Documents for NoSQL compatibility)
    CREATE TABLE IF NOT EXISTS generic_documents (
      id TEXT PRIMARY KEY,
      collection TEXT NOT NULL,
      data JSON NOT NULL,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    """
    db_ts = db_ts.replace('CREATE TABLE IF NOT EXISTS suppliers (', table_sql + '\n    CREATE TABLE IF NOT EXISTS suppliers (')

with open('server/src/lib/db.ts', 'w') as f:
    f.write(db_ts)

