<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Help\RouteAccess;
use Illuminate\Support\Facades\Route;

/**
 * Where things live in Pulse, so the assistant can point a person to the right
 * page when it cannot (or should not) answer from data. Every link is checked
 * against the reader's access with RouteAccess — the assistant never offers a
 * page that would answer 403. Menu paths match the sidebar labels.
 */
class AiHelpLinks
{
    /**
     * topic => keywords that signal it, and the pages that answer it (own page first).
     *
     * @var array<string, array{keywords: list<string>, pages: list<array{route: string, label: string, path: string}>}>
     */
    private const TOPICS = [
        'overtime' => [
            'keywords' => ['overtime', 'ot', 'ots', 'extra hours'],
            'pages' => [
                ['route' => 'overtime.my', 'label' => 'Open My Overtime', 'path' => 'My Work → My Overtime'],
                ['route' => 'overtime.manage', 'label' => 'Open Overtime Requests', 'path' => 'Approvals → Overtime Requests'],
            ],
        ],
        'leave' => [
            'keywords' => ['leave', 'time off', 'holiday leave', 'casual', 'sick', 'comp off', 'csl', 'mdl'],
            'pages' => [
                ['route' => 'time-off.my', 'label' => 'Open My Leave', 'path' => 'My Work → My Leave'],
                ['route' => 'time-off.team', 'label' => 'Open Team Leave', 'path' => 'Approvals → Team Leave'],
            ],
        ],
        'wfh' => [
            'keywords' => ['wfh', 'work from home', 'remote'],
            'pages' => [
                ['route' => 'wfh.my', 'label' => 'Open My WFH', 'path' => 'My Work → My WFH'],
                ['route' => 'wfh.manage', 'label' => 'Open WFH Requests', 'path' => 'Approvals → WFH Requests'],
            ],
        ],
        'attendance' => [
            'keywords' => ['attendance', 'regularis', 'regulariz', 'punch', 'check in', 'check-in', 'clock', 'missing checkout'],
            'pages' => [
                ['route' => 'attendance.my', 'label' => 'Open My Attendance', 'path' => 'My Work → My Attendance'],
                ['route' => 'attendance.command-center', 'label' => 'Open Command Center', 'path' => 'Approvals → Command Center'],
            ],
        ],
        'payslip' => [
            'keywords' => ['payslip', 'salary slip', 'pay slip', 'salary'],
            'pages' => [
                ['route' => 'payroll.payslips', 'label' => 'Open My Payslips', 'path' => 'My Work → My Payslips'],
            ],
        ],
        'profile' => [
            'keywords' => ['profile', 'photo', 'phone number', 'address', 'bank', 'pan', 'aadhaar', 'aadhar', 'emergency contact'],
            'pages' => [
                ['route' => 'profile.me', 'label' => 'Open My Profile', 'path' => 'your name (top right) → My Profile'],
            ],
        ],
        'holidays' => [
            'keywords' => ['holiday', 'bank holiday', 'public holiday'],
            'pages' => [
                ['route' => 'holidays.calendar', 'label' => 'Open Holidays', 'path' => 'My Work → Holidays'],
            ],
        ],
        'notifications' => [
            'keywords' => ['notification', 'email alert', 'emails'],
            'pages' => [
                ['route' => 'settings.my-notifications', 'label' => 'Open My notifications', 'path' => 'Settings → My notifications'],
            ],
        ],
        'preferences' => [
            'keywords' => ['date format', 'time format', 'timezone', 'time zone', '24-hour', '12-hour', 'preference'],
            'pages' => [
                ['route' => 'settings.preferences', 'label' => 'Open Preferences', 'path' => 'Settings → Preferences'],
            ],
        ],
    ];

    public function __construct(private RouteAccess $access) {}

    /**
     * The topics a question is about, most specific first.
     *
     * @return list<string>
     */
    public function topicsFor(string $question): array
    {
        $haystack = mb_strtolower($question);

        return array_values(array_keys(array_filter(
            self::TOPICS,
            fn (array $topic) => collect($topic['keywords'])->contains(fn (string $keyword) => self::mentions($haystack, $keyword)),
        )));
    }

    /**
     * Word-aware keyword match: short keywords ("ot", "pan") must be whole
     * words so "other" is not overtime; longer ones match as a word prefix so
     * "regularis" finds "regularisation".
     */
    public static function mentions(string $haystack, string $keyword): bool
    {
        $pattern = '/(?<![\pL\pN])'.preg_quote($keyword, '/').(mb_strlen($keyword) <= 3 ? '(?![\pL\pN])' : '').'/u';

        return (bool) preg_match($pattern, mb_strtolower($haystack));
    }

    /**
     * Pages for one topic the user can actually open.
     *
     * @return list<array{label: string, url: string, path: string}>
     */
    public function pagesFor(User $user, string $topic, bool $includeApprovals = true): array
    {
        return collect(self::TOPICS[$topic]['pages'] ?? [])
            ->when(! $includeApprovals, fn ($pages) => $pages->take(1))
            ->filter(fn (array $page) => Route::has($page['route']) && $this->access->allows($user, $page['route']))
            ->map(fn (array $page) => ['label' => $page['label'], 'url' => route($page['route']), 'path' => $page['path']])
            ->values()
            ->all();
    }

    /**
     * Pages for every topic a question mentions; the Employee Guide when none match.
     *
     * @return list<array{label: string, url: string, path: string}>
     */
    public function pagesForQuestion(User $user, string $question): array
    {
        $pages = collect($this->topicsFor($question))
            ->flatMap(fn (string $topic) => $this->pagesFor($user, $topic))
            ->unique('url')
            ->take(3)
            ->values()
            ->all();

        return $pages !== [] ? $pages : $this->guide($user);
    }

    /**
     * The help guide link, when the user can open it.
     *
     * @return list<array{label: string, url: string, path: string}>
     */
    public function guide(User $user): array
    {
        return Route::has('help.employee-guide') && $this->access->allows($user, 'help.employee-guide')
            ? [['label' => 'Open Employee Guide', 'url' => route('help.employee-guide'), 'path' => 'Help → Employee Guide']]
            : [];
    }
}
