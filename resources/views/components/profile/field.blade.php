@props([
    'field',            // registry key — the only thing a caller must pass
    'employee',
    'pending' => null,  // ProfileChangeRequest awaiting review, if any
    'canEdit' => true,  // viewer may act at all (self on own page, or HR)
    'asHr' => false,    // HR surface: locked fields become editable
])

@php
    use App\Services\Profile\ProfileFieldRegistry as Registry;

    // Every decision below comes from the registry — Blade never hardcodes
    // which fields are locked, so re-tiering a field needs no template edit.
    $meta    = Registry::get($field);
    $label   = Registry::label($field);
    $value   = Registry::displayValueFor($employee, $field);
    $tier    = Registry::tier($field);

    // Media needs a file picker, not a text row. The avatar in the hero is the
    // single place a photo is managed, so this row would only ever be a broken
    // duplicate of it.
    $isMedia = ($meta['type'] ?? null) === 'image';

    // On the HR surface every registered field is writable except those a
    // dedicated workflow owns; on the employee's own page the tier decides.
    // A field HR has made HR-only is managed by HR, whatever its tier.
    $hrOnly = ! $asHr && $tier !== Registry::TIER_LOCKED && Registry::isHrOnly($field);
    $requirement = $tier !== Registry::TIER_LOCKED ? Registry::requirement($field) : null;

    $mode = match (true) {
        ! $canEdit                         => 'read',
        $asHr && Registry::isHrEditable($field) => 'edit',
        $hrOnly                            => 'locked',
        $tier === Registry::TIER_EDITABLE  => 'edit',
        $tier === Registry::TIER_APPROVAL  => 'request',
        default                            => 'locked',
    };

    $isEmpty = $value === null || $value === '';
@endphp

@unless($isMedia)
@php
    // One compact ownership pill per row, aligned right, so a reader can scan who
    // owns each field without hovering anything.
    $pill = match ($mode) {
        'edit' => ['You can edit', 'bg-emerald-50 text-emerald-700 ring-emerald-200/70 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20'],
        'request' => ['Needs HR approval', 'bg-amber-50 text-amber-700 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20'],
        'locked' => ['Managed by HR', 'bg-zinc-100 text-zinc-500 ring-zinc-200/70 dark:bg-white/5 dark:text-zinc-400 dark:ring-white/10'],
        default => null,
    };
@endphp
<div
    class="flex items-start justify-between gap-3 border-b border-zinc-100 py-2.5 last:border-0 dark:border-white/5"
    wire:key="profile-field-{{ $field }}"
    data-profile-field="{{ $field }}"
>
    {{-- Label + value --}}
    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-1.5">
            <span class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">{{ $label }}</span>

            @if($mode === 'locked')
                <flux:icon.lock-closed class="size-3 shrink-0 text-zinc-300 dark:text-zinc-600" />
            @elseif($requirement === \App\Models\ProfileFieldSetting::REQUIRED && $isEmpty)
                <span class="rounded bg-rose-50 px-1 text-[9px] font-bold uppercase text-rose-600 dark:bg-rose-500/10 dark:text-rose-300">Required</span>
            @elseif($requirement === \App\Models\ProfileFieldSetting::OPTIONAL)
                <span class="rounded bg-zinc-100 px-1 text-[9px] font-bold uppercase text-zinc-400 dark:bg-white/5">Optional</span>
            @endif
        </div>

        <div class="mt-1 flex flex-wrap items-center gap-2">
            <span @class([
                'text-sm leading-snug break-words',
                'font-medium text-zinc-900 dark:text-white' => ! $isEmpty,
                'italic text-zinc-400' => $isEmpty,
            ])>
                {{ $isEmpty ? 'Not set' : $value }}
            </span>

            {{-- A pending request shows the requested value alongside the live
                 one, never instead of it — the record must not display a value
                 HR has not accepted. --}}
            @if($pending)
                <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                    <flux:icon.clock class="size-3" />
                    {{ $pending->new_value ?: '—' }} pending
                </span>
            @endif
        </div>

        @if($mode === 'locked')
            <p class="mt-0.5 text-[11px] leading-snug text-zinc-400">{{ $hrOnly ? 'Managed by HR. Contact HR to change it.' : Registry::lockReason($field) }}</p>
        @endif
    </div>

    {{-- Ownership + action --}}
    <div class="flex shrink-0 items-center gap-1.5">
        @if($pill)
            <span class="hidden whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset sm:inline-flex {{ $pill[1] }}">{{ $pill[0] }}</span>
        @endif

        @if($mode === 'edit')
            <flux:button wire:click="editField('{{ $field }}')" size="xs" variant="ghost" icon="pencil" aria-label="Edit {{ $label }}" />

        @elseif($mode === 'request')
            @if($pending)
                <flux:button wire:click="viewRequest({{ $pending->id }})" size="xs" variant="ghost" class="text-amber-600 dark:text-amber-400">
                    View request
                </flux:button>
            @else
                <flux:button wire:click="requestField('{{ $field }}')" size="xs" variant="ghost" icon="arrow-up-tray">
                    Request
                </flux:button>
            @endif

        @elseif($mode === 'locked')
            {{-- Phones: the pill above is hidden, so the label stays visible here. --}}
            <span class="inline-flex whitespace-nowrap rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-500 sm:hidden dark:bg-white/5 dark:text-zinc-400">Managed by HR</span>
        @endif
    </div>
</div>
@endunless
