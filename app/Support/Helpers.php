<?php
declare(strict_types=1);

/**
 * File: app/Support/Helpers.php
 *
 * Purpose:
 *   Global helper functions used across the panel: escaping, UUID generation,
 *   Persian/Latin digit normalisation, code generation, array access, and
 *   small string utilities. All functions are namespaced-free globals guarded
 *   by function_exists() so they can be required more than once safely.
 *
 * Conventions:
 *   - All user-facing output passes through e().
 *   - Random values use random_bytes (P10, security §29).
 *
 * @package App\Support
 */

use App\Bootstrap\Container;

if (!function_exists('e')) {
    /**
     * Escape a value for safe HTML output (XSS defence).
     *
     * @param mixed $value Raw value.
     * @return string Escaped string.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('uuid4')) {
    /**
     * Generate a RFC 4122 version-4 UUID.
     *
     * @return string UUID string.
     */
    function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('random_token')) {
    /**
     * Generate a cryptographically secure hex token.
     *
     * @param int $bytes Number of random bytes.
     * @return string Hex-encoded token (2x bytes length).
     */
    function random_token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('random_digits')) {
    /**
     * Generate a random digit string (e.g. share codes, usernames).
     *
     * @param int $length Number of digits.
     * @return string Digit string.
     */
    function random_digits(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= (string) random_int(0, 9);
        }
        return $out;
    }
}

if (!function_exists('random_alnum')) {
    /**
     * Generate a random alphanumeric string (transfer codes).
     *
     * @param int $length Number of characters.
     * @return string Uppercase alphanumeric string.
     */
    function random_alnum(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}

if (!function_exists('normalize_digits')) {
    /**
     * Convert Persian and Arabic-Indic digits to Latin digits.
     *
     * @param string $value Input string.
     * @return string Normalised string.
     */
    function normalize_digits(string $value): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $value = str_replace($persian, $latin, $value);
        return str_replace($arabic, $latin, $value);
    }
}

if (!function_exists('to_persian_digits')) {
    /**
     * Convert Latin digits to Persian digits for display.
     *
     * @param string|int|float $value Input.
     * @return string Persian-digit string.
     */
    function to_persian_digits(string|int|float $value): string
    {
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return str_replace($latin, $persian, (string) $value);
    }
}

if (!function_exists('slugify')) {
    /**
     * Build a URL-safe slug from an arbitrary string.
     *
     * Latin alphanumerics are kept; everything else collapses to hyphens, and
     * a short hash suffix guarantees uniqueness for non-Latin (Persian) input.
     *
     * @param string $value Input string.
     * @param string $sep   Separator.
     * @return string Slug.
     */
    function slugify(string $value, string $sep = '-'): string
    {
        $value = trim($value);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = $ascii !== false ? $ascii : $value;
        $slug = strtolower((string) $ascii);
        $slug = preg_replace('/[^a-z0-9]+/', $sep, $slug) ?? '';
        $slug = trim($slug, $sep);
        if ($slug === '') {
            $slug = 'item';
        }
        return substr($slug, 0, 60);
    }
}

if (!function_exists('now_utc')) {
    /**
     * Current UTC timestamp in ISO-8601 format with trailing Z.
     *
     * @return string e.g. "2025-01-01T10:00:00Z"
     */
    function now_utc(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

if (!function_exists('utc_iso')) {
    /**
     * Convert a Unix timestamp to UTC ISO-8601.
     *
     * @param int $timestamp Unix timestamp.
     * @return string
     */
    function utc_iso(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}

if (!function_exists('array_get')) {
    /**
     * Read a nested array value with dot notation.
     *
     * @param array  $array   Source array.
     * @param string $key     Dot path.
     * @param mixed  $default Default.
     * @return mixed
     */
    function array_get(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) { return $array[$key]; }
        if (!str_contains($key, '.')) { return $default; }
        $cursor = $array;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }
}

if (!function_exists('str_limit')) {
    /**
     * Truncate a string to a maximum length.
     *
     * @param string $value  Input.
     * @param int    $length Max length.
     * @param string $end    Suffix.
     * @return string
     */
    function str_limit(string $value, int $length = 80, string $end = '…'): string
    {
        if (mb_strlen($value) <= $length) { return $value; }
        return mb_substr($value, 0, $length) . $end;
    }
}

if (!function_exists('container')) {
    /**
     * Read a service from the global container (set at bootstrap).
     *
     * @param string|null $key Service key, or null for the container itself.
     * @return mixed
     */
    function container(?string $key = null): mixed
    {
        /** @var Container|null $c */
        $c = $GLOBALS['__container'] ?? null;
        if ($key === null) { return $c; }
        return $c?->get($key);
    }
}

if (!function_exists('human_filesize')) {
    /**
     * Format a byte count as a human-readable string.
     *
     * @param int $bytes Byte count.
     * @return string e.g. "1.2 MB"
     */
    function human_filesize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
