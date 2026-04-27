@props(['node', 'grouped', 'visited' => []])

@php
    $visited = $visited ?? [];
    $canRender = isset($grouped[$node->user_id]) && $grouped[$node->user_id]->count() > 0 && !in_array($node->user_id, $visited);
@endphp

<div class="flex flex-col items-center">
    @if($canRender)
        @php
            $reports = $grouped[$node->user_id];
            $count = $reports->count();
            $visited[] = $node->user_id;
        @endphp

        <div class="w-px h-10 bg-zinc-300 dark:bg-zinc-700"></div>

        <div class="relative flex justify-center w-full">
            @if($count > 1)
                <div class="absolute top-0 h-px bg-zinc-300 dark:bg-zinc-700" style="width: calc(100% - (100% / {{ $count }})); left: calc(100% / {{ $count }} / 2);"></div>
            @endif

            <div class="flex gap-8 justify-center w-full">
                @foreach($reports as $report)
                    <div class="flex flex-col items-center relative group">
                        <div class="w-px h-6 bg-zinc-300 dark:bg-zinc-700"></div>

                        <div class="pulse-card relative z-10 w-60 p-4 flex flex-col items-center text-center shadow-md hover:shadow-xl transition-all duration-300 bg-white border border-zinc-200 hover:border-brand-300 dark:bg-zinc-900 dark:border-zinc-800 dark:hover:border-brand-500">
                            <div class="flex items-center gap-3 w-full text-left">
                                <img src="{{ $report->user->avatarUrl() }}" class="size-12 rounded-full border-2 border-zinc-50 dark:border-zinc-800 shadow-sm" />
                                <div class="overflow-hidden">
                                    <h4 class="text-sm font-bold text-zinc-900 truncate dark:text-white">{{ $report->user->name }}</h4>
                                    <p class="text-[10px] font-medium text-brand-600 truncate dark:text-brand-400 uppercase tracking-tight">{{ $report->jobTitle?->name ?? 'Employee' }}</p>
                                    <p class="text-[9px] text-zinc-500 truncate dark:text-zinc-500">{{ $report->department?->name }}</p>
                                </div>
                            </div>
                        </div>

                        <x-org-node :node="$report" :grouped="$grouped" :visited="$visited" />
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
