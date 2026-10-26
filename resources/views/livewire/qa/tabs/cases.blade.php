<div class="ui-surface overflow-hidden" data-tab-panel="cases">
    @if($cases->isEmpty())
        <div class="p-5"><x-pulse.empty-state icon="clipboard-document-list" title="No test cases match" text="Change the filters or add a test case." /></div>
    @else
        {{-- Desktop table --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-3">Test ID</th>
                        <th class="px-4 py-3">Title</th>
                        <th class="px-4 py-3">Module</th>
                        <th class="px-4 py-3">Role · Scope</th>
                        <th class="px-4 py-3">Severity</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Last tested</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach($cases as $case)
                        <tr wire:key="case-{{ $case->id }}" wire:click="openCase({{ $case->id }})" class="cursor-pointer hover:bg-orange-50/40 dark:hover:bg-white/5" data-case-row="{{ $case->code }}">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ $case->code }}</td>
                            <td class="max-w-md px-4 py-3">
                                <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ $case->title }}</p>
                                @if($case->feature)<p class="text-xs text-zinc-500">{{ $case->feature }}</p>@endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $case->module }}</td>
                            <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">{{ $case->role ?? '—' }}<br><span class="text-zinc-400">{{ $case->scope }}</span></td>
                            <td class="px-4 py-3"><flux:badge size="sm" :color="$case->severity->color()">{{ $case->severity->label() }}</flux:badge></td>
                            <td class="whitespace-nowrap px-4 py-3"><flux:badge size="sm" :color="$case->status->color()">{{ $case->status->label() }}</flux:badge></td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-zinc-500">{{ $case->last_tested_at?->toImmutable()->userDateTime() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile cards --}}
        <div class="divide-y divide-zinc-100 md:hidden dark:divide-white/5">
            @foreach($cases as $case)
                <button type="button" wire:key="case-m-{{ $case->id }}" wire:click="openCase({{ $case->id }})" class="block w-full space-y-1.5 px-4 py-3 text-left">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-mono text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ $case->code }}</span>
                        <flux:badge size="sm" :color="$case->status->color()">{{ $case->status->label() }}</flux:badge>
                    </div>
                    <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $case->title }}</p>
                    <p class="text-xs text-zinc-500">{{ $case->module }} · {{ $case->role ?? '—' }} · {{ $case->severity->label() }}</p>
                </button>
            @endforeach
        </div>
        <div class="border-t border-zinc-100 px-4 py-3 dark:border-white/5">{{ $cases->links() }}</div>
    @endif
</div>
