@props(['actions'])

{{-- QuickActions — shortcuts the account may open. The service drops any
     whose menu entry HR has hidden, and payslips without view_payslips. --}}
<x-employee.dashboard.card title="Quick Actions" icon="bolt" {{ $attributes }}>
    @if(empty($actions))
        <x-employee.dashboard.empty-state icon="bolt" title="No shortcuts available" />
    @else
        <div class="grid grid-cols-2 gap-2">
            @foreach($actions as $action)
                <a href="{{ $action['href'] }}" wire:navigate
                   class="group flex min-w-0 items-center gap-2.5 rounded-lg border border-zinc-200/70 p-2 text-sm text-zinc-700 transition hover:border-orange-200 hover:bg-orange-50/40 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 dark:border-white/[0.06] dark:text-zinc-200 dark:hover:border-orange-500/30 dark:hover:bg-orange-500/5 dark:hover:text-white">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-500 transition group-hover:bg-orange-100 group-hover:text-orange-600 dark:bg-white/5 dark:text-zinc-400 dark:group-hover:bg-orange-500/15 dark:group-hover:text-orange-400">
                        <flux:icon :name="$action['icon']" class="size-4" />
                    </span>
                    <span class="min-w-0 font-medium leading-tight">{{ $action['label'] }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-employee.dashboard.card>
