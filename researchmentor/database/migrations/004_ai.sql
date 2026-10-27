CREATE TABLE prompt_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(80) NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  description VARCHAR(255) NULL,
  system_prompt MEDIUMTEXT NOT NULL,
  user_template MEDIUMTEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prompt_version (template_key, version),
  KEY ix_prompt_active (template_key, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_review_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NULL,
  version_id BIGINT UNSIGNED NULL,
  review_type VARCHAR(40) NOT NULL,
  status ENUM('queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  requested_by BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(20) NULL,
  model VARCHAR(80) NULL,
  options JSON NULL,
  progress_note VARCHAR(255) NULL,
  error_message VARCHAR(500) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  review_id BIGINT UNSIGNED NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review_jobs_uuid (uuid),
  KEY ix_review_jobs_project (project_id, created_at),
  KEY ix_review_jobs_status (status),
  CONSTRAINT fk_review_jobs_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_jobs_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_review_jobs_version FOREIGN KEY (version_id) REFERENCES document_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_review_jobs_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  job_id BIGINT UNSIGNED NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NULL,
  version_id BIGINT UNSIGNED NULL,
  review_type VARCHAR(40) NOT NULL,
  provider VARCHAR(20) NOT NULL,
  model VARCHAR(80) NOT NULL,
  is_mock TINYINT(1) NOT NULL DEFAULT 0,
  summary TEXT NOT NULL,
  result_json MEDIUMTEXT NOT NULL,
  human_status ENUM('unreviewed','accepted','modified','rejected') NOT NULL DEFAULT 'unreviewed',
  human_reviewed_by BIGINT UNSIGNED NULL,
  human_reviewed_at DATETIME NULL,
  human_notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reviews_uuid (uuid),
  KEY ix_reviews_project (project_id, created_at),
  KEY ix_reviews_document (document_id),
  CONSTRAINT fk_reviews_job FOREIGN KEY (job_id) REFERENCES ai_review_jobs(id) ON DELETE SET NULL,
  CONSTRAINT fk_reviews_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_reviews_version FOREIGN KEY (version_id) REFERENCES document_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_reviews_reviewer FOREIGN KEY (human_reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ai_review_jobs ADD CONSTRAINT fk_review_jobs_review FOREIGN KEY (review_id) REFERENCES ai_reviews(id) ON DELETE SET NULL;
ALTER TABLE alignment_matrices ADD CONSTRAINT fk_matrices_review FOREIGN KEY (review_id) REFERENCES ai_reviews(id) ON DELETE SET NULL;

CREATE TABLE ai_review_issues (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  review_id BIGINT UNSIGNED NOT NULL,
  issue_code VARCHAR(40) NOT NULL,
  category ENUM('major','minor') NOT NULL,
  severity ENUM('low','medium','high','critical') NOT NULL,
  section VARCHAR(255) NULL,
  page_number INT UNSIGNED NULL,
  location VARCHAR(255) NULL,
  issue TEXT NOT NULL,
  evidence TEXT NULL,
  evidence_verified TINYINT(1) NULL,
  implication TEXT NULL,
  recommendation TEXT NULL,
  supervisor_question TEXT NULL,
  requires_human_verification TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('open','accepted','modified','rejected','resolved') NOT NULL DEFAULT 'open',
  resolution_note TEXT NULL,
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_issues_uuid (uuid),
  KEY ix_issues_review (review_id, category),
  KEY ix_issues_status (status),
  CONSTRAINT fk_issues_review FOREIGN KEY (review_id) REFERENCES ai_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_issues_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_usage_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  project_id BIGINT UNSIGNED NULL,
  job_id BIGINT UNSIGNED NULL,
  purpose VARCHAR(60) NOT NULL,
  provider VARCHAR(20) NOT NULL,
  model VARCHAR(80) NOT NULL,
  input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('success','error','rate_limited','refused') NOT NULL,
  error_code VARCHAR(60) NULL,
  request_id VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_usage_user (user_id, created_at),
  KEY ix_usage_created (created_at),
  CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_usage_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NULL,
  kind ENUM('assistant','knowledge_question') NOT NULL DEFAULT 'assistant',
  title VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conv_uuid (uuid),
  KEY ix_conv_user (user_id, created_at),
  CONSTRAINT fk_conv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_conv_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content MEDIUMTEXT NOT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_messages_conv (conversation_id, id),
  CONSTRAINT fk_messages_conv FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
