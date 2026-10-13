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
- Vault encryption (libsodium) is Phase 5.
