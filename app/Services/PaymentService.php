<?php
declare(strict_types=1);

/**
 * File: app/Services/PaymentService.php
 *
 * Purpose:
 *   Payment templates (reusable price definitions) and payment orders (the
 *   ZarinPal transaction lifecycle). Handles request/verify/callback, refunds
 *   (manual), and reconciliation (Blueprint §14.1, Technical §19).
 *
 * Dependencies: Database, SettingService, LogService, NotificationService, SmsService.
 *
 * Conventions:
 *   - All money is integer IRT (Toman).
 *   - Verify is idempotent and locked per authority (P21).
 *   - Orders are retained forever; templates are historised via signup snapshots.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Exceptions\DomainException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ServerErrorException;
use App\Exceptions\ValidationException;

/**
 * Class: PaymentService
 *
 * Purpose: Manage payment templates and ZarinPal order lifecycle.
 */
final class PaymentService
{
    private Database $db;
    private SettingService $settings;
    private LogService $log;
    private ?NotificationService $notifications;
    private ?SmsService $sms;
    /** @var callable|null httpPost(url, payload): array{status:int,body:string} */
    private $httpClient;

    /**
     * @param Database                 $db            Main DB.
     * @param SettingService           $settings      Settings.
     * @param LogService               $log           Logs.
     * @param NotificationService|null $notifications Notifications.
     * @param SmsService|null          $sms           SMS.
     * @param callable|null            $httpClient    Injectable HTTP client.
     */
    public function __construct(Database $db, SettingService $settings, LogService $log, ?NotificationService $notifications = null, ?SmsService $sms = null, ?callable $httpClient = null)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->log = $log;
        $this->notifications = $notifications;
        $this->sms = $sms;
        $this->httpClient = $httpClient;
    }

    // ---------------------------------------------------------------------
    // Templates
    // ---------------------------------------------------------------------

    /**
     * List payment templates.
     *
     * @param array $filters {active?,search?}
     * @return array<int,array>
     */
    public function listTemplates(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (($filters['active'] ?? 'all') !== 'all' && ($filters['active'] ?? '') !== '') {
            $where[] = 'is_active = :a';
            $params['a'] = (int) $filters['active'];
        }
        if (!empty($filters['search'])) { $where[] = 'name LIKE :q'; $params['q'] = '%' . $filters['search'] . '%'; }
        return $this->db->select('SELECT * FROM payments WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC', $params);
    }

    /**
     * Fetch a payment template.
     *
     * @param int $id Payment id.
     * @return array
     * @throws NotFoundException PAYMENT_NOT_FOUND.
     */
    public function getTemplate(int $id): array
    {
        $row = $this->db->selectOne('SELECT * FROM payments WHERE id = :id', ['id' => $id]);
        if ($row === null) { throw new NotFoundException('Payment not found', 'PAYMENT_NOT_FOUND'); }
        return $row;
    }

    /**
     * Create a payment template.
     *
     * @param array $input {name,slug?,description?,amount_irt,is_active?}
     * @param array $actor Actor.
     * @return array{id:int,uuid:string}
     * @throws ForbiddenException|ValidationException|DomainException
     */
    public function createTemplate(array $input, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') { throw new ValidationException('Name is required', 'name', 'VALIDATION_FAILED'); }
        $amount = (int) ($input['amount_irt'] ?? 0);
        if ($amount < 0 || $amount > 1_000_000_000) { throw new ValidationException('Invalid amount', 'amount_irt', 'VALIDATION_FAILED'); }
        $slug = trim((string) ($input['slug'] ?? ''));
        if ($slug === '') { $slug = slugify($name) . '-' . random_digits(4); }
        if ($this->db->scalar('SELECT COUNT(*) FROM payments WHERE slug = :s', ['s' => $slug]) > 0) {
            throw new DomainException('PAYMENT_SLUG_TAKEN', 'Slug already taken', 409, 'slug');
        }
        $now = now_utc();
        $uuid = uuid4();
        $id = $this->db->insert('payments', [
            'uuid' => $uuid,
            'name' => $name,
            'slug' => $slug,
            'description' => $input['description'] ?? null,
            'amount_irt' => $amount,
            'is_active' => !isset($input['is_active']) || $input['is_active'] ? 1 : 0,
            'is_demo' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->record($actor, 'payment.create', $id, ['name' => $name, 'amount_irt' => $amount]);
        return ['id' => $id, 'uuid' => $uuid];
    }

    /**
     * Update a payment template.
     *
     * @param int   $id    Payment id.
     * @param array $input Fields.
     * @param array $actor Actor.
     * @return array{id:int}
     * @throws NotFoundException|ForbiddenException|DomainException
     */
    public function updateTemplate(int $id, array $input, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->getTemplate($id);
        $data = [];
        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $input)) { $data[$field] = $input[$field]; }
        }
        if (array_key_exists('amount_irt', $input)) {
            $amount = (int) $input['amount_irt'];
            if ($amount < 0 || $amount > 1_000_000_000) { throw new ValidationException('Invalid amount', 'amount_irt', 'VALIDATION_FAILED'); }
            $data['amount_irt'] = $amount;
        }
        if (array_key_exists('is_active', $input)) { $data['is_active'] = $input['is_active'] ? 1 : 0; }
        if (array_key_exists('slug', $input) && $input['slug'] !== '') {
            $slug = (string) $input['slug'];
            if ($this->db->scalar('SELECT COUNT(*) FROM payments WHERE slug = :s AND id != :id', ['s' => $slug, 'id' => $id]) > 0) {
                throw new DomainException('PAYMENT_SLUG_TAKEN', 'Slug already taken', 409, 'slug');
            }
            $data['slug'] = $slug;
        }
        $data['updated_at'] = now_utc();
        $this->db->update('payments', $data, 'id = :id', ['id' => $id]);
        $this->record($actor, 'payment.update', $id, $data);
        return ['id' => $id];
    }

    /**
     * Delete a payment template (blocked when referenced by competition-rades).
     *
     * @param int   $id    Payment id.
     * @param array $actor Actor.
     * @return void
     */
    public function deleteTemplate(int $id, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->getTemplate($id);
        $used = (int) $this->db->scalar('SELECT COUNT(*) FROM competition_rades WHERE payment_id = :p', ['p' => $id]);
        if ($used > 0) {
            throw new DomainException('FORBIDDEN', 'Payment template is in use', 409);
        }
        $this->db->delete('payments', 'id = :id', ['id' => $id]);
        $this->record($actor, 'payment.delete', $id);
    }

    /**
     * Bulk activate/deactivate templates.
     *
     * @param array $ids    Payment ids.
     * @param bool  $active Target state.
     * @param array $actor  Actor.
     * @return int Affected.
     */
    public function bulkSetActive(array $ids, bool $active, array $actor): int
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $count = 0;
        foreach ($ids as $id) {
            $count += $this->db->update('payments', ['is_active' => $active ? 1 : 0, 'updated_at' => now_utc()], 'id = :id', ['id' => (int) $id]);
        }
        $this->record($actor, 'payment.bulk_active', 0, ['count' => $count]);
        return $count;
    }

    // ---------------------------------------------------------------------
    // Orders
    // ---------------------------------------------------------------------

    /**
     * Create a pending payment order for a signup.
     *
     * @param int    $signupId Signup id.
     * @param int    $amount   Amount IRT.
     * @param string $desc     Description.
     * @return array{id:int,uuid:string}
     *
     * Side effects: inserts a payment_orders row.
     */
    public function createOrder(int $signupId, int $amount, string $desc): array
    {
        $uuid = uuid4();
        $now = now_utc();
        $id = $this->db->insert('payment_orders', [
            'uuid' => $uuid,
            'signup_id' => $signupId,
            'amount_irt' => $amount,
            'status' => 'pending',
            'authority' => null,
            'ref_id' => null,
            'description' => $desc,
            'gateway' => (string) $this->settings->get('payment.gateway', 'zarinpal'),
            'is_demo' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return ['id' => $id, 'uuid' => $uuid];
    }

    /**
     * List payment orders with filters, scoped by role.
     *
     * @param array $filters {status?,competition_id?,rider_user_id?,ref_id?,from?,to?}
     * @param array $actor   Actor.
     * @param int   $page    Page.
     * @param int   $perPage Page size.
     * @return array{rows:array,total:int,page:int,per_page:int}
     */
    public function listOrders(array $filters, array $actor, int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $params = [];
        if ($actor['role'] === 'rider') {
            $where[] = 'po.rider_user_id = :me';
            $params['me'] = (int) $actor['id'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') { $where[] = 'po.status = :status'; $params['status'] = $filters['status']; }
        if (!empty($filters['competition_id'])) { $where[] = 'po.competition_id = :cid'; $params['cid'] = (int) $filters['competition_id']; }
        if (!empty($filters['rider_user_id'])) { $where[] = 'po.rider_user_id = :rid'; $params['rid'] = (int) $filters['rider_user_id']; }
        if (!empty($filters['ref_id'])) { $where[] = 'po.ref_id = :ref'; $params['ref'] = $filters['ref_id']; }
        if (!empty($filters['from'])) { $where[] = 'po.created_at >= :from'; $params['from'] = $filters['from']; }
        if (!empty($filters['to'])) { $where[] = 'po.created_at <= :to'; $params['to'] = $filters['to']; }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM payment_orders po WHERE ' . $whereSql, $params);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->select(
            "SELECT po.*, (u.first_name || ' ' || u.last_name) AS rider_name, c.title AS competition_title
             FROM payment_orders po
             LEFT JOIN users u ON u.id = po.rider_user_id
             LEFT JOIN competitions c ON c.id = po.competition_id
             WHERE " . $whereSql . ' ORDER BY po.id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => $offset]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Fetch a payment order.
     *
     * @param int $id Order id.
     * @return array
     * @throws NotFoundException PAYMENT_NOT_FOUND.
     */
    public function getOrder(int $id): array
    {
        $row = $this->db->selectOne('SELECT * FROM payment_orders WHERE id = :id', ['id' => $id]);
        if ($row === null) { throw new NotFoundException('Payment not found', 'PAYMENT_NOT_FOUND'); }
        return $row;
    }

    /**
     * Request a ZarinPal authority for an order.
     *
     * @param int    $orderId Order id.
     * @param string $callbackUrl Callback URL.
     * @return array{authority:string,gateway_url:string}
     * @throws DomainException PAYMENT_GATEWAY_DISABLED | PAYMENT_MERCHANT_MISSING | PAYMENT_VERIFICATION_FAILED.
     */
    public function requestGateway(int $orderId, string $callbackUrl): array
    {
        if (!(bool) $this->settings->get('payment.gateway_enabled', true)) {
            throw new DomainException('PAYMENT_GATEWAY_DISABLED', 'Payment gateway disabled', 503);
        }
        $merchant = (string) $this->settings->get('payment.zarinpal_merchant_id', '');
        if ($merchant === '') {
            throw new DomainException('PAYMENT_MERCHANT_MISSING', 'Gateway misconfigured', 500);
        }
        $order = $this->getOrder($orderId);
        $payload = [
            'merchant_id' => $merchant,
            'amount' => (int) $order['amount_irt'],
            'currency' => 'IRT',
            'callback_url' => $callbackUrl,
            'description' => (string) ($order['description'] ?? 'شرکت در مسابقه'),
            'metadata' => ['order_uuid' => $order['uuid']],
        ];
        $res = $this->post('https://payment.zarinpal.com/pg/v4/payment/request.json', $payload);
        $body = json_decode($res['body'], true);
        $code = (int) ($body['data']['code'] ?? 0);
        $authority = (string) ($body['data']['authority'] ?? '');
        if (!in_array($code, [100, 101], true) || $authority === '') {
            $this->log->app('warning', 'ZarinPal request failed', ['order_id' => $orderId, 'response' => $res['body']]);
            throw new DomainException('PAYMENT_VERIFICATION_FAILED', 'Gateway request failed', 422);
        }
        $this->db->update('payment_orders', ['authority' => $authority, 'updated_at' => now_utc()], 'id = :id', ['id' => $orderId]);
        return ['authority' => $authority, 'gateway_url' => 'https://payment.zarinpal.com/pg/StartPay/' . $authority];
    }

    /**
     * Verify a payment by order id (idempotent).
     *
     * @param int $orderId Order id.
     * @return array{order_id:int,status:string,ref_id:?string,already:bool}
     * @throws NotFoundException|DomainException PAYMENT_VERIFICATION_FAILED.
     *
     * Side effects: updates payment_orders (paid); returns status. Transaction: yes.
     */
    public function verifyOrder(int $orderId): array
    {
        $order = $this->getOrder($orderId);
        if (in_array($order['status'], ['paid'], true)) {
            return ['order_id' => $orderId, 'status' => 'paid', 'ref_id' => $order['ref_id'], 'already' => true];
        }
        return $this->doVerify($order);
    }

    /**
     * Verify a payment by ZarinPal authority (callback path).
     *
     * @param string $authority ZarinPal authority.
     * @param string $status    ZarinPal status ("OK" or other).
     * @return array{order_id:int,status:string,ref_id:?string,already:bool}|null Null when authority unknown.
     *
     * Side effects: updates payment_orders; audit. Transaction: yes.
     */
    public function verifyByAuthority(string $authority, string $status): ?array
    {
        $order = $this->db->selectOne('SELECT * FROM payment_orders WHERE authority = :a', ['a' => $authority]);
        if ($order === null) { return null; }
        if ($order['status'] === 'paid') {
            return ['order_id' => (int) $order['id'], 'status' => 'paid', 'ref_id' => $order['ref_id'], 'already' => true];
        }
        if (strtoupper($status) !== 'OK') {
            $this->db->update('payment_orders', ['status' => 'failed', 'updated_at' => now_utc()], 'id = :id', ['id' => (int) $order['id']]);
            return ['order_id' => (int) $order['id'], 'status' => 'failed', 'ref_id' => null, 'already' => false];
        }
        return $this->doVerify($order);
    }

    /**
     * Perform the ZarinPal verify call and update the order.
     *
     * @param array $order Order row.
     * @return array{order_id:int,status:string,ref_id:?string,already:bool}
     * @throws DomainException PAYMENT_MERCHANT_MISSING | PAYMENT_VERIFICATION_FAILED.
     */
    private function doVerify(array $order): array
    {
        $merchant = (string) $this->settings->get('payment.zarinpal_merchant_id', '');
        if ($merchant === '') {
            throw new DomainException('PAYMENT_MERCHANT_MISSING', 'Gateway misconfigured', 500);
        }
        $payload = ['merchant_id' => $merchant, 'amount' => (int) $order['amount_irt'], 'authority' => (string) $order['authority']];
        $res = $this->post('https://payment.zarinpal.com/pg/v4/payment/verify.json', $payload);
        $body = json_decode($res['body'], true);
        $code = (int) ($body['data']['code'] ?? 0);
        $refId = (string) ($body['data']['ref_id'] ?? '');
        $cardPan = (string) ($body['data']['card_pan'] ?? '');
        $now = now_utc();
        if (!in_array($code, [100, 101], true)) {
            $this->db->update('payment_orders', ['status' => 'failed', 'updated_at' => $now], 'id = :id', ['id' => (int) $order['id']]);
            throw new DomainException('PAYMENT_VERIFICATION_FAILED', 'Payment verification failed', 422);
        }
        return $this->db->transaction(function () use ($order, $refId, $cardPan, $now): array {
            $this->db->update('payment_orders', [
                'status' => 'paid',
                'ref_id' => $refId !== '' ? $refId : null,
                'card_pan' => $cardPan !== '' ? $cardPan : null,
                'verified_at' => $now,
                'updated_at' => $now,
            ], 'id = :id', ['id' => (int) $order['id']]);
            $this->log->audit([
                'action' => 'payment.verify', 'target_type' => 'payment_order', 'target_id' => (int) $order['id'],
                'diff' => ['status' => 'paid', 'ref_id' => $refId], 'result' => 'ok',
            ]);
            return ['order_id' => (int) $order['id'], 'status' => 'paid', 'ref_id' => $refId ?: null, 'already' => false];
        });
    }

    /**
     * Mark an order as pending refund (manual refund flow).
     *
     * @param int   $orderId Order id.
     * @param array $actor   Actor.
     * @param string $note   Note.
     * @return void
     * @throws ForbiddenException
     */
    public function markPendingRefund(int $orderId, array $actor, string $note = ''): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->getOrder($orderId);
        $this->db->update('payment_orders', ['status' => 'pending_refund', 'refund_note' => $note, 'updated_at' => now_utc()], 'id = :id', ['id' => $orderId]);
        $this->record($actor, 'payment.pending_refund', $orderId, ['note' => $note]);
    }

    /**
     * Mark an order as refunded (after manual refund).
     *
     * @param int    $orderId Order id.
     * @param array  $actor   Actor.
     * @param string $note    Note.
     * @return void
     */
    public function markRefunded(int $orderId, array $actor, string $note = ''): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $order = $this->getOrder($orderId);
        $this->db->update('payment_orders', ['status' => 'refunded', 'refunded_at' => now_utc(), 'refund_note' => $note, 'updated_at' => now_utc()], 'id = :id', ['id' => $orderId]);
        if (!empty($order['rider_user_id'])) {
            $this->notifications?->create((int) $order['rider_user_id'], 'payment.refunded', 'بازپرداخت انجام شد', 'مبلغ پرداختی شما بازگردانده شد.', '/panel/rider/signups', 'payment_order', $orderId);
        }
        $this->record($actor, 'payment.refunded', $orderId, ['note' => $note]);
    }

    /**
     * Unmark a refund (toggle back to paid).
     *
     * @param int   $orderId Order id.
     * @param array $actor   Actor.
     * @return void
     */
    public function unmarkRefund(int $orderId, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->getOrder($orderId);
        $this->db->update('payment_orders', ['status' => 'paid', 'refunded_at' => null, 'updated_at' => now_utc()], 'id = :id', ['id' => $orderId]);
        $this->record($actor, 'payment.unmark_refund', $orderId);
    }

    /**
     * Reconcile local orders against a ZarinPal CSV report.
     *
     * @param array $file CSV upload ($_FILES entry) with columns authority,ref_id.
     * @return array{matched:int,mismatched:array<int,array>,missing_local:array<int,string>}
     * @throws ValidationException IMPORT_FAILED.
     */
    public function reconcile(array $file): array
    {
        if (($file['error'] ?? 1) !== 0) { throw new ValidationException('Upload failed', 'file', 'IMPORT_FAILED'); }
        $content = (string) file_get_contents((string) $file['tmp_name']);
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($content)) ?: []));
        if ($lines === []) { throw new ValidationException('Empty CSV', 'file', 'IMPORT_FAILED'); }
        $header = array_map(static fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $matched = 0;
        $mismatched = [];
        $missing = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            $row = array_combine(array_slice($header, 0, count($cols)), $cols) ?: [];
            $authority = (string) ($row['authority'] ?? '');
            $refId = (string) ($row['ref_id'] ?? '');
            $local = $authority !== '' ? $this->db->selectOne('SELECT * FROM payment_orders WHERE authority = :a', ['a' => $authority]) : null;
            if ($local === null) {
                $missing[] = $authority !== '' ? $authority : $refId;
                continue;
            }
            if ($refId !== '' && (string) $local['ref_id'] !== $refId) {
                $mismatched[] = ['authority' => $authority, 'local_ref' => $local['ref_id'], 'remote_ref' => $refId];
            } else {
                $matched++;
            }
        }
        return ['matched' => $matched, 'mismatched' => $mismatched, 'missing_local' => $missing];
    }

    /**
     * Aggregate revenue totals for a UTC window.
     *
     * @param string|null $from UTC lower bound.
     * @param string|null $to   UTC upper bound.
     * @return array{paid:int,pending:int,refunded:int,total_paid:int}
     */
    public function totals(?string $from = null, ?string $to = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS c, COALESCE(SUM(amount_irt),0) AS total FROM payment_orders WHERE 1=1';
        $params = [];
        if ($from !== null) { $sql .= ' AND created_at >= :from'; $params['from'] = $from; }
        if ($to !== null) { $sql .= ' AND created_at <= :to'; $params['to'] = $to; }
        $sql .= ' GROUP BY status';
        $rows = $this->db->select($sql, $params);
        $out = ['paid' => 0, 'pending' => 0, 'refunded' => 0, 'failed' => 0, 'pending_refund' => 0, 'total_paid' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['c'];
            if ($r['status'] === 'paid') { $out['total_paid'] = (int) $r['total']; }
        }
        return $out;
    }

    /**
     * Perform a JSON POST request to the gateway.
     *
     * @param string $url     Endpoint.
     * @param array  $payload Payload.
     * @return array{status:int,body:string}
     */
    private function post(string $url, array $payload): array
    {
        if ($this->httpClient !== null) {
            return ($this->httpClient)($url, $payload);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new ServerErrorException('SERVICE_UNAVAILABLE', 'Gateway HTTP error: ' . $err, 503);
        }
        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Audit helper.
     *
     * @param array  $actor  Actor.
     * @param string $action Action.
     * @param int    $id     Order id.
     * @param array  $diff   Diff.
     * @return void
     */
    private function record(array $actor, string $action, int $id, array $diff = []): void
    {
        $this->log->audit([
            'actor_id' => $actor['id'] ?? null, 'actor_role' => $actor['role'] ?? null,
            'action' => $action, 'target_type' => 'payment_order', 'target_id' => $id, 'diff' => $diff,
        ]);
    }
}
