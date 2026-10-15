<div class="space-y-3" data-tab-panel="fixes">
    @forelse($fixes as $fix)
        <div wire:key="fix-{{ $fix->id }}" class="ui-surface flex flex-col gap-3 p-4 sm:flex-row sm:items-start" data-fix="{{ $fix->shortHash() }}">
            <span class="inline-flex w-fit shrink-0 rounded-md bg-zinc-900 px-2 py-1 font-mono text-xs font-semibold text-white dark:bg-white dark:text-zinc-900" title="{{ $fix->commit_hash }}">{{ $fix->shortHash() }}</span>
            <div class="min-w-0 flex-1 space-y-1.5">
                <p class="break-words text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $fix->commit_message ?? 'Commit '.$fix->shortHash() }}</p>
                @if($fix->finding)
                    <button type="button" wire:click="openFinding({{ $fix->finding->id }})" class="flex flex-wrap items-center gap-1.5 text-xs text-zinc-500 hover:text-orange-600">
                        <span class="font-mono font-semibold">{{ $fix->finding->code }}</span> · {{ $fix->finding->title }}
                        <flux:badge size="sm" :color="$fix->finding->status->color()">{{ $fix->finding->status->label() }}</flux:badge>
                    </button>
                @endif
                @if($fix->test_case_codes)
                    <p class="flex flex-wrap gap-1 text-xs">
                        @foreach($fix->test_case_codes as $code)
                            <span class="rounded bg-sky-50 px-1.5 py-0.5 font-mono text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">{{ $code }}</span>
                        @endforeach
                    </p>
                @endif
                @if($fix->files_changed)
                    <details class="text-xs text-zinc-500">
                        <summary class="cursor-pointer select-none">{{ count($fix->files_changed) }} file(s) changed</summary>
                        <ul class="mt-1 space-y-0.5 font-mono">
                            @foreach($fix->files_changed as $file)<li class="break-all">{{ $file }}</li>@endforeach
                        </ul>
                    </details>
                @endif
                @if($fix->developer_note)<p class="text-xs text-zinc-600 dark:text-zinc-300">{{ $fix->developer_note }}</p>@endif
            </div>
            <div class="shrink-0 text-xs text-zinc-500 sm:text-right">
                <p>{{ $fix->fixed_at?->format('d M Y H:i') }}</p>
                <p>{{ \App\Enums\QaEnvironment::tryFrom((string) $fix->environment)?->label() }}</p>
            </div>
        </div>
    @empty
        <div class="ui-surface p-5"><x-pulse.empty-state icon="wrench-screwdriver" title="No fixes match" /></div>
    @endforelse
    <div>{{ $fixes->links() }}</div>
</div>
