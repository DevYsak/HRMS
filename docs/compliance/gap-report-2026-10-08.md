# Pulse v3.1 gap report — 8 October 2026

Audit of the Pulse HRMS code against **Pulse by Conexus v3.1** (`Pulse_by_Conexus_v3.1.pdf`), module by module.
This is the report only. Nothing has been fixed as part of it.

Full per-requirement findings (every row, with evidence): [gap-report-2026-10-08-findings.md](gap-report-2026-10-08-findings.md).

## 1. Scope and method

| | |
|---|---|
| Code audited | `staging` at `10b6125` (pushed; identical to `origin/staging`). This is what `deploy.sh` sends to production. |
| What is live | Not known. Several `staging` commits may not be deployed. Nothing below claims a behaviour is live. |
| Uncommitted work | Other sessions have uncommitted changes in the working tree (department-head role, data scope, dashboards, navigation). They were **not** judged; where they would change a finding, the finding says so. See §9. |
| Previous audit | `appendix-a` … `appendix-g` (4 Oct 2026). Every prior finding in scope was re-checked against current code; 34 commits have landed since. |
| Method | Seven read-only reviewers, one per module group, each reading the spec section and the code. No tests were run, no database was touched, no code was changed. Every row cites `path:line`. |
| Production data | Not inspected. Role/permission findings assume the seeded defaults plus migrations; production `role_permission` rows, users' department/shift scopes, shift rows and MDL dates are UNVERIFIED. |

Status meanings:

- **PASS** — matches the spec, or the current deliberate rule.
- **PARTIAL** — exists but incomplete, or only some paths follow the rule.
- **MISSING** — required, not built.
- **BUG** — built but wrong: incorrect results, a crash, or an authorisation hole.
- **OUT OF SCOPE** — excluded by spec §1.2.
- **INTENTIONALLY CHANGED** — differs from v3.1 because the business deliberately changed the rule (§3 lists every one and its source).

## 2. Scorecard

### By review group (491 requirements)

| Group | Modules | PASS | PARTIAL | MISSING | BUG | INT. CHANGED | Rows |
|---|---|---|---|---|---|---|---|
| EMP | Employee management, departments/org, onboarding, offboarding, documents | 33 | 24 | 5 | 2 | 1 | 65 |
| ATT | Attendance, breaks, regularisation | 26 | 8 | 1 | 3 | 6 | 44 |
| LV | Leave CSL / MDL / Comp Off, holidays, leave encashment | 35 | 16 | 1 | 5 | 0 | 57 |
| PAY | OT, payroll, salary cycles, incentives, reimbursements, payslips | 38 | 26 | 4 | 10 | 2 | 80 |
| NTF | Notifications, scheduled jobs, reports | 39 | 16 | 0 | 0 | 3 | 58 |
| PRF / DSH | Performance / KPI, dashboards | 18 | 27 | 13 | 6 | 2 | 66 |
| RBAC / SET / AUD / HLP / SEC | RBAC, settings, audit logs, help & guide, security | 81 | 25 | 4 | 4 | 7 | 121 |
| **Total** | | **270** | **142** | **28** | **30** | **21** | **491** |

OUT OF SCOPE items are listed in §4 rather than counted in the groups.

### By module (25 modules requested)

| Module | Verdict | Main gaps (row IDs) |
|---|---|---|
| Employee Management | Mostly done | Cycle change not deferred (EMP-04); lifecycle transitions unenforced (EMP-09); inactive / archived_at never set after last working day (EMP-12) |
| Departments / org structure | Partial | No UI to assign a department head at HEAD, so dept-head scoping is dormant (EMP-07) |
| Attendance | Mostly done | Regularised days drop later punches (ATT-29); engine sync leaves missing-checkout flag set (ATT-16); Finance has no attendance summary (ATT-41) |
| Breaks | Partial | Open break not closed at clock-out (ATT-10); excess-break flag misses UK-shift breaks after 20:00 (ATT-12) |
| Regularisation | Done, with defects | HR-only by decision (R8). Director can fast-track corrections (ATT-34); "override reject" diverges status from data (ATT-32) |
| Leave CSL / MDL / Comp Off | Mostly done | "Leave Rejected" sent on every manager approval (LV-29); leave chargeable on PH/MDL via date edits and leave regularisation (LV-12, LV-20); half-day on a multi-day range (LV-23); Comp Off crediting inconsistent (LV-13, LV-14) |
| Leave encashment | Partial | Encash button blocks leave applications at HEAD (LV-33, fix uncommitted); approved-late encashment never paid (LV-38); extra Finance stage (LV-36, decision needed) |
| OT | Partial | Nexflow auto-approves OT with no Pulse approver (PAY-03); no Finance verification (PAY-13); late-approved OT never paid (PAY-14); approved OT for an unworked day pays the estimate (PAY-08) |
| Payroll | Partial | Finance sign-off can be removed from the approval chain (PAY-33); payslips editable after submission (PAY-34); period lock covers only biometric rebuilds (PAY-28) |
| Salary cycles | Partial | Cycle move is immediate — double pay / gap (PAY-26); pay days seeded 5 and 25 instead of 1 and 21 (PAY-21, PAY-23) |
| Incentives | Partial | Approved by HR/Finance, not the Director (PAY-49); no type field (PAY-47); late-approved incentives never paid (PAY-42) |
| Reimbursements | Partial | Claims approved after their month's run are never paid (PAY-43); categories differ from spec (PAY-52); reviewer cannot open the receipt (PAY-53) |
| Payslips | Partial | HRA silently ₹0 (PAY-38); employees see draft payslips (PAY-57); no 5-minute signed URLs (PAY-58); eligibility flags default off with no edit screen (PAY-15) |
| Notifications | Done, noisy | Nearly every event also emails by default (NTF-10 — decision needed); payroll submit can roll back on an SMTP failure (NTF-28) |
| Performance / KPI | Partial | Self-review order not enforced (PRF-07); feedback + promotion flag not captured in live cycles (PRF-09); score maths unreliable (PRF-10, PRF-11); deadlines not enforced (PRF-12) |
| Onboarding | Partial | Several checklist tasks missing or falsely auto-completed (EMP-19…26); managers cannot work their tasks (EMP-45) |
| Offboarding | Partial | Resigned/terminated users without an exit record keep access (EMP-34); Finance cannot do the F&F settlement (EMP-36, EMP-46); owners cannot complete exit tasks (EMP-42) |
| Documents | Mostly done | Library opens the oldest version (EMP-50); company-wide "acknowledge" docs notify nobody (EMP-53) |
| RBAC | Mostly done | Director over-reach (§6, item 3); login-email repoint → account takeover (RBAC-79); Finance missing attendance/leave summaries (RBAC-49, RBAC-50) |
| Dashboards | Weak | Finance dashboard empty (DSH-24…30); Executive dashboard shows made-up metrics and an Apr–Mar year (DSH-14); §5.1 KPIs missing (DSH-04, 06, 11) |
| Reports | Mostly done | CSV/PDF only, no Excel (NTF-56); `view_reports` permission gates nothing (NTF-58); monthly attendance summary is stored but never shown (NTF-48) |
| Settings | Done | Payroll approval chain can drop Finance (SET-07) |
| Scheduled jobs | Mostly done | Excess-break timing (NTF-40); review reminders repeat daily and skip managers (NTF-30, NTF-46); no per-row failure isolation in several jobs (NTF-49) |
| Audit logs | Mostly done | Demo-clear commands truncate `audit_logs` with no production guard (AUD-10); bulk payslip status change skips the audit observer (AUD-03) |
| Help & Guide | Done | Leave copy says carry-forward "can expire" (HLP-06); Payslips link shown when the module is off (HLP-03, fix uncommitted) |

## 3. Deliberate overrides of v3.1 (INTENTIONALLY CHANGED)

These differ from v3.1 because the business changed the rule. They are **not** gaps and must not be "fixed" back to v3.1.

| Ref | v3.1 says | Production policy now | Source | Rows |
|---|---|---|---|---|
| R1 | Laravel 11, Livewire 3, Breeze | Laravel 13, Livewire 4, Flux UI, Tailwind 4, Fortify | Stack upgrade | RBAC-01 |
| R4 | (consistent) 12 CSL + 6 MDL + Comp Off | Same — the earlier UK 28-day Annual Leave model was **reversed** on 4 Oct 2026; HR register snapshot is the 2026/27 balance truth; negative balances allowed | User decision 4 Oct 2026 | LV-50, LV-52 (PASS against R4) |
| R6 | total_hours = out − in, breaks informational | Same rule, made canonical: worked = final valid OUT − first valid IN; late at minute precision; missing checkout only after shift end + 60 min and nothing is invented | f8bedba, cb8ac81 | ATT-07, ATT-14 (PASS) |
| R7 | Web clock-in button only | Biometric devices also feed punches: Face = IN, ID Card = OUT, latest of a 60-s burst, stray cards ignored | d19e51b | ATT-36 |
| R8 | Regularisation → manager approves | Goes **straight to HR**, one step; manager sees "Awaiting HR"; HR may edit / delete / revert approved ones with confirmation + audit | e29edad, d46d99f | ATT-27, ATT-28, ATT-33, ATT-40, NTF-22, RBAC-37 |
| R9 | OT only with manager pre-approval | An approved regularisation that pushes the day over the threshold files **and auto-approves** the OT under the same HR reviewer | 1200e94 | PAY-02 |
| R10 | No statutory deductions | Indian PF / ESI / Maharashtra PT / TDS estimate computed, only when the component flag and the per-employee flag are both on; no filing | User request | PAY-40 |
| R12 | HR Admin: no system config | HR holds settings, roles, company, AI, impersonation, import/export by permission; data purge and force-delete stay Super-Admin-only; HR cannot assign the HR Admin role | 94be01b, migration 2026_10_06_150527 | RBAC-23, SET-06, SET-09, EMP-11 |
| R13 | Six roles | Seventh role, Coordinator (attendance-exception alerts) | 2aded41 | RBAC-73 |
| R14 | Email only for 4 critical events; **bulk email excluded (§1.2)** | Per-event mail on/off, recipients, mutes, dedup; master kill switch; **Compose & Send broadcast** to selected/all staff | 420515c, 2a3b1c4, feat/mail-center | NTF-10, NTF-15 |
| R15 | Password minimum 8 | Production: 12+ chars, mixed case, number, symbol, breach check; forced first-login password change | Policy | SEC-11 |
| R20 | Free Office/WFH choice at clock-in | Seven work modes; WFH only with an approved WFH request | 13c0e17 | ATT-05, DSH-32 |
| R21 | Tables kpis / performance_reviews / kpi_scores | PerformanceCycle + templates/components + participant scores | bedbfe0 | PRF-16 |

Other deliberate rules that the code follows and the spec does not cover (recorded so they are not mistaken for gaps): UK holiday calendar as company default (R2), leave year 1 Jul – 30 Jun (R3), append-only leave ledger (R5), Sat–Sun weekly off (R11), `employees.manager_id → users.id` and "Director" = spec "Department Head" (R16), configurable profile fields + KYC (R17), payroll/payslip module switch (R18), holiday-work requests and holiday pay (R19), permission-based Activity Log (R22).

**Flag on R14:** the broadcast feature directly contradicts spec §1.2 ("bulk email blasts excluded"). It was built on request and is kept, but it is the one override that reverses an explicit exclusion rather than refining a rule.

## 4. Out of scope (spec §1.2)

| Item | Status in code |
|---|---|
| Third-party payroll platforms (Keka, greytHR) | Not present — OUT OF SCOPE |
| Slack, ClickUp, project-management integrations | **Nexflow (NexBridge) OT integration exists** — see decision D4 |
| Google Calendar integration | Not present — OUT OF SCOPE |
| Recruitment / ATS | Not present — OUT OF SCOPE |
| Native mobile app | Not present (responsive web) — OUT OF SCOPE |
| UK statutory payroll (PAYE, NI) | Not present — OUT OF SCOPE (Indian statutory is R10) |
| Bulk email blasts | **Built** (broadcast) — INTENTIONALLY CHANGED, R14 |

## 5. Decisions needed before anything is changed

These behaviours are neither in the spec nor recorded as a deliberate decision. They were **not** marked as bugs or overrides, and nothing will be changed until you decide.

| # | Question | What the code does now | Rows |
|---|---|---|---|
| D1 | **What may a Director (Department Head) do?** Spec: view own department, approve own department, view department cost; no payslips. | Seeded Director holds manage/create/edit/delete employee, manage attendance, approve OT, `approve_finance`, `approve_payroll`, onboarding/offboarding. With no scope set (the default) a Director is **company-wide**: full employee CRUD, offboarding (locks people out), every salary on the Finance dashboard, the Executive dashboard, company salary-raise proposals in the Increment Center, and finance approval of payroll. Another session has an uncommitted `department_head` role + data scope that addresses part of this. | EMP-15, EMP-44, ATT-34, ATT-39, PAY-69, PAY-75, PRF-21, DSH-15, NTF-52, RBAC-24…34, LV-55 |
| D2 | Does an approved leave need HR approval after the manager? | Manager approval only moves the request to `pending_hr`; HR must approve again. Not in spec or register. | LV-27 |
| D3 | Does an encashment need Finance's final approval after Director/HR? | Director/HR approval moves it to `pending_finance`; Finance approves; CSL is debited only then. | LV-36, LV-37 |
| D4 | Is the Nexflow OT integration approved policy? | Nexflow-approved OT becomes payable approved OT with no Pulse approver (`reviewer_id` null). Conflicts with §1.2 (no PM integrations) and §3.4 (manager pre-approval). | PAY-03 |
| D5 | Should non-critical events email by default? | R14 lets HR switch mail per event, but the default is **on** for almost every event and the gate fails open, so staff get email for leave, OT, regularisation, attendance, documents, probation, etc. Spec: in-app only. | NTF-10, NTF-16, ATT-44, LV-31, EMP-64 |
| D6 | Keep the OT "window" gate? | OT requests are refused unless HR/Director has opened an OT window for the date. | PAY-04 |
| D7 | Is CSL auto-credit 1 day/month confirmed? | A scheduled job credits CSL monthly; a code comment says "HR-confirmed", but R4 says "no automatic crediting until HR chooses" and the schedule is hard-coded. | LV-04 |
| D8 | When an item is approved after its month's payroll has run, should it be paid in the next run (arrears)? | Reimbursements, incentives, encashments and late-settled OT match their month exactly and are **never paid** if the run already happened. | PAY-14, PAY-42, PAY-43, PAY-44, LV-38 |
| D9 | Should the employee's self-score count in the final rating? | Self score is weighted about 20% (team lead 20 / head 50 / additional 30 defaults). Spec: composite from KPI weights. | PRF-10 |
| D10 | Keep the automatic jobs that act without a human? | `hrms:issue-late-warnings` auto-issues disciplinary letters; `leave:purge-attachments` deletes leave evidence 30 days after approval; auto-flagged absences are stored as **approved** unpaid leave without HR review. | NTF extensions, DSH-07, DSH-35 |

**D10 decision (8 Oct 2026) and its one exception (9 Oct 2026).** Automatic jobs may not make disciplinary/leave/payroll decisions, except the approved missing-checkout fallback which may close an open attendance day at scheduled shift end with a system/audit marker.
The fallback is `hrms:auto-checkout` (after 11 PM Asia/Kolkata, today and yesterday only, never a backfill): a day with a valid IN and no valid final OUT is closed at the employee's assigned shift end on the attendance row (`is_auto_checkout`, reason `no_final_checkout`, audit `ATTENDANCE_AUTO_CHECKOUT`). It never writes a punch, never touches a day with a genuine OUT, an HR-corrected / regularised day or settled payroll, never creates payable overtime, and a later genuine OUT or an approved regularisation supersedes it. The Attendance Score auto-checkout penalty stays.

## 6. Priority fix list

Ordered by impact. "Decision" means it waits on §5.

| # | Risk | Issue | Rows |
|---|---|---|---|
| 1 | **H** | Every manager or Director leave approval tells the employee and HR "Leave Rejected" (the `pending_hr` status falls into the reject branch of the notification). | LV-29 |
| 2 | **H** | Unauthenticated `/iclock` (ADMS) endpoint: anyone who knows a device serial can write attendance. No auth, throttle or IP check. Unchanged since the last audit. | SEC-05 |
| 3 | **H** | Director over-reach (company-wide CRUD, offboarding, salaries, increments, payroll approval). **Decision D1.** | see D1 |
| 4 | **H** | Approved money never paid: reimbursements (H), late OT, incentives, encashments (**decision D8** for the arrears rule); OT/incentive/reimbursement eligibility defaults **off** for new hires with no screen to change it (that part is a plain bug). | PAY-15, PAY-43, PAY-14, PAY-42, PAY-44 |
| 5 | **H** | HRA is silently ₹0: no screen sets `hra_enabled`. | PAY-38 |
| 6 | **H** | Finance sign-off can be bypassed: HR can build a payroll approval chain with no Finance step; payslips can be edited after submission without resetting approvals. | PAY-33, PAY-34, SET-07 |
| 7 | **H** | Moving an employee between salary cycles is immediate → double pay or a gap. | PAY-26 |
| 8 | **H** | Incentives are approved by HR/Finance, not the Director; the Director cannot reach the page. | PAY-49, PAY-48 |
| 9 | M | Regularised days drop later genuine punches (hours/OT under-counted). Fix exists **uncommitted and untested**. | ATT-29 |
| 10 | M | Login email of an HR Admin / Finance user can be repointed by any in-reach `manage_employees` holder, then taken over by password reset. | RBAC-79 |
| 11 | M | Leave and regularisation attachments (medical certificates) and photos are on the public disk, downloadable from `/storage`. | SEC-08, EMP-60 |
| 12 | M | Employees see, download and email **draft** payslips; YTD totals include drafts. | PAY-57 |
| 13 | M | Leave can be charged on public holidays / MDL (reviewer date edits, leave regularisation); a half-day flag on a multi-day range charges 0.5 days total. | LV-12, LV-20, LV-23 |
| 14 | M | `hrms:clear-demo-data` truncates `audit_logs` with no production guard (spec: retain indefinitely). | AUD-10 |
| 15 | M | Access not revoked for resigned / terminated / archived staff without an exit record; inactive + archived_at never set after the last working day. | EMP-34, EMP-12, EMP-39 |
| 16 | M | Comp Off: MDL worked via biometric earns nothing; a web clock-out on a UK holiday earns CO **and** holiday overtime / double pay. | LV-13, LV-14 |
| 17 | M | Payroll submit sends a non-queued notification inside the DB transaction — an SMTP failure rolls back the submission. | NTF-28 |
| 18 | M | Executive dashboard shows invented metrics ("Satisfaction", "Company Health") and an Apr–Mar year; required §5.1 KPIs missing. Finance dashboard is entirely hidden. | DSH-14, DSH-04, DSH-06, DSH-11, DSH-24…30 |
| 19 | M | Performance: self-review order not enforced, feedback/promotion flag not captured, scores not normalised, attendance KPI ≈100%, deadlines ignored. | PRF-07, PRF-09, PRF-10, PRF-11, PRF-12 |
| 20 | M | Encash button on the CSL card blocks leave applications (fix uncommitted). | LV-33 |
| 21 | M | Finance has no attendance or leave summary (§4.1), no exit-settlement screen, no OT verification step. | RBAC-49, RBAC-50, RBAC-55, PAY-13 |

## 7. Changes since the 4 October audit

Fixed since then (prior status → now PASS), among others: late at minute precision (A-02); late-arrival manager digest (A-03, E21); breaks no longer deducted (A-06); per-shift missing-checkout cutoff (A-09, S01); regularisation validation (A-13); device sync no longer overwrites approved corrections (A-21); unknown salary cycle now throws (C-02); cycle selector and payroll membership sync (C-03, C-04); salary-date boundary (C-07); re-run keeps inclusions (C-10); historical import refuses open periods (C-43); self-pay guards (C-46); document policy enforced in the controller (D-07); `/settings/general` guarded (F-H1); manager dashboard scope (F-M1); biometric report scope (F-M3); most Livewire holes in appendix G (H-1 … M-6); probation / new-hire / document-expiry reminders (E-10, E16, E18, S06, S08); notification pruning (S10); auto-flagged absences skip holidays (X01); MDL dates on the employee dashboard (S-04).

Newly found (not in the prior audit): leave "Rejected" notification (LV-29); payroll approval chain without Finance (PAY-33); payroll submit rollback on SMTP failure (NTF-28); regularised days dropping later punches (ATT-29); cycle move double pay (PAY-26 — previously PASS); excess-break UK timing (ATT-12); login-email takeover (RBAC-79); audit-log truncation (AUD-10).

Still open from the prior audit: ADMS endpoint (F-H3 → SEC-05); Director on the Finance dashboard (F-M2 → RBAC-28); own email change / self-delete (F-L7 → RBAC-61); API throttle (F-L8 → SEC-06); payslip drafts visible (C-33 → PAY-57); manager onboarding tasks and Finance exit settlement (RBAC-43, RBAC-55).

Each reviewer's line-by-line reconciliation is in the findings file.

## 8. Things this audit could not verify

- Production role/permission rows, Directors' `scope_departments` / `scope_shifts` (Nikita's UK-shift scope), shift settings, MDL dates, salary-cycle pay days.
- Server crontab, queue worker and `QUEUE_CONNECTION`, `SESSION_LIFETIME` (code default 480, `.env.example` 120), HTTPS redirect and secure cookie, backup cron and off-server copy (backups currently stay on the same server and include `.env`).
- Which `staging` commits are deployed.

## 9. Uncommitted work that changes findings

Judged at HEAD; if these land, re-check the listed rows.

| Work (other sessions unless noted) | Affects |
|---|---|
| `department_head` role, DataScope / ScopeResolver, per-user permission overrides | D1 rows, RBAC-24…27, RBAC-77, EMP-07 (department head assignment UI) |
| Dashboard landing / DepartmentDashboard rebuild, KPI page gates | DSH-01, DSH-15, DSH-22, PRF-03 |
| My Time Off encashment-form fix | LV-33 |
| RouteAccess `module:` check | HLP-03 |
| Regularised-timeline fix (this session; untested — MySQL was down) | ATT-29 |
