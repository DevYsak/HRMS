@php
    use App\Services\Profile\ProfileFieldRegistry as Registry;

    // The input type and its choices both come from the registry, so adding a
    // field — or repointing one at a different master table — never means
    // touching this partial.
    $meta = Registry::get($field) ?? [];
    $type = $meta['type'] ?? 'text';
    $label = Registry::label($field);
    $options = Registry::optionsFor($field);
@endphp

@switch($type)
    @case('textarea')
        <flux:textarea wire:model="editingValue" :label="$label" rows="3" />
        @break

    @case('date')
        <flux:input wire:model="editingValue" type="date" :label="$label" />
        @break

    @case('select')
    @case('relation')
        <flux:select wire:model="editingValue" :label="$label">
            <option value="">Not specified</option>
            @foreach($options as $value => $text)
                <option value="{{ $value }}">{{ $text }}</option>
            @endforeach
        </flux:select>
        @if(empty($options))
            <p class="-mt-3 text-xs text-zinc-500">
                No {{ Str::lower($label) }} records exist yet — create one first.
            </p>
        @endif
        @break

    @case('number')
        <flux:input wire:model="editingValue" type="number" :label="$label" />
        @break

    @case('email')
        <flux:input wire:model="editingValue" type="email" :label="$label" />
        @break

    @case('tel')
        <flux:input wire:model="editingValue" type="tel" :label="$label" />
        @break

    @default
        <flux:input wire:model="editingValue" type="text" :label="$label" />
@endswitch

@error('editingValue')
    <p class="-mt-3 text-xs font-semibold text-rose-600">{{ $message }}</p>
@enderror
