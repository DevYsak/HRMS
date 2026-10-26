<?php

namespace App\Livewire\Dashboard;

use App\Services\Approvals\ApprovalQueueService;
use App\Services\Leave\LeaveDashboardService;
use App\Services\Leave\LeaveRequestAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The HR dashboard's leave section: six operational cards and the pending
 * requests, each leading to the HR leave request list or detail page.
 * Loaded after the page (lazy) — the balance figures read the calculator.
 */
#[Lazy]
class LeaveOverview extends Component
{
    public function placeholder(): string
    {
        return <<<'HTML'
        <section class="space-y-3" aria-busy="true">
            <div class="h-5 w-40 animate-pulse rounded bg-zinc-200 dark:bg-white/10"></div>
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6">
                <div class="h-24 animate-pulse rounded-xl bg-zinc-100 dark:bg-white/5"></div><div class="h-24 animate-pulse rounded-xl bg-zinc-100 dark:bg-white/5"></div><div class="h-24 animate-pulse rounded-xl bg-zinc-100 dark:bg-white/5"></div>
                <div class="h-24 animate-pulse rounded-xl bg-zinc-100 dark:bg-white/5"></div><div class="h-24 animate-pulse rounded-xl bg-zinc-100 dark:bg-white/5"></div><div class="h-24 animate-pulse rounded-xl bg-zinc-100 dark:bg-white/5"></div>
            </div>
        </section>
        HTML;
    }

    public function render()
    {
        $user = Auth::user();
        if (! app(LeaveRequestAccess::class)->canBrowse($user)) {
            return view('livewire.dashboard.leave-overview', ['visible' => false]);
        }

        $service = app(LeaveDashboardService::class);
        $today = Carbon::today();
        $queue = app(ApprovalQueueService::class);

        return view('livewire.dashboard.leave-overview', [
            'visible' => true,
            'cards' => $service->cards($user),
            'pending' => $service->pending($user),
            'approverOf' => fn ($request) => $queue->path($request)['current'],
            'links' => [
                'pending' => route('time-off.leave-requests', ['status' => 'pending']),
                'cancellations' => route('time-off.leave-requests', ['status' => 'cancellation']),
                'on_leave' => route('time-off.leave-requests', ['status' => 'approved', 'from' => $today->toDateString(), 'to' => $today->toDateString()]),
                'upcoming' => route('time-off.leave-requests', ['status' => 'approved', 'from' => $today->copy()->addDay()->toDateString(), 'to' => $today->copy()->addDays(30)->toDateString()]),
                'unpaid' => route('time-off.leave-requests', ['status' => 'approved', 'unpaid' => 1, 'from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->copy()->endOfMonth()->toDateString()]),
            ],
            // Low and negative Earned Leave filter the Employee Leave Balances list below.
            'canSeeBalances' => $user->hasPermission('view_leave_management'),
        ]);
    }
}
