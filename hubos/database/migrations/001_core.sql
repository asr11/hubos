-- OS HUB v2 — Phase 0 core schema
-- Every business table carries tenant_id. All timestamps are UTC ISO-8601.

CREATE TABLE tenants (
    id          INTEGER PRIMARY KEY,
    slug        TEXT NOT NULL UNIQUE,
    name        TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE TABLE clients (
    id                INTEGER PRIMARY KEY,
    tenant_id         INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    parent_client_id  INTEGER NULL REFERENCES clients(id) ON DELETE RESTRICT,
    name              TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
    email             TEXT NULL,
    phone             TEXT NULL,
    status            TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive', 'archived')),
    created_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX idx_clients_tenant_name ON clients(tenant_id, name);

CREATE TABLE users (
    id             INTEGER PRIMARY KEY,
    tenant_id      INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    email          TEXT NOT NULL COLLATE NOCASE UNIQUE,
    name           TEXT NOT NULL,
    password_hash  TEXT NOT NULL,
    role           TEXT NOT NULL CHECK (role IN ('owner', 'admin', 'staff', 'agent', 'client_viewer')),
    client_id      INTEGER NULL REFERENCES clients(id) ON DELETE RESTRICT,
    status         TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    last_login_at  TEXT NULL,
    created_at     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX idx_users_tenant ON users(tenant_id);

-- Append-only audit / history log. Never updated, never deleted.
CREATE TABLE events (
    id             INTEGER PRIMARY KEY,
    tenant_id      INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    actor_user_id  INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT,
    entity_type    TEXT NOT NULL,
    entity_id      INTEGER NULL,
    client_id      INTEGER NULL REFERENCES clients(id) ON DELETE RESTRICT,
    action         TEXT NOT NULL,
    before_json    TEXT NULL CHECK (before_json IS NULL OR json_valid(before_json)),
    after_json     TEXT NULL CHECK (after_json IS NULL OR json_valid(after_json)),
    created_at     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX idx_events_tenant_id     ON events(tenant_id, id);
CREATE INDEX idx_events_tenant_client ON events(tenant_id, client_id, id);
CREATE INDEX idx_events_tenant_entity ON events(tenant_id, entity_type, entity_id);

CREATE TRIGGER events_no_update BEFORE UPDATE ON events
BEGIN
    SELECT RAISE(ABORT, 'events is append-only');
END;

CREATE TRIGGER events_no_delete BEFORE DELETE ON events
BEGIN
    SELECT RAISE(ABORT, 'events is append-only');
END;

-- Which modules are active per tenant (customisation level 1).
CREATE TABLE tenant_modules (
    tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    module      TEXT NOT NULL,
    enabled     INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (tenant_id, module)
) WITHOUT ROWID;

CREATE TABLE tenant_settings (
    tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    key         TEXT NOT NULL,
    value       TEXT NULL,
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (tenant_id, key)
) WITHOUT ROWID;

-- Per-user overrides on top of the role (granted=1 adds, granted=0 revokes).
CREATE TABLE user_permissions (
    tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    permission  TEXT NOT NULL,
    granted     INTEGER NOT NULL CHECK (granted IN (0, 1)),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (user_id, permission)
) WITHOUT ROWID;
CREATE INDEX idx_user_permissions_tenant ON user_permissions(tenant_id, user_id);

-- Login throttling (global, pre-authentication).
CREATE TABLE login_attempts (
    id          INTEGER PRIMARY KEY,
    email       TEXT NOT NULL COLLATE NOCASE,
    ip          TEXT NOT NULL,
    success     INTEGER NOT NULL CHECK (success IN (0, 1)),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX idx_login_attempts_email ON login_attempts(email, created_at);
CREATE INDEX idx_login_attempts_ip    ON login_attempts(ip, created_at);
