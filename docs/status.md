# Project status

Last updated: 2026-11-13

Where the mothership stands. The spec is [mothership-plan.md](mothership-plan.md); decisions are in [decisions.md](decisions.md) (M-001 to M-023); open items are in [pending-decisions.md](pending-decisions.md) (MPD-20, MPD-25 to MPD-29); working rules are in [../CLAUDE.md](../CLAUDE.md).

## Pick up here

- **Milestone 1 review done (2026-11-13):** the tech lead answered MPD-01 to MPD-19 and MPD-21 to MPD-24 (M-017 to M-023). **The ingest contract (v1) is approved**; agent 5c can build against it.
- **The schema is approved once it applies cleanly on the milestone 1 VM.** It hasn't been applied anywhere yet (no MariaDB on the dev PC).
- **Waiting:** the tech lead says when the VM is built. Don't start the server setup before that.
- **Still open, not blocking:** MPD-20 (DNS name, dashboard CA) and MPD-25 (networks) are needed during the server setup; MPD-26 to MPD-29 later.

## Summary

| Milestone | Scope | State |
| --- | --- | --- |
| 1 | Server and contract | **Contract approved** (2026-11-13, M-017). Schema waits for its first clean apply on the VM. Server setup waits for the VM |
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

**Review (2026-11-13):** answers recorded as M-017 to M-023: renewal endpoint and new requirement MS-ING-11 (Should), TLS 1.3 only on 8443, the `seq_epoch` correction (it detects a recreated state file, not a snapshot revert), manual and deployment tokens (`enrollment_tokens.token_kind`), quarantine kept 90 days, Ubuntu 24.04 LTS with MariaDB 11.8 LTS. ingest-api.md, the schema and pending-decisions.md updated to match.

**Findings from the agent source that shape the contract:**
- **`seq` is one counter per `agent_id`**, shared by every record type and output file, reserved in ranges before use.
  - Gaps are normal after a crash, and records reach the queue out of `seq` order.
  - So `acked_seq` acknowledges a request's lines, and is not a watermark (M-004).
- **`seq` survives** restarts and a reinstall without `--purge`.
  - A purge, or a data folder renamed aside (D-060), gives a **new `agent_id`** starting at 1. That is safe.
  - A restored VM snapshot reuses `seq` under the **same** `agent_id`. That is detected and stored, never dropped (M-005).
- **Inventory item records carry no `key` and no `snapshot_id`;** only `inventory_change` has a key. The mothership derives them, and agent 5c is asked to add them (M-012).
- **`inventory_link_index`** is a 5b record that CLAUDE.md's list leaves out. It has a table (M-016).
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

All of them: nothing is built yet. Milestone 1's contract and schema work covers the design of MS-ING-01 to 06, 08, 09 and MS-DAT-01, 02, 04, 05, now approved as design; MS-ING-11 (Should) added. MS-ING-10 and MS-DAT-06 are measured in milestone 2.

## Next steps

1. **The tech lead builds the VM and says so.** Then the server part of milestone 1: Apache, PHP 8.3, MariaDB 11.8, TLS (1.3 only on 8443), ufw, agent CA, `docs/setup.md`. Answers to MPD-20 and MPD-25 are needed during it.
2. **Apply `0001_initial.sql`** on the VM's empty database and fix it until it applies cleanly; that approves the schema.
3. **Agent 5c can start now** from the approved contract (ingest-api.md §10).
4. **For the tech lead, not done here:** add MS-ING-11 and the new MS-ING-01 wording to `mothership-plan.md`, and `inventory_link_index` to CLAUDE.md's list of record types (M-016, M-018). These files are the spec and working rules, so they were left as they are.
