<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/InstallController.php
 *
 * Purpose:
 *   The installer wizard (Blueprint §22, Technical §25). Checks requirements,
 *   creates the databases, applies schemas, seeds reference data, creates the
 *   first Admin, optionally seeds demo data, and self-locks.
 *
 *   The installer auto-creates the writable directories it depends on
 *   (database, uploads, cache, logs, backups) plus the runtime subfolders
 *   (logs/app, logs/audit, cache/*), so a fresh copy-paste deployment does not
 *   need those folders to be pre-created by hand.
 *
 *   Validation failures return the full list of per-field errors so the
 *   installer UI can highlight each offending field.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Envelope;
use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;
use App\Support\Vendor\PhoneValidator;

/**
 * Class: InstallController
 * Purpose: Run the one-time installation workflow.
 */
final class InstallController extends BaseController
{
    /**
     * Writable directories the installer requires.
     *
     * @var string[]
     */
    private const REQUIRED_DIRS = ['database', 'uploads', 'cache', 'logs', 'backups'];

    /**
     * Runtime subfolders created eagerly (also created lazily at runtime, but
     * pre-creating them here makes the requirement check pass on first boot).
     *
     * @var string[]
     */
    private const RUNTIME_SUBDIRS = [
        'logs/app',
        'logs/audit',
        'cache/settings',
        'cache/cultures',
        'cache/thumbs',
        'cache/exports',
        'cache/captcha',
        'cache/captcha-guest',
        'uploads/avatars',
        'uploads/horses',
        'uploads/clubs',
        'uploads/demo',
    ];

    /**
     * Render the installer page (or a locked message).
     *
     * Route:   GET /install
     * Auth:    guest
     * Returns: HTML installer
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $this->ensureDirectories();

        if ($this->isInstalled()) {
            return Response::html('<div style="font-family:Tahoma;text-align:center;padding:48px"><h2>سامانه قبلاً نصب شده است</h2><p><a href="/auth/login">ورود به پنل</a></p></div>');
        }
        return $this->view('auth/install', [
            'requirements' => $this->checkRequirements(),
            'csrf' => $this->c->get('guest_csrf'),
        ], 'auth');
    }

    /**
     * Run the installer.
     *
     * Route:   POST /install/run
     * Auth:    guest
     * Params:  admin_username, admin_phone, admin_password, admin_first_name, admin_last_name, seed_demo?
     * Returns: JSON envelope { data: { installed: true, redirect } }
     *          On validation failure: 422 with a per-field error list.
     */
    public function run(Request $request, MiddlewareContext $ctx): Response
    {
        $this->ensureDirectories();

        if ($this->isInstalled()) {
            return $this->fail('FORBIDDEN', 'Already installed', $ctx, 403);
        }
        $input = $this->input($request);
        $errors = [];
        $username = trim((string) ($input['admin_username'] ?? 'admin'));
        $phone = PhoneValidator::toE164((string) ($input['admin_phone'] ?? ''));
        $password = (string) ($input['admin_password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $username)) {
            $errors['admin_username'] = 'نام کاربری نامعتبر است (فقط حروف انگلیسی، رقم، نقطه، خط تیره؛ ۳ تا ۳۲ نویسه).';
        }
        if ($phone === null) {
            $errors['admin_phone'] = 'شماره موبایل نامعتبر است. نمونه درست: 09123456789';
        }
        if (mb_strlen($password) < 8) {
            $errors['admin_password'] = 'رمز عبور باید حداقل ۸ نویسه باشد.';
        }
        if ($errors !== []) {
            $list = [];
            foreach ($errors as $field => $message) {
                $list[] = ['code' => 'VALIDATION_FAILED', 'field' => $field, 'message' => $message];
            }
            return Response::json(Envelope::error($list, $this->meta($ctx)), 422);
        }

        $installer = $this->c->get('installer');
        $result = $installer->install($username, $phone, $password, (string) ($input['admin_first_name'] ?? 'مدیر'), (string) ($input['admin_last_name'] ?? 'سامانه'));

        if (!empty($input['seed_demo'])) {
            $this->c->get('demo')->seed();
        }

        return $this->ok(['installed' => true, 'redirect' => '/auth/login', 'summary' => $result], $ctx, 201);
    }

    /**
     * Ensure every writable directory the installer and runtime depend on
     * exists. Missing directories are created with mode 0775 (recursive).
     *
     * @return void
     *
     * Side effects: creates directories under BASE_PATH.
     */
    private function ensureDirectories(): void
    {
        foreach (self::REQUIRED_DIRS as $dir) {
            $path = BASE_PATH . '/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
        foreach (self::RUNTIME_SUBDIRS as $sub) {
            $path = BASE_PATH . '/' . $sub;
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
        $this->writeHtaccess(BASE_PATH . '/logs', "Require all denied\n\nOptions -Indexes\n\n<FilesMatch \"\\.(log|jsonl)$\">\n    Require all denied\n</FilesMatch>\n");
        $this->writeHtaccess(BASE_PATH . '/cache', "Require all denied\n\nOptions -Indexes\n");
        $this->writeHtaccess(BASE_PATH . '/backups', "Require all denied\n\nOptions -Indexes\n");
        $this->writeHtaccess(BASE_PATH . '/uploads', "php_flag engine off\nOptions -Indexes -ExecCGI\n");
    }

    /**
     * Write an .htaccess file if it does not already exist.
     *
     * @param string $dir     Directory.
     * @param string $content File body.
     * @return void
     */
    private function writeHtaccess(string $dir, string $content): void
    {
        $path = $dir . '/.htaccess';
        if (is_dir($dir) && !is_file($path)) {
            @file_put_contents($path, $content);
        }
    }

    /**
     * Whether the app is already installed.
     *
     * @return bool
     */
    private function isInstalled(): bool
    {
        try {
            $value = $this->c->get('settings')->get('app.installed', false);
            return (bool) $value;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check runtime requirements.
     *
     * @return array<int,array{label:string,ok:bool,detail:string}>
     */
    private function checkRequirements(): array
    {
        $checks = [];
        $checks[] = ['label' => 'نسخه PHP (حداقل 8.1)', 'ok' => PHP_VERSION_ID >= 80100, 'detail' => PHP_VERSION];
        foreach (['pdo_sqlite', 'mbstring', 'json', 'openssl', 'fileinfo', 'curl', 'zip'] as $ext) {
            $checks[] = ['label' => 'افزونه ' . $ext, 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'فعال' : 'غیرفعال'];
        }
        $checks[] = ['label' => 'افزونه GD یا Imagick', 'ok' => extension_loaded('gd') || extension_loaded('imagick'), 'detail' => extension_loaded('gd') ? 'GD' : (extension_loaded('imagick') ? 'Imagick' : 'هیچ‌کدام')];
        foreach (self::REQUIRED_DIRS as $dir) {
            $path = BASE_PATH . '/' . $dir;
            $checks[] = ['label' => 'قابل نوشتن: ' . $dir, 'ok' => is_dir($path) && is_writable($path), 'detail' => is_dir($path) ? (is_writable($path) ? 'قابل نوشتن' : 'غیرقابل نوشتن') : 'وجود ندارد'];
        }
        return $checks;
    }
}