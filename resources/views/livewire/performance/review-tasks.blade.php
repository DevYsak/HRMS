<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Review Tasks</h1>
            <p class="pulse-page-subtitle">Performance reviews waiting for your input as a reviewer</p>
        </div>
    </div>

    {{-- Pending --}}
    <div>
        <div class="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-zinc-400">
            <flux:icon.clock class="size-3.5" /> Waiting on you ({{ $pending->count() }})
        </div>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @forelse($pending as $task)
                <div class="rounded-2xl border border-amber-200/70 dark:border-amber-500/20 bg-white dark:bg-zinc-900 p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-black text-zinc-900 dark:text-white">{{ $task->review->employee?->user?->name ?? 'Employee' }}</div>
                            <div class="mt-0.5 text-[11px] text-zinc-400">{{ $task->review->performanceCycle?->name ?? 'Current cycle' }} · {{ $task->review->employee?->department?->name ?? '—' }}</div>
                        </div>
                        <span class="shrink-0 rounded-full bg-violet-50 dark:bg-violet-500/10 px-2 py-0.5 text-[10px] font-bold uppercase text-violet-600 dark:text-violet-300">{{ $task->roleLabel() }}</span>
                    </div>
                    <div class="mt-3 flex items-center justify-between border-t border-zinc-100 dark:border-zinc-800 pt-3">
                        <span class="text-xs text-zinc-400">Weight <span class="font-bold text-zinc-600 dark:text-zinc-300">{{ rtrim(rtrim(number_format($task->weight_percent, 2), '0'), '.') }}%</span></span>
                        <flux:button wire:click="openTask({{ $task->id }})" size="sm" variant="primary" icon="pencil-square">Score now</flux:button>
                    </div>
                </div>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700 py-10 text-center text-sm text-zinc-400">
                    Nothing waiting on you. 🎉
                </div>
            @endforelse
        </div>
    </div>

    {{-- Submitted --}}
    @if($submitted->isNotEmpty())
        <div>
            <div class="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-zinc-400">
                <flux:icon.check-circle class="size-3.5" /> Submitted ({{ $submitted->count() }})
            </div>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach($submitted as $task)
                    <div class="rounded-2xl border border-zinc-200/70 dark:border-zinc-800 bg-white/60 dark:bg-zinc-900/60 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-bold text-zinc-700 dark:text-zinc-200">{{ $task->review->employee?->user?->name ?? 'Employee' }}</div>
                                <div class="mt-0.5 text-[11px] text-zinc-400">{{ $task->review->performanceCycle?->name ?? 'Cycle' }} · {{ $task->roleLabel() }}</div>
                            </div>
                            <span class="shrink-0 rounded-full bg-emerald-50 dark:bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-600 dark:text-emerald-300">Done</span>
                        </div>
                        <div class="mt-2 text-[11px] text-zinc-400">Submitted {{ $task->submitted_at?->format('d M Y, H:i') }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Score entry --}}
    <flux:modal wire:model.self="showForm" class="w-full max-w-xl">
        @if($active)
            <form wire:submit="submit" class="space-y-5">
                <div>
                    <flux:heading size="lg">Score: {{ $active->review->employee?->user?->name }}</flux:heading>
                    <flux:subheading>{{ $active->review->performanceCycle?->name }} — your input counts {{ rtrim(rtrim(number_format($active->weight_percent, 2), '0'), '.') }}% of the composite as {{ $active->roleLabel() }}.</flux:subheading>
                </div>

                <div class="max-h-[55vh] space-y-4 overflow-y-auto pr-1">
                    @foreach($activeComponents as $kpiComponent)
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 p-3">
                            <div class="mb-2 flex items-center justify-between">
                                <div class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ $kpiComponent->name }}</div>
                                <span class="text-[10px] font-bold uppercase text-zinc-400">max {{ rtrim(rtrim(number_format($kpiComponent->max_score, 2), '0'), '.') }}</span>
                            </div>
                            @if($kpiComponent->description)
                                <p class="mb-2 text-xs text-zinc-400">{{ $kpiComponent->description }}</p>
                            @endif
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <flux:input wire:model="entries.{{ $kpiComponent->id }}.score" type="number" step="0.5" min="0" max="{{ $kpiComponent->max_score }}" label="Score" required />
                                <div class="sm:col-span-2">
                                    <flux:input wire:model="entries.{{ $kpiComponent->id }}.comment" label="Comment (optional)" placeholder="What stood out?" />
                                </div>
                            </div>
                            @error("entries.{$kpiComponent->id}.score")<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-3">
                    <flux:button type="button" wire:click="$set('showForm', false)" variant="ghost">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check">Submit scores</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</flux:main>
