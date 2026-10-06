<?php

namespace App\Services\Help;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Profile\ProfileCompletionService;
use Laravel\Fortify\Features;

/**
 * The new-employee tutorial (/help/getting-started): signing in for the first
 * time, keeping your profile up to date and applying for leave, in order.
 *
 * Each step's "done" tick is read from the employee's real records (password
 * set, profile complete, a leave request raised …) and every button is shown
 * only when the reader can open its page — the same RouteAccess check the
 * Employee Guide uses. Password and expiry figures come from configuration,
 * so the tutorial cannot drift from what the forms enforce.
 */
class GettingStarted
{
    /** How long after joining the dashboard keeps offering this tutorial. */
    public const NEW_JOINER_DAYS = 30;

    public function __construct(
        private RouteAccess $routeAccess,
        private EmployeeGuide $guide,
        private ProfileCompletionService $profileCompletion,
    ) {}

    /**
     * The steps for this reader, in order.
     *
     * @return array<int, array{id: string, title: string, icon: string, intro: string, points: array<int, string>, tip: ?string, done: ?bool, status: ?string, links: array<int, array{label: string, url: string}>, tiers?: array<string, array<int, string>>}>
     */
    public function steps(User $user): array
    {
        $employee = $user->employee;
        $profile = $employee ? $this->profileCompletion->for($employee) : ['percent' => 100];
        $hasLeaveRequest = $employee && LeaveRequest::where('employee_id', $employee->id)->exists();
        $twoFactor = Features::canManageTwoFactorAuthentication();

        $steps = [
            [
                'id' => 'welcome-email',
                'title' => 'Open your welcome email',
                'icon' => 'envelope-open',
                'intro' => 'HR sends you an email titled "Welcome to '.config('app.name').' HRMS". It holds your work email address and a temporary password.',
                'points' => [
                    'Click "Accept invitation and sign in" (or "Login Now"), which opens the sign-in page.',
                    'The invitation link works for '.config('security.invitation_expiry_hours', 48).' hours. If it has expired, ask HR to send a new one.',
                    'Keep the temporary password private: it is only for your first sign-in.',
                ],
                'tip' => null,
                'done' => true,
                'status' => null,
                'links' => [],
            ],
            [
                'id' => 'sign-in',
                'title' => 'Sign in',
                'icon' => 'arrow-right-end-on-rectangle',
                'intro' => 'On "Log in to your account", enter your work Email Address and the temporary Password from the email.',
                'points' => [
                    'Tick Remember Me only on your own device.',
                    'You always sign in with your work email, not your employee code.',
                ],
                'tip' => 'Bookmark the sign-in page so you can find it again.',
                'done' => true,
                'status' => null,
                'links' => [],
            ],
            [
                'id' => 'set-password',
                'title' => 'Choose your own password',
                'icon' => 'key',
                'intro' => 'The first time you sign in, Pulse asks you to "Set your password" before you can go any further.',
                'points' => array_merge(
                    ['Type your new password in "New password" and again in "Confirm new password", then click Save & Continue.'],
                    $this->passwordRules(),
                    ['Changing your password signs you out on your other devices.'],
                ),
                'tip' => 'Use a password manager or a long phrase you can remember.',
                'done' => ! $user->must_change_password,
                'status' => $user->must_change_password ? 'Still using the temporary password' : 'Your own password is set',
                'links' => [],
            ],
            [
                'id' => 'forgot-password',
                'title' => 'If you forget your password',
                'icon' => 'question-mark-circle',
                'intro' => 'Click "Forgot Password" on the sign-in page and enter your work email. Pulse emails you a reset link.',
                'points' => [
                    'The reset link expires after '.config('auth.passwords.users.expire', 60).' minutes. Request a new one if it has expired.',
                    'Choose a new password that meets the same rules as above.',
                    'Still stuck? Ask HR to send you a new invitation.',
                ],
                'tip' => null,
                'done' => null,
                'status' => null,
                'links' => [],
            ],
        ];

        if ($twoFactor) {
            $steps[] = [
                'id' => 'two-factor',
                'title' => 'Protect your account (optional)',
                'icon' => 'shield-check',
                'intro' => 'Two-factor authentication asks for a code from your phone when you sign in, so a stolen password is not enough.',
                'points' => [
                    'Click your name at the top right → Account settings → Security, and confirm your password.',
                    'Click Enable 2FA, scan the QR code with an authenticator app, and enter the code it shows.',
                    'Save the recovery codes somewhere safe. They let you in if you lose your phone.',
                ],
                'tip' => null,
                'done' => $user->two_factor_confirmed_at !== null,
                'status' => $user->two_factor_confirmed_at !== null ? 'Two-factor is on' : 'Optional: not set up yet',
                'links' => $this->links($user, [['route' => 'security.edit', 'label' => 'Open Security settings']]),
            ];
        }

        $steps[] = [
            'id' => 'dashboard',
            'title' => 'Find your way from the dashboard',
            'icon' => 'home',
            'intro' => 'After signing in you land on My Dashboard, a one-page summary of your day.',
            'points' => [
                'Today\'s status has the Clock In / Clock Out button.',
                '"Tasks waiting for you" lists anything you need to do, such as completing your profile.',
                'Quick Actions jump straight to Apply Leave, My Payslips, Documents and Help Desk.',
                'The left sidebar lists every page you can use. Your name at the top right opens My Profile, Account settings and Help.',
            ],
            'tip' => 'Press Ctrl + K (⌘ K on a Mac) to search for any page.',
            'done' => null,
            'status' => null,
            'links' => $this->links($user, [['route' => 'dashboard', 'label' => 'Open Dashboard']]),
        ];

        $steps[] = [
            'id' => 'profile',
            'title' => 'Check and update your profile',
            'icon' => 'user-circle',
            'intro' => 'Click your name at the top right and choose My Profile. Check your details on the Overview, Personal and Employment tabs.',
            'points' => [
                'Fields you can edit save as soon as you change them.',
                'For fields that need HR approval, enter the new value and a reason, then click Send to HR. Your current value stays until HR approves.',
                'Follow your requests on the Requests tab. You can withdraw one while it is still pending, and HR\'s decision (with any comment) appears there.',
                'Fields HR manages, such as department, manager or work email, can only be changed by HR: ask them.',
            ],
            'tiers' => $this->guide->profileTiers(),
            'tip' => null,
            'done' => (int) ($profile['percent'] ?? 100) >= 100,
            'status' => 'Profile '.(int) ($profile['percent'] ?? 100).'% complete',
            'links' => $this->links($user, [['route' => 'profile.me', 'label' => 'Open My Profile']]),
        ];

        $steps[] = [
            'id' => 'apply-leave',
            'title' => 'Apply for leave',
            'icon' => 'calendar-days',
            'intro' => 'Open Leave in the sidebar (My Time Off), check "Available to request" on your balance cards, then click Apply Leave.',
            'points' => [
                'Leave Type: choose the kind of leave.',
                'Start Date and End Date: today or later. Leave cannot be backdated, and it cannot start or end on your weekly off or include a company holiday.',
                'Half-Day: tick it for First Half or Second Half (only for leave types that allow half days).',
                'Paid or Unpaid, then a Reason of at least 5 characters. Additional Remarks are optional.',
                'Attachment: PDF, JPG, PNG or WEBP up to 5 MB. Some leave types require one (marked with *).',
                'Click Submit. You will see "Leave request submitted successfully."',
            ],
            'tip' => 'Requests cannot overlap: cancel the old one first if your plans change.',
            'done' => $hasLeaveRequest,
            'status' => $hasLeaveRequest ? 'You have applied for leave' : 'No leave requests yet',
            'links' => $this->links($user, [['route' => 'time-off.my', 'label' => 'Open My Time Off']]),
        ];

        $steps[] = [
            'id' => 'track-leave',
            'title' => 'Track, answer and cancel leave requests',
            'icon' => 'clipboard-document-list',
            'intro' => 'Your requests appear on the Pending & Upcoming tab and under Leave Applications.',
            'points' => [
                'Pending: waiting for your manager. Pending HR: your manager approved and HR decides next. Then Approved or Rejected.',
                'More Info Needed: your reviewer has a question. Click Respond, answer (you can attach a file), and it goes back to them.',
                'Cancel: click Cancel on a Pending, Pending HR or Approved request. Cancelling approved paid leave returns the days to your balance.',
                'Transaction History and Month-wise Statement show every credit and deduction to your balance.',
            ],
            'tip' => null,
            'done' => null,
            'status' => null,
            'links' => $this->links($user, [['route' => 'time-off.my', 'label' => 'Open My Time Off']]),
        ];

        $steps[] = [
            'id' => 'attendance',
            'title' => 'Clock in, and fix a missed punch',
            'icon' => 'clock',
            'intro' => 'Clock in and out from the dashboard or from Attendance in the sidebar.',
            'points' => [
                'Forgot to clock out, or a punch is wrong? On My Attendance click Regularize, pick the day, enter the correct time and a reason, and submit.',
                'Regularisation requests go straight to HR. When HR approves, the day\'s hours are recalculated and you are notified.',
            ],
            'tip' => null,
            'done' => null,
            'status' => null,
            'links' => $this->links($user, [['route' => 'attendance.my', 'label' => 'Open My Attendance']]),
        ];

        $steps[] = [
            'id' => 'help',
            'title' => 'Getting help',
            'icon' => 'lifebuoy',
            'intro' => 'The Employee Guide explains every page in more detail, with screenshots and answers to common questions.',
            'points' => [
                'Open it any time from your name at the top right → Help & Employee Guide.',
                'For attendance and leave questions, ask your reporting manager (shown on your dashboard under My Team). For profile, payroll and policy questions, ask HR.',
            ],
            'tip' => null,
            'done' => null,
            'status' => null,
            'links' => $this->links($user, [['route' => 'help.employee-guide', 'label' => 'Open the Employee Guide']]),
        ];

        return $steps;
    }

    /**
     * Whether the dashboard should still offer the tutorial: a recent joiner
     * (by joining date, or by account creation when no date is recorded).
     */
    public function isNewJoiner(User $user): bool
    {
        $since = now()->subDays(self::NEW_JOINER_DAYS)->startOfDay();
        $joined = $user->employee?->joining_date;

        return $joined !== null ? $joined->gte($since) : ($user->created_at?->gte($since) ?? false);
    }

    /**
     * The password rules the forms enforce in this environment.
     *
     * @return array<int, string>
     */
    private function passwordRules(): array
    {
        $history = (int) config('security.password_history_limit', 5);

        $rules = app()->isProduction()
            ? ['Use at least 12 characters, with upper- and lower-case letters, a number and a symbol.', 'Passwords found in known data breaches are refused.']
            : ['Use at least 8 characters.'];

        $rules[] = 'You cannot reuse your temporary password'.($history > 0 ? " or any of your last {$history} passwords." : '.');

        return $rules;
    }

    /**
     * @param  array<int, array{route: string, label: string}>  $links
     * @return array<int, array{label: string, url: string}>
     */
    private function links(User $user, array $links): array
    {
        return collect($links)
            ->filter(fn (array $link): bool => $this->routeAccess->allows($user, $link['route']))
            ->map(fn (array $link): array => ['label' => $link['label'], 'url' => route($link['route'])])
            ->values()
            ->all();
    }
}
