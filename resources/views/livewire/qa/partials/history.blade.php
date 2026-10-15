{{-- Change history of the open record (activity log, category "qa"): who, when, old → new. Never edited. --}}
@php
    $fmt = fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $v;
@endphp
<section class="space-y-2" data-history>
    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Change history</h4>
    @forelse($history as $entry)
        <div class="rounded-lg border border-zinc-100 p-3 text-xs dark:border-white/10">
            <div class="flex flex-wrap items-center justify-between gap-1">
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ \Illuminate\Support\Str::headline(strtolower((string) ($entry->event ?? $entry->action))) }}</span>
                <span class="text-zinc-500">{{ $entry->user?->name ?? 'System' }} · {{ $entry->created_at->format('d M Y H:i') }}</span>
            </div>
            @php $keys = collect(array_keys($entry->old_values ?? []))->merge(array_keys($entry->new_values ?? []))->unique(); @endphp
            @foreach($keys as $key)
                <p class="mt-1 break-words text-zinc-600 dark:text-zinc-300">
                    <span class="font-medium">{{ $key }}:</span>
                    @if(array_key_exists($key, $entry->old_values ?? []))<span class="text-rose-600 line-through dark:text-rose-400">{{ $fmt($entry->old_values[$key]) }}</span> → @endif
                    <span class="text-emerald-700 dark:text-emerald-400">{{ $fmt($entry->new_values[$key] ?? '') }}</span>
                </p>
            @endforeach
            @if($entry->reason)<p class="mt-1 italic text-zinc-500">“{{ $entry->reason }}”</p>@endif
        </div>
    @empty
        <p class="text-xs text-zinc-500">No changes recorded since it was created.</p>
    @endforelse
</section>
