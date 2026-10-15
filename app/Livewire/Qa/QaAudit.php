<?php

namespace App\Livewire\Qa;

use App\Enums\QaEnvironment;
use App\Enums\QaFindingStatus;
use App\Enums\QaPhaseStatus;
use App\Enums\QaSeverity;
use App\Enums\QaTestStatus;
use App\Models\QaChecklistItem;
use App\Models\QaEvidence;
use App\Models\QaFinding;
use App\Models\QaPhase;
use App\Models\QaTestCase;
use App\Models\QaTestRun;
use App\Models\User;
use App\Services\Help\RouteAccess;
use App\Services\Qa\QaFilters;
use App\Services\Qa\QaReadiness;
use App\Services\Qa\QaTracker;
use App\Services\Qa\RoleAccessMatrix;
use App\Services\Qa\TestRunImporter;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * System → QA & Audit. A Super Admin workspace that records what has been
 * tested, what failed, which commit fixed it, and how ready the portal is.
 * view_qa_audit opens it read-only; manage_qa_audit records results and
 * findings; run_qa_checks records runs; export_qa_report exports. It writes
 * QA records only (through QaTracker) — never application data.
 */
class QaAudit extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** @var array<string, string> */
    public const TABS = [
        'overview' => 'Overview',
        'phases' => 'Phases',
        'cases' => 'Test Cases',
        'findings' => 'Findings',
        'fixes' => 'Fixes',
        'runs' => 'Test Runs',
        'matrix' => 'Role Matrix',
        'modules' => 'Modules',
        'ui' => 'UI / UX',
        'responsive' => 'Responsive',
        'demo' => 'Demo',
    ];

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $search = '';

    #[Url]
    public string $phase = '';

    #[Url]
    public string $module = '';

    #[Url]
    public string $role = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $severity = '';

    #[Url]
    public string $environment = '';

    /** The commit filter ("commit" itself is reserved by Livewire's $wire.commit). */
    #[Url(as: 'commit')]
    public string $commitRef = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $decision = '';

    #[Url]
    public string $theme = 'light';

    // Drill-downs
    public ?int $caseId = null;

    public ?int $findingId = null;

    public bool $showCase = false;

    public bool $showFinding = false;

    // Forms
    public bool $showCaseForm = false;

    public bool $showFindingForm = false;

    public bool $showRunForm = false;

    public bool $showCellForm = false;

    public bool $showPhaseForm = false;

    public ?int $editingCaseId = null;

    public ?int $editingFindingId = null;

    public ?int $editingPhaseId = null;

    /** @var array<string, mixed> */
    public array $caseForm = [];

    /** @var array<string, mixed> */
    public array $findingForm = [];

    /** @var array<string, mixed> */
    public array $resultForm = [];

    /** @var array<string, mixed> */
    public array $statusForm = [];

    /** @var array<string, mixed> */
    public array $fixForm = [];

    /** @var array<string, mixed> */
    public array $runForm = [];

    /** @var array<string, mixed> */
    public array $cellForm = [];

    /** @var array<string, mixed> */
    public array $phaseForm = [];

    /** @var array<string, mixed> */
    public array $evidenceForm = ['kind' => 'screenshot', 'note' => ''];

    public $evidenceFile = null;

    public $runFile = null;

    public function mount(): void
    {
        abort_unless($this->user()->hasPermission('view_qa_audit'), 403);

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'overview';
        }

        $this->resetResultForm();
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'overview';
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, [...QaFilters::KEYS, 'commitRef'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset([...array_diff(QaFilters::KEYS, ['commit']), 'commitRef']);
        $this->resetPage();
    }

    // ───────────────────────────── Drill-downs ─────────────────────────────

    public function openCase(int $id): void
    {
        $this->caseId = QaTestCase::findOrFail($id)->id;
        $this->resetResultForm();
        $this->evidenceForm = ['kind' => 'screenshot', 'note' => ''];
        $this->showFinding = false;
        $this->showCase = true;
    }

    public function openFinding(int $id): void
    {
        $finding = QaFinding::findOrFail($id);
        $this->findingId = $finding->id;
        $this->statusForm = ['status' => $finding->status->value, 'note' => ''];
        $this->fixForm = ['commit_hash' => '', 'commit_message' => '', 'files' => '', 'tests' => $finding->testCase?->code ?? '', 'note' => '', 'environment' => QaEnvironment::Staging->value];
        $this->resultForm['note'] = '';
        $this->evidenceForm = ['kind' => 'screenshot', 'note' => ''];
        $this->showCase = false;
        $this->showFinding = true;
    }

    // ───────────────────────────── Test cases ─────────────────────────────

    public function newCase(): void
    {
        $this->authorizeManage();
        $this->editingCaseId = null;
        $this->caseForm = ['code' => '', 'qa_phase_id' => $this->phase ?: (string) QaPhase::where('status', QaPhaseStatus::InProgress->value)->value('id'), 'module' => '', 'feature' => '', 'role' => '', 'scope' => '',
            'title' => '', 'description' => '', 'precondition' => '', 'steps' => '', 'expected_result' => '', 'route_name' => '', 'severity' => QaSeverity::Medium->value, 'automated_reference' => ''];
        $this->resetValidation();
        $this->showCaseForm = true;
    }

    public function editCase(int $id): void
    {
        $this->authorizeManage();
        $case = QaTestCase::findOrFail($id);
        $this->editingCaseId = $case->id;
        $this->caseForm = [...$case->only(['code', 'module', 'feature', 'role', 'scope', 'title', 'description', 'precondition', 'steps', 'expected_result', 'route_name', 'automated_reference']),
            'qa_phase_id' => (string) $case->qa_phase_id, 'severity' => $case->severity->value];
        $this->resetValidation();
        $this->showCaseForm = true;
    }

    public function saveCase(QaTracker $tracker): void
    {
        $this->authorizeManage();
        $data = $this->validate([
            'caseForm.code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('qa_test_cases', 'code')->ignore($this->editingCaseId)],
            'caseForm.qa_phase_id' => ['nullable', 'exists:qa_phases,id'],
            'caseForm.module' => ['required', 'string', 'max:60'],
            'caseForm.feature' => ['nullable', 'string', 'max:255'],
            'caseForm.role' => ['nullable', 'string', 'max:60'],
            'caseForm.scope' => ['nullable', 'string', 'max:60'],
            'caseForm.title' => ['required', 'string', 'max:255'],
            'caseForm.description' => ['nullable', 'string', 'max:5000'],
            'caseForm.precondition' => ['nullable', 'string', 'max:5000'],
            'caseForm.steps' => ['nullable', 'string', 'max:5000'],
            'caseForm.expected_result' => ['nullable', 'string', 'max:5000'],
            'caseForm.route_name' => ['nullable', 'string', 'max:255'],
            'caseForm.severity' => ['required', Rule::enum(QaSeverity::class)],
            'caseForm.automated_reference' => ['nullable', 'string', 'max:255'],
        ], attributes: ['caseForm.module' => 'module', 'caseForm.title' => 'title', 'caseForm.code' => 'test ID'])['caseForm'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        if ($this->editingCaseId !== null) {
            $case = $tracker->updateTestCase(QaTestCase::findOrFail($this->editingCaseId), $data, $this->user());
        } else {
            $case = $tracker->createTestCase($data, $this->user());
        }

        $this->showCaseForm = false;
        Flux::toast("Test case {$case->code} saved.");
        $this->openCase($case->id);
    }

    public function recordResult(QaTracker $tracker): void
    {
        $this->authorizeManage();
        $case = QaTestCase::findOrFail($this->caseId);
        $this->validate([
            'resultForm.status' => ['required', Rule::in(array_map(fn (QaTestStatus $s) => $s->value, QaTestStatus::manual()))],
            'resultForm.environment' => ['required', Rule::enum(QaEnvironment::class)],
            'resultForm.actual_result' => ['nullable', 'string', 'max:5000'],
            'resultForm.note' => ['nullable', 'string', 'max:5000'],
            'resultForm.commit' => ['nullable', 'string', 'max:40', 'regex:/^[0-9a-fA-F]{7,40}$/'],
        ], attributes: ['resultForm.commit' => 'commit']);

        $this->catchingValidation('resultForm', fn () => $tracker->recordResult(
            $case,
            QaTestStatus::from($this->resultForm['status']),
            $this->user(),
            $this->resultForm['environment'],
            $this->resultForm['actual_result'] ?: null,
            $this->resultForm['note'] ?: null,
            $this->resultForm['commit'] ?: null,
        ));

        if ($this->getErrorBag()->isEmpty()) {
            $this->resetResultForm();
            Flux::toast("Result recorded for {$case->code}.");
        }
    }

    // ───────────────────────────── Findings ─────────────────────────────

    public function newFinding(?int $fromCaseId = null): void
    {
        $this->authorizeManage();
        $case = $fromCaseId ? QaTestCase::find($fromCaseId) : null;
        $this->editingFindingId = null;
        $this->findingForm = ['code' => '', 'qa_phase_id' => (string) ($case?->qa_phase_id ?? ''), 'qa_test_case_id' => (string) ($case?->id ?? ''), 'kind' => 'functional',
            'module' => $case?->module ?? '', 'title' => '', 'description' => '', 'severity' => QaSeverity::Medium->value, 'risk' => '', 'role_affected' => $case?->role ?? '',
            'scope_affected' => $case?->scope ?? '', 'route' => $case?->route_name ?? '', 'page' => '', 'viewport' => '', 'theme' => '', 'root_cause' => '', 'environment' => QaEnvironment::Staging->value];
        $this->resetValidation();
        $this->showCase = false;
        $this->showFindingForm = true;
    }

    public function editFinding(int $id): void
    {
        $this->authorizeManage();
        $finding = QaFinding::findOrFail($id);
        $this->editingFindingId = $finding->id;
        $this->findingForm = [...$finding->only(['code', 'kind', 'module', 'title', 'description', 'risk', 'role_affected', 'scope_affected', 'route', 'page', 'viewport', 'theme', 'root_cause', 'environment']),
            'qa_phase_id' => (string) $finding->qa_phase_id, 'qa_test_case_id' => (string) $finding->qa_test_case_id, 'severity' => $finding->severity->value];
        $this->resetValidation();
        $this->showFinding = false;
        $this->showFindingForm = true;
    }

    public function saveFinding(QaTracker $tracker): void
    {
        $this->authorizeManage();
        $data = $this->validate([
            'findingForm.code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('qa_findings', 'code')->ignore($this->editingFindingId)],
            'findingForm.qa_phase_id' => ['nullable', 'exists:qa_phases,id'],
            'findingForm.qa_test_case_id' => ['nullable', 'exists:qa_test_cases,id'],
            'findingForm.kind' => ['required', Rule::in(array_keys(QaFinding::KINDS))],
            'findingForm.module' => ['required', 'string', 'max:60'],
            'findingForm.title' => ['required', 'string', 'max:255'],
            'findingForm.description' => ['nullable', 'string', 'max:5000'],
            'findingForm.severity' => ['required', Rule::enum(QaSeverity::class)],
            'findingForm.risk' => ['nullable', 'string', 'max:5000'],
            'findingForm.role_affected' => ['nullable', 'string', 'max:60'],
            'findingForm.scope_affected' => ['nullable', 'string', 'max:60'],
            'findingForm.route' => ['nullable', 'string', 'max:255'],
            'findingForm.page' => ['nullable', 'string', 'max:255'],
            'findingForm.viewport' => ['nullable', 'string', 'max:60'],
            'findingForm.theme' => ['nullable', 'string', 'max:20'],
            'findingForm.root_cause' => ['nullable', 'string', 'max:5000'],
            'findingForm.environment' => ['nullable', Rule::enum(QaEnvironment::class)],
        ], attributes: ['findingForm.module' => 'module', 'findingForm.title' => 'title'])['findingForm'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        $finding = $this->editingFindingId !== null
            ? $tracker->updateFinding(QaFinding::findOrFail($this->editingFindingId), $data, $this->user())
            : $tracker->openFinding($data, $this->user());

        $this->showFindingForm = false;
        Flux::toast("Finding {$finding->code} saved.");
        $this->openFinding($finding->id);
    }

    public function changeFindingStatus(QaTracker $tracker): void
    {
        $this->authorizeManage();
        $this->validate([
            'statusForm.status' => ['required', Rule::enum(QaFindingStatus::class)],
            'statusForm.note' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->catchingValidation('statusForm', fn () => $tracker->changeFindingStatus(
            QaFinding::findOrFail($this->findingId), QaFindingStatus::from($this->statusForm['status']), $this->user(), $this->statusForm['note'] ?: null,
        ));

        if ($this->getErrorBag()->isEmpty()) {
            $this->statusForm['note'] = '';
            Flux::toast('Finding status updated.');
        }
    }

    public function linkFix(QaTracker $tracker): void
    {
        $this->authorizeManage();
        $this->validate([
            'fixForm.commit_hash' => ['required', 'string', 'regex:/^[0-9a-fA-F]{7,40}$/'],
            'fixForm.commit_message' => ['nullable', 'string', 'max:255'],
            'fixForm.files' => ['nullable', 'string', 'max:5000'],
            'fixForm.tests' => ['nullable', 'string', 'max:1000'],
            'fixForm.note' => ['nullable', 'string', 'max:5000'],
            'fixForm.environment' => ['nullable', Rule::enum(QaEnvironment::class)],
        ], attributes: ['fixForm.commit_hash' => 'commit hash']);

        $split = fn (string $v, string $pattern) => array_values(array_filter(array_map('trim', preg_split($pattern, $v) ?: [])));
        $tracker->linkFix(QaFinding::findOrFail($this->findingId), [
            'commit_hash' => $this->fixForm['commit_hash'],
            'commit_message' => $this->fixForm['commit_message'] ?: null,
            'files_changed' => $split((string) $this->fixForm['files'], '/[\r\n,]+/'),
            'test_case_codes' => $split((string) $this->fixForm['tests'], '/[\s,]+/'),
            'developer_note' => $this->fixForm['note'] ?: null,
            'environment' => $this->fixForm['environment'] ?: null,
        ], $this->user());

        $this->openFinding($this->findingId);
        Flux::toast('Fix linked. Linked tests now wait for a retest.');
    }

    public function retestFinding(bool $passed, QaTracker $tracker): void
    {
        $this->authorizeManage();
        $this->validate([
            'resultForm.note' => ['required', 'string', 'max:5000'],
            'resultForm.environment' => ['required', Rule::enum(QaEnvironment::class)],
            'resultForm.commit' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{7,40}$/'],
        ], attributes: ['resultForm.note' => 'retest result']);

        $tracker->retestFinding(QaFinding::findOrFail($this->findingId), $passed, $this->user(), $this->resultForm['note'], $this->resultForm['environment'], $this->resultForm['commit'] ?: null);

        $this->openFinding($this->findingId);
        Flux::toast($passed ? 'Retest passed — finding verified.' : 'Retest failed — finding reopened.');
    }

    // ───────────────────────────── Evidence ─────────────────────────────

    public function addEvidence(QaTracker $tracker): void
    {
        $this->authorizeManage();
        $this->validate([
            'evidenceForm.kind' => ['required', Rule::in(array_keys(QaEvidence::KINDS))],
            'evidenceForm.note' => ['nullable', 'string', 'max:5000'],
            'evidenceFile' => ['nullable', 'file', 'max:10240', 'mimes:png,jpg,jpeg,webp,pdf,txt,log'],
        ]);

        $record = $this->showFinding ? QaFinding::findOrFail($this->findingId) : QaTestCase::findOrFail($this->caseId);
        $this->catchingValidation('evidenceForm', fn () => $tracker->addEvidence($record, $this->evidenceForm['kind'], $this->user(), $this->evidenceFile, $this->evidenceForm['note'] ?: null));

        if ($this->getErrorBag()->isEmpty()) {
            $this->evidenceForm = ['kind' => 'screenshot', 'note' => ''];
            $this->evidenceFile = null;
            Flux::toast('Evidence attached.');
        }
    }

    // ───────────────────────────── Runs ─────────────────────────────

    public function newRun(): void
    {
        $this->authorizeRuns();
        $this->runForm = ['name' => now()->format('Y-m-d').' Regression', 'qa_phase_id' => '', 'suite' => 'pest', 'kind' => 'targeted', 'environment' => QaEnvironment::LocalTestDb->value, 'commit' => '',
            'total' => '', 'passed' => '', 'failed' => '0', 'skipped' => '0', 'known_baseline' => '0', 'duration_seconds' => '', 'notes' => ''];
        $this->runFile = null;
        $this->resetValidation();
        $this->showRunForm = true;
    }

    /** Fill the run form from an uploaded JUnit XML / Pest summary. */
    public function updatedRunFile(TestRunImporter $importer): void
    {
        $this->authorizeRuns();
        $this->validate(['runFile' => ['file', 'max:20480', 'mimes:xml,txt,log']]);

        $this->catchingValidation('runFile', function () use ($importer) {
            $summary = $importer->parse((string) file_get_contents($this->runFile->getRealPath()));
            $this->runForm = [...$this->runForm, ...collect($summary)->only(['total', 'passed', 'failed', 'skipped', 'known_baseline', 'duration_seconds'])->map(fn ($v) => (string) $v)->all(),
                'notes' => $summary['failures'] === [] ? '' : "Failures:\n".implode("\n", $summary['failures'])];
        });
    }

    public function saveRun(QaTracker $tracker): void
    {
        $this->authorizeRuns();
        $data = $this->validate([
            'runForm.name' => ['required', 'string', 'max:255'],
            'runForm.qa_phase_id' => ['nullable', 'exists:qa_phases,id'],
            'runForm.suite' => ['required', Rule::in([...array_keys(QaTestRun::SUITES), 'migration'])],
            'runForm.kind' => ['required', Rule::in(array_keys(QaTestRun::KINDS))],
            'runForm.environment' => ['required', Rule::enum(QaEnvironment::class)],
            'runForm.commit' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{7,40}$/'],
            'runForm.total' => ['required', 'integer', 'min:0'],
            'runForm.passed' => ['required', 'integer', 'min:0', 'lte:runForm.total'],
            'runForm.failed' => ['required', 'integer', 'min:0'],
            'runForm.skipped' => ['nullable', 'integer', 'min:0'],
            'runForm.known_baseline' => ['nullable', 'integer', 'min:0', 'lte:runForm.failed'],
            'runForm.duration_seconds' => ['nullable', 'integer', 'min:0'],
            'runForm.notes' => ['nullable', 'string', 'max:10000'],
        ], attributes: ['runForm.passed' => 'passed', 'runForm.known_baseline' => 'known baseline'])['runForm'];

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $run = $tracker->recordRun([...$data, 'skipped' => (int) ($data['skipped'] ?? 0), 'known_baseline' => (int) ($data['known_baseline'] ?? 0)], $this->user());

        $this->showRunForm = false;
        Flux::toast("Run recorded: {$run->resultLabel()}.");
    }

    // ───────────────────────────── Checklist grids ─────────────────────────────

    public function editCell(string $category, string $subject, string $dimension): void
    {
        $this->authorizeManage();
        abort_unless(in_array($category, [QaChecklistItem::MODULE, QaChecklistItem::RESPONSIVE, QaChecklistItem::DEMO], true), 404);
        $item = QaChecklistItem::where(compact('category', 'subject', 'dimension'))->first();
        $this->cellForm = ['category' => $category, 'subject' => $subject, 'dimension' => $dimension, 'status' => $item?->status ?? 'pass', 'note' => $item?->note ?? '', 'commit' => $item?->commit ?? ''];
        $this->resetValidation();
        $this->showCellForm = true;
    }

    public function saveCell(QaTracker $tracker): void
    {
        $this->authorizeManage();
        $this->validate([
            'cellForm.status' => ['required', Rule::in(array_keys(QaChecklistItem::STATUSES))],
            'cellForm.note' => ['nullable', 'string', 'max:5000'],
            'cellForm.commit' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{7,40}$/'],
        ]);

        $this->catchingValidation('cellForm', fn () => $tracker->setChecklist(
            $this->cellForm['category'], $this->cellForm['subject'], $this->cellForm['dimension'], $this->cellForm['status'], $this->user(),
            $this->cellForm['note'] ?: null, $this->cellForm['commit'] ?: null,
        ));

        if ($this->getErrorBag()->isEmpty()) {
            $this->showCellForm = false;
        }
    }

    // ───────────────────────────── Phases ─────────────────────────────

    public function editPhase(int $id): void
    {
        $this->authorizeManage();
        $phase = QaPhase::findOrFail($id);
        $this->editingPhaseId = $phase->id;
        $this->phaseForm = ['status' => $phase->status->value, 'owner' => $phase->owner ?? '', 'started_at' => $phase->started_at?->toDateString() ?? '',
            'completed_at' => $phase->completed_at?->toDateString() ?? '', 'notes' => $phase->notes ?? ''];
        $this->showPhaseForm = true;
    }

    public function savePhase(QaTracker $tracker): void
    {
        $this->authorizeManage();
        $data = $this->validate([
            'phaseForm.status' => ['required', Rule::enum(QaPhaseStatus::class)],
            'phaseForm.owner' => ['nullable', 'string', 'max:255'],
            'phaseForm.started_at' => ['nullable', 'date'],
            'phaseForm.completed_at' => ['nullable', 'date'],
            'phaseForm.notes' => ['nullable', 'string', 'max:5000'],
        ])['phaseForm'];

        $tracker->updatePhase(QaPhase::findOrFail($this->editingPhaseId), array_map(fn ($v) => $v === '' ? null : $v, $data), $this->user());
        $this->showPhaseForm = false;
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    /** "Open Page" only for a page this viewer can open and that needs no parameters. */
    public function pageUrl(?string $routeName): ?string
    {
        if ($routeName === null || ! Route::has($routeName)) {
            return null;
        }

        $route = Route::getRoutes()->getByName($routeName);
        if ($route->parameterNames() !== [] || ! app(RouteAccess::class)->allows($this->user(), $routeName)) {
            return null;
        }

        return route($routeName);
    }

    private function resetResultForm(): void
    {
        $this->resultForm = ['status' => QaTestStatus::Pass->value, 'environment' => QaEnvironment::Staging->value, 'actual_result' => '', 'note' => '', 'commit' => ''];
    }

    /** Run a tracker call, turning its validation errors into this form's error bag. */
    private function catchingValidation(string $form, callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(str_contains($field, '.') || $field === 'runFile' ? $field : "{$form}.{$field}", $messages[0]);
            }
        }
    }

    private function authorizeManage(): void
    {
        abort_unless($this->user()->hasPermission('manage_qa_audit'), 403);
    }

    private function authorizeRuns(): void
    {
        abort_unless($this->user()->hasPermission('run_qa_checks') || $this->user()->hasPermission('manage_qa_audit'), 403);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    /** @return array<string, mixed> */
    private function filterValues(): array
    {
        return collect(QaFilters::KEYS)->mapWithKeys(fn (string $k) => [$k => $k === 'commit' ? $this->commitRef : $this->{$k}])->all();
    }

    public function render(QaReadiness $readiness, QaTracker $tracker)
    {
        $filters = QaFilters::from($this->filterValues());
        $user = $this->user();
        $data = [
            'summary' => $readiness->summary(),
            'phases' => QaPhase::orderBy('number')->get(['id', 'number', 'name']),
            'modules' => QaTestCase::query()->distinct()->orderBy('module')->pluck('module')->merge(QaFinding::query()->distinct()->pluck('module'))->unique()->sort()->values(),
            'canManage' => $user->hasPermission('manage_qa_audit'),
            'canRun' => $user->hasPermission('run_qa_checks') || $user->hasPermission('manage_qa_audit'),
            'canExport' => $user->hasPermission('export_qa_report'),
            'filterQuery' => $filters->toArray(),
            'hasFilters' => $filters->toArray() !== [],
        ];

        $data += match ($this->tab) {
            'overview' => [
                'recentFindings' => QaFinding::open()->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")->limit(6)->get(),
                'phaseRows' => $readiness->phases(),
                'recentRuns' => QaTestRun::latest('run_at')->limit(3)->get(),
            ],
            'phases' => ['phaseRows' => $readiness->phases()],
            'cases' => ['cases' => $filters->cases()->paginate(25)],
            'findings' => ['findings' => $filters->findings()->paginate(25)],
            'ui' => ['findings' => $filters->findings('ui')->paginate(25)],
            'fixes' => ['fixes' => $filters->fixes()->paginate(25)],
            'runs' => ['runs' => $filters->runs()->with('phase:id,number')->paginate(25)],
            'matrix' => ['matrix' => app(RoleAccessMatrix::class)->build()],
            'modules', 'responsive', 'demo' => ['cells' => QaChecklistItem::where('category', match ($this->tab) {
                'modules' => QaChecklistItem::MODULE, 'responsive' => QaChecklistItem::RESPONSIVE, default => QaChecklistItem::DEMO,
            })->get()->keyBy(fn (QaChecklistItem $i) => $i->subject.'|'.$i->dimension)],
            default => [],
        };

        $viewCase = $this->showCase && $this->caseId ? QaTestCase::with(['phase', 'results', 'findings', 'evidence.uploader:id,name'])->find($this->caseId) : null;
        $viewFinding = $this->showFinding && $this->findingId ? QaFinding::with(['phase', 'testCase', 'fixes', 'evidence.uploader:id,name'])->find($this->findingId) : null;

        return view('livewire.qa.qa-audit', [
            ...$data,
            'viewCase' => $viewCase,
            'viewFinding' => $viewFinding,
            'history' => $viewCase ? $tracker->history($viewCase) : ($viewFinding ? $tracker->history($viewFinding) : collect()),
        ])->layout('layouts.app', ['title' => 'QA & Audit']);
    }
}
