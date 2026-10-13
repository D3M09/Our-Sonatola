# Browser installer (`public/setup.php`)

No-shell-needed installer for shared hosting (PHP 8.1+, MySQL 5.7+/MariaDB 10.3+).
Uses the same code as the CLI (`src/Setup/InstallRunner.php` backs both
`database/migrate.php` and the wizard — one implementation, no duplication).

## When to use it

- Fresh hosting account with an **empty** database you created in the panel.
- You cannot or will not use SSH/CLI. Otherwise prefer the CLI flow in
  `docs/deployment.md` (identical results).

## Operator steps

1. Upload the project so the web root serves `public/` and open
   `https://yourdomain/setup.php`.
2. Requirements screen must be all green (PHP ≥ 8.1; `pdo_mysql, mbstring,
   openssl, json, fileinfo`; writable project root and `storage/`).
3. Database step: enter host/port/name/user/password. The installer only
   **reads** (`SELECT VERSION()`, table inventory). Confirm the reported
   server version and table count (expect 0).
4. Write `.env`: re-enter the DB password to authorize. Refused if `.env`
   already exists — back it up or remove it via File Manager first.
5. Migrate, then seeds (offered only on the just-verified empty DB), then
   create the single Super Admin (12+ character password, never defaulted).
6. Finish: installation locks automatically. Then **rename or delete
   `public/setup.php`** via File Manager (the lock alone already blocks reruns).

## States you may see

- **Fresh** (zero tables): normal install path.
- **Interrupted** (partial install matching its progress record): offers resume
  of the missing steps only.
- **Existing** (complete install, or lock present): installer disabled; use the
  application itself.
- **Unsafe/ambiguous** (unrelated tables, mismatched records): everything
  refused. Safe recovery: back up, create a new empty database, point the
  installer at it. The installer never drops, truncates, or repairs data.

## Lock recovery (legitimate lockouts only)

1. If setup was interrupted, reopening the wizard resumes automatically —
   no lock handling needed.
2. If `storage/install.lock` was deleted manually, the wizard re-verifies the
   real schema, seed counts, and administrator state from the database before
   allowing anything; seeds are never re-fired onto a complete schema.
3. Lock removal requires File Manager/FTP/SSH access (filesystem access is the
   ownership proof). There is deliberately no URL or button that unlocks.

## Security notes

- Wizard sessions expire after 10 idle minutes; every POST needs its CSRF token;
  GET requests never change anything.
- The DB password lives only in the POST that uses it — never in the session,
  logs, or pages. Error pages show safe causes, never credentials or SQL.
- `.env` is written atomically with restricted permissions where the host
  allows (`chmod 0600` is best-effort; verify in File Manager). It is never
  overwritten and never served for download.
- Password reset stays disabled after install until SMTP is configured
  (see `docs/api-v1.md`).

## After installing

Run the production checklist in `docs/deployment.md` (debug off, `.env`
unreachable at `/.env`, backups scheduled, `applications.online_enabled`
still `0` unless officially launched).
