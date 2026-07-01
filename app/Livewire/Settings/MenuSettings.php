<?php

namespace App\Livewire\Settings;

use App\Models\MenuSetting;
use App\Services\EmployeeMenu;
use Livewire\Component;

/**
 * Admin control for the employee sidebar: enable/disable, reorder and relabel
 * top-level items. Persists to menu_settings; the sidebar reads it fail-open.
 * (Phase 2 — Feature 7, lighter menu-toggle approach.)
 */
class MenuSettings extends Component
{
    /** @var array<int, array{key:string, label:string, enabled:bool, icon:string, type:string}> */
    public array $items = [];

    public function mount(EmployeeMenu $menu): void
    {
        $this->authorize('manage-settings');

        $this->items = array_map(fn ($i) => [
            'key' => $i['key'],
            'label' => $i['label'],
            'enabled' => $i['enabled'],
            'icon' => $i['icon'],
            'type' => $i['type'],
        ], $menu->allForAdmin());
    }

    public function moveUp(int $index): void
    {
        if ($index > 0) {
            [$this->items[$index - 1], $this->items[$index]] = [$this->items[$index], $this->items[$index - 1]];
        }
    }

    public function moveDown(int $index): void
    {
        if ($index < count($this->items) - 1) {
            [$this->items[$index + 1], $this->items[$index]] = [$this->items[$index], $this->items[$index + 1]];
        }
    }

    public function save(): void
    {
        $this->authorize('manage-settings');

        foreach ($this->items as $index => $item) {
            MenuSetting::updateOrCreate(
                ['key' => $item['key']],
                [
                    'label' => trim((string) $item['label']) ?: null,
                    'is_enabled' => (bool) $item['enabled'],
                    'sort_order' => $index,
                ],
            );
        }

        \Flux::toast('Employee sidebar menu saved.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.settings.menu-settings')
            ->layout('layouts.app', ['title' => 'Sidebar Menu']);
    }
}
