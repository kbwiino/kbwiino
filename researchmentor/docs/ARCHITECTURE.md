# Architecture

## Request flow

```
Apache (public/.htaccess rewrite) -> public/index.php
   -> Request::capture() + Session::native()
   -> Application::handle(Request): Response
        Router::match()            pattern -> [Controller, method] + middleware list
        middleware pipeline        throttle -> auth -> csrf -> (role)
        Controller                 validate input -> load resource by opaque UUID -> Gate/Policy check -> Service
        Service                    business rules, transactions, audit log, notifications
        Response                   JSON envelope / HTML shell / file download
   -> SecurityHeaders::decorate()  CSP, HSTS (production), nosniff, frame-deny, no-store for API
```

`Application::handle()` has no dependency on a web server, so the whole app (including middleware and error handling) is tested in-process.

* **Controllers** are thin. **Services** hold rules and are the only layer that writes. **Models** are table gateways returning plain arrays (column whitelist = mass-assignment protection).
* **Policies** (`app/Policies`) decide *who may do what*; `ProjectAccess` derives a user's *relations* to a project (student, supervisor, examiner, coordinator, grad_admin) from data - never from request input. System administrators receive no relation.
* **Pages** are server-rendered shells (`resources/views`) that load data with `fetch` from the JSON API. No inline scripts (strict CSP). Every dynamic string goes through `RM.esc()`.
* **Queue**: `QueueDispatcher` runs jobs inline (`QUEUE_DRIVER=sync`) or stores them in `background_jobs` for `bin/worker.php` (`FOR UPDATE SKIP LOCKED`, retries with back-off).

## Modules and where they live

| Module | Main classes |
|---|---|
| Auth, sessions, CSRF, lockout | `AuthService`, `AuthMiddleware`, `CsrfMiddleware`, `RateLimiter` |
| Projects, design components, milestones, stages | `ProjectService`, `ProjectController`, `ComponentController`, `MilestoneController` |
| Research Constitution (append-only versions) | `ConstitutionService`, `ConstitutionController`, `TextDiff` |
| Documents (upload, validation, extraction, versions) | `DocumentService`, `UploadValidator`, `FileStorage`, `Extraction\TextExtractor` |
| AI framework | `AI\ClaudeAIService`, `AI\MockAIService`, `AI\ReviewSchema`, `AI\PromptManager`, `AI\AiGuard`, `AiReviewService` |
| Agents | `app/Agents/*` (see `docs/AI.md`) |
| Supervision | `SupervisionService`, `SupervisionAgent`, `SupervisionController` |
| Dashboards | `DashboardService` (aggregate-only for admins) |
| Knowledge base | `KnowledgeService`, `Retrieval\MysqlRetriever` (implements `RetrieverInterface`) |
| Viva | `VivaService`, `VivaPreparationAgent` |
| Reports | `Reports\ReportBuilder`, `Support\SimplePdf`, `Support\DocxWriter` |

## Data model (57 tables, `database/migrations/*.sql`)

Institutional (`institutions, faculties, departments, programmes, academic_years, users, roles, user_roles`),
research (`research_projects, research_project_members, research_constitutions, constitution_proposals, objectives/questions/hypotheses/variables/theories, conceptual_frameworks, research_milestones, research_stage_history, alignment_matrices/rows`),
documents (`documents, document_versions, document_comments, document_access_permissions, document_extractions`),
AI (`ai_review_jobs, ai_reviews, ai_review_issues, ai_usage_logs, ai_conversations, ai_messages, prompt_templates`),
literature (`literature_sources, literature_evidence, source_verification_records, project_literature`),
supervision (`supervision_sessions, _agendas, _action_items, _comments, _approvals`),
knowledge (`knowledge_bases, knowledge_documents, knowledge_document_chunks (FULLTEXT), knowledge_retrieval_logs`),
viva (`viva_sessions, _questions, _answers, _feedback`),
administration (`notifications, audit_logs, system_settings, background_jobs, rate_limits, generated_reports`).

Conventions: InnoDB, utf8mb4, foreign keys everywhere, UTC (`time_zone = '+00:00'` per connection), every externally visible
entity has a unique `uuid` (internal bigint ids are never returned by the API). Deletion policy: research content `CASCADE`s from its
project; people and institutions are `RESTRICT`ed; documents are soft-deleted and their files purged after the retention date.

## Keeping four kinds of content apart

| Kind | Where stored | Marker |
|---|---|---|
| Student-authored | project fields, documents, `origin='student'` rows | "Student" badge |
| Supervisor-authored | comments, notes, `origin='supervisor'` | "Supervisor" badge |
| AI suggestions | `ai_reviews`, `ai_review_issues`, rows with `origin='ai_suggestion'`, `ai_brief` | purple "AI suggestion" badge + disclaimer; `human_status='unreviewed'` until a supervisor acts |
| Official decisions | `research_constitutions`, `supervision_approvals`, `supervision_sessions.outcome`, stage history | "Official" badge; only supervisors/staff can create them; AI code paths cannot write them |

## Notable design decisions

* **Constitution is append-only.** A change = proposal (student or supervisor) -> supervisor approval -> new version; the previous version is flagged `superseded`, never edited. Concurrent approvals are serialised with a row lock and stale proposals are refused.
* **404 vs 403.** If you have no relationship to a project, resources look non-existent (404) so existence is not leaked; related-but-unauthorised gets 403.
* **AI output is untrusted input.** It is validated against a strict schema, identifiers are overwritten by the server, quoted evidence is checked against the document text, and the result is stored as a suggestion.
* **Consent gate.** Content only leaves the server for a real AI provider when the project owner has given consent and the institution has AI enabled; every call is logged (tokens, latency, status - not content).
* **Staged analysis.** Long documents are windowed, reviewed per window and consolidated; skipped parts are reported as uncertainties.
* **No framework** by requirement; the Support layer is intentionally small (router, validator, PDO wrapper, PDF/DOCX writers) to keep dependencies to `smalot/pdfparser` (PDF text) and PHPUnit (dev).
