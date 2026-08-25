<flux:main class="min-h-screen space-y-6 bg-[#FBF7F1] p-4 dark:bg-[#0B1220] md:p-6">

    @php
        $percent = $total > 0 ? (int) round($completed / $total * 100) : 0;
    @endphp

    {{-- Header + progress --}}
    <div class="rounded-3xl border border-orange-100/70 bg-gradient-to-br from-[#FFF3E9] via-white to-[#FFF8F1] p-5 shadow-sm dark:border-white/5 dark:from-zinc-900 dark:via-zinc-900 dark:to-zinc-950 md:p-7">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">My Onboarding</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Everything to get you set up. Tick off yours as you go — the rest are handled for you.
                </p>
            </div>
            <div class="shrink-0 text-right">
                <div class="text-3xl font-black tabular-nums text-zinc-900 dark:text-white">{{ $percent }}%</div>
                <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">{{ $completed }} of {{ $total }} done</div>
            </div>
        </div>

        <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-orange-100 dark:bg-zinc-800">
            <div class="h-full rounded-full bg-orange-500 transition-all" style="width: {{ $percent }}%"></div>
        </div>
    </div>

    @if($total === 0)
        <div class="flex flex-col items-center justify-center rounded-3xl border border-zinc-100 bg-white p-16 text-zinc-400 dark:border-white/5 dark:bg-zinc-900">
            <flux:icon.clipboard-document-check class="mb-3 size-12 opacity-30" />
            <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-300">No onboarding tasks assigned.</p>
            <p class="mt-1 text-xs">If you think this is wrong, contact HR.</p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">

            {{-- Your tasks --}}
            <div class="rounded-3xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-white/5 dark:bg-zinc-900 lg:col-span-2">
                <h2 class="flex items-center gap-2 text-sm font-black uppercase tracking-widest text-zinc-400">
                    <flux:icon.check-circle class="size-4" /> Your tasks
                </h2>

                @if($mine->isEmpty())
                    <p class="mt-6 text-center text-xs text-zinc-400">Nothing needs doing by you right now.</p>
                @else
                    <div class="mt-4 space-y-2">
                        @foreach($mine as $task)
                            @php
                                $overdue = ! $task->is_completed && $task->due_date && \Illuminate\Support\Carbon::parse($task->due_date)->isPast();
                            @endphp
                            <div @class([
                                'flex items-start gap-3 rounded-2xl border p-3 transition',
                                'border-emerald-100 bg-emerald-50/50 dark:border-emerald-900/30 dark:bg-emerald-900/10' => $task->is_completed,
                                'border-rose-100 bg-rose-50/40 dark:border-rose-900/30 dark:bg-rose-900/10' => $overdue,
                                'border-zinc-100 dark:border-white/5' => ! $task->is_completed && ! $overdue,
                            ])>
                                <button type="button" wire:click="toggleComplete({{ $task->id }})"
                                        wire:loading.attr="disabled"
                                        class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md border-2 transition {{ $task->is_completed ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-zinc-300 hover:border-orange-400 dark:border-zinc-600' }}">
                                    @if($task->is_completed)
                                        <flux:icon.check class="size-3" />
                                    @endif
                                </button>

                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-bold {{ $task->is_completed ? 'text-zinc-400 line-through dark:text-zinc-500' : 'text-zinc-900 dark:text-white' }}">
                                        {{ $task->title }}
                                    </div>
                                    @if($task->description)
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $task->description }}</p>
                                    @endif
                                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] font-semibold uppercase tracking-wide">
                                        @if($task->due_date)
                                            <span class="{{ $overdue ? 'text-rose-600' : 'text-zinc-400' }}">
                                                Due {{ \Illuminate\Support\Carbon::parse($task->due_date)->format('d M Y') }}
                                                @if($overdue) · Overdue @endif
                                            </span>
                                        @endif
                                        @if($task->category)
                                            <span class="text-zinc-400">{{ $task->category }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Handled by others --}}
            <div class="rounded-3xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-white/5 dark:bg-zinc-900">
                <h2 class="flex items-center gap-2 text-sm font-black uppercase tracking-widest text-zinc-400">
                    <flux:icon.users class="size-4" /> Handled for you
                </h2>
                <p class="mt-1 text-xs text-zinc-400">Shown so you know what is in progress. Nothing to do here.</p>

                @if($others->isEmpty())
                    <p class="mt-6 text-center text-xs text-zinc-400">Nothing outstanding.</p>
                @else
                    <div class="mt-4 space-y-2">
                        @foreach($others as $task)
                            <div class="flex items-start gap-3 rounded-xl bg-zinc-50 p-2.5 dark:bg-zinc-800/50">
                                <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md {{ $task->is_completed ? 'bg-emerald-500 text-white' : 'bg-zinc-200 dark:bg-zinc-700' }}">
                                    @if($task->is_completed)<flux:icon.check class="size-3" />@endif
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-semibold {{ $task->is_completed ? 'text-zinc-400 line-through' : 'text-zinc-700 dark:text-zinc-200' }}">
                                        {{ $task->title }}
                                    </div>
                                    <div class="text-[10px] uppercase tracking-wide text-zinc-400">{{ $task->owner_role }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

</flux:main>
