<?php
declare(strict_types=1);

/**
 * File: app/Services/SettingService.php
 *
 * Purpose:
 *   Owns the settings registry (Blueprint §15). Settings live in the `settings`
 *   table only (P11: no .env, no config/*.php). This service seeds defaults,
 *   reads values with type casting, writes values, and invalidates the
 *   `settings` cache namespace on write.
 *
 * Dependencies:
 *   - Database (main)
 *   - CacheService
 *
 * Conventions:
 *   - Keys use dot notation {group}.{key}.
 *   - Values are stored as text and cast on read according to TYPE.
 *   - The full registry is documented in defaults().
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;

/**
 * Class: SettingService
 *
 * Purpose:
 *   Registry + accessor for all panel settings.
 *
 * Side effects:
 *   - Writes to the `settings` table.
 *   - Clears the `settings` cache namespace on write.
 */
final class SettingService
{
    private Database $db;
    private CacheService $cache;
    /** @var array<string,array{type:string,grp:string,label:string,help:string,default:mixed}>|null */
    private static ?array $registryCache = null;

    /**
     * @param Database     $db    Main database.
     * @param CacheService $cache Cache service.
     */
    public function __construct(Database $db, CacheService $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * The complete settings registry (key => metadata).
     *
     * Documented once here as the authoritative source for defaults, types,
     * group, label, and help text (Blueprint §15, Technical §32.6).
     *
     * @return array<string,array{type:string,grp:string,label:string,help:string,default:mixed}>
     */
    public static function registry(): array
    {
        if (self::$registryCache !== null) {
            return self::$registryCache;
        }
        return self::$registryCache = [
            // --- general ---
            'app.name' => self::def('string', 'general', 'نام برنامه', 'نام نمایشی سامانه.', 'هیئت سوارکاری استان گلستان'),
            'app.tagline' => self::def('string', 'general', 'شعار', 'زیرعنوان نمایشی سامانه.', 'سامانه مدیریت مسابقات'),
            'app.env' => self::def('string', 'general', 'محیط', 'production یا local.', 'production'),
            'app.debug' => self::def('bool', 'general', 'حالت اشکال‌زدایی', 'نمایش جزئیات خطا.', false),
            'app.maintenance' => self::def('bool', 'general', 'حالت تعمیر', 'در صورت فعال بودن، پنل غیرفعال می‌شود.', false),
            'app.maintenance_message' => self::def('string', 'general', 'پیام تعمیر', 'پیام نمایش‌داده‌شده در حالت تعمیر.', 'سامانه موقتاً در حال تعمیر است.'),
            'app.timezone_default' => self::def('string', 'general', 'منطقه زمانی', 'منطقه زمانی پیش‌فرض.', 'Asia/Tehran'),
            'app.default_culture' => self::def('string', 'general', 'زبان پیش‌فرض', 'کد زبان پیش‌فرض.', 'fa-IR'),
            'app.url_force_https' => self::def('bool', 'general', 'اجبار HTTPS', 'هدایت همه درخواست‌ها به HTTPS.', false),
            'app.url_trusted_proxies' => self::def('json', 'general', 'پروکسی‌های مورد اعتماد', 'فهرست IP پروکسی.', []),
            // --- identity [system] ---
            'app.key' => self::def('string', 'identity', 'کلید برنامه', 'کلید رمزنگاری و امضای داخلی.', ''),
            'app.key_rotated_at' => self::def('string', 'identity', 'زمان چرخش کلید', 'آخرین زمان تغییر کلید.', ''),
            'app.installed' => self::def('bool', 'identity', 'نصب‌شده', 'آیا نصب انجام شده است.', false),
            // --- whitelabel ---
            'brand.name' => self::def('string', 'whitelabel', 'نام برند', 'نام نمایشی برند.', 'هیئت سوارکاری استان گلستان'),
            'brand.logo_light_media_id' => self::def('int', 'whitelabel', 'لوگوی روشن', 'شناسه رسانه لوگو.', 0),
            'brand.logo_dark_media_id' => self::def('int', 'whitelabel', 'لوگوی تیره', 'شناسه رسانه لوگوی تیره.', 0),
            'brand.favicon_media_id' => self::def('int', 'whitelabel', 'فاوآیکون', 'شناسه رسانه فاوآیکون.', 0),
            'brand.primary_color' => self::def('string', 'whitelabel', 'رنگ اصلی', 'رنگ اصلی برند.', '#0f766e'),
            'brand.secondary_color' => self::def('string', 'whitelabel', 'رنگ ثانویه', 'رنگ ثانویه برند.', '#b45309'),
            'brand.login_background_media_id' => self::def('int', 'whitelabel', 'پس‌زمینه ورود', 'شناسه رسانه پس‌زمینه صفحه ورود.', 0),
            'brand.footer_text' => self::def('string', 'whitelabel', 'متن پانویس', 'متن پانویس.', ''),
            'brand.copyright' => self::def('string', 'whitelabel', 'حق تکثیر', 'متن حق تکثیر.', '© هیئت سوارکاری استان گلستان'),
            'brand.pdf_header_text' => self::def('string', 'whitelabel', 'سرصفحه PDF', 'متن سرصفحه چاپ.', ''),
            'brand.pdf_footer_text' => self::def('string', 'whitelabel', 'پاصفحه PDF', 'متن پاصفحه چاپ.', ''),
            // --- auth ---
            'auth.allow_signup' => self::def('bool', 'auth', 'اجازه ثبت‌نام', 'امکان ثبت‌نام جدید.', true),
            'auth.password_min_length' => self::def('int', 'auth', 'حداقل طول رمز', 'حداقل تعداد نویسه رمز.', 8),
            'auth.password_require_upper' => self::def('bool', 'auth', 'نیاز به حرف بزرگ', 'اجبار حرف بزرگ.', false),
            'auth.password_require_digit' => self::def('bool', 'auth', 'نیاز به رقم', 'اجبار رقم.', false),
            'auth.password_require_symbol' => self::def('bool', 'auth', 'نیاز به نماد', 'اجبار نماد.', false),
            'auth.session_absolute_days' => self::def('int', 'auth', 'عمر نشست (روز)', 'عمر مطلق نشست.', 90),
            'auth.captcha_on_login' => self::def('bool', 'auth', 'کپچا در ورود', 'نمایش کپچا در ورود.', false),
            'auth.captcha_on_signup' => self::def('bool', 'auth', 'کپچا در ثبت‌نام', 'نمایش کپچا در ثبت‌نام.', false),
            'auth.captcha_on_otp_request' => self::def('bool', 'auth', 'کپچا در OTP', 'نمایش کپچا در درخواست OTP.', false),
            'auth.rate_login_per_window' => self::def('int', 'auth', 'سقف ورود', 'تعداد تلاش در بازه.', 5),
            'auth.rate_login_window_seconds' => self::def('int', 'auth', 'بازه ورود (ثانیه)', 'طول بازه محدودیت.', 300),
            'auth.rate_ip_hourly_cap' => self::def('int', 'auth', 'سقف ساعتی IP', 'حداکثر تلاش در ساعت.', 20),
            'auth.auto_verify_hours' => self::def('int', 'auth', 'تایید خودکار (ساعت)', 'زمان تایید خودکار سوارکار.', 48),
            // --- uploads ---
            'uploads.max_image_mb' => self::def('int', 'uploads', 'حداکثر تصویر (MB)', 'حداکثر اندازه تصویر.', 10),
            'uploads.max_doc_mb' => self::def('int', 'uploads', 'حداکثر سند (MB)', 'حداکثر اندازه سند.', 25),
            'uploads.user_quota_mb' => self::def('int', 'uploads', 'سهم کاربر (MB)', 'حداکثر حجم فایل‌های کاربر.', 500),
            'uploads.image_max_dimension' => self::def('int', 'uploads', 'حداکثر ابعاد تصویر', 'بزرگ‌ترین ضلع تصویر.', 2560),
            'uploads.image_quality' => self::def('int', 'uploads', 'کیفیت تصویر', 'کیفیت فشرده‌سازی (۰-۱۰۰).', 82),
            'uploads.image_format' => self::def('string', 'uploads', 'قالب تصویر', 'original یا webp.', 'original'),
            'uploads.allowed_image_mimes' => self::def('json', 'uploads', 'MIMEهای مجاز تصویر', 'فهرست MIME مجاز.', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']),
            'uploads.path_pattern' => self::def('string', 'uploads', 'الگوی مسیر', 'الگوی مسیر ذخیره.', '{yyyy}/{mm}/{uuid}.{ext}'),
            // --- clubs ---
            'clubs.default_logo_media_id' => self::def('int', 'clubs', 'لوگوی پیش‌فرض', 'شناسه لوگوی پیش‌فرض.', 0),
            'clubs.enable_bans' => self::def('bool', 'clubs', 'فعال‌سازی تحریم باشگاه', 'امکان تحریم توسط باشگاه.', true),
            'clubs.ban_expiry_days' => self::def('int', 'clubs', 'انقضای تحریم (روز)', 'مدت پیش‌فرض تحریم.', 365),
            'clubs.max_affiliated_riders' => self::def('int', 'clubs', 'حداکثر سوارکار وابسته', 'صفر = بی‌نهایت.', 0),
            // --- horses ---
            'horses.max_images' => self::def('int', 'horses', 'حداکثر تصاویر اسب', 'تعداد تصاویر هر اسب.', 5),
            'horses.share_code_length' => self::def('int', 'horses', 'طول کد اشتراک', 'طول کد اشتراک.', 6),
            'horses.transfer_code_length' => self::def('int', 'horses', 'طول کد انتقال', 'طول کد انتقال.', 8),
            'horses.transfer_expiry_days' => self::def('int', 'horses', 'انقضای انتقال (روز)', 'مدت اعتبار کد انتقال.', 7),
            'horses.allow_transfer' => self::def('bool', 'horses', 'اجازه انتقال', 'امکان انتقال اسب.', true),
            'horses.allow_sharing' => self::def('bool', 'horses', 'اجازه اشتراک', 'امکان اشتراک اسب.', true),
            // --- competitions ---
            'competitions.default_capacity' => self::def('int', 'competitions', 'ظرفیت پیش‌فرض', 'صفر = بی‌نهایت.', 0),
            'competitions.allow_late_entries' => self::def('bool', 'competitions', 'اجازه ثبت دیرهنگام', 'امکان ثبت پس از بسته شدن.', true),
            'competitions.auto_status_change' => self::def('bool', 'competitions', 'تغییر خودکار وضعیت', 'تغییر وضعیت بر اساس زمان.', true),
            'competitions.registration_pause_notice' => self::def('string', 'competitions', 'پیام توقف ثبت‌نام', 'متن نمایش‌داده‌شده هنگام توقف.', 'ثبت‌نام موقتاً متوقف شده است.'),
            'competitions.result_entry_requires_confirmation' => self::def('bool', 'competitions', 'نیاز به تایید نتایج', 'نتایج پیش از انتشار تایید شوند.', true),
            // --- payments ---
            'payment.gateway' => self::def('string', 'payments', 'درگاه پرداخت', 'درگاه فعال.', 'zarinpal'),
            'payment.zarinpal_merchant_id' => self::def('string', 'payments', 'شناسه پذیرنده زرین‌پال', 'Merchant ID.', ''),
            'payment.currency' => self::def('string', 'payments', 'واحد پول', 'IRT.', 'IRT'),
            'payment.callback_url' => self::def('string', 'payments', 'آدرس بازگشت', 'callback_url زرین‌پال.', ''),
            'payment.success_redirect' => self::def('string', 'payments', 'هدایت موفق', 'آدرس پس از پرداخت موفق.', '/payment/success'),
            'payment.failed_redirect' => self::def('string', 'payments', 'هدایت ناموفق', 'آدرس پس از پرداخت ناموفق.', '/payment/failed'),
            'payment.gateway_enabled' => self::def('bool', 'payments', 'فعال بودن درگاه', 'در صورت غیرفعال بودن، پرداخت ممکن نیست.', true),
            // --- sms ---
            'sms.enabled' => self::def('bool', 'sms', 'فعال بودن پیامک', 'فعال‌سازی ارسال پیامک.', false),
            'sms.username' => self::def('string', 'sms', 'نام کاربری پیامک', 'نام کاربری ملی‌پیامک.', ''),
            'sms.password' => self::def('string', 'sms', 'رمز پیامک', 'کلید API ملی‌پیامک.', ''),
            'sms.sender_number' => self::def('string', 'sms', 'شماره فرستنده', 'شماره ارسال.', ''),
            'sms.otp_pattern' => self::def('string', 'sms', 'الگوی OTP', 'کد الگوی OTP.', ''),
            'sms.otp_ttl_seconds' => self::def('int', 'sms', 'اعتبار OTP (ثانیه)', 'مدت اعتبار کد.', 120),
            'sms.otp_max_attempts' => self::def('int', 'sms', 'حداکثر تلاش OTP', 'تلاش مجاز.', 3),
            'sms.otp_length' => self::def('int', 'sms', 'طول OTP', 'تعداد ارقام.', 5),
            'sms.notify_on_signup' => self::def('bool', 'sms', 'اعلان ثبت‌نام', 'پیامک هنگام ثبت‌نام.', true),
            'sms.notify_on_payment' => self::def('bool', 'sms', 'اعلان پرداخت', 'پیامک هنگام پرداخت.', true),
            'sms.notify_on_confirmation' => self::def('bool', 'sms', 'اعلان تایید', 'پیامک هنگام تایید.', true),
            'sms.notify_on_results' => self::def('bool', 'sms', 'اعلان نتایج', 'پیامک هنگام انتشار نتایج.', true),
            'sms.notify_on_transfer' => self::def('bool', 'sms', 'اعلان انتقال', 'پیامک هنگام انتقال.', true),
            'sms.notify_on_ban' => self::def('bool', 'sms', 'اعلان تحریم', 'پیامک هنگام تحریم.', true),
            'sms.rate_limit_per_hour' => self::def('int', 'sms', 'سقف ساعتی پیامک', 'حداکثر پیامک در ساعت.', 10),
            // --- reports ---
            'reports.default_per_page' => self::def('int', 'reports', 'تعداد سطر پیش‌فرض', 'تعداد سطر هر صفحه.', 50),
            'reports.max_export_rows' => self::def('int', 'reports', 'حداکثر سطر خروجی', 'حداکثر سطر خروجی.', 50000),
            'reports.enable_grid_state_persistence' => self::def('bool', 'reports', 'ذخیره وضعیت جدول', 'ذخیره وضعیت جدول در مرورگر.', true),
            'reports.print_header_text' => self::def('string', 'reports', 'سرصفحه گزارش', 'متن سرصفحه چاپ.', ''),
            'reports.print_footer_text' => self::def('string', 'reports', 'پاصفحه گزارش', 'متن پاصفحه چاپ.', ''),
            // --- cache ---
            'cache.enabled' => self::def('bool', 'cache', 'فعال بودن کش', 'فعال‌سازی کش فایل.', true),
            'cache.ttl_settings' => self::def('int', 'cache', 'TTL تنظیمات', 'مدت کش تنظیمات.', 3600),
            'cache.ttl_cultures' => self::def('int', 'cache', 'TTL زبان‌ها', 'مدت کش زبان‌ها.', 86400),
            'cache.ttl_thumbs' => self::def('int', 'cache', 'TTL بندانگشتی', 'مدت کش تصاویر بندانگشتی.', 604800),
            // --- logs ---
            'logs.app_retention_days' => self::def('int', 'logs', 'نگهداری لاگ برنامه', 'مدت نگهداری لاگ برنامه.', 30),
            'logs.audit_retention_days' => self::def('int', 'logs', 'نگهداری حسابرسی', 'مدت نگهداری لاگ حسابرسی.', 180),
            'logs.sms_retention_days' => self::def('int', 'logs', 'نگهداری پیامک', 'مدت نگهداری لاگ پیامک.', 90),
            'logs.login_retention_days' => self::def('int', 'logs', 'نگهداری ورود', 'مدت نگهداری لاگ ورود.', 30),
            'logs.cleanup_probability' => self::def('float', 'logs', 'احتمال پاکسازی', 'احتمال اجرای پاکسازی.', 0.01),
            // --- backup ---
            'backup.include_shares' => self::def('bool', 'backup', 'شامل اشتراک‌ها', 'شامل پوشه اشتراک در پشتیبان.', false),
            'backup.retention_count' => self::def('int', 'backup', 'تعداد پشتیبان', 'تعداد پشتیبان نگهداری‌شده.', 10),
            'backup.suffix_default' => self::def('string', 'backup', 'پسوند پیش‌فرض', 'پسوند نام پشتیبان.', 'manual'),
            // --- security ---
            'security.headers_csp' => self::def('string', 'security', 'CSP', 'سیاست امنیت محتوا.', "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"),
            'security.headers_hsts' => self::def('string', 'security', 'HSTS', 'هدر HSTS.', 'max-age=31536000; includeSubDomains'),
            'security.cookie_same_site' => self::def('string', 'security', 'SameSite کوکی', 'مقدار SameSite.', 'lax'),
            'security.trusted_ips_admin' => self::def('json', 'security', 'IPهای مورد اعتماد', 'IPهای مجاز مدیر.', []),
            // --- ui ---
            'ui.font_primary' => self::def('string', 'ui', 'فونت اصلی', 'نام فونت.', 'Vazirmatn'),
            'ui.panel_path' => self::def('string', 'ui', 'مسیر پنل', 'پیشوند مسیر پنل.', 'panel'),
            'ui.default_avatar_admin_text' => self::def('string', 'ui', 'آواتار مدیر', 'حرف آواتار مدیر.', 'A'),
            'ui.default_avatar_manager_text' => self::def('string', 'ui', 'آواتار مدیر عملیاتی', 'حرف آواتار مدیر عملیاتی.', 'M'),
            'ui.items_per_page' => self::def('int', 'ui', 'تعداد آیتم هر صفحه', 'اندازه صفحه پیش‌فرض.', 25),
        ];
    }

    /**
     * Build a registry definition row.
     *
     * @param string $type    Setting type.
     * @param string $grp     Group.
     * @param string $label   Label.
     * @param string $help    Help text.
     * @param mixed  $default Default value.
     * @return array{type:string,grp:string,label:string,help:string,default:mixed}
     */
    private static function def(string $type, string $grp, string $label, string $help, mixed $default): array
    {
        return ['type' => $type, 'grp' => $grp, 'label' => $label, 'help' => $help, 'default' => $default];
    }

    /**
     * Seed all registry defaults into the settings table (idempotent).
     *
     * @return int Number of settings inserted.
     */
    public function seedDefaults(): int
    {
        $inserted = 0;
        $now = now_utc();
        foreach (self::registry() as $key => $meta) {
            $exists = $this->db->scalar('SELECT COUNT(*) FROM settings WHERE key = :k', ['k' => $key]);
            if ((int) $exists > 0) { continue; }
            $this->db->insert('settings', [
                'key' => $key,
                'value' => $this->serialize($meta['default'], $meta['type']),
                'type' => $meta['type'],
                'grp' => $meta['grp'],
                'label' => $meta['label'],
                'help' => $meta['help'],
                'updated_at' => $now,
            ]);
            $inserted++;
        }
        $this->cache->clearNamespace('settings');
        return $inserted;
    }

    /**
     * Load all settings as a key => casted-value map (cached).
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->cache->get('settings', 'all', 3600, function (): array {
            // Registry defaults are always available (used before the schema
            // exists, e.g. by the installer, and for any not-yet-seeded key).
            $out = [];
            foreach (self::registry() as $key => $meta) {
                $out[$key] = $meta['default'];
            }
            try {
                $rows = $this->db->select('SELECT key, value, type FROM settings');
                foreach ($rows as $row) {
                    $out[$row['key']] = $this->deserialize((string) $row['value'], (string) $row['type']);
                }
            } catch (\Throwable) {
                // Settings table not present yet (fresh install); defaults stand.
            }
            return $out;
        });
    }

    /**
     * Read a setting value.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Fallback when unknown.
     * @return mixed Casted value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    /**
     * Write a setting value (upsert), invalidating the settings cache.
     *
     * @param string $key   Setting key.
     * @param mixed  $value New value.
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $registry = self::registry();
        $meta = $registry[$key] ?? ['type' => 'string', 'grp' => 'general', 'label' => $key, 'help' => ''];
        $now = now_utc();
        $exists = $this->db->scalar('SELECT COUNT(*) FROM settings WHERE key = :k', ['k' => $key]);
        if ((int) $exists > 0) {
            $this->db->update('settings', [
                'value' => $this->serialize($value, $meta['type']),
                'type' => $meta['type'],
                'updated_at' => $now,
            ], 'key = :k', ['k' => $key]);
        } else {
            $this->db->insert('settings', [
                'key' => $key,
                'value' => $this->serialize($value, $meta['type']),
                'type' => $meta['type'],
                'grp' => $meta['grp'],
                'label' => $meta['label'] ?? $key,
                'help' => $meta['help'] ?? '',
                'updated_at' => $now,
            ]);
        }
        $this->cache->clearNamespace('settings');
    }

    /**
     * Set many settings at once.
     *
     * @param array<string,mixed> $values Key => value.
     * @return void
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::registry())) {
                $this->set($key, $value);
            }
        }
    }

    /**
     * Fetch settings grouped for the settings page.
     *
     * @return array<string,array<int,array{key:string,value:mixed,type:string,label:string,help:string}>>
     */
    public function grouped(): array
    {
        $all = $this->all();
        $out = [];
        foreach (self::registry() as $key => $meta) {
            $out[$meta['grp']][] = [
                'key' => $key,
                'value' => $all[$key] ?? $meta['default'],
                'type' => $meta['type'],
                'label' => $meta['label'],
                'help' => $meta['help'],
            ];
        }
        return $out;
    }

    /**
     * Serialize a value for storage.
     *
     * @param mixed  $value Value.
     * @param string $type  Setting type.
     * @return string
     */
    private function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => ($value ? '1' : '0'),
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[]',
            'int' => (string) (int) $value,
            'float' => (string) (float) $value,
            default => (string) $value,
        };
    }

    /**
     * Deserialize a stored value according to type.
     *
     * @param string $value Value.
     * @param string $type  Setting type.
     * @return mixed
     */
    private function deserialize(string $value, string $type): mixed
    {
        return match ($type) {
            'bool' => $value === '1' || $value === 'true',
            'json' => json_decode($value, true) ?? [],
            'int' => (int) $value,
            'float' => (float) $value,
            default => $value,
        };
    }
}
