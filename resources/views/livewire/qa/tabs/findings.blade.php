@php $uiMode = $tab === 'ui'; @endphp
<div class="ui-surface overflow-hidden" data-tab-panel="{{ $tab }}">
    @if($findings->isEmpty())
        <div class="p-5"><x-pulse.empty-state icon="bug-ant" :title="$uiMode ? 'No UI / UX findings match' : 'No findings match'" /></div>
    @else
        <div class="hidden overflow-x-auto md:block">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-3">Finding</th>
                        <th class="px-4 py-3">Title</th>
                        <th class="px-4 py-3">Severity</th>
                        @if($uiMode)
                            <th class="px-4 py-3">Page</th>
                            <th class="px-4 py-3">Viewport · Theme</th>
                        @else
                            <th class="px-4 py-3">Module · Kind</th>
                            <th class="px-4 py-3">Test</th>
                        @endif
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Fix</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach($findings as $f)
                        <tr wire:key="finding-{{ $f->id }}" wire:click="openFinding({{ $f->id }})" class="cursor-pointer hover:bg-orange-50/40 dark:hover:bg-white/5" data-finding-row="{{ $f->code }}">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ $f->code }}</td>
                            <td class="max-w-md px-4 py-3 font-medium text-zinc-800 dark:text-zinc-100">{{ $f->title }}</td>
                            <td class="px-4 py-3"><flux:badge size="sm" :color="$f->severity->color()">{{ $f->severity->label() }}</flux:badge></td>
                            @if($uiMode)
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $f->page ?? $f->route ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">{{ $f->viewport ?? '—' }}<br><span class="text-zinc-400">{{ $f->theme }}</span></td>
                            @else
                                <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">{{ $f->module }}<br><span class="text-zinc-400">{{ \App\Models\QaFinding::KINDS[$f->kind] ?? $f->kind }}</span></td>
                                <td class="px-4 py-3 font-mono text-xs text-zinc-500">{{ $f->testCase?->code ?? '—' }}</td>
                            @endif
                            <td class="whitespace-nowrap px-4 py-3"><flux:badge size="sm" :color="$f->status->color()">{{ $f->status->label() }}</flux:badge></td>
                            <td class="px-4 py-3 font-mono text-xs text-zinc-500">{{ $f->fixes->map->shortHash()->unique()->implode(' ') ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="divide-y divide-zinc-100 md:hidden dark:divide-white/5">
            @foreach($findings as $f)
                <button type="button" wire:key="finding-m-{{ $f->id }}" wire:click="openFinding({{ $f->id }})" class="block w-full space-y-1.5 px-4 py-3 text-left">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-mono text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ $f->code }}</span>
                        <div class="flex gap-1">
                            <flux:badge size="sm" :color="$f->severity->color()">{{ $f->severity->label() }}</flux:badge>
                            <flux:badge size="sm" :color="$f->status->color()">{{ $f->status->label() }}</flux:badge>
                        </div>
                    </div>
                    <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $f->title }}</p>
                    <p class="text-xs text-zinc-500">{{ $uiMode ? ($f->page ?? '—').' · '.($f->viewport ?? '—') : $f->module }} · {{ $f->fixes->map->shortHash()->unique()->implode(' ') ?: 'no fix yet' }}</p>
                </button>
            @endforeach
        </div>
        <div class="border-t border-zinc-100 px-4 py-3 dark:border-white/5">{{ $findings->links() }}</div>
    @endif
</div>
