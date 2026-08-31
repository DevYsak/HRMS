<?php

namespace App\Services\Notifications;

/**
 * Renders {{variable}} tokens in an admin-edited subject/body against a
 * concrete data set, and validates a template against the variables an event
 * actually offers before it can be saved.
 *
 * A template is data entered by an admin, not code — an unknown variable
 * must be rejected at save time rather than left to render as literal
 * "{{typo}}" text in a real email.
 */
class TemplateVariableRenderer
{
    public function render(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            fn (array $m) => array_key_exists($m[1], $variables) ? (string) $variables[$m[1]] : $m[0],
            $template,
        );
    }

    /**
     * @param  array<int, string>  $allowed
     * @return array<int, string> the unknown variable names referenced, if any
     */
    public function unknownVariables(string $template, array $allowed): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $template, $matches);

        return array_values(array_unique(array_diff($matches[1], $allowed)));
    }
}
