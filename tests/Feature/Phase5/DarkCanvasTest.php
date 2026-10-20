<?php

use App\Services\Appearance\UiTheme;

/**
 * Phase 5 policy QA-F-081 — one dark application canvas (#0B1220) from a
 * global CSS variable; pages never set their own dark background.
 */
test('the dark canvas is one global variable, #0B1220', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('--ui-app-bg-dark: #0B1220;')
        ->and($css)->toMatch('/\.dark body,\s*\.dark \[data-flux-main\] \{\s*background-color: var\(--ui-app-bg-dark\);/');
});

test('no page sets its own dark background other than the canvas colour', function () {
    $offenders = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))))
        ->filter(fn (SplFileInfo $f) => str_ends_with($f->getFilename(), '.blade.php'))
        ->flatMap(function (SplFileInfo $f) {
            preg_match_all('/<flux:main\b[^>]*>/s', file_get_contents($f->getPathname()), $tags);

            return collect($tags[0])
                ->flatMap(fn ($tag) => preg_match_all('/dark:bg-(\S+?)(?=[\s"])/', $tag, $m) ? $m[1] : [])
                ->reject(fn ($bg) => $bg === '[#0B1220]')
                ->map(fn ($bg) => str_replace(resource_path('views'), '', $f->getPathname()).': dark:bg-'.$bg);
        })
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('Brand & Layout defaults to the canvas and follows it from an old saved default', function () {
    expect(UiTheme::SCHEMA['background_dark']['default'])->toBe('#0b1220')
        ->and(UiTheme::resolve([])['background_dark'])->toBe('#0b1220')
        ->and(UiTheme::resolve(['background_dark' => '#09090b'])['background_dark'])->toBe('#0b1220')
        ->and(UiTheme::resolve(['background_dark' => '#000000'])['background_dark'])->toBe('#000000');
});
