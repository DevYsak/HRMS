<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

auth()->loginUsingId(4);

try {
    $component = app(\App\Livewire\Dashboard::class);
    $view = $component->render();
    
    // In Livewire 3/4, rendering the layout requires LivewireManager.
    // Let's just output the view
    $html = $view->render();
    echo "Inner HTML rendered ok. Length: " . strlen($html) . "\n";
    
    // Now let's try to render the layout with the view inside it!
    $layout = view('layouts.app', ['slot' => $html]);
    echo "Layout HTML rendered ok. Length: " . strlen($layout->render()) . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
