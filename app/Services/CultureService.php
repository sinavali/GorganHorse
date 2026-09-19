<?php
declare(strict_types=1);

/**
 * File: app/Services/CultureService.php
 *
 * Purpose:
 *   Resolve the active culture, load its JSON definition, translate error codes
 *   and labels, and format dates, numbers, and money for display. Wraps the
 *   Jalali calendar conversion and is the single authority on presentation
 *   formatting (Technical §15, Blueprint §2 P25).
 *
 * Dependencies:
 *   - Database (main)
 *   - CacheService
 *
 * Conventions:
 *   - fa-IR is the default and primary culture.
 *   - Storage is always UTC ISO-8601; conversion is display-only.
 *   - Money is integer IRT formatted with the culture's currency label.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Support\Vendor\Jalali;

/**
 * Class: CultureService
 *
 * Purpose: Provide the active culture and locale-aware formatting helpers.
 */
final class CultureService
{
    private Database $db;
    private CacheService $cache;
    private string $default;
    /** @var array<string,mixed> Active culture definition. */
    private array $active;
    private string $activeCode;

    /**
     * @param Database     $db      Main database.
     * @param CacheService $cache   Cache service.
     * @param string       $default Default culture code.
     */
    public function __construct(Database $db, CacheService $cache, string $default = 'fa-IR')
    {
        $this->db = $db;
        $this->cache = $cache;
        $this->default = $default;
        $this->activeCode = $default;
        $this->active = $this->load($default);
    }

    /**
     * Seed the culture list (idempotent).
     *
     * @return void
     */
    public function seedCultures(): void
    {
        $now = now_utc();
        $cultures = [
            ['code' => 'fa-IR', 'name' => 'فارسی (ایران)', 'direction' => 'rtl', 'is_default' => 1, 'sort_order' => 1],
            ['code' => 'en-US', 'name' => 'English (US)', 'direction' => 'ltr', 'is_default' => 0, 'sort_order' => 2],
        ];
        foreach ($cultures as $c) {
            $exists = $this->db->scalar('SELECT COUNT(*) FROM cultures WHERE code = :c', ['c' => $c['code']]);
            if ((int) $exists > 0) { continue; }
            $this->db->insert('cultures', $c + [
                'timezone' => 'Asia/Tehran',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * List active cultures.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(): array
    {
        return $this->db->select('SELECT * FROM cultures WHERE is_active = 1 ORDER BY sort_order ASC');
    }

    /**
     * Resolve the active culture from input, preference, cookie, or default.
     *
     * @param string|null $requested Requested culture code (query param).
     * @param string|null $cookie    Culture cookie value.
     * @param string|null $accept    Accept-Language header.
     * @return void
     */
    public function resolve(?string $requested = null, ?string $cookie = null, ?string $accept = null): void
    {
        $candidate = $requested ?? $cookie;
        if ($candidate !== null && $this->exists($candidate)) {
            $this->setActive($candidate);
            return;
        }
        if ($accept !== null) {
            if (stripos($accept, 'fa') !== false && $this->exists('fa-IR')) {
                $this->setActive('fa-IR');
                return;
            }
            if (stripos($accept, 'en') !== false && $this->exists('en-US')) {
                $this->setActive('en-US');
                return;
            }
        }
        $this->setActive($this->default);
    }

    /**
     * Activate a culture by code.
     *
     * @param string $code Culture code.
     * @return void
     */
    public function setActive(string $code): void
    {
        if (!$this->exists($code)) { $code = $this->default; }
        $this->activeCode = $code;
        $this->active = $this->load($code);
    }

    /**
     * Whether a culture code exists.
     *
     * @param string $code Culture code.
     * @return bool
     */
    public function exists(string $code): bool
    {
        if (is_file(BASE_PATH . '/cultures/' . $code . '.json')) { return true; }
        $count = $this->db->scalar('SELECT COUNT(*) FROM cultures WHERE code = :c', ['c' => $code]);
        return (int) $count > 0;
    }

    /**
     * Load and cache a culture definition.
     *
     * @param string $code Culture code.
     * @return array<string,mixed>
     */
    private function load(string $code): array
    {
        return $this->cache->get('cultures', $code, 86400, function () use ($code): array {
            $path = BASE_PATH . '/cultures/' . $code . '.json';
            if (!is_file($path)) {
                $path = BASE_PATH . '/cultures/' . $this->default . '.json';
            }
            $raw = is_file($path) ? (file_get_contents($path) ?: '{}') : '{}';
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        });
    }

    /** @return string Active culture code. */
    public function code(): string { return $this->activeCode; }

    /** @return string Active direction (rtl/ltr). */
    public function direction(): string { return (string) ($this->active['direction'] ?? 'rtl'); }

    /** @return string Active timezone. */
    public function timezone(): string { return (string) ($this->active['timezone'] ?? 'Asia/Tehran'); }

    /** @return string Active currency label. */
    public function currencyLabel(): string { return (string) ($this->active['currency_label'] ?? 'تومان'); }

    /** @return array<string,mixed> Full active culture definition. */
    public function definition(): array { return $this->active; }

    /**
     * Translate an error/status code into the active culture's message.
     *
     * @param string $code     Error code.
     * @param string $fallback Fallback message.
     * @return string
     */
    public function translate(string $code, string $fallback = ''): string
    {
        $messages = $this->active['messages'] ?? [];
        return (string) ($messages[$code] ?? ($fallback !== '' ? $fallback : $code));
    }

    /**
     * Translate a UI label key (best-effort; falls back to the key).
     *
     * @param string $key      Label key.
     * @param string $fallback Fallback.
     * @return string
     */
    public function label(string $key, string $fallback = ''): string
    {
        $labels = $this->active['labels'] ?? [];
        return (string) ($labels[$key] ?? ($fallback !== '' ? $fallback : $key));
    }

    /**
     * Format a UTC ISO timestamp as a Shamsi/local date string.
     *
     * @param string|null $utc     UTC ISO-8601 timestamp.
     * @param bool        $withTime Include time.
     * @return string Formatted display date (Persian digits for fa-IR).
     */
    public function formatDate(?string $utc, bool $withTime = false): string
    {
        if ($utc === null || $utc === '') { return '—'; }
        $ts = strtotime($utc);
        if ($ts === false) { return '—'; }
        $calendar = (string) ($this->active['calendar'] ?? 'jalali');
        if ($calendar === 'jalali') {
            $out = Jalali::format($ts, $withTime ? 'YYYY/MM/DD HH:mm' : 'YYYY/MM/DD');
        } else {
            $out = gmdate($withTime ? 'Y-m-d H:i' : 'Y-m-d', $ts);
        }
        return $this->digits($out);
    }

    /**
     * Shamsi date string for a UTC timestamp (Latin digits, machine form).
     *
     * @param string|null $utc UTC ISO timestamp.
     * @return string
     */
    public function jalali(?string $utc): string
    {
        if ($utc === null || $utc === '') { return '—'; }
        $ts = strtotime($utc);
        return $ts === false ? '—' : Jalali::format($ts, 'YYYY/MM/DD');
    }

    /**
     * Convert a Shamsi (Jalali) date string to a UTC start/end range.
     *
     * @param string $jalaliDate Shamsi date "YYYY/MM/DD" (Latin or Persian digits).
     * @param bool   $endOfDay   When true return end-of-day; else start-of-day.
     * @return string|null UTC ISO-8601, or null on parse failure.
     */
    public function jalaliToUtc(string $jalaliDate, bool $endOfDay = false): ?string
    {
        $value = normalize_digits($jalaliDate);
        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $value, $m) !== 1) {
            return null;
        }
        [$gy, $gm, $gd] = Jalali::jalaliToGregorian((int) $m[1], (int) $m[2], (int) $m[3]);
        $time = $endOfDay ? '23:59:59' : '00:00:00';
        return sprintf('%04d-%02d-%02dT%sZ', $gy, $gm, $gd, $time);
    }

    /**
     * Format a date for a native <input type="date"> (Gregorian, Latin).
     *
     * @param string|null $utc UTC timestamp.
     * @return string
     */
    public function inputDate(?string $utc): string
    {
        if ($utc === null || $utc === '') { return ''; }
        $ts = strtotime($utc);
        return $ts === false ? '' : gmdate('Y-m-d', $ts);
    }

    /**
     * Format an integer amount as Toman with the culture's separators.
     *
     * @param int|float|null $amount Amount in IRT.
     * @param bool           $withLabel Append the currency label.
     * @return string
     */
    public function money(int|float|null $amount, bool $withLabel = true): string
    {
        if ($amount === null) { return '—'; }
        $formatted = number_format((float) $amount, 0, '.', '٬');
        $formatted = $this->digits($formatted);
        if (!$withLabel) { return $formatted; }
        $label = $this->currencyLabel();
        $space = !empty($this->active['currency_space']) ? ' ' : '';
        return $formatted . $space . $label;
    }

    /**
     * Format a number with the culture's digits and separators.
     *
     * @param int|float|null $value Value.
     * @param int            $decimals Decimal places.
     * @return string
     */
    public function number(int|float|null $value, int $decimals = 0): string
    {
        if ($value === null) { return '—'; }
        $formatted = number_format((float) $value, $decimals, '.', '٬');
        return $this->digits($formatted);
    }

    /**
     * Convert Latin digits in a string to the culture's digit set.
     *
     * @param string $value Input.
     * @return string
     */
    public function digits(string $value): string
    {
        if (($this->active['digits'] ?? 'latin') !== 'persian') { return $value; }
        return to_persian_digits($value);
    }

    /**
     * Build the culture meta block for the JSON envelope.
     *
     * @return array{culture:string,direction:string,timezone:string}
     */
    public function meta(): array
    {
        return [
            'culture' => $this->activeCode,
            'direction' => $this->direction(),
            'timezone' => $this->timezone(),
        ];
    }
}
