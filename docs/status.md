# Project status

Last updated: 2026-11-13

Where the mothership stands. The spec is [mothership-plan.md](mothership-plan.md); decisions are in [decisions.md](decisions.md) (M-001 to M-038); open items are in [pending-decisions.md](pending-decisions.md) (MPD-32 to MPD-34); working rules are in [../CLAUDE.md](../CLAUDE.md).

## Pick up here

- **The ingest contract (v1) is approved** (M-017). Agent 5c can build against it; the collector address is 192.168.0.191.
- **The VM is built** (2026-11-13): Ubuntu Server 26.04.1 LTS at 192.168.0.191, user `aioadmin`, clean snapshot `M0-off`.
- **Server setup scripts written, not yet run:** `setup/01` to `08` and [setup.md](setup.md) (M-033 to M-038). The owner runs them over SSH one at a time and pastes the output back. Claude never logs in to the VM.
- **Next:** step 0 (clone the repo) and step 1 (`01-ssh-key.sh`), then wait for the owner to confirm key login before `02`.
- **The schema is approved once `06-mariadb.sh` prints `SCHEMA GATE: PASS`.** It hasn't been applied anywhere yet.
- **Still open, not blocking:** MPD-32 (automatic reboot; default off), MPD-33 (MS-OPS-04 wording), MPD-34 (a separate analyst database user for command lines).

## Summary

| Milestone | Scope | State |
| --- | --- | --- |
| 1 | Server and contract | **Contract approved** (2026-11-13, M-017). Setup scripts written (2026-11-13), not yet run. Schema approved when `06-mariadb.sh` passes |
| 2 | Ingest, simulator, load test | Not started |
| 3 | Enrolment | Not started |
| 4 | Retention (backups moved to "before ready to ship", M-026) | Not started |
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

**Review (2026-11-13):** answers recorded as M-017 to M-023: renewal endpoint and new requirement MS-ING-11 (Should), TLS 1.3 only on 8443, the `seq_epoch` correction (it detects a recreated state file, not a snapshot revert), manual and deployment tokens (`enrollment_tokens.token_kind`), quarantine kept 90 days, Ubuntu 24.04 LTS with MariaDB 11.8 LTS (since changed to Ubuntu 26.04.1 LTS with Ubuntu's MariaDB 11.8 and PHP 8.5, M-029). ingest-api.md, the schema and pending-decisions.md updated to match.

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

**How to check it:** `06-mariadb.sh` applies it on the VM and prints `SCHEMA GATE: PASS` or `FAIL`.

## Milestone 1: server setup (written 2026-11-13, not yet run)

**What was written** (decisions M-033 to M-038; how to run it in [setup.md](setup.md)):

| Script | Job | Requirements |
| --- | --- | --- |
| `01-ssh-key.sh` | The PC's key for `aioadmin` (no sudo) | MS-SEC-01 |
| `02-ssh-hardening.sh` | Keys only, password and root login off; needs `--key-login-confirmed` | MS-SEC-01 |
| `03-os-updates.sh` | Updates now; unattended security updates, no auto reboot | MS-SEC-12 |
| `04-time.sh` | UTC and NTP | MS-ING-05 |
| `05-firewall.sh` | ufw: 22, 443, 8443 from 192.168.0.0/24 only | MS-SEC-01, M-025 |
| `06-mariadb.sh` | MariaDB 11.8 (Ubuntu), hardened; migrations with checksums (schema gate); 3 users, table-level grants, negative grant tests | MS-SEC-04, MS-DAT-01 |
| `07-pki.sh` | Agent CA, name-constrained web CA, server certificates (IP SAN) | MS-SEC-01, 02 |
| `08-apache-php.sh` | Apache mpm_event, PHP 8.5-FPM with 2 pools and users, vhosts 8443 (TLS 1.3, client cert optional) and 443, health pages, mTLS self-test | MS-SEC-01, 04, 05 |

Also: `setup/env.sh`, `setup/lib.sh`, `setup/templates/`, `setup/sql/grants.sql`, `public/{agent,dashboard}/health.php`, `.gitattributes` (LF for scripts).

**Not tested yet:** none of it has run. There is no Ubuntu 26.04 machine here, so package names, paths and tool output are as documented for Ubuntu and checked by each script's own checks. The first runs may need small fixes.

**Results:**

| Step | Result |
| --- | --- |
| 01 to 08 | not run yet |
| Schema gate | not run yet |

## Must requirements still open

All of them: nothing is built and tested yet.
- The contract and schema cover the design of MS-ING-01 to 06, 08, 09 and MS-DAT-01, 02, 04, 05 (approved as design). MS-ING-11 (Should) was added.
- The setup scripts, once run, will cover MS-SEC-01 (TLS, firewall), MS-SEC-02 (CA key access, as far as files go), MS-SEC-04 (database users) and MS-SEC-12 (automatic updates).
- MS-ING-10 and MS-DAT-06 are measured in milestone 2.

## Next steps

1. **The owner runs step 0 and step 1** of [setup.md](setup.md), confirms key login works, and pastes the output back.
2. **Then 02 to 08 in order,** one at a time, fixing anything the output shows. `06` is the schema gate.
3. **After 08:** import the web CA on the PC, and check `https://192.168.0.191/health.php`. Milestone 1 is then done; report it and wait for the go-ahead for milestone 2.
4. **Agent 5c can start now** from the approved contract (ingest-api.md §10), collector address 192.168.0.191.
5. **For the tech lead:** add MS-ING-11 and the new MS-ING-01 wording to `mothership-plan.md`, and `inventory_link_index` to CLAUDE.md's list of record types (M-016, M-018). Not done here.

## Before the pilot (M-031)

- An encrypted copy of the two CA keys and `/etc/aio-mothership/` (app config), kept off the VM (MS-OPS-02).

## Before ready to ship (M-026)

- Backups and a timed restore to a fresh VM: MS-OPS-01 to MS-OPS-04, plus the Unraid disk and off-box copy questions.
