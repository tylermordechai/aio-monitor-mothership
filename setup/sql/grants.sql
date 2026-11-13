-- setup/sql/grants.sql: privileges for the three application users (MS-SEC-04,
-- M-036). Applied by setup/06-mariadb.sh after the migrations, as root over the
-- local socket. No passwords here: the script creates the users first.
-- Safe to re-run: every user's privileges are revoked and granted again, so
-- the result is always exactly this file.

REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'aio_ingest'@'localhost';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'aio_dashboard'@'localhost';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'aio_maint'@'localhost';

-- ---------------------------------------------------------------------------
-- aio_ingest: the agent API on 8443 (ingest, heartbeat, config, enrol, renew).
-- Insert-only on record tables (INSERT IGNORE dedups, M-005); it never reads
-- them back, and it cannot read users, audit or alerts at all.
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
GRANT INSERT ON aio.ingest_batches         TO 'aio_ingest'@'localhost';
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
-- Read-only lookups: groups, config, retention (ts window, M-007).
GRANT SELECT ON aio.host_groups        TO 'aio_ingest'@'localhost';
GRANT SELECT ON aio.agent_config       TO 'aio_ingest'@'localhost';
GRANT SELECT ON aio.retention_policies TO 'aio_ingest'@'localhost';

-- ---------------------------------------------------------------------------
-- aio_dashboard: the dashboard on 443. Reads everything (roles and the
-- command-line audit are enforced in the app, MS-SEC-07/08, MPD-34); writes
-- only its own user, alert, audit and admin tables. Audit rows: insert only.
-- ---------------------------------------------------------------------------
GRANT SELECT ON aio.* TO 'aio_dashboard'@'localhost';
GRANT INSERT, UPDATE         ON aio.users                     TO 'aio_dashboard'@'localhost';
GRANT INSERT                 ON aio.audit_log                 TO 'aio_dashboard'@'localhost';
GRANT INSERT, UPDATE         ON aio.alert_rules               TO 'aio_dashboard'@'localhost';
GRANT INSERT, UPDATE, DELETE ON aio.alert_rule_group_settings TO 'aio_dashboard'@'localhost';
GRANT INSERT, UPDATE, DELETE ON aio.alert_allowlist           TO 'aio_dashboard'@'localhost';
GRANT UPDATE                 ON aio.alerts                    TO 'aio_dashboard'@'localhost';
-- Admin pages: groups, agent config, tokens, revocation, retention, lists.
GRANT INSERT, UPDATE ON aio.host_groups       TO 'aio_dashboard'@'localhost';
GRANT INSERT, UPDATE ON aio.agent_config      TO 'aio_dashboard'@'localhost';
GRANT INSERT, UPDATE ON aio.enrollment_tokens TO 'aio_dashboard'@'localhost';
GRANT UPDATE ON aio.hosts              TO 'aio_dashboard'@'localhost';
GRANT UPDATE ON aio.agents             TO 'aio_dashboard'@'localhost';
GRANT UPDATE ON aio.agent_certificates TO 'aio_dashboard'@'localhost';
GRANT UPDATE ON aio.retention_policies TO 'aio_dashboard'@'localhost';
GRANT UPDATE ON aio.threat_lists       TO 'aio_dashboard'@'localhost';

-- ---------------------------------------------------------------------------
-- aio_maint: background jobs from systemd timers (partitions, retention,
-- summaries, enrichment, alert rules, inventory_current). Never a web process.
-- ---------------------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX,
      LOCK TABLES, CREATE TEMPORARY TABLES, CREATE VIEW, SHOW VIEW
      ON aio.* TO 'aio_maint'@'localhost';

FLUSH PRIVILEGES;
