-- 001_schema_migrations: migration bookkeeping (must run first)
CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(50) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
