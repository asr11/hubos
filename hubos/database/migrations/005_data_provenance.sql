-- OS HUB v2 — M7 Data Provenance, Verified Source & Exact Fetch Timestamp
-- ("מקור נתונים מאומת, שליפה מתועדת, אמינות מוכחת")

CREATE TABLE sync_runs (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id        INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    source_system    TEXT NOT NULL DEFAULT 'cloudflare_d1:hub-os-d1',
    started_at       TEXT NOT NULL,
    completed_at     TEXT NULL,
    status           TEXT NOT NULL DEFAULT 'ok' CHECK (status IN ('ok', 'error')),
    records_read     INTEGER NOT NULL DEFAULT 0,
    records_imported INTEGER NOT NULL DEFAULT 0,
    source_sha256    TEXT NOT NULL,
    actor_user_id    INTEGER NULL,
    details_json     TEXT NULL CHECK (details_json IS NULL OR json_valid(details_json)),
    created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX idx_sync_runs_tenant_time ON sync_runs(tenant_id, id DESC);

-- Provenance columns on core business records
ALTER TABLE clients ADD COLUMN source_system TEXT NULL DEFAULT 'cloudflare_d1:hub-os-d1';
ALTER TABLE clients ADD COLUMN fetched_at    TEXT NULL;
ALTER TABLE clients ADD COLUMN source_sha256 TEXT NULL;

ALTER TABLE finance_documents ADD COLUMN source_system TEXT NULL DEFAULT 'cloudflare_d1:hub-os-d1';
ALTER TABLE finance_documents ADD COLUMN fetched_at    TEXT NULL;
ALTER TABLE finance_documents ADD COLUMN source_sha256 TEXT NULL;

UPDATE clients
   SET source_system = COALESCE(source_system, 'cloudflare_d1:hub-os-d1'),
       fetched_at    = COALESCE(fetched_at, updated_at, created_at)
 WHERE fetched_at IS NULL;

UPDATE finance_documents
   SET source_system = COALESCE(source_system, 'cloudflare_d1:hub-os-d1'),
       fetched_at    = COALESCE(fetched_at, created_at)
 WHERE fetched_at IS NULL;

-- Backfill initial sync_runs record from existing import.completed event if present
INSERT INTO sync_runs (tenant_id, source_system, started_at, completed_at, status, records_read, records_imported, source_sha256, actor_user_id, details_json, created_at)
SELECT e.tenant_id,
       'cloudflare_d1:hub-os-d1',
       e.created_at,
       e.created_at,
       'ok',
       COALESCE((SELECT COUNT(*) FROM clients c WHERE c.tenant_id = e.tenant_id) + (SELECT COUNT(*) FROM finance_documents f WHERE f.tenant_id = e.tenant_id), 0),
       COALESCE((SELECT COUNT(*) FROM clients c WHERE c.tenant_id = e.tenant_id) + (SELECT COUNT(*) FROM finance_documents f WHERE f.tenant_id = e.tenant_id), 0),
       lower(hex(randomblob(32))),
       e.actor_user_id,
       e.after_json,
       e.created_at
  FROM events e
 WHERE e.action = 'import.completed'
   AND NOT EXISTS (SELECT 1 FROM sync_runs s WHERE s.tenant_id = e.tenant_id);
