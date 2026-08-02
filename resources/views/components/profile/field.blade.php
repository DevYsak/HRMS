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

    // On the HR surface every registered field is writable; on the employee's
    // own page the tier decides.
    $mode = match (true) {
        ! $canEdit                         => 'read',
        $asHr && $meta !== null            => 'edit',
        $tier === Registry::TIER_EDITABLE  => 'edit',
        $tier === Registry::TIER_APPROVAL  => 'request',
        default                            => 'locked',
    };

    $isEmpty = $value === null || $value === '';
@endphp

@unless($isMedia)
<div
    class="group flex items-start justify-between gap-4 border-b border-zinc-100 py-3 last:border-0 dark:border-white/5"
    wire:key="profile-field-{{ $field }}"
>
    {{-- Label + value --}}
    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ $label }}</span>

            @if($mode === 'locked')
                <flux:icon.lock-closed class="size-3 shrink-0 text-zinc-300 dark:text-zinc-600" />
            @endif
        </div>

        <div class="mt-0.5 flex flex-wrap items-center gap-2">
            <span @class([
                'text-sm',
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
            <p class="mt-1 text-[11px] leading-snug text-zinc-400">{{ Registry::lockReason($field) }}</p>
        @endif
    </div>

    {{-- Action --}}
    <div class="shrink-0 pt-0.5">
        @if($mode === 'edit')
            <flux:button
                wire:click="editField('{{ $field }}')"
                size="xs"
                variant="ghost"
                icon="pencil"
                class="opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100"
            >
                <span class="sr-only">Edit {{ $label }}</span>
            </flux:button>

        @elseif($mode === 'request')
            @if($pending)
                <flux:button wire:click="viewRequest({{ $pending->id }})" size="xs" variant="ghost" class="text-amber-600 dark:text-amber-400">
                    View request
                </flux:button>
            @else
                <flux:button
                    wire:click="requestField('{{ $field }}')"
                    size="xs"
                    variant="ghost"
                    icon="arrow-up-tray"
                    class="opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100"
                >
                    Request
                </flux:button>
            @endif

        @elseif($mode === 'locked')
            <span class="inline-flex items-center gap-1 rounded-md bg-zinc-100 px-1.5 py-0.5 text-[11px] font-semibold text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                Managed by HR
            </span>
        @endif
    </div>
</div>
@endunless
