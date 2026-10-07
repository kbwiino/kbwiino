# Implementation phases - status report

**Overall (this build):** 189 automated tests / 2,496 assertions pass (`vendor/bin/phpunit`, ~7 s, real MySQL 8.0, in-process HTTP).
11 migrations applied (57 tables, `php bin/migrate.php status` all `[x]`). 173 routes (`docs/API.md`). About 17.6k lines of PHP/JS/SQL/Markdown.
Browser verification (Chromium via Playwright) of every page for all six roles: no console errors, no HTTP 5xx; an XSS payload sweep across all user-controlled
fields executed nothing. The deployment was also exercised on Apache 2.4.58 + PHP-FPM 8.3 over TLS/HTTP-2 (headers, blocked private paths, login/CSRF, upload validation, worker).

Demonstration for every phase: seed the demo data and sign in as the roles listed in the README.

| Stage | Delivered | Demo route(s) | Key tests |
|---|---|---|---|
| 1 Foundation | front controller, router, middleware pipeline, validator, PDO wrapper, session/CSRF, Gate/policies, 11 SQL migrations, auth (lockout, rotation), layouts, central error handling | `/login`, `/dashboard`, `GET /api/auth/me` | `AuthTest`, `ValidatorTest`, `SecurityTest` (route audit) |
| 2 Research management | institutions/users, projects (hierarchy validation), objectives/questions/hypotheses/variables/theories/framework, milestones, stage history, **append-only Research Constitution** with proposals, supervisor approval, comparison, inconsistency detection | `/projects`, `/projects/{id}`, `/projects/{id}/constitution`, `/projects/{id}/constitution/compare` | `ProjectTest`, `ConstitutionTest` |
| 3 Documents | secure upload, signature/MIME/zip checks, private random-name storage, PDF/DOCX/TXT/XLSX/CSV extraction with page/section, versions + diff, comments, per-document grants, soft delete + retention purge | `/projects/{id}/documents`, `/documents/{id}` | `DocumentTest` (+ hostile file matrix) |
| 4 AI framework | Claude Messages client (retry/back-off/refusal/truncation), mock provider, prompt manager, strict schema validation, usage logs, consent + rate-limit guard, review jobs | `/api/ai/status`, `/projects/{id}/reviews` | `ClaudeAIServiceTest`, `ReviewSchemaTest`, `AiReviewTest` |
| 5 Agents | proposal, literature, gap, methodology, alignment (+ editable matrix), writing, integrity, statistics, constitution-check, project-context, progress; staged analysis for long documents | `/reviews/{id}`, `/projects/{id}/alignment` | `AiReviewTest` (staged, fallback, alignment, statistics) |
| 6 Supervision | meetings, agendas, notes, **official outcome**, AI brief as proposals, tasks/revision requests with supervisor acceptance, comments, approvals, notifications | `/projects/{id}/supervision` | `SupervisionTest` |
| 7 Dashboards | student, supervisor (explainable attention, stage chart), administrator (aggregate-only, charts, AI usage, system health) | `/dashboard` (each role) | `DashboardTest` |
| 8 Knowledge base | upload/approve/archive/version guidelines, chunking, FULLTEXT + keyword retrieval (`RetrieverInterface`), tenant + access-level scoping, cited Q&A, retrieval log | `/knowledge` | `KnowledgeTest` (incl. committed FULLTEXT test) |
| 9 Viva & reports | six viva modes, formative feedback, practice history; PDF + DOCX reports (proposal, chapter, methodology, alignment, meeting, progress, viva) | `/projects/{id}/viva`, report buttons | `VivaTest`, `ReportTest` (PDF text/DOCX parsed back; also verified with LibreOffice, python-docx, pdftotext) |
| 10 Security, deployment | route-security audit, password change, role-escalation guards, institution settings (retention, AI switch, privacy notice), structure admin, data export, throttling, extraction hardening, performance indexes, Apache/FPM/systemd/cron/backup files, docs | `/admin/institution`, `/privacy` | `SecurityTest`, `DatabaseSchemaTest`, `ApiEnvelopeTest` |

## Bugs found by verification (and fixed)

* Login script ran before `app.js` defined `RM` -> native GET form submit leaked credentials into the URL. Fixed (script order + `method="post"` fallback + regression test).
* `Model::where` silently ignored `FIELD(...)` ordering (allow-list too strict). Fixed.
* MySQL `NULL + n` in the keyword fallback made scores NULL when `heading` was NULL; fixed with `IFNULL`.
* A Graduate School admin could reset/disable a system administrator and admins could edit their own roles; both blocked and tested.
* `AllowOverride All # comment` in the Apache vhost is invalid; fixed after real Apache parse.
* Oversize uploads dropped by `post_max_size` showed a vague 422; now 413 with a clear message.
* Missing indexes on audit history and open-findings lookups; added in migration 011.

## Known limitations

See README "Known limitations". Additionally: no email delivery, no MFA, no password-reset-by-email, no OCR, no external plagiarism service, retrieval is lexical,
viva has no voice, DOCX/PDF reports are plain-layout, Word documents report no real page numbers (section labels are used), and live Claude behaviour is untested here (no API key).

## Recommended next steps

1. Supervised pilot with a real API key and real (consented) documents: measure evidence-verification rate, cost per review, supervisor accept rates; tune prompts/model/effort.
2. MFA (TOTP) for staff/admin; email notifications and secure password reset.
3. Institutional onboarding tools: CSV import of users/projects; admin UI for per-institution prompt overrides (`prompt_templates` is ready).
4. Semantic retrieval behind `RetrieverInterface` (embeddings) while keeping the institution/access filters.
5. OCR for scanned PDFs (isolated worker) and a licensed similarity-check integration as a separate, clearly-labelled provider.
6. Calendar (ICS) export for meetings; richer statistics (inferential checks computed in PHP/R worker, never by the model).
7. Load/pen testing against a staging copy before a large intake.
