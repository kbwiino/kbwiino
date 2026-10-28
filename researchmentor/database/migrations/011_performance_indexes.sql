-- Project history looks audit entries up by project (stored in metadata.project) or by entity uuid.
ALTER TABLE audit_logs
  ADD COLUMN project_uuid CHAR(36) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.project'))) STORED AFTER entity_uuid,
  ADD KEY ix_audit_project (project_uuid, id),
  ADD KEY ix_audit_entity_uuid (entity_uuid, id);

ALTER TABLE ai_review_issues ADD KEY ix_issues_review_status (review_id, status);
ALTER TABLE supervision_comments ADD KEY ix_scomments_open (project_id, status, origin);
ALTER TABLE document_comments ADD KEY ix_doc_comments_origin (document_id, status, origin);
