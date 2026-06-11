-- Logwatch2 0.1.0 feature set: TOTP 2FA, DB-backed rate limiting,
-- Security Radar / anomaly events, extensible notification triggers.

BEGIN;

-- Two-factor auth: base32 secret, sealed with APP_KEY (libsodium). NULL = disabled.
ALTER TABLE users ADD COLUMN totp_secret text;

-- Fixed-window rate limiting across all php-fpm workers (see RateLimitMiddleware).
CREATE TABLE rate_limits (
    key        varchar(160) PRIMARY KEY,   -- realm:subject:window
    count      integer      NOT NULL DEFAULT 1,
    created_at timestamptz  NOT NULL DEFAULT now()
);

-- Security Radar + statistical anomaly detection findings.
CREATE TABLE anomaly_events (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    server_id  bigint       REFERENCES servers(id) ON DELETE CASCADE,
    kind       varchar(32)  NOT NULL,      -- auth_attack | error_rate
    details    varchar(512) NOT NULL,
    created_at timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX idx_anomaly_recent ON anomaly_events (server_id, kind, created_at DESC);

-- New triggers (auth_attack, anomaly, digest, server_recovered) need an
-- extensible column: enum → varchar + CHECK. Adding future triggers is then
-- a CHECK swap instead of an enum migration.
ALTER TABLE notification_rules
    ALTER COLUMN "trigger" TYPE varchar(32) USING "trigger"::text;
ALTER TABLE notification_rules
    ADD CONSTRAINT chk_rule_trigger CHECK ("trigger" IN (
        'critical_error', 'server_offline', 'new_error', 'recurring_error',
        'auth_attack', 'anomaly', 'digest', 'server_recovered'));
DROP TYPE rule_trigger;

COMMIT;
