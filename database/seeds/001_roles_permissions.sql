-- Seed 001: roles + permissions (safe to re-run: INSERT IGNORE)
-- scope column added by 011_roles_scope.sql; INSERT lists scope explicitly so fresh
-- installs get correct union/global values. Older rows keep their migrated scope
-- because INSERT IGNORE skips existing slugs.
INSERT IGNORE INTO roles (slug, name, description, scope) VALUES
('super_admin','Super Admin','Full system access','global'),
('upazila_admin','Upazila Admin','Upazila-wide administration','global'),
('union_admin','Union Admin','Union-scoped administration','union'),
('editor','Editor','Manages news and notices','global'),
('service_officer','Service Officer','Handles complaints and services','union'),
('citizen','Citizen','Public user: complaints, profile','global');

INSERT IGNORE INTO permissions (slug, name, description) VALUES
('news.view','View news','List/admin view news'),
('news.create','Create news','Create news items'),
('news.edit','Edit news','Edit news items'),
('news.delete','Delete news','Delete news items'),
('notice.view','View notices','List/admin view notices'),
('notice.create','Create notices','Create notices'),
('notice.edit','Edit notices','Edit notices'),
('notice.delete','Delete notices','Delete notices'),
('complaint.view','View complaints','List complaints (scoped)'),
('complaint.assign','Assign complaints','Assign complaints to officers'),
('complaint.status','Update complaint status','Change complaint status'),
('user.view','View users','List users'),
('user.manage','Manage users','Change account status'),
('role.view','View roles','List roles/permissions'),
('role.assign','Assign roles','Assign roles to users'),
('audit.view','View audit logs','Read audit trail'),
('service.manage','Manage services','CRUD service directory'),
('setting.manage','Manage settings','Change app settings');

-- Role -> permission mapping (minimal least-privilege)
-- super_admin gets all
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='super_admin';

-- upazila_admin: everything except setting.manage + role.assign(super admin grant guarded in code)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
('news.view','news.create','news.edit','news.delete','notice.view','notice.create','notice.edit','notice.delete',
 'complaint.view','complaint.assign','complaint.status','user.view','user.manage','role.view','audit.view','service.manage')
WHERE r.slug='upazila_admin';

-- union_admin: scoped content + complaints + users view
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
('news.view','news.create','news.edit','notice.view','notice.create','notice.edit',
 'complaint.view','complaint.assign','complaint.status','user.view')
WHERE r.slug='union_admin';

-- editor
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
('news.view','news.create','news.edit','notice.view','notice.create','notice.edit')
WHERE r.slug='editor';

-- service_officer
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
('complaint.view','complaint.status','news.view','notice.view')
WHERE r.slug='service_officer';

-- citizen: no admin permissions (empty mapping intentional)
