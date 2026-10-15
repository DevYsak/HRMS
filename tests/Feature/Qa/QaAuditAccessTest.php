<?php

use App\Livewire\Qa\QaAudit;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\QaChecklistItem;
use App\Models\QaEvidence;
use App\Models\QaFinding;
use App\Models\QaTestCase;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Navigation\Sidebar;
use App\Services\Security\RoleDelegationGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * System → QA & Audit: Super Admin only by default, HR read-only when
 * granted, never shown to anyone else.
 */
function qaUser(string $slug): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => Department::factory()->create()->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

function qaGrant(User $user, string $key): User
{
    UserPermissionOverride::create(['user_id' => $user->id, 'permission_id' => Permission::where('key', $key)->value('id'), 'effect' => 'grant']);

    return $user->fresh();
}

test('a Super Admin opens every QA & Audit tab and finds it under System in the menu', function () {
    $admin = qaUser('super_admin');

    $this->actingAs($admin)->get(route('qa-audit.index'))
        ->assertOk()
        ->assertSee('QA &amp; Audit', false)
        ->assertSee('READY FOR INTERNAL QA');

    foreach (array_keys(QaAudit::TABS) as $tab) {
        $this->actingAs($admin)->get(route('qa-audit.index', ['tab' => $tab]))->assertOk()->assertSee('data-tab-panel="'.$tab.'"', false);
    }

    $system = collect(app(Sidebar::class)->groups($admin))->firstWhere('key', 'system');
    expect(collect($system['items'])->pluck('label'))->toContain('QA & Audit');
});

test('nobody else sees or opens it by default — HR included', function (string $slug) {
    $user = qaUser($slug);

    $this->actingAs($user)->get(route('qa-audit.index'))->assertForbidden();
    $this->actingAs($user)->get(route('qa-audit.report'))->assertForbidden();
    $this->actingAs($user)->get(route('qa-audit.export', 'findings'))->assertForbidden();

    $labels = collect(app(Sidebar::class)->groups($user))->flatMap(fn ($g) => collect($g['items'])->pluck('label'));
    expect($labels)->not->toContain('QA & Audit');
})->with(['hr_admin', 'director', 'department_head', 'manager', 'finance', 'coordinator', 'employee']);

test('guests are sent to login', function () {
    $this->get(route('qa-audit.index'))->assertRedirect(route('login'));
});

test('HR granted view_qa_audit reads it but cannot change, run or export anything', function () {
    $hr = qaGrant(qaUser('hr_admin'), 'view_qa_audit');
    $case = QaTestCase::where('code', 'QA-RBAC-002')->firstOrFail();
    $finding = QaFinding::where('code', 'QA-F-014')->firstOrFail();

    $this->actingAs($hr)->get(route('qa-audit.index', ['tab' => 'cases']))->assertOk()->assertSee('QA-RBAC-002')->assertDontSee('New test case');
    $this->actingAs($hr)->get(route('qa-audit.report'))->assertForbidden();

    $page = Livewire::actingAs($hr)->test(QaAudit::class);
    $page->call('openCase', $case->id)->assertSet('showCase', true)->assertDontSee('Record a result');
    $page->call('newCase')->assertForbidden();
    Livewire::actingAs($hr)->test(QaAudit::class)->call('openCase', $case->id)
        ->set('resultForm.status', 'fail')->set('resultForm.note', 'x')->call('recordResult')->assertForbidden();
    Livewire::actingAs($hr)->test(QaAudit::class)->call('openFinding', $finding->id)
        ->set('statusForm.status', 'verified')->set('statusForm.note', 'x')->call('changeFindingStatus')->assertForbidden();
    Livewire::actingAs($hr)->test(QaAudit::class)->call('newRun')->assertForbidden();
    Livewire::actingAs($hr)->test(QaAudit::class)->call('editCell', QaChecklistItem::MODULE, 'Leave', 'functional')->assertForbidden();

    expect($case->fresh()->results()->count())->toBe(1)
        ->and($finding->fresh()->status->value)->toBe('open');
});

test('only a Super Admin can hand out QA permissions', function () {
    expect(RoleDelegationGuard::PRIVILEGED)
        ->toContain('view_qa_audit', 'manage_qa_audit', 'run_qa_checks', 'export_qa_report');
});

test('a Super Admin creates a test case, records results and retests it from the page', function () {
    $admin = qaUser('super_admin');

    Livewire::actingAs($admin)->test(QaAudit::class)
        ->call('newCase')
        ->set('caseForm.code', 'ATT-001')
        ->set('caseForm.module', 'Attendance')
        ->set('caseForm.title', 'Employee punches in from My Attendance')
        ->set('caseForm.route_name', 'attendance.my')
        ->call('saveCase')
        ->assertHasNoErrors()
        ->assertSet('showCase', true);

    $case = QaTestCase::where('code', 'ATT-001')->firstOrFail();
    expect($case->status->value)->toBe('not_tested');

    // FAIL without a note is refused…
    Livewire::actingAs($admin)->test(QaAudit::class)->call('openCase', $case->id)
        ->set('resultForm.status', 'fail')->call('recordResult')
        ->assertHasErrors('resultForm.note');
    expect($case->fresh()->results()->count())->toBe(0);

    // …with one it is recorded, and a later pass verifies the fix without overwriting the failure.
    Livewire::actingAs($admin)->test(QaAudit::class)->call('openCase', $case->id)
        ->set('resultForm.status', 'fail')->set('resultForm.note', 'Punch button missing at 320px')->set('resultForm.environment', 'staging')
        ->call('recordResult')->assertHasNoErrors();
    Livewire::actingAs($admin)->test(QaAudit::class)->call('openCase', $case->id)
        ->set('resultForm.status', 'pass')->set('resultForm.commit', 'abc1234')->call('recordResult')->assertHasNoErrors();

    $case->refresh();
    expect($case->status->value)->toBe('fixed_verified')
        ->and($case->results()->pluck('status')->map->value->all())->toBe(['pass', 'fail'])
        ->and($case->results()->first()->is_retest)->toBeTrue();
});

test('a Super Admin raises a finding, links the fixing commit, retests and verifies it from the page', function () {
    $admin = qaUser('super_admin');
    $case = QaTestCase::factory()->create(['code' => 'LEAVE-001', 'status' => 'fail']);

    Livewire::actingAs($admin)->test(QaAudit::class)
        ->call('newFinding', $case->id)
        ->set('findingForm.title', 'Leave balance shows negative days')
        ->set('findingForm.severity', 'high')
        ->call('saveFinding')->assertHasNoErrors();
    $finding = QaFinding::where('title', 'Leave balance shows negative days')->firstOrFail();
    expect($finding->code)->toStartWith('QA-F-')->and($finding->qa_test_case_id)->toBe($case->id);

    Livewire::actingAs($admin)->test(QaAudit::class)->call('openFinding', $finding->id)
        ->set('fixForm.commit_hash', 'not-a-hash')->call('linkFix')->assertHasErrors('fixForm.commit_hash');

    Livewire::actingAs($admin)->test(QaAudit::class)->call('openFinding', $finding->id)
        ->set('fixForm.commit_hash', 'DEADBEEF1234')
        ->set('fixForm.commit_message', 'fix(leave): clamp balance')
        ->set('fixForm.tests', 'LEAVE-001')
        ->set('fixForm.files', "app/Services/Leave/X.php\ntests/Feature/XTest.php")
        ->call('linkFix')->assertHasNoErrors();

    expect($finding->fresh()->status->value)->toBe('fixed')
        ->and($case->fresh()->status->value)->toBe('fixed_retest_pending')
        ->and($finding->fixes()->first()->commit_hash)->toBe('deadbeef1234')
        ->and($finding->fixes()->first()->files_changed)->toHaveCount(2);

    Livewire::actingAs($admin)->test(QaAudit::class)->call('openFinding', $finding->id)
        ->set('resultForm.note', 'Balance clamps at zero on staging')->set('resultForm.environment', 'staging')
        ->call('retestFinding', true)->assertHasNoErrors();

    expect($finding->fresh()->status->value)->toBe('verified')
        ->and($finding->fresh()->closed_at)->not->toBeNull()
        ->and($case->fresh()->status->value)->toBe('fixed_verified');
});

test('closing a finding as Accepted Risk needs a note', function () {
    $admin = qaUser('super_admin');
    $finding = QaFinding::factory()->create();

    Livewire::actingAs($admin)->test(QaAudit::class)->call('openFinding', $finding->id)
        ->set('statusForm.status', 'accepted_risk')->call('changeFindingStatus')->assertHasErrors('statusForm.note');
    expect($finding->fresh()->status->value)->toBe('open');

    Livewire::actingAs($admin)->test(QaAudit::class)->call('openFinding', $finding->id)
        ->set('statusForm.status', 'accepted_risk')->set('statusForm.note', 'Cosmetic; agreed with HR')->call('changeFindingStatus')->assertHasNoErrors();
    expect($finding->fresh()->status->value)->toBe('accepted_risk');
});

test('search and filters find tests and findings by ID, commit, module and status', function () {
    $admin = qaUser('super_admin');

    Livewire::actingAs($admin)->test(QaAudit::class)->set('tab', 'findings')->set('search', 'cfc173a')
        ->assertSee('QA-F-008')->assertDontSee('QA-F-001');
    Livewire::actingAs($admin)->test(QaAudit::class)->set('tab', 'cases')->set('commitRef', 'a6cfb27')
        ->assertSee('QA-RBAC-002')->assertDontSee('QA-AI-001');
    Livewire::actingAs($admin)->test(QaAudit::class)->set('tab', 'cases')->set('status', 'known_baseline')
        ->assertSee('QA-BASE-001')->assertDontSee('QA-RBAC-001');
    Livewire::actingAs($admin)->test(QaAudit::class)->set('tab', 'findings')->set('module', 'AI')->set('severity', 'low')
        ->assertSee('QA-F-009')->assertDontSee('QA-F-008');
    Livewire::actingAs($admin)->test(QaAudit::class)->set('tab', 'ui')
        ->assertSee('QA-F-011')->assertDontSee('QA-F-001');
});

test('a module readiness cell is marked from the grid, FAIL needing a note', function () {
    $admin = qaUser('super_admin');

    Livewire::actingAs($admin)->test(QaAudit::class)->set('tab', 'modules')
        ->call('editCell', QaChecklistItem::MODULE, 'Leave', 'dark_mode')
        ->set('cellForm.status', 'fail')->call('saveCell')->assertHasErrors('cellForm.note')
        ->set('cellForm.note', 'Calendar unreadable in dark mode')->call('saveCell')->assertHasNoErrors();

    expect(QaChecklistItem::where(['category' => 'module', 'subject' => 'Leave', 'dimension' => 'dark_mode'])->value('status'))->toBe('fail');
});

test('Open Page shows only for a page the viewer can open', function () {
    $admin = qaUser('super_admin');
    $page = Livewire::actingAs($admin)->test(QaAudit::class);

    expect($page->instance()->pageUrl('attendance.my'))->toBe(route('attendance.my'))
        ->and($page->instance()->pageUrl('employees.probation'))->toBeNull()   // needs a parameter
        ->and($page->instance()->pageUrl('no.such.route'))->toBeNull();

    // A manager granted read access gets no link to a page their role cannot open.
    $manager = qaGrant(qaUser('manager'), 'view_qa_audit');
    $managerPage = Livewire::actingAs($manager)->test(QaAudit::class);
    expect($managerPage->instance()->pageUrl('settings.roles'))->toBeNull()
        ->and($managerPage->instance()->pageUrl('attendance.my'))->toBe(route('attendance.my'));
});

test('evidence is stored on the private disk and downloads only through the guarded route', function () {
    Storage::fake('local');
    $admin = qaUser('super_admin');
    $case = QaTestCase::where('code', 'QA-UI-001')->firstOrFail();

    Livewire::actingAs($admin)->test(QaAudit::class)->call('openCase', $case->id)
        ->set('evidenceForm.kind', 'screenshot_after')
        ->set('evidenceFile', UploadedFile::fake()->create('after.png', 24, 'image/png'))
        ->call('addEvidence')->assertHasNoErrors();

    $evidence = QaEvidence::firstOrFail();
    Storage::disk('local')->assertExists($evidence->path);
    expect($evidence->path)->toStartWith('qa-evidence/');

    $this->actingAs($admin)->get(route('qa-audit.evidence', $evidence))->assertOk();
    $this->actingAs(qaUser('hr_admin'))->get(route('qa-audit.evidence', $evidence))->assertForbidden();
});

test('the page works on phones and in dark mode', function () {
    $admin = qaUser('super_admin');

    $this->actingAs($admin)->get(route('qa-audit.index', ['tab' => 'cases']))
        ->assertOk()
        ->assertSee('md:hidden', false)          // stacked mobile cards
        ->assertSee('hidden overflow-x-auto md:block', false)
        ->assertSee('dark:bg-zinc-950', false)
        // "commit" is a Livewire $wire method; binding to it filled the box with JS source.
        ->assertDontSee('wire:model.live.debounce.400ms="commit"', false);
});

test('the Super Admin home shows the QA readiness widget; HR does not', function () {
    $this->actingAs(qaUser('super_admin'))->get(route('dashboard'))
        ->assertOk()->assertSee('data-qa-widget', false)->assertSee('Open QA &amp; Audit', false)->assertSee('438');

    $this->actingAs(qaUser('hr_admin'))->get(route('dashboard'))
        ->assertOk()->assertDontSee('data-qa-widget', false);
});
