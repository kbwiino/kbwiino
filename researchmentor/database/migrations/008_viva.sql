CREATE TABLE viva_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('general','theory','methodology','statistics','contribution','external_examiner') NOT NULL,
  status ENUM('active','completed') NOT NULL DEFAULT 'active',
  provider VARCHAR(20) NULL,
  is_mock TINYINT(1) NOT NULL DEFAULT 0,
  summary TEXT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  UNIQUE KEY uq_viva_sessions_uuid (uuid),
  KEY ix_viva_project (project_id, started_at),
  CONSTRAINT fk_viva_sessions_project FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_viva_sessions_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE viva_questions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  seq INT UNSIGNED NOT NULL,
  question TEXT NOT NULL,
  topic VARCHAR(255) NULL,
  is_follow_up TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_viva_questions_uuid (uuid),
  UNIQUE KEY uq_viva_questions_seq (session_id, seq),
  CONSTRAINT fk_viva_questions_session FOREIGN KEY (session_id) REFERENCES viva_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE viva_answers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_id BIGINT UNSIGNED NOT NULL,
  answer TEXT NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_viva_answers_question (question_id),
  CONSTRAINT fk_viva_answers_question FOREIGN KEY (question_id) REFERENCES viva_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE viva_feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  answer_id BIGINT UNSIGNED NOT NULL,
  feedback TEXT NOT NULL,
  strengths JSON NULL,
  improvements JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_viva_feedback_answer (answer_id),
  CONSTRAINT fk_viva_feedback_answer FOREIGN KEY (answer_id) REFERENCES viva_answers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
