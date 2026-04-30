<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

\Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag());

try {
    $html = view('livewire.operations.expenses', [
        'expenses' => [],
        'canReview' => true,
        'pendingStatus' => 'pending'
    ])->render();
    
    // check if it rendered an empty svg for the icon, or any error component
    if (strpos($html, 'receipt-percent') !== false) {
        echo "Found receipt-percent in HTML.\n";
    }
    file_put_contents('test_output.html', $html);
    echo "OK";
} catch (Throwable $e) {
    echo "ERROR: " . get_class($e) . " - " . $e->getMessage();
}
