# NEXCORE / Pulse HRMS — Pre-Execution Sync

**Sync type:** Documentation and committed UI/navigation comparison only.  
**Sync date:** 8 October 2026 (IST)  
**Branch:** `staging`  
**Tested commit:** `02cf1f3d586e79177fc744314c1c6450eb4469ee`  
**Build date:** `2026-10-08 12:07:03 +05:30`  
**Database / fixture baseline:** `DatabaseSeeder` baseline; QA Matrix v1.0. **Read-only migration status at Wave 1 found the local database behind the build:** `2026_10_07_124339_add_soft_deletes_to_attendance_regularisations_table` and later migrations are pending, including D1-related migrations. The environment is not an execution-ready representation of the tested commit.

## Scope and integrity finding

The committed build above is the only baseline represented in this record. The working tree contains additional uncommitted application changes and tests; they are excluded from the tested-build claim and from D1–D10 implementation confirmation. The sync itself did not change a test result. Wave 1 subsequently found the schema blocker recorded in `bugs/NEXCORE-BUG-001-super-admin-dashboard-schema-mismatch.md`; no further manual execution may proceed until it is resolved and retested.

## D1–D10 committed-build status

| Decision | Committed-build status | QA handling |
|---|---|---|
| D1 — Director department scope | **Implemented in committed baseline**. Commit subject and migration add Department Head/per-permission scope; landing can use `/dashboard/department`. | Keep department-isolation, direct-URL and Department View screenshots required. Human execution required. |
| D2 — Manager final standard leave | **Not confirmed in this commit.** | Keep expected final Manager approval wording; execute in Wave 4 only. |
| D3 — Director OR HR final encashment | **Not confirmed in this commit.** | Keep expected behaviour; execute in Wave 4 only. |
| D4 — Nexflow creates pending Pulse OT | **Not confirmed in this commit.** | Keep expected behaviour; execute in Wave 3 only. |
| D5 — non-critical email default off | **Not confirmed in this commit.** | Keep expected behaviour; execute in Wave 5 notifications coverage. |
| D6 — no mandatory normal OT window | **Not confirmed in this commit.** | Keep expected behaviour; execute in Wave 3 only. |
| D7 — CSL auto-credit decision | **Not a confirmed D1–D10 implementation requirement for this sync.** | Remains a decision/known-risk item; do not infer a result. |
| D8 — late monetary items move to next open payroll | **Not confirmed in this commit.** | Keep arrears wording; execute in Wave 5 only. |
| D9 — self-score default 0% | **Not confirmed in this commit.** | Keep expected behaviour; execute in Wave 6 only. |
| D10 — jobs require human approval for prohibited outcomes | **Not confirmed in this commit.** | Keep expected behaviour; execute in Wave 6 only. |

## UI/navigation reconciliation

| QA document area | Committed UI/navigation observation | Sync update |
|---|---|---|
| Role landings | `/` is the entry route. It can render the company/HR/self-service dashboard or forward authorised users to Director, Department, Team, or Finance dashboards. | Retained role-specific landing test; added committed dashboard routes. |
| Department Head | Sidebar/landing label is **Department View**; route is `/dashboard/department`. | Updated Test Data Matrix and Master route inventory. |
| Manager / Finance | Current sidebar labels are **Team View** and **Finance View**; routes are `/dashboard/manager` and `/dashboard/finance`. | Added labels to Master route inventory; retain full-dashboard screenshots. |
| Sidebar visibility | Navigation is permission- and route-access-driven. Groups with no accessible item are absent; employee self-service menu is separately configurable. | Wave 1 must capture visible and hidden modules for every role; direct URL remains mandatory. |
| Audit log | Current route is `/settings/audit-log`, not `/audit-logs`. | Corrected Master route inventory. |
| Screenshot policy | No committed visual/layout change requiring a screenshot-policy change was identified from the navigation comparison. | Existing mandatory screenshots retained. |

## Known baseline failures / risks

These are open risks from the 8 October gap report, not test results for this build. They remain **NOT TESTED** until manual evidence exists:

- Non-D1 decisions D2–D10 are not confirmed by the cited commit.
- **BLOCKER — BUG-001:** the Super Admin landing dashboard throws SQLSTATE `42S22` because the running model filters `attendance_regularisations.deleted_at`, but that column is absent while the required soft-delete migration is pending.
- The gap report’s payroll, document-protection, attendance/regularisation, notification, dashboard, performance, and lifecycle findings require their assigned later waves.
- Environment database migration state, fixture freshness, role permission rows, department assignments, and module switches are unverified.
- A local working tree is dirty; its uncommitted application changes cannot be used as release evidence.

## Known intentionally deferred / excluded items

- Manual mutation testing of payroll, attendance/biometric, leave, OT, employee lifecycle, settings, imports, purge, and scheduler actions is excluded from production. Production may use only the reduced smoke checklist.
- Out-of-scope product integrations remain excluded unless separately authorised: third-party payroll platforms, Slack/ClickUp/project-management integrations, Google Calendar, recruitment/ATS, native mobile app, and UK statutory payroll.
- No additional intentionally deferred D1–D10 implementation is asserted for commit `02cf1f3`; uncommitted changes are not a deferral decision.

## Wave 1 execution ledger — started, not executed

Wave 1 is prepared for a human tester using the tested environment and the ten accounts in the Test Data Matrix. It must stop before Wave 2 if a BLOCKER or CRITICAL access/security issue is found.

| Wave 1 check | Related case | Actual Result | Status | Screenshot | Tester | Date | Browser | Viewport | Bug ID |
|---|---|---|---|---|---|---|---|---|---|
| Valid login / invalid and inactive denial | TC-AUTH-001, TC-AUTH-002 |  | NOT TESTED | Required |  |  |  |  |  |
| Role-specific landing page — Super Admin | TC-DSH-001 / TC-E2E-007 | Login completed; `GET /` returned an Internal Server Error: SQLSTATE[42S22] unknown column `attendance_regularisations.deleted_at`, from `OrganisationOverview::approvals()`. | FAIL — BLOCKER | Error trace supplied; screenshot not captured | Not provided | 2026-10-08 | Chrome 154.0.0.0 | Not captured | BUG-001 |
| Sidebar/navigation and visible/hidden modules | TC-NAV-001, TC-RBAC-001 |  | NOT TESTED | Required |  |  |  |  |  |
| Direct forbidden URLs / safe 403 | TC-RBAC-001, TC-RBAC-002 |  | NOT TESTED | Required |  |  |  |  |  |
| Basic dashboard scope | TC-DSH-001 |  | NOT TESTED | Required |  |  |  |  |  |
| Logout and post-logout access | TC-AUTH-002 |  | NOT TESTED | Required |  |  |  |  |  |

**Wave 1 stop rule:** If an executed check exposes another user’s sensitive data, grants forbidden module/action access, allows authentication bypass, or otherwise meets BLOCKER/CRITICAL severity, create a bug report with full-page and focused screenshots, stop Wave 1/later waves, and obtain triage before resuming.

**Current Wave 1 state:** **PAUSED FOR MANUAL RETEST.** The required migration has been applied to the local `hrms` test database, but the Super Admin landing page must be re-run in a browser and evidence captured before Wave 1 resumes. Remaining Wave 1 rows stay NOT TESTED.
