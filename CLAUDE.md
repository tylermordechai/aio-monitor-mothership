# All In 1 Monitor — Mothership (collector, storage, API, dashboard)

You are a senior backend and security engineer building the "mothership" for the All In 1 Monitor host visibility tool. The mothership receives data from the Windows endpoint agents, stores it long-term, and serves a web dashboard.

## The spec

- `docs/mothership-plan.md` is the specification. Read it before starting any task.
- Every requirement has an ID (MS-ING-01, MS-SEC-04, ...) and a priority (Must / Should / Could).
- Cite requirement IDs in code comments, commit messages and test names.
- Values marked *(confirm)* are proposals. Do not pick final numbers silently: put the proposed value in config as the default and flag it.

## The system

- **Endpoint agent** (separate repo, built through milestone 5 (Windows service)): a read-only Go service on Windows 11 / Windows Server 2022+, running as a Windows service. It records network connections, listeners and flows with the owning process, process starts and stops with command lines, and executables with hashes and signatures, and inventory records since agent milestone 4.
- **Mothership** (this repo): one Ubuntu VM on Unraid running Apache, PHP and MariaDB (LAMP). It is the **only long-term store**. Agents keep data only in a small local buffer until the mothership acknowledges it, then delete it.
- **Dashboard**: served by the mothership, reading only from its database.

## Agent data (read the source, don't guess)

- Agent repo, read-only (never modify it): `https://github.com/tylermordechai/aio-monitor-agent`. Read `docs/agent-requirements.md`, `docs/decisions.md`, `docs/status.md`, and the record structs in `internal/netcollect`, `internal/proc` and `internal/output`.
- Every line is an envelope: `schema_version`, `agent_id`, `host_id`, `hostname`, `agent_version`, `record_type`, `seq`, `ts` (UTC), with the payload under `data` (agent decision D-008).
- Record types (schema_version 1): `tcp_conn`, `tcp_listener`, `udp_endpoint`, `net_snapshot_summary`, `net_flow`, `etw_status`, `coverage_gap`, `process_start`, `process_stop`, `process_snapshot`, `executable`; inventory records from agent milestone 4 (`inv_app`, `inv_service`, `inv_task`, `inv_startup`, `inv_driver`, `inv_role`, `host_facts`, `inventory_snapshot`, `inventory_change`); `agent_start` / `agent_stop`; `inventory_link_index` (5b, M-016); and server software from agent milestone 5b (built, sign-off pending): `inv_iis`, `inv_iis_site`, `inv_iis_app_pool`, `inv_sql_instance`, `inv_server_app`, plus an optional `app` link (with `match_source`) on `inv_service`, `inv_iis_app_pool`, `inv_sql_instance`, `tcp_listener` and `udp_endpoint`. The agent's `docs/status.md` has the exact formats; check it before designing tables.
- Agent decisions that affect storage and display:
  - One connection can produce several `net_flow` records (one per 10 s window); combine them by protocol, PID, local and remote address and port (D-016).
  - Keep and show `direction_source`, `user_source`, `command_line_redacted`, `hash_status` and signature status, so a guess is never shown as a fact.
  - `coverage_gap` records mark periods where data may be missing; show them on every time-based view.
  - Unknown `record_type` or `schema_version`: quarantine the line with a reason, store the rest of the batch.
  - Unknown **optional fields** in a known record type and schema_version are accepted and kept (extra JSON column), never quarantined. The agent adds new types and optional fields without changing schema_version; only renames, removals or changed meanings increase it.
  - `inv_iis` carries a read status; show it, so a host whose IIS configuration couldn't be read is never shown as having no sites.

## Platform

- Ubuntu Server 26.04.1 LTS (M-029) in a VM on Unraid, disk on the Unraid array (hard drives only, no SSD; production hardware specified later, M-050), bridged network with the fixed IP 192.168.0.191 and no DNS name (M-024), internal network only. ufw allows 22, 443 and 8443 from 192.168.0.0/24 only (M-025).
- Two private CAs on the VM: the **agent CA** (agent client certificates and the 8443 server certificate) and the **web CA**, name-constrained to 192.168.0.0/24, for the 443 dashboard certificate (M-024, M-037).
- Apache 2.4, MariaDB 11.8 LTS, PHP 8.5, all from Ubuntu's own packages (no third-party repositories; M-029). MariaDB's data directory is `/var/lib/mariadb`, not `/var/lib/mysql`. Composer for dependencies; ask before adding any library and say why, with its licence and maintenance status.
- Background jobs (enrichment, alert rules, retention, summaries, backups) run as PHP CLI scripts from systemd timers, never from web requests.
- Everything needed to rebuild the server lives in this repo: app code, Apache vhosts, SQL migrations (`db/migrations/`), systemd units, and `docs/setup.md` (MS-OPS-07).

## Hard rules

- **Read-only towards hosts.** The mothership never sends commands that change a host; `/api/v1/config` only changes agent settings.
- **Ingest writes, the dashboard reads**, each with its own database user; no application connects as root (MS-SEC-04).
- **The detection list pushed to agents** (`/api/v1/config`, MS-ING-07) may hold only service names and HKLM registry keys, never file paths or commands. This is a security rule; never relax it.
- **Provisional record types** (not yet final in the agent) are quarantined with the reason "provisional type, no table yet" until the PM sends their formats; never dropped.
- **Agent identity is the client certificate**, never a value in the request body (MS-ING-02).
- **Prepared statements everywhere**, output escaping, CSRF tokens, secure session cookies (MS-SEC-05).
- **Command lines are sensitive:** analysts only, every view audited, cleared after 30 days *(confirm)* (MS-SEC-07..09).
- Never log private keys, enrolment tokens, passwords or session secrets (MS-SEC-10).
- No internet-facing ports during development or the pilot.

## Build and test

- PHP: PSR-12 style, `php -l` clean, PHPUnit tests for validation, deduplication, enrolment, retention and alert rules.
- SQL changes only as numbered migrations.
- An agent simulator (PHP CLI) sends realistic batches for every record type, including resends, malformed lines and unknown types, so milestones 1 to 6 need no Windows machine.
- Load tests report rows per second, p95 ingest time, database size per host per day, and dashboard query times.

## Milestones (one at a time; don't start the next until I confirm)

Order from milestone 2 on (M-052): 5, then 3, then 4, then 6 and 7.

1. **Server and contract:** VM setup, Apache, PHP, MariaDB, TLS, firewall, agent CA, `docs/setup.md`. Write `docs/ingest-api.md` and the database schema; wait for my review. Gate: contract and schema approved.
2. **Ingest:** endpoint with mTLS, validation, dedup, quarantine, acknowledgements; agent simulator. Gate: 2 simulated servers and 2 simulated laptops, p95 under 500 ms, 0 lost; report MB per host per day (MS-DAT-06). 500 hosts is verified on production hardware (M-050).
3. **Enrolment:** one-time tokens, CSR signing, revocation, check-in tracking. Gate: revoked agent refused, silent agent flagged.
4. **Retention:** daily partitions, retention, nightly summaries, health checks. Gate: on simulator data, partitions made 7 days ahead and dropped at retention, summaries match detail, each health check fires and clears (M-031). Backups (mariabackup, restore test, MS-OPS-01..04) moved to a "before ready to ship" item (M-026); the CA keys and app config are backed up before the pilot (M-031).
5. **Dashboard core:** sign-in, roles, audit log, fleet overview, host detail. Gate: every page under 5 s at 2 servers and 2 laptops, 7 days of simulator data; 500 hosts is verified on production hardware (M-050).
6. **Dashboard full:** connections, change history, fleet search, export; enrichment (DNS, threat lists; no GeoLite2, M-027). Gate: views match the simulator's data.
7. **Alerts and pilot:** alert rules and allow-lists; real agents connected once the agent's transport milestone is done. Gate: pilot go/no-go, privacy sign-off.

## How to work

- Before coding a milestone, give a short plan: files, approach, which requirement IDs it covers.
- After each milestone, report: what was built, how to run and test it, results, and which Must requirements are still open.
- Flag anything unclear instead of guessing, especially anything that would change the API contract the agent depends on.
- Record design decisions in `docs/decisions.md` (numbered M-001, M-002, ...) and keep `docs/status.md` up to date.
- Commit after each working step with a clear message. Never force-push or rewrite pushed history; undo with a new commit.
