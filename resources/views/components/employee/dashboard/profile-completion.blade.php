@props(['completion'])

{{-- EmployeeProfileCompletion — the percentage, what is still missing, and a
     link straight to the tab to fill it in. Hidden by the dashboard once the
     profile is complete. Figures from ProfileCompletionService (HR decides
     what is required in Settings → Profile Fields). --}}
<x-employee.dashboard.card title="Profile completion" icon="user-circle" :href="route('profile.me', ['tab' => 'personal'])" cta="Complete profile" {{ $attributes }}>
    <div class="flex items-center gap-3">
        <div class="relative size-12 shrink-0" role="img" aria-label="{{ $completion['percent'] }}% complete">
            <svg viewBox="0 0 36 36" class="size-12 -rotate-90">
                <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" class="stroke-zinc-100 dark:stroke-white/10" />
                <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" stroke-linecap="round" class="stroke-orange-500"
                        stroke-dasharray="{{ round(97.4 * $completion['percent'] / 100, 1) }} 97.4" />
            </svg>
            <span class="absolute inset-0 flex items-center justify-center text-[11px] font-bold tabular-nums text-zinc-800 dark:text-zinc-100">{{ $completion['percent'] }}%</span>
        </div>
        <div class="min-w-0">
            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">
                {{ count($completion['missing']) }} required {{ \Illuminate\Support\Str::plural('item', count($completion['missing'])) }} missing
            </p>
            @if(! empty($completion['submitted']))
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ count($completion['submitted']) }} waiting for HR approval</p>
            @endif
        </div>
    </div>
    @if($completion['missing'])
        <ul class="mt-3 flex flex-wrap gap-1.5">
            @foreach(array_slice($completion['missing'], 0, 6) as $gap)
                <li class="rounded-md bg-zinc-100 px-2 py-0.5 text-[11px] font-medium text-zinc-600 dark:bg-white/5 dark:text-zinc-300">{{ $gap['label'] }}</li>
            @endforeach
        </ul>
    @endif
</x-employee.dashboard.card>
