-- =============================================================================
-- File: database/schema.sql
--
-- Purpose:
--   Main operational database DDL for the Gorgan Horse Federation Panel.
--   Holds users, clubs, horses, rades, payments, competitions, signups,
--   payment orders, notifications, media, settings, and rate limits.
--
-- Conventions (Blueprint §7, §24):
--   - Table names: snake_case, plural.
--   - Primary keys: integer autoincrement "id".
--   - Foreign keys: {singular_referenced}_id.
--   - Booleans: is_*, has_*, allow_*.
--   - Timestamps: *_at, always UTC ISO-8601 text.
--   - Enum columns: status / *_status / *_state / *_type.
--   - Every column carries a one-line comment.
-- =============================================================================

PRAGMA foreign_keys = ON;

-- -----------------------------------------------------------------------------
-- users
-- Purpose: every account (admin, manager, rider, club), with identity,
--          credentials, disable/verification state, and profile basics.
-- Relations: 1:1 rider_profiles, 1:1 clubs (via clubs.user_id),
--            1:N sessions, horses (owner_user_id), signups (rider_user_id).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid                TEXT NOT NULL UNIQUE,               -- external reference (v4)
    role                TEXT NOT NULL,                      -- admin | manager | rider | club
    username            TEXT NOT NULL UNIQUE,               -- login handle; riders get 6-8 digits
    phone               TEXT UNIQUE,                        -- E.164 (+98...)
    email               TEXT UNIQUE,                        -- optional; never used for sending
    password_hash       TEXT NOT NULL,                      -- password_hash(PASSWORD_DEFAULT)
    first_name          TEXT,                               -- given name
    last_name           TEXT,                               -- family name
    national_id         TEXT,                               -- 10-digit Iranian national ID
    avatar_media_id     INTEGER,                            -- FK media.id (avatar)
    disable_state       TEXT NOT NULL DEFAULT 'none',       -- none | limited | full
    disable_reason      TEXT,                               -- human note for the disable
    verification_status TEXT NOT NULL DEFAULT 'verified',   -- pending | verified | rejected
    auto_verify_at      TEXT,                               -- UTC; when set, auto-verify after this
    last_login_at       TEXT,                               -- UTC of last successful login
    last_login_ip       TEXT,                               -- IP of last successful login
    logged_out_at       TEXT,                               -- UTC; set on force-logout (informational)
    is_demo             INTEGER NOT NULL DEFAULT 0,         -- 1 = demo seed row
    created_at          TEXT NOT NULL,                      -- UTC creation timestamp
    updated_at          TEXT NOT NULL                       -- UTC last update timestamp
);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_phone ON users(phone);
CREATE INDEX IF NOT EXISTS idx_users_verification ON users(verification_status);
CREATE INDEX IF NOT EXISTS idx_users_disable ON users(disable_state);

-- -----------------------------------------------------------------------------
-- rider_profiles
-- Purpose: rider-specific metadata (insurance, birth, address, emergency contact,
--          and the personal 6-digit share code used by other owners to share horses).
-- Relations: belongs to users (user_id, unique).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rider_profiles (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id             INTEGER NOT NULL UNIQUE,            -- FK users.id (role=rider)
    gender              TEXT,                               -- male | female (free text)
    birth_date          TEXT,                               -- UTC ISO date (Shamsi shown in UI)
    insurance_number    TEXT,                               -- federation insurance number
    province            TEXT,                               -- province of residence
    city                TEXT,                               -- city of residence
    address             TEXT,                               -- street address
    bio                 TEXT,                               -- short biography
    emergency_name      TEXT,                               -- emergency contact name
    emergency_phone     TEXT,                               -- emergency contact phone (E.164)
    my_share_code       TEXT NOT NULL,                      -- 6-digit personal code others type to share
    experience_level    TEXT NOT NULL DEFAULT 'active',     -- active | occasional | inactive
    is_demo             INTEGER NOT NULL DEFAULT 0,         -- 1 = demo seed row
    created_at          TEXT NOT NULL,                      -- UTC creation timestamp
    updated_at          TEXT NOT NULL,                      -- UTC last update timestamp
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_rider_profiles_share ON rider_profiles(my_share_code);

-- -----------------------------------------------------------------------------
-- sessions
-- Purpose: DB-backed sessions (P08). Fixed 90-day absolute lifetime, UA-bound.
-- Relations: belongs to users (user_id).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id               TEXT PRIMARY KEY,                      -- 64-char random session id
    user_id          INTEGER NOT NULL,                      -- FK users.id
    role             TEXT NOT NULL,                         -- role at session creation
    impersonated_by  INTEGER,                               -- FK users.id (admin) when impersonating
    ip               TEXT,                                  -- last seen IP
    ua_hash          TEXT NOT NULL,                         -- SHA-256 of user agent
    mismatch_count   INTEGER NOT NULL DEFAULT 0,            -- IP-change warnings; 2 kills session
    payload          TEXT NOT NULL DEFAULT '{}',            -- JSON payload; only {csrf_token}
    last_activity_at TEXT NOT NULL,                         -- UTC of last request
    created_at       TEXT NOT NULL,                         -- UTC creation timestamp (lifetime anchor)
    expires_at       TEXT NOT NULL,                         -- UTC = created_at + 90 days
    is_demo          INTEGER NOT NULL DEFAULT 0,            -- 1 = demo seed row
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);

-- -----------------------------------------------------------------------------
-- clubs
-- Purpose: club profiles with a linked club user account; may host competitions
--          and/or serve as a rider's affiliation.
-- Relations: belongs to users (user_id, unique); 1:N club_bans, competitions (venue).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clubs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid            TEXT NOT NULL UNIQUE,                   -- external reference (v4)
    user_id         INTEGER NOT NULL UNIQUE,                -- FK users.id (role=club)
    name            TEXT NOT NULL,                          -- club display name
    slug            TEXT NOT NULL UNIQUE,                   -- URL-safe unique slug
    city            TEXT,                                   -- city
    province        TEXT,                                   -- province
    address         TEXT,                                   -- street address
    contact_person  TEXT,                                   -- primary contact name
    phone           TEXT,                                   -- contact phone (E.164)
    email           TEXT,                                   -- contact email
    description     TEXT,                                   -- free-text description
    logo_media_id   INTEGER,                                -- FK media.id (logo)
    banner_media_id INTEGER,                                -- FK media.id (banner)
    is_active       INTEGER NOT NULL DEFAULT 1,             -- 1 = active
    is_demo         INTEGER NOT NULL DEFAULT 0,             -- 1 = demo seed row
    created_at      TEXT NOT NULL,                          -- UTC creation timestamp
    updated_at      TEXT NOT NULL,                          -- UTC last update timestamp
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_clubs_city ON clubs(city);
CREATE INDEX IF NOT EXISTS idx_clubs_active ON clubs(is_active);

-- -----------------------------------------------------------------------------
-- club_bans
-- Purpose: a club banning a rider or horse from selecting that club as affiliation.
--          Forward-looking only. Requires the target to have used the club before
--          OR is simply preemptive (both supported).
-- Relations: belongs to clubs; targets users (rider) or horses.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS club_bans (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    club_id       INTEGER NOT NULL,                         -- FK clubs.id
    target_type   TEXT NOT NULL,                            -- rider | horse
    target_id     INTEGER NOT NULL,                         -- users.id or horses.id depending on type
    reason        TEXT,                                     -- reason note
    banned_by     INTEGER NOT NULL,                         -- FK users.id (actor)
    expires_at    TEXT,                                     -- UTC expiry (null = no expiry)
    is_active     INTEGER NOT NULL DEFAULT 1,               -- 1 = active
    is_demo       INTEGER NOT NULL DEFAULT 0,               -- 1 = demo seed row
    created_at    TEXT NOT NULL,                            -- UTC creation timestamp
    updated_at    TEXT NOT NULL,                            -- UTC last update timestamp
    FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_club_bans_club ON club_bans(club_id);
CREATE INDEX IF NOT EXISTS idx_club_bans_target ON club_bans(target_type, target_id);

-- -----------------------------------------------------------------------------
-- rider_bans
-- Purpose: admin/manager bans on a rider (global) or on a rider/horse within a
--          competition or rade. Priority: Rider > Competition > Rade.
-- Relations: targets users (rider) or horses; optionally scoped to competitions/rades.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rider_bans (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    target_type      TEXT NOT NULL,                         -- rider | horse
    target_id        INTEGER NOT NULL,                      -- users.id or horses.id
    scope            TEXT NOT NULL,                         -- global | competition | rade
    competition_id   INTEGER,                               -- FK competitions.id (scope=competition)
    rade_id          INTEGER,                               -- FK rades.id (scope=rade)
    reason           TEXT,                                  -- reason note
    banned_by        INTEGER NOT NULL,                      -- FK users.id (actor)
    expires_at       TEXT,                                  -- UTC expiry (null = no expiry)
    is_active        INTEGER NOT NULL DEFAULT 1,            -- 1 = active
    is_demo          INTEGER NOT NULL DEFAULT 0,            -- 1 = demo seed row
    created_at       TEXT NOT NULL,                         -- UTC creation timestamp
    updated_at       TEXT NOT NULL,                         -- UTC last update timestamp
    FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE CASCADE,
    FOREIGN KEY (rade_id) REFERENCES rades(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_rider_bans_target ON rider_bans(target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_rider_bans_scope ON rider_bans(scope);

-- -----------------------------------------------------------------------------
-- horse_races / horse_colors / horse_genders
-- Purpose: controlled vocabularies for horse attributes.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS horse_races (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL UNIQUE,                        -- race name (Persian)
    sort_order INTEGER NOT NULL DEFAULT 0,                  -- display order
    is_active  INTEGER NOT NULL DEFAULT 1,                  -- 1 = active
    created_at TEXT NOT NULL,                               -- UTC creation timestamp
    updated_at TEXT NOT NULL                                -- UTC last update timestamp
);

CREATE TABLE IF NOT EXISTS horse_colors (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL UNIQUE,                        -- color name (Persian)
    sort_order INTEGER NOT NULL DEFAULT 0,                  -- display order
    is_active  INTEGER NOT NULL DEFAULT 1,                  -- 1 = active
    created_at TEXT NOT NULL,                               -- UTC creation timestamp
    updated_at TEXT NOT NULL                                -- UTC last update timestamp
);

CREATE TABLE IF NOT EXISTS horse_genders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL UNIQUE,                        -- gender name (Persian)
    sort_order INTEGER NOT NULL DEFAULT 0,                  -- display order
    is_active  INTEGER NOT NULL DEFAULT 1,                  -- 1 = active
    created_at TEXT NOT NULL,                               -- UTC creation timestamp
    updated_at TEXT NOT NULL                                -- UTC last update timestamp
);

-- -----------------------------------------------------------------------------
-- horses
-- Purpose: horse records, each owned by exactly one rider. Soft-deleted rows are
--          retained for historical reports; microchip uniqueness ignores them.
-- Relations: belongs to users (owner_user_id); 1:N horse_images, horse_shares,
--            horse_transfers, signups.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS horses (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid             TEXT NOT NULL UNIQUE,                  -- external reference (v4)
    owner_user_id    INTEGER NOT NULL,                      -- FK users.id (owner rider)
    name             TEXT NOT NULL,                         -- horse name
    name_en          TEXT,                                  -- optional Latin name
    microchip_number TEXT,                                  -- 15-digit microchip (unique among active)
    ueln             TEXT,                                  -- optional UELN (<=30 chars)
    gender           TEXT,                                  -- controlled gender value (Persian)
    race             TEXT,                                  -- controlled race value (Persian)
    color            TEXT,                                  -- controlled color value (Persian)
    birth_date       TEXT,                                  -- UTC ISO date of birth
    ghamari_birthday TEXT,                                  -- legacy Ghamari birthday (valid past date)
    sire_name        TEXT,                                  -- father's name
    dam_name         TEXT,                                  -- mother's name
    breeder          TEXT,                                  -- breeder name
    registration_no  TEXT,                                  -- federation registration number
    status           TEXT NOT NULL DEFAULT 'active',        -- active | sold_to_non_rider | soft_deleted
    transfer_locked  INTEGER NOT NULL DEFAULT 0,            -- 1 = a transfer is in progress
    share_code       TEXT,                                  -- current share code (reset on transfer)
    notes            TEXT,                                  -- free-text notes
    is_demo          INTEGER NOT NULL DEFAULT 0,            -- 1 = demo seed row
    created_at       TEXT NOT NULL,                         -- UTC creation timestamp
    updated_at       TEXT NOT NULL,                         -- UTC last update timestamp
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_horses_owner ON horses(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_horses_status ON horses(status);
CREATE INDEX IF NOT EXISTS idx_horses_microchip ON horses(microchip_number);

-- -----------------------------------------------------------------------------
-- horse_images
-- Purpose: horse gallery (max 5 per horse, enforced in service).
-- Relations: belongs to horses and media.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS horse_images (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    horse_id   INTEGER NOT NULL,                            -- FK horses.id
    media_id   INTEGER NOT NULL,                            -- FK media.id
    sort_order INTEGER NOT NULL DEFAULT 0,                  -- gallery order
    is_demo    INTEGER NOT NULL DEFAULT 0,                  -- 1 = demo seed row
    created_at TEXT NOT NULL,                               -- UTC creation timestamp
    FOREIGN KEY (horse_id) REFERENCES horses(id) ON DELETE CASCADE,
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_horse_images_horse ON horse_images(horse_id);

-- -----------------------------------------------------------------------------
-- horse_transfers
-- Purpose: ownership transfer requests between riders.
-- Relations: belongs to horses; from_user_id (current owner), to_user_id (initiator).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS horse_transfers (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    horse_id      INTEGER NOT NULL,                         -- FK horses.id
    from_user_id  INTEGER NOT NULL,                         -- FK users.id (current owner)
    to_user_id    INTEGER,                                  -- FK users.id (buyer), null until code used
    transfer_code TEXT NOT NULL,                            -- 8-char code shared by owner
    status        TEXT NOT NULL DEFAULT 'pending',          -- pending | accepted | rejected | cancelled | expired
    note          TEXT,                                     -- optional message
    accepted_at   TEXT,                                     -- UTC acceptance timestamp
    expires_at    TEXT NOT NULL,                            -- UTC expiry (default +7 days)
    is_demo       INTEGER NOT NULL DEFAULT 0,               -- 1 = demo seed row
    created_at    TEXT NOT NULL,                            -- UTC creation timestamp
    updated_at    TEXT NOT NULL,                            -- UTC last update timestamp
    FOREIGN KEY (horse_id) REFERENCES horses(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_horse_transfers_horse ON horse_transfers(horse_id);
CREATE INDEX IF NOT EXISTS idx_horse_transfers_code ON horse_transfers(transfer_code);

-- -----------------------------------------------------------------------------
-- horse_shares
-- Purpose: an owner sharing a horse to a specific rider for signup use.
-- Relations: belongs to horses; owner_user_id, recipient_user_id.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS horse_shares (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    horse_id          INTEGER NOT NULL,                     -- FK horses.id
    owner_user_id     INTEGER NOT NULL,                     -- FK users.id (owner)
    recipient_user_id INTEGER NOT NULL,                     -- FK users.id (recipient rider)
    status            TEXT NOT NULL DEFAULT 'pending',      -- pending | accepted | rejected | revoked
    responded_at      TEXT,                                 -- UTC of accept/reject
    is_demo           INTEGER NOT NULL DEFAULT 0,           -- 1 = demo seed row
    created_at        TEXT NOT NULL,                        -- UTC creation timestamp
    updated_at        TEXT NOT NULL,                        -- UTC last update timestamp
    FOREIGN KEY (horse_id) REFERENCES horses(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_horse_shares_recipient ON horse_shares(recipient_user_id);
CREATE INDEX IF NOT EXISTS idx_horse_shares_horse ON horse_shares(horse_id);

-- -----------------------------------------------------------------------------
-- rades
-- Purpose: reusable competition class definitions.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rades (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid         TEXT NOT NULL UNIQUE,                      -- external reference (v4)
    name         TEXT NOT NULL,                             -- rade name (e.g. رده E)
    slug         TEXT NOT NULL UNIQUE,                      -- URL-safe unique slug
    description  TEXT,                                      -- free-text description
    age_min      INTEGER,                                   -- minimum age (null = none)
    age_max      INTEGER,                                   -- maximum age (null = none)
    age_enforced INTEGER NOT NULL DEFAULT 0,                -- 1 = age limits enforced
    sort_order   INTEGER NOT NULL DEFAULT 0,                -- display order
    is_active    INTEGER NOT NULL DEFAULT 1,                -- 1 = active
    is_demo      INTEGER NOT NULL DEFAULT 0,                -- 1 = demo seed row
    created_at   TEXT NOT NULL,                             -- UTC creation timestamp
    updated_at   TEXT NOT NULL                              -- UTC last update timestamp
);

-- -----------------------------------------------------------------------------
-- payments
-- Purpose: reusable price templates bound to competition-rades.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid        TEXT NOT NULL UNIQUE,                       -- external reference (v4)
    name        TEXT NOT NULL,                              -- template name
    slug        TEXT NOT NULL UNIQUE,                       -- URL-safe unique slug
    description TEXT,                                       -- free-text description
    amount_irt  INTEGER NOT NULL DEFAULT 0,                 -- price in Toman (integer)
    is_active   INTEGER NOT NULL DEFAULT 1,                 -- 1 = active
    is_demo     INTEGER NOT NULL DEFAULT 0,                 -- 1 = demo seed row
    created_at  TEXT NOT NULL,                              -- UTC creation timestamp
    updated_at  TEXT NOT NULL                               -- UTC last update timestamp
);

-- -----------------------------------------------------------------------------
-- competitions
-- Purpose: an event at a venue club with a registration window and date.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS competitions (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid                  TEXT NOT NULL UNIQUE,             -- external reference (v4)
    title                 TEXT NOT NULL,                    -- competition title
    slug                  TEXT NOT NULL UNIQUE,             -- URL-safe unique slug
    venue_club_id         INTEGER,                          -- FK clubs.id (host venue)
    city                  TEXT,                             -- city (defaults to venue city)
    description           TEXT,                             -- free-text description
    rules                 TEXT,                             -- free-text rules
    start_registration_at TEXT NOT NULL,                    -- UTC registration opens
    end_registration_at   TEXT NOT NULL,                    -- UTC registration closes
    start_at              TEXT NOT NULL,                    -- UTC competition starts
    end_at                TEXT,                             -- UTC competition ends
    registration_paused   INTEGER NOT NULL DEFAULT 0,       -- 1 = registration paused
    status                TEXT NOT NULL DEFAULT 'draft',    -- draft|open|closed|running|finished|cancelled
    results_status        TEXT NOT NULL DEFAULT 'draft',    -- draft | confirmed | published
    results_published_at  TEXT,                             -- UTC of publish
    cancelled_at          TEXT,                             -- UTC of cancellation
    is_demo               INTEGER NOT NULL DEFAULT 0,       -- 1 = demo seed row
    created_at            TEXT NOT NULL,                    -- UTC creation timestamp
    updated_at            TEXT NOT NULL,                    -- UTC last update timestamp
    FOREIGN KEY (venue_club_id) REFERENCES clubs(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_competitions_status ON competitions(status);
CREATE INDEX IF NOT EXISTS idx_competitions_venue ON competitions(venue_club_id);
CREATE INDEX IF NOT EXISTS idx_competitions_start ON competitions(start_at);

-- -----------------------------------------------------------------------------
-- competition_rades
-- Purpose: binds a Rade to a Competition with a Payment, capacity, auto-confirm,
--          barrage flag, and signup mode.
-- Relations: belongs to competitions and rades and payments.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS competition_rades (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid                     TEXT NOT NULL UNIQUE,          -- external reference (v4)
    competition_id           INTEGER NOT NULL,              -- FK competitions.id
    rade_id                  INTEGER NOT NULL,              -- FK rades.id
    payment_id               INTEGER NOT NULL,              -- FK payments.id
    capacity                 INTEGER,                       -- max signups (null = unlimited)
    auto_confirm             INTEGER NOT NULL DEFAULT 0,    -- 1 = auto-confirm at payment success
    had_barrage              INTEGER NOT NULL DEFAULT 0,    -- 1 = barrage was held
    barrage_notes            TEXT,                          -- barrage notes
    signup_mode              TEXT NOT NULL DEFAULT 'per_rade', -- per_competition | per_rade
    sort_order               INTEGER NOT NULL DEFAULT 0,    -- display order
    is_demo                  INTEGER NOT NULL DEFAULT 0,    -- 1 = demo seed row
    created_at               TEXT NOT NULL,                 -- UTC creation timestamp
    updated_at               TEXT NOT NULL,                 -- UTC last update timestamp
    UNIQUE (competition_id, rade_id),
    FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE CASCADE,
    FOREIGN KEY (rade_id) REFERENCES rades(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_comp_rades_comp ON competition_rades(competition_id);

-- -----------------------------------------------------------------------------
-- signups
-- Purpose: a rider entering a horse into a competition-rade, with immutable
--          price snapshots for historical reporting (P22).
-- Relations: belongs to competitions, competition_rades, users (rider),
--            horses, clubs (affiliation).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS signups (
    id                          INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid                        TEXT NOT NULL UNIQUE,       -- external reference (v4)
    competition_id              INTEGER NOT NULL,           -- FK competitions.id
    competition_rade_id         INTEGER NOT NULL,           -- FK competition_rades.id
    rade_id                     INTEGER NOT NULL,           -- FK rades.id (denormalised for reports)
    rider_user_id               INTEGER NOT NULL,           -- FK users.id (rider)
    horse_id                    INTEGER NOT NULL,           -- FK horses.id
    affiliation_club_id         INTEGER,                    -- FK clubs.id (chosen affiliation)
    status                      TEXT NOT NULL DEFAULT 'pending_payment', -- pending_payment|paid|confirmed|rejected|cancelled|withdrawn
    is_confirmed                INTEGER NOT NULL DEFAULT 0, -- 1 = confirmed (auto or manual)
    confirmed_by                INTEGER,                    -- FK users.id (confirmer)
    confirmed_at                TEXT,                       -- UTC confirmation timestamp
    position                    INTEGER,                    -- final rank (null until results)
    is_winner                   INTEGER NOT NULL DEFAULT 0, -- 1 = winner
    result_notes                TEXT,                       -- per-signup result note
    payment_id_snapshot         INTEGER,                    -- immutable payment template id
    payment_name_snapshot       TEXT,                       -- immutable payment name
    payment_amount_irt_snapshot INTEGER NOT NULL DEFAULT 0, -- immutable price in Toman
    order_uuid                  TEXT,                       -- link to payment_orders.uuid
    is_demo                     INTEGER NOT NULL DEFAULT 0, -- 1 = demo seed row
    created_at                  TEXT NOT NULL,              -- UTC creation timestamp
    updated_at                  TEXT NOT NULL,              -- UTC last update timestamp
    FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE CASCADE,
    FOREIGN KEY (competition_rade_id) REFERENCES competition_rades(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (horse_id) REFERENCES horses(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_signups_comp ON signups(competition_id);
CREATE INDEX IF NOT EXISTS idx_signups_rider ON signups(rider_user_id);
CREATE INDEX IF NOT EXISTS idx_signups_horse ON signups(horse_id);
CREATE INDEX IF NOT EXISTS idx_signups_status ON signups(status);
CREATE INDEX IF NOT EXISTS idx_signups_rade ON signups(competition_rade_id);
-- Duplicate guard: a rider+horse+rade is unique unless cancelled/withdrawn.
CREATE UNIQUE INDEX IF NOT EXISTS uq_signups_active
    ON signups(competition_id, competition_rade_id, rider_user_id, horse_id)
    WHERE status NOT IN ('cancelled', 'withdrawn');

-- -----------------------------------------------------------------------------
-- payment_orders
-- Purpose: the ZarinPal transaction lifecycle for a signup (retained forever).
-- Relations: optionally linked to a signup via signup_id.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_orders (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid          TEXT NOT NULL UNIQUE,                     -- external reference (v4)
    signup_id     INTEGER,                                  -- FK signups.id (nullable for free orders)
    competition_id INTEGER,                                 -- FK competitions.id (denormalised)
    rider_user_id INTEGER,                                  -- FK users.id (denormalised)
    amount_irt    INTEGER NOT NULL DEFAULT 0,               -- amount in Toman (integer)
    status        TEXT NOT NULL DEFAULT 'pending',          -- pending|paid|failed|pending_refund|refunded
    authority     TEXT UNIQUE,                              -- ZarinPal authority (unique when set)
    ref_id        TEXT UNIQUE,                              -- ZarinPal reference id (unique when set)
    card_pan      TEXT,                                     -- masked card number returned by ZarinPal
    description   TEXT,                                     -- human description
    gateway       TEXT NOT NULL DEFAULT 'zarinpal',         -- gateway key
    verified_at   TEXT,                                     -- UTC verification timestamp
    refunded_at   TEXT,                                     -- UTC refund timestamp
    refund_note   TEXT,                                     -- refund note
    is_demo       INTEGER NOT NULL DEFAULT 0,               -- 1 = demo seed row
    created_at    TEXT NOT NULL,                            -- UTC creation timestamp
    updated_at    TEXT NOT NULL,                            -- UTC last update timestamp
    FOREIGN KEY (signup_id) REFERENCES signups(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_orders_status ON payment_orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_signup ON payment_orders(signup_id);

-- -----------------------------------------------------------------------------
-- notifications
-- Purpose: in-panel notifications for a user, optionally linked to an entity.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,                           -- FK users.id (recipient)
    type        TEXT NOT NULL,                              -- event type key
    title       TEXT NOT NULL,                              -- short title
    body        TEXT,                                       -- body text
    link        TEXT,                                       -- relative panel link
    ref_type    TEXT,                                       -- referenced entity type
    ref_id      INTEGER,                                    -- referenced entity id
    is_read     INTEGER NOT NULL DEFAULT 0,                 -- 1 = read
    read_at     TEXT,                                       -- UTC read timestamp
    dedupe_key  TEXT,                                       -- dedupe key (user,type,ref) window
    is_demo     INTEGER NOT NULL DEFAULT 0,                 -- 1 = demo seed row
    created_at  TEXT NOT NULL,                              -- UTC creation timestamp
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_dedupe ON notifications(dedupe_key);

-- -----------------------------------------------------------------------------
-- messages
-- Purpose: broadcast messages authored by managers/admins.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid        TEXT NOT NULL UNIQUE,                       -- external reference (v4)
    sender_id   INTEGER NOT NULL,                           -- FK users.id (author)
    subject     TEXT NOT NULL,                              -- subject line
    body        TEXT NOT NULL,                              -- message body
    scope       TEXT NOT NULL DEFAULT 'global',             -- global | competition | rade | selected
    competition_id INTEGER,                                 -- FK competitions.id (scope=competition)
    rade_id     INTEGER,                                    -- FK rades.id (scope=rade)
    via_sms     INTEGER NOT NULL DEFAULT 0,                 -- 1 = also delivered by SMS
    is_demo     INTEGER NOT NULL DEFAULT 0,                 -- 1 = demo seed row
    created_at  TEXT NOT NULL,                              -- UTC creation timestamp
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_messages_sender ON messages(sender_id);

-- -----------------------------------------------------------------------------
-- message_recipients
-- Purpose: broadcast delivery + read receipts per recipient.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS message_recipients (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id   INTEGER NOT NULL,                          -- FK messages.id
    user_id      INTEGER NOT NULL,                          -- FK users.id (recipient)
    is_read      INTEGER NOT NULL DEFAULT 0,                -- 1 = read
    read_at      TEXT,                                      -- UTC read timestamp
    sms_status   TEXT,                                      -- sent | failed | skipped | null
    created_at   TEXT NOT NULL,                             -- UTC creation timestamp
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_message_recipients_user ON message_recipients(user_id);

-- -----------------------------------------------------------------------------
-- report_shares
-- Purpose: in-panel report sharing (live view using the sharer's filters).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS report_shares (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id     INTEGER NOT NULL,                     -- FK users.id (sharer)
    shared_to_user_id INTEGER NOT NULL,                     -- FK users.id (recipient)
    report_key        TEXT NOT NULL,                        -- report key
    filter_state_json TEXT NOT NULL DEFAULT '{}',           -- serialised filters
    is_demo           INTEGER NOT NULL DEFAULT 0,           -- 1 = demo seed row
    created_at        TEXT NOT NULL,                        -- UTC creation timestamp
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_to_user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_report_shares_owner ON report_shares(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_report_shares_recipient ON report_shares(shared_to_user_id);

-- -----------------------------------------------------------------------------
-- media
-- Purpose: uploaded files (avatars, horse/club images, documents).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS media (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid          TEXT NOT NULL UNIQUE,                     -- external reference (v4)
    owner_user_id INTEGER,                                  -- FK users.id (uploader)
    kind          TEXT NOT NULL,                            -- avatar | horse | club | demo | doc
    original_name TEXT,                                     -- original filename (metadata only)
    path          TEXT NOT NULL,                            -- storage path relative to BASE_PATH
    mime          TEXT NOT NULL,                            -- sniffed MIME type
    size_bytes    INTEGER NOT NULL DEFAULT 0,               -- file size in bytes
    width         INTEGER,                                  -- image width (nullable)
    height        INTEGER,                                  -- image height (nullable)
    is_demo       INTEGER NOT NULL DEFAULT 0,               -- 1 = demo seed row
    created_at    TEXT NOT NULL,                            -- UTC creation timestamp
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_media_owner ON media(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_media_kind ON media(kind);

-- -----------------------------------------------------------------------------
-- settings
-- Purpose: rich settings registry (P11). All configuration lives here.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    key        TEXT NOT NULL UNIQUE,                        -- dot-notation key (group.name)
    value      TEXT,                                        -- serialised value
    type       TEXT NOT NULL DEFAULT 'string',              -- string | int | bool | json | float
    grp        TEXT NOT NULL DEFAULT 'general',             -- settings group
    label      TEXT,                                        -- human label (fa-IR)
    help       TEXT,                                        -- help text (fa-IR)
    updated_at TEXT NOT NULL                                -- UTC last update timestamp
);

-- -----------------------------------------------------------------------------
-- cultures
-- Purpose: culture list (fa-IR default, en-US optional).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cultures (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    code      TEXT NOT NULL UNIQUE,                         -- fa-IR | en-US
    name      TEXT NOT NULL,                                -- display name
    direction TEXT NOT NULL DEFAULT 'rtl',                  -- rtl | ltr
    timezone  TEXT NOT NULL DEFAULT 'Asia/Tehran',          -- default timezone
    is_default INTEGER NOT NULL DEFAULT 0,                  -- 1 = default culture
    is_active INTEGER NOT NULL DEFAULT 1,                   -- 1 = active
    sort_order INTEGER NOT NULL DEFAULT 0,                  -- display order
    created_at TEXT NOT NULL,                               -- UTC creation timestamp
    updated_at TEXT NOT NULL                                -- UTC last update timestamp
);

-- -----------------------------------------------------------------------------
-- rate_limits
-- Purpose: generic rate limiting counters.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket     TEXT NOT NULL,                               -- bucket key (route + identity hash)
    hits       INTEGER NOT NULL DEFAULT 0,                  -- hit count in the window
    window_start TEXT NOT NULL,                             -- UTC window start
    updated_at TEXT NOT NULL,                               -- UTC last update
    UNIQUE (bucket)
);

-- -----------------------------------------------------------------------------
-- password_resets
-- Purpose: reserved for a future manager-triggered password reset flow.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,                            -- FK users.id
    token      TEXT NOT NULL UNIQUE,                        -- reset token
    expires_at TEXT NOT NULL,                               -- UTC expiry
    used_at    TEXT,                                        -- UTC usage timestamp
    created_at TEXT NOT NULL,                               -- UTC creation timestamp
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- -----------------------------------------------------------------------------
-- api_tokens
-- Purpose: reserved for future API access.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,                            -- FK users.id
    name       TEXT NOT NULL,                               -- token label
    token_hash TEXT NOT NULL UNIQUE,                        -- hashed token
    last_used_at TEXT,                                      -- UTC last use
    expires_at TEXT,                                        -- UTC expiry (null = none)
    created_at TEXT NOT NULL,                               -- UTC creation timestamp
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- -----------------------------------------------------------------------------
-- sms_templates
-- Purpose: reusable SMS message templates with variable placeholders.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sms_templates (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,                              -- template name
    body        TEXT NOT NULL,                              -- message body with {var} placeholders
    variables   TEXT DEFAULT '[]',                          -- JSON array of variable names
    is_active   INTEGER NOT NULL DEFAULT 1,                 -- 1 = active
    created_at  TEXT NOT NULL,                              -- UTC creation timestamp
    updated_at  TEXT NOT NULL                               -- UTC update timestamp
);
