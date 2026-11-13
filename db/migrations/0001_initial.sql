-- 0001_initial.sql: the initial mothership schema (MS-DAT-01).
--
-- Status: contract approved 2026-11-13 (M-017). The schema is approved once it
-- applies cleanly on the milestone 1 VM; until then it may still be edited in place.
-- Target: MariaDB 11.8 LTS from Ubuntu 26.04.1 LTS main (M-029), InnoDB, utf8mb4.
--
-- Conventions (M-010; see docs/decisions.md):
--   * All times are UTC, DATETIME(6). The agent sends 7 fractional digits; the
--     7th is dropped consistently, so a resent line maps to the same value.
--   * One table per agent record type (CLAUDE.md, plan "Data model"). Columns
--     use the agent's own field names; nested objects and arrays are JSON
--     columns of the same name; unknown top-level fields go to `extra` (MS-ING-05).
--   * Envelope: `agent_pk` and `host_pk` are surrogate keys for agents.agent_id
--     and hosts.machine_guid; `seq` and `ts` are kept as sent. `batch_pk` points
--     at ingest_batches, for tracing and for the alert job's "new rows" scan.
--   * Big tables are partitioned by day on `ts` (RANGE COLUMNS). This migration
--     creates only p_start and p_future; the partition job (MS-DAT-02) splits
--     p_future into daily partitions 7 days ahead and drops expired ones.
--     Partitioned tables have no foreign keys (InnoDB doesn't allow them).
--   * Dedup (MS-ING-03, M-005): PRIMARY KEY (agent_pk, seq, ts) on every
--     partitioned record table. Ingest uses INSERT IGNORE on pre-validated rows
--     and fails the batch on any warning other than a duplicate key.
--   * IP addresses: the original string (may hold an IPv6 zone) plus a
--     VARBINARY(16) form from INET6_ATON for lookups (NULL if it doesn't parse).
--   * Retention per table is data in `retention_policies`, not hard-coded.
--
-- Database users and grants (MS-SEC-04) are set up with the server
-- (docs/setup.md, milestone 1 server part), not in this file.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Migration bookkeeping
-- ---------------------------------------------------------------------------

CREATE TABLE schema_migrations (
    version     VARCHAR(64)  NOT NULL PRIMARY KEY,
    applied_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    checksum    CHAR(64)     NOT NULL COMMENT 'SHA-256 of the migration file'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Hosts, agents, certificates, enrolment (MS-ING-02, 06, 08; MS-SEC-02, 03)
-- ---------------------------------------------------------------------------

CREATE TABLE host_groups (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)  NOT NULL,
    description VARCHAR(500)  NULL,
    created_at  DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_host_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO host_groups (name, description) VALUES ('default', 'Hosts enrolled without a group');

-- A host is a MachineGuid plus hostname at enrolment (D-003: clones can share
-- a MachineGuid, so machine_guid is not unique).
CREATE TABLE hosts (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_guid  CHAR(36)      NOT NULL COMMENT 'envelope host_id',
    hostname      VARCHAR(255)  NOT NULL COMMENT 'current envelope hostname',
    group_id      INT UNSIGNED  NOT NULL,
    first_seen    DATETIME(6)   NOT NULL,
    last_seen     DATETIME(6)   NULL,
    retired_at    DATETIME(6)   NULL,
    KEY ix_hosts_guid (machine_guid),
    KEY ix_hosts_hostname (hostname),
    KEY ix_hosts_group (group_id),
    CONSTRAINT fk_hosts_group FOREIGN KEY (group_id) REFERENCES host_groups (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hostname history: a renamed host keeps its row (ingest-api.md §3.4).
CREATE TABLE host_names (
    host_pk     INT UNSIGNED  NOT NULL,
    hostname    VARCHAR(255)  NOT NULL,
    first_seen  DATETIME(6)   NOT NULL,
    last_seen   DATETIME(6)   NOT NULL,
    PRIMARY KEY (host_pk, hostname),
    CONSTRAINT fk_host_names_host FOREIGN KEY (host_pk) REFERENCES hosts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agents (
    id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agent_id           CHAR(36)      NOT NULL COMMENT 'UUID v4, the certificate CN (M-003)',
    host_pk            INT UNSIGNED  NOT NULL,
    status             ENUM('active','revoked','replaced') NOT NULL DEFAULT 'active',
    enrolled_at        DATETIME(6)   NOT NULL,
    revoked_at         DATETIME(6)   NULL,
    revoked_by         INT UNSIGNED  NULL COMMENT 'users.id',
    revoke_reason      VARCHAR(500)  NULL,
    -- MS-ING-08: updated by every request.
    last_checkin_at    DATETIME(6)   NULL,
    last_checkin_ip    VARBINARY(16) NULL,
    last_version       VARCHAR(64)   NULL,
    last_refused_at    DATETIME(6)   NULL COMMENT 'last 401/403 for this agent',
    -- Ingest state (ingest-api.md §3.3, §3.6).
    max_seq_held       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_ingest_at     DATETIME(6)   NULL,
    seq_regression_at  DATETIME(6)   NULL COMMENT 'state rollback detected (§3.6)',
    -- Last heartbeat summary (AG-PRF-06).
    last_heartbeat_at  DATETIME(6)   NULL,
    clock_skew_ms      INT           NULL COMMENT 'agent_time minus server time',
    queue_records      BIGINT UNSIGNED NULL,
    queue_bytes        BIGINT UNSIGNED NULL,
    queue_oldest_ts    DATETIME(6)   NULL,
    queue_dropped_records BIGINT UNSIGNED NULL,
    config_etag        VARCHAR(100)  NULL COMMENT 'the settings the agent says it uses',
    UNIQUE KEY uq_agents_agent_id (agent_id),
    KEY ix_agents_host (host_pk),
    KEY ix_agents_checkin (status, last_checkin_at),
    CONSTRAINT fk_agents_host FOREIGN KEY (host_pk) REFERENCES hosts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every certificate issued (M-002). Looked up on every request (§2.4).
CREATE TABLE agent_certificates (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agent_pk            INT UNSIGNED  NOT NULL,
    serial_hex          VARCHAR(40)   NOT NULL COMMENT '128-bit serial, upper-case hex',
    fingerprint_sha256  BINARY(32)    NOT NULL,
    not_before          DATETIME(6)   NOT NULL,
    not_after           DATETIME(6)   NOT NULL,
    issued_at           DATETIME(6)   NOT NULL,
    issued_via          ENUM('enroll','renew') NOT NULL,
    status              ENUM('valid','superseded','revoked') NOT NULL DEFAULT 'valid',
    revoked_at          DATETIME(6)   NULL,
    revoke_reason       VARCHAR(500)  NULL,
    UNIQUE KEY uq_certs_serial (serial_hex),
    UNIQUE KEY uq_certs_fingerprint (fingerprint_sha256),
    KEY ix_certs_agent (agent_pk),
    KEY ix_certs_expiry (not_after),
    CONSTRAINT fk_certs_agent FOREIGN KEY (agent_pk) REFERENCES agents (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MS-SEC-03: single use, expiring, stored only as a SHA-256 hash. The token
-- has 256 random bits, so a plain hash is enough (no slow KDF needed).
CREATE TABLE enrollment_tokens (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_sha256        BINARY(32)    NOT NULL,
    group_id            INT UNSIGNED  NOT NULL,
    expected_hostname   VARCHAR(255)  NULL COMMENT 'optional: only this hostname may use it',
    reenroll_agent_id   CHAR(36)      NULL COMMENT 'set: a re-enrolment token for this agent (§2.5)',
    token_kind          ENUM('manual','deployment') NOT NULL DEFAULT 'manual' COMMENT 'M-021: manual at most 24 h, deployment at most 7 days',
    note                VARCHAR(500)  NULL,
    created_by          INT UNSIGNED  NULL COMMENT 'users.id; NULL when made by the CLI',
    created_at          DATETIME(6)   NOT NULL,
    expires_at          DATETIME(6)   NOT NULL,
    used_at             DATETIME(6)   NULL,
    used_by_agent_pk    INT UNSIGNED  NULL,
    used_from_ip        VARBINARY(16) NULL,
    UNIQUE KEY uq_tokens_hash (token_sha256),
    KEY ix_tokens_expiry (expires_at),
    CONSTRAINT fk_tokens_group FOREIGN KEY (group_id) REFERENCES host_groups (id),
    CONSTRAINT fk_tokens_agent FOREIGN KEY (used_by_agent_pk) REFERENCES agents (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed enrolments per source address, for 429 too_many_enrollments.
CREATE TABLE enrollment_failures (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_ip   VARBINARY(16)  NOT NULL,
    at          DATETIME(6)    NOT NULL,
    code        VARCHAR(64)    NOT NULL,
    KEY ix_enroll_fail_ip (source_ip, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-group agent settings served by /api/v1/config (MS-ING-07). Stored as
-- overrides on the built-in defaults; the detection list is validated on save
-- and again when served (service names and HKLM keys only, never paths).
CREATE TABLE agent_config (
    group_id        INT UNSIGNED  NOT NULL PRIMARY KEY,
    config_version  INT UNSIGNED  NOT NULL DEFAULT 1,
    settings        JSON          NOT NULL COMMENT 'overrides of intervals, transport, collectors, caps',
    exclusions      JSON          NOT NULL DEFAULT ('[]'),
    redaction_rules JSON          NOT NULL DEFAULT ('[]') COMMENT 'additive only',
    detection_list  JSON          NOT NULL DEFAULT ('[]') COMMENT 'D-071 DetectEntry list',
    replace_builtin_detection BOOLEAN NOT NULL DEFAULT FALSE,
    etag            VARCHAR(100)  NOT NULL,
    updated_at      DATETIME(6)   NOT NULL,
    updated_by      INT UNSIGNED  NULL,
    CONSTRAINT fk_agent_config_group FOREIGN KEY (group_id) REFERENCES host_groups (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO agent_config (group_id, settings, etag, updated_at)
SELECT id, '{}', '"cfg-1-1"', UTC_TIMESTAMP(6) FROM host_groups WHERE name = 'default';

-- One row per ingest request: tracing, load-test figures (p95, rows/s),
-- and the alert job's cursor.
CREATE TABLE ingest_batches (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    received_at       DATETIME(6)    NOT NULL,
    agent_pk          INT UNSIGNED   NOT NULL,
    batch_id          VARCHAR(64)    NULL COMMENT 'X-AIO-Batch-Id',
    source_ip         VARBINARY(16)  NULL,
    agent_version     VARCHAR(64)    NULL,
    http_status       SMALLINT UNSIGNED NOT NULL,
    error_code        VARCHAR(64)    NULL,
    bytes_compressed  INT UNSIGNED   NOT NULL,
    bytes_raw         INT UNSIGNED   NULL,
    line_count        INT UNSIGNED   NULL COMMENT 'response "lines" (LINES is a reserved word)',
    stored_count      INT UNSIGNED   NULL,
    duplicate_count   INT UNSIGNED   NULL,
    quarantined_count INT UNSIGNED   NULL,
    min_seq           BIGINT UNSIGNED NULL,
    max_seq           BIGINT UNSIGNED NULL,
    duration_ms       INT UNSIGNED   NOT NULL,
    PRIMARY KEY (id, received_at),
    KEY ix_batches_agent (agent_pk, received_at),
    KEY ix_batches_time (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (received_at) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- AG-PRF-06 heartbeats (ingest-api.md §4).
CREATE TABLE agent_heartbeats (
    agent_pk          INT UNSIGNED   NOT NULL,
    received_at       DATETIME(6)    NOT NULL,
    agent_time        DATETIME(6)    NULL,
    source_ip         VARBINARY(16)  NULL,
    agent_version     VARCHAR(64)    NULL,
    uptime_s          DOUBLE         NULL,
    queue_records     BIGINT UNSIGNED NULL,
    queue_bytes       BIGINT UNSIGNED NULL,
    queue_oldest_ts   DATETIME(6)    NULL,
    queue_dropped_records BIGINT UNSIGNED NULL,
    last_acked_seq    BIGINT UNSIGNED NULL,
    consecutive_failures INT UNSIGNED NULL,
    last_error        VARCHAR(64)    NULL,
    body              JSON           NOT NULL COMMENT 'the whole heartbeat, unknown fields included',
    PRIMARY KEY (agent_pk, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (received_at) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- MS-ING-05: lines that can't be stored, with the reason; the rest of the
-- batch is stored. Raw line kept for replay (e.g. a provisional type).
CREATE TABLE quarantine (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    received_day  DATE           NOT NULL,
    received_at   DATETIME(6)    NOT NULL,
    agent_pk      INT UNSIGNED   NOT NULL,
    batch_pk      BIGINT UNSIGNED NOT NULL,
    seq           BIGINT UNSIGNED NULL COMMENT 'NULL if it could not be read',
    record_type   VARCHAR(64)    NULL,
    schema_version INT           NULL,
    reason        VARCHAR(200)   NOT NULL COMMENT 'e.g. unknown_record_type, missing_field:pid',
    line_sha256   BINARY(32)     NOT NULL,
    line_bytes    INT UNSIGNED   NOT NULL COMMENT 'full length before any cut',
    line          MEDIUMBLOB     NOT NULL COMMENT 'raw line; first 64 KB when line_too_long',
    replayed_at   DATETIME(6)    NULL,
    PRIMARY KEY (id, received_day),
    UNIQUE KEY uq_quarantine_line (agent_pk, line_sha256, received_day),
    KEY ix_quarantine_reason (reason, received_day),
    KEY ix_quarantine_type (record_type, received_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (received_day) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- ---------------------------------------------------------------------------
-- Network snapshots (milestone 1 records): tcp_conn, tcp_listener,
-- udp_endpoint, net_snapshot_summary. All records of one snapshot share `ts`.
-- Process attribution columns are the agent's proc.Process fields.
-- ---------------------------------------------------------------------------

CREATE TABLE snapshots (
    agent_pk           INT UNSIGNED   NOT NULL,
    host_pk            INT UNSIGNED   NOT NULL,
    seq                BIGINT UNSIGNED NOT NULL,
    ts                 DATETIME(6)    NOT NULL,
    batch_pk           BIGINT UNSIGNED NOT NULL,
    tcp_conns          INT UNSIGNED   NOT NULL,
    tcp_listeners      INT UNSIGNED   NOT NULL,
    udp_endpoints      INT UNSIGNED   NOT NULL,
    loopback_skipped   INT UNSIGNED   NULL,
    include_loopback   BOOLEAN        NULL,
    attribution_errors INT UNSIGNED   NULL,
    duration_ms        BIGINT         NULL,
    etw_reconciliation JSON           NULL COMMENT 'D-019',
    extra              JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_snapshots_host_ts (host_pk, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE snap_tcp_conns (
    agent_pk           INT UNSIGNED   NOT NULL,
    host_pk            INT UNSIGNED   NOT NULL,
    seq                BIGINT UNSIGNED NOT NULL,
    ts                 DATETIME(6)    NOT NULL,
    batch_pk           BIGINT UNSIGNED NOT NULL,
    family             VARCHAR(8)     NOT NULL,
    local_addr         VARCHAR(64)    NOT NULL,
    local_ip           VARBINARY(16)  NULL,
    local_port         SMALLINT UNSIGNED NOT NULL,
    remote_addr        VARCHAR(64)    NOT NULL,
    remote_ip          VARBINARY(16)  NULL,
    remote_port        SMALLINT UNSIGNED NOT NULL,
    state              VARCHAR(32)    NOT NULL,
    direction          VARCHAR(16)    NOT NULL,
    direction_source   VARCHAR(32)    NOT NULL COMMENT 'heuristic: a guess (D-001), MS-DAT-05',
    listener_pid_match BOOLEAN        NULL,
    pid                INT UNSIGNED   NOT NULL,
    process_name       VARCHAR(260)   NULL,
    exe                VARCHAR(1024)  NULL,
    `user`             VARCHAR(256)   NULL,
    user_source        VARCHAR(32)    NULL,
    process_start_time DATETIME(6)    NULL,
    services           JSON           NULL,
    attribution_error  VARCHAR(1024)  NULL,
    extra              JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_stc_host_ts (host_pk, ts),
    KEY ix_stc_remote (remote_ip, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE snap_listeners (
    agent_pk           INT UNSIGNED   NOT NULL,
    host_pk            INT UNSIGNED   NOT NULL,
    seq                BIGINT UNSIGNED NOT NULL,
    ts                 DATETIME(6)    NOT NULL,
    batch_pk           BIGINT UNSIGNED NOT NULL,
    family             VARCHAR(8)     NOT NULL,
    local_addr         VARCHAR(64)    NOT NULL,
    local_ip           VARBINARY(16)  NULL,
    local_port         SMALLINT UNSIGNED NOT NULL,
    pid                INT UNSIGNED   NOT NULL,
    process_name       VARCHAR(260)   NULL,
    exe                VARCHAR(1024)  NULL,
    `user`             VARCHAR(256)   NULL,
    user_source        VARCHAR(32)    NULL,
    process_start_time DATETIME(6)    NULL,
    services           JSON           NULL,
    attribution_error  VARCHAR(1024)  NULL,
    app                JSON           NULL COMMENT 'AG-INV-15 link (5b)',
    app_name           VARCHAR(255)   AS (JSON_VALUE(app, '$.name')) VIRTUAL,
    app_match_source   VARCHAR(32)    AS (JSON_VALUE(app, '$.match_source')) VIRTUAL COMMENT 'guessed or confirmed',
    extra              JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_sl_host_ts (host_pk, ts),
    KEY ix_sl_port (local_port, ts),
    KEY ix_sl_batch (batch_pk),
    KEY ix_sl_app (app_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE snap_udp_endpoints (
    agent_pk           INT UNSIGNED   NOT NULL,
    host_pk            INT UNSIGNED   NOT NULL,
    seq                BIGINT UNSIGNED NOT NULL,
    ts                 DATETIME(6)    NOT NULL,
    batch_pk           BIGINT UNSIGNED NOT NULL,
    family             VARCHAR(8)     NOT NULL,
    local_addr         VARCHAR(64)    NOT NULL,
    local_ip           VARBINARY(16)  NULL,
    local_port         SMALLINT UNSIGNED NOT NULL,
    pid                INT UNSIGNED   NOT NULL,
    process_name       VARCHAR(260)   NULL,
    exe                VARCHAR(1024)  NULL,
    `user`             VARCHAR(256)   NULL,
    user_source        VARCHAR(32)    NULL,
    process_start_time DATETIME(6)    NULL,
    services           JSON           NULL,
    attribution_error  VARCHAR(1024)  NULL,
    app                JSON           NULL COMMENT 'AG-INV-15 link (5b)',
    app_name           VARCHAR(255)   AS (JSON_VALUE(app, '$.name')) VIRTUAL,
    app_match_source   VARCHAR(32)    AS (JSON_VALUE(app, '$.match_source')) VIRTUAL,
    extra              JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_sue_host_ts (host_pk, ts),
    KEY ix_sue_port (local_port, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- ---------------------------------------------------------------------------
-- Flows (milestone 2): one row per flow per 10 s window (D-016). Combine rows
-- with the same flow_key and first_seen to get one connection (MS-DAT-04,
-- M-013).
-- ---------------------------------------------------------------------------

CREATE TABLE net_flows (
    agent_pk           INT UNSIGNED   NOT NULL,
    host_pk            INT UNSIGNED   NOT NULL,
    seq                BIGINT UNSIGNED NOT NULL,
    ts                 DATETIME(6)    NOT NULL,
    batch_pk           BIGINT UNSIGNED NOT NULL,
    proto              VARCHAR(8)     NOT NULL,
    family             VARCHAR(8)     NOT NULL,
    local_addr         VARCHAR(64)    NOT NULL,
    local_ip           VARBINARY(16)  NULL,
    local_port         SMALLINT UNSIGNED NOT NULL,
    remote_addr        VARCHAR(64)    NOT NULL,
    remote_ip          VARBINARY(16)  NULL,
    remote_port        SMALLINT UNSIGNED NOT NULL,
    direction          VARCHAR(16)    NOT NULL,
    direction_source   VARCHAR(32)    NULL COMMENT 'etw, etw_first_packet (D-014)',
    first_seen         DATETIME(6)    NULL,
    last_seen          DATETIME(6)    NULL,
    window_start       DATETIME(6)    NOT NULL,
    window_end         DATETIME(6)    NOT NULL,
    connects           BIGINT UNSIGNED NULL,
    accepts            BIGINT UNSIGNED NULL,
    closes             BIGINT UNSIGNED NULL,
    reconnects         BIGINT UNSIGNED NULL,
    packets_sent       BIGINT UNSIGNED NULL,
    packets_recv       BIGINT UNSIGNED NULL,
    bytes_sent         BIGINT UNSIGNED NULL,
    bytes_recv         BIGINT UNSIGNED NULL,
    closed             BOOLEAN        NULL,
    pid                INT UNSIGNED   NOT NULL,
    process_name       VARCHAR(260)   NULL,
    exe                VARCHAR(1024)  NULL,
    `user`             VARCHAR(256)   NULL,
    user_source        VARCHAR(32)    NULL COMMENT 'etw_event: from the event SID (D-012)',
    process_start_time DATETIME(6)    NULL,
    services           JSON           NULL,
    attribution_error  VARCHAR(1024)  NULL,
    -- D-016 key: protocol, PID, local and remote address and port.
    flow_key           BINARY(16)     AS (UNHEX(MD5(CONCAT_WS('|', proto, pid, local_addr, local_port, remote_addr, remote_port)))) STORED,
    extra              JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_nf_host_ts (host_pk, ts),
    KEY ix_nf_remote (remote_ip, ts),
    KEY ix_nf_local_port (host_pk, local_port, ts),
    KEY ix_nf_process (host_pk, process_name, ts),
    KEY ix_nf_flow (host_pk, flow_key, first_seen),
    KEY ix_nf_batch (batch_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- etw_status (plan: table agent_status). Counters the dashboard filters on are
-- columns; the rest stays in JSON with the agent's names.
CREATE TABLE agent_status (
    agent_pk           INT UNSIGNED   NOT NULL,
    host_pk            INT UNSIGNED   NOT NULL,
    seq                BIGINT UNSIGNED NOT NULL,
    ts                 DATETIME(6)    NOT NULL,
    batch_pk           BIGINT UNSIGNED NOT NULL,
    event              VARCHAR(32)    NOT NULL COMMENT 'started, stopped, start_failed, periodic, final',
    error              VARCHAR(1024)  NULL,
    events             BIGINT UNSIGNED NULL,
    failed_connects    BIGINT UNSIGNED NULL,
    loopback_skipped   BIGINT UNSIGNED NULL,
    flows_active       INT UNSIGNED   NULL,
    flows_dropped      BIGINT UNSIGNED NULL,
    seen_evicted       BIGINT UNSIGNED NULL,
    seen_tracked       INT UNSIGNED   NULL,
    decode_errors      BIGINT UNSIGNED NULL,
    agent_dropped      BIGINT UNSIGNED NULL,
    handler_panics     BIGINT UNSIGNED NULL,
    processes_tracked  INT UNSIGNED   NULL,
    event_sid_checked  BIGINT UNSIGNED NULL,
    event_sid_mismatch BIGINT UNSIGNED NULL,
    tcp_bytes          BOOLEAN        NULL,
    go_heap_bytes      BIGINT UNSIGNED NULL,
    go_mem             JSON           NULL,
    working_set_bytes  BIGINT UNSIGNED NULL,
    working_set_trims  BIGINT UNSIGNED NULL,
    priority_class     VARCHAR(32)    NULL COMMENT 'milestone 5',
    priority_boosts    BIGINT UNSIGNED NULL COMMENT 'milestone 5',
    processes          JSON           NULL COMMENT 'proc.ProcStats',
    hash_access_denied_needs_backup_privilege BIGINT UNSIGNED
        AS (JSON_VALUE(processes, '$.hash_access_denied_needs_backup_privilege')) VIRTUAL COMMENT 'milestone 5, D-056',
    extra              JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_as_host_ts (host_pk, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- coverage_gap: kept 2 years, not partitioned. The agent writes a
-- session_stopped gap when it opens and again when it closes (D-017); both
-- land on one row, keyed by agent, reason and start (M-015).
CREATE TABLE coverage_gaps (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agent_pk      INT UNSIGNED   NOT NULL,
    host_pk       INT UNSIGNED   NOT NULL,
    reason        VARCHAR(64)    NOT NULL COMMENT 'session_stopped, events_lost, agent_queue_full, not_elevated, flow_table_full',
    gap_start     DATETIME(6)    NOT NULL COMMENT 'data.start',
    gap_end       DATETIME(6)    NULL     COMMENT 'data.end; NULL while open',
    events_lost   BIGINT UNSIGNED NULL,
    buffers_lost  BIGINT UNSIGNED NULL,
    agent_dropped BIGINT UNSIGNED NULL,
    flows_dropped BIGINT UNSIGNED NULL,
    error         VARCHAR(1024)  NULL,
    first_seq     BIGINT UNSIGNED NOT NULL,
    last_seq      BIGINT UNSIGNED NOT NULL,
    first_ts      DATETIME(6)    NOT NULL,
    last_ts       DATETIME(6)    NOT NULL,
    batch_pk      BIGINT UNSIGNED NOT NULL COMMENT 'last batch that touched it',
    extra         JSON           NULL,
    UNIQUE KEY uq_gaps_natural (agent_pk, reason, gap_start),
    KEY ix_gaps_host_time (host_pk, gap_start, gap_end),
    KEY ix_gaps_batch (batch_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Processes and executables (milestone 3)
-- ---------------------------------------------------------------------------

-- process_start, process_stop, process_snapshot. The command line itself is
-- in process_command_lines (M-011); the flags stay here after it's cleared.
CREATE TABLE process_events (
    agent_pk                 INT UNSIGNED   NOT NULL,
    host_pk                  INT UNSIGNED   NOT NULL,
    seq                      BIGINT UNSIGNED NOT NULL,
    ts                       DATETIME(6)    NOT NULL,
    batch_pk                 BIGINT UNSIGNED NOT NULL,
    record_type              ENUM('process_start','process_stop','process_snapshot') NOT NULL,
    pid                      INT UNSIGNED   NOT NULL,
    parent_pid               INT UNSIGNED   NULL,
    parent_start_time        DATETIME(6)    NULL,
    session_id               INT UNSIGNED   NULL,
    start_time               DATETIME(6)    NULL,
    exit_time                DATETIME(6)    NULL,
    exit_code                INT UNSIGNED   NULL,
    exe                      VARCHAR(1024)  NULL,
    process_name             VARCHAR(260)   NULL,
    exe_size                 BIGINT         NULL,
    exe_mod_time             DATETIME(6)    NULL,
    exe_sha256               CHAR(64)       NULL,
    exe_error                VARCHAR(1024)  NULL,
    has_command_line         BOOLEAN        NOT NULL DEFAULT FALSE COMMENT 'a row exists in process_command_lines (until cleared)',
    command_line_length      INT UNSIGNED   NULL,
    command_line_truncated   BOOLEAN        NULL,
    command_line_redacted    BOOLEAN        NULL COMMENT 'shown beside the command line (MS-SEC-09)',
    command_line_unavailable VARCHAR(32)    NULL COMMENT 'process_exited, access_denied, none, error (D-029)',
    `user`                   VARCHAR(256)   NULL,
    user_source              VARCHAR(32)    NULL,
    attribution_error        VARCHAR(1024)  NULL,
    extra                    JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_pe_host_ts (host_pk, ts),
    KEY ix_pe_host_pid (host_pk, pid, start_time),
    KEY ix_pe_parent (host_pk, parent_pid, parent_start_time),
    KEY ix_pe_name (process_name, ts),
    KEY ix_pe_user (`user`, ts),
    KEY ix_pe_sha (exe_sha256),
    KEY ix_pe_batch (batch_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- MS-SEC-07..09: command lines apart, so they are cleared after 30 days
-- (M-017) by dropping whole partitions, and only analyst queries touch them.
CREATE TABLE process_command_lines (
    agent_pk      INT UNSIGNED   NOT NULL,
    host_pk       INT UNSIGNED   NOT NULL,
    seq           BIGINT UNSIGNED NOT NULL,
    ts            DATETIME(6)    NOT NULL,
    command_line  TEXT           NOT NULL COMMENT 'redacted and cut at 8 KB by the agent',
    PRIMARY KEY (agent_pk, seq, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- executable: one row per host and file version (path, size, mod_time),
-- updated in place by later records (M-014). Not partitioned; the retention
-- job removes rows not seen for 90 days.
CREATE TABLE executables (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    host_pk              INT UNSIGNED   NOT NULL,
    agent_pk             INT UNSIGNED   NOT NULL COMMENT 'agent of the latest record',
    path                 VARCHAR(1024)  NOT NULL,
    path_hash            BINARY(32)     AS (UNHEX(SHA2(LOWER(path), 256))) STORED,
    size                 BIGINT         NOT NULL,
    mod_time             DATETIME(6)    NOT NULL,
    sha256               CHAR(64)       NULL,
    hash_status          VARCHAR(32)    NOT NULL COMMENT 'ok, skipped_too_large, access_denied, error, ... (MS-DAT-05)',
    access_denied_reason VARCHAR(32)    NULL COMMENT 'milestone 5, D-056',
    would_need_privilege VARCHAR(64)    NULL COMMENT 'milestone 5, D-056',
    error                VARCHAR(1024)  NULL,
    signature            JSON           NULL,
    sig_status           VARCHAR(64)    AS (JSON_VALUE(signature, '$.status')) VIRTUAL,
    sig_type             VARCHAR(16)    AS (JSON_VALUE(signature, '$.type')) VIRTUAL,
    sig_signer           VARCHAR(255)   AS (JSON_VALUE(signature, '$.signer')) VIRTUAL,
    sig_thumbprint       CHAR(40)       AS (JSON_VALUE(signature, '$.thumbprint')) VIRTUAL COMMENT 'D-037, for MS-ENR-06',
    version_info         JSON           NULL,
    product_name         VARCHAR(255)   AS (JSON_VALUE(version_info, '$.product_name')) VIRTUAL,
    company_name         VARCHAR(255)   AS (JSON_VALUE(version_info, '$.company_name')) VIRTUAL,
    version_info_error   VARCHAR(1024)  NULL,
    elapsed_ms           BIGINT         NULL,
    first_seen           DATETIME(6)    NOT NULL,
    last_seen            DATETIME(6)    NOT NULL,
    first_seq            BIGINT UNSIGNED NOT NULL,
    last_seq             BIGINT UNSIGNED NOT NULL,
    batch_pk             BIGINT UNSIGNED NOT NULL COMMENT 'last batch that touched it',
    extra                JSON           NULL,
    UNIQUE KEY uq_exe_version (host_pk, path_hash, size, mod_time),
    KEY ix_exe_sha (sha256),
    KEY ix_exe_sig (sig_status),
    KEY ix_exe_signer (sig_signer),
    KEY ix_exe_thumb (sig_thumbprint),
    KEY ix_exe_company (company_name),
    KEY ix_exe_last_seen (last_seen),
    KEY ix_exe_batch (batch_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Agent lifecycle (milestone 5): agent_start, agent_stop. 2 years.
-- ---------------------------------------------------------------------------

CREATE TABLE agent_lifecycle (
    agent_pk             INT UNSIGNED   NOT NULL,
    host_pk              INT UNSIGNED   NOT NULL,
    seq                  BIGINT UNSIGNED NOT NULL,
    ts                   DATETIME(6)    NOT NULL,
    batch_pk             BIGINT UNSIGNED NOT NULL,
    record_type          ENUM('agent_start','agent_stop') NOT NULL,
    -- agent_start
    mode                 VARCHAR(16)    NULL,
    service_name         VARCHAR(256)   NULL,
    pid                  INT UNSIGNED   NULL,
    previous_exit        VARCHAR(16)    NULL COMMENT 'first_start, clean, error, crash (MS-ALR-08)',
    previous_start       DATETIME(6)    NULL,
    previous_pid         INT UNSIGNED   NULL,
    previous_version     VARCHAR(64)    NULL,
    previous_error       VARCHAR(2048)  NULL,
    crash_text           TEXT           NULL,
    restarts_since_clean INT UNSIGNED   NULL,
    data_dir             VARCHAR(1024)  NULL,
    data_dir_report      JSON           NULL,
    priority_class       VARCHAR(32)    NULL,
    priority_error       VARCHAR(1024)  NULL,
    privileges           JSON           NULL,
    -- agent_stop
    reason               VARCHAR(32)    NULL,
    uptime_s             DOUBLE         NULL,
    extra                JSON           NULL,
    PRIMARY KEY (agent_pk, seq),
    KEY ix_al_host_ts (host_pk, ts),
    KEY ix_al_exit (previous_exit, ts),
    KEY ix_al_batch (batch_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Inventory (milestone 4) and server software (milestone 5b).
--
-- Each inv_* table keeps every item record received (history, partitioned,
-- 90 days). `item_key` is the agent's change-detection key: from the record
-- once the agent sends it (M-012), otherwise derived by the mothership with
-- the agent's rules (key_source = 'derived', M-012). `snapshot_id` is the
-- inventory_snapshot the record belongs to, when known.
-- inventory_current holds the latest state per host and item (kept by a
-- background job, not by ingest).
-- ---------------------------------------------------------------------------

CREATE TABLE inventory_snapshots (
    agent_pk       INT UNSIGNED   NOT NULL,
    host_pk        INT UNSIGNED   NOT NULL,
    seq            BIGINT UNSIGNED NOT NULL,
    ts             DATETIME(6)    NOT NULL,
    batch_pk       BIGINT UNSIGNED NOT NULL,
    snapshot_id    VARCHAR(64)    NOT NULL,
    event          VARCHAR(16)    NOT NULL COMMENT 'start, end',
    reason         VARCHAR(32)    NOT NULL COMMENT 'startup, scheduled',
    sources        JSON           NULL COMMENT 'per kind: items, error, partial, duration_ms',
    users_covered  JSON           NULL COMMENT 'D-043',
    duration_ms    BIGINT         NULL,
    extra          JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_is_host_ts (host_pk, ts),
    KEY ix_is_snapshot (agent_pk, snapshot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- inventory_change: 2 years (M-017). `key` and `change` are reserved words.
CREATE TABLE inventory_changes (
    agent_pk       INT UNSIGNED   NOT NULL,
    host_pk        INT UNSIGNED   NOT NULL,
    seq            BIGINT UNSIGNED NOT NULL,
    ts             DATETIME(6)    NOT NULL,
    batch_pk       BIGINT UNSIGNED NOT NULL,
    kind           VARCHAR(64)    NOT NULL COMMENT 'the item record type, e.g. inv_service, inv_iis_app_pool',
    `key`          VARCHAR(1024)  NOT NULL,
    `change`       VARCHAR(16)    NOT NULL COMMENT 'added, removed, modified',
    reason         VARCHAR(32)    NULL COMMENT 'signal, rescan, scheduled, link_index',
    `before`       JSON           NULL,
    `after`        JSON           NULL,
    item_name      VARCHAR(255)   AS (COALESCE(JSON_VALUE(`after`, '$.name'), JSON_VALUE(`before`, '$.name'),
                                               JSON_VALUE(`after`, '$.path'), JSON_VALUE(`before`, '$.path'),
                                               JSON_VALUE(`after`, '$.product'), JSON_VALUE(`before`, '$.product'),
                                               JSON_VALUE(`after`, '$.instance_name'), JSON_VALUE(`before`, '$.instance_name'))) VIRTUAL,
    extra          JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_ic_host_ts (host_pk, ts),
    KEY ix_ic_kind (kind, ts),
    KEY ix_ic_name (item_name),
    KEY ix_ic_batch (batch_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- inventory_link_index (5b): one row per link-index build. Small; M-016.
CREATE TABLE inventory_link_index (
    agent_pk          INT UNSIGNED   NOT NULL,
    host_pk           INT UNSIGNED   NOT NULL,
    seq               BIGINT UNSIGNED NOT NULL,
    ts                DATETIME(6)    NOT NULL,
    batch_pk          BIGINT UNSIGNED NOT NULL,
    reason            VARCHAR(32)    NOT NULL COMMENT 'after_start_hold, scheduled',
    duration_ms       BIGINT         NULL,
    component_keys    INT UNSIGNED   NULL,
    values_scanned    INT UNSIGNED   NULL,
    paths_wanted      INT UNSIGNED   NULL,
    paths_matched     INT UNSIGNED   NULL,
    products          INT UNSIGNED   NULL,
    alloc_bytes       BIGINT UNSIGNED NULL,
    heap_growth_bytes BIGINT UNSIGNED NULL,
    error             VARCHAR(1024)  NULL,
    extra             JSON           NULL,
    PRIMARY KEY (agent_pk, seq),
    KEY ix_ili_host_ts (host_pk, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inv_apps (
    agent_pk          INT UNSIGNED   NOT NULL,
    host_pk           INT UNSIGNED   NOT NULL,
    seq               BIGINT UNSIGNED NOT NULL,
    ts                DATETIME(6)    NOT NULL,
    batch_pk          BIGINT UNSIGNED NOT NULL,
    item_key          VARCHAR(1024)  NOT NULL,
    key_source        ENUM('agent','derived') NOT NULL,
    snapshot_id       VARCHAR(64)    NULL,
    source            VARCHAR(16)    NOT NULL COMMENT 'uninstall, msix',
    name              VARCHAR(512)   NOT NULL,
    version           VARCHAR(128)   NULL,
    publisher         VARCHAR(512)   NULL,
    install_date      VARCHAR(32)    NULL COMMENT 'as stored, usually YYYYMMDD',
    install_location  VARCHAR(1024)  NULL,
    uninstall_string  VARCHAR(2048)  NULL,
    scope             VARCHAR(16)    NULL COMMENT 'machine, user',
    `user`            VARCHAR(256)   NULL COMMENT 'SID for user scope',
    arch              VARCHAR(16)    NULL,
    reg_key           VARCHAR(1024)  NULL,
    system_component  BOOLEAN        NULL,
    windows_installer BOOLEAN        NULL,
    package_full_name VARCHAR(512)   NULL,
    package_type      VARCHAR(16)    NULL,
    publisher_id      VARCHAR(64)    NULL,
    extra             JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_ia_host_ts (host_pk, ts),
    KEY ix_ia_name (name(191), version),
    KEY ix_ia_publisher (publisher(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE inv_services (
    agent_pk          INT UNSIGNED   NOT NULL,
    host_pk           INT UNSIGNED   NOT NULL,
    seq               BIGINT UNSIGNED NOT NULL,
    ts                DATETIME(6)    NOT NULL,
    batch_pk          BIGINT UNSIGNED NOT NULL,
    item_key          VARCHAR(1024)  NOT NULL,
    key_source        ENUM('agent','derived') NOT NULL,
    snapshot_id       VARCHAR(64)    NULL,
    name              VARCHAR(256)   NOT NULL,
    display_name      VARCHAR(512)   NULL,
    description       VARCHAR(2048)  NULL,
    binary_path       VARCHAR(2048)  NULL,
    exe               JSON           NULL COMMENT 'file reference: path, size, mod_time, error',
    service_dll       JSON           NULL,
    account           VARCHAR(256)   NULL,
    start_type        VARCHAR(16)    NULL,
    delayed_start     BOOLEAN        NULL,
    trigger_start     BOOLEAN        NULL,
    type              VARCHAR(64)    NULL,
    state             VARCHAR(32)    NULL,
    pid               INT UNSIGNED   NULL,
    config_error      VARCHAR(1024)  NULL,
    config_source     VARCHAR(16)    NULL,
    app               JSON           NULL COMMENT 'AG-INV-15 link (5b)',
    app_name          VARCHAR(255)   AS (JSON_VALUE(app, '$.name')) VIRTUAL,
    app_match_source  VARCHAR(32)    AS (JSON_VALUE(app, '$.match_source')) VIRTUAL,
    extra             JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_isv_host_ts (host_pk, ts),
    KEY ix_isv_name (name, ts),
    KEY ix_isv_account (account(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE inv_tasks (
    agent_pk          INT UNSIGNED   NOT NULL,
    host_pk           INT UNSIGNED   NOT NULL,
    seq               BIGINT UNSIGNED NOT NULL,
    ts                DATETIME(6)    NOT NULL,
    batch_pk          BIGINT UNSIGNED NOT NULL,
    item_key          VARCHAR(1024)  NOT NULL,
    key_source        ENUM('agent','derived') NOT NULL,
    snapshot_id       VARCHAR(64)    NULL,
    path              VARCHAR(1024)  NOT NULL,
    author            VARCHAR(512)   NULL,
    description       VARCHAR(2048)  NULL,
    enabled           BOOLEAN        NULL,
    hidden            BOOLEAN        NULL,
    run_as            VARCHAR(256)   NULL,
    run_level         VARCHAR(32)    NULL,
    logon_type        VARCHAR(32)    NULL,
    actions           JSON           NULL,
    triggers          JSON           NULL,
    has_file          BOOLEAN        NULL,
    in_task_cache     BOOLEAN        NULL,
    no_security_descriptor BOOLEAN   NULL COMMENT 'possible hidden task (D-042)',
    parse_error       VARCHAR(1024)  NULL,
    extra             JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_it_host_ts (host_pk, ts),
    KEY ix_it_path (path(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE inv_startup (
    agent_pk          INT UNSIGNED   NOT NULL,
    host_pk           INT UNSIGNED   NOT NULL,
    seq               BIGINT UNSIGNED NOT NULL,
    ts                DATETIME(6)    NOT NULL,
    batch_pk          BIGINT UNSIGNED NOT NULL,
    item_key          VARCHAR(1024)  NOT NULL,
    key_source        ENUM('agent','derived') NOT NULL,
    snapshot_id       VARCHAR(64)    NULL,
    location          VARCHAR(1024)  NOT NULL,
    name              VARCHAR(512)   NOT NULL,
    command           VARCHAR(4096)  NULL,
    shortcut          JSON           NULL,
    exe               JSON           NULL,
    enabled           BOOLEAN        NULL,
    `user`            VARCHAR(256)   NULL,
    extra             JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_ist_host_ts (host_pk, ts),
    KEY ix_ist_name (name(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE inv_drivers (
    agent_pk          INT UNSIGNED   NOT NULL,
    host_pk           INT UNSIGNED   NOT NULL,
    seq               BIGINT UNSIGNED NOT NULL,
    ts                DATETIME(6)    NOT NULL,
    batch_pk          BIGINT UNSIGNED NOT NULL,
    item_key          VARCHAR(1024)  NOT NULL,
    key_source        ENUM('agent','derived') NOT NULL,
    snapshot_id       VARCHAR(64)    NULL,
    name              VARCHAR(256)   NULL COMMENT 'empty for a loaded image with no service',
    display_name      VARCHAR(512)   NULL,
    image_path        VARCHAR(2048)  NULL,
    file              JSON           NULL,
    start_type        VARCHAR(16)    NULL,
    type              VARCHAR(32)    NULL,
    state             VARCHAR(32)    NULL,
    loaded            BOOLEAN        NULL,
    config_error      VARCHAR(1024)  NULL,
    config_source     VARCHAR(16)    NULL,
    extra             JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_idr_host_ts (host_pk, ts),
    KEY ix_idr_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE inv_roles (
    agent_pk          INT UNSIGNED   NOT NULL,
    host_pk           INT UNSIGNED   NOT NULL,
    seq               BIGINT UNSIGNED NOT NULL,
    ts                DATETIME(6)    NOT NULL,
    batch_pk          BIGINT UNSIGNED NOT NULL,
    item_key          VARCHAR(1024)  NOT NULL,
    key_source        ENUM('agent','derived') NOT NULL,
    snapshot_id       VARCHAR(64)    NULL,
    name              VARCHAR(256)   NOT NULL,
    display_name      VARCHAR(512)   NULL,
    install_state     BIGINT UNSIGNED NULL,
    installed         BOOLEAN        NULL,
    parent            VARCHAR(256)   NULL,
    extra             JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_ir_host_ts (host_pk, ts),
    KEY ix_ir_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE host_facts (
    agent_pk             INT UNSIGNED   NOT NULL,
    host_pk              INT UNSIGNED   NOT NULL,
    seq                  BIGINT UNSIGNED NOT NULL,
    ts                   DATETIME(6)    NOT NULL,
    batch_pk             BIGINT UNSIGNED NOT NULL,
    item_key             VARCHAR(1024)  NOT NULL,
    key_source           ENUM('agent','derived') NOT NULL,
    snapshot_id          VARCHAR(64)    NULL,
    hostname             VARCHAR(255)   NOT NULL,
    dns_hostname         VARCHAR(255)   NULL,
    dns_domain           VARCHAR(255)   NULL,
    join_name            VARCHAR(255)   NULL,
    join_type            VARCHAR(16)    NULL,
    os_product_name      VARCHAR(255)   NULL,
    os_edition           VARCHAR(128)   NULL,
    os_display_version   VARCHAR(32)    NULL,
    os_version           VARCHAR(64)    NULL,
    os_installation_type VARCHAR(64)    NULL,
    os_product_type      VARCHAR(32)    NULL,
    os_install_date      DATETIME(6)    NULL,
    last_boot            DATETIME(6)    NULL,
    time_zone            VARCHAR(128)   NULL,
    manufacturer         VARCHAR(255)   NULL,
    model                VARCHAR(255)   NULL,
    bios_version         VARCHAR(255)   NULL,
    logical_cpus         INT UNSIGNED   NULL,
    memory_bytes         BIGINT UNSIGNED NULL,
    installed_updates    JSON           NULL,
    interfaces           JSON           NULL,
    extra                JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_hf_host_ts (host_pk, ts),
    KEY ix_hf_os (os_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- inv_iis: the read status is a column and is always shown (MS-UI-02), so a
-- host whose IIS config couldn't be read never looks like "no sites".
CREATE TABLE inv_iis (
    agent_pk          INT UNSIGNED   NOT NULL,
    host_pk           INT UNSIGNED   NOT NULL,
    seq               BIGINT UNSIGNED NOT NULL,
    ts                DATETIME(6)    NOT NULL,
    batch_pk          BIGINT UNSIGNED NOT NULL,
    item_key          VARCHAR(1024)  NOT NULL,
    key_source        ENUM('agent','derived') NOT NULL,
    snapshot_id       VARCHAR(64)    NULL,
    installed         BOOLEAN        NOT NULL,
    version           VARCHAR(32)    NULL,
    config_path       VARCHAR(1024)  NULL,
    config_mod_time   DATETIME(6)    NULL,
    shared_config     JSON           NULL,
    `read`            VARCHAR(32)    NOT NULL COMMENT 'ok, not_installed, not_found, shared_config_network, shared_config_not_local, too_large, parse_error, access_denied, error',
    parse_error       JSON           NULL COMMENT 'file, line, element only',
    sites             INT UNSIGNED   NULL,
    app_pools         INT UNSIGNED   NULL,
    extra             JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_iis_host_ts (host_pk, ts),
    KEY ix_iis_read (`read`, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE inv_iis_sites (
    agent_pk               INT UNSIGNED   NOT NULL,
    host_pk                INT UNSIGNED   NOT NULL,
    seq                    BIGINT UNSIGNED NOT NULL,
    ts                     DATETIME(6)    NOT NULL,
    batch_pk               BIGINT UNSIGNED NOT NULL,
    item_key               VARCHAR(1024)  NOT NULL,
    key_source             ENUM('agent','derived') NOT NULL,
    snapshot_id            VARCHAR(64)    NULL,
    name                   VARCHAR(512)   NOT NULL,
    id                     INT UNSIGNED   NOT NULL COMMENT 'IIS site ID',
    auto_start             BOOLEAN        NULL,
    bindings               JSON           NULL,
    physical_path          VARCHAR(1024)  NULL,
    physical_path_expanded VARCHAR(1024)  NULL,
    app_pool               VARCHAR(256)   NULL,
    applications           JSON           NULL,
    extra                  JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_iiss_host_ts (host_pk, ts),
    KEY ix_iiss_name (name(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- Binding ports, one row per site binding, filled by the inventory job from
-- inv_iis_sites.bindings, so "which site is on port 443" is an index lookup.
CREATE TABLE iis_binding_ports (
    host_pk      INT UNSIGNED   NOT NULL,
    site_id      INT UNSIGNED   NOT NULL,
    protocol     VARCHAR(32)    NOT NULL,
    ip           VARCHAR(64)    NOT NULL DEFAULT '',
    port         SMALLINT UNSIGNED NOT NULL,
    host_header  VARCHAR(255)   NOT NULL DEFAULT '',
    site_name    VARCHAR(512)   NOT NULL,
    updated_at   DATETIME(6)    NOT NULL,
    PRIMARY KEY (host_pk, site_id, protocol, ip, port, host_header),
    KEY ix_ibp_port (port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inv_iis_app_pools (
    agent_pk                INT UNSIGNED   NOT NULL,
    host_pk                 INT UNSIGNED   NOT NULL,
    seq                     BIGINT UNSIGNED NOT NULL,
    ts                      DATETIME(6)    NOT NULL,
    batch_pk                BIGINT UNSIGNED NOT NULL,
    item_key                VARCHAR(1024)  NOT NULL,
    key_source              ENUM('agent','derived') NOT NULL,
    snapshot_id             VARCHAR(64)    NULL,
    name                    VARCHAR(256)   NOT NULL,
    managed_runtime_version VARCHAR(16)    NULL,
    pipeline_mode           VARCHAR(16)    NULL,
    identity_type           VARCHAR(32)    NULL,
    account                 VARCHAR(256)   NULL,
    start_mode              VARCHAR(16)    NULL,
    auto_start              BOOLEAN        NULL,
    enable_32bit            BOOLEAN        NULL,
    iis_version             VARCHAR(32)    NULL,
    app                     JSON           NULL COMMENT 'AG-INV-15 link; always install_location (D-070)',
    app_name                VARCHAR(255)   AS (JSON_VALUE(app, '$.name')) VIRTUAL,
    app_match_source        VARCHAR(32)    AS (JSON_VALUE(app, '$.match_source')) VIRTUAL,
    extra                   JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_iisp_host_ts (host_pk, ts),
    KEY ix_iisp_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE inv_sql_instances (
    agent_pk          INT UNSIGNED   NOT NULL,
    host_pk           INT UNSIGNED   NOT NULL,
    seq               BIGINT UNSIGNED NOT NULL,
    ts                DATETIME(6)    NOT NULL,
    batch_pk          BIGINT UNSIGNED NOT NULL,
    item_key          VARCHAR(1024)  NOT NULL,
    key_source        ENUM('agent','derived') NOT NULL,
    snapshot_id       VARCHAR(64)    NULL,
    instance_name     VARCHAR(128)   NOT NULL,
    instance_id       VARCHAR(128)   NULL,
    edition           VARCHAR(128)   NULL,
    version           VARCHAR(32)    NULL,
    patch_level       VARCHAR(32)    NULL COMMENT 'build number; named via sql_server_builds (MS-ENR-07)',
    service_name      VARCHAR(256)   NULL,
    service_account   VARCHAR(256)   NULL,
    start_type        VARCHAR(16)    NULL,
    tcp_enabled       BOOLEAN        NULL,
    tcp_ports         JSON           NULL,
    tcp_dynamic_ports JSON           NULL,
    install_path      VARCHAR(1024)  NULL,
    registry_view     VARCHAR(4)     NULL,
    exe               JSON           NULL,
    app               JSON           NULL,
    app_name          VARCHAR(255)   AS (JSON_VALUE(app, '$.name')) VIRTUAL,
    app_match_source  VARCHAR(32)    AS (JSON_VALUE(app, '$.match_source')) VIRTUAL,
    extra             JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_isql_host_ts (host_pk, ts),
    KEY ix_isql_patch (patch_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE inv_server_apps (
    agent_pk          INT UNSIGNED   NOT NULL,
    host_pk           INT UNSIGNED   NOT NULL,
    seq               BIGINT UNSIGNED NOT NULL,
    ts                DATETIME(6)    NOT NULL,
    batch_pk          BIGINT UNSIGNED NOT NULL,
    item_key          VARCHAR(1024)  NOT NULL,
    key_source        ENUM('agent','derived') NOT NULL,
    snapshot_id       VARCHAR(64)    NULL,
    product           VARCHAR(128)   NOT NULL,
    version           VARCHAR(128)   NULL,
    version_source    VARCHAR(16)    NULL COMMENT 'registry, exe_version',
    detected_by       VARCHAR(16)    NULL COMMENT 'service, registry',
    service_name      VARCHAR(256)   NULL,
    registry_key      VARCHAR(1024)  NULL,
    exe               JSON           NULL,
    install_path      VARCHAR(1024)  NULL,
    list_source       VARCHAR(16)    NULL COMMENT 'builtin, mothership',
    extra             JSON           NULL,
    PRIMARY KEY (agent_pk, seq, ts),
    KEY ix_isa_host_ts (host_pk, ts),
    KEY ix_isa_product (product, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01 00:00:00'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- Current inventory per host and item, kept by the inventory job from the
-- inv_* tables, inventory_snapshot end markers and inventory_change records.
-- Kept for as long as the host exists.
CREATE TABLE inventory_current (
    host_pk        INT UNSIGNED   NOT NULL,
    kind           VARCHAR(64)    NOT NULL COMMENT 'the item record type',
    item_key_hash  BINARY(32)     NOT NULL COMMENT 'SHA-256 of item_key',
    item_key       VARCHAR(1024)  NOT NULL,
    name           VARCHAR(512)   NULL,
    version        VARCHAR(128)   NULL,
    publisher      VARCHAR(512)   NULL COMMENT 'publisher, company or product, by kind',
    app_name       VARCHAR(255)   NULL,
    app_match_source VARCHAR(32)  NULL,
    data           JSON           NOT NULL COMMENT 'the latest record data',
    first_seen     DATETIME(6)    NOT NULL,
    last_seen      DATETIME(6)    NOT NULL,
    removed_at     DATETIME(6)    NULL,
    last_agent_pk  INT UNSIGNED   NOT NULL,
    last_seq       BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (host_pk, kind, item_key_hash),
    KEY ix_ic_kind_name (kind, name(191)),
    KEY ix_ic_app (app_name),
    KEY ix_ic_removed (removed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Enrichment (MS-ENR)
-- ---------------------------------------------------------------------------

CREATE TABLE ip_enrichment (
    ip               VARBINARY(16)  NOT NULL PRIMARY KEY,
    ip_text          VARCHAR(64)    NOT NULL,
    scope            ENUM('internal','public') NOT NULL COMMENT 'MS-ENR-04: internal never sent to outside lookups',
    rdns_name        VARCHAR(255)   NULL,
    rdns_status      VARCHAR(16)    NULL COMMENT 'ok, nxdomain, timeout, error',
    rdns_checked_at  DATETIME(6)    NULL,
    -- Country and ASN stay NULL: no MaxMind account, MS-ENR-02 not built (M-027).
    -- The columns are kept so it can be added later without a migration.
    country          CHAR(2)        NULL,
    asn              INT UNSIGNED   NULL,
    as_org           VARCHAR(255)   NULL,
    geo_checked_at   DATETIME(6)    NULL,
    geo_db_version   VARCHAR(32)    NULL,
    listed           BOOLEAN        NOT NULL DEFAULT FALSE COMMENT 'on any threat list now',
    first_seen       DATETIME(6)    NOT NULL,
    last_seen        DATETIME(6)    NOT NULL,
    KEY ix_ipe_rdns_due (rdns_checked_at),
    KEY ix_ipe_country (country),
    KEY ix_ipe_asn (asn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE threat_lists (
    id               INT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(100)   NOT NULL,
    url              VARCHAR(500)   NOT NULL,
    enabled          BOOLEAN        NOT NULL DEFAULT TRUE,
    refresh_minutes  INT UNSIGNED   NOT NULL DEFAULT 240,
    min_refresh_minutes INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'the provider''s floor; the job never fetches more often',
    terms_url        VARCHAR(500)   NULL,
    terms_summary    VARCHAR(1000)  NULL COMMENT 'M-027: the list''s terms as checked, with the date',
    attribution      VARCHAR(255)   NULL COMMENT 'credit to show where the list is used, if required',
    last_fetch_at    DATETIME(6)    NULL,
    last_fetch_status VARCHAR(64)   NULL,
    entries          INT UNSIGNED   NULL,
    dropped_internal INT UNSIGNED   NULL COMMENT 'entries skipped at import because they overlap internal or reserved ranges (MS-ENR-04, M-027)',
    UNIQUE KEY uq_threat_lists_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MS-ENR-03, M-027: the three agreed lists, terms checked 2026-11-13.
-- Every entry overlapping a private, loopback, link-local, CGNAT, multicast or
-- other reserved range is skipped at import (firehol_level1 includes
-- fullbogons, so it lists 10/8, 172.16/12 and 192.168/16).
INSERT INTO threat_lists (name, url, enabled, refresh_minutes, min_refresh_minutes, terms_url, terms_summary, attribution) VALUES
 ('abuse.ch Feodo Tracker',
  'https://feodotracker.abuse.ch/downloads/ipblocklist_recommended.txt', TRUE, 15, 5,
  'https://abuse.ch/terms-of-use/',
  'CC0, commercial and non-commercial use, no attribution required, no export limits for Feodo Tracker (terms s. 3.4); data as is, no liability; no AI training without consent (s. 7.3). Checked 2026-11-13.',
  NULL),
 ('Spamhaus DROP v4',
  'https://www.spamhaus.org/drop/drop_v4.json', TRUE, 1440, 60,
  'https://www.spamhaus.org/blocklists/do-not-route-or-peer/',
  'Free for any use. Credit Spamhaus Project and keep the date and copyright with the data. Never fetch more than once an hour (else the IP may be firewalled); daily is enough. EDROP merged into DROP on 2024-04-10. Checked 2026-11-13.',
  'Spamhaus DROP list, (c) The Spamhaus Project'),
 ('Spamhaus DROP v6',
  'https://www.spamhaus.org/drop/drop_v6.json', TRUE, 1440, 60,
  'https://www.spamhaus.org/blocklists/do-not-route-or-peer/',
  'As Spamhaus DROP v4. Checked 2026-11-13.',
  'Spamhaus DROP list, (c) The Spamhaus Project'),
 ('FireHOL Level 1',
  'https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/firehol_level1.netset', TRUE, 240, 60,
  'https://iplists.firehol.org/',
  'FireHOL tooling is GPL v2; "IP lists are a property of their maintainers". Aggregates dshield, feodo, fullbogons and spamhaus_drop; each source''s own terms apply (DShield''s terms not yet checked, MPD-31). Contains private ranges: filtered at import. Checked 2026-11-13.',
  NULL);

-- Entries are CIDR ranges, stored as start and end addresses of one length.
CREATE TABLE threat_list_entries (
    list_id      INT UNSIGNED   NOT NULL,
    range_start  VARBINARY(16)  NOT NULL,
    range_end    VARBINARY(16)  NOT NULL,
    cidr         VARCHAR(64)    NOT NULL,
    listed_at    DATETIME(6)    NULL COMMENT 'date the list gives, else first fetch',
    first_fetched DATETIME(6)   NOT NULL,
    last_fetched DATETIME(6)    NOT NULL,
    PRIMARY KEY (list_id, range_start, range_end),
    KEY ix_tle_start (range_start, range_end),
    CONSTRAINT fk_tle_list FOREIGN KEY (list_id) REFERENCES threat_lists (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MS-ENR-03: a match keeps the list name and the listing date.
CREATE TABLE ip_reputation (
    ip           VARBINARY(16)  NOT NULL,
    list_id      INT UNSIGNED   NOT NULL,
    cidr         VARCHAR(64)    NOT NULL,
    listed_at    DATETIME(6)    NULL,
    matched_at   DATETIME(6)    NOT NULL,
    delisted_at  DATETIME(6)    NULL,
    PRIMARY KEY (ip, list_id, cidr),
    CONSTRAINT fk_iprep_list FOREIGN KEY (list_id) REFERENCES threat_lists (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MS-ENR-06: revocation status of signing certificates (D-037).
CREATE TABLE signer_revocation (
    thumbprint     CHAR(40)       NOT NULL PRIMARY KEY,
    serial_number  VARCHAR(80)    NULL,
    signer         VARCHAR(255)   NULL,
    issuer         VARCHAR(255)   NULL,
    status         ENUM('unknown','good','revoked','unreachable') NOT NULL DEFAULT 'unknown',
    revoked_at     DATETIME(6)    NULL,
    revocation_reason VARCHAR(64) NULL,
    checked_at     DATETIME(6)    NULL,
    first_seen     DATETIME(6)    NOT NULL,
    KEY ix_sr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MS-ENR-07: SQL Server build names, kept by hand from Microsoft's list.
CREATE TABLE sql_server_builds (
    build         VARCHAR(32)    NOT NULL PRIMARY KEY COMMENT 'e.g. 16.0.4135.4',
    product       VARCHAR(64)    NOT NULL COMMENT 'e.g. SQL Server 2022',
    release_name  VARCHAR(64)    NOT NULL COMMENT 'e.g. CU14',
    kb            VARCHAR(16)    NULL,
    released_on   DATE           NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Users and audit (MS-SEC-06..08)
-- ---------------------------------------------------------------------------

CREATE TABLE users (
    id                INT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username          VARCHAR(100)   NOT NULL,
    display_name      VARCHAR(200)   NULL,
    role              ENUM('viewer','analyst','admin') NOT NULL,
    auth_source       ENUM('local') NOT NULL DEFAULT 'local' COMMENT 'Entra ID / AD later',
    password_hash     VARCHAR(255)   NULL COMMENT 'password_hash(), Argon2id',
    totp_secret_enc   VARBINARY(255) NULL COMMENT 'encrypted with the app key',
    totp_enabled      BOOLEAN        NOT NULL DEFAULT FALSE,
    failed_logins     INT UNSIGNED   NOT NULL DEFAULT 0,
    locked_until      DATETIME(6)    NULL,
    disabled_at       DATETIME(6)    NULL,
    created_at        DATETIME(6)    NOT NULL,
    last_login_at     DATETIME(6)    NULL,
    password_changed_at DATETIME(6)  NULL,
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MS-SEC-08: 2 years, not deletable from the dashboard (its DB user gets
-- INSERT and SELECT only on this table).
CREATE TABLE audit_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    at           DATETIME(6)    NOT NULL,
    user_id      INT UNSIGNED   NULL,
    username     VARCHAR(100)   NULL COMMENT 'copied, so it survives a renamed user',
    source_ip    VARBINARY(16)  NULL,
    action       VARCHAR(64)    NOT NULL COMMENT 'e.g. sign_in, sign_in_failed, view_command_line, export, revoke_agent, config_change',
    target_type  VARCHAR(64)    NULL,
    target_id    VARCHAR(255)   NULL,
    details      JSON           NULL COMMENT 'never secrets (MS-SEC-10)',
    KEY ix_audit_at (at),
    KEY ix_audit_user (user_id, at),
    KEY ix_audit_action (action, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Alerts (MS-ALR)
-- ---------------------------------------------------------------------------

CREATE TABLE alert_rules (
    id                INT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(16)    NOT NULL COMMENT 'requirement ID, e.g. MS-ALR-01',
    name              VARCHAR(200)   NOT NULL,
    default_severity  ENUM('low','medium','high') NOT NULL,
    enabled           BOOLEAN        NOT NULL DEFAULT TRUE,
    params            JSON           NOT NULL DEFAULT ('{}'),
    UNIQUE KEY uq_alert_rules_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO alert_rules (code, name, default_severity, enabled, params) VALUES
 ('MS-ALR-01', 'New listening port',                       'medium', TRUE,  '{"lookback_days": 30}'),
 ('MS-ALR-02', 'Unsigned program on the network',          'high',   TRUE,  '{}'),
 ('MS-ALR-03', 'Flagged destination',                      'high',   TRUE,  '{}'),
 ('MS-ALR-04', 'New executable',                           'low',    TRUE,  '{}'),
 ('MS-ALR-05', 'Agent silent',                             'high',   TRUE,  '{"silent_minutes": 5}'),
 ('MS-ALR-06', 'Coverage gap',                             'medium', TRUE,  '{}'),
 ('MS-ALR-07', 'New outbound destination for a server role','low',   FALSE, '{"lookback_days": 30}'),
 ('MS-ALR-08', 'Agent crashed and restarted',              'medium', TRUE,  '{}'),
 ('MS-ALR-09', 'IIS certificate expiring or weak',         'medium', FALSE, '{"expiry_days": 30}');

CREATE TABLE alert_rule_group_settings (
    rule_id    INT UNSIGNED  NOT NULL,
    group_id   INT UNSIGNED  NOT NULL,
    enabled    BOOLEAN       NULL,
    severity   ENUM('low','medium','high') NULL,
    params     JSON          NULL,
    PRIMARY KEY (rule_id, group_id),
    CONSTRAINT fk_args_rule FOREIGN KEY (rule_id) REFERENCES alert_rules (id),
    CONSTRAINT fk_args_group FOREIGN KEY (group_id) REFERENCES host_groups (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alert_allowlist (
    id          INT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rule_id     INT UNSIGNED   NULL COMMENT 'NULL: every rule',
    group_id    INT UNSIGNED   NULL,
    host_pk     INT UNSIGNED   NULL,
    match_type  ENUM('process','exe_sha256','port','ip_range','signer') NOT NULL,
    match_value VARCHAR(1024)  NOT NULL,
    note        VARCHAR(1000)  NOT NULL COMMENT 'why it is known-good',
    created_by  INT UNSIGNED   NOT NULL,
    created_at  DATETIME(6)    NOT NULL,
    expires_at  DATETIME(6)    NULL,
    disabled_at DATETIME(6)    NULL,
    KEY ix_aal_rule (rule_id),
    CONSTRAINT fk_aal_rule FOREIGN KEY (rule_id) REFERENCES alert_rules (id),
    CONSTRAINT fk_aal_group FOREIGN KEY (group_id) REFERENCES host_groups (id),
    CONSTRAINT fk_aal_host FOREIGN KEY (host_pk) REFERENCES hosts (id),
    CONSTRAINT fk_aal_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One open alert per rule, host and finding; repeats bump the counter.
-- Health alerts (MS-OPS-06) use rule codes starting with 'MS-OPS'.
CREATE TABLE alerts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rule_id          INT UNSIGNED   NOT NULL,
    host_pk          INT UNSIGNED   NULL COMMENT 'NULL for mothership health alerts',
    finding_key      VARCHAR(255)   NOT NULL COMMENT 'e.g. tcp/8080, sha256, IP',
    severity         ENUM('low','medium','high') NOT NULL,
    state            ENUM('open','acknowledged','closed') NOT NULL DEFAULT 'open',
    title            VARCHAR(500)   NOT NULL,
    details          JSON           NULL,
    first_seen       DATETIME(6)    NOT NULL,
    last_seen        DATETIME(6)    NOT NULL,
    occurrences      INT UNSIGNED   NOT NULL DEFAULT 1,
    acknowledged_by  INT UNSIGNED   NULL,
    acknowledged_at  DATETIME(6)    NULL,
    closed_by        INT UNSIGNED   NULL,
    closed_at        DATETIME(6)    NULL,
    close_note       VARCHAR(1000)  NULL,
    open_key         VARCHAR(300)   AS (IF(state = 'closed', NULL,
                                            CONCAT_WS('|', rule_id, IFNULL(host_pk, 0), finding_key))) STORED,
    UNIQUE KEY uq_alerts_open (open_key),
    KEY ix_alerts_state (state, severity, last_seen),
    KEY ix_alerts_host (host_pk, last_seen),
    CONSTRAINT fk_alerts_rule FOREIGN KEY (rule_id) REFERENCES alert_rules (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Daily summaries (MS-DAT-03), 2 years (M-017). Built nightly before the
-- detail expires. Monthly partitions on `day`.
-- ---------------------------------------------------------------------------

CREATE TABLE daily_host_destinations (
    day           DATE           NOT NULL,
    host_pk       INT UNSIGNED   NOT NULL,
    dest_hash     BINARY(16)     NOT NULL COMMENT 'MD5 of proto|remote_ip|remote_port|direction|process_name',
    proto         VARCHAR(8)     NOT NULL,
    direction     VARCHAR(16)    NOT NULL,
    remote_ip     VARBINARY(16)  NULL,
    remote_addr   VARCHAR(64)    NOT NULL,
    remote_port   SMALLINT UNSIGNED NOT NULL,
    process_name  VARCHAR(260)   NULL,
    connections   BIGINT UNSIGNED NOT NULL,
    bytes_sent    BIGINT UNSIGNED NULL,
    bytes_recv    BIGINT UNSIGNED NULL,
    first_seen    DATETIME(6)    NOT NULL,
    last_seen     DATETIME(6)    NOT NULL,
    PRIMARY KEY (day, host_pk, dest_hash),
    KEY ix_dhd_remote (remote_ip, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (day) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE daily_host_listeners (
    day           DATE           NOT NULL,
    host_pk       INT UNSIGNED   NOT NULL,
    proto         VARCHAR(8)     NOT NULL,
    local_addr    VARCHAR(64)    NOT NULL,
    local_port    SMALLINT UNSIGNED NOT NULL,
    process_name  VARCHAR(260)   NOT NULL DEFAULT '',
    app_name      VARCHAR(255)   NULL,
    first_seen    DATETIME(6)    NOT NULL,
    last_seen     DATETIME(6)    NOT NULL,
    PRIMARY KEY (day, host_pk, proto, local_addr, local_port, process_name),
    KEY ix_dhl_port (local_port, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (day) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE daily_host_processes (
    day           DATE           NOT NULL,
    host_pk       INT UNSIGNED   NOT NULL,
    exe_hash      BINARY(32)     NOT NULL COMMENT 'SHA-256 of lower(exe)|user',
    exe           VARCHAR(1024)  NOT NULL,
    `user`        VARCHAR(256)   NULL,
    starts        BIGINT UNSIGNED NOT NULL,
    first_seen    DATETIME(6)    NOT NULL,
    last_seen     DATETIME(6)    NOT NULL,
    PRIMARY KEY (day, host_pk, exe_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (day) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE daily_host_executables (
    day           DATE           NOT NULL,
    host_pk       INT UNSIGNED   NOT NULL,
    sha256        CHAR(64)       NOT NULL,
    path          VARCHAR(1024)  NOT NULL,
    sig_status    VARCHAR(64)    NULL,
    sig_signer    VARCHAR(255)   NULL,
    PRIMARY KEY (day, host_pk, sha256),
    KEY ix_dhe_sha (sha256, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (day) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE daily_host_traffic (
    day                  DATE           NOT NULL,
    host_pk              INT UNSIGNED   NOT NULL,
    flow_records         BIGINT UNSIGNED NOT NULL,
    connections          BIGINT UNSIGNED NOT NULL,
    distinct_remote_ips  INT UNSIGNED   NOT NULL,
    bytes_sent           BIGINT UNSIGNED NULL,
    bytes_recv           BIGINT UNSIGNED NULL,
    coverage_gap_seconds INT UNSIGNED   NOT NULL DEFAULT 0 COMMENT 'MS-UI-07',
    PRIMARY KEY (day, host_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (day) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE daily_host_inventory_changes (
    day           DATE           NOT NULL,
    host_pk       INT UNSIGNED   NOT NULL,
    kind          VARCHAR(64)    NOT NULL,
    `change`      VARCHAR(16)    NOT NULL,
    changes       INT UNSIGNED   NOT NULL,
    PRIMARY KEY (day, host_pk, kind, `change`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (day) (
    PARTITION p_start  VALUES LESS THAN ('2026-11-01'),
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- ---------------------------------------------------------------------------
-- Operations (MS-DAT-02, MS-OPS-05)
-- ---------------------------------------------------------------------------

-- MS-DAT-02: retention per table, as data. The plan's values were accepted as
-- defaults at the milestone 1 review (M-017); changing one is a row update.
CREATE TABLE retention_policies (
    table_name      VARCHAR(64)   NOT NULL PRIMARY KEY,
    keep_days       INT UNSIGNED  NOT NULL,
    method          ENUM('drop_partition','delete_rows','none') NOT NULL,
    partition_unit  ENUM('day','month') NULL,
    note            VARCHAR(500)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO retention_policies (table_name, keep_days, method, partition_unit, note) VALUES
 ('net_flows',                     90, 'drop_partition', 'day',   'detail 90 days'),
 ('snapshots',                     90, 'drop_partition', 'day',   'detail 90 days'),
 ('snap_tcp_conns',                90, 'drop_partition', 'day',   'detail 90 days'),
 ('snap_listeners',                90, 'drop_partition', 'day',   'detail 90 days'),
 ('snap_udp_endpoints',            90, 'drop_partition', 'day',   'detail 90 days'),
 ('process_events',                90, 'drop_partition', 'day',   'detail 90 days'),
 ('process_command_lines',         30, 'drop_partition', 'day',   'command lines 30 days, MS-SEC-09 (M-017)'),
 ('agent_status',                  90, 'drop_partition', 'day',   'detail 90 days'),
 ('inventory_snapshots',           90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inv_apps',                      90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inv_services',                  90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inv_tasks',                     90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inv_startup',                   90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inv_drivers',                   90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inv_roles',                     90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('host_facts',                    90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inv_iis',                       90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inv_iis_sites',                 90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inv_iis_app_pools',             90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inv_sql_instances',             90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inv_server_apps',               90, 'drop_partition', 'day',   'full snapshots 90 days'),
 ('inventory_changes',            730, 'drop_partition', 'day',   'changes 2 years'),
 ('inventory_link_index',          90, 'delete_rows',    NULL,    'diagnostics'),
 ('executables',                   90, 'delete_rows',    NULL,    'rows not seen for 90 days'),
 ('coverage_gaps',                730, 'delete_rows',    NULL,    '2 years'),
 ('agent_lifecycle',              730, 'delete_rows',    NULL,    '2 years'),
 ('quarantine',                    90, 'drop_partition', 'day',   '90 days, so provisional types outlive their wait (M-022)'),
 ('ingest_batches',                30, 'drop_partition', 'day',   'operations'),
 ('agent_heartbeats',              90, 'drop_partition', 'day',   '90 days'),
 ('audit_log',                    730, 'delete_rows',    NULL,    '2 years, MS-SEC-08'),
 ('daily_host_destinations',      730, 'drop_partition', 'month', 'summaries 2 years'),
 ('daily_host_listeners',         730, 'drop_partition', 'month', 'summaries 2 years'),
 ('daily_host_processes',         730, 'drop_partition', 'month', 'summaries 2 years'),
 ('daily_host_executables',       730, 'drop_partition', 'month', 'summaries 2 years'),
 ('daily_host_traffic',           730, 'drop_partition', 'month', 'summaries 2 years'),
 ('daily_host_inventory_changes', 730, 'drop_partition', 'month', 'summaries 2 years'),
 ('enrollment_failures',            7, 'delete_rows',    NULL,    'rate limiting only');

-- MS-OPS-05: last run of each background job, for the health page.
CREATE TABLE job_runs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job          VARCHAR(64)    NOT NULL COMMENT 'e.g. partitions, retention, summaries, enrich_dns, alerts, backup, inventory_apply',
    started_at   DATETIME(6)    NOT NULL,
    finished_at  DATETIME(6)    NULL,
    status       ENUM('running','ok','failed') NOT NULL,
    details      JSON           NULL,
    KEY ix_job_runs (job, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
