<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithPagination;

class DocumentManager extends Component
{
    use WithPagination;

    public string $filterCategory = '';

    public string $filterSearch = '';

    public function updatingFilterSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterCategory(): void
    {
        $this->resetPage();
    }

    public function acknowledge(int $documentId): void
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return;
        }

        DocumentAcknowledgement::firstOrCreate(
            ['document_id' => $documentId, 'employee_id' => $employee->id],
            ['acknowledged_at' => now(), 'ip_address' => request()->ip()]
        );

        \Flux::toast('Document acknowledged.');
    }

    public function delete(int $documentId): void
    {
        abort_unless(Auth::user()->canManageDocuments(), 403);

        $doc = Document::findOrFail($documentId);
        Storage::disk('local')->delete($doc->file_path);
        $doc->delete();

        \Flux::toast('Document deleted.', variant: 'warning');
        $this->resetPage();
    }

    public function render()
    {
        $user = Auth::user();
        $employee = $user->employee;
        $canManage = $user->canManageDocuments();

        $query = Document::with(['uploader', 'employee.user'])
            ->when($this->filterCategory, fn ($q) => $q->where('category', $this->filterCategory))
            ->when($this->filterSearch, fn ($q) => $q->where('title', 'like', "%{$this->filterSearch}%"))
            ->whereNull('parent_id');

        if (! $canManage) {
            $query->where(function ($q) use ($employee, $user) {
                $q->where('visibility', 'all')->orWhere('category', 'policy');
                if ($employee) {
                    $q->orWhere('employee_id', $employee->id);
                }
                if ($user->canApproveFinance()) {
                    $q->orWhere('category', 'payslip');
                }
            });
        }

        $documents = $query->latest()->paginate(15);

        $acknowledgedIds = $employee
            ? DocumentAcknowledgement::where('employee_id', $employee->id)->pluck('document_id')->all()
            : [];

        $employees = $canManage
            ? Employee::with('user')->where('status', 'active')->orderBy('id')->get()
            : collect();

        return view('livewire.documents.document-manager', compact(
            'documents', 'acknowledgedIds', 'canManage', 'employees'
        ))->layout('layouts.app', ['title' => 'Documents']);
    }
}
