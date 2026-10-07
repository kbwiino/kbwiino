CREATE TABLE literature_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  institution_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(500) NOT NULL,
  authors VARCHAR(500) NOT NULL,
  publication_year SMALLINT UNSIGNED NULL,
  venue VARCHAR(300) NULL,
  doi VARCHAR(200) NULL,
  url VARCHAR(500) NULL,
  source_type ENUM('journal','book','chapter','thesis','report','conference','web','other') NOT NULL DEFAULT 'journal',
  verification_status ENUM('unverified','verified','rejected') NOT NULL DEFAULT 'unverified',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sources_uuid (uuid),
  KEY ix_sources_doi (doi),
  CONSTRAINT fk_sources_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE source_verification_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  verified_by BIGINT UNSIGNED NULL,
  method ENUM('doi_lookup','library_catalogue','manual_inspection','publisher_site') NOT NULL,
  outcome ENUM('verified','not_found','mismatch') NOT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_verif_source (source_id),
  CONSTRAINT fk_verif_source FOREIGN KEY (source_id) REFERENCES literature_sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_verif_user FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_literature (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  theme VARCHAR(255) NULL,
  literature_type ENUM('theoretical','empirical','methodological','other') NOT NULL DEFAULT 'empirical',
  relevance_notes TEXT NULL,
  added_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_project_literature (project_id, source_id),
  CONSTRAINT fk_pl_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pl_source FOREIGN KEY (source_id) REFERENCES literature_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE literature_evidence (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NULL,
  document_id BIGINT UNSIGNED NULL,
  review_id BIGINT UNSIGNED NULL,
  evidence_type ENUM('theory','empirical','methodological','gap_claim','other') NOT NULL,
  excerpt TEXT NOT NULL,
  page_number INT UNSIGNED NULL,
  notes TEXT NULL,
  origin ENUM('student','supervisor','ai_suggestion') NOT NULL DEFAULT 'student',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_evidence_uuid (uuid),
  KEY ix_evidence_project (project_id),
  CONSTRAINT fk_evidence_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_evidence_source FOREIGN KEY (source_id) REFERENCES literature_sources(id) ON DELETE SET NULL,
  CONSTRAINT fk_evidence_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_evidence_review FOREIGN KEY (review_id) REFERENCES ai_reviews(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
