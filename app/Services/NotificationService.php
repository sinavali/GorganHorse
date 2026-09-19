<?php
declare(strict_types=1);

/**
 * File: app/Services/NotificationService.php
 *
 * Purpose:
 *   Create and manage in-panel notifications and broadcast messages
 *   (Blueprint §18). Notifications are deduplicated by (user, type, ref) within
 *   a 5-minute window. Broadcasts are delivered in-panel and, when SMS is
 *   enabled, by SMS.
 *
 * Dependencies: Database, LogService, SmsService (optional).
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;

/**
 * Class: NotificationService
 *
 * Purpose: Notification centre + broadcast messaging.
 */
final class NotificationService
{
    private Database $db;
    private ?SmsService $sms;
    private ?LogService $log;

    /**
     * @param Database      $db  Main DB.
     * @param SmsService|null $sms SMS service.
     * @param LogService|null $log Log service.
     */
    public function __construct(Database $db, ?SmsService $sms = null, ?LogService $log = null)
    {
        $this->db = $db;
        $this->sms = $sms;
        $this->log = $log;
    }

    /**
     * Create a notification (deduplicated within a 5-minute window).
     *
     * @param int         $userId  Recipient user id.
     * @param string      $type    Notification type key.
     * @param string      $title   Title.
     * @param string      $body    Body.
     * @param string|null $link    Relative link.
     * @param string|null $refType Referenced entity type.
     * @param int|null    $refId   Referenced entity id.
     * @return int Notification id.
     *
     * Side effects: inserts a notifications row.
     */
    public function create(int $userId, string $type, string $title, string $body = '', ?string $link = null, ?string $refType = null, ?int $refId = null): int
    {
        $dedupeKey = $userId . ':' . $type . ':' . ($refType ?? '') . ':' . ($refId ?? 0);
        $recent = $this->db->selectOne(
            'SELECT id FROM notifications WHERE dedupe_key = :k AND created_at > :since',
            ['k' => $dedupeKey, 'since' => utc_iso(time() - 300)]
        );
        if ($recent !== null) {
            return (int) $recent['id'];
        }
        return $this->db->insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'is_read' => 0,
            'dedupe_key' => $dedupeKey,
            'is_demo' => 0,
            'created_at' => now_utc(),
        ]);
    }

    /**
     * Notify every manager and admin.
     *
     * @param string $type  Type key.
     * @param string $title Title.
     * @param string $body  Body.
     * @param string|null $link Link.
     * @param string|null $refType Ref type.
     * @param int|null $refId Ref id.
     * @return void
     */
    public function notifyStaff(string $type, string $title, string $body = '', ?string $link = null, ?string $refType = null, ?int $refId = null): void
    {
        $staff = $this->db->select("SELECT id FROM users WHERE role IN ('admin','manager')");
        foreach ($staff as $u) {
            $this->create((int) $u['id'], $type, $title, $body, $link, $refType, $refId);
        }
    }

    /**
     * List notifications for a user.
     *
     * @param int  $userId   User id.
     * @param bool $unreadOnly Only unread.
     * @param int  $limit    Max rows.
     * @return array<int,array>
     */
    public function list(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $sql = 'SELECT * FROM notifications WHERE user_id = :u';
        if ($unreadOnly) { $sql .= ' AND is_read = 0'; }
        $sql .= ' ORDER BY id DESC LIMIT :limit';
        return $this->db->select($sql, ['u' => $userId, 'limit' => $limit]);
    }

    /**
     * Unread count for a user.
     *
     * @param int $userId User id.
     * @return int
     */
    public function unreadCount(int $userId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0', ['u' => $userId]);
    }

    /**
     * Mark one notification read (scoped to owner).
     *
     * @param int $userId         User id.
     * @param int $notificationId Notification id.
     * @return void
     */
    public function markRead(int $userId, int $notificationId): void
    {
        $this->db->update('notifications', ['is_read' => 1, 'read_at' => now_utc()], 'id = :id AND user_id = :u', ['id' => $notificationId, 'u' => $userId]);
    }

    /**
     * Mark all notifications read for a user.
     *
     * @param int $userId User id.
     * @return void
     */
    public function markAllRead(int $userId): void
    {
        $this->db->update('notifications', ['is_read' => 1, 'read_at' => now_utc()], 'user_id = :u AND is_read = 0', ['u' => $userId]);
    }

    /**
     * Compose and deliver a broadcast message.
     *
     * @param array $input {
     *   sender_id:int, subject:string, body:string, scope:string (global|competition|rade|selected),
     *   competition_id?:int, rade_id?:int, user_ids?:int[], via_sms?:bool
     * }
     * @return array{message_id:int,recipients:int}
     *
     * Side effects: inserts messages + message_recipients; creates notifications; optional SMS.
     * Transaction: yes.
     */
    public function broadcast(array $input): array
    {
        $senderId = (int) $input['sender_id'];
        $scope = (string) ($input['scope'] ?? 'global');
        $recipients = $this->resolveRecipients($scope, $input);
        $now = now_utc();
        $viaSms = !empty($input['via_sms']);

        return $this->db->transaction(function () use ($input, $recipients, $scope, $now, $senderId, $viaSms): array {
            $uuid = uuid4();
            $messageId = $this->db->insert('messages', [
                'uuid' => $uuid,
                'sender_id' => $senderId,
                'subject' => (string) $input['subject'],
                'body' => (string) $input['body'],
                'scope' => $scope,
                'competition_id' => $input['competition_id'] ?? null,
                'rade_id' => $input['rade_id'] ?? null,
                'via_sms' => $viaSms ? 1 : 0,
                'is_demo' => 0,
                'created_at' => $now,
            ]);
            $delivered = 0;
            foreach ($recipients as $rid) {
                $smsStatus = null;
                if ($viaSms && $this->sms !== null) {
                    $user = $this->db->selectOne('SELECT phone FROM users WHERE id = :id', ['id' => $rid]);
                    $ok = $user !== null && !empty($user['phone']) && $this->sms->notify((string) $user['phone'], 'sms.notify_on_confirmation', (string) $input['subject']);
                    $smsStatus = $ok ? 'sent' : 'skipped';
                }
                $this->db->insert('message_recipients', [
                    'message_id' => $messageId,
                    'user_id' => $rid,
                    'is_read' => 0,
                    'sms_status' => $smsStatus,
                    'created_at' => $now,
                ]);
                $this->create($rid, 'message.broadcast', (string) $input['subject'], (string) $input['body'], '/panel/messages/' . $messageId, 'message', $messageId);
                $delivered++;
            }
            if ($this->log !== null) {
                $this->log->audit(['actor_id' => $senderId, 'action' => 'message.broadcast', 'target_type' => 'message', 'target_id' => $messageId, 'diff' => ['scope' => $scope, 'recipients' => $delivered]]);
            }
            return ['message_id' => $messageId, 'recipients' => $delivered];
        });
    }

    /**
     * Resolve broadcast recipients for a scope.
     *
     * @param string $scope global|competition|rade|selected.
     * @param array  $input Input.
     * @return int[] User ids.
     */
    private function resolveRecipients(string $scope, array $input): array
    {
        if ($scope === 'competition' && !empty($input['competition_id'])) {
            $rows = $this->db->select('SELECT DISTINCT rider_user_id AS id FROM signups WHERE competition_id = :c', ['c' => (int) $input['competition_id']]);
        } elseif ($scope === 'rade' && !empty($input['rade_id'])) {
            $rows = $this->db->select('SELECT DISTINCT rider_user_id AS id FROM signups WHERE rade_id = :r', ['r' => (int) $input['rade_id']]);
        } elseif ($scope === 'selected' && !empty($input['user_ids']) && is_array($input['user_ids'])) {
            return array_values(array_unique(array_map('intval', $input['user_ids'])));
        } else {
            $rows = $this->db->select("SELECT id FROM users WHERE role = 'rider' AND disable_state != 'full'");
        }
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * List messages authored (optionally filtered by sender).
     *
     * @param int|null $senderId Sender filter.
     * @return array<int,array>
     */
    public function listMessages(?int $senderId = null): array
    {
        $sql = "SELECT m.*, (u.first_name || ' ' || u.last_name) AS sender_name,
                       (SELECT COUNT(*) FROM message_recipients mr WHERE mr.message_id = m.id) AS recipient_count,
                       (SELECT COUNT(*) FROM message_recipients mr WHERE mr.message_id = m.id AND mr.is_read = 1) AS read_count
                FROM messages m JOIN users u ON u.id = m.sender_id";
        $params = [];
        if ($senderId !== null) { $sql .= ' WHERE m.sender_id = :s'; $params['s'] = $senderId; }
        $sql .= ' ORDER BY m.id DESC';
        return $this->db->select($sql, $params);
    }

    /**
     * Fetch a message with delivery stats (author or recipient may view).
     *
     * @param int $messageId Message id.
     * @return array|null
     */
    public function getMessage(int $messageId): ?array
    {
        return $this->db->selectOne(
            'SELECT m.*, (u.first_name || \' \' || u.last_name) AS sender_name FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.id = :id',
            ['id' => $messageId]
        );
    }

    /**
     * List messages received by a user (with read state).
     *
     * @param int $userId User id.
     * @return array<int,array>
     */
    public function inbox(int $userId): array
    {
        return $this->db->select(
            'SELECT mr.id AS recipient_id, mr.is_read, mr.read_at, m.*
             FROM message_recipients mr JOIN messages m ON m.id = mr.message_id
             WHERE mr.user_id = :u ORDER BY m.id DESC',
            ['u' => $userId]
        );
    }

    /**
     * Mark a received message read.
     *
     * @param int $userId    User id.
     * @param int $messageId Message id.
     * @return void
     */
    public function markMessageRead(int $userId, int $messageId): void
    {
        $this->db->update('message_recipients', ['is_read' => 1, 'read_at' => now_utc()], 'user_id = :u AND message_id = :m', ['u' => $userId, 'm' => $messageId]);
    }
}
