# Project status

Last updated: 2026-11-18

Where the mothership stands. The spec is [mothership-plan.md](mothership-plan.md); decisions are in [decisions.md](decisions.md) (M-001 to M-042); open items are in [pending-decisions.md](pending-decisions.md) (none open); working rules are in [../CLAUDE.md](../CLAUDE.md).

## Pick up here

- **The ingest contract (v1) is approved** (M-017). Agent 5c can build against it; the collector address is 192.168.0.191.
- **The VM is built** (2026-11-13): Ubuntu Server 26.04.1 LTS at 192.168.0.191, user `aioadmin`, clean snapshot `M0-off`.
- **Server setup in progress:** `setup/01` to `08` and [setup.md](setup.md) (M-033 to M-039). 01 to 08 passed on the VM. The owner runs them over SSH one at a time and pastes the output back. Claude never logs in to the VM.
- **Next:** import the web CA on the PC and open the dashboard health page; then the milestone 1 report.
- **The schema is approved** (2026-11-18): `06-mariadb.sh` printed `SCHEMA GATE: PASS` on MariaDB 11.8.6 from Ubuntu (M-017). Milestone 1's gate (contract and schema approved) is met; 07 and 08 remain.
- **MPD-32 to 34 answered** (M-039 to M-041): no automatic reboot, with "reboot required" shown by 03 and the dashboard health page; MS-OPS-04 reworded; an `aio_analyst` database user for command lines, details before milestone 5.
- **The repo's plan copy and CLAUDE.md now match the decisions** (M-042), including the new MS-ING-12 (heartbeat, Must).

## Summary

| Milestone | Scope | State |
| --- | --- | --- |
| 1 | Server and contract | **Contract approved** (2026-11-13, M-017). **Schema approved** (2026-11-18, schema gate passed). All 8 setup scripts passed on the VM; the web CA import on the PC remains |
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

## Milestone 1: server setup (written 2026-11-13, running)

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

**Tested so far:** all of 01 to 08 on the VM (2026-11-18). There is no Ubuntu 26.04 machine here, so package names, paths and tool output are as documented for Ubuntu and checked by each script's own checks. The first runs may need small fixes.

**Results:**

| Step | Result |
| --- | --- |
| 01 SSH key | **PASS** (2026-11-13), 5/5 checks; key ED25519 `SHA256:eG/2d0I8FdK8FA+c0NMj8rnPtb4wWnL1G01bMq70ddU`. Fingerprint and key-only login confirmed from the PC |
| 02 SSH keys only | **Passed** (2026-11-13), per the owner: key login works, password login refused (`Permission denied (publickey)`). Script output not pasted |
| 03 OS updates | **PASS** (2026-11-18), 8/8 checks, no reboot required. During `full-upgrade`, `apt-get` sat paused (state `T`) for 25 min after `update-initramfs` for kernel 7.0.0-43; resumed with `kill -CONT`, then finished normally. Happened again in 06 (`apt-get install mariadb-server`, 20 min), so not a Ctrl+Z: apt touching the terminal under `sudo ... | tee`. Fixed after 06 started: every apt call now goes through `apt_get` in `lib.sh` (stdin from `/dev/null`, `Dpkg::Use-Pty=0`) |
| 04 Time | **PASS** (2026-11-18), 4/4 checks: Etc/UTC, chrony running, clock synchronised |
| 05 Firewall | **PASS** (2026-11-18), 8/8 checks: ufw active, deny incoming, 22, 443 and 8443 from 192.168.0.0/24 only, no other allow rules, enabled at boot. SSH session from 192.168.0.54 kept |
| 06 MariaDB | **PASS** (2026-11-18), 38/38 checks, including 14 grant checks (each user can do its job and nothing more, MS-SEC-04). MariaDB 11.8.6-MariaDB-5ubuntu0.1. apt paused once more during the install (before the fix), resumed with `kill -CONT` |
| 07 PKI | **PASS** (2026-11-18), 17/17 checks: key modes (MS-SEC-02), P-384 CAs and P-256 server keys, chains, the web CA's name constraint refuses `DNS:example.com` and `IP:10.0.0.1`. Agent CA and web CA valid to 2036-11-15; agent-api certificate to 2027-11-18, dashboard to 2027-12-20 |
| 08 Apache and PHP | **PASS** on the re-run (2026-11-18), 24/24 checks. First run: 23/24. All TLS, mTLS, pool and port checks passed (8443 TLS 1.3 only, client cert SUCCESS and NONE, TLS 1.2 refused, nothing on 80); `reboot_required` shown. **FAIL: Server header not just `Apache`.** Likely cause: Ubuntu's `security.conf` (ServerTokens OS) loads after `aio-mothership.conf` and overrides it. Fix: 08 disables `security.conf`; the check now ignores the header's CR. The re-run passed the header check (the original header value wasn't captured). No apt pause was reported (the `apt_get` fix). From the PC, `Test-NetConnection` to 443 and 8443 succeeded |
| Schema gate | **PASS** (2026-11-18): `db/migrations` applied cleanly. Schema approved (M-017) |

## Public fingerprints (from 07-pki.sh, 2026-11-18; not secret)

Also on the VM in `/etc/aio-mothership/pki/public/fingerprints.txt`. If 07 ever prints different values, the CA was replaced and every agent must be told.

| CA | Value | Used for |
| --- | --- | --- |
| Agent CA SHA-256 | `A2:C8:F1:30:80:F7:86:0C:4B:67:48:14:B5:DD:A3:D3:C4:AD:AE:13:C9:BC:64:A7:01:9E:9C:52:9C:AD:00:4E` | Pinned by agents, MSI property (M-002) |
| Web CA SHA-256 | `C1:C0:84:96:2F:A9:36:33:28:CD:61:C6:95:A6:9E:0F:0B:41:DE:BF:D3:E5:7C:0D:0C:DB:2B:F4:1A:4E:7E:89` | Checking the web CA |
| Web CA SHA-1 thumbprint | `2333E0D2359F712DCAFDB34E2EA72AD03697F8FF` | What Windows shows on import |

## Must requirements still open

All of them: nothing is built and tested yet.
- The contract and schema cover the design of MS-ING-01 to 06, 08, 09, 12 and MS-DAT-01, 02, 04, 05 (approved as design). MS-ING-11 (Should) and MS-ING-12 (Must, heartbeat) were added.
- The setup scripts, once run, will cover MS-SEC-01 (TLS, firewall), MS-SEC-02 (CA key access, as far as files go), MS-SEC-04 (database users) and MS-SEC-12 (automatic updates).
- MS-ING-10 and MS-DAT-06 are measured in milestone 2.

## Next steps

1. **Steps 0 to 8 are done;** the schema gate passed.
2. **The web CA import** on the PC (setup.md), then the health page in the browser.
3. **After 08:** import the web CA on the PC, and check `https://192.168.0.191/health.php`. Milestone 1 is then done; report it and wait for the go-ahead for milestone 2.
4. **Agent 5c can start now** from the approved contract (ingest-api.md §10), collector address 192.168.0.191.
5. **For the tech lead:** the repo's `mothership-plan.md` and CLAUDE.md were updated (M-042); copy the changes to the master plan if it is kept elsewhere.

## Before the pilot (M-031)

- An encrypted copy of the two CA keys and `/etc/aio-mothership/` (app config), kept off the VM (MS-OPS-02).

## Before ready to ship (M-026)

- Backups and a timed restore to a fresh VM: MS-OPS-01 to MS-OPS-04, plus the Unraid disk and off-box copy questions.
