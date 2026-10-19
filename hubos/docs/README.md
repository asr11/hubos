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
