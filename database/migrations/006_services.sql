-- 006_services: service directory
CREATE TABLE IF NOT EXISTS services (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name_en VARCHAR(200) NOT NULL,
  name_bn VARCHAR(200) NULL,
  description TEXT NULL,
  office_name VARCHAR(200) NULL,
  contact_name VARCHAR(150) NULL,
  contact_phone VARCHAR(20) NULL,
  contact_email VARCHAR(190) NULL,
  union_id INT UNSIGNED NULL,
  upazila_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_services_active (is_active),
  KEY idx_services_union (union_id),
  FULLTEXT KEY ft_services (name_en, name_bn, office_name, description),
  CONSTRAINT fk_services_union FOREIGN KEY (union_id) REFERENCES unions(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_services_upazila FOREIGN KEY (upazila_id) REFERENCES upazilas(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
