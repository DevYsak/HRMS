<?php

namespace App\Services\Appearance;

use App\Models\UiThemeSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Company-wide look and feel (Settings → Brand & Layout), as CSS variables.
 *
 * Every setting has a schema (type, allowed range or options) and a default
 * equal to the current NEXCORE design. Stored values are re-validated on
 * every read: anything missing, malformed or out of range silently falls back
 * to its default, so a bad row can never break a page.
 *
 * Rendering rule: a value equal to its default produces NO CSS. Shared
 * components read the variables with the original design as the fallback
 * (e.g. `var(--ui-card-radius, 16px)`), and the few page-wide overrides
 * (content width, page padding, table density, control sizes, header height,
 * colours) are only emitted once an admin changes them. Out of the box the
 * app therefore renders exactly as before.
 */
class UiTheme
{
    public const CACHE_KEY = 'ui_theme.css.v1';

    public const VALUES_CACHE_KEY = 'ui_theme.values.v1';

    /** The dark background default before QA-F-081 (zinc-950). */
    private const LEGACY_BACKGROUND_DARK = '#09090b';

    /** Font choices: key => [label, CSS stack, Google Fonts family or null]. */
    public const FONTS = [
        'inter' => ['Inter', "'Inter', ui-sans-serif, system-ui, sans-serif", 'Inter:wght@300;400;500;600;700;800'],
        'poppins' => ['Poppins', "'Poppins', ui-sans-serif, system-ui, sans-serif", 'Poppins:wght@300;400;500;600;700;800'],
        'manrope' => ['Manrope', "'Manrope', ui-sans-serif, system-ui, sans-serif", 'Manrope:wght@300;400;500;600;700;800'],
        'dm_sans' => ['DM Sans', "'DM Sans', ui-sans-serif, system-ui, sans-serif", 'DM+Sans:wght@300;400;500;600;700;800'],
        'plus_jakarta' => ['Plus Jakarta Sans', "'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif", 'Plus+Jakarta+Sans:wght@300;400;500;600;700;800'],
        'nunito_sans' => ['Nunito Sans', "'Nunito Sans', ui-sans-serif, system-ui, sans-serif", 'Nunito+Sans:wght@300;400;500;600;700;800'],
        'roboto' => ['Roboto', "'Roboto', ui-sans-serif, system-ui, sans-serif", 'Roboto:wght@300;400;500;700;900'],
        'system' => ['System UI', 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif', null],
    ];

    /**
     * key => schema. Types: px (int within min..max), select (one of options),
     * color (#rrggbb).
     *
     * @var array<string, array{group: string, label: string, type: string, default: mixed, min?: int, max?: int, options?: array<string, string>, hint?: string}>
     */
    public const SCHEMA = [
        // Typography
        'font_family' => ['group' => 'typography', 'label' => 'Font family', 'type' => 'select', 'default' => 'inter', 'options' => ['inter' => 'Inter', 'poppins' => 'Poppins', 'manrope' => 'Manrope', 'dm_sans' => 'DM Sans', 'plus_jakarta' => 'Plus Jakarta Sans', 'nunito_sans' => 'Nunito Sans', 'roboto' => 'Roboto', 'system' => 'System UI']],
        'font_size_base' => ['group' => 'typography', 'label' => 'Base font size', 'type' => 'px', 'default' => 14, 'min' => 12, 'max' => 18, 'hint' => 'The everyday text size; every text size scales with it.'],
        'heading_scale' => ['group' => 'typography', 'label' => 'Heading scale', 'type' => 'select', 'default' => 'standard', 'options' => ['compact' => 'Compact', 'standard' => 'Standard', 'large' => 'Large', 'xlarge' => 'Extra large']],
        'font_weight' => ['group' => 'typography', 'label' => 'Font weight', 'type' => 'select', 'default' => 'standard', 'options' => ['light' => 'Light', 'standard' => 'Standard', 'strong' => 'Strong']],
        'line_height' => ['group' => 'typography', 'label' => 'Line height', 'type' => 'select', 'default' => 'standard', 'options' => ['compact' => 'Compact', 'standard' => 'Standard', 'relaxed' => 'Relaxed']],

        // Sidebar (desktop only — the mobile drawer keeps its own size)
        'sidebar_width' => ['group' => 'sidebar', 'label' => 'Sidebar width', 'type' => 'px', 'default' => 290, 'min' => 220, 'max' => 360],
        'sidebar_collapsed_width' => ['group' => 'sidebar', 'label' => 'Collapsed width', 'type' => 'px', 'default' => 64, 'min' => 56, 'max' => 96],
        'sidebar_density' => ['group' => 'sidebar', 'label' => 'Density', 'type' => 'select', 'default' => 'comfortable', 'options' => ['compact' => 'Compact', 'comfortable' => 'Comfortable']],
        'sidebar_icon_size' => ['group' => 'sidebar', 'label' => 'Icon size', 'type' => 'px', 'default' => 16, 'min' => 14, 'max' => 24],
        'sidebar_text_size' => ['group' => 'sidebar', 'label' => 'Menu text size', 'type' => 'px', 'default' => 14, 'min' => 12, 'max' => 16],
        'sidebar_section_gap' => ['group' => 'sidebar', 'label' => 'Section spacing', 'type' => 'px', 'default' => 6, 'min' => 0, 'max' => 24],

        // Layout
        'content_max_width' => ['group' => 'layout', 'label' => 'Content max width', 'type' => 'px', 'default' => 1400, 'min' => 1024, 'max' => 1920],
        'page_padding_x' => ['group' => 'layout', 'label' => 'Page horizontal padding', 'type' => 'px', 'default' => 32, 'min' => 8, 'max' => 64, 'hint' => 'Desktop; phones keep their own padding.'],
        'card_radius' => ['group' => 'layout', 'label' => 'Card radius', 'type' => 'px', 'default' => 16, 'min' => 0, 'max' => 32],
        'card_padding' => ['group' => 'layout', 'label' => 'Card padding', 'type' => 'px', 'default' => 20, 'min' => 8, 'max' => 40],
        'section_gap' => ['group' => 'layout', 'label' => 'Section gap', 'type' => 'px', 'default' => 20, 'min' => 8, 'max' => 48],
        'table_density' => ['group' => 'layout', 'label' => 'Table row height', 'type' => 'select', 'default' => 'standard', 'options' => ['compact' => 'Compact', 'standard' => 'Standard', 'comfortable' => 'Comfortable']],
        'button_height' => ['group' => 'layout', 'label' => 'Button height', 'type' => 'px', 'default' => 40, 'min' => 28, 'max' => 52],
        'button_radius' => ['group' => 'layout', 'label' => 'Button radius', 'type' => 'px', 'default' => 8, 'min' => 0, 'max' => 24],

        // Components
        'input_height' => ['group' => 'components', 'label' => 'Input height', 'type' => 'px', 'default' => 40, 'min' => 28, 'max' => 52],
        'input_radius' => ['group' => 'components', 'label' => 'Input radius', 'type' => 'px', 'default' => 8, 'min' => 0, 'max' => 24],
        'modal_small' => ['group' => 'components', 'label' => 'Small modal width', 'type' => 'px', 'default' => 448, 'min' => 320, 'max' => 640],
        'modal_medium' => ['group' => 'components', 'label' => 'Medium modal width', 'type' => 'px', 'default' => 512, 'min' => 400, 'max' => 800],
        'modal_large' => ['group' => 'components', 'label' => 'Large modal width', 'type' => 'px', 'default' => 672, 'min' => 560, 'max' => 1100],
        'badge_radius' => ['group' => 'components', 'label' => 'Badge shape', 'type' => 'select', 'default' => 'pill', 'options' => ['pill' => 'Pill', 'rounded' => 'Rounded', 'square' => 'Square']],
        'shadow_strength' => ['group' => 'components', 'label' => 'Shadow strength', 'type' => 'select', 'default' => 'subtle', 'options' => ['none' => 'None', 'subtle' => 'Subtle', 'medium' => 'Medium', 'strong' => 'Strong']],
        'header_height' => ['group' => 'components', 'label' => 'Header height', 'type' => 'px', 'default' => 56, 'min' => 48, 'max' => 80],

        // Theme colours (light and dark)
        'primary_light' => ['group' => 'theme', 'label' => 'Primary — light', 'type' => 'color', 'default' => '#f97316'],
        'primary_dark' => ['group' => 'theme', 'label' => 'Primary — dark', 'type' => 'color', 'default' => '#f97316'],
        'secondary_light' => ['group' => 'theme', 'label' => 'Secondary — light', 'type' => 'color', 'default' => '#1f2937'],
        'secondary_dark' => ['group' => 'theme', 'label' => 'Secondary — dark', 'type' => 'color', 'default' => '#cbd5e1'],
        'background_light' => ['group' => 'theme', 'label' => 'Background — light', 'type' => 'color', 'default' => '#fafafa'],
        // QA-F-081: the one dark canvas is #0B1220 (was zinc-950 #09090b).
        'background_dark' => ['group' => 'theme', 'label' => 'Background — dark', 'type' => 'color', 'default' => '#0b1220'],
        'surface_light' => ['group' => 'theme', 'label' => 'Card surface — light', 'type' => 'color', 'default' => '#ffffff'],
        'surface_dark' => ['group' => 'theme', 'label' => 'Card surface — dark', 'type' => 'color', 'default' => '#111827'],
        'border_light' => ['group' => 'theme', 'label' => 'Border — light', 'type' => 'color', 'default' => '#f5f5f5'],
        'border_dark' => ['group' => 'theme', 'label' => 'Border — dark', 'type' => 'color', 'default' => '#1d2333'],
    ];

    public const GROUPS = [
        'typography' => 'Typography',
        'layout' => 'Layout',
        'sidebar' => 'Sidebar',
        'components' => 'Components',
        'theme' => 'Theme',
    ];

    private const HEADING_SCALE = ['compact' => 0.9, 'standard' => 1.0, 'large' => 1.12, 'xlarge' => 1.25];

    private const LINE_HEIGHT = ['compact' => 0.9, 'standard' => 1.0, 'relaxed' => 1.1];

    /** font_weight preset => Tailwind weight tokens. */
    private const WEIGHTS = [
        'light' => ['normal' => 300, 'medium' => 400, 'semibold' => 500, 'bold' => 600, 'extrabold' => 700, 'black' => 800],
        'standard' => ['normal' => 400, 'medium' => 500, 'semibold' => 600, 'bold' => 700, 'extrabold' => 800, 'black' => 900],
        'strong' => ['normal' => 500, 'medium' => 600, 'semibold' => 700, 'bold' => 800, 'extrabold' => 900, 'black' => 900],
    ];

    private const SHADOWS = [
        'none' => 'none',
        'subtle' => '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)',
        'medium' => '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
        'strong' => '0 10px 15px -3px rgb(0 0 0 / 0.12), 0 4px 6px -4px rgb(0 0 0 / 0.1)',
    ];

    private const BADGE_RADIUS = ['pill' => '9999px', 'rounded' => '6px', 'square' => '2px'];

    /** Tailwind text tokens (rem) and their default line heights. */
    private const TEXT = [
        'xs' => [0.75, 'calc(1 / 0.75)'], 'sm' => [0.875, 'calc(1.25 / 0.875)'], 'base' => [1, 'calc(1.5 / 1)'],
        'lg' => [1.125, 'calc(1.75 / 1.125)'], 'xl' => [1.25, 'calc(1.75 / 1.25)'], '2xl' => [1.5, 'calc(2 / 1.5)'],
        '3xl' => [1.875, 'calc(2.25 / 1.875)'], '4xl' => [2.25, 'calc(2.5 / 2.25)'],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(fn (array $s) => $s['default'], self::SCHEMA);
    }

    /**
     * One value made safe: the stored value when it is valid, else the default.
     */
    public static function sanitise(string $key, mixed $value): mixed
    {
        $schema = self::SCHEMA[$key] ?? null;

        if ($schema === null) {
            return null;
        }

        return match ($schema['type']) {
            'px' => is_numeric($value) && (int) $value >= $schema['min'] && (int) $value <= $schema['max'] ? (int) $value : $schema['default'],
            'select' => is_string($value) && array_key_exists($value, $schema['options']) ? $value : $schema['default'],
            'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $schema['default'],
            default => $schema['default'],
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> every key, each valid
     */
    public static function resolve(array $values): array
    {
        $out = [];
        foreach (self::SCHEMA as $key => $schema) {
            $out[$key] = self::sanitise($key, $values[$key] ?? $schema['default']);
        }

        // A save made before QA-F-081 stored the old default dark background;
        // it was never a choice, so it follows the new canvas.
        if (strtolower($out['background_dark']) === self::LEGACY_BACKGROUND_DARK) {
            $out['background_dark'] = self::SCHEMA['background_dark']['default'];
        }

        return $out;
    }

    /** Laravel validation rules for the settings form. */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::SCHEMA as $key => $schema) {
            $rules["values.$key"] = match ($schema['type']) {
                'px' => ['required', 'integer', 'min:'.$schema['min'], 'max:'.$schema['max']],
                'select' => ['required', 'string', 'in:'.implode(',', array_keys($schema['options']))],
                'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            };
        }

        return $rules;
    }

    /** The saved settings, each one valid (cached; cleared on save). */
    public function current(): array
    {
        $stored = rescue(fn () => Cache::rememberForever(self::VALUES_CACHE_KEY,
            // Read-only (the page head must never write); no row = current design.
            fn () => UiThemeSetting::query()->first()?->settings ?? []), [], false);

        return self::resolve(is_array($stored) ? $stored : []);
    }

    /** The stylesheet for the page head (cached; cleared on save). */
    public function css(): string
    {
        return rescue(
            fn () => Cache::rememberForever(self::CACHE_KEY, fn () => $this->build($this->current())),
            '',
            false,
        );
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::VALUES_CACHE_KEY);
    }

    /** Google Fonts URL for a non-default font, or null (Inter is loaded by the head already). */
    public function fontUrl(?array $values = null): ?string
    {
        $values ??= $this->current();
        $font = self::FONTS[$values['font_family']] ?? null;

        if ($values['font_family'] === 'inter' || $font === null || $font[2] === null) {
            return null;
        }

        return 'https://fonts.googleapis.com/css2?family='.$font[2].'&display=swap';
    }

    /**
     * The CSS for a set of values. Only differences from the defaults are
     * written, so defaults render exactly the current design.
     *
     * @param  array<string, mixed>  $values  already resolved
     */
    public function build(array $values): string
    {
        $d = self::defaults();
        $changed = fn (string $key) => $values[$key] !== $d[$key];
        $root = [];
        $dark = [];
        $rules = [];

        // Typography — scale Tailwind's own tokens so every text-* follows.
        if ($changed('font_family')) {
            $root['--font-sans'] = self::FONTS[$values['font_family']][1];
        }
        if ($changed('font_size_base') || $changed('heading_scale') || $changed('line_height')) {
            $scale = $values['font_size_base'] / 14;
            $heading = self::HEADING_SCALE[$values['heading_scale']];
            $leading = self::LINE_HEIGHT[$values['line_height']];
            foreach (self::TEXT as $size => [$rem, $lineHeight]) {
                $factor = in_array($size, ['xl', '2xl', '3xl', '4xl'], true) ? $scale * $heading : $scale;
                $root["--text-$size"] = round($rem * $factor, 4).'rem';
                $root["--text-$size--line-height"] = $leading === 1.0 ? $lineHeight : "calc($lineHeight * $leading)";
            }
        }
        if ($changed('font_weight')) {
            foreach (self::WEIGHTS[$values['font_weight']] as $token => $weight) {
                $root["--font-weight-$token"] = (string) $weight;
            }
        }

        // Sidebar — read by app.css (desktop media query only).
        foreach (['sidebar_width' => '--ui-sidebar-width', 'sidebar_collapsed_width' => '--ui-sidebar-collapsed-width', 'sidebar_icon_size' => '--ui-sidebar-icon-size', 'sidebar_text_size' => '--ui-sidebar-text-size', 'sidebar_section_gap' => '--ui-sidebar-section-gap'] as $key => $var) {
            if ($changed($key)) {
                $root[$var] = $values[$key].'px';
            }
        }
        if ($changed('sidebar_density')) {
            $root['--ui-sidebar-item-gap'] = '1px';
            $root['--ui-sidebar-item-height'] = '28px';
        }

        // Cards, badges, shadows, modals — read by the shared components.
        foreach (['card_radius' => '--ui-card-radius', 'card_padding' => '--ui-card-padding', 'section_gap' => '--ui-section-gap', 'modal_small' => '--ui-modal-sm', 'modal_medium' => '--ui-modal-md', 'modal_large' => '--ui-modal-lg'] as $key => $var) {
            if ($changed($key)) {
                $root[$var] = $values[$key].'px';
            }
        }
        if ($changed('badge_radius')) {
            $root['--ui-badge-radius'] = self::BADGE_RADIUS[$values['badge_radius']];
        }
        if ($changed('shadow_strength')) {
            $root['--ui-card-shadow'] = self::SHADOWS[$values['shadow_strength']];
        }

        // Colours.
        foreach (['light', 'dark'] as $mode) {
            $vars = [];
            foreach (['surface' => '--ui-surface', 'border' => '--ui-border', 'secondary' => '--ui-secondary'] as $name => $var) {
                if ($changed("{$name}_{$mode}")) {
                    // The dark values live in their own variables so a light
                    // colour never leaks into dark mode.
                    $vars[$mode === 'dark' ? "$var-dark" : $var] = $values["{$name}_{$mode}"];
                }
            }
            if ($changed("primary_{$mode}")) {
                $palette = self::palette($values["primary_{$mode}"]);
                foreach ($palette as $shade => $hex) {
                    $vars["--color-brand-$shade"] = $hex;
                    $vars["--color-orange-$shade"] = $hex;
                }
                $vars['--color-accent'] = $palette[500];
                $vars['--color-accent-content'] = $mode === 'dark' ? $palette[400] : $palette[600];
            }

            if ($mode === 'light') {
                $root = array_merge($root, $vars);
            } else {
                $dark = array_merge($dark, $vars);
            }
        }

        // Page-wide overrides — only once changed.
        if ($changed('background_light')) {
            $rules[] = "body, [data-flux-main] { background-color: {$values['background_light']}; }";
        }
        if ($changed('background_dark')) {
            $rules[] = ".dark body, .dark [data-flux-main] { background-color: {$values['background_dark']}; }";
        }
        if ($changed('content_max_width')) {
            $w = $values['content_max_width'];
            $rules[] = "[data-flux-main] .max-w-\\[1400px\\], [data-flux-main] .max-w-7xl { max-width: {$w}px; }";
        }
        if ($changed('page_padding_x')) {
            $rules[] = "@media (min-width: 1024px) { [data-flux-main]:not(.p-0):not(.p-0\\!) { padding-inline: {$values['page_padding_x']}px; } }";
        }
        if ($changed('section_gap')) {
            $rules[] = "[data-flux-main] > .space-y-5 > :not(:last-child), [data-flux-main] > div > .space-y-5 > :not(:last-child) { margin-bottom: 0; } [data-flux-main] > .space-y-5 > * + *, [data-flux-main] > div > .space-y-5 > * + * { margin-top: {$values['section_gap']}px; }";
        }
        if ($changed('table_density')) {
            $py = ['compact' => '0.375rem', 'comfortable' => '0.875rem'][$values['table_density']] ?? null;
            if ($py) {
                $rules[] = "[data-flux-main] table td { padding-top: $py; padding-bottom: $py; }";
            }
        }
        if ($changed('button_height')) {
            $rules[] = "[data-flux-button].h-10 { height: {$values['button_height']}px; }";
        }
        if ($changed('button_radius')) {
            $rules[] = "[data-flux-button].rounded-lg { border-radius: {$values['button_radius']}px; }";
        }
        if ($changed('input_height')) {
            $rules[] = "input[data-flux-control].h-10, [data-flux-select-button].h-10, select[data-flux-control].h-10 { height: {$values['input_height']}px; }";
        }
        if ($changed('input_radius')) {
            $rules[] = "input[data-flux-control], textarea[data-flux-control], select[data-flux-control], [data-flux-select-button] { border-radius: {$values['input_radius']}px; }";
        }
        if ($changed('header_height')) {
            $rules[] = "[data-flux-header] { min-height: {$values['header_height']}px; }";
        }

        $css = '';
        if ($root !== []) {
            $css .= ':root{'.self::declarations($root).'}';
        }
        if ($dark !== []) {
            $css .= '.dark{'.self::declarations($dark).'}';
        }

        return $css.implode('', $rules);
    }

    /**
     * The variables for a scoped live preview (inline style), from unsaved
     * values — every key, so the preview matches what Save would do.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function previewVariables(array $values): array
    {
        $v = self::resolve($values);
        $palette = self::palette($v['primary_light']);

        return [
            '--font-sans' => self::FONTS[$v['font_family']][1],
            '--pv-font-size' => $v['font_size_base'].'px',
            '--pv-heading-size' => round(24 * ($v['font_size_base'] / 14) * self::HEADING_SCALE[$v['heading_scale']], 1).'px',
            '--pv-heading-weight' => (string) self::WEIGHTS[$v['font_weight']]['bold'],
            '--pv-body-weight' => (string) self::WEIGHTS[$v['font_weight']]['normal'],
            '--pv-line-height' => (string) round(1.43 * self::LINE_HEIGHT[$v['line_height']], 2),
            '--ui-card-radius' => $v['card_radius'].'px',
            '--ui-card-padding' => $v['card_padding'].'px',
            '--ui-section-gap' => $v['section_gap'].'px',
            '--ui-badge-radius' => self::BADGE_RADIUS[$v['badge_radius']],
            '--ui-card-shadow' => self::SHADOWS[$v['shadow_strength']],
            '--ui-surface' => $v['surface_light'],
            '--ui-border' => $v['border_light'],
            '--ui-secondary' => $v['secondary_light'],
            '--pv-bg' => $v['background_light'],
            '--pv-primary' => $palette[500],
            '--pv-primary-soft' => $palette[50],
            '--pv-primary-strong' => $palette[700],
            '--pv-button-height' => $v['button_height'].'px',
            '--pv-button-radius' => $v['button_radius'].'px',
            '--pv-input-height' => $v['input_height'].'px',
            '--pv-input-radius' => $v['input_radius'].'px',
            '--pv-row-py' => ['compact' => '6px', 'standard' => '10px', 'comfortable' => '14px'][$v['table_density']],
            '--pv-sidebar-width' => round($v['sidebar_width'] * 0.62).'px',
            '--pv-sidebar-text' => $v['sidebar_text_size'].'px',
            '--pv-sidebar-icon' => $v['sidebar_icon_size'].'px',
            '--pv-sidebar-gap' => $v['sidebar_density'] === 'compact' ? '1px' : '3px',
            '--pv-sidebar-item' => $v['sidebar_density'] === 'compact' ? '28px' : '32px',
            '--pv-header-height' => round($v['header_height'] * 0.8).'px',
        ];
    }

    /**
     * A 50…950 palette from one colour, by mixing with white (light shades)
     * and black (dark shades). 500 is the colour itself.
     *
     * @return array<int, string>
     */
    public static function palette(string $hex): array
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $mix = fn (array $to, float $t) => sprintf('#%02x%02x%02x',
            (int) round($r + ($to[0] - $r) * $t), (int) round($g + ($to[1] - $g) * $t), (int) round($b + ($to[2] - $b) * $t));

        $white = [255, 255, 255];
        $black = [0, 0, 0];

        return [
            50 => $mix($white, 0.94), 100 => $mix($white, 0.86), 200 => $mix($white, 0.70), 300 => $mix($white, 0.50),
            400 => $mix($white, 0.25), 500 => strtolower($hex), 600 => $mix($black, 0.12), 700 => $mix($black, 0.28),
            800 => $mix($black, 0.42), 900 => $mix($black, 0.55), 950 => $mix($black, 0.72),
        ];
    }

    /** @param  array<string, string>  $vars */
    private static function declarations(array $vars): string
    {
        $out = '';
        foreach ($vars as $name => $value) {
            // Values come from the schema only (numbers, known stacks, #hex),
            // never raw input — but strip anything that could end the block.
            $out .= $name.':'.str_replace(['{', '}', '<', '>', ';'], '', (string) $value).';';
        }

        return $out;
    }
}
