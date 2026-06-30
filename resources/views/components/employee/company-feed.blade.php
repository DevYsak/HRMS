@props(['holidays' => null, 'notifications' => null])

@php
    $items = collect();

    foreach (($notifications ?? collect()) as $n) {
        $items->push([
            'icon'  => 'bell-alert',
            'tone'  => 'text-amber-600 bg-amber-50 dark:bg-amber-900/20',
            'title' => $n->data['title'] ?? 'Notification',
            'sub'   => $n->data['body'] ?? '',
            'time'  => $n->created_at?->diffForHumans(),
        ]);
    }

    foreach (($holidays ?? collect()) as $h) {
        $items->push([
            'icon'  => 'sparkles',
            'tone'  => 'text-orange-600 bg-orange-50 dark:bg-orange-900/20',
            'title' => $h->name,
            'sub'   => 'Holiday',
            'time'  => \Illuminate\Support\Carbon::parse($h->date)->format('d M Y'),
        ]);
    }

    $items = $items->take(6);
@endphp

@if($items->isEmpty())
    <div class="flex flex-col items-center py-8 text-zinc-400">
        <flux:icon.megaphone class="mb-2 size-8 opacity-30" />
        <p class="text-xs">No announcements right now.</p>
    </div>
@else
    <div class="space-y-2">
        @foreach($items as $it)
            <div class="flex items-start gap-3 rounded-xl p-2.5 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg {{ $it['tone'] }}">
                    <flux:icon :name="$it['icon']" class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $it['title'] }}</p>
                    @if($it['sub'])<p class="truncate text-xs text-zinc-400">{{ $it['sub'] }}</p>@endif
                </div>
                <span class="shrink-0 text-[10px] text-zinc-400">{{ $it['time'] }}</span>
            </div>
        @endforeach
    </div>
@endif
