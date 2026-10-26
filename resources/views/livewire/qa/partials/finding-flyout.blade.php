<flux:modal wire:model.self="showFinding" variant="flyout" class="w-full max-w-2xl" data-finding-flyout>
    @if($viewFinding)
        @php $openPage = $this->pageUrl($viewFinding->route); @endphp
        <div class="space-y-5">
            <div class="space-y-2 pr-8">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-sm font-bold text-zinc-700 dark:text-zinc-200">{{ $viewFinding->code }}</span>
                    <flux:badge size="sm" :color="$viewFinding->severity->color()">{{ $viewFinding->severity->label() }}</flux:badge>
                    <flux:badge size="sm" :color="$viewFinding->status->color()">{{ $viewFinding->status->label() }}</flux:badge>
                    <flux:badge size="sm" color="zinc">{{ \App\Models\QaFinding::KINDS[$viewFinding->kind] ?? $viewFinding->kind }}</flux:badge>
                </div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $viewFinding->title }}</h2>
                <div class="flex flex-wrap gap-2">
                    @if($openPage)
                        <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="$openPage" target="_blank" data-open-page>Open page</flux:button>
                    @endif
                    @if($viewFinding->testCase)
                        <flux:button size="xs" variant="ghost" icon="clipboard-document-list" wire:click="openCase({{ $viewFinding->testCase->id }})">{{ $viewFinding->testCase->code }}</flux:button>
                    @endif
                    @if($canManage)
                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editFinding({{ $viewFinding->id }})">Edit</flux:button>
                    @endif
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                @foreach([['Module', $viewFinding->module], ['Phase', $viewFinding->phase?->number], ['Role', $viewFinding->role_affected], ['Scope', $viewFinding->scope_affected], ['Route / page', $viewFinding->route ?? $viewFinding->page], ['Environment', \App\Enums\QaEnvironment::tryFrom((string) $viewFinding->environment)?->label()], ['Viewport', $viewFinding->viewport], ['Theme', $viewFinding->theme], ['Found', $viewFinding->found_at?->format('d M Y')], ['Closed', $viewFinding->closed_at?->format('d M Y')]] as [$label, $value])
                    @if($value)
                        <div class="min-w-0">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</dt>
                            <dd class="break-words text-zinc-800 dark:text-zinc-100">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>

            @foreach([['Description', $viewFinding->description], ['Risk', $viewFinding->risk], ['Root cause', $viewFinding->root_cause], ['Fix', $viewFinding->fix_summary], ['Retest result', $viewFinding->retest_result]] as [$label, $value])
                @if($value)
                    <div>
                        <h4 class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</h4>
                        <p class="mt-0.5 whitespace-pre-line break-words text-sm text-zinc-700 dark:text-zinc-200">{{ $value }}</p>
                    </div>
                @endif
            @endforeach

            <section class="space-y-2" data-fixes>
                <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Fixes</h4>
                @forelse($viewFinding->fixes as $fix)
                    <div class="rounded-lg border border-zinc-100 p-3 text-xs dark:border-white/10">
                        <p class="flex flex-wrap items-center gap-2"><span class="rounded bg-zinc-900 px-1.5 py-0.5 font-mono font-semibold text-white dark:bg-white dark:text-zinc-900">{{ $fix->shortHash() }}</span>
                            <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $fix->commit_message }}</span></p>
                        <p class="mt-1 text-zinc-500">{{ $fix->fixed_at?->toImmutable()->userDateTime() }}@if($fix->test_case_codes) · tests {{ implode(', ', $fix->test_case_codes) }}@endif @if($fix->files_changed) · {{ count($fix->files_changed) }} file(s)@endif</p>
                        @if($fix->developer_note)<p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ $fix->developer_note }}</p>@endif
                    </div>
                @empty
                    <p class="text-xs text-zinc-500">No fix linked yet.</p>
                @endforelse
            </section>

            @if($canManage)
                <div class="space-y-3 rounded-xl bg-zinc-50 p-4 dark:bg-white/5">
                    <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Workflow</h4>

                    <form wire:submit="changeFindingStatus" class="space-y-2" data-status-form>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                            <select wire:model="statusForm.status" class="{{ $inputClass }}" aria-label="Finding status">
                                @foreach(\App\Enums\QaFindingStatus::cases() as $s)
                                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                                @endforeach
                            </select>
                            <flux:button type="submit" size="sm">Update status</flux:button>
                        </div>
                        <textarea wire:model="statusForm.note" rows="2" placeholder="Note (required for Accepted Risk, Won't Fix and Verified)" class="{{ $inputClass }} h-auto py-2"></textarea>
                        @error('statusForm.note')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    </form>

                    <details class="rounded-lg bg-white p-3 dark:bg-zinc-900" @if($errors->has('fixForm.*')) open @endif>
                        <summary class="cursor-pointer text-sm font-medium text-zinc-800 dark:text-zinc-100">Link a fix commit</summary>
                        <form wire:submit="linkFix" class="mt-3 space-y-2" data-fix-form>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <input type="text" wire:model="fixForm.commit_hash" placeholder="Commit hash" class="{{ $inputClass }} font-mono" aria-label="Commit hash">
                                <select wire:model="fixForm.environment" class="{{ $inputClass }}" aria-label="Environment">
                                    @foreach(\App\Enums\QaEnvironment::cases() as $e)
                                        <option value="{{ $e->value }}">{{ $e->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <input type="text" wire:model="fixForm.commit_message" placeholder="Commit message" class="{{ $inputClass }}">
                            <input type="text" wire:model="fixForm.tests" placeholder="Test IDs, e.g. QA-RBAC-002 QA-RBAC-003" class="{{ $inputClass }} font-mono">
                            <textarea wire:model="fixForm.files" rows="2" placeholder="Files changed (one per line)" class="{{ $inputClass }} h-auto py-2 font-mono text-xs"></textarea>
                            <textarea wire:model="fixForm.note" rows="2" placeholder="Developer note" class="{{ $inputClass }} h-auto py-2"></textarea>
                            @error('fixForm.commit_hash')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                            <flux:button type="submit" size="sm" icon="link">Link fix</flux:button>
                        </form>
                    </details>

                    @if(in_array($viewFinding->status, [\App\Enums\QaFindingStatus::Fixed, \App\Enums\QaFindingStatus::RetestRequired], true))
                        <div class="space-y-2 rounded-lg bg-white p-3 dark:bg-zinc-900" data-retest>
                            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">Retest</p>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <select wire:model="resultForm.environment" class="{{ $inputClass }}" aria-label="Retest environment">
                                    @foreach(\App\Enums\QaEnvironment::cases() as $e)
                                        <option value="{{ $e->value }}">{{ $e->label() }}</option>
                                    @endforeach
                                </select>
                                <input type="text" wire:model="resultForm.commit" placeholder="Retested on commit" class="{{ $inputClass }} font-mono">
                            </div>
                            <textarea wire:model="resultForm.note" rows="2" placeholder="What was retested and what happened" class="{{ $inputClass }} h-auto py-2"></textarea>
                            @error('resultForm.note')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="sm" variant="primary" icon="check-badge" wire:click="retestFinding(true)">Retest passed — verify</flux:button>
                                <flux:button size="sm" variant="danger" icon="arrow-uturn-left" wire:click="retestFinding(false)">Retest failed — reopen</flux:button>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            @include('livewire.qa.partials.evidence', ['evidenceOf' => $viewFinding])
            @include('livewire.qa.partials.history')
        </div>
    @endif
</flux:modal>
