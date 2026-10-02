<?php
declare(strict_types=1);

/**
 * File: app/Services/Admin/BackupService.php
 *
 * Purpose:
 *   Backup, restore, and reset operations (Blueprint §21, Technical §24).
 *   Backups zip app.sqlite, logs.sqlite, and uploads/ using VACUUM INTO for a
 *   consistent DB copy. Restore enters maintenance, snapshots, and re-injects
 *   the app key + current admin. Reset wipes data but preserves settings + admin.
 *
 * Dependencies: Database, Database (logs), SettingService, CacheService, LogService.
 *
 * @package App\Services\Admin
 */

namespace App\Services\Admin;

use App\Bootstrap\Database;
use App\Exceptions\ServerErrorException;
use ZipArchive;

/**
 * Class: BackupService
 * Purpose: Manage backup files and destructive maintenance flows.
 */
final class BackupService
{
    private Database $db;
    private Database $logsDb;
    private \App\Services\SettingService $settings;
    private \App\Services\CacheService $cache;
    private \App\Services\LogService $log;
    private string $backupsDir;
    /** @var bool Whether this process already holds the backup lock. */
    private bool $lockHeld = false;

    /**
     * @param Database                       $db         Main DB.
     * @param Database                       $logsDb     Logs DB.
     * @param \App\Services\SettingService   $settings   Settings.
     * @param \App\Services\CacheService     $cache      Cache.
     * @param \App\Services\LogService       $log        Logs.
     * @param string                         $backupsDir Backups directory.
     */
    public function __construct(Database $db, Database $logsDb, \App\Services\SettingService $settings, \App\Services\CacheService $cache, \App\Services\LogService $log, string $backupsDir)
    {
        $this->db = $db;
        $this->logsDb = $logsDb;
        $this->settings = $settings;
        $this->cache = $cache;
        $this->log = $log;
        $this->backupsDir = rtrim($backupsDir, '/');
    }

    /**
     * List existing backups newest-first.
     *
     * @return array<int,array{name:string,size:int,created_at:string}>
     */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->backupsDir . '/*.zip') ?: [] as $file) {
            $out[] = [
                'name' => basename($file),
                'size' => (int) filesize($file),
                'created_at' => utc_iso((int) filemtime($file)),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        return $out;
    }

    /**
     * Resolve the absolute path of a backup by name (guards traversal).
     *
     * @param string $name Backup filename.
     * @return string
     */
    public function path(string $name): string
    {
        return $this->backupsDir . '/' . basename($name);
    }

    /**
     * Create a backup zip.
     *
     * @param string $suffix Optional filename suffix.
     * @param int    $actorId Acting admin id.
     * @return array{name:string,size:int}
     * @throws ServerErrorException BACKUP_FAILED.
     *
     * Side effects: writes DB snapshots + a zip file in backups/; prunes old backups; audit.
     */
    public function create(string $suffix, int $actorId): array
    {
        // Serialise backup work: the cron job and a manual backup must never
        // zip the same files at once, and a restore must not race either.
        return $this->withLock(fn (): array => $this->createLocked($suffix, $actorId));
    }

    /**
     * Create a backup zip while the backup lock is held.
     *
     * @param string $suffix  Optional filename suffix.
     * @param int    $actorId Acting admin id.
     * @return array{name:string,size:int}
     * @throws ServerErrorException BACKUP_FAILED.
     *
     * Side effects: writes DB snapshots + a zip file in backups/; prunes old backups; audit.
     */
    private function createLocked(string $suffix, int $actorId): array
    {
        if (!is_dir($this->backupsDir)) {
            @mkdir($this->backupsDir, 0775, true);
        }
        $safeSuffix = preg_replace('/[^a-z0-9_-]/i', '', $suffix) ?: 'manual';
        $name = 'backup-' . gmdate('Y-m-d_His') . '-' . $safeSuffix . '.zip';
        $zipPath = $this->backupsDir . '/' . $name;

        $tmpDir = BASE_PATH . '/cache/backup-tmp-' . random_token(6);
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $appCopy = $tmpDir . '/app.sqlite';
        $logsCopy = $tmpDir . '/logs.sqlite';

        try {
            // Consistent DB copies via VACUUM INTO (exec() discards cached
            // prepared statements, which SQLite refuses to VACUUM past).
            $this->db->exec('VACUUM INTO ' . $this->db->pdo()->quote($appCopy));
            $this->logsDb->exec('VACUUM INTO ' . $this->logsDb->pdo()->quote($logsCopy));

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new ServerErrorException('BACKUP_FAILED', 'Cannot create zip', 500);
            }
            $zip->addFile($appCopy, 'app.sqlite');
            $zip->addFile($logsCopy, 'logs.sqlite');
            $this->addDirectory($zip, BASE_PATH . '/uploads', 'uploads');
            $zip->addFromString('meta.json', json_encode(['created_at' => now_utc(), 'version' => '1.0'], JSON_UNESCAPED_UNICODE));
            $zip->close();
        } catch (\Throwable $e) {
            $this->log->app('error', 'Backup failed: ' . $e->getMessage());
            throw new ServerErrorException('BACKUP_FAILED', 'Backup failed', 500);
        } finally {
            @array_map('unlink', glob($tmpDir . '/*') ?: []);
            @rmdir($tmpDir);
        }

        $this->prune((int) $this->settings->get('backup.retention_count', 10));
        $this->log->audit(['actor_id' => $actorId, 'actor_role' => 'admin', 'action' => 'backup.create', 'target_type' => 'backup', 'target_id' => 0, 'diff' => ['name' => $name]]);
        return ['name' => $name, 'size' => (int) filesize($zipPath)];
    }

    /**
     * Restore a backup (maintenance, snapshot, replace, re-inject key + admin).
     *
     * Per Blueprint §24.2, all sessions are revoked except the acting admin's
     * current one.
     *
     * @param string $name    Backup filename.
     * @param int    $actorId Acting admin id.
     * @param string $currentSessionId Current admin's session id to keep.
     * @return void
     * @throws ServerErrorException RESTORE_FAILED.
     */
    public function restore(string $name, int $actorId, string $currentSessionId = ''): void
    {
        $this->withLock(function () use ($name, $actorId, $currentSessionId): void {
            $this->restoreLocked($name, $actorId, $currentSessionId);
        });
    }

    /**
     * Restore a backup while the backup lock is held.
     *
     * @param string $name             Backup filename.
     * @param int    $actorId          Acting admin id.
     * @param string $currentSessionId Current admin's session id to keep.
     * @return void
     * @throws ServerErrorException RESTORE_FAILED.
     */
    private function restoreLocked(string $name, int $actorId, string $currentSessionId): void
    {
        $zipPath = $this->path($name);
        if (!is_file($zipPath)) {
            throw new ServerErrorException('RESTORE_FAILED', 'Backup not found', 500);
        }

        $this->settings->set('app.maintenance', true);
        try {
            $this->create('pre-restore', $actorId);
        } catch (\Throwable) {
        }
        $appKey = (string) $this->settings->get('app.key', '');
        $adminId = $actorId;

        $tmpDir = BASE_PATH . '/cache/restore-tmp-' . random_token(6);
        @mkdir($tmpDir, 0775, true);

        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new ServerErrorException('RESTORE_FAILED', 'Cannot open zip', 500);
            }
            $zip->extractTo($tmpDir);
            $zip->close();

            if (!is_file($tmpDir . '/app.sqlite')) {
                throw new ServerErrorException('RESTORE_FAILED', 'Archive missing app.sqlite', 500);
            }
            $dbFile = $this->db->file();
            $logFile = $this->logsDb->file();
            $this->closeConnections();
            /* Drop the WAL/SHM sidecars of the databases being replaced: a
               stale write-ahead log replayed over a freshly copied file is a
               corruption path. SQLite recreates them on the next open. */
            foreach ([$dbFile, $logFile] as $target) {
                @unlink($target . '-wal');
                @unlink($target . '-shm');
            }
            @copy($tmpDir . '/app.sqlite', $dbFile);
            if (is_file($tmpDir . '/logs.sqlite')) {
                @copy($tmpDir . '/logs.sqlite', $logFile);
            }
            if (is_dir($tmpDir . '/uploads')) {
                $this->rrmdir(BASE_PATH . '/uploads');
                @mkdir(BASE_PATH . '/uploads', 0775, true);
                $this->copyDir($tmpDir . '/uploads', BASE_PATH . '/uploads');
            }
            @array_map('unlink', glob($tmpDir . '/*.*') ?: []);
            $this->rrmdir($tmpDir);
        } catch (\Throwable $e) {
            $this->settings->set('app.maintenance', false);
            $this->log->app('error', 'Restore failed: ' . $e->getMessage());
            throw new ServerErrorException('RESTORE_FAILED', 'Restore failed', 500);
        }

        $this->settings->set('app.key', $appKey);

        // Revoke every session except the acting admin's current one.
        if ($currentSessionId !== '') {
            $this->db->execute('DELETE FROM sessions WHERE id != :sid', ['sid' => $currentSessionId]);
        } else {
            $this->db->execute('DELETE FROM sessions WHERE user_id != :admin', ['admin' => $adminId]);
        }

        $this->cache->clearAll();
        $this->settings->set('app.maintenance', false);
        $this->log->audit(['actor_id' => $actorId, 'actor_role' => 'admin', 'action' => 'backup.restore', 'target_type' => 'backup', 'target_id' => 0, 'diff' => ['name' => $name]]);
    }

    /**
     * Delete a backup file.
     *
     * @param string $name Backup filename.
     * @return void
     */
    public function delete(string $name): void
    {
        $path = $this->path($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Reset operational data: wipe all tables except settings, keep current admin.
     *
     * @param int    $adminId          Current admin id.
     * @param string $currentSessionId Current admin's session id.
     * @return void
     *
     * Side effects: truncates data; wipes uploads; preserves admin + session; audit.
     */
    public function reset(int $adminId, string $currentSessionId): void
    {
        $tables = [
            'signups',
            'payment_orders',
            'competition_rades',
            'competitions',
            'horse_images',
            'horse_shares',
            'horse_transfers',
            'horses',
            'club_bans',
            'rider_bans',
            'notifications',
            'message_recipients',
            'messages',
            'report_shares',
            'media',
            'clubs',
            'rider_profiles',
            'rate_limits',
            'password_resets',
            'api_tokens'
        ];
        $this->db->transaction(function () use ($tables, $adminId, $currentSessionId): void {
            foreach ($tables as $t) {
                $this->db->execute('DELETE FROM ' . $t);
            }
            if ($currentSessionId !== '') {
                $this->db->execute('DELETE FROM sessions WHERE id != :sid', ['sid' => $currentSessionId]);
            } else {
                $this->db->execute('DELETE FROM sessions');
            }
            $this->db->execute('DELETE FROM users WHERE id != :admin', ['admin' => $adminId]);
        });
        $this->rrmdir(BASE_PATH . '/uploads');
        @mkdir(BASE_PATH . '/uploads', 0775, true);
        /* Reclaim the space freed by the DELETEs; without a VACUUM the file
           keeps every page and grows forever. */
        try {
            $this->db->exec('VACUUM');
        } catch (\Throwable) {
            // A failed VACUUM must not fail the reset (data is already gone).
        }
        $this->cache->clearAll();
        $this->log->audit(['actor_id' => $adminId, 'actor_role' => 'admin', 'action' => 'system.reset', 'target_type' => 'system', 'target_id' => 0]);
    }

    /**
     * Prune old backups beyond the retention count.
     *
     * @param int $keep Number to keep.
     * @return void
     */
    private function prune(int $keep): void
    {
        $list = $this->list();
        foreach (array_slice($list, max(0, $keep)) as $old) {
            $this->delete($old['name']);
        }
    }

    /**
     * Add a directory recursively to a zip.
     *
     * @param ZipArchive $zip     Zip.
     * @param string     $dir     Source directory.
     * @param string     $prefix  Zip prefix.
     * @return void
     */
    private function addDirectory(ZipArchive $zip, string $dir, string $prefix): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item->isFile()) {
                $local = $prefix . '/' . substr($item->getPathname(), strlen($dir) + 1);
                $zip->addFile($item->getPathname(), $local);
            }
        }
    }

    /**
     * Run a callable while holding an exclusive backup lock.
     *
     * SQLite backup/restore rewrite database files; two concurrent runs (a
     * manual backup plus the cron job, or a restore racing a backup) could zip
     * or replace a half-written file. Reentrant within one process so
     * restore() can call create() for its pre-restore snapshot.
     *
     * @param callable $fn Work to run.
     * @return mixed Callback result.
     * @throws ServerErrorException When the lock cannot be acquired.
     */
    private function withLock(callable $fn): mixed
    {
        if ($this->lockHeld) { return $fn(); }
        if (!is_dir($this->backupsDir)) { @mkdir($this->backupsDir, 0775, true); }
        $handle = @fopen($this->backupsDir . '/.backup.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if ($handle !== false) { fclose($handle); }
            throw new ServerErrorException('BACKUP_FAILED', 'Backup storage is busy', 500);
        }
        $this->lockHeld = true;
        try {
            return $fn();
        } finally {
            $this->lockHeld = false;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Close PDO connections so files can be replaced.
     *
     * @return void
     */
    private function closeConnections(): void
    {
        // Actually drop the handles: SQLite may keep WAL/SHM state tied to
        // them, and restore() must not copy over a file with an open writer.
        $this->db->close();
        $this->logsDb->close();
    }

    /**
     * Recursively copy a directory.
     *
     * @param string $src Source.
     * @param string $dst Destination.
     * @return void
     */
    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            @mkdir($dst, 0775, true);
        }
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $s = $src . '/' . $entry;
            $d = $dst . '/' . $entry;
            if (is_dir($s)) {
                $this->copyDir($s, $d);
            } else {
                @copy($s, $d);
            }
        }
    }

    /**
     * Recursively remove a directory.
     *
     * @param string $dir Directory.
     * @return void
     */
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}