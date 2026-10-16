<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Services\Help\RouteAccess;
use Illuminate\Support\Facades\Route;

/**
 * A notification link the recipient can actually open. One notification
 * often goes to the employee and to HR; an HR page in the employee's copy
 * was a 403. The first candidate route the recipient's permissions open
 * (RouteAccess — the same check as the sidebar) wins, else the fallback.
 */
trait LinksForRecipient
{
    /**
     * @param  array<int, array{0: string, 1?: array<string, mixed>|int|string}>  $candidates  [route name, parameters]
     */
    protected function linkFor(object $notifiable, array $candidates, string $fallback = 'dashboard'): string
    {
        foreach ($candidates as $candidate) {
            [$name, $parameters] = [$candidate[0], $candidate[1] ?? []];

            if (Route::has($name) && $notifiable instanceof User && app(RouteAccess::class)->allows($notifiable, $name)) {
                return route($name, $parameters);
            }
        }

        return route($fallback);
    }

    /** Whether the recipient is the employee the notification is about. */
    protected function isAbout(object $notifiable, ?int $userId): bool
    {
        return $notifiable instanceof User && $userId !== null && (int) $notifiable->getKey() === $userId;
    }
}
