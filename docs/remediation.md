# Remediation report — independent audit findings

Source audit: `Prompt/First-Step/audit.MD` (read-only). This file matches actual
behavior after remediation. No MySQL/MariaDB was available during remediation;
DB-dependent behavior is marked **unverified-live** where applicable.

## Phase A — High findings

### A1. Reliable migration execution — FIXED (code) / UNVERIFIED-LIVE (DB run)
- New `src/Core/SqlSplitter.php`: quote/comment-aware splitter (single/double/
  backtick quotes, `--`/`#` line comments, `/* */` blocks, `''` escapes). No naive
  `explode(';')`.
- `database/migrate.php`: executes each file statement-by-statement, preserves
  filename ordering + `schema_migrations` bookkeeping + rerun safety, keeps
  destructive `DROP` guard, `--dry-run` now reports statement counts.
- Removed false transactional DDL guarantee: no `beginTransaction` around DDL.
  Documented in file header + `database/schema.md`: MySQL/MariaDB DDL causes an
  implicit commit and is not rollbackable; on failure the runner stops and the
  operator restores from pre-migration backup.
- New `database/migrations/011_roles_scope.sql` (2 statements) covered by splitter.
- Tests: `tests/test_sql_splitter.php` (literals, comments, escapes, real-file
  counts, migrate doctrinal checks).
- Remaining risk: actual MySQL 5.7+/8.0 and MariaDB 10.3+ runs unverified.

### A2. CSRF on complaint submission — FIXED
- `src/Controllers/ComplaintController.php::submit()` now calls
  `Csrf::requireValid($req)` first. `Csrf` still exempts Bearer-token clients
  (no `$_SESSION['user_id']`) and enforces `X-CSRF-Token`/`_csrf` for
  session-cookie POSTs.
- Tests: `tests/test_csrf_submit.php` (source check + 403 without token + pass
  with token + Bearer exempt).

### A3. Atomic single-use reset tokens — FIXED (code) / UNVERIFIED-LIVE (concurrency)
- `src/Core/Auth.php::consumeToken()` rewritten as a single conditional
  `UPDATE ... WHERE token_hash,type,used_at IS NULL,expires_at > :now`, checked
  via `rowCount()`, then `SELECT`. Concurrent consumers cannot both succeed.
- Portability: PHP-generated `:now` (`gmdate`) replaces `UTC_TIMESTAMP()` in
  `consumeToken`, `userFromBearer`, `revokeToken`, plus new
  `revokeUserApiSessions()`. Works on MySQL and SQLite-backed tests.
- Tests: `tests/test_token_atomic.php` on SQLite (issue → first consumes →
  second null; wrong-type rejected; expired rejected; revoked sessions rejected).
- Remaining risk: true concurrent-race test needs live MySQL with parallel clients.

### A4. Password-reset delivery — FIXED (safely disabled by default)
- `src/Services/AuthService.php`: new `isPasswordResetEnabled()` requires BOTH
  env `PASSWORD_RESET_ENABLED=true` and DB `settings.auth.password_reset_enabled=1`.
  Default is disabled. When disabled, `requestPasswordReset()` audit-logs
  `user.password_reset_requested_disabled` and issues NO token (generic response
  preserved to avoid enumeration).
- Successful `resetPassword()` now calls `Auth::revokeUserApiSessions()`.
- Raw tokens are never logged/stored/echoed (tests assert source hygiene).
- New `database/seeds/004_auth_settings.sql` (`auth.password_reset_enabled=0`),
  `.env.example` documents `PASSWORD_RESET_ENABLED=false` + SMTP requirements,
  `docs/api-v1.md` documents enablement + delivery contract.
- Tests: `tests/test_reset_disabled.php` (defaults, no issuance while disabled,
  silent unknown email, source hygiene).
- Remaining: SMTP mailer integration is intentionally out of scope until
  credentials + provider decision exist. Do NOT enable without SMTP.

## Phase B — Medium findings

### B5. Permissions + union scoping — FIXED (code) / UNVERIFIED-LIVE (HTTP)
- `ComplaintController::show()`: staff branch now requires
  `complaint.view` before `assertUnionAccess()`.
- `UserRepository::list()` accepts `?int $unionId`; `AdminUserController::listUsers()`
  passes `allowedUnionId()` and 422-validates `status` filter.
- `ContentRepository::listNews/listNotices()` accept `?int $unionId` (own union +
  NULL-union upazila-wide); admin controllers pass scope; public news/notices
  unchanged except notices expiry (B8).
- Tests: `tests/test_session_permissions.php` (source + behavior gates).

### B6. Scope policy — FIXED (code) / UNVERIFIED-LIVE (DB migration run)
- New `src/Core/ScopePolicy.php` with explicit `UNION_SCOPED` fallback including
  future `volunteer_coordinator`, plus DB-driven `roles.scope` lookup when PDO
  is available.
- New `011_roles_scope.sql`: `scope ENUM('global','union')` + backfill
  `union_admin,service_officer`. Seed `001` now inserts `scope` explicitly.
- `AuthMiddleware::enrich()` stores `union_scoped` per request;
  `RbacMiddleware::allowedUnionId()` prefers it, falls back to `ScopePolicy`.
- Adding `volunteer_coordinator` = insert role with `scope='union'`; no
  middleware change. Tests: `tests/test_scope_policy.php`.

### B7. Session expiry + revoke — FIXED
- `Auth::sessionLogin()` sets `login_at` + `last_activity`;
  `Auth::sessionStillValid()` enforces `SESSION_IDLE_MINUTES` (default 120,
  sliding) + `SESSION_ABSOLUTE_HOURS` (default 12); `AuthMiddleware::attach()`
  destroys expired sessions. `.env.example` documents both.
- `UserRepository::touchLogin()` now uses PHP `:now` (SQLite-testable).
- Revoke on reset covered in A3/A4.
- Tests: `tests/test_session_permissions.php` (fresh/idle/absolute/anon).

### B8. Notices expiry + field validation — FIXED
- `ContentRepository::listNotices(..., $hideExpired, $now)`: public passes
  `true` (hides `expires_at` past), admin passes `false` (sees all).
- `ComplaintService::submit()` validates `priority in:low,normal,high,urgent`;
  `ComplaintController::listAll()` 422-validates `status` filter (no DB-error leak).
- Tests: `tests/test_notices_validation.php` (SQLite notices matrix + validator
  + source checks).

### B9. Uploads — DEFERRED (safe)
- Decision: no upload/download endpoints in v1. `UploadService` (allowlist,
  magic bytes, 5 MB, random names, 0640, private `storage/uploads` outside
  docroot, `is_public=0`) remains un-wired so private files cannot be exposed
  without a future authenticated + authorized file controller.
- `src/routes.php` contains no `/files|/uploads|/media` route (tested).
  `docs/api-v1.md` has an explicit “Uploads: deferred” notice.
- Tests: `tests/test_last_admin_tracking_uploads.php` (route + privacy asserts).

### B10. Last-admin + tracking collisions — FIXED (code) / UNVERIFIED-LIVE (DB race)
- `UserRepository::countOtherActiveSuperAdmins()` + `isSuperAdmin()`;
  `AdminUserController::setStatus()` blocks non-active transitions on the last
  active Super Admin (in addition to existing self-lockout guard).
- `ComplaintService::submit()` retries tracking-no generation up to 5× on
  `23000`/UNIQUE collisions via `isDuplicateKey()`; format `SN-YYYYMMDD-8HEX`.
- Tests: SQLite last-admin counts + 50-sample format/uniqueness + source checks
  in `tests/test_last_admin_tracking_uploads.php`.

## Files changed
- Added: `src/Core/SqlSplitter.php`, `src/Core/ScopePolicy.php`,
  `database/migrations/011_roles_scope.sql`, `database/seeds/004_auth_settings.sql`,
  `tests/test_sql_splitter.php`, `test_csrf_submit.php`, `test_token_atomic.php`,
  `test_reset_disabled.php`, `test_scope_policy.php`, `test_session_permissions.php`,
  `test_notices_validation.php`, `test_last_admin_tracking_uploads.php`,
  `docs/remediation.md` (this file).
- Edited: `database/migrate.php`, `database/schema.md`, `database/seeds/001_roles_permissions.sql`,
  `src/Core/Auth.php`, `src/Services/AuthService.php`, `src/Services/ComplaintService.php`,
  `src/Controllers/ComplaintController.php`, `src/Controllers/AdminUserController.php`,
  `src/Controllers/AdminContentController.php`, `src/Controllers/PublicController.php`,
  `src/Repositories/UserRepository.php`, `src/Repositories/ContentRepository.php`,
  `src/Middleware/AuthMiddleware.php`, `src/Middleware/RbacMiddleware.php`,
  `.env.example`, `docs/api-v1.md`, `tests/test_rbac_scope.php` (ScopePolicy require).

## Tests actually run
- `php -l` on all PHP files (public, config, src, database/migrate.php,
  scripts, tests): all OK.
- `php tests/run.php`: **113 passed, 0 failed, 3 skipped** (skips =
  `test_db_live.php`, no MySQL available). Covers splitter, CSRF, token
  single-use, reset-disabled, scope policy, session expiry, permission sources,
  notices matrix, validation, last-admin counts, tracking format, uploads-deferred.
- `php database/migrate.php --dry-run`: fails gracefully with DB-connect error
  (no DB configured) — no writes attempted. No migrations executed against a
  real database per instructions.

## Remaining risks
- No live MySQL/MariaDB verification: migration runs (esp. 011 ALTER +
  multi-statement files), seed reruns, FULLTEXT/JSON/DATETIME defaults,
  concurrent token consumption, and HTTP-level isolation still need a real DB.
- SMTP mailer for password reset is not implemented (by design until provider
  decision). Keep reset disabled in production until then.
- File-based `RateLimit` and local session storage remain single-node
  assumptions; revisit for clustered hosting.
- `roles.scope` backfill on pre-existing installs depends on 011 running once;
  verify `SELECT slug,scope FROM roles` after first migrate.

## Exact setup needed for live DB verification
1. Provision MySQL 8.0 (or MariaDB 10.6+) with empty DB + user; set `.env`
   `DB_*` (do not commit). Keep `APP_DEBUG=false`, `PASSWORD_RESET_ENABLED=false`.
2. `php database/migrate.php --dry-run` → expect 12 files listed with statement counts.
3. `php database/migrate.php` → expect `OK (n statement(s))` per file; then
   `SELECT version FROM schema_migrations` shows 001..011.
4. `php database/migrate.php --seeds` → rerun twice to confirm idempotency
   (`INSERT IGNORE` / `ON DUPLICATE`).
5. `php scripts/create_admin.php --name --email --password(12+)` → login,
   `GET /api/v1/me`, complaint submit (session without CSRF → 403; with → 201;
   Bearer → 201), cross-union 403 checks, expired-notice hiding, invalid
   priority/status 422s, last-admin block, reset-request generic 200 with zero
   `auth_tokens` rows while disabled.
6. Report results without pasting credentials; do not run destructive SQL.
