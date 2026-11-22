-- 0003: connections per hour, for the dashboard (milestone 5, M-054).
--
-- One row per host, hour and connection, where a connection is the D-016
-- key: protocol, PID, local and remote address and port (net_flows.flow_key).
-- Filled from net_flows every minute by tools/jobs/flow-rollup.php (systemd
-- timer, aio_maint); an hour is rebuilt whole whenever a batch adds flows to
-- it, so the rows always equal a GROUP BY over net_flows for that hour.
-- The map and the host connections tab read this instead of millions of
-- 10-second flow records (MS-UI-08). MS-DAT-03's daily summaries (milestone
-- 4) can build on it. Retention: with net_flows (milestone 4).

CREATE TABLE flow_conn_hourly (
    host_pk            INT UNSIGNED    NOT NULL,
    hour               DATETIME        NOT NULL COMMENT 'UTC, start of the hour of net_flows.ts',
    flow_key           BINARY(16)      NOT NULL COMMENT 'D-016 key, as net_flows.flow_key',
    proto              VARCHAR(8)      NOT NULL,
    local_addr         VARCHAR(64)     NOT NULL,
    local_ip           VARBINARY(16)   NULL,
    local_port         SMALLINT UNSIGNED NOT NULL,
    remote_addr        VARCHAR(64)     NOT NULL,
    remote_ip          VARBINARY(16)   NULL,
    remote_port        SMALLINT UNSIGNED NOT NULL,
    pid                INT UNSIGNED    NOT NULL,
    process_name       VARCHAR(260)    NULL,
    exe                VARCHAR(1024)   NULL,
    `user`             VARCHAR(256)    NULL,
    user_source        VARCHAR(32)     NULL,
    direction          VARCHAR(16)     NOT NULL,
    direction_source   VARCHAR(32)     NULL COMMENT 'MS-DAT-05: how the direction was decided',
    first_seen         DATETIME(6)     NULL,
    last_seen          DATETIME(6)     NULL,
    windows            INT UNSIGNED    NOT NULL COMMENT 'net_flow records combined',
    connects           BIGINT UNSIGNED NOT NULL DEFAULT 0,
    packets_sent       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    packets_recv       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_sent         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_recv         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    closed             BOOLEAN         NOT NULL DEFAULT FALSE,
    PRIMARY KEY (host_pk, hour, flow_key),
    KEY ix_fch_hour (hour, host_pk),
    KEY ix_fch_remote (remote_ip, hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where background jobs left off (job name -> value), written by aio_maint.
CREATE TABLE job_state (
    name        VARCHAR(64)   NOT NULL PRIMARY KEY,
    value       VARCHAR(255)  NOT NULL,
    updated_at  DATETIME(6)   NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
