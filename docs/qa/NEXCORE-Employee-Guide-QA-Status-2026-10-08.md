# NEXCORE / Pulse HRMS — Employee Guide QA Status (8 Oct 2026)

Scope: `/help/employee-guide` and `/help/getting-started`. Documentation QA only; no unrelated application fixes were made in this session.

## Status

| Check | Result |
|---|---|
| Employee Guide automated tests (`EmployeeGuideTest`) | PASS |
| Sidebar navigation tests (`SidebarMenuTest`) | PASS |
| Combined | 35 tests / 1,484 assertions |
| Screenshot generation (`npm run guide:screenshots`) | PASS, 32 screenshots |
| Role link-level access (all links open, no 403/404/5xx) | PASS, all 8 roles |
| Real QA-account end-to-end role walkthrough | **PENDING** |
| Production verification | **NOT STARTED** |

Roles covered at link level: Super Admin, HR Admin, Director, Department Head, Manager, Finance, Coordinator, Employee. Link-level means HTTP responses in the test suite, not a human walkthrough of the documented actions.

Git: `f5ec960` and `ddd2ee0` are already in `origin/staging`. The 8 Oct guide changes are **uncommitted** (see Commit gate).

## Defects

**BUG-GUIDE-001**
**Title:** Profile "Leave Balance" uses the wrong aggregate
**Module:** My Profile / Leave
**Role:** Employee
**Environment:** Local (throwaway `hrms_guide` database)
**Severity:** Medium  **Priority:** P2
**Test Data:** Fictional demo employee `GUIDE-0001` (`guide.employee@example.com`)
**Steps to Reproduce:**
1. Log in as the demo employee.
2. Open My Profile; note the Leave Balance card.
3. Open Leave (My Time Off); note Available to request.
**Expected:** The profile summary uses the same canonical leave-balance source and policy as the Leave module.
**Actual:** Profile shows "Leave balance 219 Days available" (it sums every leave type, including maternity, paternity and bereavement) while the Leave module shows 1 day available to request (CSL).
**Frequency:** Always
**Screenshot:** `public/images/employee-guide/profile-overview.jpg`
**Notes:** Likely in `app/Livewire/Profile/Concerns/ShowsProfileSummary.php`. **Do not fix inside the guide session.**
**Triage owner / status:** Open, unassigned (development session)

**BUG-GUIDE-002**
**Title:** `guide:demo-employee` seeds obsolete leave types and data
**Module:** Employee Guide tooling
**Role:** n/a (developer tooling)
**Environment:** Local / testing only
**Severity:** Low  **Priority:** P3
**Steps to Reproduce:**
1. Run `php artisan guide:demo-employee` on a fresh database.
2. Open Leave as the demo employee.
**Expected:** A fresh screenshot/demo setup deterministically produces current-policy fictional data (CSL, MDL, Comp Off).
**Actual:** It creates old Annual Leave and Sick Leave requests and balances. The 8 Oct screenshots were taken after the dataset was prepared by hand (CSL policy applied, joiner back-dated, 3 CSL days credited, requests recreated through the leave service), so a from-scratch re-run will not reproduce them.
**Frequency:** Always
**Notes:** **Do not fix until the owning development session takes it.**
**Triage owner / status:** Open, unassigned (development session)

## Throwaway database

`hrms_guide` is a throwaway QA database only. It must never be treated as production or migration truth. Keep it only if another screenshot rerun is expected; otherwise drop it later (`DROP DATABASE hrms_guide;`, local MySQL only).

## Shared-tree contamination (Pint)

`vendor/bin/pint --dirty` reformatted files owned by other sessions: `app/Livewire/Employees/EmployeeEdit.php`, `app/Livewire/Overtime/MyOtRequests.php`, `tests/Feature/ConexusCslAccrualTest.php`, `tests/Feature/OtPreApprovalPolicyTest.php`, `tests/Feature/Payroll/SalaryCycleTransitionTest.php`. The changes are formatting only. They were **not reverted**, because they sit alongside other sessions' uncommitted work. The owning development sessions must reconcile them before commit.

## Commit gate

Do not commit or push guide files until all three hold:
1. The owning development session confirms the shared working tree is safe.
2. Guide-only files can be staged explicitly.
3. No unrelated session files are included.

When ready, stage guide files by explicit path only. Never use `git add .`.
