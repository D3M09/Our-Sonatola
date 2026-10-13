-- 007_complaints: complaints + assignments + status history
CREATE TABLE IF NOT EXISTS complaints (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tracking_no VARCHAR(50) NOT NULL,
  citizen_id INT UNSIGNED NOT NULL,
  union_id INT UNSIGNED NOT NULL,
  upazila_id INT UNSIGNED NULL,
  category VARCHAR(100) NULL,
  subject VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  location_text VARCHAR(255) NULL,
  contact_phone VARCHAR(20) NULL,
  status ENUM('submitted','in_review','assigned','resolved','rejected','closed') NOT NULL DEFAULT 'submitted',
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  assigned_to INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_complaints_tracking (tracking_no),
  KEY idx_complaints_citizen (citizen_id),
  KEY idx_complaints_union (union_id),
  KEY idx_complaints_status (status),
  CONSTRAINT fk_comp_citizen FOREIGN KEY (citizen_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_comp_union FOREIGN KEY (union_id) REFERENCES unions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_comp_upazila FOREIGN KEY (upazila_id) REFERENCES upazilas(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_comp_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS complaint_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  complaint_id INT UNSIGNED NOT NULL,
  assigned_to INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED NOT NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ca_complaint (complaint_id),
  CONSTRAINT fk_ca_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ca_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ca_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS complaint_status_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  complaint_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NOT NULL,
  changed_by INT UNSIGNED NOT NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_csh_complaint (complaint_id),
  CONSTRAINT fk_csh_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_csh_by FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
