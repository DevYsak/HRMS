<flux:modal wire:model.self="showCase" variant="flyout" class="w-full max-w-2xl" data-case-flyout>
    @if($viewCase)
        @php $openPage = $this->pageUrl($viewCase->route_name); @endphp
        <div class="space-y-5">
            <div class="space-y-2 pr-8">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-sm font-bold text-zinc-700 dark:text-zinc-200">{{ $viewCase->code }}</span>
                    <flux:badge size="sm" :color="$viewCase->status->color()">{{ $viewCase->status->label() }}</flux:badge>
                    <flux:badge size="sm" :color="$viewCase->severity->color()">{{ $viewCase->severity->label() }}</flux:badge>
                </div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $viewCase->title }}</h2>
                <div class="flex flex-wrap gap-2">
                    @if($openPage)
                        <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="$openPage" target="_blank" data-open-page>Open page</flux:button>
                    @endif
                    @if($canManage)
                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editCase({{ $viewCase->id }})">Edit</flux:button>
                        <flux:button size="xs" variant="ghost" icon="bug-ant" wire:click="newFinding({{ $viewCase->id }})">Raise finding</flux:button>
                    @endif
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                @foreach([['Module', $viewCase->module], ['Feature', $viewCase->feature], ['Phase', $viewCase->phase ? $viewCase->phase->number.' · '.$viewCase->phase->name : null], ['Role', $viewCase->role], ['Scope', $viewCase->scope], ['Route', $viewCase->route_name]] as [$label, $value])
                    <div class="min-w-0">
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</dt>
                        <dd class="break-words text-zinc-800 dark:text-zinc-100">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>

            @foreach([['Description', $viewCase->description], ['Precondition', $viewCase->precondition], ['Steps', $viewCase->steps], ['Expected result', $viewCase->expected_result], ['Automated test', $viewCase->automated_reference]] as [$label, $value])
                @if($value)
                    <div>
                        <h4 class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</h4>
                        <p class="mt-0.5 whitespace-pre-line break-words text-sm text-zinc-700 dark:text-zinc-200 {{ $label === 'Automated test' ? 'font-mono text-xs' : '' }}">{{ $value }}</p>
                    </div>
                @endif
            @endforeach

            @if($canManage)
                <form wire:submit="recordResult" class="space-y-3 rounded-xl bg-zinc-50 p-4 dark:bg-white/5" data-record-result>
                    <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Record a result</h4>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <select wire:model="resultForm.status" class="{{ $inputClass }}" aria-label="Result">
                            @foreach(\App\Enums\QaTestStatus::manual() as $s)
                                <option value="{{ $s->value }}">{{ $s->label() }}</option>
                            @endforeach
                        </select>
                        <select wire:model="resultForm.environment" class="{{ $inputClass }}" aria-label="Environment">
                            @foreach(\App\Enums\QaEnvironment::cases() as $e)
                                <option value="{{ $e->value }}">{{ $e->label() }}</option>
                            @endforeach
                        </select>
                        <input type="text" wire:model="resultForm.commit" placeholder="Commit (optional)" class="{{ $inputClass }} font-mono" aria-label="Commit">
                    </div>
                    <textarea wire:model="resultForm.actual_result" rows="2" placeholder="Actual result" class="{{ $inputClass }} h-auto py-2"></textarea>
                    <textarea wire:model="resultForm.note" rows="2" placeholder="Note (required for FAIL and BLOCKED)" class="{{ $inputClass }} h-auto py-2"></textarea>
                    @foreach(['resultForm.status', 'resultForm.note', 'resultForm.commit'] as $field)
                        @error($field)<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    @endforeach
                    <p class="text-[11px] text-zinc-500">Production is read-only: record observations there, never run destructive checks.</p>
                    <flux:button type="submit" size="sm" variant="primary" icon="check">Save result</flux:button>
                </form>
            @endif

            <section class="space-y-2" data-results>
                <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Result history</h4>
                @forelse($viewCase->results as $result)
                    <div class="flex gap-3 rounded-lg border border-zinc-100 p-3 text-xs dark:border-white/10">
                        <flux:badge size="sm" :color="$result->status->color()">{{ $result->status->label() }}</flux:badge>
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <p class="text-zinc-500">{{ $result->tested_at->toImmutable()->userDateTime() }} · {{ $result->tested_by_name ?? '—' }} · {{ \App\Enums\QaEnvironment::tryFrom($result->environment)?->label() ?? $result->environment }}
                                @if($result->commit) · <span class="font-mono">{{ substr($result->commit, 0, 7) }}</span>@endif
                                @if($result->is_retest) · <span class="font-semibold text-sky-600">retest</span>@endif
                            </p>
                            @if($result->actual_result)<p class="break-words text-zinc-700 dark:text-zinc-200">{{ $result->actual_result }}</p>@endif
                            @if($result->note)<p class="break-words italic text-zinc-500">{{ $result->note }}</p>@endif
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-zinc-500">Not tested yet.</p>
                @endforelse
            </section>

            @if($viewCase->findings->isNotEmpty())
                <section class="space-y-2">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Findings</h4>
                    @foreach($viewCase->findings as $f)
                        <button type="button" wire:click="openFinding({{ $f->id }})" class="flex w-full items-center gap-2 rounded-lg border border-zinc-100 p-2.5 text-left text-xs hover:bg-zinc-50 dark:border-white/10 dark:hover:bg-white/5">
                            <span class="font-mono font-semibold">{{ $f->code }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ $f->title }}</span>
                            <flux:badge size="sm" :color="$f->status->color()">{{ $f->status->label() }}</flux:badge>
                        </button>
                    @endforeach
                </section>
            @endif

            @include('livewire.qa.partials.evidence', ['evidenceOf' => $viewCase])
            @include('livewire.qa.partials.history')
        </div>
    @endif
</flux:modal>
