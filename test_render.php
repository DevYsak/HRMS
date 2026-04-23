<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

auth()->loginUsingId(4);

try {
    $component = app(\App\Livewire\Dashboard::class);
    $view = $component->render();
    $html = $view->render();
    echo "Rendered successfully. HTML length: " . strlen($html);
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
