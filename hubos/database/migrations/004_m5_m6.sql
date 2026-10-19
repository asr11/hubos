-- OS HUB v2 — M5 (assets, DNS/SSL/WHOIS inspection, vault asset linking) & M6 (modules)
-- Additive only: existing rows keep their values.

-- ------------------------------------------------------------------ M5: assets
CREATE UNIQUE INDEX IF NOT EXISTS ux_assets_tenant_id ON assets(tenant_id, id);

ALTER TABLE assets ADD COLUMN registrar         TEXT NULL;
ALTER TABLE assets ADD COLUMN ssl_status        TEXT NULL;
ALTER TABLE assets ADD COLUMN ssl_expiry_date   TEXT NULL;
ALTER TABLE assets ADD COLUMN last_inspected_at TEXT NULL;

-- ------------------------------------------------------------------ M5: vault -> asset link
ALTER TABLE vault_items ADD COLUMN asset_id INTEGER NULL REFERENCES assets(id) ON DELETE SET NULL;
CREATE INDEX idx_vault_items_asset ON vault_items(tenant_id, asset_id);

-- ------------------------------------------------------------------ module switches
INSERT OR IGNORE INTO tenant_modules (tenant_id, module, enabled) SELECT id, 'assets', 1 FROM tenants;
INSERT OR IGNORE INTO tenant_modules (tenant_id, module, enabled) SELECT id, 'vault', 1 FROM tenants;
