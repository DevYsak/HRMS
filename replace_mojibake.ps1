$files = @(
    "resources\views\livewire\attendance\all-attendance.blade.php",
    "resources\views\livewire\attendance\attendance-settings.blade.php",
    "resources\views\livewire\onboarding\onboarding-checklist.blade.php",
    "resources\views\livewire\operations\expenses.blade.php",
    "resources\views\livewire\overtime\my-ot-requests.blade.php",
    "resources\views\livewire\time-off\my-time-off.blade.php",
    "resources\views\livewire\manager-dashboard.blade.php"
)

$replacements = @{
    "â†’" = "→"
    "â€“" = "–"
    "â€”" = "—"
    "â€œ" = "“"
    "â€ " = "”"
    "â€˜" = "‘"
    "â€™" = "’"
    "â€¢" = "•"
    "âš " = "⚠"
    "âš " = "⚠"
    "â†©" = "↩"
    "â‚¹" = "₹"
    "âœ"" = "✔"
    "â„¹ï¸" = "ℹ️"
    "â„¹" = "ℹ️"
    "â”€" = "─"
    "â•" = "═"
    "â€•" = "―"
}

foreach ($file in $files) {
    if (Test-Path $file) {
        $content = Get-Content -Raw -Encoding UTF8 $file
        foreach ($key in $replacements.Keys) {
            $content = $content.Replace($key, $replacements[$key])
        }
        Set-Content -Path $file -Value $content -Encoding UTF8
        Write-Host "Processed $file"
    }
}
