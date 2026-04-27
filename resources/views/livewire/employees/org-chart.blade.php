<flux:main class="bg-zinc-50 p-0 space-y-6 dark:bg-zinc-950">
    <div class="px-6 pt-6">
        <div class="pulse-page-header">
            <div>
                <h1 class="pulse-page-title">Organizational Chart</h1>
                <p class="pulse-page-subtitle">View reporting lines and team structures across the organization.</p>
            </div>
        </div>
    </div>

    <div class="w-full overflow-x-auto overflow-y-hidden pb-20 pt-8">
        <div class="min-w-max flex flex-col items-center px-12">
            @if($topLevel->isEmpty())
                <div class="flex flex-col items-center justify-center p-12 bg-white rounded-xl shadow-sm border border-zinc-200 dark:bg-zinc-900 dark:border-zinc-800">
                    <flux:icon.users class="size-12 text-zinc-300 mb-4" />
                    <h3 class="text-lg font-medium text-zinc-900 dark:text-white">No hierarchy found</h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">We couldn't find any reporting lines for your account.</p>
                </div>
            @else
                <div class="flex gap-16 justify-center">
                    @foreach($topLevel as $manager)
                        <div class="flex flex-col items-center">
                            {{-- Root Node --}}
                            <div class="pulse-card relative z-20 w-64 p-5 flex flex-col items-center text-center shadow-lg border-t-4 border-brand-500 bg-white dark:bg-zinc-900">
                                <div class="relative mb-4">
                                    <img src="{{ $manager->user->avatarUrl() }}" class="size-20 rounded-full border-4 border-white shadow-sm dark:border-zinc-800" />
                                    <div class="absolute -bottom-1 -right-1 size-5 rounded-full bg-green-500 border-2 border-white dark:border-zinc-900"></div>
                                </div>
                                
                                <h3 class="font-bold text-zinc-900 dark:text-white">{{ $manager->user->name }}</h3>
                                <p class="text-xs font-semibold text-brand-600 uppercase tracking-wider mt-1 dark:text-brand-400">{{ $manager->jobTitle?->name ?? 'Leader' }}</p>
                                <p class="text-[10px] text-zinc-500 mt-0.5 dark:text-zinc-500">{{ $manager->department?->name }}</p>
                            </div>

                            {{-- Render children recursively --}}
                            <x-org-node :node="$manager" :grouped="$groupedByManager" :visited="[$manager->user_id]" />
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</flux:main>
