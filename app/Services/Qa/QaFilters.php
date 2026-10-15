<?php

namespace App\Services\Qa;

use App\Models\QaFinding;
use App\Models\QaFixLink;
use App\Models\QaTestCase;
use App\Models\QaTestRun;
use Illuminate\Database\Eloquent\Builder;

/**
 * One set of filters for the QA page, the CSV export and the report:
 * phase, module, role, status, severity, environment, date range, commit,
 * and a free search over Test ID, Finding ID, commit, module, title, route.
 */
class QaFilters
{
    /** @var array<int, string> */
    public const KEYS = ['search', 'phase', 'module', 'role', 'status', 'severity', 'environment', 'from', 'to', 'commit'];

    /** @param  array<string, mixed>  $filters */
    public function __construct(private readonly array $filters = []) {}

    /** @param  array<string, mixed>  $input */
    public static function from(array $input): self
    {
        return new self(collect($input)->only(self::KEYS)->map(fn ($v) => is_string($v) ? trim($v) : $v)->filter(fn ($v) => $v !== null && $v !== '')->all());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->filters;
    }

    private function get(string $key): ?string
    {
        return isset($this->filters[$key]) ? (string) $this->filters[$key] : null;
    }

    /** @return Builder<QaTestCase> */
    public function cases(): Builder
    {
        $search = $this->get('search');
        $commit = $this->get('commit');

        return QaTestCase::query()
            ->with('phase:id,number,name')
            ->when($this->get('phase'), fn ($q, $v) => $q->where('qa_phase_id', $v))
            ->when($this->get('module'), fn ($q, $v) => $q->where('module', $v))
            ->when($this->get('role'), fn ($q, $v) => $q->where('role', 'like', "%{$v}%"))
            ->when($this->get('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($this->get('severity'), fn ($q, $v) => $q->where('severity', $v))
            ->when($this->get('environment'), fn ($q, $v) => $q->whereHas('results', fn ($r) => $r->where('environment', $v)))
            ->when($this->get('from'), fn ($q, $v) => $q->whereDate('last_tested_at', '>=', $v))
            ->when($this->get('to'), fn ($q, $v) => $q->whereDate('last_tested_at', '<=', $v))
            ->when($commit, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('results', fn ($r) => $r->where('commit', 'like', "{$commit}%"))
                ->orWhereIn('code', $this->caseCodesFixedBy($commit))))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('code', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhere('module', 'like', "%{$search}%")
                ->orWhere('route_name', 'like', "%{$search}%")
                ->orWhere('automated_reference', 'like', "%{$search}%")
                ->orWhereHas('results', fn ($r) => $r->where('commit', 'like', "{$search}%"))
                ->orWhereHas('findings', fn ($f) => $f->where('code', 'like', "%{$search}%"))))
            ->orderBy('code');
    }

    /** @return Builder<QaFinding> */
    public function findings(?string $kind = null): Builder
    {
        $search = $this->get('search');

        return QaFinding::query()
            ->with(['phase:id,number,name', 'testCase:id,code', 'fixes'])
            ->when($kind === 'ui', fn ($q) => $q->where('kind', 'ui'))
            ->when($this->get('phase'), fn ($q, $v) => $q->where('qa_phase_id', $v))
            ->when($this->get('module'), fn ($q, $v) => $q->where('module', $v))
            ->when($this->get('role'), fn ($q, $v) => $q->where('role_affected', 'like', "%{$v}%"))
            ->when($this->get('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($this->get('severity'), fn ($q, $v) => $q->where('severity', $v))
            ->when($this->get('environment'), fn ($q, $v) => $q->where('environment', $v))
            ->when($this->get('from'), fn ($q, $v) => $q->whereDate('found_at', '>=', $v))
            ->when($this->get('to'), fn ($q, $v) => $q->whereDate('found_at', '<=', $v))
            ->when($this->get('commit'), fn ($q, $v) => $q->whereHas('fixes', fn ($f) => $f->where('commit_hash', 'like', "{$v}%")))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('code', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhere('module', 'like', "%{$search}%")
                ->orWhere('route', 'like', "%{$search}%")
                ->orWhere('page', 'like', "%{$search}%")
                ->orWhereHas('fixes', fn ($f) => $f->where('commit_hash', 'like', "{$search}%"))
                ->orWhereHas('testCase', fn ($t) => $t->where('code', 'like', "%{$search}%"))))
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('code');
    }

    /** @return Builder<QaFixLink> */
    public function fixes(): Builder
    {
        $search = $this->get('search');

        return QaFixLink::query()
            ->with('finding:id,code,title,module,severity,status')
            ->when($this->get('commit'), fn ($q, $v) => $q->where('commit_hash', 'like', "{$v}%"))
            ->when($this->get('environment'), fn ($q, $v) => $q->where('environment', $v))
            ->when($this->get('module'), fn ($q, $v) => $q->whereHas('finding', fn ($f) => $f->where('module', $v)))
            ->when($this->get('from'), fn ($q, $v) => $q->whereDate('fixed_at', '>=', $v))
            ->when($this->get('to'), fn ($q, $v) => $q->whereDate('fixed_at', '<=', $v))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('commit_hash', 'like', "{$search}%")
                ->orWhere('commit_message', 'like', "%{$search}%")
                ->orWhereHas('finding', fn ($f) => $f->where('code', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%"))))
            ->latest('fixed_at');
    }

    /** @return Builder<QaTestRun> */
    public function runs(): Builder
    {
        return QaTestRun::query()
            ->when($this->get('phase'), fn ($q, $v) => $q->where('qa_phase_id', $v))
            ->when($this->get('environment'), fn ($q, $v) => $q->where('environment', $v))
            ->when($this->get('commit'), fn ($q, $v) => $q->where('commit', 'like', "{$v}%"))
            ->when($this->get('from'), fn ($q, $v) => $q->whereDate('run_at', '>=', $v))
            ->when($this->get('to'), fn ($q, $v) => $q->whereDate('run_at', '<=', $v))
            ->when($this->get('search'), fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%{$v}%")->orWhere('commit', 'like', "{$v}%")))
            ->latest('run_at')->latest('id');
    }

    /** @return array<int, string> */
    private function caseCodesFixedBy(string $commit): array
    {
        return QaFixLink::query()->where('commit_hash', 'like', "{$commit}%")->pluck('test_case_codes')
            ->flatten()->filter()->unique()->values()->all();
    }
}
