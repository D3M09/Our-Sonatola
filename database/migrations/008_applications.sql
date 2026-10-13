-- 008_applications: online applications DISABLED by default (see settings seed)
CREATE TABLE IF NOT EXISTS applications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tracking_no VARCHAR(50) NOT NULL,
  citizen_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NULL,
  union_id INT UNSIGNED NOT NULL,
  upazila_id INT UNSIGNED NULL,
  subject VARCHAR(200) NOT NULL,
  data_json JSON NULL,
  status ENUM('draft','submitted','under_review','approved','rejected','closed') NOT NULL DEFAULT 'draft',
  submitted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_apps_tracking (tracking_no),
  KEY idx_apps_citizen (citizen_id),
  KEY idx_apps_union (union_id),
  KEY idx_apps_status (status),
  CONSTRAINT fk_apps_citizen FOREIGN KEY (citizen_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_apps_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_apps_union FOREIGN KEY (union_id) REFERENCES unions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_apps_upazila FOREIGN KEY (upazila_id) REFERENCES upazilas(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_status_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NOT NULL,
  changed_by INT UNSIGNED NOT NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ash_app (application_id),
  CONSTRAINT fk_ash_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ash_by FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
