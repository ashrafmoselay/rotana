<?php

namespace App\Support;

final class DemoPassword
{
    public static function resolve(?string $configured = null, ?string $envPath = null): ?string
    {
        $configured ??= config('rotana.demo_password');

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        $envPath ??= base_path('.env');
        if (! is_file($envPath) || ! is_readable($envPath)) {
            return $configured;
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (! str_starts_with($line, 'DEMO_PASSWORD=')) {
                continue;
            }

            $value = substr($line, strlen('DEMO_PASSWORD='));
            $value = trim($value);

            if ($value === '') {
                return '';
            }

            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                return substr($value, 1, -1);
            }

            if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                return substr($value, 1, -1);
            }

            return preg_replace('/\s+#.*$/', '', $value) ?? $value;
        }

        return $configured;
    }
}
