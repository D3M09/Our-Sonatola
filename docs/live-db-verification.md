# Live MySQL/MariaDB verification

Backend code is complete; live database verification is still pending (no DB was
available during remediation). Do not begin frontend work until this checklist
passes against an empty test database.

Reference docs: `docs/remediation.md` (what was fixed), `database/schema.md`
(schema + DDL policy), `docs/api-v1.md` (endpoint behavior), `.env.example`
(all variable names below come from that file — nothing invented).

## 1. Prerequisites (exact)

- Empty database on MySQL 8.0 or MariaDB 10.6+ (older MariaDB 10.1 and below
  are NOT supported — no `JSON` type). Confirm version first:
  `SELECT VERSION();`
- A DB user with CREATE/ALTER/INSERT/SELECT/UPDATE on that empty DB only.
- Project files as-is. Never commit `.env` (it is line 1 of `.gitignore` and no
  `.env` file exists in the repo — verified).
- Keep `APP_DEBUG=false` and `PASSWORD_RESET_ENABLED=false` until SMTP exists.
- Take/hold a backup before migrating anything non-empty (see
  `docs/backup-restore.md`). All commands below are safe on an **empty** DB;
  never run `--allow-destructive` or manual `DROP` on a non-empty DB.

## 2. Environment setup (safe, no secrets committed)

```bash
cp .env.example .env   # then edit .env locally, never commit it
```

Set only these names (from `.env.example`):
`DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_CHARSET=utf8mb4`,
`APP_ENV, APP_DEBUG=false`, `SESSION_NAME, COOKIE_SECURE, COOKIE_SAMESITE`,
`SESSION_IDLE_MINUTES=120, SESSION_ABSOLUTE_HOURS=12`,
`PASSWORD_RESET_ENABLED=false`, `CORS_ALLOWED_ORIGINS=` (empty),
`UPLOAD_MAX_MB, LOGIN_MAX_ATTEMPTS, LOGIN_LOCKOUT_MINUTES`.

## 3. Migration check (exact CLI args from `database/migrate.php`)

Supported flags (verified in code): `--dry-run`, `--seeds`, `--allow-destructive`.
Do NOT use `--allow-destructive` here — shipped migrations contain no `DROP`.

```bash
php database/migrate.php --dry-run
# expect: "[DRY-RUN] 11 pending migration(s) (no writes performed)" with per-file
# counts 001(1) 002(2) 003(3) 004(2) 005(3) 006(1) 007(3) 008(2) 009(3) 010(3)
# 011(2), plus a bookkeeping-status note, exit 0.
# Dry-run is genuinely read-only (information_schema SELECTs only): it creates
# neither the database nor schema_migrations. Confirm afterwards:
#   SHOW TABLES FROM `sonatola_test`;  → still 0 tables.

php database/migrate.php
# expect per file: OK (n statement(s))
# 001(1) 002(2) 003(3) 004(2) 005(3) 006(1) 007(3) 008(2) 009(3) 010(3) 011(2)

mysql -h $DB_HOST -u $DB_USER -p $DB_NAME -e "SELECT version FROM schema_migrations ORDER BY version;"
# expect: 001_schema_migrations … 011_roles_scope (11 rows)

mysql -h $DB_HOST -u $DB_USER -p $DB_NAME -e "SELECT slug,scope FROM roles ORDER BY id;"
# expect: super_admin/global, upazila_admin/global, union_admin/union,
#         editor/global, service_officer/union, citizen/global
```

DDL policy (`database/schema.md`): MySQL/MariaDB DDL implicitly commits — the
runner does NOT wrap DDL in a transaction and makes no rollback claim. On any
`FAILED <version>` stop, restore the pre-migration backup; do not retry blindly.

## 4. Seed idempotency (exact)

```bash
php database/migrate.php --seeds
# expect: 001_roles_permissions.sql, 002_settings.sql, 003_sonatola_base.sql,
#         004_auth_settings.sql each OK
php database/migrate.php --seeds
# expect: same OK again (INSERT IGNORE / ON DUPLICATE — safe to re-run)

mysql -h $DB_HOST -u $DB_USER -p $DB_NAME -e "SELECT setting_value FROM settings WHERE setting_key='auth.password_reset_enabled';"
# expect: 0 (reset stays disabled)
mysql -h $DB_HOST -u $DB_USER -p $DB_NAME -e "SELECT setting_value FROM settings WHERE setting_key='applications.online_enabled';"
# expect: 0 (online applications stay disabled)
mysql -h $DB_HOST -u $DB_USER -p $DB_NAME -e "SELECT COUNT(*) FROM users;"
# expect: 0 (no seeded users, no default admin — verified by tests)
```

## 5. First admin (exact CLI args from `scripts/create_admin.php`)

Flags (verified): `--name, --email, --password, --phone (optional), --force`.

```bash
php scripts/create_admin.php --name="Test Admin" --email="admin@example.com" --password="0123456789abCD..(12+ chars)"
# expect: "Super admin created: id=…"
php scripts/create_admin.php --name="Second" --email="second@example.com" --password="0123456789abCD..(12+ chars)"
# expect refusal: super_admin already exists (re-run with --force to override)
```

Password rule enforced in code: minimum 12 characters. No admin is created
automatically; seeding never inserts users.

## 6. HTTP integration checklist (needs DB + `php -S`)

```bash
php -S localhost:8080 -t public
```

- `GET /api/v1/health` → 200 `{success:true}`.
- `GET /api/v1/news`, `/notices`, `/unions`, `/services` → 200, only
  `published` (notices exclude expired).
- Register citizen → `POST /api/v1/register` → 201; login →
  `POST /api/v1/login` → 200 `{user, token, csrf}`.
- `POST /api/v1/complaints` as session cookie WITHOUT `X-CSRF-Token` → 403;
  with token → 201; with `Authorization: Bearer` (no session) → 201.
- `GET /api/v1/complaints/{id}` as other citizen → 403; as union admin of
  another union → 403; as owner/same-union staff with `complaint.view` → 200.
- `GET /api/v1/complaints?status=bogus` → 422; `priority=hacked` on submit → 422.
- Admin news/notices lists as union staff show own union + NULL-union only.
- `PUT /api/v1/admin/users/{id}/status` disabling last active super_admin → 403.
- `POST /api/v1/password/request` → generic 200 with
  `SELECT COUNT(*) FROM auth_tokens WHERE type='password_reset'` staying 0
  while disabled (per `docs/api-v1.md`).

## 7. Test separation (exact results, non-destructive run)

Already passed WITHOUT a database (verified just now):
- `php -l` on every PHP file: all OK.
- `php tests/run.php`: **113 passed, 0 failed, 3 skipped**.
- `php database/migrate.php --dry-run` without DB: graceful
  `DB connect failed: SQLSTATE[HY000] [2002]` — no writes attempted.
- `.gitignore` line 1 ignores `.env`; no `.env` file present.

Requires a real database (NOT yet run — do not claim otherwise):
- `tests/test_db_live.php` (3 skips): connectivity, prepared-statement SQLi
  check, `schema_migrations` readability.
- Migration apply + seed rerun + 011 ALTER on MySQL 8.0/MariaDB 10.6.
- Concurrent reset-token consumption race, HTTP-level isolation/403 matrix,
  expired-notice hiding against real `DATETIME`, tracking-collision retry under
  real UNIQUE contention, file-permission behavior on the host.

## 8. Safety rules

- Never paste real credentials into chat/logs; keep them in local `.env` only.
- Never commit `.env`; never echo raw reset tokens (only SHA-256 in DB).
- Never run `--allow-destructive`, manual `DROP/DELETE/TRUNCATE`, restores, or
  migrates against a non-empty/shared database for this checklist — use the
  empty test DB above.
