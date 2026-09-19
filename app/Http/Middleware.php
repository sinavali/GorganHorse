<?php
declare(strict_types=1);

/**
 * File: app/Http/Middleware.php
 *
 * Purpose:
 *   All middleware classes merged into one file (P23). The pipeline runs in the
 *   order defined by the Kernel: SecurityHeaders, Maintenance, Culture, RateLimit,
 *   Auth, DisabledUser, Role, Impersonation, Csrf (Technical §13).
 *
 * Conventions:
 *   - Each middleware receives the request context array and returns either null
 *     (continue) or a Response (short-circuit).
 *   - JSON requests receive JSON errors; HTML requests receive a redirect or page.
 *
 * @package App\Http
 */

namespace App\Http;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Exceptions\DomainException;
use App\Exceptions\RateLimitException;
use App\Exceptions\ValidationException;
use App\Services\AuthService;
use App\Services\CultureService;
use App\Services\SettingService;
use App\Services\UserService;

/**
 * Class: MiddlewareContext
 *
 * Purpose:
 *   Mutable per-request context shared across the pipeline. Holds resolved
 *   services, the authenticated user/session, CSRF token, and impersonation info.
 */
final class MiddlewareContext
{
    public ?array $user = null;
    public ?array $session = null;
    public string $csrf = '';
    public bool $pendingVerification = false;
    public ?int $impersonatedBy = null;
    /** @var string[] Middleware keys remaining to run. */
    public array $middleware = [];

    /**
     * @param Request        $request  Request.
     * @param SettingService $settings Settings.
     * @param CultureService $culture  Culture.
     * @param AuthService    $auth     Auth.
     * @param UserService    $users    Users.
     */
    public function __construct(
        public readonly Request $request,
        public readonly SettingService $settings,
        public readonly CultureService $culture,
        public readonly AuthService $auth,
        public readonly UserService $users,
    ) {
    }

    /** @return array Actor descriptor for service calls. */
    public function actor(): array
    {
        if ($this->user === null) {
            return ['id' => null, 'role' => 'guest'];
        }
        return [
            'id' => (int) $this->user['id'],
            'role' => (string) $this->user['role'],
            'verification_status' => $this->user['verification_status'] ?? 'verified',
            'impersonated_by' => $this->impersonatedBy,
            'ip' => $this->request->ip(),
            'ua_hash' => $this->request->uaHash(),
        ];
    }
}

/**
 * Class: Middleware
 *
 * Purpose: Static factory collection of the pipeline middlewares.
 */
final class Middleware
{
    /**
     * SecurityHeadersMiddleware: sets standard security headers.
     *
     * @param MiddlewareContext $ctx Context.
     * @param Response|null     $response Response being built (headers applied later).
     * @return Response|null Always null (headers set at emit time).
     */
    public static function securityHeaders(MiddlewareContext $ctx, ?Response $response = null): ?Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-XSS-Protection' => '1; mode=block',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        ];
        $csp = (string) $ctx->settings->get('security.headers_csp', '');
        if ($csp !== '') { $headers['Content-Security-Policy'] = $csp; }
        if ($ctx->request->scheme() === 'https') {
            $hsts = (string) $ctx->settings->get('security.headers_hsts', '');
            if ($hsts !== '') { $headers['Strict-Transport-Security'] = $hsts; }
        }
        foreach ($headers as $name => $value) {
            if (!headers_sent()) { header($name . ': ' . $value); }
        }
        return null;
    }

    /**
     * MaintenanceMiddleware: blocks panel routes when app.maintenance = 1.
     *
     * @param MiddlewareContext $ctx Context.
     * @return Response|null 423 response, or null to continue.
     */
    public static function maintenance(MiddlewareContext $ctx): ?Response
    {
        if (!(bool) $ctx->settings->get('app.maintenance', false)) { return null; }
        $path = $ctx->request->path();
        $allowed = ['/install', '/payment/callback', '/auth/logout', '/auth/login', '/payment/success', '/payment/failed'];
        foreach ($allowed as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix)) { return null; }
        }
        $message = (string) $ctx->settings->get('app.maintenance_message', 'Under maintenance');
        if ($ctx->request->isJson()) {
            return Response::json(['ok' => false, 'data' => null, 'errors' => [['code' => 'MAINTENANCE', 'field' => null, 'message' => $message]], 'meta' => [], 'csrf' => null], 423);
        }
        return Response::html('<h1>423</h1><p>' . e($message) . '</p>', 423);
    }

    /**
     * CultureMiddleware: resolves the active culture.
     *
     * @param MiddlewareContext $ctx Context.
     * @return Response|null Always null.
     */
    public static function culture(MiddlewareContext $ctx): ?Response
    {
        $requested = $ctx->request->query('culture');
        $cookie = $ctx->request->cookie('culture');
        $accept = $ctx->request->header('accept-language');
        $ctx->culture->resolve(
            is_string($requested) ? $requested : null,
            is_string($cookie) ? $cookie : null,
            is_string($accept) ? $accept : null
        );
        return null;
    }

    /**
     * AuthMiddleware: resolves the session; requires auth when flagged.
     *
     * @param MiddlewareContext $ctx      Context.
     * @param bool              $required Whether authentication is required.
     * @return Response|null 401/redirect when required and missing.
     */
    public static function auth(MiddlewareContext $ctx, bool $required = true): ?Response
    {
        $sessionId = (string) ($ctx->request->cookie('session_id') ?? '');
        $resolved = $ctx->auth->resolveSession($sessionId, $ctx->request->uaHash(), $ctx->request->ip());
        if ($resolved === null) {
            if (!$required) { return null; }
            if ($ctx->request->isJson()) {
                return Response::json(['ok' => false, 'data' => null, 'errors' => [['code' => 'AUTH_SESSION_EXPIRED', 'field' => null, 'message' => 'Session expired']], 'meta' => [], 'csrf' => null], 401);
            }
            return Response::redirect('/auth/login?expired=1');
        }
        $ctx->session = $resolved['session'];
        $ctx->user = $resolved['user'];
        $ctx->csrf = $resolved['csrf'];
        $ctx->impersonatedBy = $resolved['session']['impersonated_by'] !== null ? (int) $resolved['session']['impersonated_by'] : null;
        $ctx->pendingVerification = ($ctx->user['role'] === 'rider' && ($ctx->user['verification_status'] ?? '') === 'pending');

        // Opportunistic auto-verify when the window has elapsed.
        if ($ctx->pendingVerification) {
            $ctx->users->autoVerifyDue((int) $ctx->user['id']);
            $fresh = $ctx->users->get((int) $ctx->user['id']);
            $ctx->user['verification_status'] = $fresh['verification_status'];
            $ctx->pendingVerification = ($fresh['verification_status'] === 'pending');
        }
        return null;
    }

    /**
     * DisabledUserMiddleware: blocks full-disable; restricts limited writes.
     *
     * @param MiddlewareContext $ctx Context.
     * @return Response|null 403 when blocked.
     */
    public static function disabledUser(MiddlewareContext $ctx): ?Response
    {
        if ($ctx->user === null) { return null; }
        if (($ctx->user['disable_state'] ?? 'none') === 'full') {
            // Kill the session server-side and redirect the user to login.
            $sessionId = (string) ($ctx->request->cookie('session_id') ?? '');
            if ($sessionId !== '') {
                try { $ctx->auth->logout($sessionId); } catch (\Throwable) {}
            }
            if ($ctx->request->isJson()) {
                return Response::json(['ok' => false, 'data' => null, 'errors' => [['code' => 'USER_DISABLED_FULL', 'field' => null, 'message' => 'Account banned']], 'meta' => [], 'csrf' => null], 403);
            }
            $response = Response::redirect('/auth/login?banned=1');
            $response->withCookie('session_id', '', time() - 3600);
            return $response;
        }
        return null;
    }

    /**
     * RoleMiddleware: enforces allowed roles for the route.
     *
     * @param MiddlewareContext $ctx   Context.
     * @param string[]          $roles Allowed roles.
     * @return Response|null 403/404 when not permitted.
     */
    public static function role(MiddlewareContext $ctx, array $roles): ?Response
    {
        if ($ctx->user === null) { return null; }
        if (!in_array((string) $ctx->user['role'], $roles, true)) {
            if ($ctx->request->isJson()) {
                return Response::json(['ok' => false, 'data' => null, 'errors' => [['code' => 'FORBIDDEN', 'field' => null, 'message' => 'Forbidden']], 'meta' => [], 'csrf' => $ctx->csrf], 403);
            }
            return Response::html('<h1>403</h1><p>شما به این بخش دسترسی ندارید</p>', 403);
        }
        return null;
    }

    /**
     * ImpersonationMiddleware: blocks sensitive actions while impersonating.
     *
     * Blocked (non-GET) paths match Blueprint §17.11: settings, users,
     * password changes, transfers, shares, resets, backups, demo.
     *
     * @param MiddlewareContext $ctx Context.
     * @return Response|null 403 when a blocked action is attempted.
     */
    public static function impersonation(MiddlewareContext $ctx): ?Response
    {
        if ($ctx->impersonatedBy === null) { return null; }
        if ($ctx->request->isMethod('GET')) { return null; }

        $path = $ctx->request->path();
        $blocked = [
            '/panel/settings',
            '/panel/users',
            '/panel/reset',
            '/panel/demo',
            '/panel/backups',
            '/panel/profile/password',
            '/panel/impersonation/stop',
        ];
        $blockedPrefixes = [
            '/panel/horses/',   // catches transfer/*, share/*, images/*, etc.
        ];

        $isBlocked = false;
        foreach ($blocked as $b) {
            if ($path === $b || str_starts_with($path, $b . '/')) { $isBlocked = true; break; }
        }
        if (!$isBlocked) {
            foreach ($blockedPrefixes as $p) {
                if (str_starts_with($path, $p)) { $isBlocked = true; break; }
            }
        }
        if (!$isBlocked) { return null; }

        if ($ctx->request->isJson()) {
            return Response::json(['ok' => false, 'data' => null, 'errors' => [['code' => 'FORBIDDEN', 'field' => null, 'message' => 'Not allowed while impersonating']], 'meta' => [], 'csrf' => $ctx->csrf], 403);
        }
        return Response::html('<h1>403</h1><p>در حالت جعل هویت مجاز نیست</p>', 403);
    }

    /**
     * CsrfMiddleware: validates CSRF tokens on state-changing requests.
     *
     * Validates both authed sessions (session-bound token) and guest sessions
     * (guest_csrf instance token + signed guest cookie), per Blueprint §23.
     *
     * @param MiddlewareContext $ctx       Context.
     * @param bool              $guestMode When true the caller is a guest route.
     * @return Response|null 403 when the token is invalid.
     */
    public static function csrf(MiddlewareContext $ctx, bool $guestMode = false): ?Response
    {
        if (!in_array($ctx->request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) { return null; }

        $token = (string) ($ctx->request->header('x-csrf-token') ?? $ctx->request->input('_csrf', '') ?? '');
        $expected = $ctx->csrf;

        // Guest CSRF: use the per-request token registered at boot.
        if ($ctx->user === null && $guestMode) {
            try {
                $expected = (string) \App\Support\container('guest_csrf');
            } catch (\Throwable) {
                $expected = '';
            }
            // Fall back to the cookie-bound token so multi-tab guests stay consistent.
            $cookie = (string) ($ctx->request->cookie('guest_csrf') ?? '');
            if ($expected === '' && $cookie !== '') { $expected = $cookie; }
        }

        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            if ($ctx->request->isJson()) {
                return Response::json(['ok' => false, 'data' => null, 'errors' => [['code' => 'AUTH_CSRF_INVALID', 'field' => null, 'message' => 'Invalid request']], 'meta' => [], 'csrf' => $ctx->csrf], 403);
            }
            return Response::html('<h1>403</h1><p>درخواست نامعتبر است</p>', 403);
        }
        return null;
    }

    /**
     * RateLimitMiddleware: applies a named rate limit rule.
     *
     * Concrete limiting for auth flows is enforced inside AuthService; this
     * middleware remains as an extension point (e.g. signup IP caps).
     *
     * @param MiddlewareContext $ctx  Context.
     * @param string            $rule Rule name (login, signup, otp).
     * @return Response|null 429 when exceeded.
     */
    public static function rateLimit(MiddlewareContext $ctx, string $rule): ?Response
    {
        try {
            $db = \App\Support\container('db');
            if (!$db instanceof \App\Bootstrap\Database) { return null; }
            $ip = $ctx->request->ip();
            $bucket = 'route:' . $rule . ':' . $ip;
            $window = 3600;
            $cap = match ($rule) {
                'signup' => 3,
                'login'  => 20,
                'otp'    => 10,
                default  => 60,
            };
            $hits = (int) $db->scalar(
                'SELECT COUNT(*) FROM rate_limits WHERE bucket = :b AND window_start > :w',
                ['b' => $bucket, 'w' => utc_iso(time() - $window)]
            );
            if ($hits >= $cap) {
                if ($ctx->request->isJson()) {
                    return Response::json(['ok' => false, 'data' => null, 'errors' => [['code' => 'RATE_LIMITED', 'field' => null, 'message' => 'Too many requests']], 'meta' => [], 'csrf' => $ctx->csrf], 429);
                }
                return Response::html('<h1>429</h1><p>تعداد درخواست‌ها بیش از حد مجاز است</p>', 429);
            }
            $db->insert('rate_limits', [
                'bucket' => $bucket,
                'hits' => 1,
                'window_start' => now_utc(),
                'updated_at' => now_utc(),
            ]);
        } catch (\Throwable) {
            // Rate limiting must never break the request.
        }
        return null;
    }
}