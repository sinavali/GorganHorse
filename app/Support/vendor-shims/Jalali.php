<?php
declare(strict_types=1);

/**
 * File: app/Support/vendor-shims/Jalali.php
 *
 * Purpose:
 *   Dependency-free Jalali (Shamsi) <-> Gregorian conversion, standing in for
 *   morilog/jalali. Implements the well-known algorithm by Kazimierz M. Borkowski
 *   / jalaali-js (MIT). Correct for the years 1800-2200 CE.
 *
 * Used by:
 *   - CultureService (display formatting)
 *   - ReportEngine (Shamsi filter ranges -> UTC)
 *   - DemoSeeder (converting historical Jalali dates to UTC)
 *
 * @package App\Support\Vendor
 */

namespace App\Support\Vendor;

/**
 * Class: Jalali
 *
 * Purpose: Convert between Gregorian and Jalali calendars and format Jalali dates.
 */
final class Jalali
{
    /** @var int[] Cumulative day counts before each Jalali month. */
    private const JALALI_MONTH_DAYS = [0, 31, 62, 93, 124, 155, 186, 216, 246, 276, 306, 336];

    /**
     * Determine whether a Jalali year is a leap year.
     *
     * @param int $jy Jalali year.
     * @return bool
     */
    public static function isLeapYear(int $jy): bool
    {
        return (((($jy - 979) % 33) % 4) === 1);
    }

    /**
     * Number of days in a Jalali month.
     *
     * @param int $jy    Jalali year.
     * @param int $jm    Jalali month (1-12).
     * @return int
     */
    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) { return 31; }
        if ($jm <= 11) { return 30; }
        return self::isLeapYear($jy) ? 30 : 29;
    }

    /**
     * Convert a Gregorian date to Jalali.
     *
     * @param int $gy Gregorian year.
     * @param int $gm Gregorian month (1-12).
     * @param int $gd Gregorian day (1-31).
     * @return array{0:int,1:int,2:int} [jy, jm, jd]
     */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $gDaysInMonth[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    /**
     * Convert a Jalali date to Gregorian.
     *
     * @param int $jy Jalali year.
     * @param int $jm Jalali month (1-12).
     * @param int $jd Jalali day (1-31).
     * @return array{0:int,1:int,2:int} [gy, gm, gd]
     */
    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd
            + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) { $days++; }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0));
        $salt = [0, 31, ($leap ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 1; $gm <= 12; $gm++) {
            if ($gd <= $salt[$gm]) { break; }
            $gd -= $salt[$gm];
        }
        return [$gy, $gm, $gd];
    }

    /**
     * Format a Unix timestamp as a Jalali date string.
     *
     * @param int    $timestamp Unix timestamp (UTC).
     * @param string $format    Token format: YYYY, MM, DD, HH, mm, ss and separators.
     * @return string Jalali date in Latin digits.
     */
    public static function format(int $timestamp, string $format = 'YYYY/MM/DD'): string
    {
        [$jy, $jm, $jd] = self::gregorianToJalali(
            (int) gmdate('Y', $timestamp),
            (int) gmdate('n', $timestamp),
            (int) gmdate('j', $timestamp)
        );
        $hh = gmdate('H', $timestamp);
        $mm = gmdate('i', $timestamp);
        $ss = gmdate('s', $timestamp);
        return strtr($format, [
            'YYYY' => sprintf('%04d', $jy),
            'MM' => sprintf('%02d', $jm),
            'DD' => sprintf('%02d', $jd),
            'HH' => $hh,
            'mm' => $mm,
            'ss' => $ss,
        ]);
    }
}
