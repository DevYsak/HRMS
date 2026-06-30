@php
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $role = $user?->role?->value;

    // Safe route resolver — falls back to '#' when a route name doesn't exist.
    $r = fn (string $name) => Route::has($name) ? route($name) : '#';

    // Badges
    $notifBadge = $user?->unreadNotifications()->count() ?? 0;
    $leaveBadge = $user?->can('approve_leave') || in_array($role, ['super_admin', 'hr_admin', 'director', 'manager'], true)
        ? \App\Models\LeaveRequest::where('status', 'pending')->count()
        : ($user?->employee?->leaveRequests()->whereIn('status', ['pending', 'pending_hr'])->count() ?? 0);

    // Heroicons (outline) — inner paths only.
    $paths = [
        'home' => '<path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75"/>',
        'chart' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>',
        'id' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z"/>',
        'clock' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
        'finger' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7.864 4.243A7.5 7.5 0 0 1 19.5 10.5c0 2.92-.556 5.709-1.568 8.268M5.742 6.364A7.465 7.465 0 0 0 4.5 10.5a7.464 7.464 0 0 1-1.15 3.993m1.989 3.559A11.209 11.209 0 0 0 8.25 10.5a3.75 3.75 0 1 1 7.5 0c0 .527-.021 1.049-.064 1.565M12 10.5a14.94 14.94 0 0 1-3.6 9.75m6.633-4.596a18.666 18.666 0 0 1-2.485 5.33"/>',
        'calendar' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/>',
        'bolt' => '<path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/>',
        'home-modern' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 13.5h1.5m15 0H21M4.5 21V8.25M19.5 21V8.25M3 8.25l9-5.25 9 5.25"/>',
        'wallet' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 15h.008v.008H16.5V15Z"/>',
        'trend' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941"/>',
        'report' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
        'inbox' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z"/>',
        'sparkles' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 0 0 2.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/>',
        'cog' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
        'audit' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z"/>',
        'folder' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/>',
        'user-plus' => '<path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z"/>',
        'receipt' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0c1.1.128 1.907 1.077 1.907 2.185ZM9.75 9h.008v.008H9.75V9Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 4.5h.008v.008h-.008V13.5Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/>',
        'gift' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 1 0 9.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1 1 14.625 7.5H12m0 0V21m-8.625-9.75h18.75c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>',
        'payslip' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
        'bell' => '<path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>',
    ];

    // Each item: [label, iconKey, href, routeIs-pattern, badge|null]
    $groups = match ($role) {
        'super_admin' => [
            ['Overview', [
                ['Dashboard', 'home', $r('dashboard'), 'dashboard', null],
                ['Executive View', 'chart', $r('executive.dashboard'), 'executive.*', null],
                ['HR Overview', 'users', $r('hr.overview'), 'hr.*', null],
            ]],
            ['People', [
                ['Employees', 'id', $r('employees.index'), 'employees.*', null],
                ['Attendance', 'clock', $r('attendance.employees'), 'attendance.employees', null],
                ['Leave', 'calendar', $r('time-off.employees'), 'time-off.employees', $leaveBadge ?: null],
                ['Overtime', 'bolt', $r('overtime.manage'), 'overtime.manage', null],
                ['WFH', 'home-modern', $r('wfh.manage'), 'wfh.manage', null],
            ]],
            ['Finance', [
                ['Payroll', 'wallet', $r('payroll.overview'), 'payroll.*', null],
                ['Performance', 'trend', $r('performance.dashboard'), 'performance.*', null],
            ]],
            ['System', [
                ['Reports', 'report', $r('reports.attendance-summary'), 'reports.*', null],
                ['Inbox', 'inbox', $r('notifications.index'), 'notifications.*', $notifBadge ?: null],
                ['AI Assistant', 'sparkles', $r('ai.assistant'), 'ai.*', null],
                ['Settings', 'cog', $r('settings.general'), 'settings.*', null],
                ['Audit Log', 'audit', $r('audit.index'), 'audit.*', null],
            ]],
        ],
        'hr_admin' => [
            ['HR', [
                ['Dashboard', 'home', $r('dashboard'), 'dashboard', null],
                ['Employees', 'id', $r('employees.index'), 'employees.*', null],
                ['Attendance', 'clock', $r('attendance.employees'), 'attendance.employees', null],
                ['Leave', 'calendar', $r('time-off.employees'), 'time-off.employees', $leaveBadge ?: null],
                ['OT', 'bolt', $r('overtime.manage'), 'overtime.manage', null],
            ]],
            ['Ops', [
                ['Documents', 'folder', $r('documents.index'), 'documents.*', null],
                ['Performance', 'trend', $r('performance.dashboard'), 'performance.*', null],
                ['Onboarding', 'user-plus', $r('employees.onboarding-manager'), 'employees.onboarding*', null],
            ]],
        ],
        'director' => [
            ['Team', [
                ['Dashboard', 'home', $r('dashboard'), 'dashboard', null],
                ['My Team', 'users', $r('attendance.team'), 'attendance.team', null],
                ['Attendance', 'clock', $r('attendance.employees'), 'attendance.employees', null],
                ['Leave', 'calendar', $r('time-off.employees'), 'time-off.*', $leaveBadge ?: null],
                ['OT Approvals', 'bolt', $r('overtime.manage'), 'overtime.manage', null],
                ['Performance', 'trend', $r('performance.dashboard'), 'performance.*', null],
            ]],
        ],
        'finance' => [
            ['Finance', [
                ['Dashboard', 'home', $r('dashboard'), 'dashboard', null],
                ['Payroll Runs', 'wallet', $r('payroll.process'), 'payroll.process', null],
                ['OT', 'bolt', $r('overtime.manage'), 'overtime.manage', null],
                ['Reimbursements', 'receipt', $r('operations.expenses'), 'operations.*', null],
                ['Incentives', 'gift', $r('payroll.incentives'), 'payroll.incentives', null],
                ['Payslips', 'payslip', $r('payroll.payslips'), 'payroll.payslips', null],
            ]],
        ],
        default => [
            ['Me', [
                ['Dashboard', 'home', $r('dashboard'), 'dashboard', null],
                ['Attendance', 'clock', $r('attendance.my'), 'attendance.my', null],
                ['Leave', 'calendar', $r('time-off.my'), 'time-off.my', $leaveBadge ?: null],
                ['OT', 'bolt', $r('overtime.my'), 'overtime.my', null],
                ['Payslips', 'payslip', $r('payroll.payslips'), 'payroll.payslips', null],
            ]],
            ['More', [
                ['Performance', 'trend', $r('performance.my'), 'performance.my', null],
                ['Documents', 'folder', $r('documents.index'), 'documents.*', null],
                ['Notifications', 'bell', $r('notifications.index'), 'notifications.*', $notifBadge ?: null],
            ]],
        ],
    };
@endphp

@once
    <script>
        // Apply persisted theme before paint to avoid flash.
        if (localStorage.getItem('theme') === 'dark'
            || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
        document.addEventListener('alpine:init', () => {
            Alpine.store('darkMode', {
                on: document.documentElement.classList.contains('dark'),
                toggle() {
                    this.on = !this.on;
                    document.documentElement.classList.toggle('dark', this.on);
                    localStorage.setItem('theme', this.on ? 'dark' : 'light');
                },
            });
        });
    </script>
@endonce

<div x-data="{ collapsed: localStorage.getItem('sb-collapsed') === '1', mobileOpen: false }">

    {{-- Mobile hamburger --}}
    <button type="button" x-on:click="mobileOpen = true"
        class="fixed left-4 top-4 z-30 flex size-10 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 shadow-sm md:hidden dark:border-white/10 dark:bg-gray-900 dark:text-gray-300">
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/></svg>
    </button>

    {{-- Mobile backdrop --}}
    <div x-show="mobileOpen" x-cloak x-transition.opacity x-on:click="mobileOpen = false"
        class="fixed inset-0 z-40 bg-black/50 md:hidden"></div>

    {{-- ===================== SIDEBAR ===================== --}}
    <aside
        :class="{ 'w-14': collapsed, 'w-56': !collapsed, 'translate-x-0': mobileOpen, '-translate-x-full': !mobileOpen }"
        class="fixed inset-y-0 left-0 z-50 flex h-screen flex-col border-r border-gray-100 bg-white transition-all duration-300 ease-in-out md:sticky md:top-0 md:translate-x-0 dark:border-white/5 dark:bg-gray-950">

        {{-- Collapse toggle (desktop only) --}}
        <button type="button" x-on:click="collapsed = !collapsed; localStorage.setItem('sb-collapsed', collapsed ? '1' : '0')"
            class="absolute -right-3 top-6 z-10 hidden size-6 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 shadow-sm transition hover:text-violet-600 md:flex dark:border-white/10 dark:bg-gray-900 dark:text-gray-400">
            <svg class="size-3.5 transition-transform duration-300" :class="collapsed && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
        </button>

        {{-- ===== LOGO ===== --}}
        <div class="flex h-16 items-center gap-3 px-3">
            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-violet-600 text-sm font-bold text-white">P</div>
            <div class="overflow-hidden transition-all duration-300" :class="collapsed && 'w-0 opacity-0'">
                <div class="whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">Pulse</div>
                <div class="whitespace-nowrap text-xs text-gray-400">by Conexus</div>
            </div>
        </div>

        {{-- ===== USER CARD ===== --}}
        <div class="mx-2 mb-1 flex items-center gap-3 rounded-xl px-2 py-2">
            <div class="relative shrink-0">
                <div class="flex size-9 items-center justify-center rounded-full bg-violet-100 text-[13px] font-bold text-violet-700 dark:bg-violet-500/20 dark:text-violet-300">
                    {{ $user?->initials() ?? 'U' }}
                </div>
                <span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full border-2 border-white bg-green-500 dark:border-gray-950"></span>
            </div>
            <div class="min-w-0 overflow-hidden transition-all duration-300" :class="collapsed && 'w-0 opacity-0'">
                <div class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $user?->name ?? 'User' }}</div>
                <span class="mt-0.5 inline-block rounded-full bg-violet-50 px-2 text-[10px] font-semibold text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">
                    {{ $user?->role?->label() ?? 'Member' }}
                </span>
            </div>
        </div>

        {{-- ===== NAV ===== --}}
        <nav class="flex-1 overflow-y-auto overflow-x-hidden py-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach($groups as [$label, $items])
                <div class="px-3 pb-1 pt-4 text-[9px] font-semibold uppercase tracking-widest text-gray-400 transition-all duration-300"
                    :class="collapsed && 'h-0 overflow-hidden opacity-0 !py-0'">{{ $label }}</div>

                @foreach($items as [$itemLabel, $iconKey, $href, $pattern, $badge])
                    @php $active = $pattern && request()->routeIs($pattern); @endphp
                    <a href="{{ $href }}" @if(! \Illuminate\Support\Str::startsWith($href, '#')) wire:navigate @endif
                        class="group relative mx-2 flex items-center gap-2.5 rounded-lg px-3 py-2.5 text-[13px] transition-colors
                            {{ $active
                                ? 'rounded-l-none border-l-[3px] border-violet-600 bg-violet-50 font-medium text-violet-700 dark:border-violet-500 dark:bg-violet-500/10 dark:text-violet-300'
                                : 'text-gray-500 hover:bg-violet-50 hover:text-violet-700 dark:text-gray-400 dark:hover:bg-violet-500/10 dark:hover:text-violet-300' }}"
                        :class="collapsed && 'justify-center'">
                        <svg class="size-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">{!! $paths[$iconKey] ?? $paths['home'] !!}</svg>

                        <span class="overflow-hidden whitespace-nowrap transition-all duration-300" :class="collapsed && 'w-0 opacity-0'">{{ $itemLabel }}</span>

                        @if($badge)
                            <span class="ml-auto rounded-full bg-red-500 px-1.5 py-0.5 text-[9px] font-bold text-white transition-all"
                                :class="collapsed && 'absolute right-1 top-1 ml-0 !px-1 !py-0'">{{ $badge > 9 ? '9+' : $badge }}</span>
                        @endif

                        {{-- Tooltip (collapsed only) --}}
                        <span x-show="collapsed" x-cloak
                            class="pointer-events-none absolute left-full z-50 ml-2 whitespace-nowrap rounded bg-gray-900 px-2 py-1 text-xs text-white opacity-0 shadow-lg transition-opacity duration-150 group-hover:opacity-100">
                            {{ $itemLabel }}
                        </span>
                    </a>
                @endforeach
            @endforeach
        </nav>

        {{-- ===== BOTTOM DOCK ===== --}}
        <div class="mt-auto border-t border-gray-100 px-3 py-3 dark:border-white/5">
            <div class="flex items-center gap-2" :class="collapsed && 'flex-col'">
                {{-- Live status --}}
                <div class="flex items-center gap-1.5 overflow-hidden" :class="collapsed && 'w-0 opacity-0'">
                    <span class="size-2 shrink-0 rounded-full bg-green-500"></span>
                    <span class="whitespace-nowrap text-[10px] text-gray-400">All systems live</span>
                </div>

                <div class="flex items-center gap-1" :class="collapsed ? 'flex-col' : 'ml-auto'">
                    {{-- Settings --}}
                    <a href="{{ $r('settings.general') }}" @if(Route::has('settings.general')) wire:navigate @endif
                        class="group relative flex size-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-violet-50 hover:text-violet-700 dark:hover:bg-violet-500/10 dark:hover:text-violet-300" aria-label="Settings">
                        <svg class="size-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">{!! $paths['cog'] !!}</svg>
                    </a>

                    {{-- Dark mode toggle --}}
                    <button type="button" x-data x-on:click="$store.darkMode.toggle()"
                        class="flex size-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-violet-50 hover:text-violet-700 dark:hover:bg-violet-500/10 dark:hover:text-violet-300" aria-label="Toggle dark mode">
                        {{-- moon (light mode) --}}
                        <svg x-show="!$store.darkMode.on" x-cloak class="size-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/></svg>
                        {{-- sun (dark mode) --}}
                        <svg x-show="$store.darkMode.on" x-cloak class="size-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/></svg>
                    </button>
                </div>

                {{-- Version --}}
                <span class="text-[9px] text-gray-300 dark:text-gray-600" :class="collapsed ? 'mt-1' : ''">v3.1</span>
            </div>
        </div>
    </aside>
</div>
