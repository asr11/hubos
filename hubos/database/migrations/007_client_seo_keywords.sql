-- OS HUB v2 — M9: Client SEO & GEO Keywords Progress Tracker (transparent on Client Portal & PDF Report)

CREATE TABLE IF NOT EXISTS client_seo_keywords (
    id            INTEGER PRIMARY KEY,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    client_id     INTEGER NOT NULL,
    keyword       TEXT NOT NULL CHECK (length(keyword) BETWEEN 1 AND 200),
    target_url    TEXT NULL,
    stage         TEXT NOT NULL DEFAULT 'optimizing' CHECK (stage IN ('research', 'optimizing', 'tracking', 'top10', 'top3')),
    current_rank  INTEGER NULL,
    previous_rank INTEGER NULL,
    notes         TEXT NULL,
    updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_client_seo_kw ON client_seo_keywords(tenant_id, client_id);
