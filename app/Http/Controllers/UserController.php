<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/UserController.php
 *
 * Purpose:
 *   HTTP layer for the unified users module: list, create, view, update, delete,
 *   verify, reject, disable/enable, reset password, impersonate, session
 *   revocation, and bulk operations (Blueprint §10.3, User Usage §7.2–7.3).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: UserController
 * Purpose: Handle user management endpoints.
 */
final class UserController extends BaseController
{
    /**
     * List users (role-scoped).
     *
     * Route:   GET /panel/users
     * Auth:    role:admin,manager
     * Params:  role?, status?, search?, page?, per_page?
     * Returns: HTML page (browser) or JSON envelope (API)
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $service = $this->c->get('users');
        $filters = [
            'role' => (string) $request->query('role', ''),
            'status' => (string) $request->query('status', ''),
            'search' => (string) $request->query('search', ''),
        ];
        $result = $service->list($filters, $ctx->actor(), $this->page($request), $this->perPage($request));
        if ($request->isJson()) {
            return $this->ok($result, $ctx, 200, ['total' => $result['total'], 'filtered' => $result['total']]);
        }
        return $this->view('panel/users', [
            'rows' => $result['rows'], 'total' => $result['total'], 'filters' => $filters,
            'csrf' => $ctx->csrf, 'role' => $ctx->user['role'],
        ]);
    }

    /**
     * Create a user.
     *
     * Route:   POST /panel/users
     * Auth:    role:admin,manager
     * Params:  role, username, phone, password, first_name, last_name, national_id
     * Returns: JSON envelope { data: { id, uuid } }
     */
    public function store(Request $request, MiddlewareContext $ctx): Response
    {
        $result = $this->c->get('users')->create($this->input($request), $ctx->actor());
        return $this->ok($result, $ctx, 201);
    }

    /**
     * Show a user detail page or JSON.
     *
     * Route:   GET /panel/users/{id}
     * Auth:    role:admin,manager (or self)
     * Params:  id (route)
     * Returns: HTML page or JSON envelope
     */
    public function show(Request $request, MiddlewareContext $ctx): Response
    {
        $id = (int) $request->attr('id');
        if (!in_array($ctx->user['role'], ['admin', 'manager'], true) && (int) $ctx->user['id'] !== $id) {
            return $this->fail('FORBIDDEN', 'Forbidden', $ctx, 403);
        }
        $user = $this->c->get('users')->get($id);
        if ($request->isJson()) { return $this->ok($user, $ctx); }
        return $this->view('panel/user-edit', ['record' => $user, 'csrf' => $ctx->csrf, 'role' => $ctx->user['role']]);
    }

    /**
     * Update a user.
     *
     * Route:   PUT /panel/users/{id}
     * Auth:    role:admin,manager (or self)
     * Returns: JSON envelope { data: { id } }
     */
    public function update(Request $request, MiddlewareContext $ctx): Response
    {
        $id = (int) $request->attr('id');
        $result = $this->c->get('users')->update($id, $this->input($request), $ctx->actor());
        return $this->ok($result, $ctx);
    }

    /**
     * Delete a user (Admin only, typed confirmation enforced client-side).
     *
     * Route:   DELETE /panel/users/{id}
     * Auth:    role:admin
     * Returns: JSON envelope { data: null }
     */
    public function destroy(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('users')->delete((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Verify a pending rider.
     *
     * Route:   POST /panel/users/{id}/verify
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id, verified_at } }
     */
    public function verify(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('users')->verify((int) $request->attr('id'), $ctx->actor()), $ctx);
    }

    /**
     * Reject a pending rider.
     *
     * Route:   POST /panel/users/{id}/reject
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function reject(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('users')->reject((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Disable a user (limited/full).
     *
     * Route:   POST /panel/users/{id}/disable
     * Auth:    role:admin,manager
     * Params:  state, reason
     * Returns: JSON envelope { data: null }
     */
    public function disable(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $this->c->get('users')->setDisableState((int) $request->attr('id'), (string) ($input['state'] ?? 'limited'), (string) ($input['reason'] ?? ''), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Enable a user (clear disable state).
     *
     * Route:   POST /panel/users/{id}/enable
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function enable(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('users')->setDisableState((int) $request->attr('id'), 'none', '', $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Reset a user's password (manual, by manager/admin).
     *
     * Route:   POST /panel/users/{id}/reset-password
     * Auth:    role:admin,manager
     * Params:  password
     * Returns: JSON envelope { data: null }
     */
    public function resetPassword(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $this->c->get('users')->resetPassword((int) $request->attr('id'), (string) ($input['password'] ?? ''), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Impersonate a user (Admin only).
     *
     * Route:   POST /panel/users/{id}/impersonate
     * Auth:    role:admin
     * Returns: JSON envelope { data: { redirect } }
     */
    public function impersonate(Request $request, MiddlewareContext $ctx): Response
    {
        $targetId = (int) $request->attr('id');
        $target = $this->c->get('users')->get($targetId);
        $session = $this->c->get('auth')->createSession($targetId, (string) $target['role'], $request->ip(), $request->uaHash(), (int) $ctx->user['id']);
        $this->c->get('log')->audit([
            'actor_id' => (int) $ctx->user['id'], 'actor_role' => 'admin', 'action' => 'user.impersonate',
            'target_type' => 'user', 'target_id' => $targetId, 'diff' => ['impersonated_role' => $target['role']],
        ]);
        $response = $this->ok(['redirect' => '/panel'], $ctx, 200, ['csrf' => $session['csrf']]);
        $response->withCookie('session_id', $session['id'], time() + ((int) $ctx->settings->get('auth.session_absolute_days', 90) * 86400));
        return $response;
    }

    /**
     * Stop impersonating and restore the admin session.
     *
     * Route:   POST /panel/impersonation/stop
     * Auth:    auth
     * Returns: JSON envelope { data: { redirect } }
     */
    public function stopImpersonation(Request $request, MiddlewareContext $ctx): Response
    {
        if ($ctx->impersonatedBy === null) { return $this->fail('FORBIDDEN', 'Not impersonating', $ctx, 403); }
        $admin = $this->c->get('users')->get($ctx->impersonatedBy);
        $this->c->get('auth')->logout((string) ($request->cookie('session_id') ?? ''));
        $session = $this->c->get('auth')->createSession($ctx->impersonatedBy, (string) $admin['role'], $request->ip(), $request->uaHash());
        $this->c->get('log')->audit(['actor_id' => $ctx->impersonatedBy, 'actor_role' => 'admin', 'action' => 'user.impersonate.stop', 'target_type' => 'user', 'target_id' => (int) $ctx->user['id']]);
        $response = $this->ok(['redirect' => '/panel'], $ctx, 200, ['csrf' => $session['csrf']]);
        $response->withCookie('session_id', $session['id'], time() + ((int) $ctx->settings->get('auth.session_absolute_days', 90) * 86400));
        return $response;
    }

    /**
     * Revoke all sessions of a user (Admin only).
     *
     * Route:   POST /panel/users/{id}/sessions/revoke-all
     * Auth:    role:admin
     * Returns: JSON envelope { data: { revoked } }
     */
    public function revokeAllSessions(Request $request, MiddlewareContext $ctx): Response
    {
        $ownSession = (string) ($request->cookie('session_id') ?? '');
        $revoked = $this->c->get('auth')->revokeAllSessions((int) $request->attr('id'), $ownSession);
        return $this->ok(['revoked' => $revoked], $ctx);
    }

    /**
     * Bulk operations on users.
     *
     * Route:   POST /panel/users/bulk
     * Auth:    role:admin,manager
     * Params:  action (verify|disable_limited|disable_full|enable|reset_password|delete), ids[]
     * Returns: JSON envelope { data: { processed } }
     */
    public function bulk(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $ids = array_map('intval', $input['ids'] ?? []);
        $action = (string) ($input['action'] ?? '');
        $processed = 0;
        $service = $this->c->get('users');
        foreach ($ids as $id) {
            try {
                match ($action) {
                    'verify' => $service->verify($id, $ctx->actor()),
                    'disable_limited' => $service->setDisableState($id, 'limited', 'bulk', $ctx->actor()),
                    'disable_full' => $service->setDisableState($id, 'full', 'bulk', $ctx->actor()),
                    'enable' => $service->setDisableState($id, 'none', '', $ctx->actor()),
                    'delete' => $service->delete($id, $ctx->actor()),
                    default => null,
                };
                $processed++;
            } catch (\Throwable) {
                // Skip rows that cannot be processed.
            }
        }
        return $this->ok(['processed' => $processed], $ctx);
    }

    /**
     * Show the current user's own profile page.
     *
     * Route:   GET /panel/profile
     * Auth:    auth
     * Returns: HTML or JSON envelope
     */
    public function profile(Request $request, MiddlewareContext $ctx): Response
    {
        $record = $this->c->get('users')->get((int) $ctx->user['id']);
        if ($request->isJson()) { return $this->ok($record, $ctx); }
        $sessions = $this->c->get('auth')->sessionsFor((int) $ctx->user['id']);
        return $this->view('panel/profile', [
            'record' => $record,
            'sessions' => $sessions,
            'current_session' => (string) ($request->cookie('session_id') ?? ''),
            'tab' => 'profile',
            'csrf' => $ctx->csrf,
        ]);
    }

    /**
     * Update the current user's own profile.
     *
     * Route:   POST /panel/profile
     * Auth:    auth
     * Returns: JSON envelope { data: { id } }
     */
    public function updateProfile(Request $request, MiddlewareContext $ctx): Response
    {
        $result = $this->c->get('users')->update((int) $ctx->user['id'], $this->input($request), $ctx->actor());
        return $this->ok($result, $ctx);
    }

    /**
     * Upload the current user's avatar (riders/clubs only; staff use text avatars).
     *
     * Route:   POST /panel/profile/avatar
     * Auth:    auth
     * Returns: JSON envelope { data: { media_id } }
     */
    public function uploadAvatar(Request $request, MiddlewareContext $ctx): Response
    {
        if (in_array($ctx->user['role'], ['admin', 'manager'], true)) {
            return $this->fail('FORBIDDEN', 'Staff use default text avatars', $ctx, 403);
        }
        $file = $request->files('file');
        if (!is_array($file)) { return $this->fail('VALIDATION_FAILED', 'No file uploaded', $ctx, 422, 'file'); }
        $stored = $this->c->get('media')->store($file, 'avatar', (int) $ctx->user['id'], 'avatars');
        $this->c->get('db')->update('users', ['avatar_media_id' => $stored['id'], 'updated_at' => now_utc()], 'id = :id', ['id' => (int) $ctx->user['id']]);
        return $this->ok(['media_id' => $stored['id']], $ctx, 201);
    }

    /**
     * List the current user's active sessions.
     *
     * Route:   GET /panel/profile/sessions
     * Auth:    auth
     * Returns: HTML or JSON envelope
     */
    public function sessions(Request $request, MiddlewareContext $ctx): Response
    {
        $rows = $this->c->get('auth')->sessionsFor((int) $ctx->user['id']);
        $current = (string) ($request->cookie('session_id') ?? '');
        if ($request->isJson()) { return $this->ok(['rows' => $rows, 'current' => $current], $ctx); }
        return $this->view('panel/profile', ['record' => $this->c->get('users')->get((int) $ctx->user['id']), 'sessions' => $rows, 'current_session' => $current, 'tab' => 'sessions', 'csrf' => $ctx->csrf]);
    }

    /**
     * Change the current user's own password.
     *
     * Route:   POST /panel/profile/password
     * Auth:    auth
     * Params:  current_password, password, password_confirm
     * Returns: JSON envelope { data: null }
     */
    public function changePassword(Request $request, MiddlewareContext $ctx): Response
    {
        if ($ctx->impersonatedBy !== null) {
            return $this->fail('FORBIDDEN', 'Password change is not allowed while impersonating', $ctx, 403);
        }
        $input = $this->input($request);
        $current = (string) ($input['current_password'] ?? '');
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');
        $user = $this->c->get('db')->selectOne('SELECT password_hash FROM users WHERE id = :id', ['id' => (int) $ctx->user['id']]);
        if ($user === null || !password_verify($current, (string) $user['password_hash'])) {
            return $this->fail('AUTH_INVALID', 'Current password is incorrect', $ctx, 401, 'current_password');
        }
        if ($password !== $confirm) {
            return $this->fail('VALIDATION_FAILED', 'Password confirmation does not match', $ctx, 422, 'password_confirm');
        }
        $this->c->get('auth')->setPassword((int) $ctx->user['id'], $password, (string) ($request->cookie('session_id') ?? ''));
        return $this->ok(null, $ctx);
    }

    /**
     * Revoke one of the current user's own sessions.
     *
     * Route:   POST /panel/profile/sessions/{id}/revoke
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function revokeOwnSession(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('auth')->revokeSession((int) $ctx->user['id'], (string) $request->attr('id'));
        return $this->ok(null, $ctx);
    }

    /**
     * Revoke all of the current user's other sessions.
     *
     * Route:   POST /panel/profile/sessions/revoke-all
     * Auth:    auth
     * Returns: JSON envelope { data: { revoked } }
     */
    public function revokeOwnSessions(Request $request, MiddlewareContext $ctx): Response
    {
        $revoked = $this->c->get('auth')->revokeAllSessions((int) $ctx->user['id'], (string) ($request->cookie('session_id') ?? ''));
        return $this->ok(['revoked' => $revoked], $ctx);
    }
}
