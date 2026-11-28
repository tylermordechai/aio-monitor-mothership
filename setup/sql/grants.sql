-- setup/sql/grants.sql: privileges for the five application users (MS-SEC-04,
-- M-036, M-070). Applied by setup/06-mariadb.sh after the migrations, as root over the
-- local socket. No passwords here: the script creates the users first.
-- Safe to re-run: every user's privileges are revoked and granted again, so
-- the result is always exactly this file.

REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'aio_ingest'@'localhost';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'aio_dashboard'@'localhost';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'aio_maint'@'localhost';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'aio_analyst'@'localhost';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'aio_admin'@'localhost';

-- ---------------------------------------------------------------------------
-- aio_ingest: the agent API on 8443 (ingest, heartbeat, config, enrol, renew).
-- Insert-only on record tables (INSERT IGNORE dedups, M-005); it never reads
-- them back, and it cannot read users, audit or alerts at all (it may only
-- add audit rows, M-062).
-- ---------------------------------------------------------------------------
GRANT INSERT ON aio.snapshots              TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.snap_tcp_conns         TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.snap_listeners         TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.snap_udp_endpoints     TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.net_flows              TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.agent_status           TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.process_events         TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.process_command_lines  TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.agent_lifecycle        TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inventory_snapshots    TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inventory_changes      TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inventory_link_index   TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inv_apps               TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inv_services           TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inv_tasks              TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inv_startup            TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inv_drivers            TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inv_roles              TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.host_facts             TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inv_iis                TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inv_iis_sites          TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.iis_binding_ports      TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inv_iis_app_pools      TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inv_sql_instances      TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.inv_server_apps        TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.quarantine             TO 'aio_ingest'@'localhost';
-- The batch row is written first and its counts filled in before COMMIT;
-- UPDATE ... WHERE needs SELECT on the key columns (M-045).
GRANT SELECT, INSERT, UPDATE ON aio.ingest_batches TO 'aio_ingest'@'localhost';
GRANT INSERT ON aio.agent_heartbeats       TO 'aio_ingest'@'localhost';
-- One row per item, updated in place (M-014, M-015).
GRANT SELECT, INSERT, UPDATE ON aio.executables   TO 'aio_ingest'@'localhost';
GRANT SELECT, INSERT, UPDATE ON aio.coverage_gaps TO 'aio_ingest'@'localhost';
-- Identity, enrolment and renewal (M-001, M-003, M-018, M-021).
GRANT SELECT, INSERT, UPDATE ON aio.agents              TO 'aio_ingest'@'localhost';
GRANT SELECT, INSERT, UPDATE ON aio.hosts               TO 'aio_ingest'@'localhost';
GRANT SELECT, INSERT, UPDATE ON aio.host_names          TO 'aio_ingest'@'localhost';
GRANT SELECT, INSERT, UPDATE ON aio.agent_certificates  TO 'aio_ingest'@'localhost';
GRANT SELECT, INSERT, UPDATE ON aio.enrollment_failures TO 'aio_ingest'@'localhost';
GRANT SELECT, UPDATE         ON aio.enrollment_tokens   TO 'aio_ingest'@'localhost';
-- Enrolment and re-enrolment are audited (ingest-api.md §2.5, M-062):
-- insert only, so the agent API can't read, change or delete audit rows.
GRANT INSERT                 ON aio.audit_log           TO 'aio_ingest'@'localhost';
-- Read-only lookups: groups, config, retention (ts window, M-007).
GRANT SELECT ON aio.host_groups        TO 'aio_ingest'@'localhost';
GRANT SELECT ON aio.agent_config       TO 'aio_ingest'@'localhost';
GRANT SELECT ON aio.retention_policies TO 'aio_ingest'@'localhost';

-- ---------------------------------------------------------------------------
-- aio_dashboard: the dashboard on 443 (M-053, M-054).
-- Reads every table except process_command_lines: those SELECT grants are
-- made per table by setup/06-mariadb.sh from the live schema, right after
-- this file, so new tables are covered when 06 is re-run after a migration.
-- Writes only users (sign-in counters, tools/users.php) and audit_log
-- (insert only, MS-SEC-08). The admin pages write through aio_admin below
-- (M-070); the alert pages get their own grants in milestones 6 and 7.
-- ---------------------------------------------------------------------------
GRANT INSERT, UPDATE ON aio.users     TO 'aio_dashboard'@'localhost';
GRANT INSERT         ON aio.audit_log TO 'aio_dashboard'@'localhost';

-- ---------------------------------------------------------------------------
-- aio_analyst: the only user that can read command lines (M-041, M-053).
-- The dashboard connects as it only after checking the signed-in user is an
-- analyst or admin, and writes the audit row in the same transaction as the
-- read (MS-SEC-07..09). Nothing else.
-- ---------------------------------------------------------------------------
GRANT SELECT ON aio.process_command_lines TO 'aio_analyst'@'localhost';
GRANT INSERT ON aio.audit_log             TO 'aio_analyst'@'localhost';

-- ---------------------------------------------------------------------------
-- aio_admin: the admin pages' writes (M-070, MS-SEC-07): enrolment tokens and
-- the revoke and unrevoke buttons. The dashboard connects as it only after
-- checking the signed-in user is an admin; the pages themselves still read
-- as aio_dashboard. Column grants: it can cancel a token but not change
-- one, and change an agent's revocation but nothing else about it.
-- ---------------------------------------------------------------------------
GRANT SELECT, INSERT ON aio.enrollment_tokens TO 'aio_admin'@'localhost';
GRANT UPDATE (cancelled_at, cancelled_by) ON aio.enrollment_tokens TO 'aio_admin'@'localhost';
GRANT SELECT ON aio.agents TO 'aio_admin'@'localhost';
GRANT UPDATE (status, revoked_at, revoked_by, revoke_reason) ON aio.agents TO 'aio_admin'@'localhost';
GRANT SELECT ON aio.hosts       TO 'aio_admin'@'localhost';
GRANT SELECT ON aio.host_groups TO 'aio_admin'@'localhost';
GRANT INSERT ON aio.audit_log   TO 'aio_admin'@'localhost';

-- ---------------------------------------------------------------------------
-- aio_maint: background jobs from systemd timers (partitions, retention,
-- summaries, enrichment, alert rules, inventory_current). Never a web process.
-- ---------------------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX,
      LOCK TABLES, CREATE TEMPORARY TABLES, CREATE VIEW, SHOW VIEW
      ON aio.* TO 'aio_maint'@'localhost';

FLUSH PRIVILEGES;
