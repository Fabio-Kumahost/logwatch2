-- Logwatch2 initial schema (PostgreSQL 16)
-- Applied by: php bin/console migrate  (tracks applied files in schema_migrations)

BEGIN;

CREATE TYPE user_role     AS ENUM ('admin', 'user');
CREATE TYPE server_status AS ENUM ('online', 'offline', 'warning', 'critical');
CREATE TYPE log_level     AS ENUM ('debug', 'info', 'notice', 'warning', 'error', 'critical');
CREATE TYPE group_status  AS ENUM ('open', 'acknowledged', 'resolved', 'ignored');
CREATE TYPE channel_type  AS ENUM ('discord', 'gotify');
CREATE TYPE rule_trigger  AS ENUM ('critical_error', 'server_offline', 'new_error', 'recurring_error');

CREATE TABLE users (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username      varchar(64)  NOT NULL UNIQUE,
    email         varchar(255),
    password_hash text         NOT NULL,            -- Argon2id
    role          user_role    NOT NULL DEFAULT 'user',
    is_active     boolean      NOT NULL DEFAULT true,
    created_at    timestamptz  NOT NULL DEFAULT now(),
    last_login_at timestamptz
);

CREATE TABLE login_attempts (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username   varchar(64) NOT NULL,
    ip         inet        NOT NULL,
    success    boolean     NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_login_attempts_window ON login_attempts (username, ip, created_at DESC);

CREATE TABLE servers (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    public_id     uuid          NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    name          varchar(128)  NOT NULL UNIQUE,
    hostname      varchar(255),
    token_hash    char(64)      NOT NULL UNIQUE,    -- sha256(agent token), never plaintext
    status        server_status NOT NULL DEFAULT 'offline',
    agent_version varchar(32),
    os_info       varchar(255),
    tags          jsonb         NOT NULL DEFAULT '[]',
    last_seen_at  timestamptz,
    created_at    timestamptz   NOT NULL DEFAULT now()
);

CREATE TABLE error_groups (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fingerprint      char(64)     NOT NULL UNIQUE,
    service          varchar(128) NOT NULL,
    source_class     varchar(512) NOT NULL,          -- log path without rotation suffix
    level            log_level    NOT NULL,
    title            varchar(512) NOT NULL,          -- normalized message, truncated
    status           group_status NOT NULL DEFAULT 'open',
    recurring        boolean      NOT NULL DEFAULT false,
    occurrence_count bigint       NOT NULL DEFAULT 0,
    server_ids       bigint[]     NOT NULL DEFAULT '{}',
    first_seen       timestamptz  NOT NULL DEFAULT now(),
    last_seen        timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX idx_groups_triage ON error_groups (status, level, last_seen DESC);

CREATE TABLE log_entries (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    server_id      bigint       NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    error_group_id bigint       REFERENCES error_groups(id) ON DELETE SET NULL,
    ts             timestamptz  NOT NULL,             -- from log line, else received_at
    received_at    timestamptz  NOT NULL DEFAULT now(),
    source_file    varchar(512) NOT NULL,
    service        varchar(128) NOT NULL DEFAULT 'unknown',
    level          log_level    NOT NULL DEFAULT 'info',
    message        text         NOT NULL,
    raw            text         NOT NULL,
    fingerprint    char(64),
    meta           jsonb        NOT NULL DEFAULT '{}'
);
CREATE INDEX idx_entries_server_ts ON log_entries (server_id, ts DESC);
CREATE INDEX idx_entries_level_ts  ON log_entries (level, ts DESC);
CREATE INDEX idx_entries_group     ON log_entries (error_group_id) WHERE error_group_id IS NOT NULL;
CREATE INDEX idx_entries_search    ON log_entries USING gin (to_tsvector('simple', message));

CREATE TABLE ai_analyses (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fingerprint       char(64)    NOT NULL UNIQUE,    -- the cache key
    provider          varchar(32) NOT NULL,
    model             varchar(128) NOT NULL,
    summary           text        NOT NULL,
    explanation       text        NOT NULL,
    probable_causes   jsonb       NOT NULL DEFAULT '[]',
    impact            text        NOT NULL DEFAULT '',
    severity          smallint    NOT NULL CHECK (severity BETWEEN 1 AND 5),
    urgency           varchar(16) NOT NULL,
    solution_steps    jsonb       NOT NULL DEFAULT '[]',
    commands          jsonb       NOT NULL DEFAULT '[]',
    related_checks    jsonb       NOT NULL DEFAULT '[]',
    masked_input_hash char(64)    NOT NULL,           -- audit: masking gate ran
    tokens_used       integer     NOT NULL DEFAULT 0,
    created_at        timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE notification_channels (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    type       channel_type NOT NULL,
    name       varchar(128) NOT NULL,
    config     text         NOT NULL,                 -- libsodium-sealed JSON (webhook URL / token)
    is_active  boolean      NOT NULL DEFAULT true,
    created_at timestamptz  NOT NULL DEFAULT now()
);

CREATE TABLE notification_rules (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_id       bigint       NOT NULL REFERENCES notification_channels(id) ON DELETE CASCADE,
    trigger          rule_trigger NOT NULL,
    filters          jsonb        NOT NULL DEFAULT '{}',   -- {server_ids:[],services:[],min_level:}
    cooldown_seconds integer      NOT NULL DEFAULT 900,
    is_active        boolean      NOT NULL DEFAULT true,
    created_at       timestamptz  NOT NULL DEFAULT now()
);

CREATE TABLE notifications_log (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    rule_id        bigint      REFERENCES notification_rules(id) ON DELETE SET NULL,
    channel_id     bigint      REFERENCES notification_channels(id) ON DELETE SET NULL,
    error_group_id bigint      REFERENCES error_groups(id) ON DELETE SET NULL,
    server_id      bigint      REFERENCES servers(id) ON DELETE SET NULL,
    status         varchar(16) NOT NULL,              -- sent | suppressed | failed
    reason         varchar(255),                      -- cooldown / hourly_cap / dedupe / http_4xx…
    payload_hash   char(64),
    sent_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_notif_ratelimit ON notifications_log (rule_id, error_group_id, sent_at DESC);
CREATE INDEX idx_notif_cap       ON notifications_log (channel_id, sent_at DESC);

CREATE TABLE settings (
    key          varchar(128) PRIMARY KEY,
    value        text        NOT NULL,                -- JSON; sealed when is_encrypted
    is_encrypted boolean     NOT NULL DEFAULT false,
    updated_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE jobs (
    id        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    type      varchar(64) NOT NULL,                   -- ai.analyze | notify.dispatch | …
    payload   jsonb       NOT NULL DEFAULT '{}',
    run_at    timestamptz NOT NULL DEFAULT now(),
    attempts  smallint    NOT NULL DEFAULT 0,
    locked_at timestamptz,
    locked_by varchar(64),
    failed_at timestamptz,
    error     text,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_jobs_claim ON jobs (run_at) WHERE locked_at IS NULL AND failed_at IS NULL;

CREATE TABLE audit_log (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id    bigint      REFERENCES users(id) ON DELETE SET NULL,
    action     varchar(64) NOT NULL,                  -- login / settings.update / server.token_rotate…
    details    jsonb       NOT NULL DEFAULT '{}',
    ip         inet,
    created_at timestamptz NOT NULL DEFAULT now()
);

COMMIT;
