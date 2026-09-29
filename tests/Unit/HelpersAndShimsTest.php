<?php
declare(strict_types=1);

/**
 * File: tests/Unit/HelpersAndShimsTest.php
 * Purpose: Unit tests for helper functions and vendor shims.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__DIR__)));
}
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/app/Support/Helpers.php';

use App\Support\Vendor\HtmlSanitizer;
use App\Support\Vendor\ImageProcessor;
use App\Support\Vendor\Jalali;
use App\Support\Vendor\Markdown;
use App\Support\Vendor\PhoneValidator;

final class HelpersAndShimsTest
{
    public static function run(): void
    {
        echo "Running Helpers & Shims Unit Tests...\n";

        // 1. Helpers HTML escaping
        assert(e('<script>alert("xss")</script>') === '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;');
        assert(e(null) === '');

        // 2. Slugify
        assert(!empty(slugify('test-slug-123')));

        // 3. Timestamps
        $now = now_utc();
        assert(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $now) === 1);
        $iso = utc_iso(1700000000);
        assert($iso === '2023-11-14T22:13:20Z');

        // 4. Digits & Formatting
        assert(normalize_digits('۰۹۱۲۳۴۵۶۷۸۹') === '09123456789');
        assert(to_persian_digits('09123456789') === '۰۹۱۲۳۴۵۶۷۸۹');

        // 5. Tokens, UUID, Randoms, Strings, Arrays, Filesize
        assert(preg_match('/^[a-f0-9-]{36}$/', uuid4()) === 1);
        assert(strlen(random_token(16)) === 32);
        assert(preg_match('/^\d{6}$/', random_digits(6)) === 1);
        assert(strlen(random_alnum(10)) === 10);
        assert(array_get(['a' => ['b' => 'val']], 'a.b') === 'val');
        assert(str_limit('long string text here', 5) === 'long …');
        assert(human_filesize(1048576) === '1 MB');

        // 6. PhoneValidator
        assert(PhoneValidator::isValid('09123456789') === true);
        assert(PhoneValidator::toE164('09123456789') === '+989123456789');
        assert(PhoneValidator::isValidNationalId('0010350829') === true);
        assert(PhoneValidator::isValidNationalId('12345') === false);

        // 7. Jalali Conversion
        [$jy, $jm, $jd] = Jalali::gregorianToJalali(2023, 11, 14);
        assert($jy === 1402 && $jm === 8 && $jd === 23);
        [$gy, $gm, $gd] = Jalali::jalaliToGregorian(1402, 8, 23);
        assert($gy === 2023 && $gm === 11 && $gd === 14);
        assert(!empty(Jalali::format(time(), 'YYYY/MM/DD')));

        // 8. HtmlSanitizer
        $dirty = '<p>Test <script>alert(1)</script> <a href="javascript:alert(1)" onclick="bad()">Link</a></p>';
        $clean = HtmlSanitizer::clean($dirty);
        assert(str_contains($clean, '<script>') === false);
        assert(str_contains($clean, 'javascript:') === false);

        // 9. Markdown
        $md = "# Header\n**Bold** text";
        $html = Markdown::render($md);
        assert(str_contains($html, '<h1>Header</h1>'));
        assert(str_contains($html, '<strong>Bold</strong>'));

        // 10. ImageProcessor
        if (extension_loaded('gd')) {
            $tmpImg = BASE_PATH . '/cache/test_img.png';
            $im = imagecreatetruecolor(100, 100);
            imagepng($im, $tmpImg);
            imagedestroy($im);

            $thumb = BASE_PATH . '/cache/test_thumb.png';
            ImageProcessor::thumbnail($tmpImg, $thumb, 50, 50);
            assert(is_file($thumb));
            @unlink($tmpImg);
            @unlink($thumb);
        }

        echo "  Helpers & Shims Unit Tests Passed!\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    HelpersAndShimsTest::run();
}
