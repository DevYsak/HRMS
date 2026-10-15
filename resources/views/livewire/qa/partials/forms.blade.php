{{-- ── Test case ── --}}
@if($canManage)
<flux:modal wire:model.self="showCaseForm" class="w-full max-w-2xl" data-case-form>
    <form wire:submit="saveCase" class="space-y-4">
        <flux:heading size="lg">{{ $editingCaseId ? 'Edit test case' : 'New test case' }}</flux:heading>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <flux:input wire:model="caseForm.code" label="Test ID" placeholder="Auto (QA-T-###) or e.g. ATT-001" :disabled="(bool) $editingCaseId" />
            <flux:select wire:model="caseForm.qa_phase_id" label="Phase">
                <flux:select.option value="">—</flux:select.option>
                @foreach($phases as $p)
                    <flux:select.option value="{{ $p->id }}">{{ $p->number }} · {{ $p->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="caseForm.module" label="Module" placeholder="Attendance" required />
            <flux:input wire:model="caseForm.feature" label="Feature" />
            <flux:input wire:model="caseForm.role" label="Role" placeholder="Manager" />
            <flux:input wire:model="caseForm.scope" label="Scope" placeholder="Self / Team / Department / Selected / Company" />
            <flux:select wire:model="caseForm.severity" label="Severity">
                @foreach(\App\Enums\QaSeverity::cases() as $s)
                    <flux:select.option value="{{ $s->value }}">{{ $s->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="caseForm.route_name" label="Route name" placeholder="attendance.my" />
        </div>
        <flux:input wire:model="caseForm.title" label="Title" required />
        <flux:textarea wire:model="caseForm.description" label="Description" rows="2" />
        <flux:textarea wire:model="caseForm.precondition" label="Precondition" rows="2" />
        <flux:textarea wire:model="caseForm.steps" label="Steps" rows="3" />
        <flux:textarea wire:model="caseForm.expected_result" label="Expected result" rows="2" />
        <flux:input wire:model="caseForm.automated_reference" label="Automated test" placeholder="tests/Feature/… > test name" />
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">Save</flux:button>
        </div>
    </form>
</flux:modal>

{{-- ── Finding ── --}}
<flux:modal wire:model.self="showFindingForm" class="w-full max-w-2xl" data-finding-form>
    <form wire:submit="saveFinding" class="space-y-4">
        <flux:heading size="lg">{{ $editingFindingId ? 'Edit finding' : 'New finding' }}</flux:heading>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <flux:input wire:model="findingForm.code" label="Finding ID" placeholder="Auto (QA-F-###)" :disabled="(bool) $editingFindingId" />
            <flux:select wire:model="findingForm.kind" label="Type">
                @foreach(\App\Models\QaFinding::KINDS as $k => $l)
                    <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="findingForm.module" label="Module" required />
            <flux:select wire:model="findingForm.severity" label="Severity">
                @foreach(\App\Enums\QaSeverity::cases() as $s)
                    <flux:select.option value="{{ $s->value }}">{{ $s->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="findingForm.qa_phase_id" label="Phase">
                <flux:select.option value="">—</flux:select.option>
                @foreach($phases as $p)
                    <flux:select.option value="{{ $p->id }}">{{ $p->number }} · {{ $p->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="findingForm.environment" label="Environment">
                <flux:select.option value="">—</flux:select.option>
                @foreach(\App\Enums\QaEnvironment::cases() as $e)
                    <flux:select.option value="{{ $e->value }}">{{ $e->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="findingForm.role_affected" label="Role affected" />
            <flux:input wire:model="findingForm.scope_affected" label="Scope affected" />
            <flux:input wire:model="findingForm.route" label="Route name" />
            <flux:input wire:model="findingForm.page" label="Page (UI findings)" />
            <flux:input wire:model="findingForm.viewport" label="Viewport" placeholder="375px" />
            <flux:input wire:model="findingForm.theme" label="Theme" placeholder="Light / Dark" />
        </div>
        <flux:input wire:model="findingForm.title" label="Title" required />
        <flux:textarea wire:model="findingForm.description" label="Description" rows="3" />
        <flux:textarea wire:model="findingForm.risk" label="Risk" rows="2" />
        <flux:textarea wire:model="findingForm.root_cause" label="Root cause" rows="2" />
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">Save</flux:button>
        </div>
    </form>
</flux:modal>

{{-- ── Checklist cell ── --}}
<flux:modal wire:model.self="showCellForm" class="w-full max-w-md" data-cell-form>
    <form wire:submit="saveCell" class="space-y-4">
        <div>
            <flux:heading size="lg">{{ $cellForm['subject'] ?? '' }}</flux:heading>
            <flux:subheading>{{ $cellForm['dimension'] ?? '' }}</flux:subheading>
        </div>
        <flux:select wire:model="cellForm.status" label="Status">
            @foreach(\App\Models\QaChecklistItem::STATUSES as $k => $l)
                <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:textarea wire:model="cellForm.note" label="Note" rows="2" placeholder="Required for FAIL" />
        <flux:input wire:model="cellForm.commit" label="Commit" class="font-mono" />
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">Save</flux:button>
        </div>
    </form>
</flux:modal>

{{-- ── Phase ── --}}
<flux:modal wire:model.self="showPhaseForm" class="w-full max-w-md" data-phase-form>
    <form wire:submit="savePhase" class="space-y-4">
        <flux:heading size="lg">Update phase</flux:heading>
        <flux:select wire:model="phaseForm.status" label="Status">
            @foreach(\App\Enums\QaPhaseStatus::cases() as $s)
                <flux:select.option value="{{ $s->value }}">{{ $s->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="phaseForm.owner" label="Owner" />
        <div class="grid grid-cols-2 gap-3">
            <flux:input type="date" wire:model="phaseForm.started_at" label="Started" />
            <flux:input type="date" wire:model="phaseForm.completed_at" label="Completed" />
        </div>
        <flux:textarea wire:model="phaseForm.notes" label="Notes" rows="3" />
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">Save</flux:button>
        </div>
    </form>
</flux:modal>
@endif

{{-- ── Test run ── --}}
@if($canRun)
<flux:modal wire:model.self="showRunForm" class="w-full max-w-2xl" data-run-form>
    <form wire:submit="saveRun" class="space-y-4">
        <div>
            <flux:heading size="lg">Record a test run</flux:heading>
            <flux:subheading>Upload a JUnit XML (Pest --log-junit, PHPUnit, Playwright) or a saved Pest summary to fill the counts, or type them. Failures matching a KNOWN BASELINE test are not regressions.</flux:subheading>
        </div>
        <div>
            <input type="file" wire:model="runFile" accept=".xml,.txt,.log" class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-800" aria-label="Run summary file">
            @error('runFile')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <flux:input wire:model="runForm.name" label="Run name" required />
            <flux:select wire:model="runForm.qa_phase_id" label="Phase">
                <flux:select.option value="">—</flux:select.option>
                @foreach($phases as $p)
                    <flux:select.option value="{{ $p->id }}">{{ $p->number }} · {{ $p->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="runForm.suite" label="Suite">
                @foreach([...\App\Models\QaTestRun::SUITES, 'migration' => 'Migration'] as $k => $l)
                    <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="runForm.kind" label="Run type">
                @foreach(\App\Models\QaTestRun::KINDS as $k => $l)
                    <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="runForm.environment" label="Environment">
                @foreach(\App\Enums\QaEnvironment::cases() as $e)
                    <flux:select.option value="{{ $e->value }}">{{ $e->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="runForm.commit" label="Commit" class="font-mono" />
            <flux:input type="number" wire:model="runForm.duration_seconds" label="Duration (seconds)" min="0" />
        </div>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <flux:input type="number" wire:model="runForm.total" label="Total" min="0" required />
            <flux:input type="number" wire:model="runForm.passed" label="Passed" min="0" required />
            <flux:input type="number" wire:model="runForm.failed" label="Failed" min="0" required />
            <flux:input type="number" wire:model="runForm.skipped" label="Skipped" min="0" />
            <flux:input type="number" wire:model="runForm.known_baseline" label="Known baseline" min="0" />
        </div>
        <flux:textarea wire:model="runForm.notes" label="Notes" rows="3" />
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">Record run</flux:button>
        </div>
    </form>
</flux:modal>
@endif
