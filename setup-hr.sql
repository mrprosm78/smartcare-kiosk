
CREATE TABLE hr_applications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_token VARCHAR(64) NOT NULL,
  status ENUM('draft','submitted','reviewing','rejected','hired','archived') NOT NULL DEFAULT 'draft',
  job_slug VARCHAR(120) NOT NULL DEFAULT '',
  applicant_name VARCHAR(160) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  phone VARCHAR(80) NOT NULL DEFAULT '',
  payload_json LONGTEXT NOT NULL,
  review_notes LONGTEXT NULL,
  reviewed_by_admin_id INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  submitted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hr_app_public_token (public_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
