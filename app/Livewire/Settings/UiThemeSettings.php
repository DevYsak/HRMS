<?php

namespace App\Livewire\Settings;

use App\Models\Company;
use App\Models\UiThemeSetting;
use App\Services\Appearance\UiTheme;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Settings → Brand & Layout: the company-wide look of the whole panel —
 * typography, layout, sidebar, components and theme colours — with a live
 * preview before anything is saved. Values are validated against
 * UiTheme::SCHEMA; the logos and favicon stay on Company settings.
 */
class UiThemeSettings extends Component
{
    public const TABS = ['branding' => 'Branding', 'typography' => 'Typography', 'layout' => 'Layout', 'sidebar' => 'Sidebar', 'components' => 'Components', 'theme' => 'Theme'];

    #[Url]
    public string $tab = 'branding';

    /** @var array<string, mixed> */
    public array $values = [];

    public function mount(): void
    {
        $this->authorizeSettings();
        $this->values = app(UiTheme::class)->current();
    }

    private function authorizeSettings(): void
    {
        abort_unless(Auth::user()?->canManageSettings(), 403);
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'branding';
    }

    /** Fill the form with the original design (nothing is saved until Save). */
    public function resetToDefaults(): void
    {
        $this->values = UiTheme::defaults();
        $this->resetErrorBag();
        \Flux::toast('Default design loaded — Save to apply it.');
    }

    public function save(): void
    {
        $this->authorizeSettings();
        $this->validate(UiTheme::rules(), [], collect(UiTheme::SCHEMA)->mapWithKeys(fn ($s, $k) => ["values.$k" => mb_strtolower($s['label'])])->all());

        $setting = UiThemeSetting::current();
        $old = UiTheme::resolve($setting->settings ?? []);
        $new = UiTheme::resolve($this->values);

        // Only the differences from the default design are stored.
        $defaults = UiTheme::defaults();
        $setting->update([
            'settings' => array_filter($new, fn ($value, $key) => $value !== $defaults[$key], ARRAY_FILTER_USE_BOTH),
            'updated_by' => Auth::id(),
        ]);

        $changed = array_keys(array_diff_assoc(array_map('strval', $new), array_map('strval', $old)));
        app(AuditService::class)->event('UI_THEME_UPDATED', AuditService::SETTINGS, $setting,
            old: array_intersect_key($old, array_flip($changed)), new: array_intersect_key($new, array_flip($changed)),
            module: AuditService::SETTINGS);

        UiTheme::flush();
        \Flux::toast('Brand & layout saved — applied across the panel.', variant: 'success');

        // A full reload so the new stylesheet in <head> takes effect here too.
        $this->redirect(route('settings.ui-theme', ['tab' => $this->tab]));
    }

    public function render()
    {
        $theme = app(UiTheme::class);
        $resolved = UiTheme::resolve($this->values);
        $company = rescue(fn () => Company::query()->first(), null, false);
        $logo = fn (string $field) => ($path = $company?->getAttribute($field)) ? Storage::url($path) : null;

        return view('livewire.settings.ui-theme-settings', [
            'schema' => collect(UiTheme::SCHEMA)->where('group', $this->tab)->all(),
            'preview' => collect($theme->previewVariables($resolved))->map(fn ($v, $k) => $k.':'.str_replace(['{', '}', ';', '<', '>', '"'], '', $v))->implode(';'),
            'previewFontUrl' => $theme->fontUrl($resolved),
            'changedCount' => count(array_diff_assoc(array_map('strval', $resolved), array_map('strval', UiTheme::defaults()))),
            'branding' => [
                ['label' => 'Logo — light', 'url' => $logo('logo')],
                ['label' => 'Logo — dark', 'url' => $logo('logo_dark')],
                ['label' => 'Sidebar icon — light', 'url' => $logo('logo_mark')],
                ['label' => 'Sidebar icon — dark', 'url' => $logo('logo_mark_dark')],
                ['label' => 'Favicon', 'url' => $logo('favicon')],
            ],
        ])->layout('layouts.app', ['title' => 'Brand & Layout']);
    }
}
