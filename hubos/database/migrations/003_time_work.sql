-- OS HUB v2 — M3 (timer + hours bank) and M4 (tasks & projects)
-- Additive only: existing rows keep their values (new columns have safe defaults).

-- ------------------------------------------------------------------ M3: timer
-- Elapsed = accumulated_seconds + (paused_at IS NULL ? now - started_at : 0).
-- first_started_at = when the timer was first started (kept across pause/resume).
ALTER TABLE active_timers ADD COLUMN paused_at           TEXT NULL;
ALTER TABLE active_timers ADD COLUMN accumulated_seconds INTEGER NOT NULL DEFAULT 0 CHECK (accumulated_seconds >= 0);
ALTER TABLE active_timers ADD COLUMN first_started_at    TEXT NULL;

-- ------------------------------------------------------------------ M3: hours banks
-- period: one_time = total_hours for the bank's whole life; monthly = total_hours per calendar month.
-- rollover (monthly only): unused hours of a month carry into the next month (optionally capped).
ALTER TABLE hours_banks ADD COLUMN period             TEXT NOT NULL DEFAULT 'one_time' CHECK (period IN ('one_time', 'monthly'));
ALTER TABLE hours_banks ADD COLUMN rollover           INTEGER NOT NULL DEFAULT 0 CHECK (rollover IN (0, 1));
ALTER TABLE hours_banks ADD COLUMN rollover_cap_hours REAL NULL CHECK (rollover_cap_hours IS NULL OR rollover_cap_hours >= 0);
ALTER TABLE hours_banks ADD COLUMN starts_on          TEXT NULL;
CREATE INDEX idx_hours_banks_client ON hours_banks(tenant_id, client_id, status);
CREATE INDEX idx_time_entries_bank  ON time_entries(tenant_id, hours_bank_id, entry_date);
CREATE INDEX idx_time_entries_user  ON time_entries(tenant_id, user_id, entry_date);

-- ------------------------------------------------------------------ M4: tasks & projects
-- client_visible: 0 = internal (default), 1 = visible to the client's viewer users.
ALTER TABLE tasks ADD COLUMN priority              TEXT NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'urgent'));
ALTER TABLE tasks ADD COLUMN client_visible        INTEGER NOT NULL DEFAULT 0 CHECK (client_visible IN (0, 1));
ALTER TABLE tasks ADD COLUMN assignee_user_id      INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT;
ALTER TABLE tasks ADD COLUMN archived_at           TEXT NULL;
ALTER TABLE tasks ADD COLUMN recurring_template_id INTEGER NULL;
ALTER TABLE tasks ADD COLUMN recurring_period      TEXT NULL;
-- One generated task per template per month (idempotent bin/recurring.php).
CREATE UNIQUE INDEX ux_tasks_recurring ON tasks(tenant_id, recurring_template_id, recurring_period)
    WHERE recurring_template_id IS NOT NULL;

ALTER TABLE projects ADD COLUMN description    TEXT NULL;
ALTER TABLE projects ADD COLUMN client_visible INTEGER NOT NULL DEFAULT 0 CHECK (client_visible IN (0, 1));
ALTER TABLE projects ADD COLUMN archived_at    TEXT NULL;

-- Monthly recurring maintenance task templates (one per client/topic).
CREATE TABLE recurring_task_templates (
    id                INTEGER PRIMARY KEY,
    tenant_id         INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    client_id         INTEGER NOT NULL,
    project_id        INTEGER NULL,
    title             TEXT NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
    description       TEXT NULL,
    priority          TEXT NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    client_visible    INTEGER NOT NULL DEFAULT 0 CHECK (client_visible IN (0, 1)),
    assignee_user_id  INTEGER NULL,
    day_of_month      INTEGER NOT NULL DEFAULT 1 CHECK (day_of_month BETWEEN 1 AND 28),
    active            INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, client_id)        REFERENCES clients(tenant_id, id)  ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, project_id)       REFERENCES projects(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, assignee_user_id) REFERENCES users(tenant_id, id)    ON DELETE RESTRICT
);
CREATE INDEX idx_recurring_client ON recurring_task_templates(tenant_id, client_id);

-- ------------------------------------------------------------------ module switches
INSERT OR IGNORE INTO tenant_modules (tenant_id, module, enabled) SELECT id, 'time', 1 FROM tenants;
INSERT OR IGNORE INTO tenant_modules (tenant_id, module, enabled) SELECT id, 'work', 1 FROM tenants;
