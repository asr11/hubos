-- OS HUB v2 — M1 business schema (the 7 roots: clients, work, time, money, sales, assets, access)
-- Every table carries tenant_id. Cross-table references use composite FKs
-- (tenant_id, x_id) -> parent(tenant_id, id) so a row can never point at
-- another tenant's data. legacy_id = id in the old D1/Firebase system
-- (UNIQUE per tenant => idempotent imports).
-- Money: amounts are gross (VAT included) as stored by Morning; vat is the VAT part.

-- ------------------------------------------------------------------ clients
ALTER TABLE clients ADD COLUMN legacy_id TEXT NULL;
ALTER TABLE clients ADD COLUMN tax_id    TEXT NULL;
ALTER TABLE clients ADD COLUMN address   TEXT NULL;
ALTER TABLE clients ADD COLUMN city      TEXT NULL;
ALTER TABLE clients ADD COLUMN domain    TEXT NULL;
ALTER TABLE clients ADD COLUMN notes     TEXT NULL;
ALTER TABLE clients ADD COLUMN data_json TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json));
CREATE UNIQUE INDEX ux_clients_tenant_legacy ON clients(tenant_id, legacy_id);
CREATE UNIQUE INDEX ux_clients_tenant_id     ON clients(tenant_id, id);
CREATE INDEX idx_clients_tenant_tax    ON clients(tenant_id, tax_id);
CREATE INDEX idx_clients_tenant_parent ON clients(tenant_id, parent_client_id);

CREATE UNIQUE INDEX ux_users_tenant_id ON users(tenant_id, id);

CREATE TABLE client_contacts (
    id          INTEGER PRIMARY KEY,
    tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    client_id   INTEGER NOT NULL,
    legacy_id   TEXT NULL,
    name        TEXT NOT NULL,
    role        TEXT NULL,
    phone       TEXT NULL,
    mobile      TEXT NULL,
    email       TEXT NULL,
    is_primary  INTEGER NOT NULL DEFAULT 0 CHECK (is_primary IN (0, 1)),
    notes       TEXT NULL,
    data_json   TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_client_contacts_client ON client_contacts(tenant_id, client_id);

-- ------------------------------------------------------------------ suppliers
CREATE TABLE suppliers (
    id            INTEGER PRIMARY KEY,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id     TEXT NULL,
    name          TEXT NOT NULL,
    email         TEXT NULL,
    phone         TEXT NULL,
    contact_info  TEXT NULL,
    status        TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    data_json     TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    UNIQUE (tenant_id, id)
);

-- ------------------------------------------------------------------ work
CREATE TABLE projects (
    id          INTEGER PRIMARY KEY,
    tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id   TEXT NULL,
    client_id   INTEGER NULL,
    name        TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'active',
    data_json   TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_projects_client ON projects(tenant_id, client_id);

CREATE TABLE tasks (
    id           INTEGER PRIMARY KEY,
    tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id    TEXT NULL,
    client_id    INTEGER NULL,
    project_id   INTEGER NULL,
    title        TEXT NOT NULL,
    description  TEXT NULL,
    status       TEXT NOT NULL DEFAULT 'todo',
    assignee     TEXT NULL,
    due_at       TEXT NULL,
    data_json    TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, client_id)  REFERENCES clients(tenant_id, id)  ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, project_id) REFERENCES projects(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_tasks_client  ON tasks(tenant_id, client_id);
CREATE INDEX idx_tasks_project ON tasks(tenant_id, project_id);

-- ------------------------------------------------------------------ time
CREATE TABLE hours_banks (
    id           INTEGER PRIMARY KEY,
    tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id    TEXT NULL,
    client_id    INTEGER NULL,
    name         TEXT NOT NULL,
    total_hours  REAL NOT NULL DEFAULT 0,
    rate         REAL NULL,
    type         TEXT NULL,
    status       TEXT NOT NULL DEFAULT 'active',
    notes        TEXT NULL,
    data_json    TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE RESTRICT
);

CREATE TABLE time_entries (
    id                INTEGER PRIMARY KEY,
    tenant_id         INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id         TEXT NULL,
    client_id         INTEGER NULL,
    project_id        INTEGER NULL,
    task_id           INTEGER NULL,
    hours_bank_id     INTEGER NULL,
    user_id           INTEGER NULL,
    legacy_user_ref   TEXT NULL,
    description       TEXT NULL,
    entry_date        TEXT NULL,
    started_at        TEXT NULL,
    ended_at          TEXT NULL,
    duration_seconds  INTEGER NOT NULL DEFAULT 0 CHECK (duration_seconds >= 0),
    source            TEXT NULL,
    data_json         TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    FOREIGN KEY (tenant_id, client_id)     REFERENCES clients(tenant_id, id)     ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, project_id)    REFERENCES projects(tenant_id, id)    ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, task_id)       REFERENCES tasks(tenant_id, id)       ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, hours_bank_id) REFERENCES hours_banks(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, user_id)       REFERENCES users(tenant_id, id)       ON DELETE RESTRICT
);
CREATE INDEX idx_time_entries_client ON time_entries(tenant_id, client_id, entry_date);

-- One running timer per user; the server is the source of truth for elapsed time.
CREATE TABLE active_timers (
    id          INTEGER PRIMARY KEY,
    tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    user_id     INTEGER NOT NULL,
    client_id   INTEGER NULL,
    project_id  INTEGER NULL,
    task_id     INTEGER NULL,
    note        TEXT NULL,
    started_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, user_id),
    FOREIGN KEY (tenant_id, user_id)    REFERENCES users(tenant_id, id)    ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, client_id)  REFERENCES clients(tenant_id, id)  ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, project_id) REFERENCES projects(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, task_id)    REFERENCES tasks(tenant_id, id)    ON DELETE RESTRICT
);

-- ------------------------------------------------------------------ money
-- doc_type = Morning (GreenInvoice) document code as text: '300','305','320','400',...
-- counts_as_income is computed by the accounting rule (Modules\Import\AccountingRule).
CREATE TABLE finance_documents (
    id                   INTEGER PRIMARY KEY,
    tenant_id            INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id            TEXT NULL,
    client_id            INTEGER NULL,
    doc_type             TEXT NOT NULL,
    doc_type_source      TEXT NOT NULL DEFAULT 'code' CHECK (doc_type_source IN ('code', 'kind', 'unknown')),
    doc_kind             TEXT NULL,
    doc_number           TEXT NULL,
    doc_date             TEXT NULL,
    amount               REAL NOT NULL DEFAULT 0,
    vat                  REAL NULL,
    currency             TEXT NOT NULL DEFAULT 'ILS',
    status               TEXT NULL,
    paid_amount          REAL NULL,
    paid_date            TEXT NULL,
    description          TEXT NULL,
    client_name_raw      TEXT NULL,
    link_method          TEXT NULL,
    counts_as_income     INTEGER NOT NULL DEFAULT 0 CHECK (counts_as_income IN (0, 1)),
    income_reason        TEXT NULL,
    matched_document_id  INTEGER NULL REFERENCES finance_documents(id) ON DELETE SET NULL,
    source               TEXT NULL,
    data_json            TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at           TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at           TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_finance_documents_client ON finance_documents(tenant_id, client_id, doc_date);
CREATE INDEX idx_finance_documents_income ON finance_documents(tenant_id, counts_as_income, doc_date);

CREATE TABLE expenses (
    id                 INTEGER PRIMARY KEY,
    tenant_id          INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id          TEXT NULL,
    supplier_id        INTEGER NULL,
    client_id          INTEGER NULL,
    supplier_name_raw  TEXT NULL,
    category           TEXT NULL,
    description        TEXT NULL,
    amount             REAL NOT NULL DEFAULT 0,
    vat                REAL NULL,
    amount_before_vat  REAL NULL,
    currency           TEXT NOT NULL DEFAULT 'ILS',
    billing_month      TEXT NULL,
    paid_date          TEXT NULL,
    doc_number         TEXT NULL,
    recurring          INTEGER NOT NULL DEFAULT 0 CHECK (recurring IN (0, 1)),
    vat_class          TEXT NULL,
    source             TEXT NULL,
    data_json          TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at         TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at         TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    FOREIGN KEY (tenant_id, supplier_id) REFERENCES suppliers(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, client_id)   REFERENCES clients(tenant_id, id)   ON DELETE RESTRICT
);
CREATE INDEX idx_expenses_month ON expenses(tenant_id, billing_month);

-- ------------------------------------------------------------------ sales
CREATE TABLE leads (
    id          INTEGER PRIMARY KEY,
    tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id   TEXT NULL,
    client_id   INTEGER NULL,
    name        TEXT NOT NULL,
    email       TEXT NULL,
    phone       TEXT NULL,
    status      TEXT NOT NULL DEFAULT 'new',
    data_json   TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE RESTRICT
);

CREATE TABLE quotes (
    id            INTEGER PRIMARY KEY,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id     TEXT NULL,
    client_id     INTEGER NULL,
    quote_number  TEXT NULL,
    total_amount  REAL NOT NULL DEFAULT 0,
    status        TEXT NOT NULL DEFAULT 'draft',
    signed_at     TEXT NULL,
    data_json     TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE RESTRICT
);

-- ------------------------------------------------------------------ assets
CREATE TABLE assets (
    id           INTEGER PRIMARY KEY,
    tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id    TEXT NULL,
    client_id    INTEGER NULL,
    kind         TEXT NOT NULL DEFAULT 'hosting' CHECK (kind IN ('domain', 'hosting', 'server', 'other')),
    domain       TEXT NULL,
    provider     TEXT NULL,
    ip_address   TEXT NULL,
    expiry_date  TEXT NULL,
    status       TEXT NULL,
    notes        TEXT NULL,
    link_method  TEXT NULL,
    data_json    TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_assets_client ON assets(tenant_id, client_id);
CREATE INDEX idx_assets_expiry ON assets(tenant_id, expiry_date);

-- ------------------------------------------------------------------ access (vault)
-- secret_ciphertext = 'v1:' || base64(iv12 || tag16 || ciphertext), AES-256-GCM,
-- key from env HUBOS_VAULT_KEY. Plaintext secrets are NEVER stored.
CREATE TABLE vault_items (
    id                 INTEGER PRIMARY KEY,
    tenant_id          INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id          TEXT NULL,
    client_id          INTEGER NULL,
    title              TEXT NULL,
    service            TEXT NULL,
    url                TEXT NULL,
    username           TEXT NULL,
    has_secret         INTEGER NOT NULL DEFAULT 0 CHECK (has_secret IN (0, 1)),
    secret_ciphertext  TEXT NULL,
    encryption         TEXT NOT NULL DEFAULT 'none' CHECK (encryption IN ('none', 'aes-256-gcm')),
    status             TEXT NULL,
    notes              TEXT NULL,
    data_json          TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at         TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at         TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    CHECK (encryption = 'aes-256-gcm' OR secret_ciphertext IS NULL),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX idx_vault_items_client ON vault_items(tenant_id, client_id);

-- ------------------------------------------------------------------ interactions
CREATE TABLE interactions (
    id           INTEGER PRIMARY KEY,
    tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    legacy_id    TEXT NULL,
    client_id    INTEGER NULL,
    task_id      INTEGER NULL,
    kind         TEXT NOT NULL DEFAULT 'note' CHECK (kind IN ('note', 'call', 'meeting', 'whatsapp', 'email', 'task_note', 'task_activity')),
    occurred_at  TEXT NULL,
    title        TEXT NULL,
    summary      TEXT NULL,
    data_json    TEXT NULL CHECK (data_json IS NULL OR json_valid(data_json)),
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, legacy_id),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, task_id)   REFERENCES tasks(tenant_id, id)   ON DELETE RESTRICT
);
CREATE INDEX idx_interactions_client ON interactions(tenant_id, client_id, occurred_at);
