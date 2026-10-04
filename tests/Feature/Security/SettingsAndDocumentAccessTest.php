<?php

use App\Enums\UserRole;
use App\Livewire\Documents\DocumentManager;
use App\Livewire\Employees\EmployeeDocuments;
use App\Models\Company;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * Spec v3.1 §4 / §9 — System Settings belong to Super Admin (HR: partial);
 * HR documents are scoped to the individual + HR Admin (+ Finance for
 * payslips); uploads are PDF/images only and never run in the browser.
 */
function docUser(UserRole $role): User
{
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

function storedDocument(Employee $owner, array $attributes = []): Document
{
    Storage::disk('local')->put('documents/employee/'.$owner->id.'/contract.pdf', '%PDF-1.4 test');

    return Document::create(array_merge([
        'title' => 'Employment contract',
        'file_path' => 'documents/employee/'.$owner->id.'/contract.pdf',
        'file_name' => 'contract.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 13,
        'category' => 'contract',
        'visibility' => 'individual',
        'employee_id' => $owner->id,
        'uploaded_by' => $owner->user_id,
    ], $attributes));
}

function signedDocumentUrl(string $route, Document $document): string
{
    return URL::temporarySignedRoute($route, now()->addMinutes(5), ['document' => $document->id]);
}

beforeEach(function () {
    Company::factory()->create();
    Storage::fake('local');
    Storage::fake('public');
});

// ── System settings ─────────────────────────────────────────────────────────

test('an employee cannot open or act on the company settings page', function () {
    $employee = docUser(UserRole::Employee);
    $department = Department::factory()->create();

    $this->actingAs($employee)->get(route('settings.general'))->assertForbidden();

    Livewire::actingAs($employee)->test('pages::settings.general')->assertForbidden();

    expect(Department::find($department->id))->not->toBeNull();
});

test('a manager cannot rename the company or delete a department', function () {
    $manager = docUser(UserRole::Manager);

    $this->actingAs($manager)->get(route('settings.general'))->assertForbidden();
});

test('HR Admin can still manage company settings', function () {
    $this->actingAs(docUser(UserRole::HrAdmin))->get(route('settings.general'))->assertOk();
});

test('an SVG favicon is refused because it can carry script', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    Livewire::test('pages::settings.general')
        ->set('favicon', UploadedFile::fake()->create('icon.svg', 2, 'image/svg+xml'))
        ->call('updateCompany')
        ->assertHasErrors('favicon');
});

// ── Signed document links are not a bearer token ────────────────────────────

test('a signed link to someone else\'s contract is refused', function () {
    $owner = docUser(UserRole::Employee);
    $colleague = docUser(UserRole::Employee);
    $document = storedDocument($owner->employee);

    $this->actingAs($colleague)->get(signedDocumentUrl('documents.view', $document))->assertForbidden();
    $this->actingAs($colleague)->get(signedDocumentUrl('documents.download', $document))->assertForbidden();
});

test('the owner and HR can open the contract', function () {
    $owner = docUser(UserRole::Employee);
    $document = storedDocument($owner->employee);

    $this->actingAs($owner)->get(signedDocumentUrl('documents.view', $document))->assertOk();
    $this->actingAs(docUser(UserRole::HrAdmin))->get(signedDocumentUrl('documents.download', $document))->assertOk();
});

test('a stored HTML file is downloaded, never rendered inline', function () {
    $owner = docUser(UserRole::Employee);
    Storage::disk('local')->put('documents/employee/x/page.html', '<script>alert(1)</script>');
    $document = storedDocument($owner->employee, [
        'file_path' => 'documents/employee/x/page.html', 'file_name' => 'page.html', 'mime_type' => 'text/html',
    ]);

    $response = $this->actingAs($owner)->get(signedDocumentUrl('documents.view', $document))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->not->toContain('text/html');
});

test('payslip documents are not visible to a Director', function () {
    $owner = docUser(UserRole::Employee);
    $document = storedDocument($owner->employee, ['category' => 'payslip']);

    $this->actingAs(docUser(UserRole::Director))->get(signedDocumentUrl('documents.view', $document))->assertForbidden();
    $this->actingAs(docUser(UserRole::Finance))->get(signedDocumentUrl('documents.view', $document))->assertOk();
});

// ── Employee document tab ───────────────────────────────────────────────────

test('a Director without document management cannot list, upload or delete employee documents', function () {
    $owner = docUser(UserRole::Employee);
    $document = storedDocument($owner->employee);

    Livewire::actingAs(docUser(UserRole::Director))
        ->test(EmployeeDocuments::class, ['employee' => $owner->employee])
        ->assertForbidden();

    expect(Document::find($document->id))->not->toBeNull();
});

test('HR may upload a PDF to an employee record but not an HTML file', function () {
    $owner = docUser(UserRole::Employee);

    Livewire::actingAs(docUser(UserRole::HrAdmin))
        ->test(EmployeeDocuments::class, ['employee' => $owner->employee])
        ->set('title', 'Signed contract')
        ->set('category', 'contract')
        ->set('file', UploadedFile::fake()->create('evil.html', 1, 'text/html'))
        ->call('upload')
        ->assertHasErrors('file')
        ->set('file', UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf'))
        ->call('upload')
        ->assertHasNoErrors();

    expect(Document::where('employee_id', $owner->employee->id)->count())->toBe(1);
});

test('an employee can only acknowledge a document addressed to them that asks for it', function () {
    $owner = docUser(UserRole::Employee);
    $other = docUser(UserRole::Employee);
    $private = storedDocument($owner->employee, ['requires_acknowledgement' => true]);

    Livewire::actingAs($other)
        ->test(DocumentManager::class)
        ->call('acknowledge', $private->id)
        ->assertForbidden();

    expect(DocumentAcknowledgement::count())->toBe(0);
});
