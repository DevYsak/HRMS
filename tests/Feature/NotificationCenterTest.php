<?php

use App\Enums\UserRole;
use App\Livewire\NotificationsPage;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Create a database notification for the given user with the provided data payload.
 *
 * @param  array<string, mixed>  $data
 */
function makeNotification(User $user, array $data, ?string $createdAt = null, ?string $readAt = null): DatabaseNotification
{
    $attributes = [
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'data' => $data,
        'read_at' => $readAt,
    ];

    if ($createdAt !== null) {
        $attributes['created_at'] = $createdAt;
        $attributes['updated_at'] = $createdAt;
    }

    return $user->notifications()->create($attributes);
}

test('priority column is populated from the notification data payload', function () {
    $user = User::factory()->create();

    $high = makeNotification($user, ['type' => 'document_expiry', 'priority' => 'high', 'title' => 'High one']);
    $normal = makeNotification($user, ['type' => 'leave_request', 'title' => 'Normal one']);
    $invalid = makeNotification($user, ['type' => 'x', 'priority' => 'urgent', 'title' => 'Invalid priority']);

    expect($high->fresh()->priority)->toBe('high')
        ->and($normal->fresh()->priority)->toBe('normal')   // default when absent
        ->and($invalid->fresh()->priority)->toBe('normal'); // invalid value falls back
});

test('inbox filters notifications by priority', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    makeNotification($user, ['type' => 'document_expiry', 'priority' => 'high', 'title' => 'HIGHNOTIF']);
    makeNotification($user, ['type' => 'leave_request', 'priority' => 'low', 'title' => 'LOWNOTIF']);

    Livewire::test(NotificationsPage::class)
        ->assertSee('HIGHNOTIF')
        ->assertSee('LOWNOTIF')
        ->call('setPriority', 'high')
        ->assertSee('HIGHNOTIF')
        ->assertDontSee('LOWNOTIF');
});

test('inbox filters notifications by date range', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    makeNotification($user, ['title' => 'OLDNOTIF'], now()->subDays(30)->toDateTimeString());
    makeNotification($user, ['title' => 'NEWNOTIF'], now()->toDateTimeString());

    Livewire::test(NotificationsPage::class)
        ->set('dateFrom', now()->subDays(2)->toDateString())
        ->assertSee('NEWNOTIF')
        ->assertDontSee('OLDNOTIF');
});

test('bulk mark read marks only the selected notifications', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $a = makeNotification($user, ['title' => 'A']);
    $b = makeNotification($user, ['title' => 'B']);

    Livewire::test(NotificationsPage::class)
        ->set('selected', [$a->id])
        ->call('markSelectedRead')
        ->assertSet('selected', []);

    expect($a->fresh()->read_at)->not->toBeNull()
        ->and($b->fresh()->read_at)->toBeNull();
});

test('bulk delete removes only the selected notifications', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $a = makeNotification($user, ['title' => 'A']);
    $b = makeNotification($user, ['title' => 'B']);

    Livewire::test(NotificationsPage::class)
        ->set('selected', [$a->id])
        ->call('deleteSelected');

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->id)->toBe($b->id);
});

test('a plain employee does not see the reminders tab', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Employee]));

    Livewire::test(NotificationsPage::class)
        ->assertDontSeeHtml("setView('reminders')");
});

test('hr admin sees the reminders tab and all probation reviews', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $person = User::factory()->create(['name' => 'PROBATIONPERSON']);
    Employee::factory()->create([
        'user_id' => $person->id,
        'status' => 'probation',
        'probation_end_date' => now()->addDays(5),
    ]);

    Livewire::test(NotificationsPage::class)
        ->assertSeeHtml("setView('reminders')")
        ->call('setView', 'reminders')
        ->assertSee('PROBATIONPERSON');
});

test('a manager only sees their own team in the reminder center', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $this->actingAs($manager);

    $mine = User::factory()->create(['name' => 'MYTEAMMEMBER']);
    Employee::factory()->create([
        'user_id' => $mine->id,
        'status' => 'probation',
        'probation_end_date' => now()->addDays(5),
        'manager_id' => $manager->id,
    ]);

    $otherManager = User::factory()->create();
    $theirs = User::factory()->create(['name' => 'OTHERTEAMMEMBER']);
    Employee::factory()->create([
        'user_id' => $theirs->id,
        'status' => 'probation',
        'probation_end_date' => now()->addDays(5),
        'manager_id' => $otherManager->id,
    ]);

    Livewire::test(NotificationsPage::class)
        ->call('setView', 'reminders')
        ->assertSee('MYTEAMMEMBER')
        ->assertDontSee('OTHERTEAMMEMBER');
});
