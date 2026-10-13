# Sonatola Upazila Digital Platform — Backend

Digital public-service platform for Sonatola Upazila, Bogura. Plain PHP 8.1+ + MySQL/MariaDB + PDO. No framework. REST JSON at `/api/v1`. Capacitor-ready. Public content needs no login.

> Unofficial demo — verify information with the Upazila administration. No official affiliation claimed. No government DB integration.

## Architecture

```
public/index.php      # front controller (docroot must point here)
src/
  bootstrap.php       # autoload, .env, secure session, error handler, CORS
  routes.php          # /api/v1 table (no business logic here)
  Core/               # Router, Request, Response, DB, Validator, Auth, Audit, Logger, Csrf, RateLimit, Esc, AppException
  Middleware/         # AuthMiddleware (session+Bearer), RbacMiddleware (roles + union scoping)
  Controllers/        # thin HTTP layer
  Services/           # AuthService, ComplaintService, UploadService
  Repositories/       # PDO prepared statements only
config/config.php     # .env loader (no secrets in repo)
database/
  migrations/001..010 # versioned SQL (InnoDB, utf8mb4, FKs)
  seeds/              # roles/permissions, settings (online_enabled=0), Sonatola base
  migrate.php         # safe runner with --dry-run + destructive guard
  schema.md / import-official-data.md
storage/              # logs + uploads OUTSIDE docroot, private by default
docs/api-v1.md, deployment.md, backup-restore.md
tests/run.php         # plain-PHP tests, no deps
scripts/create_admin.php # CLI first-admin bootstrap (no seeded admin)
```

Request flow: `index.php → bootstrap → DB::pdo → Request::fromGlobals → AuthMiddleware::attach → Router::match → Controller → Service → Repository → Response::json`. Errors → `AppException` with safe messages; 500s logged to `storage/logs/app.log` without PII/secrets.

Auth: `password_hash` + session (browser, `Secure;HttpOnly;SameSite`, regenerate on login, CSRF on writes) + Bearer SHA-256-hashed tokens in `auth_tokens` (mobile/Capacitor, 30d). Throttling on login/register/reset. `account_status` checked every login + token use. RBAC: 6 roles, 18 permissions; `union_admin`/`service_officer` forced to own `union_id` in SQL + `assertUnionAccess` after fetch — changing IDs never escapes scope.

## Requirements

- PHP 8.1+ (coded to 8.1 subset; tested on 8.5 CLI) with `pdo_mysql, mbstring, openssl, fileinfo, curl, intl`.
- MySQL 5.7+/MariaDB 10.3+. Apache with `mod_rewrite` preferred (`.htaccess` included; `?route=` fallback if ignored).
- Composer optional (no runtime deps).

## Quick start (local)

```bash
cp .env.example .env   # fill DB_* (placeholders only in repo)
php database/migrate.php --dry-run
php database/migrate.php
php database/migrate.php --seeds   # fresh install only
php scripts/create_admin.php --name="Admin" --email="admin@example.com" --password="0123456789abCD..(12+ chars)"
php -S localhost:8080 -t public
curl localhost:8080/api/v1/health
```

Without DB (current state): syntax + unit tests still run; DB tests report `SKIPPED-no-db` (never fabricated).

## Tests

```bash
php tests/run.php        # all: validator, router/response, auth crypto, RBAC scope, static security, db-live (skipped w/o DB)
for f in public/index.php src/*.php src/*/*.php config/config.php database/migrate.php scripts/*.php tests/*.php; do php -l "$f"; done
```

Covers: connectivity/migrations (live, skipped w/o DB), session/token hashing, invalid input, SQLi (static + live prepared check), privilege escalation, cross-union isolation, complaint submit/history logic, public visibility (published-only queries), private uploads (MIME+magic bytes, non-public default), response envelopes.

## Progress checklist

- [x] Phase 1: inspect (empty workspace, PHP 8.5/Composer/MariaDB-client found), plan, structure
- [x] Phase 2: 10 migrations + 3 seeds + `migrate.php` + `schema.md` + official-import doc; Sonatola only, no invented unions
- [x] Phase 3: front controller, router, request/response, config, DB, controllers/services/repos, validation, logging, `/api/v1`
- [x] Phase 4: hashing, session+CSRF, Bearer tokens, RBAC 6 roles, union/upazila scoping, throttling, status checks, CLI bootstrap
- [x] Phase 5: public news/notices/unions/services, register/login/me/reset, complaints (submit/mine/scoped/assign/status), admin news/notices/users/roles/audit
- [x] Phase 6: prepared statements, escaping, CSRF, authz on every protected route, private storage + MIME/signature checks, size/rate limits, secure cookies, restricted CORS, no secrets in repo, hashed tokens, prod errors hidden, no PII in logs
- [x] Phase 7: automated tests written; run locally (see above)
- [x] Phase 8: `.env.example`, `.gitignore`, `docs/api-v1.md`, `deployment.md`, `backup-restore.md`, checklists

## Limitations / remaining

- No live DB run yet (no credentials) — `test_db_live` + migration apply untested; run `migrate.php` when DB available.
- Applications table + `applications.online_enabled=0` ready but no public endpoints (by design, disabled by default).
- Notifications/device-token tables ready; push delivery not implemented (needs FCM config decision).
- No admin HTML UI yet — API-first per scope; minimal public homepage only.
- File serving endpoint (`GET /files/{id}` with authz) pending; uploads stored privately, metadata in `media_files`.
- Multi-node rate limiting uses tmp files; replace with DB table for clustered prod.
- Target hosting unknown — assumed PHP 8.1 + Apache + `public/` docroot; confirm before launch.
