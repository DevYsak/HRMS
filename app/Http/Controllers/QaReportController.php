<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\QaChecklistItem;
use App\Models\QaEvidence;
use App\Models\QaFinding;
use App\Models\QaTestCase;
use App\Models\QaTestRun;
use App\Services\Audit\AuditService;
use App\Services\Qa\BuildInfo;
use App\Services\Qa\QaFilters;
use App\Services\Qa\QaReadiness;
use App\Services\Qa\RoleAccessMatrix;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * QA & Audit outputs: the readiness report (printable page or PDF), CSV
 * exports with the page's filters, and private evidence downloads.
 */
class QaReportController extends Controller
{
    public function report(Request $request, QaReadiness $readiness, RoleAccessMatrix $matrix): Response
    {
        $filters = QaFilters::from($request->query());
        $data = [
            'summary' => $readiness->summary(),
            'phaseRows' => $readiness->phases(),
            'cases' => $filters->cases()->get(),
            'findings' => $filters->findings()->get(),
            'fixes' => $filters->fixes()->get(),
            'runs' => $filters->runs()->limit(20)->get(),
            'matrix' => $matrix->build(),
            'moduleCells' => QaChecklistItem::where('category', QaChecklistItem::MODULE)->get()->keyBy(fn ($i) => $i->subject.'|'.$i->dimension),
            'demoCells' => QaChecklistItem::where('category', QaChecklistItem::DEMO)->get()->keyBy(fn ($i) => $i->subject.'|'.$i->dimension),
            'responsiveDone' => QaChecklistItem::where('category', QaChecklistItem::RESPONSIVE)->count(),
            'filters' => $filters->toArray(),
            'generatedBy' => $request->user()->name,
            'generatedAt' => now(),
            'commit' => app(BuildInfo::class)->commit(),
        ];

        $pdf = $request->query('format') === 'pdf';
        AuditLog::record(QaTestRun::make(), 'exported', null, ['report' => 'qa_readiness', 'format' => $pdf ? 'pdf' : 'html', 'filters' => $filters->toArray()],
            context: ['module' => 'qa_audit', 'category' => AuditService::QA, 'event' => 'QA_REPORT_EXPORTED']);

        if ($pdf) {
            return Pdf::loadView('pdf.qa-report', [...$data, 'printable' => false])->setPaper('a4', 'landscape')
                ->download('nexcore-qa-readiness-report_'.now()->format('Ymd-Hi').'.pdf');
        }

        return response()->view('pdf.qa-report', [...$data, 'printable' => true]);
    }

    public function export(Request $request, string $dataset): StreamedResponse
    {
        $filters = QaFilters::from($request->query());

        [$headers, $rows] = match ($dataset) {
            'cases' => [
                ['Test ID', 'Phase', 'Module', 'Feature', 'Role', 'Scope', 'Title', 'Expected', 'Severity', 'Status', 'Last tested', 'Route', 'Automated test'],
                $filters->cases()->get()->map(fn (QaTestCase $c) => [$c->code, $c->phase?->number, $c->module, $c->feature, $c->role, $c->scope, $c->title, $c->expected_result,
                    $c->severity->label(), $c->status->label(), $c->last_tested_at?->format('Y-m-d H:i'), $c->route_name, $c->automated_reference]),
            ],
            'findings' => [
                ['Finding ID', 'Phase', 'Kind', 'Module', 'Title', 'Severity', 'Status', 'Role', 'Scope', 'Route / page', 'Related test', 'Root cause', 'Fix commits', 'Retest result', 'Found', 'Closed'],
                $filters->findings()->get()->map(fn (QaFinding $f) => [$f->code, $f->phase?->number, QaFinding::KINDS[$f->kind] ?? $f->kind, $f->module, $f->title, $f->severity->label(), $f->status->label(),
                    $f->role_affected, $f->scope_affected, $f->route ?? $f->page, $f->testCase?->code, $f->root_cause, $f->fixes->map->shortHash()->implode(' '), $f->retest_result,
                    $f->found_at?->format('Y-m-d'), $f->closed_at?->format('Y-m-d')]),
            ],
            'fixes' => [
                ['Commit', 'Message', 'Finding', 'Finding title', 'Tests', 'Files', 'Environment', 'Fixed at', 'Note'],
                $filters->fixes()->get()->map(fn ($x) => [$x->commit_hash, $x->commit_message, $x->finding?->code, $x->finding?->title, implode(' ', $x->test_case_codes ?? []),
                    implode(' ', $x->files_changed ?? []), $x->environment, $x->fixed_at?->format('Y-m-d H:i'), $x->developer_note]),
            ],
            'runs' => [
                ['Run', 'Suite', 'Environment', 'Commit', 'Total', 'Passed', 'Failed', 'Skipped', 'Known baseline', 'New regressions', 'Duration (s)', 'Result', 'Run at'],
                $filters->runs()->get()->map(fn (QaTestRun $r) => [$r->name, $r->suite, $r->environment, $r->commit, $r->total, $r->passed, $r->failed, $r->skipped,
                    $r->known_baseline, $r->new_regressions, $r->duration_seconds, $r->resultLabel(), $r->run_at->format('Y-m-d H:i')]),
            ],
            default => abort(404),
        };

        AuditLog::record(QaTestRun::make(), 'exported', null, ['export' => "qa_{$dataset}", 'rows' => $rows->count(), 'filters' => $filters->toArray()],
            context: ['module' => 'qa_audit', 'category' => AuditService::QA, 'event' => 'QA_DATA_EXPORTED']);

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => $this->csvSafe($v), $row));
            }
            fclose($out);
        }, "nexcore-qa-{$dataset}_".now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function evidence(QaEvidence $evidence): Response
    {
        abort_if($evidence->path === null || ! Storage::disk(QaEvidence::DISK)->exists($evidence->path), 404);

        return Storage::disk(QaEvidence::DISK)->download($evidence->path, $evidence->original_name ?? basename($evidence->path));
    }

    /** Neutralise spreadsheet formulas in exported text. */
    private function csvSafe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
