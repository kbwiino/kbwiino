# ResearchMentor AI

AI-assisted research supervision for Master's and PhD programmes: student research workspaces, supervisor
dashboards, versioned Research Constitution, document management with text extraction, AI-assisted (never
AI-decided) reviews, supervision workflow, institutional guideline retrieval, viva practice and PDF/DOCX reports.

* **Stack:** Core PHP 8.2+ (OOP, MVC-style, PSR-4/Composer), MySQL 8 (InnoDB, utf8mb4), HTML5/CSS3, Bootstrap 5,
  vanilla JavaScript (Fetch/AJAX), Chart.js. No framework, no Node build step.
* **AI:** Anthropic Claude Messages API, called server-side only. A clearly-labelled mock provider lets the app run
  without an API key in development (it is refused in production).
* **Target host:** Ubuntu VPS, Apache 2.4 + PHP-FPM, MySQL, Certbot - `https://mentor.versalynx.net`.

> **Principle:** the AI is an assistant. It never grades, approves a thesis, certifies originality or takes an
> examination decision. AI output, student content, supervisor comments and official decisions are stored and
> displayed as separate, labelled things.

## Quick start (development)

```bash
cd researchmentor
composer install
cp .env.example .env                      # then edit DB_* ; set AI_PROVIDER=mock and SESSION_SECURE_COOKIE=false for local HTTP
mysql -uroot -e "CREATE DATABASE researchmentor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php bin/migrate.php up                    # 011 migrations: 57 tables
php bin/seed.php                          # Demo University: 3 supervisors, 10 students, 10 projects, documents, meetings, tasks, AI (mock) reviews
php -S 127.0.0.1:8080 -t public bin/dev-router.php
```

Open <http://127.0.0.1:8080>. Demo accounts (password `Demo-Passw0rd!2026`, or `DEMO_PASSWORD`):

| Role | Sign in |
|---|---|
| Student | `student1@demo.edu` ... `student10@demo.edu` |
| Supervisor | `supervisor1@demo.edu` (4 students), `supervisor2@demo.edu` (3), `supervisor3@demo.edu` (3) |
| Research Coordinator | `coordinator@demo.edu` (Computer Science dept.) |
| Graduate School Administrator | `gradadmin@demo.edu` |
| External Examiner | `examiner@demo.edu` (assigned to one project only) |
| System Administrator | `sysadmin@demo.edu` |

Docker alternative: `docker compose -f docker-compose.dev.yml up --build` (see the file header).
Demo data refuses to load when `APP_ENV=production`.

## Tests

```bash
mysql -uroot -e "CREATE DATABASE researchmentor_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"   # grant your DB user access
vendor/bin/phpunit                         # uses DB_DATABASE=researchmentor_test (set in phpunit.xml); recreates the schema each run
```

The suite runs against real MySQL, in-process through the same front controller the web server uses
(`Application::handle(Request)`), with a scripted HTTP transport standing in for the Anthropic API.
See `docs/PHASES.md` for the current results and `docs/ACCEPTANCE_CHECKLIST.md` for the manual checklist.

## Layout

```
app/Controllers  thin HTTP controllers (JSON API + page shells)     app/Policies    authorization (Gate, per-resource policies)
app/Services     business logic (projects, documents, AI, ...)       app/Agents      specialist AI/data agents
app/Models       table gateways                                      app/Middleware  auth, CSRF, role, throttle, security headers
app/Support      router, request/response, validator, PDO, PDF/DOCX  app/Jobs        background job handlers
bootstrap/       app + CLI bootstrap        config/   env-driven config      routes/  web + api route files
database/        migrations (SQL) + demo seeder       resources/ views + AI prompt templates
public/          the ONLY web-reachable directory     storage/   logs, cache (private files live outside the web root)
bin/             migrate, seed, worker, console, routes     deploy/  Apache, PHP-FPM, systemd, cron, backup scripts
tests/           Unit, Integration, Feature           docs/    architecture, security, deployment, API reference
```

## Documentation

* `docs/DEPLOYMENT.md` - Ubuntu + Apache + PHP-FPM + MySQL + Certbot, worker, cron, backups, logs; one-command install with `deploy/scripts/provision.sh`
* `docs/ARCHITECTURE.md` - request flow, modules, data model, design decisions
* `docs/SECURITY.md` - controls, role/authorization matrix, data protection (Uganda), AI data handling
* `docs/AI.md` - Claude integration, prompts, schema validation, staged analysis, agents, limitations
* `docs/API.md` - generated route reference (`php bin/routes.php`)
* `docs/ACCEPTANCE_CHECKLIST.md` - manual acceptance testing
* `docs/PHASES.md` - what each implementation stage delivered, results, known limitations, next steps
* `docs/PRIVACY_NOTICE_TEMPLATE.md` - institutional privacy notice to complete before go-live

## Known limitations (honest summary)

* **No OCR** - scanned/image-only PDFs cannot be analysed; the system says so instead of guessing.
* **No plagiarism/originality check** - the Integrity agent flags citation/attribution indicators only and says it did not run a similarity check. Integrating a licensed service would be a new provider behind the same review interface.
* **Statistics assistant (MVP)** - descriptive statistics are computed by PHP for CSV/XLSX (first sheet, up to 20 000 rows); it explains results and flags objective/analysis mismatches but does not run inferential tests and is not a replacement for SPSS/SmartPLS/R.
* **Retrieval is keyword/full-text** (MySQL FULLTEXT + keyword fallback). `RetrieverInterface` is the seam for embeddings later.
* **Notifications are in-app only** (no email), password reset is administrator-initiated, and there is no MFA yet. These are the first recommended next steps (see `docs/PHASES.md`).
* **AI reviews of real theses need an Anthropic API key and the project owner's consent**; with `QUEUE_DRIVER=database` they run in the background worker.
* The AI model/prompt behaviour could not be evaluated against live Claude in the build environment (no key). The request format follows the current Messages API reference and is covered by transport-level tests; do a supervised pilot with real documents before relying on it.
