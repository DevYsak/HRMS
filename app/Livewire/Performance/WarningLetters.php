<?php

namespace App\Livewire\Performance;

use App\Models\Document;
use App\Models\Employee;
use App\Models\WarningLetter;
use App\Services\Performance\WarningService;
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

    public function mount()
    {
        $this->issue_date = now()->format('Y-m-d');
    }

    public function openIssueModal()
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
        $this->reset(['employee_id', 'warning_type', 'reason', 'description', 'next_review_date']);
        $this->issue_date = now()->format('Y-m-d');
        $this->showIssueModal = true;
    }

    public function issueWarning(WarningService $warningService)
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $this->validate([
            'employee_id' => 'required|exists:employees,id',
            'warning_type' => 'required|in:verbal,written,final,pip',
            'reason' => 'required|string|max:255',
            'description' => 'nullable|string',
            'issue_date' => 'required|date',
            'next_review_date' => 'nullable|date|after:issue_date',
        ]);

        $employee = Employee::findOrFail($this->employee_id);

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
        abort_unless($user->canManageEmployees() || $user->employee?->id === WarningLetter::findOrFail($id)->issued_by, 403);

        $this->activeWarning = WarningLetter::with(['employee.user', 'issuedBy', 'closedBy', 'acknowledgements', 'documents'])->findOrFail($id);
        $this->showCloseForm = false;
        $this->showEscalateForm = false;
        $this->showViewModal = true;
    }

    public function openDocUploadModal(): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
        $this->reset(['doc_title', 'doc_description', 'doc_file']);
        $this->resetErrorBag();
        $this->showDocUploadModal = true;
    }

    public function uploadDocument(): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $this->validate([
            'doc_title' => 'required|string|max:255',
            'doc_description' => 'nullable|string|max:1000',
            'doc_file' => 'required|file|max:10240',
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
        abort_unless(Auth::user()->canManageEmployees(), 403);

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
        abort_unless(Auth::user()->canManageEmployees(), 403);

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
        abort_unless($user->canManageEmployees() || $user->canReviewPerformance(), 403);

        $query = WarningLetter::with(['employee.user', 'employee.jobTitle', 'issuedBy'])
            ->when($this->search, function ($q) {
                $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$this->search}%"));
            })
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->type, fn ($q) => $q->where('warning_type', $this->type));

        if (! $user->canManageEmployees() && $user->employee) {
            $query->where('issued_by', $user->employee->id);
        }

        return view('livewire.performance.warning-letters', [
            'warnings' => $query->latest()->paginate(15),
            'employees' => Employee::with('user')->where('status', 'active')->get(),
        ])->layout('layouts.app', ['title' => 'Warning Letters']);
    }
}
