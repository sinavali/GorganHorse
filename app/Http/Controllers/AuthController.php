<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/AuthController.php
 *
 * Purpose:
 *   HTTP layer for authentication: login form + submit, OTP request/verify,
 *   rider signup, forgot page, captcha image/issue, and logout (Blueprint §10.1).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;
use App\Services\AuthService;

/**
 * Class: AuthController
 *
 * Purpose: Handle guest authentication endpoints.
 */
final class AuthController extends BaseController
{
    /**
     * Render the login page.
     *
     * Route:   GET /auth/login
     * Auth:    guest
     */
    public function loginForm(Request $request, MiddlewareContext $ctx): Response
    {
        $smsEnabled = (bool) $ctx->settings->get('sms.enabled', false);
        return $this->view('auth/login', [
            'sms_enabled' => $smsEnabled,
            'captcha_on_login' => (bool) $ctx->settings->get('auth.captcha_on_login', false),
            'allow_signup' => (bool) $ctx->settings->get('auth.allow_signup', true),
            'expired' => (bool) $request->query('expired', false),
            'banned' => (bool) $request->query('banned', false),
            'csrf' => $this->guestCsrf($ctx),
        ], 'auth');
    }

    /**
     * Handle password login.
     *
     * Route:   POST /auth/login
     * Auth:    guest
     */
    public function login(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);

        if ((bool) $ctx->settings->get('auth.captcha_on_login', false)) {
            $this->assertCaptcha($request, $ctx, $input);
        }

        /** @var AuthService $auth */
        $auth = $this->c->get('auth');
        $result = $auth->login(
            (string) ($input['identifier'] ?? ''),
            (string) ($input['password'] ?? ''),
            $request->ip(),
            $request->uaHash()
        );
        $response = $this->ok([
            'role' => $result['role'],
            'pending_verification' => $result['pending_verification'],
            'redirect' => '/panel',
        ], $ctx, 200, ['csrf' => $result['csrf']]);
        $response->withCookie('session_id', $result['session_id'], time() + ((int) $ctx->settings->get('auth.session_absolute_days', 90) * 86400));
        $response->withCookie('culture', $ctx->culture->code(), time() + 31536000, '/', false);
        return $response;
    }

    /**
     * Request an OTP code for login.
     *
     * Route:   POST /auth/login/otp/request
     * Auth:    guest
     */
    public function requestOtp(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);

        if ((bool) $ctx->settings->get('auth.captcha_on_otp_request', false)) {
            $this->assertCaptcha($request, $ctx, $input);
        }

        $phone = $this->c->get('auth')->requestOtp((string) ($input['phone'] ?? ''), $request->ip());
        $masked = substr($phone, 0, 7) . str_repeat('*', max(0, strlen($phone) - 9)) . substr($phone, -2);
        return $this->ok(['phone' => $masked, 'ttl' => (int) $ctx->settings->get('sms.otp_ttl_seconds', 120)], $ctx);
    }

    /**
     * Verify an OTP code and start a session.
     *
     * Route:   POST /auth/login/otp/verify
     * Auth:    guest
     */
    public function verifyOtp(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $result = $this->c->get('auth')->verifyOtp(
            (string) ($input['phone'] ?? ''),
            (string) ($input['code'] ?? ''),
            $request->ip(),
            $request->uaHash()
        );
        $response = $this->ok([
            'role' => $result['role'],
            'pending_verification' => $result['pending_verification'],
            'redirect' => '/panel',
        ], $ctx, 200, ['csrf' => $result['csrf']]);
        $response->withCookie('session_id', $result['session_id'], time() + ((int) $ctx->settings->get('auth.session_absolute_days', 90) * 86400));
        return $response;
    }

    /**
     * Render the signup page.
     *
     * Route:   GET /auth/signup
     * Auth:    guest
     */
    public function signupForm(Request $request, MiddlewareContext $ctx): Response
    {
        if (!(bool) $ctx->settings->get('auth.allow_signup', true)) {
            return Response::redirect('/auth/login');
        }
        return $this->view('auth/signup', [
            'captcha_on_signup' => (bool) $ctx->settings->get('auth.captcha_on_signup', false),
            'password_min_length' => (int) $ctx->settings->get('auth.password_min_length', 8),
            'csrf' => $this->guestCsrf($ctx),
        ], 'auth');
    }

    /**
     * Create a rider account.
     *
     * Route:   POST /auth/signup
     * Auth:    guest
     */
    public function signup(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);

        if ((bool) $ctx->settings->get('auth.captcha_on_signup', false)) {
            $this->assertCaptcha($request, $ctx, $input);
        }

        $result = $this->c->get('auth')->signup($input);
        return $this->ok([
            'username' => $result['username'],
            'auto_verify_at' => $result['auto_verify_at'],
            'redirect' => '/auth/login',
        ], $ctx, 201);
    }

    /**
     * Render the forgot-password information page.
     *
     * Route:   GET /auth/forgot
     * Auth:    guest
     */
    public function forgot(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->view('auth/forgot', ['csrf' => $this->guestCsrf($ctx)], 'auth');
    }

    /**
     * Render a captcha image for a token.
     *
     * Route:   GET /captcha/{token}
     * Auth:    guest
     */
    public function captcha(Request $request, MiddlewareContext $ctx): Response
    {
        $token = (string) $request->attr('token', '');
        $code = $this->c->get('captcha')->peek($token);
        if ($code === null) {
            return Response::html('', 404);
        }
        return $this->c->get('captcha')->render($code);
    }

    /**
     * Issue a captcha challenge bound to the current guest session.
     *
     * Route:   POST /captcha/issue
     * Auth:    guest
     * Returns: JSON envelope { data: { token, image_url, guest_token } }
     */
    public function issueCaptcha(Request $request, MiddlewareContext $ctx): Response
    {
        $sessionId = (string) ($request->cookie('session_id') ?? '');
        $guestToken = (string) ($request->cookie('guest_csrf') ?? '');

        $newGuestToken = false;
        if ($guestToken === '') {
            $guestToken = random_token(32);
            $newGuestToken = true;
        }

        $issued = $this->c->get('captcha')->issue($sessionId, $guestToken);
        $response = $this->ok([
            'token' => $issued['token'],
            'image_url' => '/captcha/' . $issued['token'],
            'guest_token' => $guestToken,
        ], $ctx);
        if ($newGuestToken) {
            $response->withCookie('guest_csrf', $guestToken, time() + 3600, '/', false, 'Lax');
        }
        return $response;
    }

    /**
     * Log out the current session.
     *
     * Route:   POST /auth/logout
     * Auth:    guest/auth
     */
    public function logout(Request $request, MiddlewareContext $ctx): Response
    {
        $sessionId = (string) ($request->cookie('session_id') ?? '');
        if ($sessionId !== '') {
            $this->c->get('auth')->logout($sessionId);
        }
        $response = $this->ok(['redirect' => '/auth/login'], $ctx);
        $response->withCookie('session_id', '', time() - 3600);
        return $response;
    }

    /**
     * Validate a captcha challenge or throw a 422 ValidationException.
     */
    private function assertCaptcha(Request $request, MiddlewareContext $ctx, array $input): void
    {
        $token = (string) ($input['captcha_token'] ?? $input['captcha'] ?? '');
        $answer = (string) ($input['captcha_answer'] ?? '');
        $sessionId = (string) ($request->cookie('session_id') ?? '');
        $guestToken = (string) ($request->cookie('guest_csrf') ?? '');
        if ($token === '' || $answer === ''
            || !$this->c->get('captcha')->verify($sessionId, $token, $answer, $guestToken)) {
            throw new \App\Exceptions\DomainException('CAPTCHA_INVALID', 'Invalid captcha', 422, 'captcha_answer');
        }
    }

    /**
     * Provide a CSRF token for guest forms (bound to a pre-auth session).
     */
    private function guestCsrf(MiddlewareContext $ctx): string
    {
        return (string) $this->c->get('guest_csrf');
    }
}