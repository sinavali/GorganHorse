<?php
declare(strict_types=1);

/**
 * File: app/Support/vendor-shims/PhoneValidator.php
 *
 * Purpose:
 *   Iranian phone number and national-ID validation, standing in for
 *   giggsey/libphonenumber-for-php for the panel's Iran-only use case.
 *
 *   Iranian mobile numbers are +98 followed by 10 digits, the first of which
 *   is always 9 (e.g. 0912 345 6789 → +989123456789). The E.164 regex therefore
 *   requires exactly 9 digits after the leading 9.
 *
 * Used by:
 *   - Validation (User, Club, Installer, Auth)
 *   - DemoSeeder (generating valid Iranian phone numbers and national IDs)
 *
 * @package App\Support\Vendor
 */

namespace App\Support\Vendor;

/**
 * Class: PhoneValidator
 *
 * Purpose: Normalise and validate Iranian mobile numbers and national IDs.
 */
final class PhoneValidator
{
    /**
     * Normalise an Iranian phone number to E.164 (+98XXXXXXXXXX).
     *
     * Accepts: "09123456789", "9123456789", "+989123456789", "00989123456789",
     * with Persian or Arabic digits.
     *
     * @param string $input Raw input.
     * @return string|null E.164 number, or null when invalid.
     */
    public static function toE164(string $input): ?string
    {
        $value = normalize_digits(trim($input));
        $value = str_replace([' ', '-', '(', ')'], '', $value);
        if (str_starts_with($value, '0098')) {
            $value = '+98' . substr($value, 4);
        } elseif (str_starts_with($value, '+98')) {
            // already E.164
        } elseif (str_starts_with($value, '98') && strlen($value) === 12) {
            $value = '+98' . substr($value, 2);
        } elseif (str_starts_with($value, '0') && strlen($value) === 11) {
            $value = '+98' . substr($value, 1);
        } elseif (strlen($value) === 10 && str_starts_with($value, '9')) {
            $value = '+98' . $value;
        } else {
            return null;
        }
        // +98, then a leading 9, then exactly 9 more digits (10 digits total).
        if (preg_match('/^\+989\d{9}$/', $value) !== 1) {
            return null;
        }
        return $value;
    }

    /**
     * Whether the input is a valid Iranian mobile number.
     *
     * @param string $input Raw input.
     * @return bool
     */
    public static function isValidMobile(string $input): bool
    {
        return self::toE164($input) !== null;
    }

    /**
     * Validate the Iranian national-ID (کد ملی) 10-digit check algorithm.
     *
     * @param string $input Raw input (Persian/Latin digits).
     * @return bool
     */
    public static function isValidNationalId(string $input): bool
    {
        $value = normalize_digits(trim($input));
        if (preg_match('/^\d{10}$/', $value) !== 1) {
            return false;
        }
        if (preg_match('/^(\d)\1{9}$/', $value) === 1) {
            return false;
        }
        $check = (int) $value[9];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $value[$i]) * (10 - $i);
        }
        $rem = $sum % 11;
        return ($rem < 2) ? ($check === $rem) : ($check === (11 - $rem));
    }
}