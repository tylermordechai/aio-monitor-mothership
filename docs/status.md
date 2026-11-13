# Project status

Last updated: 2026-11-13

Where the mothership stands. The spec is [mothership-plan.md](mothership-plan.md); decisions are in [decisions.md](decisions.md) (M-001 to M-016); open items are in [pending-decisions.md](pending-decisions.md) (MPD-01 to MPD-28); working rules are in [../CLAUDE.md](../CLAUDE.md).

## Pick up here

- **Milestone 1, contract and schema part:** drafted and pushed, **waiting for the tech lead's review** (the milestone 1 gate).
- **Not started, on purpose:** the server part of milestone 1 (VM, Apache, PHP, MariaDB, TLS, firewall, agent CA, `docs/setup.md`). It waits for the go-ahead.
- **Needed to pass the gate:** answers to the **[contract]** items MPD-01 to MPD-17 in pending-decisions.md. MPD-05 (`acked_seq` meaning), MPD-07 (heartbeat), MPD-11 (agent ID) and MPD-12 (inventory keys) change what agent 5c builds.
- **The schema hasn't been applied to a database yet:** there is no MariaDB on the dev PC. It is first applied, and fixed if needed, on the milestone 1 VM.

## Summary

| Milestone | Scope | State |
| --- | --- | --- |
| 1 | Server and contract | **Contract and schema drafted** (2026-11-13), in review. Server setup not started |
| 2 | Ingest, simulator, load test | Not started |
| 3 | Enrolment | Not started |
| 4 | Retention, backups | Not started |
| 5 | Dashboard core | Not started |
| 6 | Dashboard full, enrichment | Not started |
| 7 | Alerts and pilot | Not started |

## Milestone 1: contract and schema (2026-11-13)

**What was done:**
- Read the agent repo, read-only, at `master` as of 2026-11-13:
  - `docs/status.md`, `docs/decisions.md` (D-004, D-008, D-016, D-039, D-060, D-071, D-073 and the rest), `docs/agent-requirements.md`;
  - the record structs in `internal/netcollect`, `internal/proc`, `internal/inventory`, `internal/output`;
  - `internal/config/identity.go` for `seq`.
- Wrote [ingest-api.md](ingest-api.md): MS-ING-01 to MS-ING-10, the enrolment flow and certificate profile, ingest, heartbeat, config with the D-071 detection list, error codes, retry and backoff, and a worked example per endpoint.
- Wrote `db/migrations/0001_initial.sql`: one table per agent record type, the mothership's own tables, and daily partitions where the plan asks for them.
- Recorded M-001 to M-016, and MPD-01 to MPD-28 with recommendations.

**Findings from the agent source that shape the contract:**
- **`seq` is one counter per `agent_id`**, shared by every record type and output file, reserved in ranges before use.
  - Gaps are normal after a crash, and records reach the queue out of `seq` order.
  - So `acked_seq` acknowledges a request's lines, and is not a watermark (M-004, MPD-05).
- **`seq` survives** restarts and a reinstall without `--purge`.
  - A purge, or a data folder renamed aside (D-060), gives a **new `agent_id`** starting at 1. That is safe.
  - A restored VM snapshot reuses `seq` under the **same** `agent_id`. That is detected and stored, never dropped (M-005).
- **Inventory item records carry no `key` and no `snapshot_id`;** only `inventory_change` has a key. The mothership derives them, and agent 5c is asked to add them (MPD-12).
- **`inventory_link_index`** is a 5b record that CLAUDE.md's list leaves out. It has a table (MPD-13).
- **All records of one network snapshot share their `ts`** (`output.JSONFile.Write`). A snapshot is identified by agent and `ts`.

**Tables in `0001_initial.sql`:**

| Group | Tables |
| --- | --- |
| Network snapshots (M1 records) | `snapshots`, `snap_tcp_conns`, `snap_listeners`, `snap_udp_endpoints` |
| Flows and health (M2) | `net_flows`, `agent_status`, `coverage_gaps` |
| Processes (M3) | `process_events`, `process_command_lines`, `executables` |
| Agent lifecycle (M5) | `agent_lifecycle` |
| Inventory (M4) | `inventory_snapshots`, `inventory_changes`, `inv_apps`, `inv_services`, `inv_tasks`, `inv_startup`, `inv_drivers`, `inv_roles`, `host_facts`, `inventory_current` |
| Server software (5b) | `inv_iis`, `inv_iis_sites`, `iis_binding_ports`, `inv_iis_app_pools`, `inv_sql_instances`, `inv_server_apps`, `inventory_link_index` |
| Ingest and identity | `host_groups`, `hosts`, `host_names`, `agents`, `agent_certificates`, `enrollment_tokens`, `enrollment_failures`, `agent_config`, `ingest_batches`, `agent_heartbeats`, `quarantine` |
| Enrichment | `ip_enrichment`, `threat_lists`, `threat_list_entries`, `ip_reputation`, `signer_revocation`, `sql_server_builds` |
| Dashboard and alerts | `users`, `audit_log`, `alert_rules` (seeded MS-ALR-01..09), `alert_rule_group_settings`, `alert_allowlist`, `alerts` |
| Summaries and operations | `daily_host_destinations`, `daily_host_listeners`, `daily_host_processes`, `daily_host_executables`, `daily_host_traffic`, `daily_host_inventory_changes`, `retention_policies` (seeded), `job_runs`, `schema_migrations` |

**How to check it:** read `docs/ingest-api.md` and `db/migrations/0001_initial.sql`. Nothing runs yet. On the milestone 1 VM: `mariadb aio < db/migrations/0001_initial.sql` on an empty database.

## Must requirements still open

All of them: nothing is built yet. Milestone 1's contract and schema work covers the design of MS-ING-01 to 06, 08, 09 and MS-DAT-01, 02, 04, 05, all in review. MS-ING-10 and MS-DAT-06 are measured in milestone 2.

## Next steps

1. **The tech lead's review** of ingest-api.md and the schema, and answers to the [contract] items in pending-decisions.md. Then fix and re-push.
2. **After approval:** the server part of milestone 1 (VM, Apache, PHP 8.3, MariaDB, TLS, ufw, agent CA, `docs/setup.md`), and apply the schema there.
3. **Agent 5c can start** from the approved contract. Hand over its section 10 list.
