<?php

namespace App\Services\Qa;

use App\Enums\QaEnvironment;
use App\Enums\QaFindingStatus;
use App\Enums\QaPhaseStatus;
use App\Enums\QaTestStatus;
use App\Models\AuditLog;
use App\Models\QaChecklistItem;
use App\Models\QaEvidence;
use App\Models\QaFinding;
use App\Models\QaFixLink;
use App\Models\QaPhase;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write the QA & Audit tracker makes goes through here. It records QA
 * only: it never touches employee, attendance, leave, payroll or permission
 * data, and it never deletes — a result, finding, fix or retest is kept, and
 * each change lands in the activity log (category "qa") with old and new
 * values, so the trail failure → finding → fix → retest → verified survives.
 */
class QaTracker
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param  array<string, mixed>  $data */
    public function createTestCase(array $data, User $actor): QaTestCase
    {
        $case = QaTestCase::create([
            ...$data,
            'code' => strtoupper(trim((string) ($data['code'] ?? ''))) ?: $this->nextCode(QaTestCase::class, 'QA-T-'),
            'status' => QaTestStatus::NotTested,
            'created_by' => $actor->id,
        ]);

        $this->log('QA_TEST_CASE_CREATED', $case, null, $case->only(['code', 'module', 'title', 'severity']), $actor);

        return $case;
    }

    /** @param  array<string, mixed>  $data */
    public function updateTestCase(QaTestCase $case, array $data, User $actor): QaTestCase
    {
        unset($data['code'], $data['status'], $data['last_tested_at']);

        return $this->updateLogged($case, $data, 'QA_TEST_CASE_UPDATED', $actor);
    }

    /**
     * Record one outcome. FAIL and BLOCKED need a note. A pass after the case
     * last failed (or was waiting on a fix) closes the loop as FIXED / VERIFIED.
     */
    public function recordResult(
        QaTestCase $case,
        QaTestStatus $status,
        User $actor,
        QaEnvironment|string $environment,
        ?string $actualResult = null,
        ?string $note = null,
        ?string $commit = null,
        ?QaTestRun $run = null,
    ): QaTestResult {
        if ($status->requiresNote() && blank($note)) {
            throw ValidationException::withMessages(['note' => "A note is required when marking a test {$status->label()}."]);
        }

        if ($status === QaTestStatus::NotTested) {
            throw ValidationException::withMessages(['status' => 'Choose a result.']);
        }

        $previous = $case->status;
        $isRetest = in_array($previous, [QaTestStatus::Fail, QaTestStatus::Partial, QaTestStatus::Blocked, QaTestStatus::RetestRequired, QaTestStatus::FixedRetestPending], true);
        // A pass closes the loop when a fix was waiting for it, or when the case has failed before.
        $caseStatus = $status === QaTestStatus::Pass && $isRetest
            && ($previous === QaTestStatus::FixedRetestPending || $case->results()->where('status', QaTestStatus::Fail->value)->exists())
            ? QaTestStatus::FixedVerified
            : $status;

        return DB::transaction(function () use ($case, $status, $caseStatus, $actor, $environment, $actualResult, $note, $commit, $run, $isRetest, $previous) {
            $result = $case->results()->create([
                'qa_test_run_id' => $run?->id,
                'status' => $status,
                'actual_result' => $actualResult,
                'environment' => $environment instanceof QaEnvironment ? $environment->value : $environment,
                'commit' => $commit ?: null,
                'note' => $note,
                'tested_by' => $actor->id,
                'tested_by_name' => $actor->name,
                'tested_at' => now(),
                'is_retest' => $isRetest,
            ]);

            $case->update(['status' => $caseStatus, 'last_tested_at' => $result->tested_at]);

            $this->log($isRetest ? 'QA_TEST_RETESTED' : 'QA_TEST_RESULT_RECORDED', $case,
                ['status' => $previous->value], ['status' => $caseStatus->value, 'commit' => $commit, 'environment' => $result->environment], $actor, $note);

            return $result;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function openFinding(array $data, User $actor): QaFinding
    {
        $finding = QaFinding::create([
            ...$data,
            'code' => strtoupper(trim((string) ($data['code'] ?? ''))) ?: $this->nextCode(QaFinding::class, 'QA-F-'),
            'status' => QaFindingStatus::Open,
            'found_at' => $data['found_at'] ?? now(),
            'created_by' => $actor->id,
        ]);

        $this->log('QA_FINDING_OPENED', $finding, null, $finding->only(['code', 'module', 'title', 'severity', 'kind']), $actor);

        return $finding;
    }

    /** @param  array<string, mixed>  $data */
    public function updateFinding(QaFinding $finding, array $data, User $actor): QaFinding
    {
        unset($data['code'], $data['status'], $data['closed_at']);

        return $this->updateLogged($finding, $data, 'QA_FINDING_UPDATED', $actor);
    }

    /**
     * Move a finding along. Accepted Risk and Won't Fix need a note; so does
     * Verified (it is the retest result). A closing status stamps closed_at;
     * reopening clears it.
     */
    public function changeFindingStatus(QaFinding $finding, QaFindingStatus $status, User $actor, ?string $note = null): QaFinding
    {
        if (($status->requiresNote() || $status === QaFindingStatus::Verified) && blank($note)) {
            throw ValidationException::withMessages(['note' => "A note is required to mark a finding {$status->label()}."]);
        }

        $old = ['status' => $finding->status->value];
        $finding->status = $status;
        $finding->closed_at = $status->isOpen() ? null : ($finding->closed_at ?? now());

        if ($status === QaFindingStatus::Verified) {
            $finding->retest_result = $note;
        }

        $finding->save();
        $this->log('QA_FINDING_STATUS_CHANGED', $finding, $old, ['status' => $status->value], $actor, $note);

        return $finding;
    }

    /**
     * Link the commit that fixed a finding. The finding moves to Fixed (if it
     * was still open) and every linked test case to FIXED / RETEST PENDING —
     * the fix is not trusted until a retest passes.
     *
     * @param  array{commit_hash: string, commit_message?: ?string, files_changed?: array<int, string>, test_case_codes?: array<int, string>, developer_note?: ?string, environment?: ?string, fixed_at?: mixed}  $data
     */
    public function linkFix(QaFinding $finding, array $data, User $actor): QaFixLink
    {
        $hash = strtolower(trim($data['commit_hash']));
        if (! preg_match('/^[0-9a-f]{7,40}$/', $hash)) {
            throw ValidationException::withMessages(['commit_hash' => 'Enter a git commit hash (7–40 hex characters).']);
        }

        $codes = array_values(array_filter(array_map(fn ($c) => strtoupper(trim((string) $c)), $data['test_case_codes'] ?? [])));

        return DB::transaction(function () use ($finding, $data, $hash, $codes, $actor) {
            $fix = $finding->fixes()->create([
                ...$data,
                'commit_hash' => $hash,
                'test_case_codes' => $codes,
                'fixed_at' => $data['fixed_at'] ?? now(),
                'created_by' => $actor->id,
            ]);

            $this->log('QA_FIX_LINKED', $finding, null, ['commit' => $hash, 'tests' => $codes], $actor, $data['developer_note'] ?? null);

            if (in_array($finding->status, [QaFindingStatus::Open, QaFindingStatus::Investigating, QaFindingStatus::Fixing], true)) {
                $this->changeFindingStatus($finding, QaFindingStatus::Fixed, $actor);
            }

            foreach (QaTestCase::whereIn('code', $codes)->get() as $case) {
                if (in_array($case->status, [QaTestStatus::Fail, QaTestStatus::Partial, QaTestStatus::Blocked, QaTestStatus::RetestRequired, QaTestStatus::NotTested], true)) {
                    $old = ['status' => $case->status->value];
                    $case->update(['status' => QaTestStatus::FixedRetestPending]);
                    $this->log('QA_TEST_AWAITING_RETEST', $case, $old, ['status' => QaTestStatus::FixedRetestPending->value, 'commit' => $hash], $actor);
                }
            }

            return $fix;
        });
    }

    /**
     * Retest a fixed finding: a pass verifies it (and its test case), a fail
     * sends it back to Open. Either way the attempt is kept.
     */
    public function retestFinding(QaFinding $finding, bool $passed, User $actor, string $note, QaEnvironment|string $environment, ?string $commit = null): QaFinding
    {
        if (blank($note)) {
            throw ValidationException::withMessages(['note' => 'Describe the retest result.']);
        }

        return DB::transaction(function () use ($finding, $passed, $actor, $note, $environment, $commit) {
            if ($finding->testCase !== null) {
                $this->recordResult($finding->testCase, $passed ? QaTestStatus::Pass : QaTestStatus::Fail, $actor, $environment, $note, $note, $commit);
            }

            $finding->update(['retest_result' => $note]);

            return $this->changeFindingStatus($finding, $passed ? QaFindingStatus::Verified : QaFindingStatus::Open, $actor, $note);
        });
    }

    /**
     * Record a test run (manual entry or an imported summary). The result is
     * derived: failures that are all known baseline are not regressions.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordRun(array $data, ?User $actor): QaTestRun
    {
        $failed = (int) ($data['failed'] ?? 0);
        $baseline = min($failed, (int) ($data['known_baseline'] ?? 0));
        $regressions = $failed - $baseline;

        $run = QaTestRun::create([
            ...$data,
            'failed' => $failed,
            'known_baseline' => $baseline,
            'new_regressions' => $regressions,
            'result' => QaTestRun::resultFor($failed, $regressions),
            'run_at' => $data['run_at'] ?? now(),
            'created_by' => $actor?->id,
        ]);

        $this->log('QA_TEST_RUN_RECORDED', $run, null, $run->only(['name', 'environment', 'commit', 'total', 'passed', 'failed', 'known_baseline', 'new_regressions', 'result']), $actor);

        return $run;
    }

    /** @param  array{status?: string, owner?: ?string, started_at?: ?string, completed_at?: ?string, notes?: ?string}  $data */
    public function updatePhase(QaPhase $phase, array $data, User $actor): QaPhase
    {
        if (($data['status'] ?? null) === QaPhaseStatus::Completed->value && blank($data['completed_at'] ?? null)) {
            $data['completed_at'] = $phase->completed_at?->toDateString() ?? now()->toDateString();
        }
        if (($data['status'] ?? null) === QaPhaseStatus::InProgress->value && blank($data['started_at'] ?? null)) {
            $data['started_at'] = $phase->started_at?->toDateString() ?? now()->toDateString();
        }

        $phase->fill($data);
        $dirty = array_keys($phase->getDirty());
        if ($dirty !== []) {
            $old = collect($dirty)->mapWithKeys(fn ($k) => [$k => $this->plain($phase->getOriginal($k))])->all();
            $phase->save();
            $this->log('QA_PHASE_UPDATED', $phase, $old, collect($dirty)->mapWithKeys(fn ($k) => [$k => $this->plain($phase->getAttribute($k))])->all(), $actor);
        }

        return $phase;
    }

    /** Set one readiness cell (module × dimension, page × viewport/theme, role × demo step). */
    public function setChecklist(string $category, string $subject, string $dimension, string $status, User $actor, ?string $note = null, ?string $commit = null): QaChecklistItem
    {
        if (! array_key_exists($status, QaChecklistItem::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }

        if ($status === 'fail' && blank($note)) {
            throw ValidationException::withMessages(['note' => 'A note is required when marking FAIL.']);
        }

        $item = QaChecklistItem::firstOrNew(['category' => $category, 'subject' => $subject, 'dimension' => $dimension]);
        $old = $item->exists ? ['status' => $item->status] : null;
        $item->fill(['status' => $status, 'note' => $note, 'commit' => $commit ?: null, 'checked_by' => $actor->id, 'checked_at' => now()])->save();

        $this->log('QA_CHECKLIST_MARKED', $item, $old, ['category' => $category, 'subject' => $subject, 'dimension' => $dimension, 'status' => $status], $actor, $note);

        return $item;
    }

    /** Attach evidence. Files go to the private disk under qa-evidence/ only. */
    public function addEvidence(QaTestCase|QaFinding $record, string $kind, User $actor, ?UploadedFile $file = null, ?string $note = null): QaEvidence
    {
        if (! array_key_exists($kind, QaEvidence::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Unknown evidence type.']);
        }

        if ($file === null && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Attach a file or write a note.']);
        }

        $evidence = $record->evidence()->create([
            'kind' => $kind,
            'path' => $file?->store('qa-evidence/'.now()->format('Y/m'), QaEvidence::DISK),
            'original_name' => $file?->getClientOriginalName(),
            'note' => $note,
            'uploaded_by' => $actor->id,
        ]);

        $this->log('QA_EVIDENCE_ADDED', $record, null, ['evidence_id' => $evidence->id, 'kind' => $kind, 'file' => $evidence->original_name], $actor, $note);

        return $evidence;
    }

    /**
     * The change history of a QA record: who, when, old value, new value.
     *
     * @return Collection<int, AuditLog>
     */
    public function history(Model $record): Collection
    {
        return AuditLog::query()
            ->where('auditable_type', $record::class)
            ->where('auditable_id', $record->getKey())
            ->latest('id')
            ->get();
    }

    /** @param  array<string, mixed>  $data */
    private function updateLogged(QaTestCase|QaFinding $record, array $data, string $event, User $actor): QaTestCase|QaFinding
    {
        $record->fill($data);
        $dirty = array_keys($record->getDirty());

        if ($dirty === []) {
            return $record;
        }

        $old = collect($dirty)->mapWithKeys(fn ($k) => [$k => $this->plain($record->getOriginal($k))])->all();
        $record->save();
        $new = collect($dirty)->mapWithKeys(fn ($k) => [$k => $this->plain($record->getAttribute($k))])->all();

        $this->log($event, $record, $old, $new, $actor);

        return $record;
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i') : $value);
    }

    /** @param  class-string<Model>  $model */
    private function nextCode(string $model, string $prefix): string
    {
        $max = $model::query()->where('code', 'like', $prefix.'%')->pluck('code')
            ->map(fn (string $c) => (int) substr($c, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function log(string $event, Model $record, ?array $old, ?array $new, ?User $actor, ?string $reason = null): void
    {
        $this->audit->event($event, AuditService::QA, $record, old: $old, new: $new, reason: $reason, module: 'qa_audit', actor: $actor);
    }
}
