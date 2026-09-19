<?php
declare(strict_types=1);

/**
 * File: app/Models/Models.php
 *
 * Purpose:
 *   All thin data models for the panel merged into one file (P23). Models are
 *   data structures only: they declare the table name, column list, cast map,
 *   and relation helpers. They contain NO business logic and perform NO writes
 *   (Technical §8).
 *
 * Conventions:
 *   - Table names and column lists are constants.
 *   - Type casts are declared in $casts.
 *   - Relations are declared as methods returning SQL fragments or model lists.
 *
 * @package App\Models
 */

namespace App\Models;

use App\Bootstrap\Database;

/**
 * Class: BaseModel
 *
 * Purpose:
 *   Shared behaviour for all models: hydration, JSON conversion, and simple
 *   read helpers backed by a Database connection. Subclasses declare TABLE and
 *   CASTS.
 */
abstract class BaseModel
{
    /** @var string DB table name. */
    public const TABLE = '';

    /** @var array<string,string> Column => cast type (int|bool|float|json|string). */
    public const CASTS = [];

    /** @var array<string,mixed> Hydrated attributes. */
    protected array $attributes = [];

    /** @var Database|null Optional connection used by read helpers. */
    protected ?Database $db;

    /**
     * @param array<string,mixed> $attributes Column values.
     * @param Database|null       $db         Connection for read helpers.
     */
    public function __construct(array $attributes = [], ?Database $db = null)
    {
        $this->db = $db;
        $this->attributes = $this->castAll($attributes);
    }

    /**
     * Build a model from a DB row.
     *
     * @param array         $row Row.
     * @param Database|null $db  Connection.
     * @return static
     */
    public static function hydrate(array $row, ?Database $db = null): static
    {
        return new static($row, $db);
    }

    /**
     * Find a record by primary key.
     *
     * @param int           $id Primary key.
     * @param Database|null $db Connection.
     * @return static|null
     */
    public static function find(int $id, ?Database $db = null): ?static
    {
        if ($db === null) { return null; }
        $row = $db->selectOne('SELECT * FROM ' . static::TABLE . ' WHERE id = :id', ['id' => $id]);
        return $row === null ? null : new static($row, $db);
    }

    /**
     * Find a record by a single column value.
     *
     * @param string        $column Column name.
     * @param mixed         $value  Value.
     * @param Database|null $db     Connection.
     * @return static|null
     */
    public static function findBy(string $column, mixed $value, ?Database $db = null): ?static
    {
        if ($db === null) { return null; }
        $row = $db->selectOne('SELECT * FROM ' . static::TABLE . " WHERE $column = :v LIMIT 1", ['v' => $value]);
        return $row === null ? null : new static($row, $db);
    }

    /**
     * Fetch all records (optionally ordered).
     *
     * @param Database|null $db    Connection.
     * @param string        $order ORDER BY clause (trusted).
     * @return array<int,static>
     */
    public static function all(?Database $db = null, string $order = 'id ASC'): array
    {
        if ($db === null) { return []; }
        $rows = $db->select('SELECT * FROM ' . static::TABLE . ' ORDER BY ' . $order);
        return array_map(static fn (array $r): static => new static($r, $db), $rows);
    }

    /**
     * Cast all attributes per the CASTS map.
     *
     * @param array $attributes Raw attributes.
     * @return array Casted attributes.
     */
    protected function castAll(array $attributes): array
    {
        foreach (static::CASTS as $column => $type) {
            if (!array_key_exists($column, $attributes)) { continue; }
            $attributes[$column] = $this->castValue($attributes[$column], $type);
        }
        return $attributes;
    }

    /**
     * Cast a single value to the requested type.
     *
     * @param mixed  $value Raw value.
     * @param string $type  Cast type.
     * @return mixed
     */
    protected function castValue(mixed $value, string $type): mixed
    {
        if ($value === null) { return null; }
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'json' => is_string($value) ? (json_decode($value, true) ?? []) : (array) $value,
            default => (string) $value,
        };
    }

    /**
     * Read an attribute.
     *
     * @param string $key     Attribute name.
     * @param mixed  $default Default.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Whether the attribute exists.
     *
     * @param string $key Attribute name.
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Set an attribute.
     *
     * @param string $key   Attribute name.
     * @param mixed  $value Value.
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /** @return array<string,mixed> All attributes. */
    public function attributes(): array { return $this->attributes; }

    /** @return int|null Primary key. */
    public function id(): ?int { return isset($this->attributes['id']) ? (int) $this->attributes['id'] : null; }

    /** @return string UUID. */
    public function uuid(): string { return (string) ($this->attributes['uuid'] ?? ''); }

    /** @return array<string,mixed> Attribute array. */
    public function toArray(): array { return $this->attributes; }

    /** @return string JSON representation. */
    public function toJson(): string { return json_encode($this->attributes, JSON_UNESCAPED_UNICODE) ?: '{}'; }
}

/**
 * Class: User
 *
 * Table: users
 * Purpose: any account (admin, manager, rider, club).
 * Relations: riderProfile (1:1), club (1:1), sessions (1:N), horses (1:N).
 */
final class User extends BaseModel
{
    public const TABLE = 'users';
    public const CASTS = ['id' => 'int', 'disable_state' => 'string'];

    /** @return bool Whether the user is a rider. */
    public function isRider(): bool { return $this->get('role') === 'rider'; }

    /** @return bool Whether the user is a club. */
    public function isClub(): bool { return $this->get('role') === 'club'; }

    /** @return bool Whether the user is an admin. */
    public function isAdmin(): bool { return $this->get('role') === 'admin'; }

    /** @return bool Whether the user is a manager. */
    public function isManager(): bool { return $this->get('role') === 'manager'; }

    /** @return bool Whether the rider is pending verification. */
    public function isPending(): bool { return $this->get('verification_status') === 'pending'; }

    /** @return bool Whether the account is fully disabled. */
    public function isFullyDisabled(): bool { return $this->get('disable_state') === 'full'; }

    /** @return bool Whether the account is limited. */
    public function isLimited(): bool { return $this->get('disable_state') === 'limited'; }

    /** @return string Full display name. */
    public function fullName(): string
    {
        $name = trim((string) $this->get('first_name', '') . ' ' . (string) $this->get('last_name', ''));
        return $name !== '' ? $name : (string) $this->get('username', '');
    }
}

/**
 * Class: RiderProfile
 * Table: rider_profiles
 * Purpose: rider-specific metadata.
 * Relations: belongs to User.
 */
final class RiderProfile extends BaseModel
{
    public const TABLE = 'rider_profiles';
    public const CASTS = ['id' => 'int', 'user_id' => 'int'];
}

/**
 * Class: Session
 * Table: sessions
 * Purpose: DB-backed session row.
 * Relations: belongs to User.
 */
final class Session extends BaseModel
{
    public const TABLE = 'sessions';
    public const CASTS = ['user_id' => 'int', 'mismatch_count' => 'int', 'impersonated_by' => 'int', 'payload' => 'json'];
}

/**
 * Class: Club
 * Table: clubs
 * Purpose: club profile linked to a user account.
 * Relations: belongs to User; 1:N bans, competitions.
 */
final class Club extends BaseModel
{
    public const TABLE = 'clubs';
    public const CASTS = ['id' => 'int', 'user_id' => 'int', 'is_active' => 'bool'];
}

/**
 * Class: ClubBan
 * Table: club_bans
 * Purpose: club banning a rider/horse from affiliation.
 * Relations: belongs to Club; targets User or Horse.
 */
final class ClubBan extends BaseModel
{
    public const TABLE = 'club_bans';
    public const CASTS = ['id' => 'int', 'club_id' => 'int', 'target_id' => 'int', 'is_active' => 'bool'];
}

/**
 * Class: RiderBan
 * Table: rider_bans
 * Purpose: admin/manager ban (global/competition/rade).
 * Relations: targets User or Horse; optionally scoped.
 */
final class RiderBan extends BaseModel
{
    public const TABLE = 'rider_bans';
    public const CASTS = ['id' => 'int', 'target_id' => 'int', 'competition_id' => 'int', 'rade_id' => 'int', 'is_active' => 'bool'];
}

/**
 * Class: Horse
 * Table: horses
 * Purpose: horse record owned by exactly one rider.
 * Relations: belongs to User (owner); 1:N images, shares, transfers, signups.
 */
final class Horse extends BaseModel
{
    public const TABLE = 'horses';
    public const CASTS = ['id' => 'int', 'owner_user_id' => 'int', 'transfer_locked' => 'bool'];

    /** @return bool Whether the horse is active. */
    public function isActive(): bool { return $this->get('status') === 'active'; }
}

/**
 * Class: HorseImage
 * Table: horse_images
 * Purpose: horse gallery entry.
 * Relations: belongs to Horse and Media.
 */
final class HorseImage extends BaseModel
{
    public const TABLE = 'horse_images';
    public const CASTS = ['id' => 'int', 'horse_id' => 'int', 'media_id' => 'int', 'sort_order' => 'int'];
}

/**
 * Class: HorseTransfer
 * Table: horse_transfers
 * Purpose: ownership transfer request.
 * Relations: belongs to Horse; from/to User.
 */
final class HorseTransfer extends BaseModel
{
    public const TABLE = 'horse_transfers';
    public const CASTS = ['id' => 'int', 'horse_id' => 'int', 'from_user_id' => 'int', 'to_user_id' => 'int'];
}

/**
 * Class: HorseShare
 * Table: horse_shares
 * Purpose: owner sharing a horse to a rider.
 * Relations: belongs to Horse; owner/recipient User.
 */
final class HorseShare extends BaseModel
{
    public const TABLE = 'horse_shares';
    public const CASTS = ['id' => 'int', 'horse_id' => 'int', 'owner_user_id' => 'int', 'recipient_user_id' => 'int'];
}

/**
 * Class: Rade
 * Table: rades
 * Purpose: reusable competition class definition.
 * Relations: 1:N competition_rades.
 */
final class Rade extends BaseModel
{
    public const TABLE = 'rades';
    public const CASTS = ['id' => 'int', 'age_min' => 'int', 'age_max' => 'int', 'age_enforced' => 'bool', 'is_active' => 'bool'];
}

/**
 * Class: Payment
 * Table: payments
 * Purpose: reusable price template.
 * Relations: 1:N competition_rades.
 */
final class Payment extends BaseModel
{
    public const TABLE = 'payments';
    public const CASTS = ['id' => 'int', 'amount_irt' => 'int', 'is_active' => 'bool'];
}

/**
 * Class: Competition
 * Table: competitions
 * Purpose: event at a venue club.
 * Relations: 1:N competition_rades, signups, payment_orders.
 */
final class Competition extends BaseModel
{
    public const TABLE = 'competitions';
    public const CASTS = ['id' => 'int', 'venue_club_id' => 'int', 'registration_paused' => 'bool'];
}

/**
 * Class: CompetitionRade
 * Table: competition_rades
 * Purpose: binds Rade + Payment + capacity + auto-confirm + barrage.
 * Relations: belongs to Competition, Rade, Payment.
 */
final class CompetitionRade extends BaseModel
{
    public const TABLE = 'competition_rades';
    public const CASTS = ['id' => 'int', 'competition_id' => 'int', 'rade_id' => 'int', 'payment_id' => 'int', 'capacity' => 'int', 'auto_confirm' => 'bool', 'had_barrage' => 'bool'];
}

/**
 * Class: Signup
 * Table: signups
 * Purpose: rider entering a horse into a competition-rade.
 * Relations: belongs to Competition, CompetitionRade, User (rider), Horse, Club.
 */
final class Signup extends BaseModel
{
    public const TABLE = 'signups';
    public const CASTS = [
        'id' => 'int', 'competition_id' => 'int', 'competition_rade_id' => 'int', 'rade_id' => 'int',
        'rider_user_id' => 'int', 'horse_id' => 'int', 'affiliation_club_id' => 'int', 'is_confirmed' => 'bool',
        'position' => 'int', 'is_winner' => 'bool', 'payment_amount_irt_snapshot' => 'int',
    ];
}

/**
 * Class: PaymentOrder
 * Table: payment_orders
 * Purpose: ZarinPal transaction lifecycle.
 * Relations: belongs to Signup (optional).
 */
final class PaymentOrder extends BaseModel
{
    public const TABLE = 'payment_orders';
    public const CASTS = ['id' => 'int', 'signup_id' => 'int', 'amount_irt' => 'int'];
}

/**
 * Class: Notification
 * Table: notifications
 * Purpose: in-panel notification.
 * Relations: belongs to User.
 */
final class Notification extends BaseModel
{
    public const TABLE = 'notifications';
    public const CASTS = ['id' => 'int', 'user_id' => 'int', 'is_read' => 'bool', 'ref_id' => 'int'];
}

/**
 * Class: Message
 * Table: messages
 * Purpose: broadcast message.
 * Relations: belongs to User (sender); 1:N recipients.
 */
final class Message extends BaseModel
{
    public const TABLE = 'messages';
    public const CASTS = ['id' => 'int', 'sender_id' => 'int', 'competition_id' => 'int', 'rade_id' => 'int', 'via_sms' => 'bool'];
}

/**
 * Class: MessageRecipient
 * Table: message_recipients
 * Purpose: broadcast delivery + read receipt.
 * Relations: belongs to Message and User.
 */
final class MessageRecipient extends BaseModel
{
    public const TABLE = 'message_recipients';
    public const CASTS = ['id' => 'int', 'message_id' => 'int', 'user_id' => 'int', 'is_read' => 'bool'];
}

/**
 * Class: ReportShare
 * Table: report_shares
 * Purpose: in-panel report share.
 * Relations: belongs to User (owner + recipient).
 */
final class ReportShare extends BaseModel
{
    public const TABLE = 'report_shares';
    public const CASTS = ['id' => 'int', 'owner_user_id' => 'int', 'shared_to_user_id' => 'int', 'filter_state_json' => 'json'];
}

/**
 * Class: Media
 * Table: media
 * Purpose: uploaded file.
 * Relations: belongs to User (owner).
 */
final class Media extends BaseModel
{
    public const TABLE = 'media';
    public const CASTS = ['id' => 'int', 'owner_user_id' => 'int', 'size_bytes' => 'int', 'width' => 'int', 'height' => 'int'];
}

/**
 * Class: Setting
 * Table: settings
 * Purpose: settings registry row.
 * Relations: none.
 */
final class Setting extends BaseModel
{
    public const TABLE = 'settings';
    public const CASTS = ['id' => 'int'];
}

/**
 * Class: Culture
 * Table: cultures
 * Purpose: culture list row.
 * Relations: none.
 */
final class Culture extends BaseModel
{
    public const TABLE = 'cultures';
    public const CASTS = ['id' => 'int', 'is_default' => 'bool', 'is_active' => 'bool'];
}
