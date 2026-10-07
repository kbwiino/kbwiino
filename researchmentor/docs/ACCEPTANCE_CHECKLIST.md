# Manual acceptance checklist

Prerequisites: seeded demo data (`php bin/migrate.php fresh && php bin/seed.php`, development only). Demo password `Demo-Passw0rd!2026`.
Mark each line Pass/Fail with the tester's initials. AI items use the mock provider unless a key is configured - repeat the AI section with a real key in a pilot.

## A. Authentication and sessions
- [ ] A1 Wrong password shows a generic error; 5 wrong attempts lock the account for the configured time
- [ ] A2 Login works; the session cookie is HttpOnly (+ Secure on HTTPS); sign-out ends the session; back button after sign-out shows the login page
- [ ] A3 Idle longer than `SESSION_LIFETIME_MINUTES` requires sign-in again
- [ ] A4 Change password works (via API/`/api/auth/change-password`); old password no longer signs in

## B. Isolation and roles (sign in as each user)
- [ ] B1 `student1` sees only their own project; pasting `student2`'s project URL shows "not found"
- [ ] B2 `supervisor1` sees only their 4 students; a project of `supervisor2` is "not found"
- [ ] B3 `coordinator` sees summary-level project info only (no problem statement, no documents, no reviews)
- [ ] B4 `gradadmin` sees aggregate dashboard only; cannot open research documents
- [ ] B5 `sysadmin` sees system health/aggregates; project URLs are "not found"; cannot reset a user to gain content access
- [ ] B6 `examiner` sees exactly one project; documents appear only after the student/supervisor grants access (Document > Access)
- [ ] B7 `student3`'s *private* draft is invisible to `supervisor2`

## C. Projects and Research Constitution
- [ ] C1 Create a project (student): supervisor required; faculty/department/programme must be consistent
- [ ] C2 Add objectives, questions, hypotheses, variables, theories, conceptual framework; milestones; stage change by supervisor only
- [ ] C3 Supervisor approves Constitution v1 (project > Constitution); student proposes a change; Constitution unchanged until supervisor approves
- [ ] C4 Approval creates v2; v1 is still viewable and marked superseded; the comparison screen shows highlighted differences
- [ ] C5 Edit the working project data: "differences ... suggestions requiring human review" appears and nothing is changed automatically
- [ ] C6 Archive a project (supervisor/admin): hidden from the default list, uploads blocked

## D. Documents
- [ ] D1 Upload PDF, DOCX, TXT, XLSX, CSV: extraction completes; preview shows page/section labels
- [ ] D2 A renamed `.exe`/`.html` as `.pdf` is rejected; a >25 MB file is rejected with a clear message
- [ ] D3 Upload a new version; compare versions shows additions/removals; download works only for permitted users
- [ ] D4 Delete a document: disappears immediately; file removed after the retention date (`worker.php --purge` with a backdated retention date)
- [ ] D5 Comments show Student/Supervisor labels; resolve works

## E. AI reviews (mock or real)
- [ ] E1 Run each review type on a document; results are labelled AI suggestion, with the disclaimer and (mock) warning
- [ ] E2 Evidence quotes show "quote found in document" (or a red "NOT found" warning when fabricated)
- [ ] E3 Without project consent a real provider is refused with an explanation; withdrawing consent stops queued jobs
- [ ] E4 Student cannot accept/reject findings (only "Mark addressed"); supervisor can, and the decision appears in "Official decisions"
- [ ] E5 PDF and DOCX reports download and contain project info, date, type, strengths, findings, recommendations, evidence, **AI disclaimer** and **human review status**
- [ ] E6 Alignment matrix: generate, edit as supervisor (row marked supervisor), mark reviewed
- [ ] E7 Rate limit: exceed `AI_RATE_LIMIT_PER_HOUR` -> friendly 429 message
- [ ] E8 With an invalid API key / network down the review fails with a safe message and no partial review is saved

## F. Supervision
- [ ] F1 Create a meeting with agenda; counterpart is notified
- [ ] F2 Supervisor generates an AI brief: agenda/actions appear as *proposed* and AI-labelled; they are not active until accepted
- [ ] F3 Record notes (student/supervisor), decisions + official outcome (supervisor only)
- [ ] F4 Task lifecycle: assign -> student starts -> submits -> supervisor accepts / requests more; overdue badge appears
- [ ] F5 Revision request linked to a document; student uploads a new version and submits; supervisor approves the version

## G. Dashboards
- [ ] G1 Student dashboard shows programme, title, supervisor, stage, milestones, tasks, documents, feedback, AI review history, meetings
- [ ] G2 Supervisor dashboard counts match the lists; attention indicators show reasons, never a score or "weak student" label
- [ ] G3 Admin dashboard shows aggregates and charts; sysadmin sees health; no project titles/content anywhere

## H. Knowledge base
- [ ] H1 Grad admin uploads a guideline (draft), approves it; search finds it with page/heading/source id
- [ ] H2 A user from another institution cannot see/search it; staff-only/admin-only levels respected
- [ ] H3 "Ask": answer cites sources and lists retrieved excerpts; an unrelated question says the guidelines do not answer it

## I. Viva
- [ ] I1 Start each mode; answer; receive formative feedback + follow-up; end session; history saved; report downloads
- [ ] I2 The banner "This is an AI practice exercise, not an official examination" is always visible; no marks/grades appear
- [ ] I3 Supervisors cannot open a student's practice session

## J. Security and operations
- [ ] J1 `https://.../.env`, `/vendor/autoload.php`, `/storage/`, `/app/` return 403/404
- [ ] J2 Response headers include CSP, HSTS (production), X-Frame-Options, nosniff
- [ ] J3 `php bin/console.php check` is green; worker processes a queued extraction; backup + restore rehearsal succeeded
- [ ] J4 Browser developer tools show no console errors on any page; no API key appears in any response or script
