<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/PaymentController.php
 *
 * Purpose:
 *   HTTP layer for payment templates and payment orders: list, CRUD, order
 *   lifecycle (verify/refunds), reconciliation, callbacks, and print
 *   (Blueprint §10.2–10.3, §14.1; User Usage §7.10–7.11, §7.16).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: PaymentController
 * Purpose: Handle payment template and order endpoints.
 */
final class PaymentController extends BaseController
{
    /**
     * List payment templates.
     *
     * Route:   GET /panel/payments
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $filters = ['active' => (string) $request->query('active', 'all'), 'search' => (string) $request->query('search', '')];
        $rows = $this->c->get('payments')->listTemplates($filters);
        if ($request->isJson()) { return $this->ok($rows, $ctx, 200, ['total' => count($rows), 'filtered' => count($rows)]); }
        return $this->view('panel/payments', ['rows' => $rows, 'filters' => $filters, 'csrf' => $ctx->csrf]);
    }

    /**
     * Create a payment template.
     *
     * Route:   POST /panel/payments
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id, uuid } }
     */
    public function store(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('payments')->createTemplate($this->input($request), $ctx->actor()), $ctx, 201);
    }

    /**
     * Show a payment template.
     *
     * Route:   GET /panel/payments/{id}
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function show(Request $request, MiddlewareContext $ctx): Response
    {
        $record = $this->c->get('payments')->getTemplate((int) $request->attr('id'));
        if ($request->isJson()) { return $this->ok($record, $ctx); }
        return $this->view('panel/payment-edit', ['record' => $record, 'csrf' => $ctx->csrf]);
    }

    /**
     * Update a payment template.
     *
     * Route:   PUT /panel/payments/{id}
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id } }
     */
    public function update(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('payments')->updateTemplate((int) $request->attr('id'), $this->input($request), $ctx->actor()), $ctx);
    }

    /**
     * Delete a payment template.
     *
     * Route:   DELETE /panel/payments/{id}
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function destroy(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('payments')->deleteTemplate((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Bulk activate/deactivate templates.
     *
     * Route:   POST /panel/payments/bulk
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { processed } }
     */
    public function bulk(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $ids = array_map('intval', $input['ids'] ?? []);
        $active = ($input['action'] ?? '') === 'activate';
        return $this->ok(['processed' => $this->c->get('payments')->bulkSetActive($ids, $active, $ctx->actor())], $ctx);
    }

    /**
     * Print a single payment template.
     *
     * Route:   GET /panel/payments/{id}/print
     * Auth:    auth
     * Returns: HTML print page
     */
    public function print(Request $request, MiddlewareContext $ctx): Response
    {
        $record = $this->c->get('payments')->getTemplate((int) $request->attr('id'));
        return $this->view('print/payment', ['record' => $record, 'title' => $record['name']], 'print');
    }

    /**
     * Print the payment list.
     *
     * Route:   GET /panel/payments/print-list
     * Auth:    auth
     * Returns: HTML print page
     */
    public function printList(Request $request, MiddlewareContext $ctx): Response
    {
        $rows = $this->c->get('payments')->listTemplates([]);
        return $this->view('print/payment-list', ['rows' => $rows, 'title' => 'فهرست الگوهای پرداخت'], 'print');
    }

    // ---- Payment orders ----

    /**
     * List payment orders.
     *
     * Route:   GET /panel/payment-orders
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function orders(Request $request, MiddlewareContext $ctx): Response
    {
        $filters = [
            'status' => (string) $request->query('status', ''),
            'competition_id' => (int) $request->query('competition_id', 0),
            'rider_user_id' => (int) $request->query('rider_user_id', 0),
            'ref_id' => (string) $request->query('ref_id', ''),
        ];
        $result = $this->c->get('payments')->listOrders($filters, $ctx->actor(), $this->page($request), $this->perPage($request));
        if ($request->isJson()) { return $this->ok($result, $ctx, 200, ['total' => $result['total'], 'filtered' => $result['total']]); }
        return $this->view('panel/payment-orders', ['rows' => $result['rows'], 'total' => $result['total'], 'filters' => $filters, 'csrf' => $ctx->csrf]);
    }

    /**
     * Show a payment order.
     *
     * Route:   GET /panel/payment-orders/{id}
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function showOrder(Request $request, MiddlewareContext $ctx): Response
    {
        $order = $this->c->get('payments')->getOrder((int) $request->attr('id'));
        if ($request->isJson()) { return $this->ok($order, $ctx); }
        return $this->view('panel/payment-order', ['record' => $order, 'csrf' => $ctx->csrf]);
    }

    /**
     * Manually verify an order.
     *
     * Route:   POST /panel/payment-orders/{id}/verify
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: {...} }
     */
    public function verifyOrder(Request $request, MiddlewareContext $ctx): Response
    {
        $result = $this->c->get('payments')->verifyOrder((int) $request->attr('id'));
        if (($result['status'] ?? '') === 'paid') {
            $order = $this->c->get('payments')->getOrder((int) $request->attr('id'));
            if (!empty($order['signup_id'])) { $this->c->get('signups')->markPaid((int) $order['signup_id']); }
        }
        return $this->ok($result, $ctx);
    }

    /**
     * Mark an order pending refund.
     *
     * Route:   POST /panel/payment-orders/{id}/mark-pending-refund
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function markPendingRefund(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('payments')->markPendingRefund((int) $request->attr('id'), $ctx->actor(), (string) ($this->input($request)['note'] ?? ''));
        return $this->ok(null, $ctx);
    }

    /**
     * Mark an order refunded.
     *
     * Route:   POST /panel/payment-orders/{id}/mark-refunded
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function markRefunded(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('payments')->markRefunded((int) $request->attr('id'), $ctx->actor(), (string) ($this->input($request)['note'] ?? ''));
        return $this->ok(null, $ctx);
    }

    /**
     * Unmark a refund.
     *
     * Route:   POST /panel/payment-orders/{id}/unmark-refund
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function unmarkRefund(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('payments')->unmarkRefund((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Print a payment order.
     *
     * Route:   GET /panel/payment-orders/{id}/print
     * Auth:    auth
     * Returns: HTML print page
     */
    public function printOrder(Request $request, MiddlewareContext $ctx): Response
    {
        $order = $this->c->get('payments')->getOrder((int) $request->attr('id'));
        return $this->view('print/payment', ['record' => $order, 'title' => 'صورت‌حساب #' . $order['id']], 'print');
    }

    /**
     * Bulk refund operations.
     *
     * Route:   POST /panel/payment-orders/bulk
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { processed } }
     */
    public function bulkOrders(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $ids = array_map('intval', $input['ids'] ?? []);
        $action = (string) ($input['action'] ?? '');
        $processed = 0;
        $svc = $this->c->get('payments');
        foreach ($ids as $id) {
            try {
                match ($action) {
                    'pending_refund' => $svc->markPendingRefund($id, $ctx->actor()),
                    'refunded' => $svc->markRefunded($id, $ctx->actor()),
                    'unmark_refund' => $svc->unmarkRefund($id, $ctx->actor()),
                    default => null,
                };
                $processed++;
            } catch (\Throwable) {}
        }
        return $this->ok(['processed' => $processed], $ctx);
    }

    /**
     * Reconciliation page (upload a ZarinPal CSV report).
     *
     * Route:   GET /panel/payment-orders/reconciliation  |  POST (with file)
     * Auth:    role:admin,manager
     * Returns: HTML or JSON { data: {...} }
     */
    public function reconciliation(Request $request, MiddlewareContext $ctx): Response
    {
        if ($request->isMethod('POST')) {
            $file = $request->files('file');
            if (!is_array($file)) { return $this->fail('IMPORT_FAILED', 'No file uploaded', $ctx, 422, 'file'); }
            return $this->ok($this->c->get('payments')->reconcile($file), $ctx);
        }
        return $this->view('panel/reconciliation', ['csrf' => $ctx->csrf]);
    }

    /**
     * ZarinPal callback (public).
     *
     * Route:   GET /payment/callback
     * Auth:    guest
     * Params:  Authority, Status
     * Returns: 302 redirect to success or failed page
     */
    public function callback(Request $request, MiddlewareContext $ctx): Response
    {
        $authority = (string) $request->query('Authority', '');
        $status = (string) $request->query('Status', 'NOK');
        $result = $this->c->get('payments')->verifyByAuthority($authority, $status);
        if ($result === null) {
            return Response::redirect('/payment/failed?reason=unknown');
        }
        if (($result['status'] ?? '') === 'paid') {
            $order = $this->c->get('payments')->getOrder((int) $result['order_id']);
            if (!empty($order['signup_id'])) { $this->c->get('signups')->markPaid((int) $order['signup_id']); }
            return Response::redirect('/payment/success');
        }
        return Response::redirect('/payment/failed?reason=' . urlencode($result['status']));
    }

    /**
     * Payment success page (public).
     *
     * Route:   GET /payment/success
     * Auth:    guest
     * Returns: HTML
     */
    public function success(Request $request, MiddlewareContext $ctx): Response
    {
        return Response::html('<div style="font-family:Vazirmatn,Tahoma;text-align:center;padding:48px"><h2>پرداخت با موفقیت انجام شد</h2><p><a href="/panel/rider/signups">مشاهده ثبت‌نام‌ها</a></p></div>');
    }

    /**
     * Payment failed page (public).
     *
     * Route:   GET /payment/failed
     * Auth:    guest
     * Returns: HTML
     */
    public function failed(Request $request, MiddlewareContext $ctx): Response
    {
        return Response::html('<div style="font-family:Vazirmatn,Tahoma;text-align:center;padding:48px"><h2>پرداخت ناموفق بود</h2><p><a href="/panel/rider/competitions">تلاش دوباره</a></p></div>');
    }
}
