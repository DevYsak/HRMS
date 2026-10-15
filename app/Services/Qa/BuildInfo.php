<?php

namespace App\Services\Qa;

/**
 * The branch and commit of the running code, read from .git without
 * shelling out (null when the deploy has no .git directory).
 */
class BuildInfo
{
    public function branch(): ?string
    {
        $head = $this->head();

        return $head !== null && str_starts_with($head, 'ref: refs/heads/') ? substr($head, 16) : null;
    }

    /** Short (7-character) commit hash. */
    public function commit(): ?string
    {
        $head = $this->head();
        if ($head === null) {
            return null;
        }

        if (! str_starts_with($head, 'ref: ')) {
            return substr($head, 0, 7);
        }

        $ref = substr($head, 5);
        $file = base_path('.git/'.$ref);
        if (is_file($file)) {
            return substr(trim((string) file_get_contents($file)), 0, 7);
        }

        // Packed refs (after git gc).
        $packed = base_path('.git/packed-refs');
        if (is_file($packed) && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', (string) file_get_contents($packed), $m)) {
            return substr($m[1], 0, 7);
        }

        return null;
    }

    private function head(): ?string
    {
        $file = base_path('.git/HEAD');

        return is_file($file) ? trim((string) file_get_contents($file)) : null;
    }
}
