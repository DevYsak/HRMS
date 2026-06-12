<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Component;
use Livewire\WithPagination;

class DocumentManager extends Component
{
    use WithPagination;

    public string $filterCategory = '';

    public string $filterSearch = '';

    public string $filterEmployee = '';   // employee_id (managers only)

    public string $filterExpiringFrom = '';

    public string $filterExpiringTo = '';

    public ?int $versionsFor = null;   // document id whose version history is open

    public ?int $previewId = null;     // document id being previewed

    public string $view = 'library';   // library | expiry | acknowledgements

    public function setView(string $view): void
    {
        $allowed = ['library'];

        if (Auth::user()->canManageDocuments()) {
            $allowed[] = 'expiry';
            $allowed[] = 'acknowledgements';
        }

        $this->view = in_array($view, $allowed, true) ? $view : 'library';
    }

    public function updatingFilterSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterCategory(): void
    {
        $this->resetPage();
    }

    public function updatingFilterEmployee(): void
    {
        $this->resetPage();
    }

    public function updatingFilterExpiringFrom(): void
    {
        $this->resetPage();
    }

    public function updatingFilterExpiringTo(): void
    {
        $this->resetPage();
    }

    public function showVersions(int $documentId): void
    {
        $this->assertCanAccess(Document::findOrFail($documentId));
        $this->versionsFor = $documentId;
    }

    public function closeVersions(): void
    {
        $this->versionsFor = null;
    }

    public function preview(int $documentId): void
    {
        $this->assertCanAccess(Document::findOrFail($documentId));
        $this->previewId = $documentId;
    }

    public function closePreview(): void
    {
        $this->previewId = null;
    }

    /**
     * Guard preview/version access with the same visibility rules used to
     * build the document list, so ids cannot be probed by URL/payload.
     */
    protected function assertCanAccess(Document $document): void
    {
        $user = Auth::user();

        if ($user->canManageDocuments()) {
            return;
        }

        $employee = $user->employee;

        $allowed = $document->visibility === 'all'
            || $document->category === 'policy'
            || ($employee && $document->employee_id === $employee->id)
            || ($document->category === 'payslip' && $user->canApproveFinance());

        abort_unless($allowed, 403);
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
            ->withCount('versions')
            ->when($this->filterCategory, fn ($q) => $q->where('category', $this->filterCategory))
            ->when($this->filterSearch, function ($q) {
                $term = "%{$this->filterSearch}%";
                $q->where(function ($sub) use ($term) {
                    $sub->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('file_name', 'like', $term)
                        ->orWhereHas('employee.user', fn ($u) => $u->where('name', 'like', $term));
                });
            })
            ->when($this->filterEmployee, fn ($q) => $q->where('employee_id', $this->filterEmployee))
            ->when($this->filterExpiringFrom, fn ($q) => $q->whereNotNull('expires_at')->whereDate('expires_at', '>=', $this->filterExpiringFrom))
            ->when($this->filterExpiringTo, fn ($q) => $q->whereNotNull('expires_at')->whereDate('expires_at', '<=', $this->filterExpiringTo))
            ->whereNull('parent_id');

        if (! $canManage) {
            $query->where(function ($q) use ($employee, $user) {
                $q->where('visibility', 'all')->orWhere('category', 'policy');
                if ($employee) {
                    // Own docs (personal, warning letters, PIPs, promotions, reviews)
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

        $expiry = ($canManage && $this->view === 'expiry')
            ? $this->expiryBuckets()
            : null;

        $ackTracker = ($canManage && $this->view === 'acknowledgements')
            ? $this->acknowledgementTracker()
            : null;

        // Version history — full chain (root + child versions), newest first.
        $versionList = null;
        if ($this->versionsFor) {
            $doc = Document::with(['versions.uploader', 'uploader'])->find($this->versionsFor);
            if ($doc) {
                $this->assertCanAccess($doc);
                $versionList = collect([$doc])->concat($doc->versions)->sortByDesc('version')->values();
            } else {
                $this->versionsFor = null;
            }
        }

        // Inline preview payload (PDF in an iframe, images in <img>).
        $preview = null;
        if ($this->previewId) {
            $doc = Document::find($this->previewId);
            if ($doc) {
                $this->assertCanAccess($doc);
                $mime = (string) ($doc->mime_type ?? '');
                $preview = [
                    'document' => $doc,
                    'url' => URL::temporarySignedRoute('documents.view', now()->addMinutes(5), ['document' => $doc->id]),
                    'kind' => str_contains($mime, 'pdf') ? 'pdf' : (str_starts_with($mime, 'image/') ? 'image' : 'other'),
                ];
            } else {
                $this->previewId = null;
            }
        }

        return view('livewire.documents.document-manager', compact(
            'documents', 'acknowledgedIds', 'canManage', 'employees', 'expiry', 'ackTracker', 'versionList', 'preview'
        ))->layout('layouts.app', ['title' => 'Documents']);
    }

    /**
     * Group top-level documents with an expiry date into 30/60/90-day windows
     * plus an already-expired bucket.
     *
     * @return array{expired: Collection, d30: Collection, d60: Collection, d90: Collection}
     */
    protected function expiryBuckets(): array
    {
        $today = now()->startOfDay();
        $base = fn () => Document::with(['employee.user', 'uploader'])
            ->whereNull('parent_id')
            ->whereNotNull('expires_at');

        return [
            'expired' => $base()->whereDate('expires_at', '<', $today)->orderByDesc('expires_at')->get(),
            'd30' => $base()->whereDate('expires_at', '>=', $today)->whereDate('expires_at', '<=', $today->addDays(30))->orderBy('expires_at')->get(),
            'd60' => $base()->whereDate('expires_at', '>', $today->addDays(30))->whereDate('expires_at', '<=', $today->addDays(60))->orderBy('expires_at')->get(),
            'd90' => $base()->whereDate('expires_at', '>', $today->addDays(60))->whereDate('expires_at', '<=', $today->addDays(90))->orderBy('expires_at')->get(),
        ];
    }

    /**
     * For every document requiring acknowledgement, resolve its expected
     * audience (by visibility) and report confirmed vs pending employees.
     *
     * @return Collection<int, array{document: Document, expected: int, acknowledged: int, pending: int, pendingNames: Collection}>
     */
    protected function acknowledgementTracker(): Collection
    {
        return Document::with(['department', 'employee.user'])
            ->whereNull('parent_id')
            ->where('requires_acknowledgement', true)
            ->latest()
            ->get()
            ->map(function (Document $doc) {
                $audience = match ($doc->visibility) {
                    'individual' => $doc->employee_id ? collect([$doc->employee_id]) : collect(),
                    'department' => Employee::where('status', 'active')->where('department_id', $doc->department_id)->pluck('id'),
                    default => Employee::where('status', 'active')->pluck('id'),
                };

                $acknowledgedIds = DocumentAcknowledgement::where('document_id', $doc->id)->pluck('employee_id');
                $pendingIds = $audience->diff($acknowledgedIds);

                return [
                    'document' => $doc,
                    'expected' => $audience->count(),
                    'acknowledged' => $audience->intersect($acknowledgedIds)->count(),
                    'pending' => $pendingIds->count(),
                    'pendingNames' => Employee::with('user')->whereIn('id', $pendingIds->take(12))->get()
                        ->map(fn ($e) => $e->user?->name)->filter()->values(),
                ];
            });
    }
}
