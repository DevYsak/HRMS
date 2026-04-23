<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Employee Directory</h1>
            <p class="pulse-page-subtitle">Find colleagues and contact information.</p>
        </div>
    </div>

    <div class="flex flex-col sm:flex-row gap-4 mb-6">
        <div class="relative flex-1">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 size-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by name or email..." class="w-full h-10 rounded-xl border border-zinc-200 bg-white pl-10 pr-4 text-sm text-zinc-800 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-brand-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100 shadow-sm" />
        </div>
        <select wire:model.live="department_id" class="h-10 rounded-xl border border-zinc-200 bg-white px-4 text-sm text-zinc-600 focus:outline-none focus:ring-2 focus:ring-brand-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 shadow-sm w-full sm:w-48">
            <option value="">All Departments</option>
            @foreach($departments as $dept)
                <option value="{{ $dept->id }}">{{ $dept->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        @forelse($employees as $emp)
            <div class="pulse-card flex flex-col items-center text-center hover:shadow-md transition-shadow group">
                <div class="relative w-24 h-24 mb-4">
                    @if($emp->user->avatar)
                        <img src="{{ $emp->user->avatarUrl() }}" class="size-24 rounded-full object-cover border-4 border-white shadow-sm dark:border-zinc-800" />
                    @else
                        <div class="flex size-24 items-center justify-center rounded-full bg-brand-100/50 text-2xl font-bold text-brand-600 border-4 border-white shadow-sm dark:border-zinc-800 dark:bg-brand-900/30 dark:text-brand-400">
                            {{ strtoupper(substr($emp->user->name, 0, 1)) }}
                        </div>
                    @endif
                    <div class="absolute bottom-1 right-1 size-4 rounded-full border-2 border-white {{ $emp->status->value === 'active' ? 'bg-green-500' : 'bg-amber-500' }} dark:border-zinc-800"></div>
                </div>

                <h3 class="font-bold text-lg text-zinc-900 dark:text-white mb-1 group-hover:text-brand-600 dark:group-hover:text-brand-400 transition-colors">{{ $emp->user->name }}</h3>
                <p class="text-sm font-medium text-brand-600 dark:text-brand-400 mb-1">{{ $emp->jobTitle?->name ?? 'Employee' }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-4">{{ $emp->department?->name ?? 'No Department' }}</p>

                <div class="w-full pt-4 mt-auto border-t border-zinc-100 dark:border-zinc-800/60 flex items-center justify-center gap-4">
                    <a href="mailto:{{ $emp->user->email }}" class="p-2 rounded-full text-zinc-400 hover:text-brand-600 hover:bg-brand-50 transition-colors dark:hover:bg-brand-900/20" title="{{ $emp->user->email }}">
                        <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </a>
                </div>
            </div>
        @empty
            <div class="col-span-full py-12 flex flex-col items-center justify-center text-zinc-400">
                <svg class="size-16 mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <p class="text-lg font-medium">No colleagues found</p>
                <p class="text-sm mt-1">Try adjusting your search criteria</p>
            </div>
        @endforelse
    </div>
</flux:main>
