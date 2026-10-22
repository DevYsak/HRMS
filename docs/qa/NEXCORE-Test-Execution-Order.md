# NEXCORE / Pulse HRMS — Manual Test Execution Order

**Baseline:** All cases remain **NOT TESTED** until a manual tester records an actual outcome.  
**Entry gate:** A tested development commit/build is supplied; exact commit hash/build is recorded in the Master QA/UAT Test Plan; final D1–D10 implementation is compared against the test wording; route/menu labels are updated only where the final UI changed. Automated test success never changes a manual case to PASS.

## Pre-execution gate

1. Record build hash, deployment URL, database/seed reference, timezone, browser versions, and tester in the Master Plan and Execution Summary.
2. Build the accounts and fixtures in `NEXCORE-Test-Data-Matrix.md`; verify each role can sign in.
3. Reconcile final behaviour with D1–D10: Director scope, manager-final standard leave, Director-or-HR final encashment, pending Nexflow OT, non-critical email off by default, no mandatory normal OT window, next-open-cycle arrears, 0% default self-score, and human approval for automated outcomes.
4. Update only changed UI/navigation wording in the test cases; preserve test IDs, evidence naming and NOT TESTED status.
5. Confirm production is excluded from all destructive/mutating scenarios.

## Wave 1 — Smoke

**Purpose:** establish that every role can reach a safe, correct starting point.

- Login, logout, wrong password, inactive-account denial, and first-login/reset path where provisioned.
- Each role dashboard, sidebar, landing redirect, breadcrumb and basic navigation.
- At least one forbidden direct URL per role; capture safe 403/redirect.
- Basic employee own/profile directory access and Employee A ↔ Employee B isolation probe.
- Block the run if authentication, any role landing page, or server-side access denial leaks data.

## Wave 2 — RBAC

**Purpose:** prove effective permissions, not menu visibility.

- Full role × module × action × scope matrix from the Master Plan.
- Director A versus Director B department data; Manager A versus Manager B direct-report data; Coordinator assigned-exception limits.
- Direct URLs, copied deep links, changed IDs, filtered URLs and hidden-action server denial.
- Role/permission change, user-specific override, refresh/session behaviour and effective permission re-check.
- Finance, HR Admin and Super Admin boundaries including Super-Admin-only destructive controls (do not execute destructive actions).

## Wave 3 — Attendance

**Purpose:** validate source timeline through downstream OT without production devices.

- Biometric simulator: Face IN, Card OUT, duplicate/stray, 60-second burst and shift-specific timing.
- Punch timeline, worked-hours canonical rule, late, weekly-off, holiday and missing checkout.
- Break start/end, excess break, open break at clock-out and UK-shift timing.
- Regularisation submit/HR approval/reject/edit/revert; later genuine biometric punches must remain.
- OT interaction, missing final OUT, regularisation-created OT and holiday OT.

## Wave 4 — Leave

**Purpose:** validate entitlement, calendar and approval decisions.

- CSL, MDL and Comp Off balance, request, overlap, half-day, holiday/weekly-off and correction behaviour.
- Standard leave Manager approval is final; notification says Approved after approval.
- HR override/cancellation/rejection with ledger/audit reconciliation.
- Encashment insufficient balance, Director-or-HR final approval, single debit, rejection and arrears inclusion.

## Wave 5 — Payroll / Finance

**Purpose:** reconcile money flow in isolated test periods.

- Eligibility switches, HRA, OT, incentives, reimbursements, encashment, statutory deductions and LOP.
- Cycle A/B draft, Finance approval mandatory, finalisation/lock and submitted-payslip edit behaviour.
- Late OT/reimbursement/incentive/encashment arrears: original period retained, next open payroll, paid once.
- A↔B salary-cycle pending/cancel/transition; no duplicate/missing pay, one payslip monthly, no pro-rating expectation.
- Draft payslip denial and final-payslip self-service/download scope.

## Wave 6 — People Operations

**Purpose:** validate people lifecycle and confidential content.

- Onboarding task ownership/visibility; employee, Manager, HR and Finance workflows.
- Offboarding records/tasks, exit settlement visibility and access revocation.
- Documents: version, acknowledgement, expiry, KYC/evidence access and guessed/copied URLs.
- Performance/KPI: cycle, ordering, weights, normalisation, 0% self-score default, feedback/promotion/history.

## Wave 7 — UI / Responsive

**Purpose:** validate usable layouts after workflow correctness is established.

- Desktop 1920×1080 and 1366×768; tablet 768×1024; mobile 390×844.
- Sidebar/header, spacing, typography, cards, tables/filters/pagination, drawers/modals, forms/buttons and long names.
- Empty/loading/error states, validation placement, 403/404/419/422 and approved controlled staging 500 handling.
- Attach full page and focused-area screenshots for every visual defect.

## Wave 8 — Help & Guide

**Purpose:** prove a user can complete their permitted journey using the guide.

- Execute Help & Guide as every role.
- Open every visible guide route/link for that role.
- Compare label, instruction, screenshot, business rule and navigation destination with the deployed UI.
- Verify no forbidden page is promoted and each role’s end-to-end journey is covered.

## Wave 9 — Final regression

**Purpose:** establish release evidence.

- Re-run every FAIL after its documented fix and all BLOCKED cases when unblocked.
- Re-run Wave 1 smoke and cross-role/direct-URL security checks after RBAC/navigation changes.
- Confirm screenshot naming, actual result, tester/date/browser/viewport and bug links for each executed case.
- Update execution totals and severity counts; list accepted risks separately from PASS.
- Recommend UAT approval only after all Critical cases are PASS or formally risk-accepted by accountable stakeholders.

## Production reduced smoke checklist — read-only / safe

Production verification requires release owner approval and a named low-privilege test account. Do **not** use production for payroll generation/finalisation, attendance/biometric writes, leave/OT submission or approval, salary-cycle changes, lifecycle/offboarding changes, uploads, settings, user overrides, scheduler triggers, imports, purge, or delete actions.

| Check | Safe production action | Expected evidence |
|---|---|---|
| Release identity | View release/version/commit through approved deployment source | Matches tested build |
| Login and logout | Sign in/out with approved QA/read-only account | Safe landing and session termination |
| Role navigation | Open dashboard, sidebar and allowed read-only pages | Correct menu and no rendering error |
| Access denial | Open one known forbidden route in private window | Safe denial/no sensitive content |
| Own-data scope | View only account’s own profile/dashboard/notifications | No other person’s private data |
| Help links | Open visible Help & Guide links | Routes load and labels are current |
| Responsive spot-check | Approved read-only pages at desktop/mobile | No blocking layout issue |
| Observability | Check approved monitoring/log dashboard only | No release-critical error spike |

Record production results separately in the Test Execution Summary as **Production-read-only**; never merge them with staging mutation evidence.
