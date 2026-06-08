<?php

use App\Models\Employee;
use App\Models\PromotionRecommendation;
use App\Models\User;
use App\Services\Performance\PromotionService;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function promotionEmployee(?array $attrs = []): Employee
{
    $user = User::factory()->create();

    return Employee::factory()->create(array_merge(['user_id' => $user->id], $attrs));
}

function recommendPromotion(Employee $employee, User $manager, array $overrides = []): PromotionRecommendation
{
    return app(PromotionService::class)->recommend($employee, array_merge([
        'recommendation_type' => 'promotion',
        'current_role' => 'Software Engineer',
        'proposed_role' => 'Senior Software Engineer',
        'current_salary' => 60000.0,
        'proposed_salary' => 72000.0,
        'bonus_amount' => null,
        'target_department_id' => null,
        'justification' => 'Consistently exceeds expectations and has taken on lead responsibilities.',
        'effective_date' => '2026-09-01',
    ], $overrides), $manager);
}

// ─── Creation ─────────────────────────────────────────────────────────────────

it('creates a recommendation pending hr review', function () {
    $employee = promotionEmployee();
    $manager = User::factory()->create();

    $recommendation = recommendPromotion($employee, $manager);

    expect($recommendation->employee_id)->toBe($employee->id)
        ->and($recommendation->recommended_by)->toBe($manager->id)
        ->and($recommendation->status)->toBe('pending_hr')
        ->and($recommendation->current_reviewer_stage)->toBe('hr');
});

it('calculates the salary increment percentage', function () {
    $employee = promotionEmployee();
    $manager = User::factory()->create();

    $recommendation = recommendPromotion($employee, $manager);

    expect($recommendation->incrementPercent())->toBe(20.0);
});

// ─── Multi-stage approval chain ───────────────────────────────────────────────

it('advances a recommendation through the hr, dept head, and super admin stages until approved', function () {
    $employee = promotionEmployee();
    $manager = User::factory()->create();
    $hr = User::factory()->create();
    $deptHead = User::factory()->create();
    $superAdmin = User::factory()->create();

    $recommendation = recommendPromotion($employee, $manager);

    app(PromotionService::class)->approve($recommendation, $hr, 'Looks good from an HR perspective.');
    expect($recommendation->fresh())
        ->status->toBe('pending_dept_head')
        ->current_reviewer_stage->toBe('dept_head')
        ->hr_comments->toBe('Looks good from an HR perspective.');

    app(PromotionService::class)->approve($recommendation->fresh(), $deptHead, 'Approved at department level.');
    expect($recommendation->fresh())
        ->status->toBe('pending_super_admin')
        ->current_reviewer_stage->toBe('super_admin')
        ->dept_head_comments->toBe('Approved at department level.');

    app(PromotionService::class)->approve($recommendation->fresh(), $superAdmin, 'Final approval granted.');
    expect($recommendation->fresh())
        ->status->toBe('approved')
        ->current_reviewer_stage->toBe('super_admin')
        ->super_admin_comments->toBe('Final approval granted.');
});

// ─── Rejection ────────────────────────────────────────────────────────────────

it('rejects a pending recommendation and records the reviewer stage comment', function () {
    $employee = promotionEmployee();
    $manager = User::factory()->create();
    $hr = User::factory()->create();

    $recommendation = recommendPromotion($employee, $manager);

    app(PromotionService::class)->reject($recommendation, $hr, 'Not enough tenure in the current role yet.');

    expect($recommendation->fresh())
        ->status->toBe('rejected')
        ->hr_comments->toBe('Not enough tenure in the current role yet.');
});

it('records the rejection comment under the current reviewer stage at any point in the chain', function () {
    $employee = promotionEmployee();
    $manager = User::factory()->create();
    $hr = User::factory()->create();
    $deptHead = User::factory()->create();

    $recommendation = recommendPromotion($employee, $manager);

    app(PromotionService::class)->approve($recommendation, $hr, 'Forwarding to department head.');
    app(PromotionService::class)->reject($recommendation->fresh(), $deptHead, 'Budget freeze for this quarter.');

    expect($recommendation->fresh())
        ->status->toBe('rejected')
        ->current_reviewer_stage->toBe('dept_head')
        ->dept_head_comments->toBe('Budget freeze for this quarter.')
        ->hr_comments->toBe('Forwarding to department head.');
});

it('blocks rejecting an already approved recommendation', function () {
    $employee = promotionEmployee();
    $manager = User::factory()->create();
    $hr = User::factory()->create();
    $deptHead = User::factory()->create();
    $superAdmin = User::factory()->create();

    $recommendation = recommendPromotion($employee, $manager);

    app(PromotionService::class)->approve($recommendation, $hr, 'HR approval.');
    app(PromotionService::class)->approve($recommendation->fresh(), $deptHead, 'Dept head approval.');
    app(PromotionService::class)->approve($recommendation->fresh(), $superAdmin, 'Super admin approval.');

    expect(fn () => app(PromotionService::class)->reject($recommendation->fresh(), $superAdmin, 'Changed my mind.'))
        ->toThrow(DomainException::class, 'Approved recommendations cannot be rejected.');
});
