<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Document;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\OtRequest;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\ProfileChangeRequest;
use App\Models\ReviewGoal;
use App\Models\Role;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Leave\LeaveMovementService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates (or removes) the fictional employee the Employee Guide screenshots
 * are captured from.
 *
 * Every value is invented — name, phone, address, salary — so a screenshot can
 * never leak a real colleague's details. The account is an ordinary Employee
 * role user: the guide shows exactly what a real employee sees, nothing more.
 *
 * Mail is switched to the array driver and the queue to sync for the run, so
 * the observers and notifications this data trips never reach a real inbox.
 * Refuses to run outside local/testing.
 */
#[Signature('guide:demo-employee
    {--password=GuideDemo#2026 : Password for the demo employee login}
    {--remove : Delete the demo employee and everything created for them}')]
#[Description('Create the fictional Employee Guide demo account (local/testing only)')]
class GuideDemoEmployee extends Command
{
    public const EMAIL = 'guide.employee@example.com';

    public const MANAGER_EMAIL = 'guide.manager@example.com';

    public const EMPLOYEE_ID = 'GUIDE-0001';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('guide:demo-employee only runs in local or testing environments.');

            return self::FAILURE;
        }

        config(['mail.default' => 'array', 'queue.default' => 'sync']);

        if ($this->option('remove')) {
            $this->remove();
            $this->info('Employee Guide demo data removed.');

            return self::SUCCESS;
        }

        $password = (string) $this->option('password');

        // One transaction: if creating fails, the previous demo account survives.
        DB::transaction(function () use ($password): void {
            $this->remove();
            $this->create($password);
        });

        $this->info('Employee Guide demo employee ready.');
        $this->line('  Email:    '.self::EMAIL);
        $this->line('  Password: '.$password);

        return self::SUCCESS;
    }

    private function create(string $password): void
    {
        $today = CarbonImmutable::today();

        $manager = User::create([
            'name' => 'Jordan Reed',
            'email' => self::MANAGER_EMAIL,
            'password' => Hash::make(Str::random(40)),
            'role' => 'manager',
            'role_id' => Role::where('slug', 'manager')->value('id'),
            'password_changed_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Alex Morgan',
            'email' => self::EMAIL,
            'password' => Hash::make($password),
            'role' => 'employee',
            'role_id' => Role::where('slug', 'employee')->value('id'),
            'current_team_id' => User::where('role', 'employee')->whereNotNull('current_team_id')->value('current_team_id'),
            'password_changed_at' => now(),
        ]);

        $employee = Employee::create([
            'user_id' => $user->id,
            'employee_id' => self::EMPLOYEE_ID,
            'phone' => '+91 90000 00000',
            'date_of_birth' => '1994-03-15',
            'gender' => 'other',
            'address' => '12 Sample Street, Demo Nagar, Mumbai 400001',
            'emergency_contact' => 'Sam Morgan, +91 90000 00001',
            'office_id' => Office::value('id'),
            'leave_policy_id' => DB::table('leave_policies')->where('is_default', true)->value('id'),
            'working_pattern' => 'regular',
            'working_days_per_week' => 5,
            'department_id' => Department::where('name', 'IT')->value('id') ?? Department::value('id'),
            'job_title_id' => JobTitle::where('name', 'Senior Software Engineer')->value('id') ?? JobTitle::value('id'),
            'manager_id' => $manager->id,
            'shift_id' => DB::table('shift_settings')->value('id'),
            'joining_date' => $today->subDays(35)->toDateString(),
            'status' => 'active',
            'employment_type' => 'full-time',
            'salary_cycle' => 'cycle_a',
        ]);

        $this->seedAttendance($employee, $today);
        $this->seedRequests($employee, $manager, $today);
        $this->seedPayslip($employee);
        $this->seedDocument($employee, $manager);
        $this->seedNotifications($user);
    }

    /**
     * One private document (visible to the demo employee only) that asks for
     * acknowledgement, so the Documents page has something to show.
     */
    private function seedDocument(Employee $employee, User $manager): void
    {
        $path = 'employee-guide/demo-offer-letter.pdf';
        $pdf = implode("\n", [
            '%PDF-1.4',
            '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
            '3 0 obj<</Type/Page/MediaBox[0 0 595 842]/Parent 2 0 R/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>endobj',
            '4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj',
            '5 0 obj<</Length 58>>stream',
            'BT /F1 18 Tf 72 760 Td (Demo offer letter - fictional) Tj ET',
            'endstream endobj',
            'trailer<</Root 1 0 R>>',
            '%%EOF',
        ]);

        Storage::disk('local')->put($path, $pdf);

        Document::withoutEvents(fn () => Document::create([
            'title' => 'Offer letter (demo)',
            'description' => 'Your signed offer letter. Please read and acknowledge.',
            'file_path' => $path,
            'file_name' => 'offer-letter.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen($pdf),
            'version' => 1,
            'category' => 'contract',
            'visibility' => 'restricted',
            'employee_id' => $employee->id,
            'requires_acknowledgement' => true,
            'uploaded_by' => $manager->id,
        ]));
    }

    /**
     * Three working weeks of punches, with one late arrival and one missed
     * check-out so the guide can show what those look like.
     */
    private function seedAttendance(Employee $employee, CarbonImmutable $today): void
    {
        $day = $today->subDays(21);

        while ($day->lt($today)) {
            if ($day->isWeekday()) {
                $isLate = $day->isSameDay($today->subDays(6)->startOfWeek()->addDay());
                $missingCheckout = $day->isSameDay($today->subDays(3)) && $day->isWeekday();
                $checkIn = $day->setTime($isLate ? 10 : 9, $isLate ? 25 : 2);
                $checkOut = $missingCheckout ? null : $day->setTime(18, 10);

                Attendance::withoutEvents(fn () => Attendance::create([
                    'employee_id' => $employee->id,
                    'date' => $day->toDateString(),
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'check_in_method' => 'web',
                    'check_out_method' => $checkOut ? 'web' : null,
                    'break_minutes' => 45,
                    'status' => $isLate ? 'late' : 'on_time',
                    'work_mode' => 'office',
                    'is_late' => $isLate,
                    'late_minutes' => $isLate ? 55 : 0,
                    'missing_checkout' => $missingCheckout,
                    // Pulse v3.1: final out − first in; the 45m break is not deducted.
                    'total_hours' => app(AttendanceCalculator::class)->storedHours($checkIn, $checkOut),
                ]));
            }

            $day = $day->addDay();
        }
    }

    private function seedRequests(Employee $employee, User $manager, CarbonImmutable $today): void
    {
        $annual = LeaveType::where('code', 'AL')->value('id') ?? LeaveType::value('id');
        $sick = LeaveType::where('code', 'SL')->value('id') ?? $annual;
        $nextMonday = $today->next(CarbonImmutable::MONDAY)->addWeek();
        $lastWeek = $today->subWeeks(2)->startOfWeek();

        $approvedSick = LeaveRequest::withoutEvents(function () use ($employee, $manager, $annual, $sick, $nextMonday, $lastWeek): LeaveRequest {
            LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $annual,
                'start_date' => $nextMonday->toDateString(),
                'end_date' => $nextMonday->addDays(1)->toDateString(),
                'days' => 2,
                'reason' => 'Family function',
                'requested_leave_status' => 'paid',
                'status' => 'pending',
            ]);

            return LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $sick,
                'start_date' => $lastWeek->addDays(2)->toDateString(),
                'end_date' => $lastWeek->addDays(2)->toDateString(),
                'days' => 1,
                'reason' => 'Fever',
                'requested_leave_status' => 'paid',
                'approved_leave_status' => 'paid',
                'status' => 'approved',
                'approved_at' => now()->subDays(12),
                'reviewer_id' => $manager->id,
                'reviewer_comment' => 'Get well soon.',
            ]);
        });

        // Post the approved day to the leave ledger the way an approval does, so
        // My Balances shows it as Used rather than an approval that never landed.
        app(LeaveMovementService::class)->recordUsage($approvedSick, 1, $sick, CarbonImmutable::parse($approvedSick->start_date), false, $manager);

        WfhRequest::withoutEvents(fn () => WfhRequest::create([
            'employee_id' => $employee->id,
            'start_date' => $today->next(CarbonImmutable::FRIDAY)->toDateString(),
            'end_date' => $today->next(CarbonImmutable::FRIDAY)->toDateString(),
            'reason' => 'Internet installation at home',
            'status' => 'pending',
        ]));

        OtRequest::withoutEvents(fn () => OtRequest::create([
            'employee_id' => $employee->id,
            'work_date' => $lastWeek->addDay()->toDateString(),
            'start_time' => '18:30:00',
            'end_time' => '20:30:00',
            'requested_hours' => 2,
            'reason' => 'Release support',
            'status' => 'approved',
            'source' => 'manual',
            'reviewer_id' => $manager->id,
            'reviewed_at' => now()->subDays(9),
        ]));

        ExpenseClaim::withoutEvents(function () use ($employee, $manager, $today): void {
            ExpenseClaim::create([
                'employee_id' => $employee->id,
                'title' => 'Client visit cab fare',
                'category' => 'travel',
                'amount' => 850,
                'expense_date' => $today->subDays(5)->toDateString(),
                'status' => 'pending',
            ]);

            ExpenseClaim::create([
                'employee_id' => $employee->id,
                'approved_by' => $manager->id,
                'title' => 'Team lunch',
                'category' => 'meals',
                'amount' => 1200,
                'expense_date' => $today->subDays(18)->toDateString(),
                'status' => 'approved',
            ]);
        });

        ReviewGoal::create([
            'employee_id' => $employee->id,
            'title' => 'Complete the AWS Cloud Practitioner certification',
            'description' => 'Study plan agreed with manager; exam booked for next quarter.',
            'due_date' => $today->addMonths(2)->toDateString(),
        ]);

        ProfileChangeRequest::withoutEvents(fn () => ProfileChangeRequest::create([
            'employee_id' => $employee->id,
            'requested_by' => $employee->user_id,
            'field' => 'address',
            'old_value' => '12 Sample Street, Demo Nagar, Mumbai 400001',
            'new_value' => '45 Example Road, Test Colony, Mumbai 400002',
            'reason' => 'Moved house',
            'status' => 'pending',
        ]));
    }

    /**
     * A paid payslip on the latest finalised payroll, with invented figures.
     */
    private function seedPayslip(Employee $employee): void
    {
        $payroll = Payroll::where('status', 'finalized')->get()
            ->sortByDesc(fn (Payroll $run): int => (int) CarbonImmutable::parse("1 {$run->month} {$run->year}")->format('Ym'))
            ->first();

        if (! $payroll) {
            return;
        }

        $payslip = Payslip::withoutEvents(fn () => Payslip::create([
            'payroll_id' => $payroll->id,
            'employee_id' => $employee->id,
            'gross_salary' => 60000,
            'total_deductions' => 4800,
            'net_salary' => 55200,
            'status' => 'paid',
        ]));

        foreach ([
            ['Basic Salary', 30000, 'earning'],
            ['House Rent Allowance', 15000, 'earning'],
            ['Special Allowance', 15000, 'earning'],
            ['Provident Fund', 3600, 'deduction'],
            ['Professional Tax', 200, 'deduction'],
            ['Income Tax (TDS)', 1000, 'deduction'],
        ] as [$name, $amount, $type]) {
            PayslipItem::create(['payslip_id' => $payslip->id, 'name' => $name, 'amount' => $amount, 'type' => $type]);
        }
    }

    private function seedNotifications(User $user): void
    {
        $rows = [
            ['Leave request submitted', 'Your Annual Leave request is waiting for your manager.', 'calendar-days', 'blue', route('time-off.my'), null],
            ['Payslip available', 'Your latest payslip is ready to view and download.', 'banknotes', 'green', route('payroll.payslips'), null],
            ['Overtime approved', 'Your 2.0 hour overtime request was approved.', 'clock', 'green', route('overtime.my'), now()],
        ];

        foreach ($rows as $index => [$title, $body, $icon, $color, $url, $readAt]) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\GuideDemoNotification',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode(compact('title', 'body', 'icon', 'color', 'url')),
                'read_at' => $readAt,
                'created_at' => now()->subHours($index * 5),
                'updated_at' => now()->subHours($index * 5),
            ]);
        }
    }

    private function remove(): void
    {
        $employee = Employee::withTrashed()->where('employee_id', self::EMPLOYEE_ID)->first();

        DB::transaction(function () use ($employee): void {
            if ($employee) {
                $id = $employee->id;
                PayslipItem::whereIn('payslip_id', Payslip::where('employee_id', $id)->pluck('id'))->delete();
                Payslip::where('employee_id', $id)->delete();
                $documents = Document::withTrashed()->where('employee_id', $id)->get();
                DB::table('document_acknowledgements')->whereIn('document_id', $documents->pluck('id'))->delete();
                $documents->each(fn (Document $document) => $document->forceDelete());
                Storage::disk('local')->delete('employee-guide/demo-offer-letter.pdf');
                foreach (['attendances', 'leave_requests', 'wfh_requests', 'ot_requests', 'expense_claims', 'review_goals', 'profile_change_requests', 'leave_balances', 'onboarding_tasks'] as $table) {
                    DB::table($table)->where('employee_id', $id)->delete();
                }
                Employee::withoutEvents(fn () => $employee->forceDelete());
            }

            $users = User::withTrashed()->whereIn('email', [self::EMAIL, self::MANAGER_EMAIL])->pluck('id');
            DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $users)->delete();
            User::withTrashed()->whereIn('id', $users)->forceDelete();
        });
    }
}
