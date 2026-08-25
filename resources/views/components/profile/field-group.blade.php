@props([
    'group',                 // registry group key: identity | contact | employment | financial
    'employee',
    'pending' => null,       // Collection<field => ProfileChangeRequest>, keyed by field
    'canEdit' => true,
    'asHr' => false,
    'title' => null,
    'icon' => null,
    'only' => null,          // optional whitelist of field keys, to split a group across cards
])

@php
    use App\Services\Profile\ProfileFieldRegistry as Registry;

    $fields = Registry::group($group);

    if ($only) {
        $fields = array_intersect_key($fields, array_flip((array) $only));
    }

    $pending ??= collect();
@endphp

@if(! empty($fields))
    <x-employee.section-card
        :title="$title ?? (App\Services\Profile\ProfileFieldRegistry::GROUPS[$group] ?? ucfirst($group))"
        :icon="$icon"
    >
        <div class="-mt-1">
            @foreach($fields as $key => $meta)
                <x-profile.field
                    :field="$key"
                    :employee="$employee"
                    :pending="$pending->get($key)"
                    :can-edit="$canEdit"
                    :as-hr="$asHr"
                />
            @endforeach
        </div>
    </x-employee.section-card>
@endif
