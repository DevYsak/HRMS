$user = \App\Models\User::where('email', 'employee@conexus.in')->first();
if (!$user) { echo "User not found!\n"; return; }
if ($user->employee) { echo "Already exists: " . $user->employee->id . "\n"; return; }
$emp = \App\Models\Employee::create(['user_id' => $user->id, 'employee_id' => 'EMP001', 'joining_date' => now()->toDateString(), 'status' => 'active', 'employment_type' => 'full_time']);
echo "Created! Employee ID: " . $emp->id . "\n";
