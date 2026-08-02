@php
    use App\Services\Profile\ProfileFieldRegistry as Registry;

    // The input type comes from the registry, so adding a field never means
    // touching this partial.
    $meta = Registry::get($field) ?? [];
    $type = $meta['type'] ?? 'text';
    $label = Registry::label($field);
@endphp

@switch($type)
    @case('textarea')
        <flux:textarea wire:model="editingValue" :label="$label" rows="3" />
        @break

    @case('date')
        <flux:input wire:model="editingValue" type="date" :label="$label" />
        @break

    @case('select')
        <flux:select wire:model="editingValue" :label="$label">
            <option value="">Not specified</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
            <option value="other">Other</option>
        </flux:select>
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
