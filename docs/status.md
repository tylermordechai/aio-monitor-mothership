# Project status

Last updated: 2026-11-18

Where the mothership stands. The spec is [mothership-plan.md](mothership-plan.md); decisions are in [decisions.md](decisions.md) (M-001 to M-042); open items are in [pending-decisions.md](pending-decisions.md) (none open); working rules are in [../CLAUDE.md](../CLAUDE.md).

## Pick up here

- **Milestone 1 is done** (2026-11-18). Waiting for the owner's go-ahead for milestone 2; nothing of milestone 2 is started.
- **The ingest contract (v1) is approved** (M-017). Agent 5c can build against it; the collector address is 192.168.0.191.
- **The schema is approved** (2026-11-18): `06-mariadb.sh` printed `SCHEMA GATE: PASS` on MariaDB 11.8.6 from Ubuntu (M-017).
- **The server is set up:** all of `setup/01` to `08` passed on the VM (Ubuntu Server 26.04.1 LTS, 192.168.0.191), run by the owner over SSH. The web CA is trusted on the owner's PC (thumbprint matched), and `https://192.168.0.191/health.php` loads with no certificate warning. Snapshot `M1-done` to be taken before milestone 2.
- **No decisions are open.** MPD-32 to 34 were answered as M-039 to M-041; the plan copy and CLAUDE.md match the decisions (M-042).

## Summary

| Milestone | Scope | State |
| --- | --- | --- |
| 1 | Server and contract | **Done** (2026-11-18). Contract approved (2026-11-13, M-017); schema approved (2026-11-18, schema gate passed); all 8 setup scripts passed on the VM; dashboard health page loads in the browser with the web CA |
| 2 | Ingest, simulator, load test | Not started; waiting for the go-ahead |
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

## Milestone 1 report (2026-11-18)

**What was built:**
- **The contract and schema:** [ingest-api.md](ingest-api.md) (v1, approved) and `db/migrations/0001_initial.sql` (applied cleanly, approved).
- **The server:** setup scripts `setup/01` to `08`, with templates and `grants.sql`, plus [setup.md](setup.md). Each script is idempotent and ends with PASS or FAIL checks.
- **Two health pages,** which read no database and no secret.

**How to run and test it:** follow [setup.md](setup.md) steps 0 to 8 on a fresh Ubuntu 26.04.1 VM. Each script's checks are the tests, and re-running a script re-runs its checks.

**Results on the VM (2026-11-18):**

| Step | Checks |
| --- | --- |
| 01 SSH key | 5/5 |
| 02 SSH keys only | passed (tested from the PC) |
| 03 OS updates | 8/8 |
| 04 Time | 4/4 |
| 05 Firewall | 8/8, plus 443 and 8443 reachable from the PC |
| 06 MariaDB and schema gate | 38/38, `SCHEMA GATE: PASS` |
| 07 PKI | 17/17 |
| 08 Apache and PHP | 24/24 (on the re-run) |
| Browser | `https://192.168.0.191/health.php` loads, showing `status ok`, TLS 1.3 and `reboot_required: false` |

**Fixed during setup:**
- **apt left stopped:** apt-get was paused (state `T`) under `sudo … | tee` in steps 03 and 06. All apt calls now go through `apt_get`, which gives apt no terminal. Step 08 then ran without a pause.
- **Server header:** Ubuntu's `security.conf` overrode `ServerTokens Prod`. Step 08 now disables it.
- **Lost logs:** `~/setup-logs` didn't exist, so `tee` saved nothing. The fix is `mkdir -p ~/setup-logs`, which is already in setup.md.

## Must requirements still open

- **Met by the setup, as far as milestone 1 goes:**
  - MS-SEC-01: TLS on both ports, TLS 1.3 only on 8443, client certificates from the agent CA, the firewall;
  - MS-SEC-02: the CA key's file access. Revocation comes with milestone 3;
  - MS-SEC-04: three database users with tested table-level grants;
  - MS-SEC-12: automatic security updates.
- **Designed and approved but not built:** MS-ING-01 to 06, 08, 09 and 12, and MS-DAT-01, 02, 04 and 05.
- **Measured in milestone 2:** MS-ING-10 and MS-DAT-06.
- **Every other Must** (enrolment, retention, dashboard, alerts, operations) is open, in milestones 2 to 7.
- **Only partly met:** MS-OPS-07 (rebuild from the repo). setup.md and the scripts cover the server; the app, timers and restore come later.

## Next steps

1. **The owner's go-ahead for milestone 2** (ingest, simulator, load test). A plan comes first: files, approach and requirement IDs.
2. **Before the milestone 2 gate:** resize the VM to the pilot size (4 vCPU, 16 GB, 250 GB), so the 500-host test measures the code.
3. **Agent 5c can start now** from the approved contract (ingest-api.md §10), collector address 192.168.0.191. Agents pin the agent CA SHA-256 shown above.
4. **For the tech lead:** copy the changes in the repo's `mothership-plan.md` and CLAUDE.md (M-042) to the master plan, if one is kept elsewhere.

## Before the pilot (M-031)

- An encrypted copy of the two CA keys and `/etc/aio-mothership/` (app config), kept off the VM (MS-OPS-02).

## Before ready to ship (M-026)

- Backups and a timed restore to a fresh VM: MS-OPS-01 to MS-OPS-04, plus the Unraid disk and off-box copy questions.
