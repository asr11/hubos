-- OS HUB v2 — M8: Isolated Human Sales Partner Role (10% Trust Ledger) & Multi-Tenant SaaS Onboarding
-- Additive only: preserves existing users CHECK constraint while adding partner isolation & commission tracking.

ALTER TABLE users ADD COLUMN is_partner INTEGER NOT NULL DEFAULT 0 CHECK (is_partner IN (0, 1));
ALTER TABLE users ADD COLUMN commission_pct REAL NOT NULL DEFAULT 10.0;

ALTER TABLE clients ADD COLUMN partner_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_clients_partner ON clients(tenant_id, partner_user_id);

ALTER TABLE leads ADD COLUMN partner_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_leads_partner ON leads(tenant_id, partner_user_id);
