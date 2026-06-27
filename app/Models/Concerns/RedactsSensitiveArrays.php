<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Redacts secret-shaped values from a model's JSON attributes before display.
 *
 * Used by models that store provider payloads/results/metadata so that an
 * admin-rendered view (or any serialization) never surfaces a secret, even one
 * an upstream system put in a webhook/checkout payload.
 */
trait RedactsSensitiveArrays
{
    /** Key-name substrings whose values are redacted. */
    public const SENSITIVE_KEY_PATTERNS = [
        'token', 'secret', 'password', 'passwd', 'private_key', 'api_key', 'apikey', 'credential',
    ];

    /**
     * Recursively redact secret-shaped values from an array by key name.
     *
     * @param  array<mixed>|null  $data
     * @return array<mixed>
     */
    public static function redact(?array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && Str::contains(strtolower($key), self::SENSITIVE_KEY_PATTERNS)) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = self::redact($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public function safePayload(): array
    {
        return self::redact($this->payload);
    }

    public function safeMetadata(): array
    {
        return self::redact($this->metadata);
    }

    public function safeResult(): array
    {
        return self::redact($this->result);
    }
}
