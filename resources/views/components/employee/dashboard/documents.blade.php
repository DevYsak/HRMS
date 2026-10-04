@props(['documents'])

{{-- EmployeeDocuments — the five newest documents the Documents page would
     show this employee (company-wide, policies and their own). Links are
     short-lived signed URLs minted on render, as on the Documents page. --}}
<x-employee.dashboard.card title="My Documents" icon="folder" :href="route('documents.index')" cta="View all" {{ $attributes }}>
    @if($documents['items']->isEmpty())
        <x-employee.dashboard.empty-state icon="folder" title="No documents yet." text="Letters, policies and files shared with you will appear here." />
    @else
        <ul class="-mx-2 space-y-0.5">
            @foreach($documents['items'] as $doc)
                <li class="group flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                        <flux:icon.document-text class="size-3.5" />
                    </span>
                    <a href="{{ $doc['view_url'] }}" target="_blank" rel="noopener" class="min-w-0 flex-1 focus-visible:outline-2 focus-visible:outline-orange-500">
                        <span class="block truncate text-sm text-zinc-800 dark:text-zinc-100">{{ $doc['title'] }}</span>
                        <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $doc['type'] }} · {{ $doc['date']?->format('j M Y') }}</span>
                    </a>
                    <flux:tooltip content="Download">
                        <a href="{{ $doc['download_url'] }}" aria-label="Download {{ $doc['title'] }}"
                           class="flex size-7 shrink-0 items-center justify-center rounded-md text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 focus-visible:outline-2 focus-visible:outline-orange-500 dark:hover:bg-white/10 dark:hover:text-white">
                            <flux:icon.arrow-down-tray class="size-3.5" />
                        </a>
                    </flux:tooltip>
                </li>
            @endforeach
        </ul>
    @endif
</x-employee.dashboard.card>
