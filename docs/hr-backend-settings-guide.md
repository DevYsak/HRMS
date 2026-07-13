# HR Backend — Management & Settings Guide

Where HR/Admin configures the system, what each screen controls, the URL to open it, the source file to inspect, and who's allowed in.

> **The one place to start:** the **Control Panel** — `/settings/control-panel` → [ControlPanel.php](../app/Livewire/Settings/ControlPanel.php). It's the hub that links to every configuration screen below, grouped into four sections. Sidebar: **Settings → Control Panel**.

## Access model (who can open what)

Access is by **permission**, not a fixed role — assign permissions to roles on the Roles screen.

| Permission gate | Unlocks |
|---|---|
| `role:manage-settings` | Every `/settings/*` config screen, plus Leave & Attendance settings |
| `role:manage-employees` | Employee CRUD, **Teams**, Import, and the HR performance screens |
| `role:approve-leave` / `approve-ot` / `approve-wfh` | The matching approval inboxes |
| `role:run-payroll` / `approve-finance` | Payroll run & finance approval |
| `role:review-performance` | Team performance reviews |
| **Super Admin only** | Data Management (destructive data reset) |

---

## 1 · Company & Organisation

| Setting | What you configure | URL | Source |
|---|---|---|---|
| Company | Name, offices, departments, branding | `/settings/general` | Livewire page `settings.general` |
| Departments | Department structure + **department head** (drives approval escalation) | `/settings/departments` | [DepartmentManager.php](../app/Livewire/Settings/DepartmentManager.php) |
| Job Titles | Designations | `/settings/job-titles` | [JobTitleManager.php](../app/Livewire/Settings/JobTitleManager.php) |
| Employment Types | Contract types & probation length | `/settings/employment-types` | [EmploymentTypeManager.php](../app/Livewire/Settings/EmploymentTypeManager.php) |
| Work Modes | On-site / hybrid / remote definitions | `/settings/work-modes` | [WorkModeManager.php](../app/Livewire/Settings/WorkModeManager.php) |
| Salary Cycles | Payroll cycle windows (e.g. 1–31, 21–20) | `/settings/salary-cycles` | [SalaryCycleManager.php](../app/Livewire/Settings/SalaryCycleManager.php) |

---

## 2 · People & Access

| Setting | What you configure | URL | Source |
|---|---|---|---|
| Roles & Permissions | Who can do what — assign permission gates to each role | `/settings/roles` | [RoleManager.php](../app/Livewire/Settings/RoleManager.php) |
| Sidebar Menu | Which menu items employees see, and their order | `/settings/menu` | [MenuSettings.php](../app/Livewire/Settings/MenuSettings.php) |
| Import Employees | Bulk create / update from a spreadsheet | `/employees/import` | [EmployeeImport.php](../app/Livewire/Employees/EmployeeImport.php) |
| Onboarding Templates | Reusable new-hire checklists | `/settings/onboarding-templates` | [OnboardingTemplateManager.php](../app/Livewire/Settings/OnboardingTemplateManager.php) |

---

## 3 · Time & Leave

| Setting | What you configure | URL | Source |
|---|---|---|---|
| Leave Settings | Leave types & their rules (paid/unpaid, half-day, attachments, gender/probation limits) | `/time-off/settings` | [TimeOffSettings.php](../app/Livewire/TimeOff/TimeOffSettings.php) |
| Leave Policies | Conditional **default allocations** by department/role | `/time-off/leave-policies` | [LeaveAllocationPolicies.php](../app/Livewire/TimeOff/LeaveAllocationPolicies.php) |
| Bulk Leave | Assign leave balances to many employees at once | `/time-off/bulk-assign` | [BulkLeaveAssignment.php](../app/Livewire/TimeOff/BulkLeaveAssignment.php) |
| Attendance Settings | Shifts, grace minutes, biometric behaviour | `/attendance/settings` | [AttendanceSettings.php](../app/Livewire/Attendance/AttendanceSettings.php) |

---

## 4 · Notifications & Governance

| Setting | What you configure | URL | Source |
|---|---|---|---|
| Notifications & Email | Master email kill-switch + per-event email toggles | `/settings/notifications` | [NotificationSettings.php](../app/Livewire/Settings/NotificationSettings.php) |
| Audit Log | Read-only trail of every change — who & when | `/settings/audit-log` | [AuditLogViewer.php](../app/Livewire/AuditLogViewer.php) |
| AI Assistant | Provider & access for the AI assistant | `/settings/ai` | Livewire page `settings.ai` |
| **Data Management** ⚠️ | **Permanently clear operational data or remove a single employee — Super Admin only** | `/settings/data-management` | [DataManagement.php](../app/Livewire/Settings/DataManagement.php) |

---

## 5 · v4 Enterprise screens (not yet in the Control Panel hub)

These shipped with the v4 extension and are reached from the **sidebar / module pages** rather than the Control Panel grid.

| Setting | What you configure | URL | Source | Access |
|---|---|---|---|---|
| **Teams** | Department teams — lead, backup lead, members. Drives leave/OT notification routing. | `/employees/teams` | [TeamManagement.php](../app/Livewire/Employees/TeamManagement.php) | `manage-employees` |
| **HR scope** (dept/shift) | Which departments & shifts an HR admin covers. Set **per user on their Employee Edit page** (`scope_departments` / `scope_shifts`). | `/employees/{id}/edit` | [EmployeeEdit.php:52](../app/Livewire/Employees/EmployeeEdit.php#L52) | `manage-employees` |
| Performance Cycles | Create/activate review cycles (template-driven) | `/performance/cycles` | [PerformanceCycles.php](../app/Livewire/Performance/PerformanceCycles.php) | `manage-employees` |
| Review Tasks | Reviewer inbox — self/lead/head score their assigned reviews | `/performance/review-tasks` | [ReviewTasks.php](../app/Livewire/Performance/ReviewTasks.php) | any reviewer |
| Increment Center | Open cycle, generate proposals, calibrate, approve, apply | `/performance/increments` | [IncrementCenter.php](../app/Livewire/Performance/IncrementCenter.php) | HR build / Director approve |

> **How multi-HR works:** set each HR admin's `scope_departments` / `scope_shifts` on their Employee Edit page. A request then notifies **all** in-scope HRs; the first to open it "claims" it (others see *"being handled by…"*); any in-scope HR can approve. Leave blank = company-wide HR (sees everything).

---

## Known gaps in the settings backend

These are **not yet configurable** in a settings screen — they're either hardcoded or depend on the skipped multi-company layer:

| Gap | Where | Fix needed |
|---|---|---|
| **OT rate is hardcoded** at ₹100/hr | [OvertimeService.php:19](../app/Services/OvertimeService.php#L19) `RATE_PER_HOUR = 100.0` | Move to an editable setting (small fix) or `company_settings` (needs multi-company) |
| **Payslip company branding** | Payslip PDF | Needs the `companies` table (multi-company / Phase B — skipped) |
| **Increment review is single-stage** | [IncrementCenter.php](../app/Livewire/Performance/IncrementCenter.php) | Spec wanted team-lead → dept-head → director hand-offs; built as one HR calibration screen |

---

*Everything above is on branch `feat/v4-enterprise`. Config screens are gated by `role:manage-settings`; assign that permission on the Roles screen to let an HR admin in.*
