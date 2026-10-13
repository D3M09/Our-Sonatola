# Database schema

All tables: `InnoDB`, `utf8mb4_unicode_ci`, FKs with explicit names, indexes on foreign keys + status fields.

## Tables

- `schema_migrations(version PK, applied_at)` — bookkeeping.
- `upazilas(id, name_en, name_bn, district=Bogura, division=Rajshahi)` — unique (name_en, district).
- `unions(id, upazila_id FK, name_en, name_bn, code UNIQUE NULL, is_active)` — seed empty by design.
- `roles(id, slug UNIQUE, name)` — 6 roles: super_admin, upazila_admin, union_admin, editor, service_officer, citizen.
- `permissions(id, slug UNIQUE, name)` — 18 permissions (news.*×4, notice.*×4, complaint.view/assign/status, user.view/manage, role.view/assign, audit.view, service.manage, setting.manage).
- `role_permissions(role_id, permission_id)` — least-privilege mapping; citizen has none.
- `users(id, name, email UNIQUE, phone UNIQUE NULL, password_hash, union_id FK NULL, upazila_id FK NULL, account_status ENUM pending/active/suspended/disabled, email_verified_at, last_login_at, ...)` — passwords via `password_hash`, never plaintext.
- `user_roles(user_id, role_id)` — composite PK.
- `news_categories(id, slug UNIQUE, name_en, name_bn, is_active)`.
- `news(id, category_id FK, union_id FK, upazila_id FK, author_id FK, title, slug UNIQUE, excerpt, body MEDIUMTEXT, status draft/published/archived, published_at, FULLTEXT(title,excerpt,body))`.
- `notices` — same scoping as news + `expires_at`.
- `services(id, name_en, name_bn, description, office_name, contact_*, union_id FK, upazila_id FK, is_active, sort_order, FULLTEXT)`.
- `complaints(id, tracking_no UNIQUE SN-YYYYMMDD-XXXXXXXX, citizen_id FK RESTRICT, union_id FK RESTRICT, upazila_id FK, category, subject, description, location_text, contact_phone, status submitted/in_review/assigned/resolved/rejected/closed, priority, assigned_to FK)`.
- `complaint_assignments(id, complaint_id CASCADE, assigned_to RESTRICT, assigned_by RESTRICT, note)`.
- `complaint_status_history(id, complaint_id CASCADE, from_status, to_status, changed_by RESTRICT, note)`.
- `applications(id, tracking_no UNIQUE, citizen_id RESTRICT, service_id SET NULL, union_id RESTRICT, subject, data_json JSON, status draft/submitted/under_review/approved/rejected/closed, submitted_at)` — gated by `settings.applications.online_enabled=0`.
- `application_status_history` — mirrors complaint history.
- `media_files(id, uploaded_by RESTRICT, related_type, related_id, file_path, original_name, mime_type, file_size, sha256, is_public=0)` — private by default; served via controller after authz.
- `notifications(id, user_id CASCADE, title, body, type, related_*, is_read, read_at)`.
- `device_tokens(id, user_id CASCADE, platform, token_hash UNIQUE SHA-256, device_id, last_used_at)` — raw token never stored (Capacitor-ready).
- `auth_tokens(id, user_id CASCADE, token_hash UNIQUE SHA-256, type api_session/password_reset/email_verify, expires_at, used_at NULL)` — single-use for reset/verify.
- `audit_logs(id BIGINT, actor_id SET NULL, action, entity_type, entity_id, ip_address, user_agent, metadata_json, created_at)` — append-only; indexes on actor/entity/time.
- `settings(setting_key PK, setting_value, description)` — includes `applications.online_enabled=0`.

## Migration procedure (safe)

1. Backup: `mysqldump -u USER -p DB > backup-YYYYMMDD.sql` (see `docs/backup-restore.md`).
2. Review: `php database/migrate.php --dry-run` (lists pending files + statement counts, no writes).
3. Apply: `php database/migrate.php` then `php database/migrate.php --seeds` on fresh installs only.
4. Verify: `SELECT version FROM schema_migrations;` + app health check.

Execution: files run in filename order via `src/Core/SqlSplitter.php`
(quote/comment-aware splitting, no naive `explode(';')`). Each statement is
executed individually with PDO. Supported versions: MySQL 5.7+/8.0 and
MariaDB 10.3+ (`DATETIME DEFAULT CURRENT_TIMESTAMP`, `JSON`, InnoDB FULLTEXT).
Older MariaDB 10.1 and below are NOT supported (no `JSON` type).

## Rollback strategy

- Migrations are additive and idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`). No `DROP` in shipped migrations.
- MySQL/MariaDB DDL (CREATE/ALTER) causes an **implicit commit** and is NOT
  transactionally rollbackable. The runner intentionally does NOT wrap DDL in a
  transaction and makes NO transactional-rollback claim. On failure it stops at
  the failing statement; earlier statements in that file remain applied.
- Rollback = restore from pre-migration backup; never auto-downgrade production. Each migration file header documents inverse steps (e.g. `DROP TABLE X` only after manual export review).
- Emergency: point `DB_*` to standby snapshot, verify `schema_migrations`, replay binlog if needed. Document incident in audit log.
