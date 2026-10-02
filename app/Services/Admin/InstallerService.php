<?php
declare(strict_types=1);

/**
 * File: app/Services/Admin/InstallerService.php
 *
 * Purpose:
 *   Create the two SQLite databases, apply schemas, seed reference data,
 *   generate the application key, create the first Admin account, and
 *   self-lock the installer (Blueprint §22, Technical §25).
 *
 * Dependencies: Database (main + logs), SettingService, CultureService, LogService.
 *
 * @package App\Services\Admin
 */

namespace App\Services\Admin;

use App\Bootstrap\Database;

/**
 * Class: InstallerService
 * Purpose: Execute the one-time installation steps.
 */
final class InstallerService
{
    private Database $db;
    private Database $logsDb;
    private \App\Services\SettingService $settings;

    /**
     * @param Database                     $db       Main DB.
     * @param Database                     $logsDb   Logs DB.
     * @param \App\Services\SettingService $settings Settings.
     */
    public function __construct(Database $db, Database $logsDb, \App\Services\SettingService $settings)
    {
        $this->db = $db;
        $this->logsDb = $logsDb;
        $this->settings = $settings;
    }

    /**
     * Host requirement checklist used by the installer wizard.
     *
     * Checks the PHP version, the extensions the panel needs, an image
     * extension, and that every writable folder the runtime uses exists and
     * is writable. Missing folders are reported (not created) so the wizard
     * can show the operator exactly what to fix.
     *
     * @return array<int,array{label:string,ok:bool,detail:string}>
     */
    public function requirements(): array
    {
        $checks = [];
        $checks[] = ['label' => 'نسخه PHP (حداقل 8.1)', 'ok' => PHP_VERSION_ID >= 80100, 'detail' => PHP_VERSION];
        foreach (['pdo_sqlite', 'mbstring', 'json', 'openssl', 'fileinfo', 'curl', 'zip'] as $ext) {
            $checks[] = ['label' => 'افزونه ' . $ext, 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'فعال' : 'غیرفعال'];
        }
        $checks[] = [
            'label' => 'افزونه GD یا Imagick',
            'ok' => extension_loaded('gd') || extension_loaded('imagick'),
            'detail' => extension_loaded('gd') ? 'GD' : (extension_loaded('imagick') ? 'Imagick' : 'هیچ‌کدام'),
        ];
        foreach (['database', 'uploads', 'cache', 'logs', 'backups'] as $dir) {
            $path = BASE_PATH . '/' . $dir;
            $checks[] = [
                'label' => 'قابل نوشتن: ' . $dir,
                'ok' => is_dir($path) && is_writable($path),
                'detail' => is_dir($path) ? (is_writable($path) ? 'قابل نوشتن' : 'غیرقابل نوشتن') : 'وجود ندارد',
            ];
        }
        /* The panel advertises 25 MB documents (uploads.max_doc_mb) and 50k-row
           exports, but PHP itself rejects larger uploads first with
           UPLOAD_ERR_INI_SIZE ("Upload failed (error 1)"). Surface the ini
           limits so the operator can raise them before users hit that. */
        $iniUpload = self::iniBytes((string) ini_get('upload_max_filesize'));
        $iniPost = self::iniBytes((string) ini_get('post_max_size'));
        $minDoc = 25 * 1024 * 1024;
        $checks[] = [
            'label' => 'حداقل upload_max_filesize (۲۵ مگابایت)',
            'ok' => $iniUpload < 0 || $iniUpload >= $minDoc,
            'detail' => $iniUpload < 0 ? 'نامحدود' : human_filesize($iniUpload) . ($iniUpload >= $minDoc ? ' ✓' : ' — مقدار پیشنهادی 25M'),
        ];
        $checks[] = [
            'label' => 'حداقل post_max_size (۲۶ مگابایت)',
            'ok' => $iniPost < 0 || $iniPost >= 26 * 1024 * 1024,
            'detail' => $iniPost < 0 ? 'نامحدود' : human_filesize($iniPost) . ($iniPost >= 26 * 1024 * 1024 ? ' ✓' : ' — مقدار پیشنهادی 26M'),
        ];
        $memory = self::iniBytes((string) ini_get('memory_limit'));
        $checks[] = [
            'label' => 'حداقل memory_limit (۱۲۸ مگابایت)',
            'ok' => $memory < 0 || $memory >= 128 * 1024 * 1024,
            'detail' => $memory < 0 ? 'نامحدود' : human_filesize($memory),
        ];
        return $checks;
    }

    /**
     * Parse a php.ini shorthand size ("8M", "128M", "-1") into bytes.
     *
     * @param string $value ini value.
     * @return int Bytes, or -1 for unlimited.
     */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') { return -1; }
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Run the installation.
     *
     * @param string $username  Admin username.
     * @param string $phone     Admin phone (E.164).
     * @param string $password  Admin password.
     * @param string $firstName Admin first name.
     * @param string $lastName  Admin last name.
     * @return array<string,int|string> Installation summary.
     * @throws \App\Exceptions\ServerErrorException On schema or write failure.
     *
     * Side effects: creates app.sqlite + logs.sqlite, applies schemas, seeds
     *               reference data, creates the first Admin, sets app.installed.
     */
    public function install(string $username, string $phone, string $password, string $firstName, string $lastName): array
    {
        // 3. Create databases.
        $this->db->pdo();
        $this->logsDb->pdo();

        // 4. Apply schemas.
        $schema = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
        $logSchema = (string) file_get_contents(BASE_PATH . '/database/schema_logs.sql');
        $this->db->applyScript($schema);
        $this->logsDb->applyScript($logSchema);

        // 5. Generate the application key.
        $this->settings->seedDefaults();
        $this->settings->set('app.key', random_token(32));
        $this->settings->set('app.key_rotated_at', now_utc());

        // 6. Seed reference data.
        require_once BASE_PATH . '/database/seeds/reference.php';
        $counts = function_exists('seed_reference') ? seed_reference() : [];

        // 7. Create the first Admin.
        $now = now_utc();
        $existing = (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE role = :r', ['r' => 'admin']);
        if ($existing === 0) {
            $this->db->insert('users', [
                'uuid' => uuid4(),
                'role' => 'admin',
                'username' => $username,
                'phone' => $phone,
                'email' => null,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'national_id' => null,
                'avatar_media_id' => null,
                'disable_state' => 'none',
                'disable_reason' => null,
                'verification_status' => 'verified',
                'auto_verify_at' => null,
                'is_demo' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 9. Self-lock.
        $this->settings->set('app.installed', true);

        return [
            'installed' => 'ok',
            'references' => array_sum(array_map('intval', $counts)),
            'admin' => $username,
            'installed_at' => $now,
        ];
    }
}
