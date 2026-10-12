-- 0006_ssot_data_column.sql
-- Phase 2 SSOT: hybrid model (core SQL columns + `data` JSON column).
-- Target: Cloudflare D1 (SQLite). No BEGIN/COMMIT (D1 rejects them in migration files).
-- Safety: NO DELETE, NO DROP. generic_documents is READ ONLY here (never modified).
--
-- Idempotency:
--   * Section 1 (ALTER TABLE ADD COLUMN) is NOT re-runnable: SQLite has no
--     "ADD COLUMN IF NOT EXISTS". D1 tracks applied files in d1_migrations, so
--     `wrangler d1 migrations apply` runs it exactly once.
--   * Sections 2-5 are guarded by WHERE clauses and are safe to re-run
--     (backfill only touches rows whose data is still '{}'; status updates only
--     touch non-canonical values; legacyStatus keeps the FIRST original value).
--
-- JSON safety: every json_* call on generic_documents.data is wrapped in
--   CASE WHEN json_valid(...) THEN ... END   (CASE is lazily evaluated in SQLite).

-- =====================================================================
-- 1. Schema: add `data` column (default empty JSON object)
-- =====================================================================
ALTER TABLE projects  ADD COLUMN data TEXT NOT NULL DEFAULT '{}';
ALTER TABLE tasks     ADD COLUMN data TEXT NOT NULL DEFAULT '{}';
ALTER TABLE leads     ADD COLUMN data TEXT NOT NULL DEFAULT '{}';
ALTER TABLE suppliers ADD COLUMN data TEXT NOT NULL DEFAULT '{}';
ALTER TABLE campaigns ADD COLUMN data TEXT NOT NULL DEFAULT '{}';

-- =====================================================================
-- 2. Backfill `data` from generic_documents (same id, same collection).
--    Core keys removed: id, name, status, clientId, title
--    (core values stay in their SQL columns).
-- =====================================================================
UPDATE projects
SET data = (
  SELECT json_remove(g.data, '$.id', '$.name', '$.status', '$.clientId', '$.title')
  FROM generic_documents g
  WHERE g.collection = 'projects' AND g.id = projects.id
    AND CASE WHEN json_valid(g.data) THEN json_type(g.data) = 'object' ELSE 0 END
)
WHERE data = '{}'
  AND EXISTS (
    SELECT 1 FROM generic_documents g
    WHERE g.collection = 'projects' AND g.id = projects.id
      AND CASE WHEN json_valid(g.data) THEN json_type(g.data) = 'object' ELSE 0 END
  );

UPDATE tasks
SET data = (
  SELECT json_remove(g.data, '$.id', '$.name', '$.status', '$.clientId', '$.title')
  FROM generic_documents g
  WHERE g.collection = 'tasks' AND g.id = tasks.id
    AND CASE WHEN json_valid(g.data) THEN json_type(g.data) = 'object' ELSE 0 END
)
WHERE data = '{}'
  AND EXISTS (
    SELECT 1 FROM generic_documents g
    WHERE g.collection = 'tasks' AND g.id = tasks.id
      AND CASE WHEN json_valid(g.data) THEN json_type(g.data) = 'object' ELSE 0 END
  );

UPDATE leads
SET data = (
  SELECT json_remove(g.data, '$.id', '$.name', '$.status', '$.clientId', '$.title')
  FROM generic_documents g
  WHERE g.collection = 'leads' AND g.id = leads.id
    AND CASE WHEN json_valid(g.data) THEN json_type(g.data) = 'object' ELSE 0 END
)
WHERE data = '{}'
  AND EXISTS (
    SELECT 1 FROM generic_documents g
    WHERE g.collection = 'leads' AND g.id = leads.id
      AND CASE WHEN json_valid(g.data) THEN json_type(g.data) = 'object' ELSE 0 END
  );

UPDATE suppliers
SET data = (
  SELECT json_remove(g.data, '$.id', '$.name', '$.status', '$.clientId', '$.title')
  FROM generic_documents g
  WHERE g.collection = 'suppliers' AND g.id = suppliers.id
    AND CASE WHEN json_valid(g.data) THEN json_type(g.data) = 'object' ELSE 0 END
)
WHERE data = '{}'
  AND EXISTS (
    SELECT 1 FROM generic_documents g
    WHERE g.collection = 'suppliers' AND g.id = suppliers.id
      AND CASE WHEN json_valid(g.data) THEN json_type(g.data) = 'object' ELSE 0 END
  );

UPDATE campaigns
SET data = (
  SELECT json_remove(g.data, '$.id', '$.name', '$.status', '$.clientId', '$.title')
  FROM generic_documents g
  WHERE g.collection = 'campaigns' AND g.id = campaigns.id
    AND CASE WHEN json_valid(g.data) THEN json_type(g.data) = 'object' ELSE 0 END
)
WHERE data = '{}'
  AND EXISTS (
    SELECT 1 FROM generic_documents g
    WHERE g.collection = 'campaigns' AND g.id = campaigns.id
      AND CASE WHEN json_valid(g.data) THEN json_type(g.data) = 'object' ELSE 0 END
  );

-- 2b. Preserve (do not apply) a generic status that differs from the table
--     status, e.g. UI PATCHes that wrote status='done' only to generic_documents.
--     Stored as data.legacyGenericStatus. Applying it is a separate, OPTIONAL
--     decision (see OPTIONAL_0007_apply_ui_project_status.sql).
UPDATE projects
SET data = json_set(data, '$.legacyGenericStatus', (
  SELECT CASE WHEN json_valid(g.data) THEN json_extract(g.data, '$.status') END
  FROM generic_documents g
  WHERE g.collection = 'projects' AND g.id = projects.id
))
WHERE json_extract(data, '$.legacyGenericStatus') IS NULL
  AND EXISTS (
    SELECT 1 FROM generic_documents g
    WHERE g.collection = 'projects' AND g.id = projects.id
      AND CASE WHEN json_valid(g.data) THEN json_extract(g.data, '$.status') END IS NOT NULL
      -- compare with the ORIGINAL table status (legacyStatus after section 4) => re-run safe
      AND CASE WHEN json_valid(g.data) THEN json_extract(g.data, '$.status') END
          IS NOT COALESCE(json_extract(projects.data, '$.legacyStatus'), projects.status)
  );

-- =====================================================================
-- 3. projects.client_id from generic $.clientId (only when table value is
--    NULL/'' and generic value is non-empty).
--    FK guard: projects.client_id REFERENCES clients(id) and D1 enforces FKs,
--    so only ids that exist in `clients` are written to the column.
-- =====================================================================
UPDATE projects
SET client_id = (
  SELECT CAST(CASE WHEN json_valid(g.data) THEN json_extract(g.data, '$.clientId') END AS TEXT)
  FROM generic_documents g
  WHERE g.collection = 'projects' AND g.id = projects.id
)
WHERE COALESCE(client_id, '') = ''
  AND EXISTS (
    SELECT 1
    FROM generic_documents g
    JOIN clients c
      ON c.id = CAST(CASE WHEN json_valid(g.data) THEN json_extract(g.data, '$.clientId') END AS TEXT)
    WHERE g.collection = 'projects' AND g.id = projects.id
      AND COALESCE(CASE WHEN json_valid(g.data) THEN json_extract(g.data, '$.clientId') END, '') <> ''
  );

-- 3b. Generic clientId that does NOT exist in `clients` (FK would fail):
--     keep it in data.clientId so it is not lost (API shows it when column is NULL).
UPDATE projects
SET data = json_set(data, '$.clientId', (
  SELECT CAST(CASE WHEN json_valid(g.data) THEN json_extract(g.data, '$.clientId') END AS TEXT)
  FROM generic_documents g
  WHERE g.collection = 'projects' AND g.id = projects.id
))
WHERE COALESCE(client_id, '') = ''
  AND json_extract(data, '$.clientId') IS NULL
  AND EXISTS (
    SELECT 1 FROM generic_documents g
    WHERE g.collection = 'projects' AND g.id = projects.id
      AND COALESCE(CASE WHEN json_valid(g.data) THEN json_extract(g.data, '$.clientId') END, '') <> ''
  );

-- =====================================================================
-- 4. Status normalization. Original value kept in data.legacyStatus
--    (first original wins: COALESCE with an existing legacyStatus).
-- =====================================================================

-- 4a. projects: פעיל/active->active, מושהה/paused->paused,
--     הושלם/completed/done->done, anything else->archived (+ data.auditLabel)
UPDATE projects
SET data = CASE
    WHEN TRIM(COALESCE(status, '')) IN ('פעיל', 'active', 'מושהה', 'paused', 'הושלם', 'completed', 'done')
      THEN json_set(data, '$.legacyStatus', COALESCE(json_extract(data, '$.legacyStatus'), status))
    ELSE json_set(data,
      '$.legacyStatus', COALESCE(json_extract(data, '$.legacyStatus'), status),
      '$.auditLabel',   COALESCE(json_extract(data, '$.auditLabel'), status))
  END,
  status = CASE
    WHEN TRIM(COALESCE(status, '')) IN ('פעיל', 'active')               THEN 'active'
    WHEN TRIM(COALESCE(status, '')) IN ('מושהה', 'paused')              THEN 'paused'
    WHEN TRIM(COALESCE(status, '')) IN ('הושלם', 'completed', 'done')   THEN 'done'
    ELSE 'archived'
  END
WHERE status IS NULL OR status NOT IN ('active', 'paused', 'done', 'archived');

-- 4b. tasks: open/לביצוע->todo, in_progress/בתהליך->doing, completed/הושלם->done.
--     Unknown values are left untouched (no guessing).
UPDATE tasks
SET data = json_set(data, '$.legacyStatus', COALESCE(json_extract(data, '$.legacyStatus'), status)),
  status = CASE TRIM(status)
    WHEN 'open'        THEN 'todo'
    WHEN 'לביצוע'      THEN 'todo'
    WHEN 'in_progress' THEN 'doing'
    WHEN 'בתהליך'      THEN 'doing'
    WHEN 'completed'   THEN 'done'
    WHEN 'הושלם'       THEN 'done'
  END
WHERE TRIM(status) IN ('open', 'לביצוע', 'in_progress', 'בתהליך', 'completed', 'הושלם');

-- 4c. leads: לא רלוונטי->not_relevant, נשלחה הצעת מחיר->quote_sent, נסגרה->closed,
--     בתהליך אפיון->specification, contacted->follow_up. Unknown values untouched.
UPDATE leads
SET data = json_set(data, '$.legacyStatus', COALESCE(json_extract(data, '$.legacyStatus'), status)),
  status = CASE TRIM(status)
    WHEN 'לא רלוונטי'        THEN 'not_relevant'
    WHEN 'נשלחה הצעת מחיר'   THEN 'quote_sent'
    WHEN 'נסגרה'             THEN 'closed'
    WHEN 'בתהליך אפיון'      THEN 'specification'
    WHEN 'contacted'         THEN 'follow_up'
  END
WHERE TRIM(status) IN ('לא רלוונטי', 'נשלחה הצעת מחיר', 'נסגרה', 'בתהליך אפיון', 'contacted');

-- campaigns: 0 rows at time of writing, no mapping defined -> nothing to do.
-- suppliers: no status column -> nothing to do.

-- =====================================================================
-- 5. Indexes for the default list filters.
-- =====================================================================
CREATE INDEX IF NOT EXISTS idx_projects_status ON projects(status);
CREATE INDEX IF NOT EXISTS idx_tasks_project ON tasks(project_id);
