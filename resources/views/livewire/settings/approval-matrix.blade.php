<flux:main>
    <div class="mx-auto w-full max-w-6xl space-y-5">
        <div>
            <h1 class="pulse-page-title">Approval Matrix</h1>
            <p class="pulse-page-subtitle">Who approves what, from the current policy and today's role permissions. Read-only — change who decides in Roles &amp; Permissions.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach($rows as $row)
                <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" data-approval-matrix="{{ $row['type'] }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-base font-bold text-zinc-900 dark:text-white">{{ $row['label'] }}</h2>
                        <span class="text-[11px] font-semibold text-zinc-500">Decided by: {{ $row['permission'] }}</span>
                    </div>

                    <ol class="mt-4 space-y-2 border-l border-zinc-200 pl-4 dark:border-zinc-700">
                        @foreach($row['steps'] as $step)
                            <li class="relative text-sm text-zinc-700 dark:text-zinc-200">
                                <span @class(['absolute -left-[21px] top-1.5 size-2.5 rounded-full', 'bg-orange-500' => $loop->index === 1, 'bg-zinc-300 dark:bg-zinc-600' => $loop->index !== 1])></span>
                                {{ $step }}
                            </li>
                        @endforeach
                    </ol>

                    @if($row['note'])
                        <p class="mt-3 rounded-xl bg-zinc-50 px-3 py-2 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $row['note'] }}</p>
                    @endif

                    <div class="mt-4">
                        <p class="mb-1.5 text-[11px] font-bold uppercase tracking-wide text-zinc-400">Roles that can decide</p>
                        <div class="flex flex-wrap gap-1.5">
                            @forelse($row['deciders'] as $d)
                                <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-[11px] font-semibold text-orange-800 ring-1 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/30">
                                    {{ $d['role'] }} <span class="font-normal text-orange-700/70 dark:text-orange-300/70">· {{ $d['scope'] }}</span>
                                </span>
                            @empty
                                <span class="text-xs text-rose-600">No active role holds this permission.</span>
                            @endforelse
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</flux:main>
