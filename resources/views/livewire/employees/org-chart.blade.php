<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Organizational Chart</h1>
            <p class="pulse-page-subtitle">View reporting lines and team structures.</p>
        </div>
    </div>

    <div class="overflow-x-auto pb-12 w-full">
        <div class="min-w-max flex flex-col items-center px-4">
            @foreach($topLevel as $ceo)
                <div class="flex flex-col items-center">
                    {{-- Node --}}
                    <div class="pulse-card shrink-0 relative z-10 w-64 p-4 flex flex-col items-center text-center shadow-md border-t-4 border-brand-500">
                        @if($ceo->user->avatar)
                            <img src="{{ $ceo->user->avatarUrl() }}" class="size-16 rounded-full mb-3" />
                        @else
                            <div class="flex size-16 items-center justify-center rounded-full bg-brand-100 text-xl font-bold text-brand-600 mb-3 dark:bg-brand-900/40 dark:text-brand-400">
                                {{ strtoupper(substr($ceo->user->name, 0, 1)) }}
                            </div>
                        @endif
                        <h3 class="font-bold text-zinc-900 dark:text-white">{{ $ceo->user->name }}</h3>
                        <p class="text-xs text-brand-600 font-medium mt-1 dark:text-brand-400">{{ $ceo->jobTitle?->name ?? 'CEO' }}</p>
                    </div>

                    {{-- Render children --}}
                    @include('livewire.employees.partials.org-node', ['manager' => $ceo, 'grouped' => $groupedByManager])
                </div>
            @endforeach
        </div>
    </div>
</flux:main>
