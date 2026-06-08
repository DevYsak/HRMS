<?php
$directory = new RecursiveDirectoryIterator('.');
$iterator = new RecursiveIteratorIterator($directory);
$regex = new RegexIterator($iterator, '/^.+\.(php)$/i', RecursiveRegexIterator::GET_MATCH);

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

$count = 0;
foreach ($regex as $file => $object) {
    // Skip vendor directory
    if (strpos($file, '\\vendor\\') !== false || strpos($file, '/vendor/') !== false) {
        continue;
    }

    if (file_exists($file)) {
        $content = file_get_contents($file);
        $original = $content;
        
        $hasMojibake = false;
        foreach (array_keys($replacements) as $search) {
            if (strpos($content, $search) !== false) {
                $hasMojibake = true;
                break;
            }
        }

        if ($hasMojibake) {
            foreach ($replacements as $search => $replace) {
                $content = str_replace($search, $replace, $content);
            }
            if ($content !== $original) {
                file_put_contents($file, $content);
                echo "Processed $file\n";
                $count++;
            }
        }
    }
}
echo "Total files processed: $count\n";
