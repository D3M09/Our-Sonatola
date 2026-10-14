# Shared-hosting deployment (cPanel-style)

Assumes: Apache + PHP 8.1+, MySQL/MariaDB, `public_html` writable, no root.

## 1. Prepare

- Create DB + user in cPanel → note host/db/user/pass.
- Set PHP version to 8.1 or 8.2 in cPanel → MultiPHP.
- Enable extensions: `pdo_mysql, mbstring, openssl, curl, fileinfo, intl`.

## 2. Upload

- Upload project so that **document root points to `public/`**:
  - Ideal: project at `/home/user/sonatola`, symlink or set `public_html` → `sonatola/public` (ask host support if panel disallows).
  - Fallback: copy contents of `public/` into `public_html`, keep everything else in `/home/user/sonatola` one level above, adjust `public/index.php` paths (`__DIR__.'/../src'` → `/home/user/sonatola/src`).
- `storage/uploads` + `storage/logs` must stay **outside** `public_html`. `chmod 750` dirs, `640` files.

## 3. Configure

- **Option A (no shell):** open `https://yourdomain/setup.php` and follow the
  wizard — it checks requirements, writes `.env`, migrates, seeds, creates the
  admin, and locks. Full operator guide: `docs/setup.md`.
- **Option B (shell):** copy `.env.example` → `.env` (project root, never in `public/`). Fill DB creds, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://yourdomain`, `COOKIE_SECURE=true`.
- If host ignores `.htaccess`, API still works via `index.php?route=/api/v1/news` fallback (see `public/index.php`).

## 4. Migrate

```bash
php database/migrate.php --dry-run
php database/migrate.php
php database/migrate.php --seeds   # fresh install only
php scripts/create_admin.php --name="..." --email="..."   # prompts for the password (hidden, min 12 chars)
```

The first-admin password is entered twice at a hidden prompt (minimum 12 characters)
and is never passed as a command-line argument. A POSIX interactive terminal with
`stty` is required; on Windows use WSL or another POSIX terminal (e.g. Git Bash).
Non-interactive execution fails closed and creates no account.

## 5. Verify

- `GET /api/v1/health`, `GET /api/v1/news`, login + `GET /api/v1/me`, complaint submit as citizen.
- Check `storage/logs/app.log` for errors. Set up cron for log rotation.

## Production checklist

- [ ] `APP_DEBUG=false`, `display_errors=Off` in cPanel PHP options.
- [ ] `.env` not web-accessible (test `/.env` → 404).
- [ ] `COOKIE_SECURE=true`, `SESSION_NAME` custom.
- [ ] `CORS_ALLOWED_ORIGINS` empty or exact Capacitor origin only.
- [ ] `UPLOAD_MAX_MB` sane (5), uploads non-executable.
- [ ] Backups scheduled (see `docs/backup-restore.md`).
- [ ] First admin created via CLI; no seeded admin exists.
- [ ] `applications.online_enabled=0` unless officially launched.
- [ ] Footer disclaimer: unofficial demo, verify with administration.
