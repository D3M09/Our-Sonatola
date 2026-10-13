-- 005_content: news categories, news, notices
CREATE TABLE IF NOT EXISTS news_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL,
  name_en VARCHAR(150) NOT NULL,
  name_bn VARCHAR(150) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_newscat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NULL,
  union_id INT UNSIGNED NULL,
  upazila_id INT UNSIGNED NULL,
  author_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  excerpt VARCHAR(500) NULL,
  body MEDIUMTEXT NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_news_slug (slug),
  KEY idx_news_status (status),
  KEY idx_news_cat (category_id),
  KEY idx_news_published (published_at),
  KEY idx_news_union (union_id),
  FULLTEXT KEY ft_news (title, excerpt, body),
  CONSTRAINT fk_news_cat FOREIGN KEY (category_id) REFERENCES news_categories(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_news_union FOREIGN KEY (union_id) REFERENCES unions(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_news_upazila FOREIGN KEY (upazila_id) REFERENCES upazilas(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_news_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  union_id INT UNSIGNED NULL,
  upazila_id INT UNSIGNED NULL,
  author_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_notices_status (status),
  KEY idx_notices_published (published_at),
  KEY idx_notices_union (union_id),
  FULLTEXT KEY ft_notices (title, body),
  CONSTRAINT fk_notices_union FOREIGN KEY (union_id) REFERENCES unions(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_notices_upazila FOREIGN KEY (upazila_id) REFERENCES upazilas(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_notices_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
