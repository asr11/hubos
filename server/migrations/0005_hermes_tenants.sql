CREATE TABLE IF NOT EXISTS tenants (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE users ADD COLUMN tenant_id TEXT REFERENCES tenants(id);
ALTER TABLE clients ADD COLUMN tenant_id TEXT REFERENCES tenants(id);
ALTER TABLE projects ADD COLUMN tenant_id TEXT REFERENCES tenants(id);
ALTER TABLE quotes ADD COLUMN tenant_id TEXT REFERENCES tenants(id);
ALTER TABLE quote_items ADD COLUMN tenant_id TEXT REFERENCES tenants(id);
ALTER TABLE invoices ADD COLUMN tenant_id TEXT REFERENCES tenants(id);
ALTER TABLE leads ADD COLUMN tenant_id TEXT REFERENCES tenants(id);
ALTER TABLE tasks ADD COLUMN tenant_id TEXT REFERENCES tenants(id);
ALTER TABLE meetings ADD COLUMN tenant_id TEXT REFERENCES tenants(id);
ALTER TABLE campaigns ADD COLUMN tenant_id TEXT REFERENCES tenants(id);
