<?php
$files = [
    "resources/views/livewire/attendance/all-attendance.blade.php",
    "resources/views/livewire/attendance/attendance-settings.blade.php",
    "resources/views/livewire/onboarding/onboarding-checklist.blade.php",
    "resources/views/livewire/operations/expenses.blade.php",
    "resources/views/livewire/overtime/my-ot-requests.blade.php",
    "resources/views/livewire/time-off/my-time-off.blade.php",
    "resources/views/livewire/manager-dashboard.blade.php"
];

$replacements = [
    "→" => "→",
    "–" => "–",
    "—" => "—",
    "“" => "“",
    "”" => "”",
    "”" => "”",
    "”˜" => "‘",
    "”™" => "’",
    "”¢" => "•",
    "⚠" => "⚠",
    "⚠" => "⚠",
    "↩" => "↩",
    "₹" => "₹",
    "âœ\"" => "✔",
    "✔" => "✔",
    "ℹ️" => "ℹ️",
    "ℹ️" => "ℹ️",
    "─" => "─",
    "═" => "═",
    "”•" => "―"
];

foreach ($files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $original = $content;
        foreach ($replacements as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }
        if ($content !== $original) {
            file_put_contents($file, $content);
            echo "Processed $file\n";
        }
    }
}
