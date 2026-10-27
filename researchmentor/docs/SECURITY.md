# Security and data protection

## Controls implemented

| Area | Implementation |
|---|---|
| Passwords | `password_hash()` (bcrypt/argon default), rehash on login, min 10 chars with letters + numbers, constant-time dummy verify for unknown accounts |
| Login | per-IP and per-account throttling, lockout after `LOGIN_MAX_ATTEMPTS` failures for `LOGIN_LOCKOUT_MINUTES`, generic error message, audit log of failures |
| Sessions | strict mode, HttpOnly + Secure + SameSite=Lax cookie, **session id and CSRF token regenerated on login and password change**, idle timeout (`SESSION_LIFETIME_MINUTES`), destroyed on logout/disable |
| CSRF | every state-changing request needs `X-CSRF-Token`; a test enumerates all routes and fails if any POST/PUT/DELETE lacks the middleware |
| Authorization | server-side policies on every resource (`app/Policies`); buttons are hidden for UX only. Route-audit test asserts all `/api` routes require authentication |
| SQL injection | PDO prepared statements only (native prepares, no emulation); identifiers for dynamic columns are whitelisted |
| XSS | server views use `e()`; client uses `RM.esc()` for every dynamic value; strict CSP (`script-src 'self'`, no inline scripts/handlers); an end-to-end browser test injected payloads into every user-controlled field and confirmed none executed |
| Uploads | extension allow-list, size limit, detected MIME must match, file signature checks, OOXML container validation, zip-bomb/path-traversal limits, DOCTYPE rejection, random 64-hex storage name, files outside web root, `nosniff` + `attachment` downloads |
| Private files | never addressed by path or URL; served only by an authorised controller; stored `0640` in `0750` directories |
| Rate limiting | AI requests per user per hour; API requests per minute; login; password change |
| Secrets | `.env` only, never sent to the browser; logs redact keys/passwords; errors to users are generic with a reference id |
| Headers | CSP, HSTS (production), X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, `Cache-Control: no-store` on API |
| Audit | `audit_logs` records sign-ins/failures, project/document/constitution/AI/supervision/admin actions with actor, IP, entity and context (no document text) |
| Isolation | institution scoping on every query path (users, guidelines, retrieval, dashboards); other institutions' data is invisible |
| Abuse of AI | prompt-injection hardening: user text cannot close prompt delimiters, the system prompt states that tagged text is data; output is schema-validated and cannot write official records |

## Role and authorization matrix

| Capability | Student | Supervisor | Research Coordinator | Graduate School Admin | External Examiner | System Admin |
|---|---|---|---|---|---|---|
| Own project: view & edit | yes | - | - | - | - | - |
| Assigned students' projects: full details | - | yes | summary only (own department) | summary only | assigned projects only | **no** |
| Documents | own (incl. private drafts) | **shared** documents of assigned students | explicit per-document grant only | explicit grant only | explicit grant only | explicit grant only |
| Run AI review (needs owner consent) | yes | yes | no | no | no | no |
| Accept/modify/reject AI output | no (may mark "addressed") | **yes** | no | no | no | no |
| Approve Research Constitution versions | propose only | **yes** | no | no | no | no |
| Record official supervision outcome / approvals | no | **yes** | no | no | no | no |
| Change research stage | no | yes | yes | yes | no | no |
| Assign supervisor / examiner | no | no | yes | yes | no | no |
| Manage guideline knowledge base | read | read (staff docs) | read (staff docs) | **manage** | read | no |
| Manage users | no | no | no | own institution (not sysadmins) | no | platform |
| Dashboards | own | assigned students | department aggregates | institution aggregates + AI usage | assigned projects | aggregates + system health |
| Practice viva | **own, private** | no | no | no | no | no |

System administrators manage the platform but have **no implicit access to research content**. No one can change their own roles; only a system administrator can manage another system administrator; role and password changes are audited.

## Data protection (Uganda) - considerations, not legal advice

Institutions deploying ResearchMentor AI are the data controllers; Versalynx (or the host) acts as processor. Review with your Data Protection Officer and counsel:

1. **Data Protection and Privacy Act, 2019** - lawful basis and purpose (research supervision), data-subject rights (access, correction, deletion: the *My data* export at `GET /api/account/my-data` supports access requests), security safeguards, breach notification duties, and **registration with the Personal Data Protection Office** where required.
2. **Cross-border processing** - when AI features are enabled, excerpts of project text are transmitted to Anthropic (outside Uganda). Check the Act's conditions for transfers abroad, put the appropriate contract/terms in place with the provider, and keep the **disclosure + project-owner consent** controls enabled. Review Anthropic's current commercial terms for data retention and training use and reflect them in the privacy notice. AI can be switched off globally (`AI_ENABLED`) or per institution (Admin > Institution settings).
3. **Minimisation** - only the selected document text/project fields are sent; no names or emails are included in prompts; usage logs hold metadata only.
4. **Retention** - per-institution `retention_days` (30-7300) is applied to each document at upload; deleted documents disappear immediately and files are purged after the retention date (`worker.php --purge`). Define retention for audit logs, notifications and AI usage logs in your policy.
5. **Children/sensitive data** - theses may contain sensitive personal data about participants; students should anonymise before upload. Consider whether AI processing is appropriate for such documents.
6. **Transparency** - complete `docs/PRIVACY_NOTICE_TEMPLATE.md` and publish it (the app shows the institution notice at `/privacy`).

## Residual risks and recommendations

* No MFA yet - add TOTP for staff/admin accounts before wide rollout.
* PDF parsing uses a third-party library on untrusted files: run the worker/FPM as an unprivileged user (provided), keep `composer update` current, consider running extraction in a container for high-risk deployments.
* `style-src 'unsafe-inline'` is kept because Bootstrap/Chart.js set inline styles; scripts remain strictly `self`.
* Account recovery is administrator-initiated; add email-based reset only with rate limiting and short-lived single-use tokens.
* Backups contain research data: encrypt them and restrict access (script supports GPG).
