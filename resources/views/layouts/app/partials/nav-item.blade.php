{{-- One sidebar link from App\Services\Navigation\Sidebar. Downloads (reports)
     are plain links; pages use wire:navigate. The explicit tooltip keeps the
     collapsed-rail hover label plain text. --}}
@if($item['navigate'])
    <flux:sidebar.item :icon="$icon" :href="$item['url']" :current="request()->routeIs(...$item['active'])"
        :tooltip="$item['label']" :badge="$item['badge']" :badge-color="$item['badgeColor']" wire:navigate>
        {{ $item['label'] }}
    </flux:sidebar.item>
@else
    <flux:sidebar.item :icon="$icon" :href="$item['url']" :tooltip="$item['label']">
        {{ $item['label'] }}
    </flux:sidebar.item>
@endif
