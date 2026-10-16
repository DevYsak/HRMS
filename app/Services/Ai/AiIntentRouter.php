<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Attendance\TodayStatusService;
use App\Services\Dashboards\PeopleOperationsService;
use Illuminate\Support\Facades\Route;

/**
 * Answers the questions Pulse can answer exactly — request counts, approval
 * queues, the leave balance — straight from {@see AiDataTools}, without a
 * language model. A model may phrase other answers, but it never produces a
 * count: that is how "how many OT requests are pending?" stays truthful.
 *
 * The one distinction that matters most:
 *  - "my pending OT requests"         → requests I submitted, still waiting
 *  - "OT pending my approval/action"  → other people's requests waiting for me
 */
class AiIntentRouter
{
    /** @var array<string, array{keywords: list<string>, singular: string, plural: string}> */
    private const TYPES = [
        'overtime' => ['keywords' => ['overtime', 'ot', 'ots'], 'singular' => 'OT request', 'plural' => 'OT requests'],
        'leave' => ['keywords' => ['leave', 'time off', 'time-off'], 'singular' => 'leave request', 'plural' => 'leave requests'],
        'wfh' => ['keywords' => ['wfh', 'work from home', 'work-from-home'], 'singular' => 'WFH request', 'plural' => 'WFH requests'],
        'regularisation' => ['keywords' => ['regularis', 'regulariz'], 'singular' => 'regularisation request', 'plural' => 'regularisation requests'],
        'holiday' => ['keywords' => ['holiday work', 'work on holiday', 'work on a holiday'], 'singular' => 'holiday-work request', 'plural' => 'holiday-work requests'],
    ];

    /** Phrases that make the user the APPROVER rather than the requester. */
    private const APPROVER_PATTERN = '/\b(my approval|my action|my decision|for me to (approve|action|decide)|i (need|have) to (approve|action|decide)|should i approve|awaiting (me|my)|waiting (on|for) me\b|waiting for my|approval queue|pending approvals?|to approve|need(s)? approving|my team|my reports|reportees)\b/u';

    private const PENDING_PATTERN = '/\b(pending|waiting|awaiting|outstanding|not (yet )?approved|in progress)\b/u';

    private const COUNT_PATTERN = '/\b(how many|count|number of|total|summary|status|do i have|have i|my)\b/u';

    private const BALANCE_PATTERN = '/\b(balance|left|remaining|available|how much leave|how many days)\b/u';

    public function __construct(private AiDataTools $tools, private AiHelpLinks $links) {}

    /**
     * @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string}|null
     */
    public function answer(User $user, string $question): ?array
    {
        $text = mb_strtolower(trim($question));

        if ($text === '') {
            return null;
        }

        $types = $this->typesIn($text);
        $asksApprover = (bool) preg_match(self::APPROVER_PATTERN, $text);
        $asksPending = (bool) preg_match(self::PENDING_PATTERN, $text);

        // "Show UK Operations health" — only a department inside the reach.
        if (preg_match('/\b(health|overview)\b/u', $text) && ($answer = $this->departmentAnswer($user, $text))) {
            return $answer;
        }

        // "How many people are absent today?" — the shared today summary.
        if (preg_match('/\b(present|absent|late|checked in|check-in|no check|on leave|who is in|attendance)\b/u', $text)
            && preg_match('/\b(today|now|right now)\b/u', $text) && ! preg_match('/\bmy attendance\b/u', $text)) {
            return $this->attendanceAnswer($user);
        }

        // "How many reviews are pending?" — PerformanceStatusService.
        if (preg_match('/\breviews?\b/u', $text) && preg_match('/\b(pending|due|overdue|how many|status|outstanding)\b/u', $text)) {
            return $this->reviewsAnswer($user);
        }

        if ($asksApprover && ($asksPending || $types !== [] || str_contains($text, 'approv'))) {
            return $this->approvalQueueAnswer($user, $types);
        }

        if (in_array('leave', $types, true) && ! $asksPending && preg_match(self::BALANCE_PATTERN, $text)) {
            return $this->leaveBalanceAnswer($user);
        }

        if ($types !== [] && $asksPending) {
            return $this->myPendingAnswer($user, $types);
        }

        if ($types === ['overtime'] && preg_match(self::COUNT_PATTERN, $text)) {
            return $this->myOvertimeAnswer($user);
        }

        if ($types === [] && $asksPending && preg_match('/\b(my|i|me)\b/u', $text) && str_contains($text, 'request')) {
            return $this->myPendingAnswer($user, array_keys(self::TYPES));
        }

        if (preg_match('/\b(where|how (do|can|should) i|how to|which page|what page)\b/u', $text)) {
            return $this->navigationAnswer($user, $question);
        }

        return null;
    }

    /**
     * "Where do I…" — point to the page, never guess at the data.
     *
     * @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string}|null
     */
    private function navigationAnswer(User $user, string $question): ?array
    {
        $topics = $this->links->topicsFor($question);

        if ($topics === []) {
            return null;
        }

        // The person's own page for each topic; approvers get the queue too.
        $pages = collect($topics)
            ->flatMap(fn (string $topic) => $this->links->pagesFor($user, $topic))
            ->unique('url')
            ->take(3)
            ->values()
            ->all();

        if ($pages === []) {
            return null;
        }

        return [
            'content' => 'You can do that under: '.$pages[0]['path'].'.',
            'links' => $pages,
            'source' => 'help',
            'intent' => 'navigation',
        ];
    }

    /** @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string} */
    private function attendanceAnswer(User $user, ?int $departmentId = null): array
    {
        $data = $this->tools->attendanceToday($user, $departmentId);
        $s = $data['summary'];

        if ($data['own']) {
            $status = $s['rows'] !== [] ? array_values($s['rows'])[0] : null;
            $content = $status
                ? 'Today you are: '.(TodayStatusService::LABELS[$status['bucket']] ?? str_replace('_', ' ', $status['bucket'])).'. You do not have access to team attendance.'
                : 'Your account is not linked to an employee record, so there is no attendance to show.';

            return ['content' => $content, 'links' => $this->links->pagesFor($user, 'attendance', false), 'source' => 'pulse', 'intent' => 'attendance_today'];
        }

        $content = $s['day_off']
            ? "Today is a day off in {$data['scope']}: {$s['weekly_off']} on weekly off, {$s['holiday']} on a holiday — nobody is absent."
            : "Today in {$data['scope']}: {$s['present']} present ({$s['late']} late, {$s['working_now']} in now), "
                .($s['no_checkin'] + $s['absent'])." not checked in ({$s['absent']} absent after shift end), {$s['on_leave']} on leave, "
                ."{$s['missing_checkout']} missing checkout. {$s['expected']} of {$s['total']} were due today.";

        return ['content' => $content, 'links' => $this->links->pagesFor($user, 'attendance'), 'source' => 'pulse', 'intent' => 'attendance_today'];
    }

    /** @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string} */
    private function reviewsAnswer(User $user, ?int $departmentId = null): array
    {
        $r = $this->tools->reviews($user, $departmentId);

        if ($r === null) {
            return ['content' => 'You do not review anyone, so no reviews are waiting on you. Your own review is under Performance.', 'links' => [], 'source' => 'pulse', 'intent' => 'reviews'];
        }

        $cycle = $r['cycle']?->name ?? 'the current cycle';
        $content = "{$r['pending']} ".($r['pending'] === 1 ? 'review is' : 'reviews are')." pending in {$r['scope']} for {$cycle}: "
            ."{$r['self_review_pending']} self review, {$r['manager_review_pending']} manager review"
            .($r['overdue'] ? ", {$r['overdue']} overdue" : '').". {$r['completed']} completed, {$r['not_started']} not started.";

        return ['content' => $content, 'links' => [], 'source' => 'pulse', 'intent' => 'reviews'];
    }

    /** @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string}|null */
    private function departmentAnswer(User $user, string $text): ?array
    {
        $department = $this->tools->departmentNamed($user, $text);

        if ($department === null) {
            return str_contains($text, 'department') || str_contains($text, 'operations')
                ? ['content' => 'That department is not in your scope, so Pulse cannot show its figures to you.', 'links' => [], 'source' => 'pulse', 'intent' => 'department_health']
                : null;
        }

        $attendance = $this->attendanceAnswer($user, $department->id)['content'];
        $reviews = $this->tools->reviews($user, $department->id);
        $pending = array_sum(app(PeopleOperationsService::class)->approvalCounts($user, $department->id));

        $content = $attendance."\n".$pending.' '.($pending === 1 ? 'request is' : 'requests are').' waiting for your decision there.'
            .($reviews ? "\n{$reviews['pending']} reviews pending, {$reviews['overdue']} overdue." : '');

        $links = Route::has('departments.show')
            ? [['label' => 'Open '.$department->name, 'url' => route('departments.show', $department), 'path' => 'Department Overview']]
            : [];

        return ['content' => $content, 'links' => $links, 'source' => 'pulse', 'intent' => 'department_health'];
    }

    /** @return list<string> */
    private function typesIn(string $text): array
    {
        return array_values(array_keys(array_filter(
            self::TYPES,
            fn (array $type) => collect($type['keywords'])->contains(fn (string $keyword) => AiHelpLinks::mentions($text, $keyword)),
        )));
    }

    /**
     * @param  list<string>  $types
     * @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string}
     */
    private function myPendingAnswer(User $user, array $types): array
    {
        $pending = $this->tools->myPendingRequests($user);

        if ($pending === null) {
            return $this->noEmployeeRecord('my_pending');
        }

        $lines = collect($types)->map(fn (string $type) => $this->countPhrase($pending[$type], $type));

        $content = count($types) === 1
            ? 'You have '.$lines->first().' waiting for a decision.'
            : "Your requests waiting for a decision:\n• ".$lines->implode("\n• ");

        return $this->answerWith($content, $user, $types, 'my_pending', ownOnly: true);
    }

    /** @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string} */
    private function myOvertimeAnswer(User $user): array
    {
        $summary = $this->tools->myOvertime($user);

        if ($summary === null) {
            return $this->noEmployeeRecord('my_overtime');
        }

        $content = 'You have '.$this->countPhrase($summary['total'], 'overtime').' in total: '
            .$summary['pending'].' pending, '.$summary['approved'].' approved, '.$summary['rejected'].' rejected'
            .($summary['cancelled'] ? ', '.$summary['cancelled'].' cancelled' : '').'. '
            .'Payable overtime this month (approved, measured from punches): '.$this->hours($summary['approved_hours_month']).'.';

        return $this->answerWith($content, $user, ['overtime'], 'my_overtime', ownOnly: true);
    }

    /**
     * @param  list<string>  $types
     * @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string}
     */
    private function approvalQueueAnswer(User $user, array $types): array
    {
        $queue = $this->tools->approvalQueue($user);
        $asked = $types !== [] ? $types : array_keys($queue);

        $approvable = array_values(array_intersect($asked, array_keys($queue)));
        $notApprovable = array_values(array_diff($types, array_keys($queue)));

        if ($approvable === []) {
            $what = $types !== [] ? collect($types)->map(fn ($t) => self::TYPES[$t]['plural'])->implode(' or ') : 'requests';
            $content = "You don't approve {$what} in Pulse, so nothing is waiting for your approval.";

            // Answer what they most likely meant: their own pending requests.
            $pending = $this->tools->myPendingRequests($user);
            if ($pending !== null) {
                $ownTypes = $types !== [] ? $types : array_keys(self::TYPES);
                $own = collect($ownTypes)->map(fn (string $type) => $this->countPhrase($pending[$type], $type))->implode(', ');
                $content .= " Your own requests waiting for a decision: {$own}.";
            }

            return $this->answerWith($content, $user, $types !== [] ? $types : ['leave', 'overtime'], 'approval_queue', ownOnly: true);
        }

        $lines = collect($approvable)->map(fn (string $type) => $this->countPhrase($queue[$type], $type));

        $content = count($approvable) === 1
            ? $lines->first().' '.($queue[$approvable[0]] === 1 ? 'is' : 'are').' waiting for your approval.'
            : "Waiting for your approval:\n• ".$lines->implode("\n• ");

        if ($notApprovable !== []) {
            $content .= ' You do not approve '.collect($notApprovable)->map(fn ($t) => self::TYPES[$t]['plural'])->implode(' or ').'.';
        }

        $content .= ' Your own requests are not counted here.';

        return $this->answerWith(ucfirst($content), $user, $approvable, 'approval_queue', ownOnly: false);
    }

    /** @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string} */
    private function leaveBalanceAnswer(User $user): array
    {
        $leave = $this->tools->myLeave($user);

        if ($leave === null) {
            return $this->noEmployeeRecord('leave_balance');
        }

        $parts = array_filter([
            isset($leave['available_leave_csl_plus_comp_off']) ? 'Available leave: '.$this->days($leave['available_leave_csl_plus_comp_off']) : null,
            isset($leave['casual_sick_leave_available_to_request']) ? 'Casual/Sick Leave you can request: '.$this->days($leave['casual_sick_leave_available_to_request']) : null,
            isset($leave['comp_off']) ? 'Comp Off: '.$this->days($leave['comp_off']) : null,
        ]);

        $content = $parts === []
            ? 'Pulse has no leave balance on record for you yet.'
            : implode("\n", $parts);

        return $this->answerWith($content, $user, ['leave'], 'leave_balance', ownOnly: true);
    }

    /** @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string} */
    private function noEmployeeRecord(string $intent): array
    {
        return [
            'content' => 'Your account is not linked to an employee record, so you have no personal requests or balances in Pulse.',
            'links' => [],
            'source' => 'pulse',
            'intent' => $intent,
        ];
    }

    /**
     * @param  list<string>  $types
     * @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string}
     */
    private function answerWith(string $content, User $user, array $types, string $intent, bool $ownOnly): array
    {
        $topicFor = ['overtime' => 'overtime', 'leave' => 'leave', 'wfh' => 'wfh', 'regularisation' => 'attendance', 'holiday' => 'leave'];

        $links = collect($types)
            ->map(fn (string $type) => $topicFor[$type])
            ->unique()
            ->flatMap(function (string $topic) use ($user, $ownOnly) {
                $pages = $this->links->pagesFor($user, $topic);

                // The approver's own answer leads with the queue page.
                return $ownOnly ? array_slice($pages, 0, 1) : array_reverse($pages);
            })
            ->unique('url')
            ->take(3)
            ->values()
            ->all();

        if ($links !== []) {
            $content .= "\n\nYou can check it under: ".$links[0]['path'].'.';
        }

        return ['content' => $content, 'links' => $links, 'source' => 'pulse', 'intent' => $intent];
    }

    private function countPhrase(int $count, string $type): string
    {
        return $count.' '.($count === 1 ? self::TYPES[$type]['singular'] : self::TYPES[$type]['plural']);
    }

    private function hours(float $hours): string
    {
        $formatted = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');

        return $formatted.' '.($hours == 1.0 ? 'hour' : 'hours');
    }

    private function days(mixed $days): string
    {
        $formatted = rtrim(rtrim(number_format((float) $days, 2, '.', ''), '0'), '.');

        return $formatted.' '.((float) $days == 1.0 ? 'day' : 'days');
    }
}
