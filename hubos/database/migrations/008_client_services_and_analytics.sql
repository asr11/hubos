-- M11: Universal Modular Client Services Engine & Admin-Only Google Search Console / GA4 Connections

CREATE TABLE IF NOT EXISTS client_services (
    id              INTEGER PRIMARY KEY,
    tenant_id       INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    client_id       INTEGER NOT NULL,
    service_type    TEXT NOT NULL,
    title           TEXT NOT NULL,
    scope_summary   TEXT NULL,
    billing_cycle   TEXT NOT NULL DEFAULT 'monthly' CHECK (billing_cycle IN ('monthly', 'yearly', 'one_time', 'per_lead', 'hours')),
    status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'completed', 'paused')),
    widgets_json    TEXT NULL CHECK (widgets_json IS NULL OR json_valid(widgets_json)),
    metrics_json    TEXT NULL CHECK (metrics_json IS NULL OR json_valid(metrics_json)),
    sort_order      INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_client_services_client ON client_services(tenant_id, client_id, status, sort_order);

CREATE TABLE IF NOT EXISTS client_analytics_connections (
    id                  INTEGER PRIMARY KEY,
    tenant_id           INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    client_id           INTEGER NOT NULL,
    gsc_site_url        TEXT NULL,
    ga4_property_id     TEXT NULL,
    ga4_measurement_id  TEXT NULL,
    auth_mode           TEXT NOT NULL DEFAULT 'service_account' CHECK (auth_mode IN ('service_account', 'oauth')),
    status              TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'connected', 'error')),
    last_synced_at      TEXT NULL,
    last_error          TEXT NULL,
    snapshot_json       TEXT NULL CHECK (snapshot_json IS NULL OR json_valid(snapshot_json)),
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, client_id),
    FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_client_analytics_client ON client_analytics_connections(tenant_id, client_id);
