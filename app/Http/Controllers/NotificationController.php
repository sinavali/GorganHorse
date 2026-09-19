<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/NotificationController.php
 *
 * Purpose:
 *   HTTP layer for the notification centre and broadcast messages
 *   (Blueprint §18; User Usage §14).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: NotificationController
 * Purpose: Handle notifications and messages.
 */
final class NotificationController extends BaseController
{
    /**
     * List notifications.
     *
     * Route:   GET /panel/notifications
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $svc = $this->c->get('notifications');
        $rows = $svc->list((int) $ctx->user['id'], false, 100);
        if ($request->isJson()) { return $this->ok($rows, $ctx); }
        return $this->view('panel/notifications', [
            'rows' => $rows,
            'unread' => $svc->unreadCount((int) $ctx->user['id']),
            'csrf' => $ctx->csrf,
        ]);
    }

    /**
     * Mark a single notification read.
     *
     * Route:   POST /panel/notifications/{id}/read
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function read(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('notifications')->markRead((int) $ctx->user['id'], (int) $request->attr('id'));
        return $this->ok(null, $ctx);
    }

    /**
     * Mark all notifications read.
     *
     * Route:   POST /panel/notifications/read-all
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function readAll(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('notifications')->markAllRead((int) $ctx->user['id']);
        return $this->ok(null, $ctx);
    }

    /**
     * List sent broadcast messages.
     *
     * Route:   GET /panel/messages
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function messages(Request $request, MiddlewareContext $ctx): Response
    {
        $svc = $this->c->get('notifications');
        $isStaff = in_array($ctx->user['role'], ['admin', 'manager'], true);
        $rows = $isStaff ? $svc->listMessages() : $svc->inbox((int) $ctx->user['id']);
        if ($request->isJson()) { return $this->ok($rows, $ctx); }
        return $this->view('panel/messages', ['rows' => $rows, 'is_staff' => $isStaff, 'csrf' => $ctx->csrf]);
    }

    /**
     * Compose and send a broadcast.
     *
     * Route:   POST /panel/messages
     * Auth:    role:admin,manager
     * Params:  subject, body, scope, competition_id?, rade_id?, user_ids[]?, via_sms?
     * Returns: JSON envelope { data: { message_id, recipients } }
     */
    public function send(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $input['sender_id'] = (int) $ctx->user['id'];
        return $this->ok($this->c->get('notifications')->broadcast($input), $ctx, 201);
    }

    /**
     * Show a message.
     *
     * Route:   GET /panel/messages/{id}
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function showMessage(Request $request, MiddlewareContext $ctx): Response
    {
        $message = $this->c->get('notifications')->getMessage((int) $request->attr('id'));
        if ($message === null) { return $this->fail('NOT_FOUND', 'Not found', $ctx, 404); }
        if ($request->isJson()) { return $this->ok($message, $ctx); }
        return $this->view('panel/message', ['record' => $message, 'csrf' => $ctx->csrf]);
    }

    /**
     * Mark a received message read.
     *
     * Route:   POST /panel/messages/{id}/read
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function readMessage(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('notifications')->markMessageRead((int) $ctx->user['id'], (int) $request->attr('id'));
        return $this->ok(null, $ctx);
    }
}
