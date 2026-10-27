CREATE TABLE documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  category ENUM('proposal','chapter_1','chapter_2','chapter_3','chapter_4','chapter_5','complete_thesis','dissertation','journal_article','dataset','supervisor_feedback','institutional_guideline','other') NOT NULL DEFAULT 'other',
  title VARCHAR(300) NOT NULL,
  description TEXT NULL,
  visibility ENUM('private','shared') NOT NULL DEFAULT 'shared',
  current_version_id BIGINT UNSIGNED NULL,
  retention_until DATE NULL,
  deleted_at DATETIME NULL,
  deleted_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_documents_uuid (uuid),
  KEY ix_documents_project (project_id, category),
  KEY ix_documents_retention (retention_until),
  CONSTRAINT fk_documents_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_documents_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  document_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filename CHAR(64) NOT NULL,
  extension VARCHAR(10) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  change_note VARCHAR(500) NULL,
  extraction_status ENUM('pending','processing','completed','failed','unsupported') NOT NULL DEFAULT 'pending',
  extraction_error VARCHAR(500) NULL,
  word_count INT UNSIGNED NULL,
  page_count INT UNSIGNED NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  file_deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_versions_uuid (uuid),
  UNIQUE KEY uq_versions_stored (stored_filename),
  UNIQUE KEY uq_versions_number (document_id, version_number),
  CONSTRAINT fk_versions_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_versions_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE documents ADD CONSTRAINT fk_documents_current_version FOREIGN KEY (current_version_id) REFERENCES document_versions(id) ON DELETE SET NULL;

CREATE TABLE document_comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  document_id BIGINT UNSIGNED NOT NULL,
  version_id BIGINT UNSIGNED NULL,
  parent_id BIGINT UNSIGNED NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  origin ENUM('student','supervisor','examiner','coordinator') NOT NULL,
  page_number INT UNSIGNED NULL,
  location VARCHAR(255) NULL,
  body TEXT NOT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_doc_comments_uuid (uuid),
  KEY ix_doc_comments_doc (document_id, status),
  CONSTRAINT fk_doc_comments_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_comments_version FOREIGN KEY (version_id) REFERENCES document_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_comments_parent FOREIGN KEY (parent_id) REFERENCES document_comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_comments_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_access_permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  permission ENUM('view','download','comment') NOT NULL DEFAULT 'view',
  granted_by BIGINT UNSIGNED NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_doc_perm (document_id, user_id, permission),
  CONSTRAINT fk_doc_perm_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_perm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_perm_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_extractions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_id BIGINT UNSIGNED NOT NULL,
  seq INT UNSIGNED NOT NULL,
  page_number INT UNSIGNED NULL,
  section_label VARCHAR(255) NULL,
  content MEDIUMTEXT NOT NULL,
  char_count INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_extractions_seq (version_id, seq),
  CONSTRAINT fk_extractions_version FOREIGN KEY (version_id) REFERENCES document_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
