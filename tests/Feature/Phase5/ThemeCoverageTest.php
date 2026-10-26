<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\LeaveManagement;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * QA-F-061 / QA-F-066 / QA-F-082 · theme coverage.
 *
 * Card containers use the shared .ui-surface (so Brand & Layout's radius,
 * border, surface and shadow apply everywhere), on-screen dates follow the
 * reader's date / time format, and the Leave Management table keeps readable
 * columns. These scans stop new hand-rolled cards and fixed formats creeping
 * back in.
 */

/** Views another session is changing right now (leave cancellation) — swept once it lands. */
const TC_PENDING = [
    'livewire/time-off/my-time-off.blade.php',
    'livewire/time-off/my-leave-balances.blade.php',
    'livewire/time-off/leave-request-detail.blade.php',
    'livewire/time-off/leave-requests.blade.php',
    'livewire/dashboard/leave-overview.blade.php',
    'components/leave/cancel-action.blade.php',
];

/** @return array<string, string> relative path => source, for the screen views (PDFs / exports excluded) */
function tcViews(): array
{
    $views = [];
    foreach (['livewire', 'components', 'pages', 'partials', 'layouts'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path("views/{$dir}"))) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(resource_path('views/'))));
            if (! in_array($relative, TC_PENDING, true)) {
                $views[$relative] = file_get_contents($file->getPathname());
            }
        }
    }

    return $views;
}

test('card containers use the shared ui-surface, not hand-rolled shells', function () {
    $offenders = [];
    foreach (tcViews() as $path => $source) {
        preg_match_all('/<([A-Za-z][\w:.-]*)\b[^>]*?\sclass="([^"]*)"/s', $source, $tags, PREG_SET_ORDER);
        foreach ($tags as [, $tag, $classes]) {
            $tokens = preg_split('/\s+/', trim($classes));
            $card = array_intersect($tokens, ['rounded-2xl', 'rounded-[16px]', 'rounded-[18px]', 'rounded-[20px]'])
                && in_array('border', $tokens, true) && in_array('bg-white', $tokens, true)
                && array_intersect($tokens, ['shadow', 'shadow-sm']);
            if ($card && ! in_array(strtolower($tag), ['button', 'a', 'label', 'input', 'select', 'textarea', 'summary', 'flux:button'], true)) {
                $offenders[] = $path;
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});

test('on-screen dates and times follow the reader\'s format', function () {
    // Kept on purpose: seconds-precision sync logs, calendar day cells and two decorative composites.
    $allowed = ['d M Y, H:i:s', 'h:i:s A', 'D, d M · h:i A', 'D d', 'd M · D', 'l, j F Y'];
    $offenders = [];
    foreach (tcViews() as $path => $source) {
        preg_match_all('/\{\{(.*?)\}\}/s', $source, $echoes);
        foreach ($echoes[1] as $echo) {
            preg_match_all("/->format\\('([^']+)'\\)/", $echo, $formats);
            foreach ($formats[1] as $format) {
                $looksLikeDisplay = preg_match('/d M|M d|M j|j M|F Y|h:i|H:i|g:i/', $format);
                if ($looksLikeDisplay && ! in_array($format, $allowed, true) && ! in_array($format, ['F Y', 'M Y'], true)) {
                    $offenders[] = "{$path}: {$format}";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('the Leave Management balance table keeps readable columns', function () {
    $hr = User::factory()->create(['role_id' => Role::where('slug', 'hr_admin')->value('id'), 'role' => UserRole::HrAdmin]);

    Livewire::actingAs($hr)->test(LeaveManagement::class)
        ->assertOk()
        ->assertSeeHtml('<table class="w-full min-w-[80rem] text-xs" data-leave-balance-table>')
        ->assertSeeHtml('<th class="min-w-44 px-3 py-2">Employee</th>')
        ->assertSeeHtml('<th class="min-w-56 px-3 py-2">Warnings</th>');
});
