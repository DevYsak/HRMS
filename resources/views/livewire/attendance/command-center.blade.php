<flux:main class="min-h-screen bg-[#FFF8F3] p-4 md:p-6">

{{-- ═══════════════ HEADER ═══════════════ --}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-black tracking-tight text-zinc-900">Attendance Command Center</h1>
        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-400">
            <span>Dashboard</span><flux:icon.chevron-right class="size-3" /><span>Attendance</span><flux:icon.chevron-right class="size-3" /><span class="font-semibold text-orange-500">Command Center</span>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <div class="relative">
            <flux:icon.magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-zinc-400" />
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search employee…"
                class="w-52 rounded-xl border border-orange-100 bg-white py-2 pl-8 pr-2.5 text-xs font-semibold text-zinc-600 shadow-sm placeholder:text-zinc-400 focus:border-orange-400 focus:ring-0">
        </div>
        <button wire:click="exportPending" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white px-3 py-2 text-xs font-bold text-zinc-600 shadow-sm transition hover:bg-orange-50"><flux:icon.arrow-down-tray class="size-4 text-orange-500" /> Export</button>
        <a href="{{ route('attendance.employees') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white px-3 py-2 text-xs font-bold text-zinc-600 shadow-sm transition hover:bg-orange-50"><flux:icon.users class="size-4 text-orange-500" /> All Attendance</a>
    </div>
</div>

{{-- ═══════════════ COUNTS (tabs) ═══════════════ --}}
@php
    $tabs = [
        'regularisation' => ['Pending Regularization', 'pencil-square', '#F97316'],
        'leave' => ['Pending Leave', 'calendar-days', '#8b5cf6'],
        'wfh' => ['Pending WFH', 'home', '#3b82f6'],
        'overtime' => ['Pending Overtime', 'bolt', '#f59e0b'],
        'holiday' => ['Holiday Work', 'briefcase', '#14b8a6'],
    ];
@endphp
<div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-7">
    @foreach($tabs as $key => [$label, $icon, $color])
        <button wire:click="$set('tab', '{{ $key }}')"
            class="flex items-center gap-3 rounded-[18px] border p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md {{ $tab === $key ? 'border-orange-400 bg-white ring-1 ring-orange-200' : 'border-orange-100/70 bg-white' }}">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl" style="background: {{ $color }}1a; color: {{ $color }};"><flux:icon :icon="$icon" class="size-5" /></span>
            <div>
                <div class="text-xl font-black tabular-nums text-zinc-900">{{ $counts[$key] }}</div>
                <div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">{{ $label }}</div>
            </div>
        </button>
    @endforeach
    <div class="flex items-center gap-3 rounded-[18px] border border-orange-100/70 bg-white p-4 shadow-sm">
        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"><flux:icon.check-circle class="size-5" /></span>
        <div><div class="text-xl font-black tabular-nums text-zinc-900">{{ $decided['approved'] }}</div><div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">Approved Requests</div></div>
    </div>
    <div class="flex items-center gap-3 rounded-[18px] border border-orange-100/70 bg-white p-4 shadow-sm">
        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-500"><flux:icon.x-circle class="size-5" /></span>
        <div><div class="text-xl font-black tabular-nums text-zinc-900">{{ $decided['rejected'] }}</div><div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">Rejected Requests</div></div>
    </div>
</div>

<div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-12">

    {{-- ═══════════════ PENDING LIST ═══════════════ --}}
    <div class="overflow-hidden rounded-[18px] border border-orange-100/70 bg-white shadow-sm lg:col-span-8">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-orange-100/70 px-5 py-3">
            <h3 class="flex items-center gap-2 text-sm font-black text-zinc-900">
                <flux:icon :icon="$tabs[$tab][1]" class="size-4 text-orange-500" /> {{ $tabs[$tab][0] }}
                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">· {{ count($items) }} shown</span>
            </h3>
            <div class="flex items-center gap-2 text-xs">
                <button wire:click="selectAll" class="font-bold text-orange-500 hover:underline">Select all</button>
                @if(count($selected))<button wire:click="clearSelection" class="font-bold text-zinc-400 hover:underline">Clear ({{ count($selected) }})</button>@endif
            </div>
        </div>

        {{-- Bulk action bar --}}
        @if(count($selected))
            <div class="flex flex-wrap items-center gap-2 border-b border-orange-100/70 bg-orange-50/60 px-5 py-2.5">
                <span class="text-xs font-black text-zinc-800">{{ count($selected) }} selected</span>
                <button wire:click="bulkApprove" wire:loading.attr="disabled" class="inline-flex items-center gap-1 rounded-lg bg-emerald-500 px-3 py-1.5 text-[11px] font-bold text-white transition hover:bg-emerald-600 disabled:opacity-50"><flux:icon.check class="size-3.5" /> Bulk Approve</button>
                <input type="text" wire:model="rejectComment" placeholder="Rejection comment (required for reject)…"
                    class="min-w-0 flex-1 rounded-lg border border-orange-200 bg-white px-2.5 py-1.5 text-xs focus:border-orange-400 focus:ring-0">
                <button wire:click="bulkReject" wire:loading.attr="disabled" class="inline-flex items-center gap-1 rounded-lg bg-rose-500 px-3 py-1.5 text-[11px] font-bold text-white transition hover:bg-rose-600 disabled:opacity-50"><flux:icon.x-mark class="size-3.5" /> Bulk Reject</button>
            </div>
        @endif

        @if(count($items) > 0)
            <div class="divide-y divide-orange-50" wire:loading.class="opacity-50" wire:target="tab,search,bulkApprove,bulkReject">
                @foreach($items as $item)
                    <div class="flex flex-wrap items-center gap-3 px-5 py-3 transition hover:bg-orange-50/40">
                        <input type="checkbox" wire:model.live="selected" value="{{ $item['id'] }}"
                            class="size-4 rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                <span class="text-xs font-black text-zinc-900">{{ $item['employee'] }}</span>
                                <span class="text-[11px] text-zinc-500">{{ $item['when'] }}</span>
                                <span class="rounded-full bg-orange-50 px-2 py-0.5 text-[9px] font-bold text-orange-600">{{ $item['detail'] }}</span>
                            </div>
                            @if($item['reason'])<p class="mt-0.5 truncate text-[10px] italic text-zinc-400">“{{ $item['reason'] }}”</p>@endif
                        </div>
                        <div class="flex shrink-0 gap-1.5">
                            <button wire:click="approveOne('{{ $tab }}', {{ $item['id'] }})" class="inline-flex items-center gap-1 rounded-lg bg-emerald-500 px-2.5 py-1 text-[10px] font-bold text-white transition hover:bg-emerald-600"><flux:icon.check class="size-3" /> Approve</button>
                            <button wire:click="rejectOne('{{ $tab }}', {{ $item['id'] }})" class="inline-flex items-center gap-1 rounded-lg bg-rose-500 px-2.5 py-1 text-[10px] font-bold text-white transition hover:bg-rose-600"><flux:icon.x-mark class="size-3" /> Reject</button>
                        </div>
                    </div>
                @endforeach
            </div>
            @if(! count($selected))
                <div class="border-t border-orange-100/70 bg-orange-50/30 px-5 py-2 text-[10px] text-zinc-400">Tip: rejections require a comment — tick items to open the bulk bar, or type a comment there first for single rejects.</div>
            @endif
        @else
            <div class="py-14 text-center text-sm text-zinc-400"><flux:icon.check-badge class="mx-auto mb-2 size-9 text-emerald-300" /> Nothing pending — all caught up.</div>
        @endif
    </div>

    {{-- ═══════════════ ACTIVITY FEED ═══════════════ --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white p-5 shadow-sm lg:col-span-4">
        <div class="mb-3 flex items-center gap-2">
            <span class="inline-flex size-8 items-center justify-center rounded-xl bg-orange-50 text-orange-500"><flux:icon.clock class="size-4" /></span>
            <div class="text-sm font-black text-zinc-900">Activity Feed</div>
        </div>
        @if(count($feed) > 0)
            <div class="relative space-y-3">
                @foreach($feed as $f)
                    @php $ok = $f['status'] === 'approved'; @endphp
                    <div class="relative flex items-start gap-3">
                        @unless($loop->last)<span class="absolute left-[9px] top-6 h-full w-px bg-orange-100"></span>@endunless
                        <span class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full {{ $ok ? 'bg-emerald-500' : 'bg-rose-500' }} text-white"><flux:icon :icon="$ok ? 'check' : 'x-mark'" class="size-3" /></span>
                        <div class="min-w-0 flex-1 text-xs">
                            <span class="font-black text-zinc-900">{{ $f['employee'] }}</span>
                            <span class="text-zinc-500">— {{ $f['type'] }} {{ $f['status'] }}</span>
                            <div class="text-[10px] text-zinc-400">
                                @if($f['reviewer'])by {{ $f['reviewer'] }} · @endif{{ $f['at'] ? \Carbon\Carbon::parse($f['at'])->diffForHumans() : '' }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="py-6 text-center text-xs text-zinc-400">No decisions yet.</p>
        @endif
    </div>
</div>

</flux:main>
