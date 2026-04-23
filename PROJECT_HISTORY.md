# Pulse by Conexus — Project History

> **AI Session Guide**: Always read this file first. It is the canonical source of truth for project state.

---

## Current Status

| Phase | Description | Status |
|-------|-------------|--------|
| Phase 0 | Foundation, DB schema, design system, auth, core layout | ✅ **COMPLETE** |
| Phase 1 | Employee Management (full CRUD, directory, org-chart) | ✅ **COMPLETE** |
| Phase 2 | Leave / Time Off module | ✅ **COMPLETE** |
| Phase 3 | Attendance (GPS-verified clock-in, geo-fence) | ✅ **COMPLETE** |
| Phase 4 | Payroll (salary engine, payslips) | ✅ **COMPLETE** |
| Phase 5 | Performance & Recruitment | ✅ **COMPLETE** |
| Phase 6 | Super Admin SaaS Shell | 🔲 Next |

---

## Tech Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Language | PHP | 8.3 |
| Framework | Laravel | 13 |
| Reactive UI | Livewire | 4 (SFC/MFC) |
| Component Library | Flux UI **Free** | 2 |
| CSS | TailwindCSS | 4 (CSS-first `@theme`) |
| Auth | Fortify | 1 |
| DB | MySQL | `hrms` database |
| Testing | Pest | 4 (MySQL `hrms_test` — SQLite NOT available) |
| Assets | Vite | 8 |

---

## Architecture Decisions

### Single-Company Mode
The platform installs as a **single-company** HRMS (no multi-tenancy per-session).
The team routing prefix `{current_team}` has been removed from all app routes.
- `LoginResponse`, `RegisterResponse`, `TwoFactorLoginResponse` — simplified, redirect to `route('dashboard')` directly.
- `SetTeamUrlDefaults` middleware is harmless (only fires when `current_team` is set, which it never is in our routes).

### RBAC
- `UserRole` enum: `Admin | Hr | Manager | Employee`
- Permission helpers on the `User` model: `isAdmin()`, `isHr()`, `isManager()`, `canManageEmployees()`, `canApproveLeave()`, `canRunPayroll()`, `canManageSettings()`
- All helpers are null-safe (return `false` if role is null)

### Employee Identity
> **Decision pending** for Phase 1: Does every `User` have an `Employee` profile (separate `employees` table), or is the `User` model the employee record extended via migration? Lean toward a separate `employees` table with `user_id` FK for clean separation.

### Flux UI Free vs Pro
- `flux:table`, `flux:columns`, `flux:rows`, `flux:cell` are **Flux Pro** components — **not available** in this install.
- Use **plain HTML `<table>`** for data tables.
- Free components available: buttons, inputs, badges, modals, dropdowns, avatars, sidebar, toasts, icons, links, etc.

---

## Database Schema (Phase 0)

### Tables

| Table | Purpose |
|-------|---------|
| `users` | Auth + employee user accounts (extended with `avatar`, `role` ENUM, `theme` ENUM) |
| `companies` | Company settings (single record in production) |
| `offices` | Office locations linked to company |
| `departments` | Departments linked to company |
| `job_titles` | Job title definitions linked to company |

### Enums
- `App\Enums\UserRole` — `admin | hr | manager | employee`
- `App\Enums\ThemePreference` — `light | dark | system`

---

## File Map (Phase 0 deliverables)

### PHP
| File | Role |
|------|------|
| `app/Enums/UserRole.php` | RBAC enum with helper methods |
| `app/Enums/ThemePreference.php` | Theme preference enum |
| `app/Models/User.php` | Extended with role/theme casts, permission helpers |
| `app/Models/Company.php` | Company model |
| `app/Models/Office.php` | Office model |
| `app/Models/Department.php` | Department model |
| `app/Models/JobTitle.php` | Job Title model |
| `app/Http/Responses/LoginResponse.php` | Simplified — redirects to `dashboard` |
| `app/Http/Responses/RegisterResponse.php` | Simplified — redirects to `dashboard` |
| `app/Http/Responses/TwoFactorLoginResponse.php` | Simplified — redirects to `dashboard` |

### Factories
| File | Seeds |
|------|-------|
| `database/factories/CompanyFactory.php` | Company |
| `database/factories/OfficeFactory.php` | Office (with `headquarters()` state) |
| `database/factories/DepartmentFactory.php` | Department |
| `database/factories/JobTitleFactory.php` | JobTitle |
| `database/factories/UserFactory.php` | **Updated** — default role `UserRole::Employee` |

### Views
| File | Description |
|------|-------------|
| `resources/views/layouts/app/sidebar.blade.php` | Main app shell — dark sidebar + topnav |
| `resources/views/layouts/auth/split.blade.php` | Auth shell — photo left + form right |
| `resources/views/partials/head.blade.php` | Meta, Inter font, Vite |
| `resources/views/components/app-logo.blade.php` | Pulse brand logo (sidebar/auth variants) |
| `resources/views/components/coming-soon.blade.php` | Stub for unbuilt modules |
| `resources/views/dashboard.blade.php` | Dashboard — stat cards, chart, table, donut |
| `resources/views/pages/auth/login.blade.php` | Figma-matching login form |
| `resources/views/pages/employees/*.blade.php` | Stubs (index, create, directory, org-chart, show) |
| `resources/views/pages/time-off/*.blade.php` | Stubs (my, team, employees, settings) |
| `resources/views/pages/attendance/*.blade.php` | Stubs (my, team, employees, settings) |
| `resources/views/pages/payroll/*.blade.php` | Stubs (my, index) |
| `resources/views/pages/settings/general.blade.php` | Stub |

### CSS
| File | Description |
|------|-------------|
| `resources/css/app.css` | TailwindCSS v4 `@theme` tokens, `@layer utilities` — brand green `#1DB77A`, custom utility classes |

### Routes
| File | Covers |
|------|--------|
| `routes/web.php` | 19 routes: dashboard, employees, time-off, attendance, payroll, settings |
| `routes/settings.php` | Profile, appearance, security, teams (existing) |

### Tests
| File | Description |
|------|-------------|
| `tests/Feature/DashboardTest.php` | 2 tests — guest redirect + auth access |
| `tests/Feature/Auth/AuthenticationTest.php` | 5 tests — login, logout, 2FA |
| `phpunit.xml` | MySQL (`hrms_test` db), BCrypt rounds=4 |
| `tests/Pest.php` | RefreshDatabase enabled globally for Feature |

---

## Seeded Demo Data

| Type | Records |
|------|---------|
| Company | Conexus Technologies (Bangalore) |
| Offices | Head Office (Bangalore), Mumbai, Delhi NCR |
| Departments | Engineering, Product, Design, Marketing, HR, Finance, Operations, Sales |
| Job Titles | 21 titles across all departments |
| Users | admin@conexus.in (Admin), pristia@conexus.in (HR), rayna@conexus.in (Manager), test@example.com (Employee) |
| Passwords | All: `password` |

---

## Pending Decisions Before Phase 1

1. **Employee Profile Architecture**: Separate `employees` table vs. extending `users`?
2. **Employee ID Format**: `EMP-0001` auto-generated or manually assigned?
3. **Photo Upload**: Which disk driver (public/S3)? Currently `public` disk assumed.
4. **Registration Flow**: After login, does a new employee need onboarding wizard before accessing dashboard?

---

## Known Constraints

- **Flux UI Free** — No `flux:table` pro component. Use native HTML `<table>` elements.
- **SQLite not available** — Tests run on MySQL `hrms_test` database only.
- **Vite assets** — Dashboard and rendered views require `npm run build` to have run.
- **Team System** — The Boost starter has a teams concept; we're not using it but the `HasTeams` trait remains on `User` for compatibility. Do not delete team-related models unless explicitly approved.

---

## Change Log

### Session 2026-04-14 (Session 2)
- Created all Phase 0 migrations, enums, models, factories
- Rewrote sidebar layout with full HRMS navigation (dark theme matching Figma)
- Rewrote auth split layout and login page to match Figma
- Created all 19 module stub routes and stub pages
- Created production dashboard with stat cards, chart bars, HTML table, SVG donut chart
- Fixed `LoginResponse`/`RegisterResponse`/`TwoFactorLoginResponse` to remove team dependency
- Added `UserRole::Employee` default to `UserFactory`
- Added null-safety to User model RBAC helpers
- Fixed TailwindCSS v4 `@apply` — removed chained custom class references
- Configured `phpunit.xml` to use MySQL `hrms_test`; enabled `RefreshDatabase`
- Build: `npm run build` ✅ (257 KB CSS)
- Tests: 7 tests, 16 assertions, **all passing** ✅

### Session 2026-04-15 (Session 3) — Layout/Dashboard Bugfix & Model Polish

#### Bugs Fixed
- **Sidebar `flux:sidebar.group`** — was incorrectly using non-existent `x-slot name="items"` pattern on `flux:sidebar.item`. Rewrote to use correct Flux v2 free API: `<flux:sidebar.group heading="..." icon="..." :expandable="true">` with child `flux:sidebar.item` in the `$slot`.
- **`@fluxAppearanceMenu`** — directive does not exist in Flux v2. Replaced with correct `@fluxAppearance`. Also removed duplicate (already in `head.blade.php`).
- **Double `flux:main` nesting** — `layouts/app.blade.php` was wrapping the slot in `<flux:main>` AND each page added its own `<flux:main>`. Fixed `app.blade.php` to be a plain pass-through; each page manages its own `<flux:main>`.
- **`flux:button icon-trailing`** — wrong Flux v3 syntax. Corrected to `icon:trailing` (Flux v2 colon syntax).
- **`@props` missing from sidebar layout** — sidebar layout had no `@props` declaration for `$title`, causing undefined variable warnings. Added `@props(['title' => null])`.

#### Model Enhancements (Phase 1 Readiness)
- `Company` — added `headquarters()` helper method and `displayName()` formatter
- `Office` — added `scopeHeadquarters()` and `location()` helper
- `Department` — added `jobTitles()` HasMany relationship
- `JobTitle` — added `department_id` to `$fillable` and `department()` BelongsTo relationship

> **Note**: `job_titles` table does not yet have a `department_id` column — add migration at Phase 1 start before wiring this relationship.

#### Tests Added
- `tests/Feature/HrmsRoutesTest.php` — smoke-tests all 15 HRMS module routes as admin (200 OK) + employee role dashboard access

#### Final State
- Build: `npm run build` ✅ (256 KB CSS)  
- Tests: **61 tests, 149 assertions, all passing** ✅  
- Pint: ✅ no style violations

### Session 2026-04-15 (Session 4) — UI Overhaul to Match Figma

#### Root Causes Found
- `class="dark"` was hardcoded on `<html>` in sidebar layout — forced dark mode on every page, overriding `@fluxAppearance`
- Sidebar was built dark navy (`#0f1923`) but Figma uses a **white light sidebar**
- Flux default active item style = white card with gray border; Figma requires **green filled pill**
- Dashboard used bar SVG chart; Figma uses **smooth line chart**

#### Changes Made
**`resources/views/layouts/app/sidebar.blade.php`**
- Removed `class="dark"` from `<html>` tag — allows `@fluxAppearance` to control theme
- Changed sidebar background from `bg-[#0f1923]` (dark navy) → `bg-white dark:bg-zinc-950`
- Updated all borders/text to proper light/dark variants (`border-zinc-200 dark:border-zinc-800`, etc.)
- Added keyboard shortcut `kbd="⌘ F"` on header search input
- Removed duplicate `@fluxAppearance` directive

**`resources/css/app.css`**
- Added CSS override: `[data-flux-sidebar-item][data-current]` → `background: #1DB77A !important` (green filled pill matching Figma)
- Added `.badge-*` utility classes for employee status (active, onboarding, probation, on-leave, resigned, terminated)
- Refined `.pulse-stat__trend-up/down` with proper pill backgrounds
- Added dark mode accent override block in `@layer theme`

**`resources/views/dashboard.blade.php`**
- Replaced bar chart SVG with smooth **SVG polyline line chart** (Project Team green + Product Team amber)
- Stat cards now have icon circles + trend pill badges (matching Figma)
- Employee table now has search input + Office/JobTitle/Status filter dropdowns
- Employee rows use status badge classes (`badge-active`, `badge-onboarding`, etc.)
- Donut chart refined with proper stroke-dasharray values

#### Final State
- Build: `npm run build` ✅ (260 KB CSS)
- Tests: **61 tests, 149 assertions — all passing** ✅
- Pint: ✅ no style violations

#### UI Notes for Next Session
- `flux:navlist` (settings sub-nav) IS available in Flux free — stubs confirmed
- Settings/profile renders correctly — left navlist + right form
- To switch between light/dark mode, users click the Flux appearance toggle in the profile dropdown

### Session 2026-04-15 (Session 5) — Phase 1: Employee Management Implementation

#### Architecture & Database
- Created `EmployeeStatus` and `EmploymentType` globally typed Enums.
- Created independent `employees` table mapped 1-to-1 with `User` (`user_id`). This cleanly separates HR data (joining date, managers, etc.) from basic auth data.
- Built `$table->foreignId` relationships for `office_id`, `department_id`, `job_title_id`, and `manager_id`.
- Rewrote `DatabaseSeeder.php` to seamlessly seed real dummy employee relationships with random assignment. Run `php artisan migrate:fresh --seed` to populate the environment.

#### Livewire HR UI Components
- Replaced the mocked views in `routes/web.php` with 5 fully wired Livewire Class components:
  - `App\Livewire\Employees\EmployeeIndex`
  - `App\Livewire\Employees\EmployeeCreate`
  - `App\Livewire\Employees\EmployeeEdit`
  - `App\Livewire\Employees\Directory`
  - `App\Livewire\Employees\OrgChart`
- Opted out of Single File / Volt components (`.blade.php` with `⚡` prefix) to guarantee stable dependency injection and standard routing behavior in Livewire 3/Laravel. Removed the auto-generated Volt files setup.
- **MethodNotFound Fix:** Used Alpine bindings (`x-on:click="Livewire.navigate(...)"`) in the index table rows to bypass Livewire 3 lack of a native `$navigate` action inside standard `wire:click`.

#### Views & Design UI
- Re-used and properly assigned the status badge pills (`badge-active`, `badge-resigned`, etc.) from previous sessions in the tables.
- Built a visual **Employee Directory** card grid with fallback profile initial circles + status ring overlays.
- Built a recursive **Organizational Chart** mapping line managers to their reports, displaying dynamic branches via a unified `org-node.blade.php` partial.

#### Tests 
- Tests for Phase 1 are still pending to cover all Livewire interactions and routes seamlessly. 

#### Final State
- Build: `npm run build` ✅
- Pint format: `vendor/bin/pint --dirty --format agent` ✅

### Session 2026-04-15 (Session 6) — Phase 2: Leave & Time Off Implementation

#### Database & Logic
- Created `LeaveType`, `LeaveBalance`, and `LeaveRequest` models and migrations.
- Implemented relationships: `User` -> `Employee` -> `LeaveBalances`/`LeaveRequests`.
- Developed `LeaveSeeder` to initialize standard categories (Annual, Sick, Casual, etc.) and generate test data.
- Built automatic balance deduction logic on request approval.

#### Premium UI Components
- Replaced stub routes with 4 new Livewire 3 components:
  - **My Time Off**: Personal dashboard with visual "Balance Progress Cards" showing days left/used per type. Includes a "Request Time Off" modal.
  - **Team Time Off**: Manager dashboard featuring "Pending Action" cards for direct reports. Includes a high-fidelity "Review Modal" for one-click approval/rejection with comments.
  - **Employee Leave Master**: Global HR audit table with advanced search and status filtering.
  - **Leave Settings**: CRUD interface for HR to manage leave categories with a custom UI color marker selector.

#### Final State
- Build: `npm run build` ✅
- Pint: `vendor/bin/pint --dirty --format agent` ✅
- Seeding: `php artisan db:seed --class=LeaveSeeder` ✅

### Session 2026-04-15 (Session 7) — Phases 3 & 4 (Attendance & Payroll) + System Audit

#### Phase 3: Attendance & Geo-fencing
- Built `Attendance` and `AttendanceSetting` models.
- Enhanced `Office` model with Latitude, Longitude, and Radius fields for geo-fencing.
- Implemented `MyAttendance` Livewire dashboard applying browser `navigator.geolocation` and the Haversine formula to verify location against office coordinates. Includes a live shift timer.
- Created `TeamAttendance` and `AllAttendance` for managerial and HR oversight.

#### Phase 4: Payroll System
- Created `SalaryComponent`, `EmployeeSalary`, `Payroll`, `Payslip`, and `PayslipItem` models.
- Set up flexible Earnings/Deductions via `Payroll\Components`.
- Developed `Payroll\Process` to bulk-generate drafts and finalize company-wide payroll processing monthly using database transactions.
- Created `Payroll\MyPayslips` with a premium, bank-standard layout featuring native browser-based `@media print` CSS for PDF exporting.

#### Phase 1-4 Complete Audit
- Rewired static Figma mockups inside `employee-edit.blade.php` Payroll tab to actively read and calculate dynamic `$employee->salaries` relationships.
- Verified and fixed sidebar paths (`payroll.payslips`) and permissions.
- Verified soft delete actions retain cleaner Figma interface.

#### Final State
- Build: `npm run build` ✅
- Pint: `vendor/bin/pint --dirty --format agent` ✅
- Seeding: Handled via `PayrollSeeder` and `AttendanceSeeder` ✅


### Session 2026-04-15 (Session 8) — Phase 5: Performance & Recruitment

#### Database & Logic
- Created migrations and models for Performance: `ReviewCycle`, `PerformanceReview`, `ReviewGoal`.
- Created migrations and models for Recruitment: `JobPosting`, `Candidate`.
- Updated `Employee` model to reflect `goals` and `performanceReviews` `HasMany` relationships.
- Designed `Phase5Seeder` to populate sample review cycles, goals, jobs, and candidates in the DB.

#### Livewire UI Components (Performance)
- `Performance\MyReview`: Self-assessment panel where employees fill out ratings, strengths, and improvements. Includes historical manager feedback views.
- `Performance\TeamReviews`: Dedicated view for Managers to evaluate direct reports per review cycle.
- `Performance\AllReviews`: Administrative view (HR) rendering all staff evaluations, cycle, and status.
- `Performance\ReviewCycles`: Cycle engine to broadcast self/manager evaluation forms automatically to all eligible active employees.
- `Performance\Goals`: Lightweight goal/OKR tracker using a minimalist kanban/checklist approach.

#### Livewire UI Components (Recruitment)
- `Recruitment\JobPostings`: Full applicant tracking module (ATS) job board. Tracks requirements, salaries, departments, and linked applicants.
- `Recruitment\Candidates`: Kanban-style candidate pipeline tracker separating by stages (Applied -> Screening -> Interview -> Offer -> Hired/Rejected), equipped with interactive candidate HR evaluation folders.

#### Sidebar & Routing Updates
- Attached 7 new routes under `performance.` and `recruitment.` namespaces inside `routes/web.php`.
- Replaced stub items inside `resources/views/layouts/app/sidebar.blade.php` with actual expandable `flux:sidebar.group` matching Phase 5 content.

#### Final State
- Build: `npm run build` ✅
- Seeding: `php artisan db:seed --class=Phase5Seeder` ✅

---

### Session 2026-04-16 (Session 9) — Production Fix: RBAC, Overtime, Notifications, Attendance, Leave, Payroll, Onboarding, Documents

#### Routing Fix
- Resolved `RouteNotFoundException` caused by two routes registered at `/` (public `welcome` vs. authenticated `dashboard`). Moved public route to `/welcome`.

#### RBAC Expansion
- Expanded `UserRole` enum with roles: `super_admin`, `hr_admin`, `director`, `manager`, `finance`, `employee`.
- Maintained backward-compatible aliases for existing `admin`, `hr` roles.
- Added `canApproveOt()`, `canApproveFinance()`, `canManageDocuments()` to `User` model.
- Created four Laravel policies: `EmployeePolicy`, `LeavePolicy`, `AttendancePolicy`, `PayrollPolicy`.

#### Overtime Module (Full — NEW)
- **Migrations**: `ot_requests` (pre-approval workflow), `overtime_records` (immutable payout audit).
- **Models**: `OtRequest`, `OvertimeRecord` with proper scopes and relationships.
- **Service**: `OvertimeService` — `submitRequest()`, `approve()` (creates record), `reject()`, `calculateOtHours()`, `getPendingOtAmount()`, `markAsPaid()`.
- **OT Rate**: ₹100/hr | Standard: 9 hrs/day.
- **Livewire**: `Overtime\MyOtRequests` (employee submit/cancel), `Overtime\ManageOtRequests` (manager approve/reject with review modal).
- **Routes**: `GET /overtime/my`, `GET /overtime/manage`.
- **Sidebar**: Clock icon group, Manage OT only visible to `canApproveOt()` users.

#### Notification System (Database Channel — NEW)
- Laravel `notifications` table was already migrated (database channel enabled).
- Created four queued notification classes (all `ShouldQueue`, `via: ['database']`):
  - `OtRequestNotification` — pending → manager | approved/rejected → employee.
  - `LeaveRequestNotification` — pending → manager | approved/rejected → employee.
  - `AttendanceRegularisationNotification` — pending/approved/rejected states.
  - `PayrollApprovalNotification` — submitted/finance_approved/processed events.
- `App\Livewire\Notifications`: Bell dropdown component — unread badge, color-coded icons, mark-single/mark-all-read, click-to-navigate.

#### Attendance Fixes
- **Migration** `2026_04_16_..._add_break_late_ip_to_attendances_table`: adds `check_in_ip`, `check_out_ip`, `break_start`, `break_end`, `break_minutes`, `is_late`, `late_minutes`, `missing_checkout`, `notes`.
- GPS geofencing removed → replaced with **IP address logging** (non-blocking, privacy-respecting).
- **Migration** `..._create_attendance_regularisations_table`: employee-initiated correction request with manager approval.
- **Model**: `Attendance` updated — `netHours()`, `computeLate()`, missing-checkout/late scopes, `regularisation` HasOne.
- **Model**: `AttendanceRegularisation` — pending scope, full relationships.
- **Scheduled command**: `hrms:flag-missing-checkouts` — runs daily at 08:00, flags yesterday's records with null `check_out`.
- **Employee relationships**: `regularisations()` HasMany added.

#### Leave Module Fixes
- **Migration** `..._add_leave_type_fields`: adds `category` (annual/sick/mdl/comp_off/encashment/unpaid/other), `allow_carry_forward`, `carry_forward_limit`, `allow_encashment` to `leave_types`; adds `carried_forward_days`, `encashed_days`, `year` to `leave_balances`.
- **Migration** `..._create_leave_escalations_table`: tracks 24-hour auto-escalation events.
- **Model**: `LeaveEscalation` with `leaveRequest()` and `escalatedTo()` relationships.
- **LeaveRequest** updated with `escalations()` HasMany.
- **Scheduled command**: `hrms:escalate-leaves` — runs hourly, finds un-escalated pending requests >24hrs old, creates `LeaveEscalation` records and notifies all HR admins.

#### Payroll Finance Approval
- **Migration** `..._add_finance_fields_to_payrolls_table`: adds `ot_amount`, `incentives`, `reimbursements`, `deductions`, `finance_approved_by`, `finance_approved_at`, `finance_note`.
- **Payroll model** updated: new fillable/casts, `financeApprovedBy()` relationship, `isPendingFinance()`, `isApproved()`, `computeTotal()` helpers.

#### Onboarding / Offboarding Module (NEW)
- **Migrations**: `onboarding_tasks` (phase: onboarding/offboarding, categories, completion tracking), `equipment_logs` (asset issuance/return), `exit_records` (full offboarding checklist).
- **Models**: `OnboardingTask`, `EquipmentLog`, `ExitRecord`.
- **Employee relationships**: `onboardingTasks()`, `equipmentLogs()`, `exitRecord()` added.
- **Routes**: `GET /employees/{employee}/onboarding`, `GET /employees/{employee}/offboarding`.
- **Livewire**: `Onboarding\OnboardingChecklist` — toggle completion, add/delete tasks, progress bar, works for both phases.

#### Document Management Module (NEW)
- **Migrations**: `documents` (versioning via `parent_id`, visibility scoping, acknowledgement flag, soft deletes), `document_acknowledgements` (unique per employee+document, IP audit).
- **Models**: `Document`, `DocumentAcknowledgement`.
- **Route**: `GET /documents` → `documents.index`.
- **Sidebar**: Documents link added under Operations (visible to `canManageDocuments()` users).
- **Livewire**: `Documents\DocumentManager` — file upload (20MB max), version tracking, search/category filters, employee acknowledge action, HR-only delete with Storage cleanup.

#### Architecture & Cleanup
- **Recruitment** routes commented out in `web.php` (reversible, code preserved). Sidebar group replaced with comment block.
- **Scheduled jobs** registered in `routes/console.php` with `withoutOverlapping()` and `runInBackground()`.
- **Pint**: all modified PHP files formatted ✅.

#### Migrations Run (this session)
| Migration | Status |
|-----------|--------|
| `create_ot_requests_table` | ✅ |
| `create_overtime_records_table` | ✅ |
| `add_break_late_ip_to_attendances_table` | ✅ |
| `create_attendance_regularisations_table` | ✅ |
| `add_leave_type_fields` | ✅ |
| `create_leave_escalations_table` | ✅ |
| `add_finance_fields_to_payrolls_table` | ✅ |
| `create_onboarding_tasks_table` | ✅ |
| `create_equipment_logs_table` | ✅ |
| `create_exit_records_table` | ✅ |
| `create_documents_table` | ✅ |
| `create_document_acknowledgements_table` | ✅ |

#### Updated Status Table

| Phase | Description | Status |
|-------|-------------|--------|
| Phase 0 | Foundation, DB schema, design system, auth, core layout | ✅ Complete |
| Phase 1 | Employee Management (CRUD, directory, org-chart) | ✅ Complete |
| Phase 2 | Leave / Time Off module | ✅ Complete |
| Phase 3 | Attendance (IP-logged clock-in, break tracking, regularisation) | ✅ Complete |
| Phase 4 | Payroll (salary engine, payslips, finance approval workflow) | ✅ Complete |
| Phase 5 | Performance & Recruitment | ✅ Complete (Recruitment disabled) |
| **Phase 6** | **Production Fix: RBAC, Overtime, Notifications, Onboarding, Documents** | ✅ **Complete** |
| Phase 7 | Super Admin SaaS Shell | 🔲 Next |

#### Pending / Next Steps
- `npm run build` — run after this session to bundle assets.
- Wire `LeaveRequestNotification` triggers into `TeamTimeOff` approval actions.
- Wire `PayrollApprovalNotification` triggers into `Payroll\Process`.
- Build `Attendance\MyAttendance` regularisation request UI (modal using `AttendanceRegularisation` model).
- Consider adding `EncashLeaveCommand` for year-end carry-forward / encashment runs.
- Enable the queue worker (`php artisan queue:work`) in production for queued notifications.

