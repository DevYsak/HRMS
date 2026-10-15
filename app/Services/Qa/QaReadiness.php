<?php

namespace App\Services\Qa;

use App\Enums\QaFindingStatus;
use App\Enums\QaPhaseStatus;
use App\Enums\QaSeverity;
use App\Enums\QaTestStatus;
use App\Models\QaChecklistItem;
use App\Models\QaFinding;
use App\Models\QaPhase;
use App\Models\QaTestCase;
use App\Models\QaTestRun;
use Illuminate\Support\Collection;

/**
 * Portal readiness from the QA records — never a PASS count alone.
 *
 * Scores (0–100) are pass rates over what SHOULD be checked, so an untested
 * cell counts against them, minus a penalty for every open finding in that
 * area. Known-baseline failures are reported separately and never counted as
 * regressions. The level is gated by blockers first, thresholds second:
 *  - a failed migration run (blocks deployment) or new regressions in the
 *    latest run → NOT READY;
 *  - an open Critical finding blocks UAT and beyond;
 *  - an open High security/RBAC finding blocks Staging and beyond;
 *  - any open Critical or High finding blocks Production.
 * A low-severity cosmetic UI finding blocks nothing below Production.
 */
class QaReadiness
{
    public const NOT_READY = 'not_ready';

    public const INTERNAL_QA = 'internal_qa';

    public const UAT = 'uat';

    public const STAGING = 'staging';

    public const PRODUCTION = 'production';

    /** @var array<string, string> */
    public const LEVELS = [
        self::NOT_READY => 'NOT READY',
        self::INTERNAL_QA => 'READY FOR INTERNAL QA',
        self::UAT => 'READY FOR UAT',
        self::STAGING => 'READY FOR STAGING',
        self::PRODUCTION => 'READY FOR PRODUCTION',
    ];

    /** Modules on the readiness grid. */
    public const MODULES = [
        'Dashboard', 'Employees', 'Organization', 'Attendance', 'Biometric', 'Leave', 'Overtime', 'WFH', 'Performance',
        'Payroll', 'Expenses', 'Documents', 'Reports', 'Notifications', 'AI', 'Settings', 'Roles & Permissions', 'Help Guide',
    ];

    /** @var array<string, string> */
    public const MODULE_DIMENSIONS = [
        'functional' => 'Functional', 'rbac' => 'RBAC', 'scope' => 'Scope', 'responsive' => 'Responsive',
        'dark_mode' => 'Dark Mode', 'tests' => 'Tests', 'documentation' => 'Documentation', 'demo_ready' => 'Demo Ready',
    ];

    /** @var array<int, int> */
    public const VIEWPORTS = [320, 375, 390, 430, 768, 1024, 1280, 1366, 1440, 1920];

    /** Pages on the responsive matrix. */
    public const RESPONSIVE_PAGES = [
        'Main Dashboard', 'Team View', 'My Attendance', 'Live Attendance', 'My Leave', 'Team Leave', 'Employees',
        'Employee 360', 'Payroll', 'Performance', 'Reports', 'Settings', 'QA & Audit',
    ];

    /** @var array<string, string> */
    public const DEMO_ROLES = [
        'super_admin' => 'Super Admin', 'hr_admin' => 'HR Admin', 'director' => 'Director', 'department_head' => 'Department Head',
        'manager' => 'Manager', 'coordinator' => 'Coordinator', 'finance' => 'Finance', 'employee' => 'Employee',
    ];

    /** @var array<string, string> */
    public const DEMO_STEPS = [
        'account' => 'Demo account', 'login' => 'Login', 'landing' => 'Landing page', 'navigation' => 'Navigation',
        'sample_data' => 'Sample data', 'attendance' => 'Attendance', 'leave' => 'Leave', 'overtime' => 'Overtime',
        'performance' => 'Performance', 'reports' => 'Reports', 'ai' => 'AI', 'guide' => 'Guide', 'ready' => 'Ready',
    ];

    /** Test-case modules that measure access control. */
    private const RBAC_MODULES = ['RBAC', 'Navigation', 'Scope', 'Dashboards'];

    private const SECURITY_MODULES = ['Security', 'AI', 'Exports', 'Notifications'];

    /** Score penalty per open finding, by severity. */
    private const PENALTY = ['critical' => 40, 'high' => 20, 'medium' => 8, 'low' => 3, 'info' => 0];

    /** @var array<string, mixed>|null */
    private ?array $summary = null;

    /**
     * @return array{
     *     counts: array<string, int>,
     *     scores: array<string, int>,
     *     level: string, level_label: string,
     *     blockers: array<int, string>, next_steps: array<int, string>,
     *     latest_run: ?QaTestRun
     * }
     */
    public function summary(): array
    {
        if ($this->summary !== null) {
            return $this->summary;
        }

        $cases = QaTestCase::query()->get(['id', 'module', 'status']);
        $findings = QaFinding::query()->get(['id', 'kind', 'severity', 'status', 'module']);
        $openFindings = $findings->filter(fn (QaFinding $f) => $f->status->isOpen());
        $latestRun = QaTestRun::query()->where('suite', '!=', 'migration')->latest('run_at')->latest('id')->first();
        $failedMigration = QaTestRun::query()->where('suite', 'migration')->latest('run_at')->latest('id')->value('result') === QaTestRun::RESULT_FAIL;

        $byStatus = $cases->countBy(fn (QaTestCase $c) => $c->status->value);
        $counts = [
            'total' => $cases->count(),
            'passed' => ($byStatus['pass'] ?? 0) + ($byStatus['fixed_verified'] ?? 0),
            'failed' => $byStatus['fail'] ?? 0,
            'partial' => $byStatus['partial'] ?? 0,
            'blocked' => $byStatus['blocked'] ?? 0,
            'known_baseline' => $byStatus['known_baseline'] ?? 0,
            'not_tested' => $byStatus['not_tested'] ?? 0,
            'fixed' => $findings->whereIn('status', [QaFindingStatus::Fixed, QaFindingStatus::Verified])->count(),
            'pending_fix' => $openFindings->whereIn('status', [QaFindingStatus::Open, QaFindingStatus::Investigating, QaFindingStatus::Fixing])->count(),
            'retest_required' => ($byStatus['retest_required'] ?? 0) + ($byStatus['fixed_retest_pending'] ?? 0)
                + $findings->whereIn('status', [QaFindingStatus::Fixed, QaFindingStatus::RetestRequired])->count(),
            'open_findings' => $openFindings->count(),
            'open_critical' => $openFindings->where('severity', QaSeverity::Critical)->count(),
            'open_high' => $openFindings->where('severity', QaSeverity::High)->count(),
        ];

        $penalty = fn (Collection $open) => $open->sum(fn (QaFinding $f) => self::PENALTY[$f->severity->value]);

        $scores = [
            'overall' => $this->score($this->passRate($cases), $penalty($openFindings->where('severity', QaSeverity::Critical))),
            'security' => $this->score(
                $this->passRate($cases->filter(fn ($c) => in_array($c->module, self::SECURITY_MODULES, true))),
                $penalty($openFindings->where('kind', 'security')),
            ),
            'rbac' => $this->score(
                $this->passRate($cases->filter(fn ($c) => in_array($c->module, self::RBAC_MODULES, true))),
                $penalty($openFindings->where('kind', 'rbac')),
            ),
            'ui' => $this->score(
                $this->checklistRate(QaChecklistItem::RESPONSIVE, count(self::RESPONSIVE_PAGES) * count(self::VIEWPORTS) * 2),
                $penalty($openFindings->where('kind', 'ui')),
            ),
            'modules' => $this->score($this->checklistRate(QaChecklistItem::MODULE, count(self::MODULES) * count(self::MODULE_DIMENSIONS)), 0),
            'demo' => $this->score($this->checklistRate(QaChecklistItem::DEMO, count(self::DEMO_ROLES) * count(self::DEMO_STEPS)), 0),
        ];
        $scores['production'] = (int) round(collect($scores)->avg());

        [$level, $blockers, $next] = $this->level($counts, $scores, $openFindings, $latestRun, $failedMigration);
        if ($level === self::NOT_READY) {
            $scores['production'] = min($scores['production'], 25);
        } elseif ($counts['open_critical'] > 0 || $openFindings->contains(fn (QaFinding $f) => $f->severity === QaSeverity::High && $f->isSecurity())) {
            // A Critical, or a High security/RBAC finding, blocks production outright.
            $scores['production'] = min($scores['production'], 49);
        }

        return $this->summary = [
            'counts' => $counts,
            'scores' => $scores,
            'level' => $level,
            'level_label' => self::LEVELS[$level],
            'blockers' => $blockers,
            'next_steps' => $next,
            'latest_run' => $latestRun,
        ];
    }

    /** Phase rows with their counts. */
    public function phases(): Collection
    {
        return QaPhase::query()->withCount([
            'testCases',
            'testCases as passed_count' => fn ($q) => $q->whereIn('status', [QaTestStatus::Pass->value, QaTestStatus::FixedVerified->value]),
            'testCases as failed_count' => fn ($q) => $q->where('status', QaTestStatus::Fail->value),
            'findings',
            'findings as open_findings_count' => fn ($q) => $q->whereIn('status', QaFinding::openStatuses()),
        ])->with(['findings.fixes:id,qa_finding_id,commit_hash'])->orderBy('number')->get();
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $scores
     * @param  Collection<int, QaFinding>  $open
     * @return array{0: string, 1: array<int, string>, 2: array<int, string>}
     */
    private function level(array $counts, array $scores, Collection $open, ?QaTestRun $run, bool $failedMigration): array
    {
        $blockers = [];
        if ($failedMigration) {
            $blockers[] = 'The latest migration run failed — deployment is blocked.';
        }
        if ($run !== null && $run->new_regressions > 0) {
            $blockers[] = "The latest test run has {$run->new_regressions} new regression(s).";
        }
        if ($counts['total'] === 0) {
            $blockers[] = 'No test cases recorded yet.';
        }

        $highSecurity = $open->filter(fn (QaFinding $f) => $f->severity === QaSeverity::High && $f->isSecurity())->count();
        $highAny = $open->where('severity', QaSeverity::High)->count();
        $mediumSecurity = $open->filter(fn (QaFinding $f) => $f->severity === QaSeverity::Medium && $f->isSecurity())->count();
        $phasesOpen = QaPhase::query()->where('status', '!=', QaPhaseStatus::Completed->value)->count();

        $level = self::NOT_READY;
        foreach ([self::INTERNAL_QA, self::UAT, self::STAGING, self::PRODUCTION] as $candidate) {
            $unmet = $this->unmet($candidate, $counts, $scores, $blockers, $highSecurity, $highAny, $mediumSecurity, $phasesOpen);
            if ($unmet !== []) {
                return [$level, $blockers, $unmet];
            }
            $level = $candidate;
        }

        return [$level, $blockers, []];
    }

    /**
     * Unmet conditions for a level.
     *
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $scores
     * @param  array<int, string>  $blockers
     * @return array<int, string>
     */
    private function unmet(string $level, array $counts, array $scores, array $blockers, int $highSecurity, int $highAny, int $mediumSecurity, int $phasesOpen): array
    {
        $checks = match ($level) {
            self::INTERNAL_QA => [
                [$blockers === [], 'Clear the blockers above.'],
                [$scores['overall'] >= 50, 'Overall QA score must reach 50%.'],
            ],
            self::UAT => [
                [$counts['open_critical'] === 0, "Close {$counts['open_critical']} open Critical finding(s)."],
                [$scores['overall'] >= 80, 'Overall QA score must reach 80%.'],
                [$scores['modules'] >= 50, 'Module readiness must reach 50%.'],
            ],
            self::STAGING => [
                [$highSecurity === 0, "Close {$highSecurity} open High security/RBAC finding(s)."],
                [$scores['overall'] >= 90, 'Overall QA score must reach 90%.'],
                [$scores['modules'] >= 70, 'Module readiness must reach 70%.'],
                [$scores['demo'] >= 70, 'Demo readiness must reach 70%.'],
                [$counts['retest_required'] === 0, "Retest {$counts['retest_required']} item(s) waiting for verification."],
            ],
            self::PRODUCTION => [
                [$counts['open_critical'] === 0 && $highAny === 0, 'No Critical or High finding may be open.'],
                [$mediumSecurity === 0, "Close {$mediumSecurity} open Medium security/RBAC finding(s)."],
                [$scores['overall'] >= 95, 'Overall QA score must reach 95%.'],
                [$scores['modules'] >= 90, 'Module readiness must reach 90%.'],
                [$scores['demo'] >= 90, 'Demo readiness must reach 90%.'],
                [$scores['ui'] >= 85, 'UI/UX (responsive) score must reach 85%.'],
                [$phasesOpen === 0, "Complete {$phasesOpen} remaining QA phase(s)."],
            ],
        };

        return collect($checks)->reject(fn (array $c) => $c[0])->map(fn (array $c) => $c[1])->values()->all();
    }

    /** Passing ÷ everything that should pass (known baseline excluded; not tested counts against). */
    private function passRate(Collection $cases): ?float
    {
        $relevant = $cases->reject(fn (QaTestCase $c) => $c->status === QaTestStatus::KnownBaseline);
        if ($relevant->isEmpty()) {
            return null;
        }

        $points = $relevant->sum(fn (QaTestCase $c) => match ($c->status) {
            QaTestStatus::Pass, QaTestStatus::FixedVerified => 1.0,
            QaTestStatus::Partial => 0.5,
            default => 0.0,
        });

        return $points / $relevant->count() * 100;
    }

    /** PASS = 1, PARTIAL = ½ over every expected cell; unchecked cells count as 0. */
    private function checklistRate(string $category, int $expectedCells): ?float
    {
        if ($expectedCells === 0) {
            return null;
        }

        $points = QaChecklistItem::query()->where('category', $category)->get(['status'])
            ->sum(fn (QaChecklistItem $i) => match ($i->status) {
                'pass' => 1.0,
                'partial' => 0.5,
                default => 0.0,
            });

        return min(100, $points / $expectedCells * 100);
    }

    private function score(?float $rate, int $penalty): int
    {
        return (int) max(0, min(100, round(($rate ?? 0) - $penalty)));
    }
}
