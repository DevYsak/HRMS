@if(isset($grouped[$manager->user_id]) && $grouped[$manager->user_id]->count() > 0)
    @php
        $reports = $grouped[$manager->user_id];
        $hasMultiple = $reports->count() > 1;
    @endphp
    <div class="flex flex-col items-center">
        {{-- Vertical line down from manager --}}
        <div class="w-px h-8 bg-zinc-300 dark:bg-zinc-700"></div>
        
        {{-- Horizontal line connecting children if multiple --}}
        @if($hasMultiple)
            <div class="w-full relative flex justify-center">
                <div class="h-px bg-zinc-300 dark:bg-zinc-700 w-[calc(100%-16rem)] min-w-[2rem]"></div>
            </div>
        @endif

        <div class="flex gap-8 pt-4 justify-center">
            @foreach($reports as $report)
                <div class="flex flex-col items-center relative shrink-0">
                    {{-- Vertical line up from child if multiple (horizontal handles the rest) --}}
                    @if($hasMultiple)
                        <div class="w-px h-4 bg-zinc-300 absolute -top-4 dark:bg-zinc-700"></div>
                    @endif

                    <div class="pulse-card shrink-0 relative z-10 w-56 p-4 flex flex-col items-center text-center shadow hover:shadow-md transition-shadow">
                        @if($report->user->avatar)
                            <img src="{{ $report->user->avatarUrl() }}" class="size-12 rounded-full mb-2" />
                        @else
                            <div class="flex size-12 items-center justify-center rounded-full bg-zinc-100 text-sm font-bold text-zinc-600 mb-2 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ strtoupper(substr($report->user->name, 0, 1)) }}
                            </div>
                        @endif
                        <h3 class="font-semibold text-sm text-zinc-900 dark:text-white">{{ $report->user->name }}</h3>
                        <p class="text-xs text-zinc-500 mt-0.5 dark:text-zinc-400">{{ $report->jobTitle?->name ?? 'Employee' }}</p>
                    </div>

                    {{-- Next level --}}
                    @include('livewire.employees.partials.org-node', ['manager' => $report, 'grouped' => $grouped])
                </div>
            @endforeach
        </div>
    </div>
@endif
