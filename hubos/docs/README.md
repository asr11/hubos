# OS HUB v2 — Phase 0 (skeleton)

Pure PHP 8 (no framework, no Composer), SQLite via PDO, server-rendered Hebrew RTL UI.

## Layout

```
hubos/
├── public/            web root: index.php + assets only
├── app/
│   ├── bootstrap.php  autoloader (HubOS\ -> app/), config
│   ├── config.php     env-driven config (no secrets)
│   ├── routes.php     route table (auth/perm per route)
│   ├── Core/          App, Router, DB, Migrator, Auth, Tenant, Csrf, View,
│   │                  Events, Permissions, Provisioning, Context, Response, Str
│   └── Modules/       Auth, Dashboard, Clients
├── views/             plain-PHP templates (always escape with e())
├── database/migrations/001_core.sql
├── bin/               migrate.php, create_user.php
├── storage/           sqlite db, sessions, logs (gitignored, outside web root)
├── tests/             run.php (unit), e2e.sh (curl)
└── docs/
```

## Commands

```bash
php bin/migrate.php                                  # apply migrations
php bin/create_user.php --email=you@x.co --name="שם"  # prompts for password (STDIN)
php -S 127.0.0.1:8099 -t public                      # local dev server
php tests/run.php                                    # unit tests
bash tests/e2e.sh                                    # curl E2E (throwaway DB, auto cleanup)
```

Environment: `HUBOS_DB_PATH`, `HUBOS_SESSION_PATH`, `HUBOS_LOG_PATH`,
`HUBOS_COOKIE_SECURE` (`auto`|`1`|`0`), `HUBOS_SESSION_IDLE` (seconds), `HUBOS_ENV`.

## Core rules

- **Tenant scoping**: business data is read/written only via `Core\Tenant`
  (`find/all/count/insert/update/updateWhere/deleteWhere/select`). It forces
  `tenant_id`, whitelists tables, validates identifiers; raw `select()` must
  contain `:tenant_id`. Only `Auth` (session bootstrap) and `Provisioning`
  touch `users`/`tenants` directly.
- **Events**: `events` is append-only (DB triggers block UPDATE/DELETE). Every
  create, login/logout and permission/module change writes an event.
  Secret-like keys are stripped before logging.
- **Permissions**: tenant module switch → per-user override (`user_permissions`)
  → role default (`owner, admin, staff, agent, client_viewer`).
  `client_viewer` is read-only and sees only its linked client.
- **Security**: CSRF token on every non-GET request (checked before DB access),
  prepared statements only, Argon2id (`PASSWORD_DEFAULT` fallback), session id
  regenerated on login, HttpOnly + SameSite=Lax (+ Secure / `__Host-` on HTTPS),
  idle timeout, login throttling (5/email, 20/IP per 15 min), CSP and other
  security headers, internal-only redirects.
- **No hard deps** on `sodium` / `mbstring` (UTF-8 via PCRE fallback).

## Production notes (not done in Phase 0)

- Nginx `root` must point to `hubos/public`; deny everything else.
- Set `HUBOS_COOKIE_SECURE=1` behind TLS.

## M1 — business schema + D1 import

- `database/migrations/002_business.sql`: the 7 roots — `clients` (+ legacy_id,
  tax_id, address, city, domain, notes, data_json, parent_client_id),
  `client_contacts`, `suppliers`, `projects`, `tasks`, `hours_banks`,
  `time_entries`, `active_timers`, `finance_documents`, `expenses`, `leads`,
  `quotes`, `assets`, `vault_items`, `interactions`. All tenant-scoped and
  registered in `Tenant::TABLES`; references use composite FKs
  `(tenant_id, x_id)` so a row cannot point at another tenant's data.
  `legacy_id` is UNIQUE per tenant → `Tenant::upsert()` makes imports idempotent.
- Importer: `php bin/import_d1.php <dump.sql> [--db=…] [--report=…]` → tenant
  `hub` ("האב מערכות מתקדמות"). Loads the dump into a temp staging SQLite
  (foreign keys OFF), maps every entity from its SSOT, writes
  `storage/import_report.md` (source vs imported, merges, skips with reasons,
  link coverage, doc-type mapping, income per year, full source inventory).
- Accounting rule (`Modules/Import/AccountingRule`): 300/305/320 = income;
  400 receipt = income only if no invoice-type doc of the same client with the
  same amount is available to pair with it (1:1, nearest date). Code→kind
  mapping is taken from the data (`documentKind`); unknown codes are flagged.
- Vault: secrets are encrypted with AES-256-GCM (openssl) using
  `HUBOS_VAULT_KEY` (32 bytes, base64 or 64 hex). Without a key only metadata
  is imported — plaintext is never stored.
- i18n: UI/report strings live in `lang/he.php` (`Core\Lang::t()`).

## M2 — Client 360 card

- `GET /clients/{id}?tab=…` — one click from `/clients` opens the full card.
  Tabs are server-rendered links (no JS): overview, contacts, interactions,
  work, time, finance, collection, sales, assets, access, timeline. Each tab
  shows a count.
- `Modules/Clients/Client360Service::build()` returns everything for a client,
  rolled up over the family (ancestors via `parent_client_id` + all
  descendants). Every row carries `origin_client_id` / `origin_name`.
  Income = `counts_as_income` only. `data_json` is stripped from all rows.
  Vault: `VAULT_COLUMNS` only (never `secret_ciphertext`/`notes`/`data_json`);
  opening the access tab writes `vault.metadata_viewed`.
- Debt balance is shown as **not reliably computable** (no full
  invoice↔receipt link in the data); the collection tab shows invoices vs
  receipts per year and documents whose Morning status is not "paid".
- `client_viewer`: own linked client only — no roll-up, no vault, no audit events.
- Search (`/clients?q=`): `Core\HebrewText` — normalizes niqqud, final letters,
  quotes/geresh, punctuation; strips ONE leading prefix letter (ו/ה/ב/כ/ל/מ/ש)
  from query and haystack words as an alternative match; ranked.
- Registry enrichment: `POST /clients/{id}/enrich` fetches data.gov.il
  (רשם החברות) suggestions into the session; `POST /clients/{id}/enrich/apply`
  stores only the checked fields + `data_json.registry` and logs
  `client.registry_applied`. Network failures show a friendly error.
  `HUBOS_REGISTRY_URL` overrides the endpoint (tests/E2E use a dead port; unit
  tests mock `Core\Http\HttpClient`).
- `bash tests/e2e_m2.sh [db]` — curl E2E on a COPY of `storage/hubos.sqlite`
  (sha256 of the source verified unchanged; ZZ_ users created in the copy only).

## M3 — live timer, time entries, hours banks

- `database/migrations/003_time_work.sql` (additive): `active_timers` pause fields,
  `hours_banks.period/rollover/rollover_cap_hours/starts_on`, task priority /
  client_visible / assignee_user_id / archived_at / recurring keys, project
  description / client_visible / archived_at, `recurring_task_templates`, and the
  `time` + `work` module switches for every tenant.
- `Modules/Time/TimerService`: one timer per user (service check + UNIQUE index),
  server is the source of truth (`elapsed = accumulated + now - started_at`), so it
  survives refresh/navigation. start / pause / resume / stop / discard, all logged.
  **Stop requires a description** and creates a `time_entries` row (source `timer`).
- `TimeEntryService`: manual create/edit/delete (own entries with `time.track`,
  any with `time.manage`); every write → event with before/after. Entries are
  deducted from the client's oldest **active** bank (`hours_bank_id`).
- `HoursBankService`: levels ok / warn80 / warn90 / over; a crossing writes
  `hours_bank.threshold_crossed`. Monthly banks with rollover: unused hours carry
  to the next month (optional cap, overuse never carries negative) — `rollover()`.
- UI: global header widget (`public/assets/timer.js`, vanilla, re-syncs with
  `GET /timer` every 30s), `/time` (filters user/client/date + totals, manual entry,
  bank settings), Client 360 header (timer + bank used/allowance + badge) and time tab.

## M4 — tasks & projects

- `/tasks`, `/projects` list/create/edit/archive. **client_id is required** on every
  create/edit (and must agree with the project). Statuses todo/doing/done
  (imported `in_progress` shown as doing), priority, due date, assignee, internal vs
  client-visible flag. Shown in the Client 360 work tab (archived hidden).
- `/work/linking` (admin, `work.link`): imported rows without client. Suggestions by
  domain / domain label / client name in the title (`LinkingService::suggest`) are
  **shown only**; a click links one row and logs `task.linked` / `project.linked`
  (`used_suggestion`, `suggested_client_ids`).
- `/work/recurring` + `php bin/recurring.php [--month=YYYY-MM] [--tenant=slug]`:
  one task per template per month (UNIQUE index) → safe to re-run / cron.
- client_viewer: own client's client-visible tasks/projects and time **totals**
  only; no timer, no writes.
- `bash tests/e2e_m3.sh [db]` — curl E2E on a migrated COPY (sha256 of the source
  verified). `SHOTS=/abs/dir` adds headless-Chrome screenshots.

## M5 & M6 — Finance, CRM, Assets, Vault, Morning Cockpit & Client Portal

- `database/migrations/004_m5_m6.sql`: indexes and module permissions for Finance,
  CRM (`leads`, `quotes`, `interactions`), `assets`, `vault_items`, and Client Portal.
- Morning Cockpit (`/dashboard`): unified morning briefing — open tasks, active timers,
  hours-bank warnings, unpaid finance documents, expiring assets, and recent events.
- Finance (`/finance`), CRM (`/crm`), Assets (`/assets`), Vault (`/vault` — metadata
  only unless `HUBOS_VAULT_KEY` is configured and explicit reveal is authorized),
  and Settings (`/settings`).
- `bash tests/e2e_m5_m6.sh [db]` — curl E2E covering M5 & M6 modules and RBAC.

## M7 — GitOps, CloudPanel Deploy, Digital-CEO Agent API & Data Provenance

- `database/migrations/005_data_provenance.sql`: `sync_runs` table (`source_system`,
  `source_identifier`, `source_sha256`, `started_at`, `finished_at`, `status`,
  `clients_synced`, `docs_synced`) + provenance columns on `clients` and
  `finance_documents`.
- Data Provenance UI & Sync (`Modules/Import/SyncService`):
  - Provenance banner on `/dashboard` (`#provenance-banner`) and `/clients/{id}`
    (`#client-provenance-banner`) showing verified source (`Cloudflare D1 (hub-os-d1)`),
    exact `Asia/Jerusalem` timestamp (`YYYY-MM-DD HH:MM:SS`), relative age, 12-char
    `source_sha256`, and a `POST /settings/sync-d1` ("סנכרון ואימות עכשיו") button.
- Digital-CEO Agent API (`Modules/AgentApi`):
  - Bearer token auth (`Authorization: Bearer <token>`) matched via `hash_equals`
    against `HUBOS_AGENT_TOKEN` or active `agent`/`owner` user.
  - `GET /api/agent/status` — system health, schema version, counts, and `data_provenance`.
  - `GET /api/agent/morning` — full JSON morning briefing + `data_provenance`.
  - `GET /api/agent/clients?q=...` — Hebrew root-aware client search.
  - `GET /api/agent/clients/{id}` — Client 360 JSON (zero `secret_ciphertext` / `data_json` leakage) + `client_provenance` & `system_provenance`.
  - `POST /api/agent/event` — append-only `agent.*` event logging.
- CloudPanel + Nginx (`deploy/nginx-cloudpanel.conf`, `deploy/install_cloudpanel.sh`):
  `root` pointed strictly to `public/`, internal directories denied (`404`), idempotent
  installer with pre-migration SQLite `.backup` and nightly cron helper.
- CI/CD & Codespaces (`.github/workflows/ci-deploy.yml`, `.devcontainer/devcontainer.json`):
  GitHub Actions runs `php -l`, unit tests, and all E2E suites (`e2e.sh`, `e2e_m2.sh`,
  `e2e_m3.sh`, `e2e_m5_m6.sh`, `e2e_m7.sh`), with conditional SSH deploy when
  `VPS_SSH_KEY` is configured.

