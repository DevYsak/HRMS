<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row, database-driven configuration for the AI assistant features.
 *
 * The API key is stored encrypted at rest. Use {@see self::current()} to read
 * the live configuration anywhere in the app.
 */
class AiSetting extends Model
{
    protected $fillable = [
        'provider',
        'api_key',
        'model',
        'base_url',
        'enabled',
        'allowed_roles',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'allowed_roles' => 'array',
        'enabled' => 'boolean',
    ];

    /**
     * Provider presets: the default model and OpenAI-compatible base URL.
     *
     * @return array<string, array{label: string, model: string, base_url: ?string, key_url: string}>
     */
    public static function providers(): array
    {
        return [
            'openai' => [
                'label' => 'OpenAI (ChatGPT)',
                'model' => 'gpt-4o-mini',
                'base_url' => null, // SDK default: api.openai.com/v1
                'key_url' => 'https://platform.openai.com/api-keys',
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'model' => 'gemini-flash-lite-latest',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
                'key_url' => 'https://aistudio.google.com/apikey',
            ],
            'custom' => [
                'label' => 'Custom (OpenAI-compatible)',
                'model' => '',
                'base_url' => '',
                'key_url' => '',
            ],
        ];
    }

    /**
     * The single configuration row, created with sensible defaults on first use.
     */
    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'provider' => 'openai',
            'enabled' => false,
            'allowed_roles' => ['super_admin', 'hr_admin', 'director', 'manager', 'finance', 'employee'],
        ]);
    }

    /**
     * Effective model name, falling back to the provider preset.
     */
    public function resolvedModel(): string
    {
        if (filled($this->model)) {
            return $this->model;
        }

        return static::providers()[$this->provider]['model'] ?? 'gpt-4o-mini';
    }

    /**
     * Effective base URL, falling back to the provider preset (null = OpenAI default).
     */
    public function resolvedBaseUrl(): ?string
    {
        if (filled($this->base_url)) {
            return $this->base_url;
        }

        return static::providers()[$this->provider]['base_url'] ?? null;
    }

    /**
     * Whether a given role value is permitted to use AI features.
     */
    public function roleAllowed(string $role): bool
    {
        return in_array($role, $this->allowed_roles ?? [], true);
    }
}
