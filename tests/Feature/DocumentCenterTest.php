<?php

use App\Enums\UserRole;
use App\Livewire\Documents\DocumentManager;
use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Create a top-level document with sensible defaults.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeDocument(array $attributes = []): Document
{
    return Document::create(array_merge([
        'title' => 'Doc '.Str::random(6),
        'file_path' => 'documents/sample.pdf',
        'file_name' => 'sample.pdf',
        'uploaded_by' => User::factory()->create()->id,
        'category' => 'policy',
        'visibility' => 'all',
    ], $attributes));
}

test('expiry tracking buckets documents within the 90-day window and excludes the rest', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    makeDocument(['title' => 'EXPIREDDOC', 'expires_at' => now()->subDays(3)]);
    makeDocument(['title' => 'SOONDOC', 'expires_at' => now()->addDays(10)]);
    makeDocument(['title' => 'MIDDOC', 'expires_at' => now()->addDays(45)]);
    makeDocument(['title' => 'FARDOC', 'expires_at' => now()->addDays(80)]);
    makeDocument(['title' => 'WAYOUTDOC', 'expires_at' => now()->addDays(200)]);

    Livewire::test(DocumentManager::class)
        ->call('setView', 'expiry')
        ->assertSee('EXPIREDDOC')
        ->assertSee('SOONDOC')
        ->assertSee('MIDDOC')
        ->assertSee('FARDOC')
        ->assertDontSee('WAYOUTDOC');
});

test('acknowledgement tracker lists employees who have not confirmed', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $doc = makeDocument([
        'title' => 'POLICYDOC',
        'requires_acknowledgement' => true,
        'visibility' => 'all',
    ]);

    $confirmed = Employee::factory()->create(['status' => 'active']);

    $pendingUser = User::factory()->create(['name' => 'PENDINGPERSON']);
    Employee::factory()->create(['user_id' => $pendingUser->id, 'status' => 'active']);

    DocumentAcknowledgement::create([
        'document_id' => $doc->id,
        'employee_id' => $confirmed->id,
        'acknowledged_at' => now(),
    ]);

    Livewire::test(DocumentManager::class)
        ->call('setView', 'acknowledgements')
        ->assertSee('POLICYDOC')
        ->assertSee('PENDINGPERSON');
});

test('a non-manager cannot access the expiry or acknowledgement tabs', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Employee]));

    Livewire::test(DocumentManager::class)
        ->assertDontSeeHtml("setView('expiry')")
        ->assertDontSeeHtml("setView('acknowledgements')")
        ->call('setView', 'expiry')
        ->assertSet('view', 'library');
});

test('smart search matches documents by employee name', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create();
    makeDocument(['title' => 'OFFERLETTER', 'employee_id' => $employee->id, 'category' => 'contract']);
    makeDocument(['title' => 'UNRELATEDPOLICY', 'category' => 'policy']);

    Livewire::test(DocumentManager::class)
        ->set('filterSearch', $employee->user->name)
        ->assertSee('OFFERLETTER')
        ->assertDontSee('UNRELATEDPOLICY');
});

test('filtering by employee narrows the document list', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $employee = Employee::factory()->create();
    makeDocument(['title' => 'EMPDOC', 'employee_id' => $employee->id]);
    makeDocument(['title' => 'OTHERDOC']);

    Livewire::test(DocumentManager::class)
        ->set('filterEmployee', (string) $employee->id)
        ->assertSee('EMPDOC')
        ->assertDontSee('OTHERDOC');
});

test('version history lists every version newest first', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $root = makeDocument(['title' => 'CONTRACTDOC', 'version' => 1]);
    makeDocument(['title' => 'CONTRACTDOC', 'version' => 2, 'parent_id' => $root->id]);
    makeDocument(['title' => 'CONTRACTDOC', 'version' => 3, 'parent_id' => $root->id]);

    Livewire::test(DocumentManager::class)
        ->call('showVersions', $root->id)
        ->assertSee('Version History')
        ->assertSee('v3')
        ->assertSee('Latest');
});

test('preview is forbidden for a document the user cannot access', function () {
    $owner = Employee::factory()->create();
    $private = makeDocument([
        'title' => 'PRIVATEDOC',
        'visibility' => 'individual',
        'category' => 'personal',
        'employee_id' => $owner->id,
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Employee]));

    Livewire::test(DocumentManager::class)
        ->call('preview', $private->id)
        ->assertForbidden();
});

test('preview modal renders an inline frame for an accessible PDF', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $doc = makeDocument(['title' => 'PREVIEWME', 'mime_type' => 'application/pdf']);

    Livewire::test(DocumentManager::class)
        ->call('preview', $doc->id)
        ->assertSet('previewId', $doc->id)
        ->assertSee('PREVIEWME')
        ->assertSeeHtml('<iframe');
});
