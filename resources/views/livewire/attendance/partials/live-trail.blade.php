{{--
    One day's punches as chips, in order (MissedPunchTracker::trail()):
    counted IN (green) / OUT (grey), corrections (violet), duplicates and
    ignored taps (faded), and each missed punch where it was missed (red, or
    violet once a regularisation corrected it). Needs $trail.
--}}
@if($trail === [])
    <span class="text-xs text-zinc-300 dark:text-zinc-600">No punches</span>
@else
    <div class="flex flex-wrap items-center gap-1" data-trail>
        @foreach($trail as $n)
            @php
                $dir = str_ends_with($n['title'], 'IN') ? 'IN' : (str_ends_with($n['title'], 'OUT') ? 'OUT' : '');
                $chip = match ($n['state']) {
                    'counted' => $dir === 'IN'
                        ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30'
                        : 'bg-zinc-100 text-zinc-700 ring-1 ring-inset ring-zinc-200 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10',
                    'corrected' => 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/30',
                    'missed' => 'border border-dashed border-red-400 bg-red-50 text-red-700 dark:border-red-500/60 dark:bg-red-500/10 dark:text-red-300',
                    'missed_corrected' => 'border border-dashed border-violet-400 bg-violet-50 text-violet-700 dark:border-violet-500/60 dark:bg-violet-500/10 dark:text-violet-300',
                    'superseded' => 'text-zinc-400 line-through ring-1 ring-inset ring-zinc-200 dark:ring-white/10',
                    default => 'border border-dashed border-zinc-300 text-zinc-400 dark:border-zinc-600',
                };
            @endphp
            <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md px-1.5 py-0.5 text-[11px] font-semibold {{ $chip }}"
                  title="{{ $n['title'] }}{{ $n['note'] ? ' — '.$n['note'] : '' }}" data-trail-state="{{ $n['state'] }}">
                @if(in_array($n['state'], ['missed', 'missed_corrected'], true))
                    <flux:icon.exclamation-triangle variant="micro" class="size-3" />{{ $n['title'] }}@if($n['state'] === 'missed_corrected') · fixed @endif
                @else
                    <span class="opacity-70">{{ $dir ?: '·' }}</span>{{ $n['time'] }}
                    @if($n['state'] === 'duplicate') <span class="font-normal">dup</span>@elseif($n['state'] === 'ignored') <span class="font-normal">ignored</span>@endif
                @endif
            </span>
        @endforeach
    </div>
@endif
