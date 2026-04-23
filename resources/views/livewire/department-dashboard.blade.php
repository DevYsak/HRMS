<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                {{ $department ? $department->name . ' Department' : 'Department Dashboard' }}
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Overview of your department's attendance and performance.</p>
        </div>
    </div>

    @if(!$department)
        <div class="p-4 mb-4 text-sm text-yellow-800 rounded-lg bg-yellow-50 dark:bg-gray-800 dark:text-yellow-300" role="alert">
            <span class="font-medium">No Department Assigned!</span> You are not currently designated as the head of any department.
        </div>
    @else
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Total Employees Card -->
            <flux:card>
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Employees</p>
                        <h3 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $totalEmployees }}</h3>
                    </div>
                    <div class="p-3 bg-blue-100 rounded-full dark:bg-blue-900">
                        <flux:icon.users class="w-6 h-6 text-blue-600 dark:text-blue-300" />
                    </div>
                </div>
            </flux:card>

            <!-- Present Today Card -->
            <flux:card>
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Present Today</p>
                        <h3 class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $presentToday }}</h3>
                    </div>
                    <div class="p-3 bg-green-100 rounded-full dark:bg-green-900">
                        <flux:icon.check-circle class="w-6 h-6 text-green-600 dark:text-green-300" />
                    </div>
                </div>
            </flux:card>

            <!-- Late Today Card -->
            <flux:card>
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Late Arrivals</p>
                        <h3 class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{{ $lateToday }}</h3>
                    </div>
                    <div class="p-3 bg-yellow-100 rounded-full dark:bg-yellow-900">
                        <flux:icon.clock class="w-6 h-6 text-yellow-600 dark:text-yellow-300" />
                    </div>
                </div>
            </flux:card>

            <!-- Absent Today Card -->
            <flux:card>
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Absent Today</p>
                        <h3 class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $absentToday }}</h3>
                    </div>
                    <div class="p-3 bg-red-100 rounded-full dark:bg-red-900">
                        <flux:icon.x-circle class="w-6 h-6 text-red-600 dark:text-red-300" />
                    </div>
                </div>
            </flux:card>
        </div>
    @endif
</div>
