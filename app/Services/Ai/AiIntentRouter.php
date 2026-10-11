<?php

namespace App\Services\Ai;

use App\Models\User;

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
            .'Approved overtime this month: '.$this->hours($summary['approved_hours_month']).'.';

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
        $topicFor = ['overtime' => 'overtime', 'leave' => 'leave', 'wfh' => 'wfh', 'regularisation' => 'attendance'];

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
