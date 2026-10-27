CREATE TABLE knowledge_bases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  institution_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kb_uuid (uuid),
  KEY ix_kb_institution (institution_id),
  CONSTRAINT fk_kb_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE knowledge_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  knowledge_base_id BIGINT UNSIGNED NOT NULL,
  institution_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(300) NOT NULL,
  category ENUM('graduate_guideline','research_manual','proposal_template','thesis_template','dissertation_template','ethics_policy','examination_guideline','assessment_rubric','referencing_guideline','institutional_form','other') NOT NULL DEFAULT 'other',
  version_label VARCHAR(40) NOT NULL DEFAULT '1.0',
  supersedes_id BIGINT UNSIGNED NULL,
  status ENUM('draft','approved','archived') NOT NULL DEFAULT 'draft',
  access_level ENUM('all_users','staff_only','admins_only') NOT NULL DEFAULT 'all_users',
  original_filename VARCHAR(255) NOT NULL,
  stored_filename CHAR(64) NOT NULL,
  extension VARCHAR(10) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  extraction_status ENUM('pending','processing','completed','failed','unsupported') NOT NULL DEFAULT 'pending',
  metadata JSON NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kdocs_uuid (uuid),
  UNIQUE KEY uq_kdocs_stored (stored_filename),
  KEY ix_kdocs_inst (institution_id, status),
  CONSTRAINT fk_kdocs_kb FOREIGN KEY (knowledge_base_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE,
  CONSTRAINT fk_kdocs_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
  CONSTRAINT fk_kdocs_supersedes FOREIGN KEY (supersedes_id) REFERENCES knowledge_documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_kdocs_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE knowledge_document_chunks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  knowledge_document_id BIGINT UNSIGNED NOT NULL,
  institution_id BIGINT UNSIGNED NOT NULL,
  chunk_index INT UNSIGNED NOT NULL,
  page_number INT UNSIGNED NULL,
  heading VARCHAR(255) NULL,
  content TEXT NOT NULL,
  UNIQUE KEY uq_chunks_idx (knowledge_document_id, chunk_index),
  KEY ix_chunks_institution (institution_id),
  FULLTEXT KEY ft_chunks (heading, content),
  CONSTRAINT fk_chunks_doc FOREIGN KEY (knowledge_document_id) REFERENCES knowledge_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE knowledge_retrieval_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  institution_id BIGINT UNSIGNED NOT NULL,
  query_text VARCHAR(1000) NOT NULL,
  purpose VARCHAR(40) NOT NULL DEFAULT 'search',
  result_chunk_ids JSON NULL,
  result_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_retrieval_inst (institution_id, created_at),
  CONSTRAINT fk_retrieval_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
