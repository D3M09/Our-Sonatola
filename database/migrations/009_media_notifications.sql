-- 009_media_notifications: file metadata + notifications + device tokens
CREATE TABLE IF NOT EXISTS media_files (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uploaded_by INT UNSIGNED NOT NULL,
  related_type VARCHAR(50) NOT NULL DEFAULT 'complaint',
  related_id INT UNSIGNED NOT NULL DEFAULT 0,
  file_path VARCHAR(500) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_media_related (related_type, related_id),
  KEY idx_media_uploader (uploaded_by),
  CONSTRAINT fk_media_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  body TEXT NULL,
  type VARCHAR(50) NOT NULL DEFAULT 'general',
  related_type VARCHAR(50) NULL,
  related_id INT UNSIGNED NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user (user_id, is_read),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  platform VARCHAR(20) NOT NULL DEFAULT 'android',
  token_hash CHAR(64) NOT NULL,
  device_id VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  UNIQUE KEY uq_device_token (token_hash),
  KEY idx_device_user (user_id),
  CONSTRAINT fk_device_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
