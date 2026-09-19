-- =============================================================================
-- File: database/schema_logs.sql
--
-- Purpose:
--   Logs database DDL for the Gorgan Horse Federation Panel. Kept in a separate
--   SQLite file (logs.sqlite) so the main DB stays small and fast (P13).
--   Holds audit logs, app logs, login attempts, SMS logs, OTP codes, changelog.
--
-- Retention (Blueprint §20.2):
--   app 30d, audit 180d, SMS 90d, login attempts 30d, changelog forever.
-- =============================================================================

PRAGMA foreign_keys = ON;

-- -----------------------------------------------------------------------------
-- audit_logs
-- Purpose: every write with actor, target, diff, and result.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id    TEXT,                                     -- request UUID
    actor_id      INTEGER,                                  -- acting user id (null = system)
    actor_role    TEXT,                                     -- actor role
    impersonated_by INTEGER,                                -- admin id when impersonating
    action        TEXT NOT NULL,                            -- dot notation e.g. user.create
    target_type   TEXT,                                     -- entity type
    target_id     INTEGER,                                  -- entity id
    diff_json     TEXT,                                     -- JSON diff of changed fields
    ip            TEXT,                                     -- actor IP
    ua_hash       TEXT,                                     -- actor UA hash
    result        TEXT NOT NULL DEFAULT 'ok',               -- ok | error
    created_at    TEXT NOT NULL                             -- UTC creation timestamp
);
CREATE INDEX IF NOT EXISTS idx_audit_actor ON audit_logs(actor_id);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_logs(action);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);

-- -----------------------------------------------------------------------------
-- app_logs
-- Purpose: errors, warnings, and slow queries (>200 ms).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT,                                        -- request UUID
    level      TEXT NOT NULL,                               -- debug | info | warning | error
    message    TEXT NOT NULL,                               -- log message
    context    TEXT,                                        -- JSON context
    duration_ms INTEGER,                                    -- duration for slow-query logs
    created_at TEXT NOT NULL                                -- UTC creation timestamp
);
CREATE INDEX IF NOT EXISTS idx_app_logs_level ON app_logs(level);
CREATE INDEX IF NOT EXISTS idx_app_logs_created ON app_logs(created_at);

-- -----------------------------------------------------------------------------
-- login_attempts
-- Purpose: failed and blocked login attempts (successful logins are audited).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier  TEXT,                                       -- username or phone attempted
    user_id     INTEGER,                                    -- matched user id (nullable)
    ip          TEXT,                                       -- source IP
    ua_hash     TEXT,                                       -- UA hash
    result      TEXT NOT NULL,                              -- failed | blocked | success
    reason      TEXT,                                       -- error code
    created_at  TEXT NOT NULL                               -- UTC creation timestamp
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip);
CREATE INDEX IF NOT EXISTS idx_login_attempts_created ON login_attempts(created_at);

-- -----------------------------------------------------------------------------
-- sms_logs
-- Purpose: outbound SMS records.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sms_logs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient   TEXT NOT NULL,                              -- destination number (E.164)
    template    TEXT,                                       -- pattern/template key
    body        TEXT,                                       -- message body (or pattern args)
    status      TEXT NOT NULL,                              -- sent | failed | skipped
    provider_id TEXT,                                       -- provider message id
    error       TEXT,                                       -- provider error text
    created_at  TEXT NOT NULL                               -- UTC creation timestamp
);
CREATE INDEX IF NOT EXISTS idx_sms_logs_recipient ON sms_logs(recipient);
CREATE INDEX IF NOT EXISTS idx_sms_logs_created ON sms_logs(created_at);

-- -----------------------------------------------------------------------------
-- otp_codes
-- Purpose: hashed OTP codes with attempt counters and TTL.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS otp_codes (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    phone        TEXT NOT NULL,                             -- destination phone (E.164)
    code_hash    TEXT NOT NULL,                             -- hash of the OTP
    attempts     INTEGER NOT NULL DEFAULT 0,                -- verification attempts
    max_attempts INTEGER NOT NULL DEFAULT 3,                -- attempts before invalidation
    is_used      INTEGER NOT NULL DEFAULT 0,                -- 1 = consumed
    expires_at   TEXT NOT NULL,                             -- UTC expiry
    created_at   TEXT NOT NULL                              -- UTC creation timestamp
);
CREATE INDEX IF NOT EXISTS idx_otp_phone ON otp_codes(phone);
CREATE INDEX IF NOT EXISTS idx_otp_expires ON otp_codes(expires_at);

-- -----------------------------------------------------------------------------
-- changelog
-- Purpose: manager/admin action summaries surfaced on the Admin dashboard.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS changelog (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id    INTEGER,                                    -- acting user id
    actor_role  TEXT,                                       -- actor role
    action      TEXT NOT NULL,                              -- dot-notation action
    target_type TEXT,                                       -- entity type
    target_id   INTEGER,                                    -- entity id
    summary     TEXT NOT NULL,                              -- human-readable summary (fa-IR)
    link        TEXT,                                       -- relative panel link
    created_at  TEXT NOT NULL                               -- UTC creation timestamp
);
CREATE INDEX IF NOT EXISTS idx_changelog_created ON changelog(created_at);
CREATE INDEX IF NOT EXISTS idx_changelog_actor ON changelog(actor_id);
