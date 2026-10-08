<?php

namespace App\Livewire\Performance;

use App\Models\Document;
use App\Models\Employee;
use App\Models\WarningLetter;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Performance\WarningService;
use App\Services\Security\ScopeResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class WarningLetters extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $type = '';

    public bool $showIssueModal = false;

    public bool $showViewModal = false;

    public ?WarningLetter $activeWarning = null;

    // Issue Form
    public $employee_id = '';

    public $warning_type = 'verbal';

    public $reason = '';

    public $description = '';

    public $issue_date = '';

    public $next_review_date = '';

    // Action forms
    public string $close_comment = '';

    public bool $showCloseForm = false;

    public bool $showEscalateForm = false;

    // Document upload
    public bool $showDocUploadModal = false;

    public string $doc_title = '';

    public string $doc_description = '';

    public $doc_file = null;

    /** Issuing and managing letters: employee management plus the warning-letter permission. */
    private function canManageWarnings(): bool
    {
        $user = Auth::user();

        return $user->canManageEmployees() && $user->hasPermission('manage_warning_letters');
    }

    /** Actions on the open letter stay inside the viewer's reach. */
    private function assertWarningInReach(): void
    {
        $employee = $this->activeWarning?->employee;
        abort_unless($employee !== null && app(ScopeResolver::class)->covers(Auth::user(), 'manage_warning_letters', $employee), 403);
    }

    public function mount()
    {
        $this->issue_date = now()->format('Y-m-d');
    }

    public function openIssueModal()
    {
        abort_unless($this->canManageWarnings(), 403);
        $this->reset(['employee_id', 'warning_type', 'reason', 'description', 'next_review_date']);
        $this->issue_date = now()->format('Y-m-d');
        $this->showIssueModal = true;
    }

    public function issueWarning(WarningService $warningService)
    {
        abort_unless($this->canManageWarnings(), 403);

        $this->validate([
            'employee_id' => 'required|exists:employees,id',
            'warning_type' => 'required|in:verbal,written,final,pip',
            'reason' => 'required|string|max:255',
            'description' => 'nullable|string',
            'issue_date' => 'required|date',
            'next_review_date' => 'nullable|date|after:issue_date',
        ]);

        $employee = Employee::findOrFail($this->employee_id);
        // Disciplinary action only inside the issuer's reach, never on oneself.
        app(ApprovalGuard::class)->assertCanDecide(Auth::user(), $employee, 'manage_warning_letters');

        try {
            $warningService->issue($employee, [
                'warning_type' => $this->warning_type,
                'reason' => $this->reason,
                'description' => $this->description,
                'issue_date' => $this->issue_date,
                'next_review_date' => $this->next_review_date,
            ], Auth::user());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->showIssueModal = false;
        \Flux::toast('Warning issued successfully.', variant: 'success');
    }

    public function viewWarning(int $id)
    {
        $user = Auth::user();
        $warning = WarningLetter::with('employee')->findOrFail($id);
        abort_unless(
            ($this->canManageWarnings() && $warning->employee && app(ScopeResolver::class)->covers($user, 'manage_warning_letters', $warning->employee))
                // warning_letters.issued_by holds the issuing USER's id.
                || (int) $warning->issued_by === (int) $user->id,
            403,
        );

        $this->activeWarning = WarningLetter::with(['employee.user', 'issuedBy', 'closedBy', 'acknowledgements', 'documents'])->findOrFail($id);
        $this->showCloseForm = false;
        $this->showEscalateForm = false;
        $this->showViewModal = true;
    }

    public function openDocUploadModal(): void
    {
        abort_unless($this->canManageWarnings(), 403);
        $this->reset(['doc_title', 'doc_description', 'doc_file']);
        $this->resetErrorBag();
        $this->showDocUploadModal = true;
    }

    public function uploadDocument(): void
    {
        abort_unless($this->canManageWarnings(), 403);
        $this->assertWarningInReach();

        $this->validate([
            'doc_title' => 'required|string|max:255',
            'doc_description' => 'nullable|string|max:1000',
            'doc_file' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240',
        ]);

        $path = $this->doc_file->store("documents/warning-letters/{$this->activeWarning->id}", 'local');

        $this->activeWarning->documents()->create([
            'title' => $this->doc_title,
            'description' => $this->doc_description ?: null,
            'file_path' => $path,
            'file_name' => $this->doc_file->getClientOriginalName(),
            'mime_type' => $this->doc_file->getMimeType(),
            'file_size' => $this->doc_file->getSize(),
            'category' => 'warning_letter',
            'visibility' => 'restricted',
            'employee_id' => $this->activeWarning->employee_id,
            'requires_acknowledgement' => true,
            'uploaded_by' => Auth::id(),
        ]);

        $this->showDocUploadModal = false;
        $this->viewWarning($this->activeWarning->id);
        \Flux::toast('Document uploaded and linked to warning letter.', variant: 'success');
    }

    public function deleteDocument(int $documentId): void
    {
        abort_unless($this->canManageWarnings(), 403);
        $this->assertWarningInReach();

        $document = Document::where('documentable_type', WarningLetter::class)
            ->where('documentable_id', $this->activeWarning->id)
            ->findOrFail($documentId);

        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        $this->viewWarning($this->activeWarning->id);
        \Flux::toast('Document deleted.', variant: 'warning');
    }

    public function generatePdf(WarningService $warningService)
    {
        abort_unless($this->canManageWarnings(), 403);
        $this->assertWarningInReach();

        $warningService->generatePdf($this->activeWarning, Auth::user());

        $this->viewWarning($this->activeWarning->id);
        \Flux::toast('Warning letter PDF generated and stored in Documents.', variant: 'success');
    }

    public function escalateWarning(WarningService $warningService)
    {
        $this->validate([
            'reason' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        // Disciplinary action is decided about someone else, inside your reach
        // (the service stays callable by the automated late-warning job).
        app(ApprovalGuard::class)->assertCanDecide(Auth::user(), $this->activeWarning->employee, 'manage_warning_letters');

        try {
            $warningService->escalate($this->activeWarning, Auth::user(), [
                'reason' => $this->reason,
                'description' => $this->description,
            ]);
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->showEscalateForm = false;
        $this->showViewModal = false;
        \Flux::toast('Warning escalated successfully.', variant: 'success');
    }

    public function closeWarning(WarningService $warningService)
    {
        abort_unless($this->canManageWarnings(), 403);
        $this->assertWarningInReach();

        $this->validate([
            'close_comment' => 'required|string',
        ]);

        try {
            $warningService->close($this->activeWarning, Auth::user(), $this->close_comment);
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->showCloseForm = false;
        $this->viewWarning($this->activeWarning->id); // refresh
        \Flux::toast('Warning closed successfully.', variant: 'success');
    }

    public function render()
    {
        $user = Auth::user();
        abort_unless($this->canManageWarnings() || $user->canReviewPerformance(), 403);

        $reach = $this->canManageWarnings() ? app(ScopeResolver::class)->employeeIds($user, 'manage_warning_letters') : [];

        $query = WarningLetter::with(['employee.user', 'employee.jobTitle', 'issuedBy'])
            ->when($this->search, function ($q) {
                $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$this->search}%"));
            })
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->type, fn ($q) => $q->where('warning_type', $this->type));

        // Letters about people in the viewer's reach, or ones they issued
        // (warning_letters.issued_by holds the issuing user's id).
        if ($reach !== null) {
            $query->where(fn ($q) => $q->whereIn('employee_id', $reach)->orWhere('issued_by', $user->id));
        }

        return view('livewire.performance.warning-letters', [
            'warnings' => $query->latest()->paginate(15),
            'employees' => Employee::with('user')->where('status', 'active')
                ->when($reach !== null, fn ($q) => $q->whereIn('id', $reach))
                ->whereKeyNot($user->employee?->id ?? 0)
                ->get(),
        ])->layout('layouts.app', ['title' => 'Warning Letters']);
    }
}
