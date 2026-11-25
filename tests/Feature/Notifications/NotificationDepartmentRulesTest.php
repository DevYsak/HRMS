<?php

use App\Enums\UserRole;
use App\Livewire\Settings\NotificationSettings;
use App\Models\AttendanceRegularisation;
use App\Models\Department;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\NotificationDepartmentRule;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\Attendance\RegularisationNotices;
use App\Services\LeaveService;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationDeliveryGate;
use App\Services\Notifications\NotificationRecipientPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Settings → Notifications & Email: each stage of an event (Leave
 * application / approved / rejected / cancelled, missed punch approved /
 * rejected) is its own module; a department can switch an event off, add
 * recipients, exclude people or send only to the people listed; named
 * people can be excluded company-wide; and the email history shows the
 * department, the request and who triggered each email.
 */

const LEAVE = 'App\\Notifications\\LeaveRequestNotification';

beforeEach(function () {
    app(NotificationCatalog::class)->sync();

    $this->it = Department::factory()->create(['name' => 'IT']);
    $this->marketing = Department::factory()->create(['name' => 'Marketing']);
    $this->hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'Shivani HR']);
    $this->manager = User::factory()->create(['role' => UserRole::Manager, 'name' => 'Mazhar Manager']);
    $this->itHr = User::factory()->create(['name' => 'Priya IT HR']);
    Employee::factory()->create(['user_id' => $this->itHr->id, 'department_id' => $this->it->id, 'status' => 'active', 'manager_id' => null]);

    $this->itEmployee = Employee::factory()->create(['user_id' => User::factory()->create(['name' => 'Yogesh IT'])->id,
        'department_id' => $this->it->id, 'status' => 'active', 'manager_id' => $this->manager->id]);
    $this->mktEmployee = Employee::factory()->create(['user_id' => User::factory()->create(['name' => 'Gauri Marketing'])->id,
        'department_id' => $this->marketing->id, 'status' => 'active', 'manager_id' => $this->manager->id]);
});

test('every stage is its own module, created as its event is set, and governed on its own', function () {
    $approved = NotificationSetting::for(LEAVE.'@approved');
    $rejected = NotificationSetting::for(LEAVE.'@rejected');

    expect($approved)->not->toBeNull()
        ->and($approved->label)->toBe('Leave approved')
        ->and(NotificationSetting::for(LEAVE.'@submitted')->label)->toBe('Leave application')
        ->and(NotificationSetting::for('App\\Notifications\\RegularisationReviewedNotification@rejected')->label)->toBe('Missed punch (regularisation) rejected')
        ->and($approved->mail_enabled)->toBe(NotificationSetting::for(LEAVE)->mail_enabled);

    // The notification says which stage it is.
    $leave = LeaveRequest::create(['employee_id' => $this->itEmployee->id, 'leave_type_id' => LeaveType::create(['name' => 'Casual'])->id,
        'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'days' => 1, 'reason' => 'Family', 'status' => 'approved']);
    expect(NotificationCatalog::eventKeyFor(new LeaveRequestNotification($leave)))->toBe(LEAVE.'@approved');

    // Rejections off by email; approvals still on.
    $rejected->update(['mail_enabled' => false]);
    $gate = app(NotificationDeliveryGate::class);
    expect($gate->mail(LEAVE.'@rejected', 'employee')->allowed)->toBeFalse()
        ->and($gate->mail(LEAVE.'@approved', 'employee')->allowed)->toBeTrue()
        ->and($rejected->roleSettings()->count())->toBe(0);

    // A stage never sends more than the event allows a role.
    NotificationSetting::for(LEAVE)->roleSetting('employee')->update(['mail_enabled' => false]);
    NotificationSetting::flushCache();
    expect($gate->mail(LEAVE.'@approved', 'employee')->allowed)->toBeFalse()
        ->and($gate->mail(LEAVE.'@approved', 'manager')->allowed)->toBeTrue();
});

test('a department rule adds people, excludes a role, or sends only to the listed people — for that department only', function () {
    $policy = app(NotificationRecipientPolicy::class);
    $key = LEAVE.'@submitted';
    $defaults = collect([$this->manager, $this->hr]);

    NotificationDepartmentRule::create(['notification_key' => $key, 'department_id' => $this->it->id,
        'include_user_ids' => [$this->itHr->id], 'exclude_roles' => ['manager']]);

    expect($policy->resolve($key, $defaults, $this->itEmployee)->pluck('name')->sort()->values()->all())->toBe(['Priya IT HR', 'Shivani HR'])
        // Marketing has no rule: the usual recipients.
        ->and($policy->resolve($key, $defaults, $this->mktEmployee)->pluck('name')->sort()->values()->all())->toBe(['Mazhar Manager', 'Shivani HR']);

    // Only the listed people.
    NotificationDepartmentRule::where('department_id', $this->it->id)->first()->update(['replace_defaults' => true, 'exclude_roles' => null]);
    expect($policy->resolve($key, $defaults, $this->itEmployee)->pluck('name')->all())->toBe(['Priya IT HR']);

    // A rule on the event covers its stages; a stage's own rule wins.
    NotificationDepartmentRule::create(['notification_key' => LEAVE, 'department_id' => $this->marketing->id, 'is_enabled' => false]);
    expect($policy->resolve($key, $defaults, $this->mktEmployee))->toHaveCount(0);
});

test('an event switched off for a department, or a person excluded, is held back at sending', function () {
    $gate = app(NotificationDeliveryGate::class);

    NotificationDepartmentRule::create(['notification_key' => LEAVE.'@approved', 'department_id' => $this->it->id, 'is_enabled' => false]);
    expect($gate->database(LEAVE.'@approved', 'employee', $this->itEmployee->user, $this->itEmployee)->reason)->toBe('department_disabled')
        ->and($gate->database(LEAVE.'@approved', 'employee', $this->mktEmployee->user, $this->mktEmployee)->allowed)->toBeTrue();

    NotificationSetting::for(LEAVE)->update(['exclude_user_ids' => [$this->manager->id]]);
    expect($gate->database(LEAVE.'@rejected', 'manager', $this->manager)->reason)->toBe('recipient_role_excluded')
        ->and(app(NotificationRecipientPolicy::class)->excludes(LEAVE.'@submitted', $this->manager))->toBeTrue();
});

test('a leave application goes to the people the department rule adds, told about it rather than asked to decide', function () {
    Notification::fake();
    NotificationDepartmentRule::create(['notification_key' => LEAVE.'@submitted', 'department_id' => $this->it->id,
        'include_user_ids' => [$this->itHr->id]]);

    $type = LeaveType::create(['name' => 'Casual', 'is_paid' => false, 'allow_unpaid_request' => true]);
    $day = Carbon::today()->addWeek()->nextWeekday()->toDateString();
    app(LeaveService::class)->submitRequest($this->itEmployee->fresh(), $type, $day, $day, 'Personal work', requestedLeaveStatus: 'unpaid');

    Notification::assertSentTo($this->itHr, LeaveRequestNotification::class, fn ($n) => $n->notificationRole() === 'recipient');
    Notification::assertSentTo($this->manager, LeaveRequestNotification::class, fn ($n) => $n->notificationRole() === 'manager');

    // Marketing: no rule, so IT's HR is not told.
    app(LeaveService::class)->submitRequest($this->mktEmployee->fresh(), $type, $day, $day, 'Personal work', requestedLeaveStatus: 'unpaid');
    Notification::assertSentToTimes($this->itHr, LeaveRequestNotification::class, 1);
});

test('a missed-punch decision goes to the employee and whoever the rule for that stage adds', function () {
    Notification::fake();
    NotificationDepartmentRule::create(['notification_key' => 'App\\Notifications\\RegularisationReviewedNotification@approved',
        'department_id' => $this->it->id, 'include_user_ids' => [$this->itHr->id]]);

    $reg = AttendanceRegularisation::create(['employee_id' => $this->itEmployee->id, 'work_date' => '2026-11-20',
        'requested_check_in' => '2026-11-20 10:30:00', 'requested_check_out' => '2026-11-20 19:30:00',
        'reason' => 'Forgot to punch out', 'status' => 'approved']);
    app(RegularisationNotices::class)->decided($reg);

    Notification::assertSentTo($this->itEmployee->user, RegularisationReviewedNotification::class, fn ($n) => $n->notificationRole() === 'employee');
    Notification::assertSentTo($this->itHr, RegularisationReviewedNotification::class,
        fn ($n) => $n->notificationRole() === 'recipient' && str_contains($n->toArray($this->itHr)['body'], "Yogesh IT's attendance correction"));

    // Rejected has no rule: only the employee.
    $reg->update(['status' => 'rejected']);
    app(RegularisationNotices::class)->decided($reg->fresh());
    Notification::assertSentToTimes($this->itHr, RegularisationReviewedNotification::class, 1);
});

test('HR configures department rules, exclusions and reads the email history on the settings page', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($admin);

    // Department rules tab.
    Livewire::test(NotificationSettings::class)
        ->set('tab', 'departments')
        ->set('ruleDepartmentId', $this->it->id)
        ->assertSee('Department-wise notifications')
        ->assertSee('Leave approved')
        ->call('openRule', LEAVE.'@submitted')
        ->set('ruleIncludeUserIds', [(string) $this->itHr->id])
        ->set('ruleExcludeRoles', ['manager'])
        ->call('saveRule')
        ->assertHasNoErrors()
        ->assertSee('+ Priya IT HR')
        ->call('toggleRuleEnabled', LEAVE.'@approved')
        ->assertSee('Off for this department');

    expect(NotificationDepartmentRule::where('notification_key', LEAVE.'@submitted')->first()->only(['include_user_ids', 'exclude_roles']))
        ->toBe(['include_user_ids' => [$this->itHr->id], 'exclude_roles' => ['manager']])
        ->and(NotificationDepartmentRule::where('notification_key', LEAVE.'@approved')->first()->is_enabled)->toBeFalse();

    // Exceptions: a named person excluded from an event.
    Livewire::test(NotificationSettings::class)
        ->call('openRecipients', NotificationSetting::for(LEAVE)->id)
        ->set('rcExcludeUserIds', [(string) $this->manager->id])
        ->call('saveRecipients')
        ->assertHasNoErrors();
    expect(NotificationSetting::for(LEAVE)->fresh()->exclude_user_ids)->toBe([$this->manager->id]);

    // Email history: the department, the request and who triggered it.
    $leave = LeaveRequest::create(['employee_id' => $this->itEmployee->id, 'leave_type_id' => LeaveType::create(['name' => 'Casual'])->id,
        'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'days' => 1, 'reason' => 'Family', 'status' => 'pending']);
    EmailLog::create(['notification_key' => LEAVE.'@submitted', 'to_email' => 'hr@example.com', 'to_name' => 'Shivani HR',
        'subject' => 'Leave application — Yogesh IT', 'status' => 'sent', 'context_type' => 'leave_request', 'context_id' => $leave->id]);

    Livewire::test(NotificationSettings::class)
        ->set('tab', 'history')
        ->assertSee('Leave application — Yogesh IT')
        ->assertSee('Leave request #'.$leave->id)
        ->assertSee('IT')
        ->set('historyDepartment', $this->marketing->id)
        ->assertDontSee('Leave application — Yogesh IT');
});

test('only departments within the viewer\'s settings reach can be configured', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    Livewire::test(NotificationSettings::class)
        ->set('ruleDepartmentId', 999999)
        ->call('openRule', LEAVE)
        ->assertForbidden();
});
