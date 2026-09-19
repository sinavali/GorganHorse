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
