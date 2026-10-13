# Backup & restore

## Backup (before every migration/deploy)

```bash
mysqldump -h $DB_HOST -u $DB_USER -p $DB_NAME > backup-$(date +%F).sql
tar -czf uploads-$(date +%F).tar.gz storage/uploads
cp .env .env.backup-$(date +%F)
```

Store backups off-server (encrypted). Retain 30 days. Test restores monthly.

## Restore

```bash
mysql -h $DB_HOST -u $DB_USER -p $DB_NAME < backup-YYYY-MM-DD.sql
tar -xzf uploads-YYYY-MM-DD.tar.gz
php database/migrate.php --dry-run   # verify schema_migrations matches code
```

Verify: `SELECT version FROM schema_migrations;` + `GET /api/v1/health` + login smoke test. Log incident in `audit_logs`.

## Notes

- Never run `--allow-destructive` on production without a verified backup.
- `storage/logs` excluded from public backups if they contain IPs — retain per privacy policy.
- Uploaded citizen documents live in `storage/uploads` (outside docroot); include them in every backup.
