<?php
declare(strict_types=1);

/**
 * File: app/Bootstrap/Bootstrap.php
 *
 * Purpose:
 *   Build the service container, bind every core service, and expose the boot
 *   functions used by the front controller (Technical §4.1–4.2). All bindings
 *   are explicit (no auto-wiring).
 *
 *   Guest CSRF: a per-request token is generated for unauthenticated visitors
 *   and persisted to a `guest_csrf` cookie so that subsequent requests (POSTs
 *   of login/signup forms) observe the same token. Without this persistence,
 *   every request would mint a fresh token and all guest form submissions
 *   would fail CSRF validation.
 *
 * @package App\Bootstrap
 */

namespace App\Bootstrap;

use App\Exceptions\Handler;
use App\Http\Kernel;
use App\Services\Admin\BackupService;
use App\Services\Admin\DemoSeeder;
use App\Services\Admin\InstallerService;
use App\Services\AuthService;
use App\Services\BanService;
use App\Services\CacheService;
use App\Services\CaptchaService;
use App\Services\ClubService;
use App\Services\CompetitionService;
use App\Services\CultureService;
use App\Services\HorseService;
use App\Services\LogService;
use App\Services\MediaService;
use App\Services\NotificationService;
use App\Services\PaymentService;
use App\Services\RadeService;
use App\Services\Report\KpiService;
use App\Services\Report\ReportEngine;
use App\Services\ResultService;
use App\Services\SettingService;
use App\Services\SignupService;
use App\Services\SmsService;
use App\Services\UserService;
use App\Services\ViewService;

/**
 * Class: Bootstrap
 *
 * Purpose: Compose the container with all services and produce the Kernel.
 */
final class Bootstrap
{
    /**
     * Build the fully-wired container.
     *
     * @return Container
     */
    public static function container(): Container
    {
        $c = new Container();

        // Request id + guest CSRF (per-request scaffolding).
        $c->instance('request_id', uuid4());
        $guestCsrf = (string) ($_COOKIE['guest_csrf'] ?? '');
        if ($guestCsrf === '' || !preg_match('/^[a-f0-9]{16,128}$/i', $guestCsrf)) {
            $guestCsrf = random_token(32);
            // Persist the token so the next request (typically the POST that
            // follows a GET login/signup form render) sees the same value.
            // The cookie is HttpOnly — it is only ever read server-side by the
            // CSRF middleware; the token itself is echoed to the page in a
            // hidden form field and via the JSON envelope.
            if (!headers_sent()) {
                $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                setcookie('guest_csrf', $guestCsrf, [
                    'expires'  => time() + 3600,
                    'path'     => '/',
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }
        $c->instance('guest_csrf', $guestCsrf);

        // Cache.
        $c->singleton('cache', static fn(): CacheService => new CacheService(BASE_PATH . '/cache', true));

        // Databases (main + logs).
        $c->singleton('db', static function (Container $c): Database {
            return new Database(BASE_PATH . '/database/app.sqlite', static function (string $msg, array $ctx, int $ms) use ($c): void {
                try {
                    $c->get('log')->app('warning', $msg, $ctx, $ms);
                } catch (\Throwable) {
                }
            });
        });
        $c->singleton('logs_db', static fn(): Database => new Database(BASE_PATH . '/database/logs.sqlite'));

        // Settings + Culture.
        $c->singleton('settings', static fn(Container $c): SettingService => new SettingService($c->get('db'), $c->get('cache')));
        $c->singleton('culture', static fn(Container $c): CultureService => new CultureService($c->get('db'), $c->get('cache'), 'fa-IR'));

        // Logging.
        $c->singleton('log', static fn(Container $c): LogService => new LogService($c->get('logs_db'), BASE_PATH . '/logs', $c->get('settings')));

        // SMS + Media.
        $c->singleton('sms', static fn(Container $c): SmsService => new SmsService($c->get('settings'), $c->get('log'), $c->get('db')));
        $c->singleton('media', static fn(Container $c): MediaService => new MediaService($c->get('db'), $c->get('settings'), BASE_PATH . '/uploads'));

        // Notifications.
        $c->singleton('notifications', static fn(Container $c): NotificationService => new NotificationService($c->get('db'), $c->get('sms'), $c->get('log')));

        // Auth + Captcha.
        $c->singleton('auth', static fn(Container $c): AuthService => new AuthService($c->get('db'), $c->get('logs_db'), $c->get('settings'), $c->get('log'), $c->get('sms')));
        $c->singleton('captcha', static fn(Container $c): CaptchaService => new CaptchaService($c->get('db')));

        // Domain services.
        $c->singleton('users', static fn(Container $c): UserService => new UserService($c->get('db'), $c->get('settings'), $c->get('log'), $c->get('media'), $c->get('notifications')));
        $c->singleton('clubs', static fn(Container $c): ClubService => new ClubService($c->get('db'), $c->get('settings'), $c->get('log'), $c->get('media'), $c->get('notifications')));
        $c->singleton('horses', static fn(Container $c): HorseService => new HorseService($c->get('db'), $c->get('settings'), $c->get('log'), $c->get('media'), $c->get('notifications'), $c->get('sms')));
        $c->singleton('rades', static fn(Container $c): RadeService => new RadeService($c->get('db'), $c->get('log')));
        $c->singleton('payments', static fn(Container $c): PaymentService => new PaymentService($c->get('db'), $c->get('settings'), $c->get('log'), $c->get('notifications'), $c->get('sms')));
        $c->singleton('competitions', static fn(Container $c): CompetitionService => new CompetitionService($c->get('db'), $c->get('settings'), $c->get('log'), $c->get('notifications')));
        $c->singleton('bans', static fn(Container $c): BanService => new BanService($c->get('db'), $c->get('log'), $c->get('notifications'), $c->get('sms')));
        $c->singleton('signups', static fn(Container $c): SignupService => new SignupService($c->get('db'), $c->get('settings'), $c->get('log'), $c->get('payments'), $c->get('bans'), $c->get('clubs'), $c->get('notifications'), $c->get('sms')));
        $c->singleton('results', static fn(Container $c): ResultService => new ResultService($c->get('db'), $c->get('settings'), $c->get('log'), $c->get('notifications'), $c->get('sms')));

        // Reports.
        $c->singleton('reports', static fn(Container $c): ReportEngine => new ReportEngine($c->get('db'), $c->get('settings')));
        $c->singleton('kpi', static fn(Container $c): KpiService => new KpiService($c->get('db')));

        // Admin.
        $c->singleton('backup', static fn(Container $c): BackupService => new BackupService($c->get('db'), $c->get('logs_db'), $c->get('settings'), $c->get('cache'), $c->get('log'), BASE_PATH . '/backups'));
        $c->singleton('demo', static fn(Container $c): DemoSeeder => new DemoSeeder($c->get('db'), $c->get('settings'), $c->get('log')));
        $c->singleton('installer', static fn(Container $c): InstallerService => new InstallerService($c->get('db'), $c->get('logs_db'), $c->get('settings')));

        // View.
        $c->singleton('view', static fn(Container $c): ViewService => new ViewService($c, BASE_PATH . '/app/Views'));

        // Router + exception handler.
        $c->singleton('router', static function (): Router {
            /** @var array $definitions */
            $definitions = require BASE_PATH . '/app/Http/routes.php';
            return new Router($definitions);
        });
        $c->singleton('handler', static function (Container $c): Handler {
            $culture = $c->get('culture');
            $debug = false;
            try {
                $debug = (bool) $c->get('settings')->get('app.debug', false);
            } catch (\Throwable) {
                $debug = false;
            }
            return new Handler(
                $debug,
                null,
                static fn(string $code, string $fallback): string => $culture->translate($code, $fallback),
                null
            );
        });

        // Global access for helpers (container() in Helpers.php).
        $GLOBALS['__container'] = $c;

        return $c;
    }

    /**
     * Build the HTTP kernel.
     *
     * @param Container $c Container.
     * @return Kernel
     */
    public static function kernel(Container $c): Kernel
    {
        return new Kernel($c);
    }
}