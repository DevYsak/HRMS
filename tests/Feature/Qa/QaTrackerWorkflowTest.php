<?php

use App\Enums\QaFindingStatus;
use App\Enums\QaPhaseStatus;
use App\Enums\QaSeverity;
use App\Enums\QaTestStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\QaChecklistItem;
use App\Models\QaFinding;
use App\Models\QaFixLink;
use App\Models\QaPhase;
use App\Models\QaTestCase;
use App\Models\QaTestRun;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Audit\AuditService;
use App\Services\Qa\QaReadiness;
use App\Services\Qa\QaTracker;
use App\Services\Qa\RoleAccessMatrix;
use App\Services\Qa\TestRunImporter;
use Database\Seeders\QaAuditSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

function qwfAdmin(): User
{
    $role = Role::where('slug', 'super_admin')->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => Department::factory()->create()->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

test('Phase 1 is seeded: 440 / 438 / 2 known baseline, and every Phase 1 commit is mapped to findings and tests', function () {
    $run = QaTestRun::where('name', '2026-10-15 Phase 1 RBAC Regression')->firstOrFail();

    expect([$run->total, $run->passed, $run->failed, $run->known_baseline, $run->new_regressions])->toBe([440, 438, 2, 2, 0])
        ->and($run->result)->toBe(QaTestRun::RESULT_PASS_WITH_BASELINE)
        ->and(QaPhase::where('number', 1)->first()->status)->toBe(QaPhaseStatus::Completed)
        ->and(QaPhase::where('status', 'not_started')->count())->toBe(1);   // Phase 5 started 19 Oct

    foreach (['a6cfb27', 'ba5e1bb', '585d1d4', '2bd6beb', 'cfc173a', '5756210', 'a90dc23', '671b276', '961fac5'] as $hash) {
        $fix = QaFixLink::where('commit_hash', 'like', $hash.'%')->first();
        expect($fix)->not->toBeNull("{$hash} is not linked")
            ->and($fix->test_case_codes)->not->toBeEmpty()
            ->and(QaTestCase::whereIn('code', $fix->test_case_codes)->count())->toBe(count($fix->test_case_codes));
    }

    // The Command Center failures are known baseline, not regressions.
    expect(QaTestCase::whereIn('code', ['QA-BASE-001', 'QA-BASE-002'])->pluck('status')->map->value->unique()->all())->toBe(['known_baseline']);
});

test('the seeder is idempotent and never overwrites later results', function () {
    $case = QaTestCase::where('code', 'QA-RBAC-001')->firstOrFail();
    app(QaTracker::class)->recordResult($case, QaTestStatus::Fail, qwfAdmin(), 'staging', note: 'Broke on staging');
    $counts = [QaTestCase::count(), QaFinding::count(), QaFixLink::count(), QaTestRun::count()];

    (new QaAuditSeeder)->run();

    expect([QaTestCase::count(), QaFinding::count(), QaFixLink::count(), QaTestRun::count()])->toBe($counts)
        ->and($case->fresh()->status)->toBe(QaTestStatus::Fail);
});

test('every QA change lands in the activity log with old and new values', function () {
    $admin = qwfAdmin();
    $tracker = app(QaTracker::class);
    $finding = $tracker->openFinding(['module' => 'Payroll', 'title' => 'Payslip total off by one', 'severity' => 'medium', 'kind' => 'functional'], $admin);

    $tracker->updateFinding($finding, ['severity' => 'high'], $admin);
    $tracker->changeFindingStatus($finding, QaFindingStatus::Investigating, $admin);

    $log = AuditLog::where('auditable_type', QaFinding::class)->where('auditable_id', $finding->id)->orderBy('id')->get();
    expect($log->pluck('event')->all())->toBe(['QA_FINDING_OPENED', 'QA_FINDING_UPDATED', 'QA_FINDING_STATUS_CHANGED'])
        ->and($log->every(fn ($l) => $l->category === AuditService::QA && $l->user_id === $admin->id))->toBeTrue()
        ->and($log[1]->old_values)->toBe(['severity' => 'medium'])
        ->and($log[1]->new_values)->toBe(['severity' => 'high'])
        ->and($log[2]->old_values)->toBe(['status' => 'open'])
        ->and($tracker->history($finding))->toHaveCount(3);
});

test('failure → finding → fix → retest → verification is preserved, never overwritten', function () {
    $admin = qwfAdmin();
    $tracker = app(QaTracker::class);
    $case = $tracker->createTestCase(['module' => 'Overtime', 'title' => 'Manager approves OT in reach'], $admin);

    $tracker->recordResult($case, QaTestStatus::Fail, $admin, 'staging', 'Approved OT outside team', 'Leak');
    $finding = $tracker->openFinding(['module' => 'Overtime', 'title' => 'OT approval ignores scope', 'severity' => 'high', 'kind' => 'rbac', 'qa_test_case_id' => $case->id], $admin);
    $tracker->linkFix($finding, ['commit_hash' => 'ABCDEF1', 'test_case_codes' => [$case->code]], $admin);
    $tracker->retestFinding($finding->fresh(), false, $admin, 'Still leaks for department heads', 'staging');
    expect($finding->fresh()->status)->toBe(QaFindingStatus::Open);

    $tracker->linkFix($finding->fresh(), ['commit_hash' => 'abcdef2', 'test_case_codes' => [$case->code]], $admin);
    $tracker->retestFinding($finding->fresh(), true, $admin, 'Scoped correctly now', 'staging', 'abcdef2');

    expect($finding->fresh()->status)->toBe(QaFindingStatus::Verified)
        ->and($finding->fixes()->count())->toBe(2)
        ->and($case->fresh()->status)->toBe(QaTestStatus::FixedVerified)
        ->and($case->results()->pluck('status')->map->value->all())->toBe(['pass', 'fail', 'fail']);
});

test('a note is required for FAIL, BLOCKED, Accepted Risk and Verified', function (string $case) {
    $tracker = app(QaTracker::class);
    $admin = qwfAdmin();

    expect(fn () => match ($case) {
        'fail' => $tracker->recordResult(QaTestCase::factory()->create(), QaTestStatus::Fail, $admin, 'staging'),
        'blocked' => $tracker->recordResult(QaTestCase::factory()->create(), QaTestStatus::Blocked, $admin, 'staging'),
        'accepted risk' => $tracker->changeFindingStatus(QaFinding::factory()->create(), QaFindingStatus::AcceptedRisk, $admin),
        'verified' => $tracker->changeFindingStatus(QaFinding::factory()->create(), QaFindingStatus::Verified, $admin),
    })->toThrow(ValidationException::class);
})->with(['fail', 'blocked', 'accepted risk', 'verified']);

test('the tracker records QA only — no employee, attendance, leave, payroll or permission data changes', function () {
    $admin = qwfAdmin();
    $tables = ['users', 'employees', 'attendances', 'attendance_punches', 'leave_requests', 'payrolls', 'role_permission', 'user_permission_overrides', 'permissions', 'roles'];
    $tables = array_values(array_filter($tables, fn ($t) => Schema::hasTable($t)));
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();

    $tracker = app(QaTracker::class);
    $case = $tracker->createTestCase(['module' => 'Leave', 'title' => 'Approve leave'], $admin);
    $tracker->recordResult($case, QaTestStatus::Fail, $admin, 'staging', note: 'x');
    $finding = $tracker->openFinding(['module' => 'Leave', 'title' => 'y', 'severity' => 'low'], $admin);
    $tracker->linkFix($finding, ['commit_hash' => 'abc1234'], $admin);
    $tracker->recordRun(['name' => 'r', 'environment' => 'staging', 'total' => 1, 'passed' => 1], $admin);
    $tracker->setChecklist('module', 'Leave', 'functional', 'pass', $admin);

    expect(collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all())->toBe($before);
});

test('readiness is not a PASS count: a Critical finding blocks UAT, a High security finding blocks Staging, a failed migration means NOT READY', function () {
    $admin = qwfAdmin();
    $readiness = fn () => (new QaReadiness)->summary();

    // Seeded state: every Phase 1 case passes, but most modules are not tested yet.
    expect($readiness()['level'])->toBe(QaReadiness::INTERNAL_QA)
        ->and($readiness()['scores']['overall'])->toBe(100)
        ->and($readiness()['counts']['known_baseline'])->toBe(12);

    // Fill every module, demo and responsive cell so only findings decide.
    $rows = [];
    foreach (QaReadiness::MODULES as $m) {
        foreach (array_keys(QaReadiness::MODULE_DIMENSIONS) as $d) {
            $rows[] = ['category' => 'module', 'subject' => $m, 'dimension' => $d];
        }
    }
    foreach (array_keys(QaReadiness::DEMO_ROLES) as $r) {
        foreach (array_keys(QaReadiness::DEMO_STEPS) as $d) {
            $rows[] = ['category' => 'demo', 'subject' => $r, 'dimension' => $d];
        }
    }
    foreach (QaReadiness::RESPONSIVE_PAGES as $p) {
        foreach (QaReadiness::VIEWPORTS as $w) {
            foreach (['light', 'dark'] as $theme) {
                $rows[] = ['category' => 'responsive', 'subject' => $p, 'dimension' => "{$w}:{$theme}"];
            }
        }
    }
    QaChecklistItem::query()->delete();
    QaChecklistItem::insert(array_map(fn ($r) => [...$r, 'status' => 'pass', 'created_at' => now(), 'updated_at' => now()], $rows));
    QaPhase::query()->update(['status' => 'completed']);
    QaFinding::query()->update(['status' => 'verified']);
    expect($readiness()['level'])->toBe(QaReadiness::PRODUCTION);

    // A low cosmetic UI finding does not block UAT or Staging.
    $cosmetic = QaFinding::factory()->create(['kind' => 'ui', 'severity' => QaSeverity::Low]);
    expect($readiness()['level'])->toBe(QaReadiness::PRODUCTION);
    $cosmetic->update(['status' => 'verified']);

    $security = QaFinding::factory()->security()->create();
    expect($readiness()['level'])->toBe(QaReadiness::UAT)
        ->and($readiness()['scores']['production'])->toBeLessThanOrEqual(49);
    $security->update(['status' => 'verified']);

    $critical = QaFinding::factory()->critical()->create();
    expect($readiness()['level'])->toBe(QaReadiness::INTERNAL_QA);
    $critical->update(['status' => 'verified']);

    app(QaTracker::class)->recordRun(['name' => 'migrate', 'suite' => 'migration', 'environment' => 'staging', 'total' => 1, 'passed' => 0, 'failed' => 1], $admin);
    expect($readiness()['level'])->toBe(QaReadiness::NOT_READY)
        ->and($readiness()['blockers'][0])->toContain('migration');
});

test('a run whose failures are all known baseline is not a regression', function () {
    $admin = qwfAdmin();
    $run = app(QaTracker::class)->recordRun(['name' => 'r', 'environment' => 'staging', 'total' => 10, 'passed' => 8, 'failed' => 2, 'known_baseline' => 2], $admin);
    expect($run->result)->toBe(QaTestRun::RESULT_PASS_WITH_BASELINE)->and($run->new_regressions)->toBe(0);

    $run = app(QaTracker::class)->recordRun(['name' => 'r2', 'environment' => 'staging', 'total' => 10, 'passed' => 7, 'failed' => 3, 'known_baseline' => 2], $admin);
    expect($run->result)->toBe(QaTestRun::RESULT_FAIL)->and($run->new_regressions)->toBe(1);
});

test('Pest console output and JUnit XML import, matching known-baseline failures by test name', function () {
    $importer = app(TestRunImporter::class);

    $pest = "   FAILED  Tests\\Feature\\AttendanceCommandCenterTest > the command center shows pending counts per request type\n"
        ."   FAILED  Tests\\Feature\\AttendanceCommandCenterTest > the status filter shows decided history, not just pending\n"
        ."  Tests:    2 failed, 438 passed (3608 assertions)\n  Duration: 713.05s\n";
    $summary = $importer->parse($pest);
    expect([$summary['total'], $summary['passed'], $summary['failed'], $summary['known_baseline'], $summary['duration_seconds']])->toBe([440, 438, 2, 2, 713]);

    $xml = '<?xml version="1.0"?><testsuites><testsuite name="s">'
        .'<testcase name="a passing test" classname="Tests\\Feature\\XTest" time="1.5"/>'
        .'<testcase name="the command center shows pending counts per request type" classname="Tests\\Feature\\AttendanceCommandCenterTest" time="0.5"><failure>x</failure></testcase>'
        .'<testcase name="a new regression" classname="Tests\\Feature\\YTest" time="1"><error>boom</error></testcase>'
        .'<testcase name="skipped one" classname="Tests\\Feature\\ZTest"><skipped/></testcase>'
        .'</testsuite></testsuites>';
    $summary = $importer->parse($xml);
    expect([$summary['total'], $summary['passed'], $summary['failed'], $summary['skipped'], $summary['known_baseline']])->toBe([4, 1, 2, 1, 1])
        ->and($summary['baseline_matches'])->toBe(['QA-BASE-001']);

    expect(fn () => $importer->parse('nothing useful here'))->toThrow(ValidationException::class);
});

test('qa:import-run records a run from a file and fails the command on new regressions', function () {
    $file = tempnam(sys_get_temp_dir(), 'qa').'.txt';
    file_put_contents($file, "Tests:    1 failed, 9 passed (20 assertions)\n   FAILED  Tests\\Feature\\NewThingTest > it works\n");

    $this->artisan('qa:import-run', ['file' => $file, '--name' => 'CLI import', '--environment' => 'staging', '--commit' => 'abc1234'])->assertFailed();

    $run = QaTestRun::where('name', 'CLI import')->firstOrFail();
    expect([$run->total, $run->failed, $run->new_regressions, $run->environment])->toBe([10, 1, 1, 'staging']);
    @unlink($file);
});

test('the Role Access Matrix is read live from the role grants', function () {
    $cell = fn (string $label, string $slug) => collect(app(RoleAccessMatrix::class)->build()['groups'])->collapse()[$label]['cells'][$slug];

    expect($cell('QA & Audit', 'super_admin')['access'])->toBe('allowed')
        ->and($cell('QA & Audit', 'hr_admin')['access'])->toBe('denied')
        ->and($cell('Department View', 'department_head'))->toBe(['access' => 'scoped', 'scope' => 'Department'])
        ->and($cell('Main Dashboard', 'coordinator')['access'])->toBe('denied')
        ->and($cell('Approve leave', 'employee')['access'])->toBe('denied');

    // A per-user grant shows as "explicit only" for that role.
    $hr = User::factory()->create(['role_id' => Role::where('slug', 'hr_admin')->value('id')]);
    UserPermissionOverride::create(['user_id' => $hr->id, 'permission_id' => Permission::where('key', 'view_qa_audit')->value('id'), 'effect' => 'grant']);
    expect($cell('QA & Audit', 'hr_admin')['access'])->toBe('explicit');
});

test('the report renders every section, as a printable page and as a PDF', function () {
    $admin = qwfAdmin();

    $html = $this->actingAs($admin)->get(route('qa-audit.report'))->assertOk();
    foreach (['NEXCORE HRMS', 'QA, Audit &amp; Readiness Report', 'Executive summary', 'Build tested &amp; environment', 'Test summary', 'Phase summary', 'Role access matrix', 'Module readiness matrix',
        'Findings (', 'Fixes &amp; commits', 'Retest results', 'Known issues', 'UI / UX findings', 'Responsive results', 'Security &amp; RBAC results', 'Demo readiness', 'Remaining work', 'Overall readiness score'] as $section) {
        $html->assertSee($section, false);
    }
    $html->assertSee('READY FOR INTERNAL QA')->assertSee('a6cfb27')->assertSee('QA-BASE-001');

    $pdf = $this->actingAs($admin)->get(route('qa-audit.report', ['format' => 'pdf']));
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toBe('application/pdf');

    expect(AuditLog::where('event', 'QA_REPORT_EXPORTED')->count())->toBe(2);
});

test('CSV exports apply the filters', function () {
    $admin = qwfAdmin();

    $csv = $this->actingAs($admin)->get(route('qa-audit.export', ['dataset' => 'findings', 'commit' => 'cfc173a']))->assertOk();
    $body = $csv->streamedContent();
    expect($body)->toContain('QA-F-008')->not->toContain('QA-F-001,');

    $csv = $this->actingAs($admin)->get(route('qa-audit.export', ['dataset' => 'cases', 'status' => 'known_baseline']))->assertOk();
    expect(substr_count(trim($csv->streamedContent()), "\n"))->toBe(12);

    $this->actingAs($admin)->get('/admin/qa-audit/export/users')->assertNotFound();
});

test('editing a run or result never rewrites when it ran', function () {
    $run = QaTestRun::where('name', '2026-10-15 Phase 1 RBAC Regression')->firstOrFail();
    $result = $run->results()->firstOrFail();
    $this->travel(2)->hours();

    $run->update(['notes' => 'Edited later']);
    $result->update(['note' => 'Edited later']);

    expect($run->fresh()->run_at->format('Y-m-d H:i'))->toBe('2026-10-15 12:40')
        ->and($result->fresh()->tested_at->format('Y-m-d H:i'))->toBe('2026-10-15 12:40');
});

test('Phase 2 is recorded: full run, retest, fixes, policy questions and baseline', function () {
    $phase = QaPhase::where('number', 2)->firstOrFail();
    $full = QaTestRun::where('name', '2026-10-15 Full suite (staging @ fe35a84)')->firstOrFail();

    expect($phase->status)->toBe(QaPhaseStatus::Completed)
        ->and([$full->total, $full->passed, $full->failed, $full->skipped, $full->known_baseline, $full->new_regressions])->toBe([3110, 3095, 14, 1, 12, 2])
        ->and(QaTestCase::where('status', 'known_baseline')->count())->toBe(12)
        ->and(QaTestCase::where('code', 'like', 'QA-ORG-%')->where('status', 'pass')->count())->toBe(27);

    foreach (['QA-F-023' => '9a5efa3', 'QA-F-024' => 'd1fd7bc', 'QA-F-028' => '4627c03'] as $code => $commit) {
        $finding = QaFinding::where('code', $code)->firstOrFail();
        expect($finding->status)->toBe(QaFindingStatus::Verified)->and($finding->fixes()->value('commit_hash'))->toBe($commit);
    }
    expect(QaFinding::whereIn('code', ['QA-F-025', 'QA-F-026', 'QA-F-027'])->where('status', 'verified')->count())->toBe(3)
        ->and(QaFinding::where('code', 'QA-F-029')->first()->status)->toBe(QaFindingStatus::Investigating);

    // The retest settled the two non-baseline full-run failures.
    expect(QaTestRun::where('name', '2026-10-15 Retest after 4627c03')->value('new_regressions'))->toBe(0);
});

test('the Phase 2 policy decisions are recorded with their commits and history', function () {
    $status = fn (string $code) => QaFinding::where('code', $code)->firstOrFail()->status->value;

    foreach (['QA-F-014' => '5e0ea8a', 'QA-F-025' => '8bc845f', 'QA-F-026' => '90f2b0a', 'QA-F-027' => '37a05cc'] as $code => $commit) {
        $finding = QaFinding::where('code', $code)->firstOrFail();
        expect($finding->status)->toBe(QaFindingStatus::Verified)
            ->and($finding->fixes()->pluck('commit_hash'))->toContain($commit)
            ->and(AuditLog::where('auditable_type', QaFinding::class)->where('auditable_id', $finding->id)->where('event', 'QA_FINDING_STATUS_CHANGED')->exists())->toBeTrue();
    }
    expect($status('QA-F-015'))->toBe('accepted_risk')
        ->and($status('QA-F-016'))->toBe('accepted_risk')
        ->and($status('QA-F-018'))->toBe('hr_correction_required')
        ->and($status('QA-F-030'))->toBe('verified')   // fixed in Phase 3 (dd47f75)
        ->and($status('QA-F-031'))->toBe('hr_correction_required')
        ->and(QaFinding::where('code', 'QA-F-031')->value('description'))->toContain('REVIEW REQUIRED')->toContain('Jordan Reed')
        // WFH, the Phase 4 payroll / RBAC questions and the Phase 5 questions (20 Oct) are all decided.
        ->and(QaFinding::open()->where('needs_decision', true)->pluck('code')->all())->toBe([])
        ->and(QaTestCase::where('code', 'like', 'QA-DEC-%')->where('status', 'pass')->count())->toBe(7);
});

test('Phase 3 is recorded: each fix has its test cases, commit and verification, WFH included', function () {
    foreach (['QA-F-030' => 'dd47f75', 'QA-F-021' => '2d29468', 'QA-F-032' => 'bd1d2da', 'QA-F-033' => 'b50a8a3', 'QA-F-034' => 'a474909',
        'QA-F-035' => 'd29aafb', 'QA-F-036' => 'd29aafb', 'QA-F-038' => 'edf0729', 'QA-F-039' => '1c820e9'] as $code => $commit) {
        $finding = QaFinding::where('code', $code)->firstOrFail();
        expect($finding->status)->toBe(QaFindingStatus::Verified, $code)
            ->and($finding->fixes()->pluck('commit_hash'))->toContain($commit)
            ->and(AuditLog::where('auditable_type', QaFinding::class)->where('auditable_id', $finding->id)->where('event', 'QA_FIX_LINKED')->exists())->toBeTrue();
    }

    $wfh = QaFinding::where('code', 'QA-F-037')->firstOrFail();
    expect($wfh->status)->toBe(QaFindingStatus::Verified)
        ->and($wfh->needs_decision)->toBeFalse()
        ->and($wfh->description)->toContain('42 attendance days')->toContain('Decision (16 Oct 2026, approved)')
        ->and($wfh->fixes()->pluck('commit_hash'))->toContain('e6c70e5')
        ->and(QaTestCase::where('code', 'like', 'QA-P3-%')->where('status', 'pass')->count())->toBe(23)
        ->and(QaPhase::where('number', 3)->first()->status)->toBe(QaPhaseStatus::InProgress);

    // The confirming full run: 12 known baseline; the 16 failures a concurrent commit caused mid-run passed on re-run.
    $full = QaTestRun::where('name', '2026-10-16 Phase 3 full suite')->firstOrFail();
    expect([$full->kind, $full->commit, $full->total, $full->passed, $full->failed, $full->known_baseline, $full->new_regressions])->toBe(['full', '1c820e9', 3155, 3126, 28, 12, 0])
        ->and($full->notes)->toContain('905bae5')
        ->and(QaTestRun::where('name', '2026-10-16 Phase 3 re-run of disturbed files')->value('new_regressions'))->toBe(0);
});

test('Phase 4 is recorded: verified fixes with commits', function () {
    foreach (['QA-F-040' => '30caa3d', 'QA-F-041' => 'b3ed78f', 'QA-F-042' => '43741e0', 'QA-F-043' => '129816a', 'QA-F-044' => '4884b45',
        'QA-F-045' => '32017f6', 'QA-F-046' => '981f2dc', 'QA-F-047' => 'e55babf', 'QA-F-048' => '3001a2b', 'QA-F-049' => '50fbdcf'] as $code => $commit) {
        $finding = QaFinding::where('code', $code)->firstOrFail();
        expect($finding->status)->toBe(QaFindingStatus::Verified, $code)
            ->and($finding->fixes()->pluck('commit_hash'))->toContain($commit);
    }

    // The first Phase 4 pass: 23 test cases (QA-P4-001…023).
    expect(QaTestCase::where('code', 'like', 'QA-P4-0%')->where('code', '<=', 'QA-P4-023')->where('status', 'pass')->count())->toBe(23);

    // The confirming full run at a stable HEAD: exactly the 12 known baseline.
    $full = QaTestRun::where('name', '2026-10-16 Phase 4 full suite')->firstOrFail();
    expect([$full->kind, $full->commit, $full->total, $full->passed, $full->failed, $full->known_baseline, $full->new_regressions])->toBe(['full', '32017f6', 3200, 3187, 12, 12, 0]);

    expect($full->new_regressions)->toBe(0);
});

test('the Phase 4 decisions are recorded: each decided finding has its fix commits, test cases and verification', function () {
    $decided = ['QA-F-050' => '093e84c', 'QA-F-051' => '093e84c', 'QA-F-052' => '093e84c', 'QA-F-053' => '093e84c', 'QA-F-054' => '7f1e8bc',
        'QA-F-055' => 'ac70fbf', 'QA-F-056' => 'ac70fbf', 'QA-F-057' => 'ac70fbf', 'QA-F-058' => 'e24c503', 'QA-F-059' => '642e189',
        'QA-F-062' => '16d28a7', 'QA-F-063' => '642e189', 'QA-F-064' => 'e0f7e36', 'QA-F-065' => '7f1e8bc'];

    foreach ($decided as $code => $commit) {
        $finding = QaFinding::where('code', $code)->firstOrFail();
        expect($finding->status)->toBe(QaFindingStatus::Verified, $code)
            ->and($finding->needs_decision)->toBeFalse()
            ->and($finding->fixes()->pluck('commit_hash'))->toContain($commit)
            ->and(AuditLog::where('auditable_type', QaFinding::class)->where('auditable_id', $finding->id)->where('event', 'QA_FINDING_STATUS_CHANGED')->exists())->toBeTrue();
    }

    // Branding, theme coverage and the remaining date formats move to Phase 5.
    expect(QaFinding::whereIn('code', ['QA-F-060', 'QA-F-061', 'QA-F-066'])->pluck('status')->map->value->unique()->values()->all())->toBe(['open'])
        ->and(QaFinding::where('code', 'QA-F-050')->value('description'))->toContain('Decision 1 (16 Oct)')
        ->and(QaTestCase::where('code', 'like', 'QA-P4-%')->where('status', 'pass')->count())->toBe(53);

    // Phase 4 closes on a full run at a stable HEAD with nothing beyond the known baseline.
    $close = QaTestRun::where('name', '2026-10-19 Phase 4 close — full suite')->firstOrFail();
    expect([$close->kind, $close->commit, $close->total, $close->failed, $close->known_baseline, $close->new_regressions])->toBe(['full', 'f3b274a', 3237, 13, 13, 0])
        ->and(QaPhase::where('number', 4)->first()->status)->toBe(QaPhaseStatus::Completed);

    // Later runs are newer; the Phase 5 full suite (20 Oct) is now the last verified full run.
    $summary = (new QaReadiness)->summary();
    expect($summary['latest_run']->run_at->gte($close->run_at))->toBeTrue()
        ->and($summary['verified']['full']->run_at->gte($close->run_at))->toBeTrue();
});

test('Phase 5 round 1 is recorded: fixed findings verified with their commits, decisions left open', function () {
    $fixed = ['QA-F-067' => 'fb1faf5', 'QA-F-068' => 'fb1faf5', 'QA-F-069' => 'fb1faf5', 'QA-F-070' => '0f3fb39', 'QA-F-071' => '0f3fb39',
        'QA-F-072' => '0f3fb39', 'QA-F-073' => '0f3fb39', 'QA-F-074' => '1ae44ea', 'QA-F-075' => '17c2d02',
        'QA-F-076' => '0204185', 'QA-F-077' => '0204185', 'QA-F-078' => '0204185'];

    foreach ($fixed as $code => $commit) {
        $finding = QaFinding::where('code', $code)->firstOrFail();
        expect($finding->status)->toBe(QaFindingStatus::Verified, $code)
            ->and($finding->fixes()->pluck('commit_hash'))->toContain($commit);
    }

    // Opened in round 1 with needs_decision; approved and verified in round 2 (see the next test).
    expect(QaFinding::whereIn('code', ['QA-F-079', 'QA-F-080', 'QA-F-081'])->where('needs_decision', false)->where('status', 'verified')->count())->toBe(3)
        ->and(QaFinding::where('code', 'QA-F-082')->value('status')->value)->toBe('open')
        ->and(QaTestCase::where('code', 'like', 'QA-P5-%')->where('status', 'pass')->count())->toBe(31)
        ->and(QaPhase::where('number', 5)->first()->status)->toBe(QaPhaseStatus::InProgress);
});

test('Phase 5 round 2 records the approved decisions, the systemic UI fixes and the role journeys', function () {
    $verified = ['QA-F-079' => 'cd960dc', 'QA-F-080' => '915845e', 'QA-F-081' => '467fb5d', 'QA-F-083' => 'cd960dc',
        'QA-F-084' => '7b01f18', 'QA-F-085' => '7b01f18', 'QA-F-086' => '7b01f18', 'QA-F-087' => '8105d54'];

    foreach ($verified as $code => $commit) {
        $finding = QaFinding::where('code', $code)->firstOrFail();
        expect($finding->status)->toBe(QaFindingStatus::Verified, $code)
            ->and($finding->needs_decision)->toBeFalse()
            ->and($finding->fixes()->pluck('commit_hash'))->toContain($commit);
    }

    expect(QaFinding::where('code', 'QA-F-079')->value('description'))->toContain('Decision (20 Oct)')
        ->and(QaFinding::where('code', 'QA-F-082')->value('status')->value)->toBe('open');

    $journeys = QaTestRun::where('suite', 'playwright')->where('kind', 'ui')->where('name', 'like', '2026-10-20 Phase 5 role journeys%')->orderBy('run_at')->get();
    expect($journeys->map(fn ($r) => [$r->total, $r->failed, $r->commit])->all())->toBe([[774, 17, 'de4e16c'], [304, 0, '8105d54']]);

    // The Phase 5 full suite at a stable HEAD: nothing beyond the known baseline. Phase 5 stays open for QA-F-060 / 061 / 066 / 082.
    $full = QaTestRun::where('name', '2026-10-20 Phase 5 — full suite')->firstOrFail();
    expect([$full->kind, $full->commit, $full->total, $full->failed, $full->known_baseline, $full->new_regressions])->toBe(['full', 'a50a156', 3270, 12, 12, 0])
        ->and((new QaReadiness)->summary()['verified']['full']->id)->toBe($full->id)
        ->and(QaPhase::where('number', 5)->first()->status)->toBe(QaPhaseStatus::InProgress);
});
