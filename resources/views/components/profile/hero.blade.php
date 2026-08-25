@props([
    'employee',
    'canEditPhoto' => false,
    'completion' => null,   // ['percent' => int, 'missing' => array]
])

@php
    use Illuminate\Support\Str;

    $user = $employee->user;
    $name = $user?->name ?? 'Unnamed employee';
    $initials = Str::of($name)->explode(' ')->map(fn ($n) => mb_substr($n, 0, 1))->take(2)->implode('');

    $status = $employee->status?->value ?? $employee->status;
    $flags = $employee->dataFlags();
@endphp

<div class="pulse-card !p-0 overflow-hidden">
    <div class="flex flex-col gap-6 p-6 sm:flex-row sm:items-start">

        {{-- Avatar --}}
        <div class="relative shrink-0 self-center sm:self-start">
            @if($employee->photo)
                <img src="{{ Storage::url($employee->photo) }}" alt="{{ $name }}"
                     class="size-24 rounded-2xl object-cover ring-1 ring-black/5 dark:ring-white/10">
            @else
                <div class="flex size-24 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-400 to-orange-600 text-2xl font-black text-white">
                    {{ $initials }}
                </div>
            @endif

            @if($canEditPhoto)
                <label class="absolute -bottom-1.5 -right-1.5 flex size-8 cursor-pointer items-center justify-center rounded-full bg-white shadow-md ring-1 ring-black/5 transition hover:scale-105 dark:bg-zinc-800 dark:ring-white/10"
                       title="Change photo">
                    <flux:icon.camera class="size-4 text-zinc-600 dark:text-zinc-300" />
                    <input type="file" wire:model="photo" accept="image/*" class="sr-only">
                </label>
            @endif
        </div>

        {{-- Identity --}}
        <div class="min-w-0 flex-1 text-center sm:text-left">
            <div class="flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                <h1 class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">{{ $name }}</h1>
                @if($status)
                    <span class="badge-{{ $status }}">{{ Str::headline($status) }}</span>
                @endif
            </div>

            <p class="mt-0.5 text-sm text-zinc-500">
                {{ $employee->jobTitle?->name ?? 'No designation set' }}
                @if($employee->department?->name)
                    <span class="text-zinc-300 dark:text-zinc-600">·</span> {{ $employee->department->name }}
                @endif
            </p>

            <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2 text-xs sm:grid-cols-4">
                <div>
                    <dt class="font-semibold uppercase tracking-wide text-zinc-400">Employee ID</dt>
                    <dd class="mt-0.5 font-mono font-semibold text-zinc-700 dark:text-zinc-200">{{ $employee->employee_id ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-semibold uppercase tracking-wide text-zinc-400">Reports to</dt>
                    <dd class="mt-0.5 font-semibold text-zinc-700 dark:text-zinc-200">{{ $employee->manager?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-semibold uppercase tracking-wide text-zinc-400">Joined</dt>
                    <dd class="mt-0.5 font-semibold text-zinc-700 dark:text-zinc-200">
                        {{ $employee->joining_date?->format('d M Y') ?? 'Not set' }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold uppercase tracking-wide text-zinc-400">Shift</dt>
                    <dd class="mt-0.5 font-semibold text-zinc-700 dark:text-zinc-200">{{ $employee->shift?->name ?? '—' }}</dd>
                </div>
            </dl>

            @if($flags)
                <div class="mt-3 flex flex-wrap justify-center gap-1.5 sm:justify-start">
                    @foreach($flags as $flag)
                        <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                            <flux:icon.exclamation-triangle class="size-3" />{{ $flag }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Completion --}}
        @if($completion)
            <div class="shrink-0 self-center text-center">
                @php
                    $pct = $completion['percent'];
                    $tone = $pct >= 90 ? 'text-emerald-500' : ($pct >= 60 ? 'text-orange-500' : 'text-rose-500');
                @endphp
                <div class="relative size-20">
                    <svg viewBox="0 0 36 36" class="size-20 -rotate-90">
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="currentColor"
                                class="text-zinc-100 dark:text-white/10" stroke-width="3" />
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="currentColor"
                                class="{{ $tone }} transition-all duration-700" stroke-width="3"
                                stroke-linecap="round"
                                stroke-dasharray="{{ $pct }} 100" />
                    </svg>
                    <span class="absolute inset-0 flex items-center justify-center text-sm font-black text-zinc-900 dark:text-white">{{ $pct }}%</span>
                </div>
                <p class="mt-1 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">Complete</p>
                @if(count($completion['missing']) > 0)
                    <p class="text-[11px] text-zinc-400">{{ count($completion['missing']) }} left</p>
                @endif
            </div>
        @endif
    </div>
</div>
