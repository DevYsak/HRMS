<?php

namespace Database\Seeders;

use App\Enums\QaFindingStatus;
use App\Enums\QaTestStatus;
use App\Models\QaFinding;
use App\Models\QaPhase;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\User;
use App\Services\Qa\QaTracker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Phase 2 policy decisions (approved 15 Oct 2026) applied to the QA tracker,
 * through QaTracker so every change keeps its history: fixed findings get
 * their commits and are verified, decided ones are closed with the decision
 * as the note, and new items are opened. QA records only; idempotent.
 */
class QaAuditPhaseTwoDecisionsSeeder extends Seeder
{
    private const DECIDED_AT = '2026-10-15 17:30:00';

    /** Decision tests (Phase2PolicyDecisionsTest + DepartmentTeamTest). code => [module, title, test file › name] */
    private const CASES = [
        'QA-DEC-001' => ['Dashboards', 'Super Admin opens Department View only with a chosen department', 'Security/Phase2PolicyDecisionsTest > Super Admin opens Department View only with a chosen department, never company totals'],
        'QA-DEC-002' => ['Dashboards', 'A Department Head keeps their own Department View', 'Security/Phase2PolicyDecisionsTest > a Department Head keeps their own Department View with no picker'],
        'QA-DEC-003' => ['Organization', 'Super Admin, Director, HR Admin and Department Heads are top-level', 'Security/Phase2PolicyDecisionsTest > Super Admin, Director, HR Admin and Department Heads are top-level; others are not'],
        'QA-DEC-004' => ['Organization', 'HR completion queue and data quality agree on who needs a manager', 'Security/Phase2PolicyDecisionsTest > the HR completion queue and the data-quality line agree on who needs a manager'],
        'QA-DEC-005' => ['RBAC', 'Probation follows explicit permissions, not role names', 'Security/Phase2PolicyDecisionsTest > probation follows explicit permissions, not role names'],
        'QA-DEC-006' => ['Scope', 'The probation page opens for view_probation holders in scope only', 'Security/Phase2PolicyDecisionsTest > the probation page opens for view_probation holders in scope only'],
        'QA-DEC-007' => ['Organization', 'The reporting manager is the first approver; the team lead never replaces them', 'DepartmentTeamTest > the reporting manager is the first approver; the team lead is the next rung, never a replacement'],
    ];

    /** finding => [commit, message, files, tests, decision] */
    private const FIXED = [
        'QA-F-014' => ['5e0ea8a', 'fix(rbac): authorise probation by explicit permissions, not role names',
            ['database/migrations/2026_10_15_165835_add_probation_permissions.php', 'app/Services/ProbationEngine.php', 'app/Livewire/Employees/ProbationConfirmation.php', 'routes/web.php'],
            ['QA-DEC-005', 'QA-DEC-006'], 'Decision: replace role-name authorisation with explicit probation permissions (view_probation, confirm_probation, hr_approve_probation), granted to exactly the roles that had each ability.'],
        'QA-F-025' => ['8bc845f', 'feat(dashboards): give company-wide viewers a department picker on Department View',
            ['app/Livewire/DepartmentDashboard.php', 'resources/views/livewire/manager-dashboard.blade.php'],
            ['QA-DEC-001', 'QA-DEC-002'], 'Decision: Super Admin keeps company-wide capabilities; Department View is not a landing and shows only with a chosen department.'],
        'QA-F-026' => ['90f2b0a', 'feat(people): apply Phase 2 reporting-line and review decisions',
            ['app/Models/Employee.php', 'app/Services/DataQuality/DataQualityAuditor.php'],
            ['QA-DEC-003', 'QA-DEC-004'], 'Decision: Super Admin, Director, HR Admin and Department Heads / heads of function are top-level; everyone else needs a reporting manager (REVIEW REQUIRED otherwise).'],
        'QA-F-027' => ['37a05cc', 'fix(approvals): make the reporting manager the canonical first approver',
            ['app/Services/Teams/ApprovalRoutingService.php', 'tests/Feature/DepartmentTeamTest.php'],
            ['QA-DEC-007'], 'Decision: the reporting manager is the canonical first approver; team-lead approval only through an explicit routing policy.'],
    ];

    /** Decided, kept as designed: finding => decision note. */
    private const ACCEPTED = [
        'QA-F-015' => 'Decision 15 Oct: retain authorised full-action impersonation. Verified: persistent "Viewing as … / Exit to Admin" banner in the app header; every audit entry records impersonator_id; start / end are audited (ImpersonationTest, AuditTrailIntegrityTest, EmployeeDashboardRedesignTest). Read-only impersonation may be considered later.',
        'QA-F-016' => 'Decision 15 Oct: Directors keep department-scoped employee edit. Verified: role changes pass RoleDelegationGuard, salary needs run-payroll, access-scope changes are company-wide only, bank details live on the Finance profile. Credential actions are a separate gap — QA-F-030.',
    ];

    public function run(): void
    {
        $tracker = app(QaTracker::class);
        $actor = new User(['name' => 'Phase 2 policy decisions']);
        $phase = QaPhase::where('number', 2)->first();
        $at = Carbon::parse(self::DECIDED_AT);

        foreach (self::CASES as $code => [$module, $title, $reference]) {
            $case = QaTestCase::firstOrCreate(['code' => $code], [
                'qa_phase_id' => $phase?->id, 'module' => $module, 'feature' => 'Policy decision', 'title' => $title,
                'expected_result' => 'Test passes.', 'severity' => 'medium', 'automated_reference' => 'tests/Feature/'.$reference,
                'status' => QaTestStatus::Pass, 'last_tested_at' => $at,
            ]);
            if ($case->wasRecentlyCreated) {
                QaTestResult::create(['qa_test_case_id' => $case->id, 'status' => QaTestStatus::Pass, 'actual_result' => 'Passed.',
                    'environment' => 'local_test_db', 'commit' => '5e0ea8a', 'tested_by_name' => 'Claude Code (automated Pest suite)', 'tested_at' => $at]);
            }
        }

        foreach (self::FIXED as $code => [$commit, $message, $files, $tests, $decision]) {
            $finding = QaFinding::where('code', $code)->first();
            if ($finding === null || $finding->fixes()->where('commit_hash', $commit)->exists()) {
                continue;
            }
            $finding->update(['needs_decision' => false]);
            $tracker->linkFix($finding, ['commit_hash' => $commit, 'commit_message' => $message, 'files_changed' => $files,
                'test_case_codes' => $tests, 'developer_note' => $decision, 'environment' => 'local_test_db', 'fixed_at' => $at], $actor);
            $tracker->changeFindingStatus($finding->fresh(), QaFindingStatus::Verified, $actor, $decision.' Tests '.implode(', ', $tests).' pass.');
        }

        foreach (self::ACCEPTED as $code => $note) {
            $finding = QaFinding::where('code', $code)->first();
            if ($finding !== null && $finding->status !== QaFindingStatus::AcceptedRisk) {
                $finding->update(['needs_decision' => false]);
                $tracker->changeFindingStatus($finding->fresh(), QaFindingStatus::AcceptedRisk, $actor, $note);
            }
        }

        // QA-F-021: decided; the dedicated permission is Phase 3 work.
        if (($f = QaFinding::where('code', 'QA-F-021')->first()) && $f->needs_decision) {
            $tracker->updateFinding($f, ['description' => $f->description."\n\nDecision 15 Oct: retain company-wide READ-ONLY attendance reporting for Finance only for payroll / business need; no attendance-management powers; prefer a dedicated report / view permission over the broad view_attendance. Planned in Phase 3."], $actor);
            $f->update(['needs_decision' => false]);
        }

        // QA-F-018: the role drift is real configuration for HR / Super Admin to correct.
        if (($f = QaFinding::where('code', 'QA-F-018')->first()) && $f->kind !== 'data') {
            $tracker->updateFinding($f, ['kind' => 'data', 'description' => $f->description."\n\nRe-verified 15 Oct (Phase 2): local HR Admin still lacks view_employee_360 and view_org_chart and has force_delete_employee (privileged) added. Not reset automatically."], $actor);
            $tracker->changeFindingStatus($f->fresh(), QaFindingStatus::HrCorrectionRequired, $actor, 'HR / Super Admin to review the HR Admin role in Roles & Permissions.');
        }

        $this->open($tracker, $actor, $phase, 'QA-F-030', [
            'kind' => 'security', 'module' => 'Employees', 'severity' => 'medium',
            'title' => 'Credential actions on Employee Edit have no explicit permission',
            'description' => 'Reset password, set temporary password and force password reset on Employee Edit only need the general "update employee" rule (manage employees + scope). A department-scoped Director can therefore take over the login of anyone in their department. Decision QA-F-016 says security fields stay behind explicit permissions.',
            'risk' => 'Account takeover inside a department by a non-HR role.',
            'root_cause' => 'EmployeeEdit::resetPassword / setTemporaryPassword / forcePasswordReset call authorize("update") only.',
        ]);
        $this->open($tracker, $actor, $phase, 'QA-F-031', [
            'kind' => 'data', 'module' => 'Data Quality', 'severity' => 'high', 'environment' => 'local',
            'title' => 'Local database: HR data corrections pending (15 Oct audit)',
            'description' => "hrms:data-quality on the local database (15 Oct): 9 active employees.\n- Emad (Finance role) has no reporting manager — not top-level: REVIEW REQUIRED. Shivani (HR Admin) is top-level: VALID.\n- None of the 10 departments has a real head — HR Data Correction Required (never auto-assigned).\n- Employment type and work mode missing for 9 employees, job title for 7.\n- Super Admin role lacks view_department_dashboard (grant back: Super Admin keeps company-wide capabilities).\n- REVIEW REQUIRED — Alex Morgan, Jordan Reed (manager account without an employee record) and the custom role \"Yogesh sakpal\" (no users, no description): HR / Super Admin to classify as real, demo, test / leftover, or archive / delete. Nothing deleted or normalised automatically.\nRun `php artisan qa:record-data-quality --environment=local` after migrating to itemise these as QA-DQ findings.",
            'risk' => 'Approvals, Department View and HR reports rely on this master data.',
        ], QaFindingStatus::HrCorrectionRequired);
    }

    /** @param  array<string, mixed>  $data */
    private function open(QaTracker $tracker, User $actor, ?QaPhase $phase, string $code, array $data, ?QaFindingStatus $status = null): void
    {
        if (QaFinding::where('code', $code)->exists()) {
            return;
        }
        $finding = $tracker->openFinding(['code' => $code, 'qa_phase_id' => $phase?->id, 'found_at' => self::DECIDED_AT, ...$data], $actor);
        if ($status !== null) {
            $tracker->changeFindingStatus($finding, $status, $actor, 'Decision 15 Oct: real-data items stay HR Data Correction Required until HR provides the facts.');
        }
    }
}
