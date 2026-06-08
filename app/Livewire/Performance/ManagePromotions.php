<?php

namespace App\Livewire\Performance;

use App\Models\Department;
use App\Models\Document;
use App\Models\Employee;
use App\Models\PromotionRecommendation;
use App\Services\Performance\PromotionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class ManagePromotions extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $type = '';

    // Modals
    public bool $showCreateModal = false;

    public bool $showViewModal = false;

    public ?PromotionRecommendation $activeRecommendation = null;

    // Create form
    public $employee_id = '';

    public $recommendation_type = 'promotion';

    public $current_role = '';

    public $proposed_role = '';

    public $current_salary = '';

    public $proposed_salary = '';

    public $bonus_amount = '';

    public $target_department_id = '';

    public $justification = '';

    public $effective_date = '';

    // Review form
    public string $reviewComments = '';

    // Document upload form
    public bool $showDocUploadModal = false;

    public string $doc_title = '';

    public string $doc_description = '';

    public $doc_file = null;

    public function openCreateModal(): void
    {
        $this->checkPermission();
        $this->reset([
            'employee_id', 'current_role', 'proposed_role', 'current_salary',
            'proposed_salary', 'bonus_amount', 'target_department_id', 'justification', 'effective_date',
        ]);
        $this->recommendation_type = 'promotion';
        $this->resetErrorBag();
        $this->showCreateModal = true;
    }

    public function create(PromotionService $service): void
    {
        $this->checkPermission();

        $this->validate([
            'employee_id' => 'required|exists:employees,id',
            'recommendation_type' => 'required|in:promotion,salary_increment,bonus,role_change,dept_transfer',
            'current_role' => 'nullable|string|max:255',
            'proposed_role' => 'nullable|string|max:255',
            'current_salary' => 'nullable|numeric|min:0',
            'proposed_salary' => 'nullable|numeric|min:0',
            'bonus_amount' => 'nullable|numeric|min:0',
            'target_department_id' => 'nullable|exists:departments,id',
            'justification' => 'required|string|min:10|max:2000',
            'effective_date' => 'nullable|date',
        ]);

        $employee = Employee::findOrFail($this->employee_id);

        $service->recommend($employee, [
            'recommendation_type' => $this->recommendation_type,
            'current_role' => $this->current_role ?: null,
            'proposed_role' => $this->proposed_role ?: null,
            'current_salary' => $this->current_salary !== '' ? (float) $this->current_salary : null,
            'proposed_salary' => $this->proposed_salary !== '' ? (float) $this->proposed_salary : null,
            'bonus_amount' => $this->bonus_amount !== '' ? (float) $this->bonus_amount : null,
            'target_department_id' => $this->target_department_id ?: null,
            'justification' => $this->justification,
            'effective_date' => $this->effective_date ?: null,
        ], Auth::user());

        $this->showCreateModal = false;
        \Flux::toast('Recommendation submitted for HR review.', variant: 'success');
    }

    public function viewRecommendation(int $id): void
    {
        $this->checkPermission();
        $this->activeRecommendation = PromotionRecommendation::with(['employee.user', 'employee.jobTitle', 'recommendedBy', 'targetDepartment', 'documents'])->findOrFail($id);
        $this->reviewComments = '';
        $this->showDocUploadModal = false;
        $this->resetErrorBag();
        $this->showViewModal = true;
    }

    public function openDocUploadModal(): void
    {
        $this->checkPermission();
        $this->reset(['doc_title', 'doc_description', 'doc_file']);
        $this->resetErrorBag();
        $this->showDocUploadModal = true;
    }

    public function uploadDocument(): void
    {
        $this->checkPermission();

        $this->validate([
            'doc_title' => 'required|string|max:255',
            'doc_description' => 'nullable|string|max:1000',
            'doc_file' => 'required|file|max:10240',
        ]);

        $path = $this->doc_file->store("documents/promotion/{$this->activeRecommendation->id}", 'local');

        $this->activeRecommendation->documents()->create([
            'title' => $this->doc_title,
            'description' => $this->doc_description ?: null,
            'file_path' => $path,
            'file_name' => $this->doc_file->getClientOriginalName(),
            'mime_type' => $this->doc_file->getMimeType(),
            'file_size' => $this->doc_file->getSize(),
            'category' => 'promotion',
            'visibility' => 'restricted',
            'employee_id' => $this->activeRecommendation->employee_id,
            'requires_acknowledgement' => true,
            'uploaded_by' => Auth::id(),
        ]);

        $this->showDocUploadModal = false;
        $this->viewRecommendation($this->activeRecommendation->id);
        \Flux::toast('Document uploaded and stored in employee documents.', variant: 'success');
    }

    public function deleteDocument(int $documentId): void
    {
        $this->checkPermission();

        $document = Document::where('documentable_type', PromotionRecommendation::class)
            ->where('documentable_id', $this->activeRecommendation->id)
            ->findOrFail($documentId);

        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        $this->viewRecommendation($this->activeRecommendation->id);
        \Flux::toast('Document deleted.', variant: 'warning');
    }

    public function approve(PromotionService $service): void
    {
        $this->authorizeStageAction();

        $this->validate(['reviewComments' => 'required|string|min:5|max:1000']);

        $service->approve($this->activeRecommendation, Auth::user(), $this->reviewComments);

        \Flux::toast('Recommendation advanced.', variant: 'success');
        $this->viewRecommendation($this->activeRecommendation->id);
    }

    public function reject(PromotionService $service): void
    {
        $this->authorizeStageAction();

        $this->validate(['reviewComments' => 'required|string|min:5|max:1000']);

        try {
            $service->reject($this->activeRecommendation, Auth::user(), $this->reviewComments);
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Recommendation rejected.', variant: 'warning');
        $this->viewRecommendation($this->activeRecommendation->id);
    }

    public function canActOnCurrentStage(): bool
    {
        if (! $this->activeRecommendation || ! in_array($this->activeRecommendation->status, ['pending_hr', 'pending_dept_head', 'pending_super_admin'], true)) {
            return false;
        }

        $user = Auth::user();

        return match ($this->activeRecommendation->current_reviewer_stage) {
            'hr' => $user->canManageEmployees(),
            'dept_head' => $user->isDepartmentHead(),
            'super_admin' => $user->isSuperAdmin(),
            default => false,
        };
    }

    protected function authorizeStageAction(): void
    {
        if (! $this->activeRecommendation || ! $this->canActOnCurrentStage()) {
            abort(403);
        }
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->type = '';
        $this->resetPage();
    }

    public function checkPermission(): void
    {
        abort_unless(Auth::user()->canReviewPerformance(), 403);
    }

    public function render()
    {
        $this->checkPermission();
        $user = Auth::user();

        $query = PromotionRecommendation::with(['employee.user', 'employee.jobTitle', 'recommendedBy'])
            ->when($this->search, function ($q) {
                $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$this->search}%"));
            })
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->type, fn ($q) => $q->where('recommendation_type', $this->type));

        if (! $user->canManageEmployees()) {
            $query->where('recommended_by', $user->id);
        }

        $employees = $user->canManageEmployees()
            ? Employee::with('user')->where('status', 'active')->get()
            : Employee::with('user')->where('manager_id', $user->id)->where('status', 'active')->get();

        return view('livewire.performance.manage-promotions', [
            'recommendations' => $query->latest()->paginate(15),
            'employees' => $employees,
            'departments' => Department::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Manage Promotions & Rewards']);
    }
}
