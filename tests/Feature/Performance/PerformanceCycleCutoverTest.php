<?php

use App\Enums\UserRole;
use App\Livewire\Performance\PerformanceCycles;
use App\Models\Employee;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PerformanceTemplate;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Services\Performance\LegacyCycleMigrator;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('the legacy cycle migrator mirrors review cycles and backfills reviews', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create();

    $legacy = ReviewCycle::create([
        'name' => 'Q1 2026 Check-in',
        'start_date' => '2026-03-01',
        'end_date' => '2026-04-30',
        'status' => 'closed',
    ]);

    $review = PerformanceReview::create([
        'review_cycle_id' => $legacy->id,
        'employee_id' => $employee->id,
        'type' => 'self',
        'status' => 'submitted',
    ]);

    $stats = app(LegacyCycleMigrator::class)->migrate();

    $mirrored = PerformanceCycle::where('name', 'Q1 2026 Check-in')->first();

    expect($stats)->toBe(['cycles_mirrored' => 1, 'reviews_backfilled' => 1])
        ->and($mirrored)->not->toBeNull()
        ->and($mirrored->status)->toBe('completed')
        ->and($mirrored->template->code)->toBe(LegacyCycleMigrator::LEGACY_TEMPLATE_CODE)
        ->and($review->fresh()->performance_cycle_id)->toBe($mirrored->id)
        ->and($review->fresh()->review_cycle_id)->toBe($legacy->id); // legacy pointer preserved
});

test('the legacy cycle migrator is idempotent', function () {
    User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create();

    $legacy = ReviewCycle::create([
        'name' => 'Q2 2026', 'start_date' => '2026-07-01', 'end_date' => '2026-09-30', 'status' => 'active',
    ]);
    PerformanceReview::create([
        'review_cycle_id' => $legacy->id, 'employee_id' => $employee->id, 'type' => 'self', 'status' => 'draft',
    ]);

    app(LegacyCycleMigrator::class)->migrate();
    $second = app(LegacyCycleMigrator::class)->migrate();

    expect($second)->toBe(['cycles_mirrored' => 0, 'reviews_backfilled' => 0])
        ->and(PerformanceCycle::where('name', 'Q2 2026')->count())->toBe(1);
});

test('HR can create a draft performance cycle from a template and activate it', function () {
    Notification::fake();

    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['status' => 'active']);

    $template = PerformanceTemplate::create([
        'name' => 'Global KPI', 'code' => 'GKPI01', 'applies_to_type' => 'global',
        'cycle_type' => 'quarterly', 'is_active' => true, 'created_by' => $hr->id,
    ]);

    Livewire::actingAs($hr)->test(PerformanceCycles::class)
        ->call('create')
        ->set('templateId', $template->id)
        ->set('name', 'Mid-year Reviews')
        ->set('startDate', '2026-08-03')
        ->set('endDate', '2026-08-31')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $cycle = PerformanceCycle::where('name', 'Mid-year Reviews')->first();
    expect($cycle)->not->toBeNull()
        ->and($cycle->status)->toBe('draft')
        ->and($cycle->template_id)->toBe($template->id);

    Livewire::actingAs($hr)->test(PerformanceCycles::class)
        ->call('activate', $cycle->id);

    expect($cycle->fresh()->status)->toBe('active')
        ->and($cycle->reviews()->count())->toBe(1);
});

test('non-draft cycles cannot be edited', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $template = PerformanceTemplate::create([
        'name' => 'T', 'code' => 'T01', 'applies_to_type' => 'global',
        'cycle_type' => 'quarterly', 'is_active' => true, 'created_by' => $hr->id,
    ]);
    $cycle = PerformanceCycle::create([
        'name' => 'Active Cycle', 'template_id' => $template->id, 'cycle_type' => 'quarterly',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'status' => 'active', 'created_by' => $hr->id,
    ]);

    Livewire::actingAs($hr)->test(PerformanceCycles::class)
        ->call('edit', $cycle->id)
        ->assertSet('showModal', false)
        ->assertSet('editingId', null);
});

test('a plain employee cannot open the cycles screen', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(PerformanceCycles::class)
        ->assertForbidden();
});
