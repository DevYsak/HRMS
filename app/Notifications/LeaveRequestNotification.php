<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Help\RouteAccess;
use App\Services\Leave\LeaveRequestPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class LeaveRequestNotification extends Notification implements ShouldQueue
{
    use NotifiesByRole;
    use Queueable, SerializesModels;
    use SendsMailChannel {
        toMail as private standardMail;
    }

    /** To the employee on submission, when part of the request is unpaid (not enough Earned Leave). */
    public const SUBMITTED = 'submitted';

    /**
     * The status when the event happened. The notification is queued: read
     * at send time, a request decided before the worker ran told approvers
     * it was "approved" and the employee twice (Phase 4).
     */
    public readonly string $statusAtDispatch;

    /**
     * @param  string|null  $event  'message' for a message-thread post — it never re-announces the decision;
     *                              SUBMITTED for the employee's own notice that some days are unpaid
     */
    public function __construct(public readonly LeaveRequest $leaveRequest, public readonly ?string $event = null)
    {
        $this->statusAtDispatch = (string) $leaveRequest->status;
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->leaveRequest->employee->user->name;
        $status = match ($this->event) {
            'message' => 'message',
            self::SUBMITTED => self::SUBMITTED,
            default => $this->statusAtDispatch,
        };
        // Someone HR chose to keep informed of the decision: told about the employee, not addressed as them.
        $observer = $this->role === 'recipient';
        $type = $this->leaveRequest->leaveType->name;
        $days = $this->leaveRequest->days;

        // For manager/HR (pending) we open the Team page and pass a query param
        // that the Livewire component will use to open the review modal.
        // Only for someone who may decide it: a team lead kept informed without
        // approve_leave (or outside its reach) got a Team Leave link that 403'd.
        // A non-user notifiable (an on-demand mail route) has no permissions to check.
        $canReview = ! $notifiable instanceof User
            || (app(RouteAccess::class)->allows($notifiable, 'time-off.team')
                && app(ApprovalGuard::class)->covers($notifiable, $this->leaveRequest->employee, 'approve_leave'));
        $teamUrl = $canReview
            ? route('time-off.team').'?selectedRequestId='.$this->leaveRequest->id
            : route('dashboard');
        // For employees we anchor to the specific row in My Time Off.
        $myUrl = route('time-off.my').'#request-'.$this->leaveRequest->id;

        $paid = LeaveRequestPresenter::paid($this->leaveRequest) + 0;
        $unpaid = LeaveRequestPresenter::unpaid($this->leaveRequest) + 0;
        $by = $this->leaveRequest->reviewer?->name;

        $data = match ($status) {
            self::SUBMITTED => [
                'type' => 'leave_request',
                'title' => $paid > 0 ? 'Leave submitted — part of it is unpaid' : 'Leave submitted — unpaid',
                'body' => $paid > 0
                    ? "Your {$type} request for {$days} day(s) was submitted: {$paid} day(s) paid from your Earned Leave and {$unpaid} day(s) unpaid (Leave Without Pay), because only {$paid} day(s) of Earned Leave were available."
                    : "Your {$type} request for {$days} day(s) was submitted as unpaid (Leave Without Pay): you have no Earned Leave available for these dates.",
                'action' => 'View',
                'url' => $myUrl,
                'icon' => 'exclamation-triangle',
                'color' => 'amber',
            ],
            'approved' => $observer ? [
                'type' => 'leave_request',
                'title' => "Leave Approved — {$employee}",
                'body' => "{$employee}'s {$type} request for {$days} day(s) was approved".($by ? " by {$by}" : '')." ({$paid} paid, {$unpaid} unpaid).",
                'action' => 'View',
                'url' => $teamUrl,
                'icon' => 'check-circle',
                'color' => 'green',
            ] : [
                'type' => 'leave_request',
                'title' => 'Leave Approved ✓',
                'body' => "Your {$type} request for {$days} day(s) has been approved.",
                'action' => 'View',
                'url' => $myUrl,
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            'rejected' => $observer ? [
                'type' => 'leave_request',
                'title' => "Leave Rejected — {$employee}",
                'body' => "{$employee}'s {$type} request for {$days} day(s) was rejected".($by ? " by {$by}" : '').'.',
                'action' => 'View',
                'url' => $teamUrl,
                'icon' => 'x-circle',
                'color' => 'red',
            ] : [
                'type' => 'leave_request',
                'title' => 'Leave Rejected',
                'body' => "Your {$type} request for {$days} day(s) was rejected.",
                'action' => 'View',
                'url' => $myUrl,
                'icon' => 'x-circle',
                'color' => 'red',
            ],
            'pending' => [
                'type' => 'leave_request',
                'title' => 'New Leave Request',
                'body' => "{$employee} requested {$days} day(s) of {$type}.",
                'action' => 'Review',
                'url' => $teamUrl,
                'icon' => 'calendar',
                'color' => 'blue',
            ],
            'cancelled' => $this->role === 'employee' || $this->role === null
                ? [
                    'type' => 'leave_request',
                    'title' => 'Leave Cancelled',
                    'body' => "Your {$type} request for {$days} day(s) has been cancelled.",
                    'action' => 'View',
                    'url' => $myUrl,
                    'icon' => 'x-circle',
                    'color' => 'orange',
                ]
                : [
                    'type' => 'leave_request',
                    'title' => 'Leave Cancelled',
                    'body' => "{$employee} cancelled their {$type} request for {$days} day(s).",
                    'action' => 'View',
                    'url' => $teamUrl,
                    'icon' => 'x-circle',
                    'color' => 'orange',
                ],
            'more_info_requested' => [
                'type' => 'leave_request',
                'title' => 'More Information Needed',
                'body' => "Your {$type} request for {$days} day(s) needs more information before it can be reviewed.",
                'action' => 'Respond',
                'url' => $myUrl,
                'icon' => 'question-mark-circle',
                'color' => 'amber',
            ],
            // Requests created before D2 can still sit with HR.
            'pending_hr' => $this->role === 'employee'
                ? [
                    'type' => 'leave_request',
                    'title' => 'Leave Awaiting HR',
                    'body' => "Your {$type} request for {$days} day(s) is with HR for a final decision.",
                    'action' => 'View',
                    'url' => $myUrl,
                    'icon' => 'clock',
                    'color' => 'amber',
                ]
                : [
                    'type' => 'leave_request',
                    'title' => 'Leave Awaiting HR Decision',
                    'body' => "{$employee}'s {$type} request for {$days} day(s) is waiting for your decision.",
                    'action' => 'Review',
                    'url' => $teamUrl,
                    'icon' => 'calendar',
                    'color' => 'amber',
                ],
            'message' => [
                'type' => 'leave_request',
                'title' => 'New Message on a Leave Request',
                'body' => "There is a new message on {$employee}'s {$type} request ({$days} day(s)).",
                'action' => 'Open',
                'url' => $this->role === 'employee' ? $myUrl : $teamUrl,
                'icon' => 'chat-bubble-left-right',
                'color' => 'blue',
            ],
            // Never fall through to a rejection: an unknown state says only
            // that the request changed.
            default => [
                'type' => 'leave_request',
                'title' => 'Leave Request Updated',
                'body' => "Your {$type} request for {$days} day(s) was updated.",
                'action' => 'View',
                'url' => $myUrl,
                'icon' => 'calendar',
                'color' => 'blue',
            ],
        };

        // Every copy names its request, so the request page can list what was sent.
        return $data + ['leave_request_id' => $this->leaveRequest->id];
    }

    /**
     * The standard email with the request's facts under it: who, which leave,
     * the dates and length, the Earned Leave available when it was applied
     * for, the paid / unpaid split and — once decided — the decision and the
     * remarks. An admin's custom subject / body (Notifications & Email) still
     * replaces the opening text.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->standardMail($notifiable);
        $request = $this->leaveRequest->loadMissing(['employee.user', 'leaveType', 'reviewer']);
        $n = fn ($v) => (string) (round((float) $v, 2) + 0);
        $snapshot = $request->balance_snapshot ?? [];
        $decided = $this->event === null && in_array($this->statusAtDispatch, ['approved', 'rejected'], true);
        $dates = $request->start_date->format('d M Y').($request->end_date->ne($request->start_date) ? ' – '.$request->end_date->format('d M Y') : '');

        $details = array_values(array_filter([
            '**Employee:** '.($request->employee?->user?->name ?? 'Employee').($request->employee?->employee_id ? ' ('.$request->employee->employee_id.')' : ''),
            '**Leave:** '.($request->leaveType?->name ?? 'Leave'),
            '**Dates:** '.$dates,
            '**Duration:** '.$n($request->days).' day(s)'.($request->is_half_day ? ' (half day)' : ''),
            isset($snapshot['available_for_request'])
                ? '**'.($snapshot['balance_type'] ?? 'Earned Leave').' available when applied:** '.$n($snapshot['available_for_request']).' day(s)'
                : null,
            '**Paid / unpaid:** '.$n(LeaveRequestPresenter::paid($request)).' paid · '.$n(LeaveRequestPresenter::unpaid($request)).' unpaid (Leave Without Pay)',
            $decided ? '**Decision:** '.ucfirst($this->statusAtDispatch).($request->reviewer ? ' by '.$request->reviewer->name : '') : null,
            $decided && $request->reviewer_comment ? '**Remarks:** '.$request->reviewer_comment : null,
        ]));
        $message->introLines = array_merge($message->introLines, $details);

        return $message->withSymfonyMessage(function ($symfony): void {
            $symfony->getHeaders()->addTextHeader('X-Notification-Context', 'leave_request:'.$this->leaveRequest->id);
        });
    }

    /**
     * What this notification is about, for the email log.
     *
     * @return array{type: string, id: int}
     */
    public function mailContext(): array
    {
        return ['type' => 'leave_request', 'id' => (int) $this->leaveRequest->id];
    }
}
