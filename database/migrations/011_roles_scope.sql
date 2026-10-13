-- 011_roles_scope: explicit union-scope policy for roles.
-- Supports future union-scoped roles (e.g. volunteer_coordinator) without code changes.
-- Code falls back to ScopePolicy::UNION_SCOPED when this column is absent.
-- One-shot migration (bookkeeping prevents rerun); no IF NOT EXISTS for MySQL 8.0 compat.
ALTER TABLE roles ADD COLUMN scope ENUM('global','union') NOT NULL DEFAULT 'global';
UPDATE roles SET scope = 'union' WHERE slug IN ('union_admin', 'service_officer');
