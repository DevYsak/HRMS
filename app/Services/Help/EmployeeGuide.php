<?php

namespace App\Services\Help;

use App\Models\User;
use App\Services\Profile\ProfileFieldRegistry;
use Illuminate\Support\Facades\File;

/**
 * Content and screenshot manifest for the Employee Guide (/help/employee-guide).
 *
 * Sections describe only what an Employee-role account can reach today. The
 * screenshots and their numbered markers come from the Playwright capture
 * (tests/Playwright/employee-guide.spec.js), which writes manifest.json next to
 * the images — so a marker legend can never drift from the picture it labels.
 *
 * Deep links and the "can / cannot" table are resolved per reader through
 * RouteAccess and the reader's real permissions, never hard-coded.
 */
class EmployeeGuide
{
    public const ASSET_DIR = 'images/employee-guide';

    public function __construct(private RouteAccess $routeAccess) {}

    /**
     * Screenshot metadata keyed by shot id, as written by the capture script.
     *
     * @return array<string, array{file: string, annotated: string, title: string, markers: array<int, array{label: string, note?: string}>}>
     */
    public function shots(): array
    {
        $path = public_path(self::ASSET_DIR.'/manifest.json');

        if (! File::exists($path)) {
            return [];
        }

        $manifest = json_decode(File::get($path), true);

        return is_array($manifest['shots'] ?? null) ? $manifest['shots'] : [];
    }

    public function capturedAt(): ?string
    {
        $path = public_path(self::ASSET_DIR.'/manifest.json');

        return File::exists($path) ? (json_decode(File::get($path), true)['captured_at'] ?? null) : null;
    }

    /**
     * Guide sections for this reader, with deep links filtered to the routes
     * the reader can open.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sections(User $user): array
    {
        return collect($this->content())
            ->map(function (array $section) use ($user): array {
                $section['links'] = collect($section['links'] ?? [])
                    ->filter(fn (array $link): bool => $this->routeAccess->allows($user, $link['route']))
                    ->map(fn (array $link): array => $link + ['url' => route($link['route'])])
                    ->values()
                    ->all();

                return $section;
            })
            ->all();
    }

    /**
     * Profile fields grouped by who may change them, straight from the registry
     * the My Profile page itself enforces.
     *
     * @return array<string, array<int, string>>
     */
    public function profileTiers(): array
    {
        $tiers = [
            ProfileFieldRegistry::TIER_EDITABLE => [],
            ProfileFieldRegistry::TIER_APPROVAL => [],
            ProfileFieldRegistry::TIER_LOCKED => [],
        ];

        foreach (ProfileFieldRegistry::FIELDS as $key => $field) {
            $tiers[$field['tier']][] = $field['label'] ?? $key;
        }

        return $tiers;
    }

    /**
     * What this reader can and cannot do, worked out from route guards and
     * permissions at request time.
     *
     * @return array<int, array{feature: string, can: string, allowed: bool}>
     */
    public function capabilities(User $user): array
    {
        $routes = fn (string ...$names): bool => collect($names)->every(fn (string $name): bool => $this->routeAccess->allows($user, $name));

        return [
            ['feature' => 'My profile', 'can' => 'View; edit some fields directly; request HR approval for others', 'allowed' => $routes('profile.me') && $user->hasPermission('request_profile_change')],
            ['feature' => 'Attendance', 'can' => 'Clock in/out, take breaks, view history, request a correction', 'allowed' => $routes('attendance.my')],
            ['feature' => 'Leave', 'can' => 'View balances, apply, track and cancel requests', 'allowed' => $routes('time-off.my') && $user->hasPermission('apply_leave')],
            ['feature' => 'Work from home', 'can' => 'Request WFH days and track them', 'allowed' => $routes('wfh.my')],
            ['feature' => 'Overtime', 'can' => 'Request overtime pre-approval and track it', 'allowed' => $routes('overtime.my')],
            ['feature' => 'Payslips', 'can' => 'View, download and email your own payslips', 'allowed' => $routes('payroll.payslips') && $user->hasPermission('view_payslips')],
            ['feature' => 'Expense claims', 'can' => 'Submit claims with receipts and track them', 'allowed' => $routes('operations.expenses')],
            ['feature' => 'Performance & goals', 'can' => 'Complete your self-review, manage your own goals, view KPIs', 'allowed' => $routes('performance.my', 'performance.goals') && $user->hasPermission('view_performance')],
            ['feature' => 'Documents', 'can' => 'View and acknowledge documents shared with you; upload your own', 'allowed' => $routes('documents.index') && $user->hasPermission('view_documents')],
            ['feature' => 'Onboarding', 'can' => 'Tick off the onboarding tasks assigned to you', 'allowed' => $routes('onboarding.my')],
            ['feature' => 'Directory & org chart', 'can' => 'Look up colleagues and reporting lines', 'allowed' => $routes('employees.directory') && $user->hasPermission('view_directory')],
            ['feature' => 'Approve other people\'s leave', 'can' => 'Managers and HR only', 'allowed' => $user->hasPermission('approve_leave')],
            ['feature' => 'Other employees\' records', 'can' => 'HR only', 'allowed' => $user->hasPermission('manage_employees')],
            ['feature' => 'Company assets register', 'can' => 'HR only', 'allowed' => $routes('operations.assets')],
            ['feature' => 'Payroll administration', 'can' => 'Payroll and Finance only', 'allowed' => $user->hasPermission('run_payroll')],
            ['feature' => 'Reports', 'can' => 'HR and leadership only', 'allowed' => $user->hasPermission('view_reports')],
            ['feature' => 'Roles & permissions', 'can' => 'Administrators only', 'allowed' => $user->hasPermission('manage_roles')],
            ['feature' => 'Company settings', 'can' => 'Administrators only', 'allowed' => $user->hasPermission('manage_settings')],
        ];
    }

    /**
     * Where each kind of request is tracked, limited to pages this reader can open.
     *
     * @return array<int, array{type: string, where: string, statuses: string, url: string}>
     */
    public function requestTypes(User $user): array
    {
        return collect([
            ['type' => 'Leave', 'route' => 'time-off.my', 'where' => 'Leave → Pending & Upcoming tab, or Leave Applications', 'statuses' => 'Pending, Pending HR, More Info Needed, Approved, Rejected, Cancelled'],
            ['type' => 'Leave encashment', 'route' => 'time-off.my', 'where' => 'Leave → Encashment History tab', 'statuses' => 'Pending, Pending Finance, Approved, Rejected, Processed'],
            ['type' => 'Work on a holiday', 'route' => 'time-off.my', 'where' => 'Leave → Holiday Work card', 'statuses' => 'Pending, Approved, Rejected'],
            ['type' => 'Attendance correction', 'route' => 'attendance.my', 'where' => 'Attendance → click the day in the timeline', 'statuses' => 'Pending (Manager → HR → Admin), Approved, Rejected'],
            ['type' => 'Work from home', 'route' => 'wfh.my', 'where' => 'Work From Home → WFH Request History', 'statuses' => 'Pending, Approved, Rejected, Cancelled'],
            ['type' => 'Overtime', 'route' => 'overtime.my', 'where' => 'Overtime → OT Request History', 'statuses' => 'Pending, Approved, Rejected, Cancelled'],
            ['type' => 'Expense claim', 'route' => 'operations.expenses', 'where' => 'Payroll → Expense Claims', 'statuses' => 'Pending, Approved, Rejected'],
            ['type' => 'Profile change', 'route' => 'profile.me', 'where' => 'My Profile → Requests tab', 'statuses' => 'Pending, Approved, Rejected, Withdrawn'],
        ])
            ->filter(fn (array $row): bool => $this->routeAccess->allows($user, $row['route']))
            ->map(fn (array $row): array => ['type' => $row['type'], 'where' => $row['where'], 'statuses' => $row['statuses'], 'url' => route($row['route'])])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{q: string, a: string}>
     */
    public function faqs(): array
    {
        return [
            ['q' => 'Why is my available leave lower than my entitlement?', 'a' => 'Used, pending, expired and encashed leave all reduce what you can request. Pending requests are held back until they are decided. Open the leave type\'s card under My Balances to see each part, or its Transaction History for every movement.'],
            ['q' => 'Where can I check a pending leave request?', 'a' => 'Open Leave and choose the Pending & Upcoming tab. The Current Stage column shows who has it now. Leave Applications further down also lists every request with its reviewer.'],
            ['q' => 'Will my carried-forward leave expire?', 'a' => 'It can, depending on your policy. An amber alert at the top of My Time Off shows any days about to expire and the date; the Transaction History shows the expiry date on each carry-forward credit.'],
            ['q' => 'My attendance is wrong. What should I do?', 'a' => 'Open Attendance, click Regularize (or the fix link on the flagged day), choose the punch to correct, enter the right time and a reason, and submit. It goes to your manager, then HR, then an administrator.'],
            ['q' => 'I forgot to clock out. Will I lose the day?', 'a' => 'The day is flagged as a missing check-out. Raise a regularisation request for the OUT punch; once approved, your hours are recalculated.'],
            ['q' => 'Where can I download my payslip?', 'a' => 'Payroll → My Payslips. Click Download PDF for the current month, or the download icon next to any month in Payslip History.'],
            ['q' => 'How do I change my profile details?', 'a' => 'Open My Profile from your name at the top right. Phone, emergency contact and photo save immediately. Name, date of birth, address, bank details, PAN and Aadhaar are sent to HR for approval. Department, manager, job title and work email can only be changed by HR.'],
            ['q' => 'Can I cancel leave after it is approved?', 'a' => 'Yes. Click Cancel on the request in Leave Applications. Cancelling approved paid leave returns the days to your balance.'],
            ['q' => 'My reviewer asked for more information on my leave. What now?', 'a' => 'The request shows More Info Needed. Click Respond on it, answer the question (you can attach a file), and it goes back to your reviewer.'],
            ['q' => 'I can\'t see my performance review.', 'a' => 'Reviews appear under Performance → My Review only after HR opens a review cycle that includes you.'],
            ['q' => 'Who do I contact if something looks wrong?', 'a' => 'Your reporting manager (shown on your dashboard under My Team) for attendance and leave, or HR for profile, payroll and policy questions.'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function content(): array
    {
        return [
            [
                'id' => 'getting-started',
                'category' => 'Getting Started',
                'title' => 'Logging in and your dashboard',
                'icon' => 'home',
                'summary' => 'Sign in with your work email and the password from your invitation. You land on your dashboard: a one-page summary of your day, your leave, your pay and anything waiting for you.',
                'shots' => ['dashboard-top', 'dashboard-widgets'],
                'steps' => [
                    'Open Pulse and sign in with your work email and password.',
                    'Your dashboard opens. The banner shows today\'s shift and a Clock In button.',
                    'The cards below show today\'s status, attendance this month, leave left, late marks, overtime, pending requests and your latest salary.',
                    'Scroll down for your leave summary, latest payslip, quick actions, announcements and your reporting manager.',
                ],
                'can' => [
                    'Clock in straight from the dashboard.',
                    'Jump to Apply Leave, Log Overtime, My Payslips, Documents, Performance or WFH Request with the quick actions.',
                    'Open your profile and onboarding from the "Complete your profile" and "Finish your onboarding" prompts.',
                ],
                'next' => [
                    'Every card links to the full page for that topic, for example Leave Summary opens My Time Off.',
                ],
                'tips' => [
                    ['type' => 'tip', 'text' => 'Press Ctrl + K (⌘ K on Mac), or click the search bar at the top, to jump to any page you have access to.'],
                ],
                'links' => [
                    ['route' => 'dashboard', 'label' => 'Open Dashboard'],
                ],
                'keywords' => 'login sign in password dashboard home cards quick actions announcements search',
            ],
            [
                'id' => 'navigation',
                'category' => 'Getting Started',
                'title' => 'Finding your way around',
                'icon' => 'bars-3',
                'summary' => 'Everything you can use is in the left sidebar. Your name and photo at the top right open your personal menu.',
                'shots' => ['avatar-menu', 'mobile-dashboard'],
                'steps' => [
                    'Use the sidebar to switch between Dashboard, Attendance, Leave, Work From Home, Overtime, Performance, Development, Payroll, My Onboarding, Documents and Inbox.',
                    'Performance, Development and Payroll are groups: click one to expand its pages.',
                    'Click your name at the top right for My Profile, Account settings, Preferences, this guide, and Log out.',
                    'On a phone, tap the menu icon at the top left to open the sidebar.',
                ],
                'can' => [
                    'Switch between light and dark mode with the moon icon in the top bar.',
                    'Set your timezone and date/time format under Preferences.',
                    'Change your password and set up two-factor authentication under Account settings → Security.',
                ],
                'next' => [],
                'tips' => [
                    ['type' => 'info', 'text' => 'Numbered badges in the sidebar count things waiting for you: pending leave, pending overtime, documents to acknowledge and unread notifications.'],
                ],
                'links' => [
                    ['route' => 'settings.preferences', 'label' => 'Open Preferences'],
                    ['route' => 'security.edit', 'label' => 'Open Security settings'],
                ],
                'keywords' => 'sidebar menu navigation avatar profile menu logout dark mode password two factor 2fa preferences timezone mobile phone',
            ],
            [
                'id' => 'profile',
                'category' => 'Profile',
                'title' => 'My Profile',
                'icon' => 'user-circle',
                'summary' => 'Your profile holds your personal, contact and employment details. Some fields you can change yourself; others need HR approval; the rest only HR can change.',
                'shots' => ['profile-overview', 'profile-request', 'profile-requests'],
                'steps' => [
                    'Click your name at the top right and choose My Profile.',
                    'The header shows your employee ID, reporting manager, joining date, shift and how complete your profile is.',
                    'Use the Overview, Personal, Employment and Requests tabs to see each group of details.',
                    'To fill in or change a field, click it under "Finish your profile" or next to the field.',
                    'Fields you can edit save straight away. For approval fields, enter the new value, add a reason, and click Send to HR.',
                    'Track your request on the Requests tab. You can withdraw it while it is still pending.',
                ],
                'profile_tiers' => true,
                'can' => [
                    'Upload a profile photo and update your phone number and emergency contact yourself.',
                    'Request changes to your name, date of birth, gender, address, bank details, PAN and Aadhaar.',
                    'Withdraw a pending change request.',
                ],
                'next' => [
                    'HR reviews the request. Your current value stays in place until it is approved.',
                    'If approved, the new value replaces the old one. If rejected, the old value stays and the reviewer\'s comment is shown on the Requests tab.',
                    'You get a notification either way.',
                ],
                'tips' => [
                    ['type' => 'warning', 'text' => 'Your work email is also your login. To change it, contact HR or IT.'],
                ],
                'links' => [
                    ['route' => 'profile.me', 'label' => 'Open My Profile'],
                ],
                'keywords' => 'profile personal details phone address bank account ifsc pan aadhaar emergency contact photo request change approval hr edit department manager job title joining date',
            ],
            [
                'id' => 'attendance',
                'category' => 'Attendance',
                'title' => 'My Attendance: clocking in, breaks and history',
                'icon' => 'clock',
                'summary' => 'My Attendance shows today\'s punches, your attendance score and your history. If your device punches are recorded by the biometric machine, they appear here automatically.',
                'shots' => ['attendance-top', 'attendance-timeline'],
                'steps' => [
                    'Open Attendance in the sidebar.',
                    'Click Clock in to start your day. Web clock-in takes a selfie and your location.',
                    'Use Start break and End break around your breaks. Your break allowance is shown on the Total Break card.',
                    'Click Clock out at the end of the day.',
                    'Pick Today, Week, Month, Quarter or Year at the top to change the period for the score, charts and history.',
                    'Scroll to the Punch In / Out Timeline for a day-by-day record. Click a day to see its punches.',
                ],
                'can' => [
                    'See first in, last out, worked hours, breaks and late marks for each day.',
                    'Export your attendance log.',
                    'See biometric device punches alongside web punches.',
                ],
                'next' => [
                    'A late arrival or missing check-out is flagged on the day and counts towards your attendance score.',
                ],
                'tips' => [
                    ['type' => 'warning', 'text' => 'Forgot to clock out? The day is flagged as a missing check-out. Raise a regularisation request (next section) to fix it.'],
                ],
                'links' => [
                    ['route' => 'attendance.my', 'label' => 'Open My Attendance'],
                ],
                'keywords' => 'attendance clock in clock out check in check out punch break late mark missing checkout working hours biometric history monthly score selfie location',
            ],
            [
                'id' => 'regularisation',
                'category' => 'Attendance',
                'title' => 'Correcting your attendance (regularisation)',
                'icon' => 'pencil-square',
                'summary' => 'If a punch is missing or wrong, ask for a correction. The raw device record is never changed; the correction applies only after final approval.',
                'shots' => ['attendance-regularise'],
                'steps' => [
                    'On My Attendance, click Regularize, or the fix link next to a flagged day.',
                    'Choose the day.',
                    'Choose what to fix: a punch, or mark the day as a half day.',
                    'For a punch, tick IN and/or OUT and enter the correct time.',
                    'Explain why and attach proof if you have it (gate pass, screenshot, medical slip; JPG, PNG or PDF up to 5 MB).',
                    'Submit.',
                ],
                'can' => [
                    'Fix a missing or wrong IN or OUT punch.',
                    'Ask for a day to be marked as a half day.',
                ],
                'next' => [
                    'The request goes to your manager, then HR, then an administrator.',
                    'Once approved, your hours for that day are recalculated.',
                    'You can follow each approval step on the day\'s detail in My Attendance, and you are notified of the outcome.',
                ],
                'statuses' => ['Pending', 'Approved', 'Rejected'],
                'tips' => [],
                'links' => [
                    ['route' => 'attendance.my', 'label' => 'Open My Attendance'],
                ],
                'keywords' => 'attendance correction regularisation regularization regularize fix punch missing punch wrong time half day forgot clock out request correction approval',
            ],
            [
                'id' => 'leave',
                'category' => 'Leave',
                'title' => 'Your leave balance',
                'icon' => 'calendar-days',
                'summary' => 'My Time Off shows, for every leave type, exactly how your balance is made up, what you can still request, every credit and deduction, and a month-by-month statement. The leave year runs 1 July to 30 June.',
                'shots' => ['leave-top', 'leave-balances', 'leave-history', 'leave-statement', 'leave-calendar'],
                'steps' => [
                    'Open Leave in the sidebar.',
                    'The top strip shows your available leave, pending requests, days approved this year and the next holiday.',
                    'Below it, My Balances shows one card per leave type. The big number is Available to request; next to it is your Approved balance.',
                    'Each card lists what makes up the balance: base entitlement, carry forward, add-on, accrued, adjustments, used, pending, expired and encashed. Only the parts that apply to you are shown.',
                    'Use the Leave year menu to look at a previous year.',
                    'Open Transaction History to see every credit and deduction, filtered by leave type and month. Open Month-wise Statement for opening and closing balances month by month.',
                    'If some of your leave is about to expire, an amber alert at the top tells you how many days and the date.',
                ],
                'glossary' => [
                    ['term' => 'Base Entitlement', 'meaning' => 'The leave your policy gives you for this leave year.'],
                    ['term' => 'Carry Forward', 'meaning' => 'Eligible unused leave moved over from the previous year. It may have an expiry date.'],
                    ['term' => 'Add-On', 'meaning' => 'Extra leave granted to you by HR.'],
                    ['term' => 'Accrued', 'meaning' => 'Leave you earn as the year goes on, for leave types that build up monthly.'],
                    ['term' => 'Adjustments', 'meaning' => 'Corrections HR made to your balance, either added (+) or removed (−).'],
                    ['term' => 'Opening Balance', 'meaning' => 'The balance you started the year with when it was brought over from an earlier record.'],
                    ['term' => 'Used', 'meaning' => 'Approved leave already taken or booked.'],
                    ['term' => 'Pending', 'meaning' => 'Leave in requests still waiting for approval. It is held back from what you can request, but not deducted until approved.'],
                    ['term' => 'Expired', 'meaning' => 'Leave that lapsed because it was not used before its expiry date.'],
                    ['term' => 'Encashed', 'meaning' => 'Leave paid out as money instead of taken as time off.'],
                    ['term' => 'Approved Balance', 'meaning' => 'Everything credited to you, minus leave used, encashed, expired or removed by adjustment.'],
                    ['term' => 'Available to Request', 'meaning' => 'Your approved balance minus pending requests: what you can apply for right now.'],
                ],
                'can' => [
                    'Check every leave type bucket by bucket, for this year or a previous one.',
                    'Start a request, or open a type\'s history or statement, straight from its card.',
                    'Use the Leave Planner to see how many working days a date range would use before applying.',
                    'Read your leave rules in the Policy Explorer, and request to work on a holiday from the Holiday Work card.',
                ],
                'next' => [],
                'tips' => [
                    ['type' => 'warning', 'text' => 'Carried-forward leave can expire. When an expiry alert appears, book those days before the date shown or they lapse.'],
                    ['type' => 'info', 'text' => 'On the calendar, approved leave, pending leave and holidays each have their own colour. Click a working day to start a leave request for it.'],
                ],
                'links' => [
                    ['route' => 'time-off.my', 'label' => 'Open My Leave'],
                ],
                'keywords' => 'leave time off balance available to request approved balance base entitlement add-on add on accrued adjustment opening used pending expired expiry carry forward encashment encashed transaction history statement month-wise leave year holiday calendar planner policy sick annual casual',
            ],
            [
                'id' => 'apply-leave',
                'category' => 'Leave',
                'title' => 'Applying for leave and tracking it',
                'icon' => 'paper-airplane',
                'summary' => 'Apply from My Time Off. Your manager reviews the request first; some requests then go to HR.',
                'shots' => ['leave-apply', 'leave-pending-upcoming', 'leave-applications'],
                'steps' => [
                    'On My Time Off, click Apply Leave.',
                    'Choose the leave type. Your available balance for it appears underneath.',
                    'Pick the start and end dates.',
                    'For a half day, tick Half day and choose the first or second half.',
                    'Choose Paid or Unpaid if the leave type offers both.',
                    'Give a reason and attach a document if needed (for example a medical certificate).',
                    'Submit. The request appears in Leave Applications as Pending.',
                    'To see where it is now, open the Pending & Upcoming tab: the Current Stage column says who has it (awaiting manager, awaiting HR, or more information requested).',
                ],
                'statuses' => ['Pending', 'Pending HR', 'More Info Needed', 'Approved', 'Rejected', 'Cancelled'],
                'status_notes' => [
                    'Pending' => 'Waiting for your manager.',
                    'Pending HR' => 'Your manager approved it; HR is reviewing it now.',
                    'More Info Needed' => 'The reviewer asked a question. Click Respond on the request and reply; it then goes back to Pending.',
                    'Approved' => 'Your leave is booked and deducted from your balance.',
                    'Rejected' => 'Not approved. The reviewer\'s comment explains why.',
                    'Cancelled' => 'You withdrew the request.',
                ],
                'can' => [
                    'Cancel a request that is pending, with HR, or already approved.',
                    'Reply to the reviewer with the Respond button when they ask for more information.',
                    'Filter your applications by status, leave type or year, and see your Encashment History.',
                ],
                'next' => [
                    'You are notified at each step: approved, rejected or more information needed.',
                ],
                'tips' => [
                    ['type' => 'warning', 'text' => 'Weekends or holidays between leave days may count as leave under the sandwich rule of your policy. The days figure on the request shows exactly what was counted.'],
                ],
                'links' => [
                    ['route' => 'time-off.my', 'label' => 'Apply for leave'],
                ],
                'keywords' => 'apply leave request time off half day reason attachment medical certificate submit track status pending approved rejected cancel cancellation more info needed manager hr approval sandwich',
            ],
            [
                'id' => 'pending-requests',
                'category' => 'Leave',
                'title' => 'Where to see your pending requests',
                'icon' => 'inbox-stack',
                'summary' => 'Each type of request is tracked on its own page, and the dashboard\'s Pending card counts them all.',
                'shots' => [],
                'request_table' => true,
                'steps' => [
                    'Glance at the Pending card on your dashboard and the badges in the sidebar.',
                    'Open the page for the request type (see the table) to see its status and the reviewer\'s comments.',
                    'Check your Inbox: every decision on your requests is sent there as a notification.',
                ],
                'can' => [],
                'next' => [],
                'tips' => [],
                'links' => [],
                'keywords' => 'pending requests status approvals waiting track leave attendance wfh overtime expense profile change encashment',
            ],
            [
                'id' => 'wfh',
                'category' => 'WFH & Overtime',
                'title' => 'Work From Home requests',
                'icon' => 'home-modern',
                'summary' => 'Ask for approval before working from home, and see every past request with its outcome.',
                'shots' => ['wfh', 'wfh-request'],
                'steps' => [
                    'Open Work From Home in the sidebar and click Request WFH.',
                    'Pick the start and end dates. Tick Half day and choose which half if needed.',
                    'Give a reason and submit.',
                    'The request appears in WFH Request History as Pending.',
                ],
                'statuses' => ['Pending', 'Approved', 'Rejected', 'Cancelled'],
                'can' => [
                    'Cancel a request while it is pending.',
                    'Filter your history by status and month.',
                ],
                'next' => [
                    'Your manager approves or rejects it, and their name and comment appear in the history.',
                ],
                'tips' => [],
                'links' => [
                    ['route' => 'wfh.my', 'label' => 'Open Work From Home'],
                ],
                'keywords' => 'wfh work from home remote request half day approval history',
            ],
            [
                'id' => 'overtime',
                'category' => 'WFH & Overtime',
                'title' => 'Overtime requests',
                'icon' => 'bolt',
                'summary' => 'Overtime must be pre-approved. Request it here and track the approved hours.',
                'shots' => ['overtime', 'overtime-request'],
                'steps' => [
                    'Open Overtime in the sidebar and click Request OT.',
                    'Enter the work date, the start and end time of the overtime, and the reason.',
                    'Submit. The request appears in OT Request History as Pending.',
                ],
                'statuses' => ['Pending', 'Approved', 'Rejected', 'Cancelled'],
                'can' => [
                    'See total, approved and pending requests and your approved OT hours.',
                    'Cancel a request while it is pending.',
                ],
                'next' => [
                    'Your manager reviews it. Approved hours are counted for payroll.',
                ],
                'tips' => [
                    ['type' => 'info', 'text' => 'If your overtime is tracked automatically (shown with a "Nexflow" tag in the sidebar), detected overtime also appears here.'],
                ],
                'links' => [
                    ['route' => 'overtime.my', 'label' => 'Open Overtime'],
                ],
                'keywords' => 'overtime ot extra hours request pre approval approved hours nexflow',
            ],
            [
                'id' => 'payslips',
                'category' => 'Payroll',
                'title' => 'My Payslips',
                'icon' => 'banknotes',
                'summary' => 'See your monthly salary, download payslips and check your tax summary. Only payslips released by payroll appear here.',
                'shots' => ['payslips'],
                'steps' => [
                    'Open Payroll → My Payslips in the sidebar.',
                    'The top cards show your current net monthly salary, annual CTC, last payslip and total deductions.',
                    'The Current Payslip panel breaks down earnings and deductions into net pay.',
                    'Click Download PDF for this month, or use the icons in Payslip History for any month.',
                    'To print several months together, tick up to 6 months in Payslip History.',
                ],
                'glossary' => [
                    ['term' => 'Gross salary', 'meaning' => 'Total earnings before deductions (basic, allowances, overtime and so on).'],
                    ['term' => 'Deductions', 'meaning' => 'Provident Fund (PF), Professional Tax (PT), income tax (TDS) and any other deductions.'],
                    ['term' => 'Net salary', 'meaning' => 'What is paid into your bank account: gross minus deductions.'],
                    ['term' => 'CTC', 'meaning' => 'Cost to company: your total annual package.'],
                ],
                'can' => [
                    'View, download or email yourself any of your payslips.',
                    'Filter payslip history by year, financial year or a custom range.',
                    'See your salary revision history and year-to-date tax summary.',
                ],
                'next' => [
                    'Each downloaded payslip carries a verification link, so anyone you share it with can confirm it is genuine.',
                ],
                'tips' => [
                    ['type' => 'warning', 'text' => 'Spotted a mistake on a payslip? Contact HR or Payroll. Payslips cannot be edited from your account.'],
                ],
                'links' => [
                    ['route' => 'payroll.payslips', 'label' => 'Open My Payslips'],
                ],
                'keywords' => 'payslip payroll salary pay slip download pdf gross deductions net ctc pf tds professional tax verify email print tax summary',
            ],
            [
                'id' => 'expenses',
                'category' => 'Payroll',
                'title' => 'Expense claims',
                'icon' => 'receipt-percent',
                'summary' => 'Claim back money you spent on company business, such as travel or meals.',
                'shots' => ['expenses', 'expense-new'],
                'steps' => [
                    'Open Payroll → Expense Claims and click New Claim.',
                    'Enter a title, category, amount and the date you spent it.',
                    'Attach the receipt and add notes if helpful.',
                    'Submit. The claim appears in the list as Pending.',
                ],
                'statuses' => ['Pending', 'Approved', 'Rejected'],
                'can' => [
                    'See all your claims and filter them by status, category or month.',
                    'Export your claims.',
                ],
                'next' => [
                    'Your claim is reviewed. If rejected, the reason is shown.',
                    'Approved claims are reimbursed through payroll.',
                ],
                'tips' => [],
                'links' => [
                    ['route' => 'operations.expenses', 'label' => 'Open Expense Claims'],
                ],
                'keywords' => 'expense claim reimbursement receipt travel meals amount submit approved rejected',
            ],
            [
                'id' => 'performance',
                'category' => 'Performance',
                'title' => 'Performance and self-assessment',
                'icon' => 'arrow-trending-up',
                'summary' => 'The Performance menu holds your performance overview, your reviews, and any warnings, improvement plans or promotions on your record.',
                'shots' => ['performance', 'my-review'],
                'steps' => [
                    'Open Performance → My Performance for your overview: current cycle score, timeline, warnings, improvement plan and promotion recommendations.',
                    'Open Performance → My Review when HR starts a review cycle. Your self-assessment appears under Active Assessments.',
                    'Click the review to open your self-assessment. Score each component, write your strengths and areas to improve, then submit.',
                    'Components marked as auto-scored are calculated by the system (for example from attendance) and cannot be edited.',
                    'Finished reviews move to Past Reviews.',
                ],
                'statuses' => ['Draft', 'Submitted', 'Manager Reviewed', 'HR Reviewed', 'Locked'],
                'status_notes' => [
                    'Draft' => 'Your self-assessment is open. Only in this state can you edit it.',
                    'Submitted' => 'You have submitted it; it is now read-only and with your manager.',
                    'Manager Reviewed' => 'Your manager has added their scores and comments.',
                    'HR Reviewed' => 'HR has reviewed and is finalising it.',
                    'Locked' => 'The review is final and moves to Past Reviews.',
                ],
                'can' => [
                    'Fill in your self-assessment while the review is open.',
                    'Read your past reviews and scorecards.',
                    'Read and acknowledge any warning letter under My Warnings.',
                    'Follow your improvement plan and see promotion recommendations.',
                ],
                'next' => [
                    'Once you submit, your self-assessment is locked and your manager reviews it.',
                    'If you have been asked to give feedback on a colleague, a Review Tasks item appears in the Performance menu.',
                ],
                'tips' => [
                    ['type' => 'info', 'text' => 'No review showing? HR has not opened a review cycle that includes you yet. It appears automatically when they do.'],
                ],
                'links' => [
                    ['route' => 'performance.dashboard', 'label' => 'Open My Performance'],
                    ['route' => 'performance.my', 'label' => 'Open My Review'],
                ],
                'keywords' => 'performance review self assessment appraisal scorecard cycle feedback warnings pip improvement plan promotion rewards',
            ],
            [
                'id' => 'goals',
                'category' => 'Performance',
                'title' => 'Goals and KPIs',
                'icon' => 'flag',
                'summary' => 'Set your own development goals and see the KPIs your manager assigned to you.',
                'shots' => ['goals', 'goal-new'],
                'steps' => [
                    'Open Development → My Goals and click Add Goal.',
                    'Give the goal a title, describe how success will be measured, and set a target date if you want one.',
                    'Save. The goal appears under Active Goals.',
                    'Click the circle next to a goal when you complete it; it moves to Completed.',
                    'Open Development → My KPIs to see KPIs assigned to you.',
                ],
                'can' => [
                    'Add, edit, complete and delete your own goals.',
                    'View the KPIs and targets assigned to you.',
                ],
                'next' => [
                    'Your goals can be discussed and rated during your performance review.',
                ],
                'tips' => [],
                'links' => [
                    ['route' => 'performance.goals', 'label' => 'Open My Goals'],
                    ['route' => 'performance.my-kpis', 'label' => 'Open My KPIs'],
                ],
                'keywords' => 'goals kpi targets objectives development progress complete add goal',
            ],
            [
                'id' => 'documents',
                'category' => 'Documents',
                'title' => 'Documents',
                'icon' => 'document-text',
                'summary' => 'Company policies and documents shared with you, plus anything you upload yourself.',
                'shots' => ['documents'],
                'steps' => [
                    'Open Documents in the sidebar.',
                    'Search or filter by category to find a document. Click it to preview or download it.',
                    'If a document asks for acknowledgement, read it and click Acknowledge.',
                    'Click Upload My Document to add your own files (for example ID proof or certificates).',
                ],
                'can' => [
                    'View and download documents shared with you or with everyone.',
                    'Acknowledge required documents and policies.',
                    'Upload your personal documents.',
                ],
                'next' => [
                    'Documents waiting for your acknowledgement are counted in the sidebar badge until you acknowledge them.',
                ],
                'tips' => [
                    ['type' => 'info', 'text' => 'Payslips are on My Payslips, not here.'],
                ],
                'links' => [
                    ['route' => 'documents.index', 'label' => 'Open Documents'],
                ],
                'keywords' => 'documents letters policy upload download acknowledge id proof certificate files',
            ],
            [
                'id' => 'onboarding',
                'category' => 'Documents',
                'title' => 'My Onboarding',
                'icon' => 'clipboard-document-check',
                'summary' => 'Your onboarding checklist: the tasks you need to do, and the ones other teams are doing for you.',
                'shots' => ['onboarding'],
                'steps' => [
                    'Open My Onboarding in the sidebar.',
                    'Your Tasks lists what you need to do, each with a due date and the team it belongs to.',
                    'Tick a task when you have done it. The progress bar at the top updates.',
                    'Handled For You shows what HR, IT, Finance and your manager are setting up, such as your bank account, laptop, email and access card. You don\'t need to do anything for these.',
                ],
                'can' => [
                    'Complete your own onboarding tasks.',
                    'Follow progress on tasks handled by other teams.',
                ],
                'next' => [
                    'Overdue tasks are highlighted in red. HR sends reminders for tasks that are not done.',
                ],
                'tips' => [],
                'links' => [
                    ['route' => 'onboarding.my', 'label' => 'Open My Onboarding'],
                ],
                'keywords' => 'onboarding new joiner checklist tasks kyc induction progress first day',
            ],
            [
                'id' => 'notifications',
                'category' => 'Notifications',
                'title' => 'Notifications and your Inbox',
                'icon' => 'bell',
                'summary' => 'Pulse notifies you when something about your requests, pay or tasks changes: in the app, and by email for important events.',
                'shots' => ['notifications'],
                'steps' => [
                    'A red number on the bell icon (top bar) and on Inbox (sidebar) shows how many notifications you have not read.',
                    'Open Inbox to see them all. Unread ones are marked with a blue dot.',
                    'Click View on a notification to open the related page.',
                    'Use the All / Unread / Read tabs, priority and date filters to find older ones.',
                    'Click Mark All Read when you\'re up to date, or Clear Read to tidy up.',
                ],
                'can' => [
                    'Get notified about leave decisions, attendance corrections, overtime, payslips, documents, performance and onboarding.',
                ],
                'next' => [],
                'tips' => [],
                'links' => [
                    ['route' => 'notifications.index', 'label' => 'Open Inbox'],
                ],
                'keywords' => 'notifications inbox bell unread mark as read alerts email',
            ],
        ];
    }
}
