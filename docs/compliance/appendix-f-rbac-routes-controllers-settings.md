# RBAC Audit — Part A (routes, controllers, middleware, dashboards, settings, holidays, help, WFH, profile)

Spec: Pulse by Conexus v3.1 §2.4, §4, §4.1. Repo: `C:\Users\91937\Desktop\HRMS\pulse` (branch staging, working tree as of 2026-10-04). Read-only audit, no tests run.

## 0. How authorization actually works (read first)

| Layer | Mechanism | Notes |
|---|---|---|
| `role:<x>` middleware | `app/Http/Middleware/EnsureRole.php:46-64` maps kebab ability → `User::canX()` → `User::hasPermission(snake_key)` (`app/Models/User.php:146-226`) → `Role::hasPermission` (DB `roles`/`permissions`/`role_permission`, cached 300s, `app/Models/Role.php:33-48`). Super Admin always true. | `RolePermissionService` + `UserRole::canX()` enum methods are **legacy/dead for gating** (EnsureRole calls User methods, not the enum). Permissions ARE DB-configurable via Role Manager. |
| `can:<x>` middleware / `$this->authorize('x')` | `Gate::before` (`app/Providers/AppServiceProvider.php:97-103`): if a `permissions.key = x` row exists → `hasPermission(x)` (returns true/false, overriding any policy). Else normal gates/policies. `manage-settings` and `manageFullSettings` gates at 87-93. | Unknown ability with no row/gate → denied (fail closed). |
| Livewire updates | `Livewire::addPersistentMiddleware([EnsureRole::class, Authorize::class])` (AppServiceProvider.php:78) — route `role:`/`can:` re-applied on every `/livewire/update`. | Good. Routes with only `auth` get no extra protection on actions. |
| Scope | `ApprovalGuard` (`app/Services/Approvals/ApprovalGuard.php`): SA = all; **any `manage_employees` holder with empty `scope_departments`/`scope_shifts` = company-wide** (35-57); others = reporting line (direct reports by `employees.manager_id = users.id`, team leads, departments where `head_id = users.id`) + explicit scope. | Director has `manage_employees` by default → company-wide unless scope set on the user. |
| Default grants | `database/seeders/RolesAndPermissionsSeeder.php:133-190`. hr_admin includes `manage_roles`, `manage_settings`, `manage_company_settings`, `run_payroll`, `view_finance_profile`, `approve_profile_changes`. director includes `manage_employees`, `create/edit/delete_employee`, `approve_overtime`, `approve_finance`, `approve_payroll`, `view_executive_dashboard`, `manage_onboarding/offboarding`. finance includes `review_performance`, `manage_promotions`, `manage_pip`. | Live DB `role_permission` rows UNVERIFIED (no DB access in this audit); findings assume seeded defaults. |
| Global web middleware | `CheckActiveEmployee` (inactive / past last working day → logout), `EnsurePasswordChanged` (issued-credential confinement; bypassed during impersonation by design). `bootstrap/app.php:20-24`. | |

---

## 1. Routes & middleware

| Route group | Middleware | Verdict |
|---|---|---|
| `/iclock/cdata`, `/iclock/getrequest`, `/iclock/devicecmd` (web.php:118-123) | **none** (public, CSRF-exempt bootstrap/app.php:32-34, no throttle) | **HOLE** (H3) |
| `/payslips/{payslip}/verify` (web.php:135-137) | `signed` | PASS (shows name, period, net pay by design) |
| `/invite/accept/{token}` (143-144) | public, token | PASS |
| main group (155-564) | `auth` | — |
| `/settings/general` (542) | **auth only** | **HOLE** (H1) |
| `/settings/*` manage group (547-562) | `role:manage-settings` | PASS route-level; HR reach listed in §8 |
| routes/settings.php `settings/holidays`, `settings/holiday-pay` (33-34) | auth only | PASS — components self-guard (canManageSettings in mount/actions/render) |
| routes/settings.php `settings/ai` (35) | auth only | PASS — SA-only in mount/save/test |
| `/dashboard/department` (361) | auth only | PASS — scoped to `head_id = user` |
| `/dashboard/finance` (355-356) | `role:run-payroll,approve-finance` | **HOLE vs spec** (M2: Director passes via approve_finance) |
| `/attendance/dashboard-v2/api/{path}` (275-279) | `role:approve-leave` | **HOLE** (H4) |
| `/performance/increments/letter/{proposal}` closure (380-389) | auth + inline `canManageEmployees() || owner` | **HOLE** (M5, no scope) |
| `/performance/team`, `/pip/manage`, `/promotions/manage` (392, 410, 416) | `role:review-performance` | **CONFLICT**: Finance holds review_performance (spec: Finance Performance = No access) |
| `employees/*` admin group (198-211) | `role:manage-employees` | **CONFLICT**: Director gets create/edit/import/onboarding/offboarding (spec: View dept) |
| `/reports/*` perf/lifecycle (494-523) | `role:manage-employees` | **HOLE** (M4, no scope) |
| `/reports/attendance-report.*` (526-531) | `role:approve-leave` | **HOLE** (M3, `type=biometric` unscoped) |
| `/reports/*` payroll (458-476) | `role:run-payroll` | PASS (HR/Finance/SA) |
| other `/reports/*` attendance/OT/leave (477-520) | `role:approve-leave|approve-ot` + `assertCompanyWideReach()` | PASS |
| `/api/v1/*` (api.php:41-47) | `biometric.api` (shared key) | PASS auth; LOW no throttle (L8) |
| `/impersonate/{user}` POST, `/impersonate/stop` GET (169-170) | auth + controller check | PASS (see §6) |

## 2. Controllers

| Controller | Route + middleware | Method-level auth | Scope | Verdict |
|---|---|---|---|---|
| AdmsController (`options`, `upload`, `getRequest`, `deviceCmd`) | `/iclock/*`, none | none; device resolved purely from `SN` query/body (142-151) | n/a | **HOLE H3**: forged ATTLOG → `biometric_logs` → `applyPendingLogs` writes `attendances` (76-111) |
| Api\V1\* (Employee/Shift/Holiday/Leave/AttendanceSync) | `biometric.api` | `VerifyBiometricApiKey` hash_equals, 503 fail-closed when key unset (VerifyBiometricApiKey.php:26-45) | company-wide by design | PASS; LOW: no rate limit (api group has no `throttleApi()`), single static key, no IP allowlist |
| BiometricDashboardController | dashboard-v2, `role:approve-leave` | none beyond route | proxies `dashboard, calendar, device-status, pull-logs, set-time, employee/{id}` with caller's query string over GET (21-23, 35-56) | **HOLE H4** |
| DocumentController::experienceLetter | `role:manage-documents` | owner/HR/SA (15) | — | PASS (owner branch unreachable — employees can't fetch own letter; LOW functional) |
| DocumentController::download/view | `signed` (5-min temporary URLs) | **none** (31-59) | none | PARTIAL (L5): bearer-link only, no policy re-check |
| DocumentUploadController::store | `role:manage-documents` | `canManageDocuments` (16) | n/a | PASS |
| DocumentUploadController::storePersonal | auth | own employee (68-69) | own | PASS |
| ImpersonationController | auth | start: real SA, not already impersonating (26-29) | — | PASS (§6) |
| InvitationController | public token | single-use token | — | PASS |
| FirstPasswordController | auth (+throttle on POST) | own account; refused while impersonating (169-172) | own | PASS |
| PayslipController::download | auth | owner OR `canRunPayroll` (Gate `view` has no Payslip policy → false) (153) | own / payroll staff | PASS |
| PayslipController::downloadCombined | auth | non-payroll scoped to own `employee_id` (42-46), max 6 | own | PASS |
| PayslipController::downloadBulkZip | `role:run-payroll` | `canRunPayroll` (115) | all | PASS |
| PayslipController::verify | `signed` | — | — | PASS |
| ReportController attendance/OT/leave summaries | `role:approve-leave/approve-ot` | `assertCompanyWideReach()` (41-44) | company-wide only | PASS |
| ReportController::attendanceReportCsv/Pdf | `role:approve-leave` | reach injected as `employee_ids` (46-60) | **biometric type ignores it** (AttendanceReportBuilder.php:814-842) | **HOLE M3** |
| ReportController perf/KPI/dept-perf/promotion/warning/PIP/lifecycle (202-381, 484-512) | `role:manage-employees` | none | **none** (no reach filter) | **HOLE M4** |
| ReportController payroll (95-112, 677-1078) | `role:run-payroll` | none needed | company | PASS |

## 3. Livewire components

| Component | Route + middleware | mount auth | Actions (auth? scope?) | Locked props | Verdict |
|---|---|---|---|---|---|
| Dashboard | `/` auth | none; branches on legacy `role` enum (31-58) | clockIn/clockOut/startBreak/endBreak: own employee only (369-479) — PASS. HR view (60-336) company-wide for SA/HR enum. Manager→ManagerDashboard::render, Finance→FinanceDashboard::render, Director→ExecutiveDashboard::render **without the permission checks their own routes apply** | none needed | PARTIAL (L1; inherits M1/M2) |
| ApprovalCenter (embedded in `dashboard.blade.php:200`, `executive-dashboard.blade.php:265`) | parent page | none | approve/reject: leave `canApproveLeave`+`assertCanDecide` (70-72); OT `canApproveOt`+`assertCanDecide` (87-89); regularisation `canApproveLeave` (not `approve_regularisation`)+`assertCanDecide` (102-104); encashment `canApproveFinance`+`assertNotSelf` (117-119). List filtered by `accessibleEmployeeIds` and excludes self (166-170) | `$filter` (harmless) | PASS (L9 minor) |
| AuditLogViewer | `settings/audit-log` `role:manage-settings` | `authorize('manage-settings')` (50) | export re-authorizes (133); filters only | ids are filter values | PASS route; spec-PARTIAL (L3: HR sees full trail incl. SA/security events; `audit.view_all` reserved in RoleDelegationGuard but not implemented) |
| DepartmentDashboard | `/dashboard/department` auth | department = `head_id = Auth::id()` (189) | loadStats() uses that department only | `$department` model (Livewire ModelSynth not client-settable) | PASS |
| ExecutiveDashboard | `/dashboard/executive`, `/dashboard/director` `can:view_executive_dashboard`; also via `/` for Director | none | read-only; **company-wide** headcount, attendance, payroll cost (view line 25, 51), PIP/warnings | — | PARTIAL (L2: ignores Director scope) |
| FinanceDashboard | `/dashboard/finance` `role:run-payroll,approve-finance`; via `/` for Finance | none | read-only; every payslip gross/net (view 144-160), per-employee OT amounts (view 187-194) | `$month` string (harmless) | **HOLE M2** (Director) |
| HrAdminDashboard | `/dashboard/hr-admin` `can:view_hr_dashboard` | none | read-only, company-wide + last 6 audit rows | — | PASS |
| ManagerDashboard | `/dashboard/manager` `role:approve-leave`; via `/` for Manager | none | quickApproveLeave/openRejectModal/quickRejectLeave: `canApproveLeave` + LeaveService::reviewRequest → `assertCanReview` → `ApprovalGuard::assertCanDecide` (LeaveService.php:1264-1275) — PASS. **render(): team = `Employee::where('manager_id', $manager->id)` with `$manager` = Employee (99-107) but `employees.manager_id` FK → users.id** | `rejectingLeaveId` unlocked but service re-checks scope | **HOLE M1** |
| Notifications (sidebar) | every page | — | markRead/markAllRead via `Auth::user()->notifications()` | — | PASS |
| NotificationsPage | `/notifications` auth | — | all actions on `Auth::user()->notifications()`; reminders: SA/HR all, others `manager_id = Auth::id()` (176-221) | `$selected` ids constrained by owner query | PASS |
| AiAssistantPage / AiCopilot | `/ai-assistant` auth; copilot in sidebar | `enabledForUser` on send | context: SA/HR company counts, Manager/Director direct-report counts, self balances (83-117 / 191-226); only aggregates | `$messages` client-editable but display-only | PASS |
| Settings\ControlPanel | `role:manage-settings` | authorize manage-settings | links only | — | PASS |
| Settings\DataManagement | `role:manage-settings` | **SA only** (97) | every action re-checks SA (102,112,123,135) | `$selected` | PASS |
| Settings\DepartmentManager | `role:manage-settings` | authorize | save/delete authorize; delete refuses if employees assigned | `editingId` unlocked (HR may edit any anyway) | PASS |
| Settings\EmploymentTypeManager / JobTitleManager / WorkModeManager / SalaryCycleManager | `role:manage-settings` | authorize | save/delete/restore/toggle authorize | `editingId` unlocked, harmless | PASS |
| Settings\MenuSettings | `role:manage-settings` | authorize | save authorizes | — | PASS |
| Settings\OnboardingTemplateManager / OnboardingTemplateTaskManager | `role:manage-settings` | authorize | moveUp/moveDown lack explicit authorize (route middleware still applies) | `$template` model | PASS |
| Settings\ApprovalPolicySettings | `role:manage-settings` | authorize | save/delete/toggle/move authorize (58-112) — HR can rebuild the payroll approval chain without any Finance step | `editingId` unlocked | PASS (auth) / **CONFLICT M6** |
| Settings\RoleManager | `role:manage-settings` | manage-settings + `manage_roles` (53-57) | every action re-authorizes + RoleDelegationGuard ceiling; HR can still edit Director/Manager/Finance/Employee **system** roles within its ceiling | `editingId`, `viewingRoleId`, `deletingId` **#[Locked]** | PASS (auth) / **CONFLICT M6** |
| Settings\NotificationSettings | `role:manage-settings` | authorize | every action authorizes; includes master mail switch (84), broadcast to any users (195), `queue:retry`/`queue:flush` (478-492) | `editingId`, `editingRoleId` unlocked (HR may edit any) | PASS (auth) / spec-PARTIAL (L4) |
| pages::settings.general (SFC) | `/settings/general` **auth only** | **none** (50-54) | updateCompany (84), editOffice/saveOffice/deleteOffice (131-185), editDepartment/saveDepartment/deleteDepartment (189-228): **no authorization**; sidebar link hidden behind `@can('manageFullSettings')` (pages/settings/layout.blade.php:14) only | `$officeId`, `$deptId` unlocked | **HOLE H1** |
| pages::settings.ai (SFC) | auth | SA only (27) | save/test SA (50, 85) | — | PASS |
| pages::settings.profile (SFC) | auth | own | updateProfileInformation changes own name/email freely (29-43) | — | PARTIAL (L7) |
| Holidays\ManageHolidays | `settings/holidays` auth | `canManageSettings` (50) | every mutating action + render re-check (77-255) | `editingId` unlocked, harmless | PASS |
| Holidays\HolidayPaySettings | `settings/holiday-pay` auth | `canManageSettings` (54) | save/render re-check (70, 97) | — | PASS |
| Help\EmployeeGuide | auth | — | read-only | — | PASS |
| Wfh\ManageWfhRequests | `role:approve-wfh` | `checkWfhPermission` | openView `assertCanView`, openReview `assertCanDecide`, submitReview → WfhService approve/reject `assertCanDecide` (WfhService.php:70,86); list scoped by `accessibleEmployeeIds` | `reviewingId` **#[Locked]**; `selectedRequest` model | PASS |
| Wfh\MyWfhRequests | auth | — | submit/cancel scoped to own `employee_id` (114) | — | PASS |
| Profile\MyProfile | `/my-profile` auth | own employee required (50) | edit/request gated by `edit_own_profile`/`request_profile_change`; service enforces tier (`updateEditable` isEditable, `requestChange` needsApproval); withdraw only by requester (ProfileChangeService.php:177) | `editingField` unlocked but service re-validates | PASS |
| Profile\EmployeeProfile | `/employees/{employee}/profile` `role:manage-employees` | `authorize('manage_employees')` only (53) — **no scope** | editField/saveField → `updateAsHr` (ProfileChangeService.php:196-224): any registered field except `status` (only `hr_editable=false`, ProfileFieldRegistry.php:281) incl. `email` (users.email, write() 265), bank/IFSC/PAN/Aadhaar, CTC, department, manager; **no scope, no self check, no finance-permission check**. approve/rejectRequest: `approve_profile_changes`, request not tied to `$this->employee`, no self-approval check | `reviewingId`, `editingField` unlocked | **HOLE H2** |

## 4. Public properties that should be #[Locked]

All id-bearing props in scope either (a) are re-authorized server-side on use, or (b) point at data the actor may edit anyway. Material ones:
- `EmployeeProfile::$reviewingId` (39) — not Locked and not checked to belong to `$this->employee` (171-200). Minor given H2.
- `EmployeeProfile::$editingField` (34) — tamperable but service re-checks `isHrEditable`.
- `ManagerDashboard::$rejectingLeaveId` (21) — re-checked by ApprovalGuard in service. OK.
- `pages::settings.general $officeId/$deptId` — irrelevant until H1 fixed (whole component unguarded).
RoleManager and ManageWfhRequests already lock their ids.

## 5. Data shown to roles the spec forbids

| What | Who sees it | Where |
|---|---|---|
| Every employee's gross/net pay, per-employee OT amounts | Director (approve_finance) | FinanceDashboard (M2) |
| Every employee's bank account, IFSC, PAN, Aadhaar, CTC | Director (manage_employees) | EmployeeProfile financial tab (H2) — no `view_finance_profile` gate, no masking (ProfileFieldRegistry::displayValueFor 463-487) |
| Revised gross in increment letters, any employee | Director | `/performance/increments/letter/{id}` (M5) |
| Another team's attendance/leave/OT/KPI scores | Manager | ManagerDashboard (M1) |
| Company-wide biometric punch logs | Manager | `/reports/attendance-report.csv?type=biometric` (M3); BiometricControl::exportLogs (out of scope component, `Attendance/BiometricControl.php:72-88`); dashboard-v2 `employee/{id}` (H4) |
| All departments' warning letters / PIPs / performance scores / lifecycle | dept-scoped Director | perf reports (M4) |
| Company-wide payroll cost & headcount | any Director incl. scoped dept head | ExecutiveDashboard (L2) |
| Others' leave beyond "name + dates" (team calendar) | Employee | NOT in this scope's components (employee dashboard shows only own leave) — no finding |

## 6. Impersonation

- Who: only a real Super Admin not already impersonating (`ImpersonationController.php:26-29`); POST + CSRF.
- Whom: any user, including other Super Admins (no target restriction). Not an escalation (SA→SA).
- Escalation to SA by a non-SA: not possible via this controller — impersonator id lives server-side in session; `stop` (48-65) restores exactly that id.
- Audit: `IMPERSONATION_STARTED` logged as the SA before switching (36-38), `IMPERSONATION_ENDED` on stop (58-60); every `AuditLog` row written meanwhile carries `impersonator_id` (AuditLog.php:139-150). Session regenerated on both switches.
- Guards during impersonation: first-password page refuses (FirstPasswordController.php:169-172). `settings/security` password change and `settings/profile` email change are NOT blocked while impersonating (SA-only anyway, audited).
- `stop` is a GET → CSRF-triggerable, harmless.
- Verdict: PASS.
(Separate, real escalation path to SA exists via EmployeeProfile email rewrite → H2.)

## 7. API routes

- Authenticated: shared secret header `X-Api-Key` / Bearer, `hash_equals`, 503 if unconfigured (fail closed). PASS.
- Rate limited: **No** — `bootstrap/app.php` never calls `throttleApi()`, api.php adds no throttle. LOW.
- `/iclock/*` ADMS receiver: **unauthenticated**, CSRF-exempt, no throttle, no IP allowlist → H3.

## 8. System Settings — what HR Admin reaches (spec: "Partial")

HR Admin (default `manage_settings` + `manage_roles` + `manage_company_settings`) reaches: Control Panel, Departments, Audit Log (full trail + export), **Roles & Permissions** (edit Director/Manager/Finance/Employee system roles and custom roles within delegation ceiling), Employment Types, Work Modes, Salary Cycles, **Payroll Approval Policy**, Job Titles, Sidebar Menu, **Notifications & Email** (master kill switch, templates, broadcast, SMTP test, queue retry/flush), Onboarding Templates/Tasks, Holidays, Holiday Pay, Time-off Settings / Leave Policies / Bulk Assign, Attendance Settings, Company (`settings/general`, but that page is open to everyone — H1).
Blocked for HR: Data Management (SA-only mount), AI settings (SA-only).
Verdict: "Partial" is nominal — HR reaches essentially all system configuration including RBAC and payroll sign-off chain. CONFLICT.

---

## Confirmed holes (ranked High → Low)

### High
**H1. `/settings/general` has no authorization at all.**
`routes/web.php:542` (auth only); `resources/views/pages/settings/⚡general.blade.php` mount 50, `updateCompany` 84, `saveOffice` 152, `deleteOffice` 181, `saveDepartment` 204, `deleteDepartment` 225.
Exploit: any signed-in Employee calls these Livewire actions to rename the company, change currency/timezone/branding, edit office geofence radius, or delete a department (FK `nullOnDelete` silently strips every member's `department_id`, breaking department scoping such as Nikita's); the favicon rule (`mimes:png,ico,svg,jpg`, line 93) also accepts SVG onto the public disk (stored-XSS vector).
Fix: move the route into the `role:manage-settings` group (or `can:manageFullSettings`) and add `$this->authorize('manage-settings')` to mount and every action; drop `svg` from favicon/logo.

**H2. EmployeeProfile lets any `manage_employees` holder read and rewrite any employee's sensitive fields, including the login email.**
`app/Livewire/Profile/EmployeeProfile.php:53,101-151,171-200`; `app/Services/Profile/ProfileChangeService.php:196-224,262-269,115-143`; `ProfileFieldRegistry.php:184-192` (email → users table), 281 (only `status` locked).
Exploit: a Director (seeded with `manage_employees`) or HR Admin opens `/employees/{id}/profile` for any employee and (a) reads full bank/PAN/Aadhaar/CTC; (b) sets the Super Admin's/Finance user's work email to an attacker address, then uses Fortify "Forgot password" (config/fortify.php:155) to take over the account; (c) rewrites a colleague's bank account (feeds the bank-transfer report, ReportController.php:752-781); HR can also edit or self-approve changes to their own bank details.
Fix: `abort_unless(Auth::user()->coversEmployee($employee), 403)` in mount; require `view_finance_profile` for the financial group; refuse `email` edits on SA (and on anyone above the actor via RoleDelegationGuard) and add `unique:users,email`; reject self-edit/self-approval (`ApprovalGuard::isSelf`) in `updateAsHr`/`approve`; drop `manage_employees` from Director defaults.

**H3. ADMS push endpoint accepts forged attendance from anyone.**
`routes/web.php:118-123`; `bootstrap/app.php:32-34` (CSRF exempt); `app/Http/Controllers/AdmsController.php:76-111,142-151`.
Exploit: an unauthenticated internet client that knows a device serial (printed on the device, shown on BiometricControl) POSTs `/iclock/cdata?SN=<sn>&table=ATTLOG` with tab-separated lines for any biometric id; rows go into `biometric_logs` and are applied immediately to `attendances`.
Fix: require the request IP to match `BiometricDevice::ip_address` (or a per-device secret in the path/comm key), add `throttle`, and/or restrict `/iclock` at the reverse proxy.

**H4. dashboard-v2 proxy lets any Manager change device clocks and read any employee's engine data.**
`app/Http/Controllers/BiometricDashboardController.php:21-23,35-56`; route `web.php:275-279` (`role:approve-leave`). Engine semantics confirmed in `C:\Users\91937\Desktop\biometric_test_project\adms_log.py:638-656`. Whether the deployed `services.biometric_app.url` runs that same engine is UNVERIFIED.
Exploit: a Manager (or a CSRF `<img>` against one, since it is GET) calls `/attendance/dashboard-v2/api/set-time?delta_min=-90` to shift the device clock, which falsifies everyone's punch times. `employee/{id}` returns any employee's attendance regardless of reach.
Fix: remove `set-time` and `pull-logs` from `ALLOWED` (or move them to POST behind `manage_biometric`/`manage-settings`); scope `employee/{id}` with `coversEmployee`.

### Medium
**M1. ManagerDashboard computes the team from the wrong id.**
`app/Livewire/ManagerDashboard.php:99-107` uses `Employee::where('manager_id', $manager->id)`, where `$manager` is an Employee. But `employees.manager_id` references `users.id` (migration `2026_04_15_091150_create_employees_table.php:21`).
Exploit: a Manager on `/` or `/dashboard/manager` sees another user's team (today's attendance, pending leave/OT, KPI scores): the team of whichever user id equals their employee id. Approvals stay blocked by ApprovalGuard.
Fix: `Employee::where('manager_id', Auth::id())`, or better, `whereIn('id', Auth::user()->accessibleEmployeeIds())`.

**M2. Director sees company-wide payslip amounts on FinanceDashboard.**
`routes/web.php:355-356` (`role:run-payroll,approve-finance`), seeder:155 (Director holds `approve_finance`); `finance-dashboard.blade.php:144-160,187-194`.
Exploit: a Director opens `/dashboard/finance` and reads every employee's gross/net pay and per-employee OT amount. Spec says Director = "View dept cost" and Payslips = "No access".
Fix: gate the route on `role:run-payroll` (Finance/HR) or aggregate/scope it for non-company-wide viewers; revisit the Director `approve_finance` grant.

**M3. The biometric attendance report ignores the viewer's reach.**
`app/Services/AttendanceReportBuilder.php:814-842` (only `employee_id` is applied, never `employee_ids`); `ReportController.php:63-93`.
Exploit: a Manager downloads `/reports/attendance-report.csv?type=biometric` (or adds `&employee_id=N`) and gets company-wide punch logs. The same unscoped pattern exists in `Attendance/BiometricControl::exportLogs` (72-88).
Fix: add `->when(isset($filters['employee_ids']), fn ($q) => $q->whereIn('employee_id', $filters['employee_ids']))`.

**M4. HR/performance CSV exports have no scope.**
`ReportController.php:202-381,484-512` behind `role:manage-employees` only (`web.php:494-523`).
Exploit: a department-scoped Director (e.g. a UK-Sales-scoped head) downloads every department's warning letters, PIPs, review scores, promotion pipeline and lifecycle data.
Fix: call `assertCompanyWideReach()` or filter by `accessibleEmployeeIds()`.

**M5. Increment letters can be downloaded by any Director without scope.**
`routes/web.php:380-389`. The letter shows the revised monthly and annual gross (`pdf/increment-letter.blade.php:51-52`).
Exploit: a Director enumerates `/performance/increments/letter/{id}` and downloads any employee's salary letter.
Fix: require `canManageEmployees() && coversEmployee()` (or `view_finance_profile`).

**M6. HR Admin can take Finance out of payroll sign-off and redistribute privileges.**
`app/Livewire/Settings/ApprovalPolicySettings.php:58-100`. Separately, `RoleManager.php:53-57` together with seeder:147 gives `hr_admin` `manage_roles` by default, although `RoleDelegationGuard::PRIVILEGED` (38-52) treats `manage_roles` as SA-only.
Exploit: HR replaces the approval chain with an `hr_admin`/`specific_user` step, or gives `approve_finance` to a custom role held by a second HR user; HR-A processes and HR-B approves. Maker-checker only requires a different person (`PayrollService.php:327`). HR can also add `view_finance_profile`/`manage_settings`/`run_payroll` to the Employee or Manager system roles.
Fix: make ApprovalPolicySettings and RoleManager Super-Admin-only (or enforce at least one `finance` step); remove `manage_roles` from the hr_admin default.

### Low
- **L1** `Dashboard.php:31-58,338-353` branches on the legacy `role` enum. Revoking `view_executive_dashboard`, `view_hr_dashboard`, `approve_leave` or `run_payroll` in Role Manager does not change what `/` renders. Fix: apply the same gates the `/dashboard/*` routes use.
- **L2** `ExecutiveDashboard.php:22-160` is always company-wide, so a scope-limited Director still sees company headcount and payroll cost. (The spec conflicts with itself here: §5.1 vs §4.)
- **L3** `AuditLogViewer.php:50,133`: HR sees and exports the full audit trail, including SA and security events. `audit.view_all` is reserved but never enforced.
- **L4** `NotificationSettings.php:84,195,478-492`: HR controls the master mail switch, broadcast email to any user, and `queue:retry`/`queue:flush`. These are system operations, beyond "Partial".
- **L5** `DocumentController.php:31-59`: download/view are protected only by a 5-minute signed URL, with no per-user policy re-check. `experienceLetter`'s owner branch (15) is unreachable behind `role:manage-documents`.
- **L6** `EmployeeDashboardService.php:607-616`: any `category=policy` document is shown even when restricted to one individual, and all payslip-category documents are shown to `approve_finance` holders.
- **L7** `pages/settings/⚡profile.blade.php:29-43`: any user can change their own login email and name, bypassing the registry's TIER_LOCKED email. `⚡delete-user-modal.blade.php:16-24` lets any user, including the sole SA, soft-delete their own account.
- **L8** No throttle on `/api/v1/*` or `/iclock/*`.
- **L9** `ApprovalCenter.php:102` checks `canApproveLeave` for regularisations instead of `approve_regularisation`. `LeaveService.php:1270` lets a manager-stage reviewer reject a `pending_hr` request.
- **L10** `EmployeeProfile::$reviewingId` is not `#[Locked]` and is not bound to `$this->employee` (39, 171-200).
- **L11** `/impersonate/stop` is a GET (CSRF-able, harmless). A Super Admin can impersonate other Super Admins.

---

## RBAC matrix compliance (§4 cells checkable from this scope)

| Module | Role | Spec | Finding | Status |
|---|---|---|---|---|
| Employee Profiles | Super Admin | Full CRUD | full | PASS |
| Employee Profiles | HR Admin | Full CRUD | full (but can also rewrite SA email — H2) | PASS (with H2) |
| Employee Profiles | Director | View dept | `manage_employees`+create/edit/delete; EmployeeProfile unscoped read+write | CONFLICT |
| Employee Profiles | Manager | View team | no EmployeeProfile access; directory/index (part B) | PASS here |
| Employee Profiles | Finance | View basic | finance-profile (salary/bank) via view_finance_profile | PASS (consistent with Compensation Full) |
| Employee Profiles | Employee | View own | MyProfile own-only; but settings/profile edits own email/name freely | PARTIAL (L7) |
| Attendance | Director | View dept | company-wide when user has no scope | CONFLICT (config-dependent) |
| Attendance | Manager | View + approve regularise | approvals scoped (ApprovalCenter); but biometric report/export/proxy company-wide | PARTIAL (M3, H4) |
| Attendance | Finance | Summary only | no route grants Finance attendance summaries (all `role:approve-leave`) | MISSING |
| Attendance | Employee | Own + regularise | dashboard clock/break own-only | PASS |
| Leave | Director | Approve dept | ApprovalGuard company-wide if unscoped | CONFLICT (config) |
| Leave | Manager | Approve team | ApprovalCenter/ManagerDashboard actions scoped; ManagerDashboard list wrong team | PARTIAL (M1) |
| Leave | Finance | Summary | leave reports require approve_leave | MISSING |
| Leave | Employee | Own only | own | PASS |
| OT | Director | View dept | holds approve_overtime → approves (company-wide if unscoped) | CONFLICT |
| OT | Manager | Approve team | ApprovalCenter scoped | PASS |
| OT | Finance | OT summary | FinanceDashboard yes; OT CSVs need approve_ot | PARTIAL |
| Compensation | HR Admin | Configure | runs payroll, reports, can restructure approval chain | CONFLICT (M6) |
| Compensation | Director | View dept cost | company-wide per-employee pay (M2), CTC/bank (H2), increment letters (M5), payroll approval | CONFLICT |
| Compensation | Manager | No access | none reachable | PASS |
| Compensation | Finance | Full | full | PASS |
| Compensation | Employee | Own payslips | PayslipController own-only | PASS |
| Notifications | all roles | All/All/Own+team/Own+team/Own/Own | inbox own-only; reminders scoped | PASS |
| Performance | Director | Review dept | perf reports unscoped | CONFLICT (M4) |
| Performance | Finance | No access | Finance holds review_performance/manage_pip/manage_promotions → /performance/team, /pip/manage, /promotions/manage | CONFLICT |
| Onboarding/Exit | Director | View dept | onboarding/offboarding managers + checklists (manage_employees) | CONFLICT |
| Documents (HR) | non-HR | Policies only | upload HR-only; employees upload own personal docs; dashboard shows policy docs | PASS (L6 edge) |
| Documents (Payslips) | Director | No access | PayslipController blocks; FinanceDashboard shows amounts | CONFLICT (M2) |
| Documents (Payslips) | Manager | No access | blocked | PASS |
| Documents (Payslips) | Finance / HR | Full | run_payroll → any payslip | PASS |
| Documents (Payslips) | Employee | Own only | own | PASS |
| Reports | SA / HR | All | all | PASS |
| Reports | Director | Dept dash | DepartmentDashboard ok; company-wide exports/dashboards | CONFLICT |
| Reports | Manager | Team dash | ManagerDashboard wrong team; biometric report unscoped | PARTIAL (M1, M3) |
| Reports | Finance | Finance reports | payroll reports | PASS |
| Reports | Employee | Own summary | employee dashboard own | PASS |
| System Settings | Super Admin | Full | full | PASS |
| System Settings | HR Admin | Partial | everything except Data Management + AI (incl. roles, payroll chain, mail/queue) | CONFLICT (§8, M6, L3, L4) |
| System Settings | Director/Manager/Finance/Employee | None | `/settings/general` writable by all | CONFLICT (H1) |
| §4.1 Shivani | HR + manager-level read all depts | HR is company-wide via manage_employees (not a secondary flag) | PASS (different mechanism) |
| §4.1 Emad | Finance payroll approval + attendance/leave summaries | approval yes; summaries no route | PARTIAL |
| §4.1 Nikita | Dept head scoped to UK Sales shift | possible via `users.scope_shifts` (ApprovalGuard); not honoured by FinanceDashboard, ExecutiveDashboard, EmployeeProfile, perf/lifecycle reports, increment letters, settings/general; her actual role/scope in DB UNVERIFIED | PARTIAL |

Note on "Director": project memory records Director = Mazhar (the executive). The matrix column "Director" (View dept / Approve dept) reads like Department Heads (§5.2: Rustom/Nick/Nikita). If Director is meant to be company-wide (§5.1), the read-scope CONFLICT rows become intended. The write findings still stand (H2: edit CTC/bank/email; M2 payslip amounts vs "Payslips: No access").
