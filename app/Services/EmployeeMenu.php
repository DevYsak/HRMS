<?php

namespace App\Services;

use App\Models\MenuSetting;

/**
 * Canonical catalog of the employee sidebar's top-level entries, merged with
 * admin overrides (enable/disable, order, label). Fail-open: an item without a
 * MenuSetting row keeps its coded defaults, so the sidebar always renders.
 */
class EmployeeMenu
{
    /**
     * Top-level employee menu entries in their default order.
     * `type` is 'item' (a single link) or 'group' (rendered by its own blade).
     * `active` is a routeIs() pattern; `badge` names a live counter (optional).
     *
     * @var array<int, array{key:string, label:string, icon:string, type:string, route?:string, active?:string, badge?:string}>
     */
    private const CATALOG = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'type' => 'item', 'route' => 'dashboard', 'active' => 'dashboard'],
        ['key' => 'profile', 'label' => 'My Profile', 'icon' => 'user-circle', 'type' => 'item', 'route' => 'profile.edit', 'active' => 'profile.edit'],
        ['key' => 'attendance', 'label' => 'Attendance', 'icon' => 'clock', 'type' => 'item', 'route' => 'attendance.my', 'active' => 'attendance.my'],
        ['key' => 'leave', 'label' => 'Leave', 'icon' => 'calendar-days', 'type' => 'item', 'route' => 'time-off.my', 'active' => 'time-off.my', 'badge' => 'leave'],
        ['key' => 'wfh', 'label' => 'Work From Home', 'icon' => 'home-modern', 'type' => 'item', 'route' => 'wfh.my', 'active' => 'wfh.my'],
        ['key' => 'overtime', 'label' => 'Overtime', 'icon' => 'bolt', 'type' => 'item', 'route' => 'overtime.my', 'active' => 'overtime.my', 'badge' => 'overtime'],
        ['key' => 'performance', 'label' => 'Performance', 'icon' => 'arrow-trending-up', 'type' => 'group'],
        ['key' => 'development', 'label' => 'Development', 'icon' => 'academic-cap', 'type' => 'group'],
        ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'banknotes', 'type' => 'group'],
        ['key' => 'documents', 'label' => 'Documents', 'icon' => 'document-text', 'type' => 'item', 'route' => 'documents.index', 'active' => 'documents.*', 'badge' => 'documents'],
        ['key' => 'inbox', 'label' => 'Inbox', 'icon' => 'inbox', 'type' => 'item', 'route' => 'notifications.index', 'active' => 'notifications.*', 'badge' => 'inbox'],
    ];

    /**
     * Visible, ordered entries for rendering the employee sidebar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visible(): array
    {
        return array_values(array_filter($this->merged(), fn ($item) => $item['enabled']));
    }

    /**
     * Every entry (including disabled) with its current label/enabled/order —
     * for the admin management screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allForAdmin(): array
    {
        return $this->merged();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function merged(): array
    {
        $settings = MenuSetting::map();

        $items = [];
        foreach (self::CATALOG as $index => $item) {
            $setting = $settings->get($item['key']);

            $item['label'] = $setting && $setting->label ? $setting->label : $item['label'];
            $item['enabled'] = $setting ? $setting->is_enabled : true;
            $item['order'] = $setting ? $setting->sort_order : $index;

            $items[] = $item;
        }

        usort($items, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $items;
    }
}
