# Project status

Last updated: 2026-11-23

Where the mothership stands. The spec is [mothership-plan.md](mothership-plan.md); decisions are in [decisions.md](decisions.md) (M-001 to M-058); open items are in [pending-decisions.md](pending-decisions.md) (MPD-35, MPD-37, MPD-38, MPD-39); working rules are in [../CLAUDE.md](../CLAUDE.md).

## Pick up here

- **Milestone 1 is signed off** (2026-11-19) by the owner. Snapshot `M1-done` taken with the VM shut down.
- **Milestone 2: signed off** (2026-11-20, M-051). 2 simulated servers and 2 laptops (M-050): p95 133 ms, 0 lost, 111.3 MB per host per day. No VM resize; the hardware stays as it is (M-050).
- **The ingest contract (v1) is approved** (M-017). Agent 5c can build against it; the collector address is 192.168.0.191.
- **The schema is approved** (2026-11-18): `06-mariadb.sh` printed `SCHEMA GATE: PASS` on MariaDB 11.8.6 from Ubuntu (M-017).
- **The server is set up:** all of `setup/01` to `08` passed on the VM (Ubuntu Server 26.04.1 LTS, 192.168.0.191), run by the owner over SSH. The web CA is trusted on the owner's PC (thumbprint matched), and `https://192.168.0.191/health.php` loads with no certificate warning. Snapshot `M1-done` taken.
- **Milestone 5 (dashboard core): signed off** (2026-11-23, M-058): every page under 5 s at 2 servers and 2 laptops with 7.9 days of data (slowest 416 ms). Report below.
- **Next: milestone 3 (enrolment)**, then 4 (retention) (M-052): plan first, for the owner's OK.
- **VM snapshots: removed** (2026-11-23): the VM runs from one raw disk, `vdisk1-flat.img`, checked identical to the chain. The old files stay in `old-chain/` for a few days (details in the milestone 2 section).
- **Milestone 3 (enrolment): started** (2026-11-23, M-059). Step 1, the signing service (M-060), is done: `setup/11-enrolment.sh` passed on the VM on 2026-11-24 (18 of 18). Step 2 (token CLI, CA export, M-061) done on the VM on 2026-11-24 (11: 32 of 32). Step 3 (`POST /api/v1/enroll`, M-062) done on the VM on 2026-11-24 (11: 43 of 43). Items 1 to 3 are ready for the agent's enrolment test (M-059). Step 4 (renew) next. MPD-42 answered (M-062). Open points MPD-40, MPD-41, MPD-43.
- **Open decisions:** MPD-35 (simulator volume), MPD-37 (disk and retention, pilot capacity), MPD-38 (TOTP, before the pilot), MPD-39 (agent milestone 5d record types).

## Summary

| Milestone | Scope | State |
| --- | --- | --- |
| 1 | Server and contract | **Signed off** (2026-11-19). Done 2026-11-18. Contract approved (2026-11-13, M-017); schema approved (2026-11-18, schema gate passed); all 8 setup scripts passed on the VM; dashboard health page loads in the browser with the web CA |
| 2 | Ingest, simulator, load test | **Signed off** (2026-11-20, M-051). Gate passed: p95 133 ms, 0 lost, 111.3 MB per host per day |
| 3 | Enrolment | **Started** 2026-11-23 (M-059): step 1, the signing service, done on the VM |
| 4 | Retention (backups moved to "before ready to ship", M-026) | Not started |
| 5 | Dashboard core | **Signed off** 2026-11-23 (M-058); gate passed, slowest page 416 ms |
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

## Milestone 2: ingest (built and deployed 2026-11-19)

**What was built** (decisions M-043 to M-049):

| Part | Files | Requirements |
| --- | --- | --- |
| Agent API front controller, routing, §7 errors | `public/agent/index.php`, `src/Api/AgentApi.php`, `src/Http/*`, vhost `FallbackResource` | MS-ING-01, 09, M-048 |
| Identity from the client certificate; check-in on every request | `src/Auth/ClientCertAuth.php` | MS-ING-02, 08, MS-SEC-02 |
| gzip with limits (zip-bomb guard), line split | `src/Ingest/BatchReader.php` | MS-ING-01, 09 |
| Envelope checks, rules 1 to 8 | `src/Ingest/LineValidator.php` | MS-ING-02, 05 |
| Fields to columns, rule 9, `extra`, IP columns, item keys | `src/Ingest/RowBuilder.php`, `ItemKeys.php`, `RecordTypes.php`, generated `Schema/table_map.php` | MS-ING-05, M-010, M-012 |
| One transaction, INSERT IGNORE with the warning check, upserts, quarantine, rollback check, ack after commit | `src/Ingest/Writer.php`, `IngestService.php` | MS-ING-03, 04, M-005, M-014, M-015 |
| Back-pressure: slots, per-agent rate, disk | `src/Ingest/Throttle.php` | MS-ING-09, MS-OPS-08 |
| Heartbeat | `src/Heartbeat/HeartbeatService.php` | MS-ING-12 |
| Migration 0002: rollback-safe keys on two tables | `db/migrations/0002_lifecycle_link_index_keys.sql` | MS-ING-03, M-046 |
| Grants and credential modes | `setup/sql/grants.sql`, `setup/06-mariadb.sh` | MS-SEC-04, M-045, M-047 |
| App setup and API smoke test | `setup/09-app.sh`, `setup/templates/app.ini` | MS-OPS-07 |
| Simulated agents, simulator, 0-lost check, figures | `tools/dev-agents.php`, `tools/simulator/simulate.php`, `src/Sim/*`, `tools/reconcile.php`, `tools/measure.php` | MS-ING-10, MS-DAT-06, M-049 |
| Tests | `tests/Unit` (66, pass on the PC), `tests/Integration` (12, need `aio_test` on the VM) | CLAUDE.md |

**On the VM (2026-11-19):** 06 and 08 re-run, PASS (migration 0002, grants, credential modes, vhost route). 09: first run 16/17, one integration test wrong (it expected MariaDB's `JSON_VALUE` to return `'true'` for a JSON true; it returns `'1'`), fixed in the test. Re-run: **17/17 PASS**: API smoke checks (401, 403, 405, 404), 66 unit tests, 12 integration tests (3,612 assertions) on PHP 8.5.4 and MariaDB 11.8.


**First simulator run (2026-11-19, 10 agents, 120 s, faults on):**
- 139 requests (119 ingest, 20 heartbeats), all 200; 4,678 lines acknowledged; reconcile: **0 lost**, 0 extra, every table matching.
- Ingest time: simulator (client) p50 76 ms, p95 360 ms, p99 508 ms; Apache p95 355 ms; PHP to COMMIT p50 14 ms, p95 258 ms. Under 500 ms, but high for 1 request per second; being looked into before scaling up.
- 1 answer mismatch, a simulator bug: it counted the seq of a deliberately broken (non-JSON) line towards the expected `acked_seq`, which the server can't read. Fixed in `SimAgent`.

**Gate run (2026-11-19/20, M-050: 2 servers and 2 laptops, 3,600 s, faults on):**
- Reconcile: **0 LOST**, 0 extra rows, 4 agents, all 26 tables matching (e.g. 25,185 net_flows, 1,912 process_events, 956 command lines, 740 services, 10 quarantined fault lines).
- Server and laptop inventories as expected: 360 apps (2 × 60 + 2 × 120), 740 services (2 × 120 + 2 × 250), IIS, SQL Server and roles on the 2 servers only.
- Server times (`measure.php latency`, 02:07:43 to 03:07:43 UTC): 1,428 ingest batches, all 200, 31,844 rows stored (9 per s).
  - PHP to COMMIT: p50 9 ms, **p95 71 ms**, p99 127 ms, max 351 ms.
  - Apache, whole request including TLS and upload: p50 64 ms, **p95 128 ms**, p99 201 ms.
- Simulator (as the agents saw it, the gate figure, M-049): 1,668 requests (1,428 ingest, 240 heartbeats), all 200; 32,016 lines acknowledged; 0 unsent; 0 answer mismatches.
  - Ingest: p50 68 ms, **p95 133 ms**, p99 205 ms, max 397 ms. Heartbeat: p50 40 ms, p95 91 ms.
- **Gate (M-050): PASS.** p95 133 ms, under 500 ms, and 0 lost. MB per host per day: next, from a `--day` run.

**Day run (2026-11-20, `--agents=4 --laptops=2 --day`, fresh agents):** 24 hours of records per agent, sent back to back.
- 638 requests, all 200; **738,520 lines** (about 185,000 per host per day) in about 2 minutes, 6,192 lines per s; 16 MB compressed sent; 0 unsent; 0 mismatches.
- Ingest p50 538 ms, p95 1,056 ms, max 2,012 ms. These are catch-up batches of about 1,160 lines each, like an agent emptying its buffer after an outage, not the 10 s batches the gate measures. 6,192 lines per s is about 140 times the 4 hosts' live rate.
- Reconcile: **0 LOST**, 0 extra rows, 4 agents.
- Size (`measure.php size`, 1.00 day, 4 hosts): **111.3 MB per host per day** in total; **servers 120.0, laptops 101.8** (table sizes shared out by rows).
  - `net_flows` is 85 % of it: 95.0 MB per host per day, 592,941 rows (about 148,000 per host per day, about 670 bytes per row with indexes).
  - Then `process_events` 6.3, `snap_tcp_conns` 5.4, `snap_listeners` 1.1, `snap_udp_endpoints` 0.9, `process_command_lines` 0.6, `executables` 0.6, `agent_status` 0.5 (MB per host per day).
  - The plan's estimate was 25 MB per host per day; this is 4.5 times that, on the simulator's estimated volume (MPD-35). See MPD-37.

**Ingest benchmark (2026-11-19, `tools/bench-ingest.php 10`, no HTTP, `aio_test`):**

| Phase (warm median, ms) | Inventory (193 lines, 15 tables) | Flows (24 lines) |
| --- | --- | --- |
| auth (includes its own check-in commit) | 7.3 | 7.4 |
| read, setup, finish | 2.4 | 2.1 |
| PHP validation and row building (total minus the rest) | about 9 | about 11 |
| db (row statements) | 131.6 (range 95 to 200) | 4.8 (range 4.6 to 64) |
| commit | 46.4 | 42.6 (range 22 to 101) |
| total | 189.9 | 65.5 |

Server: `innodb_flush_log_at_trx_commit = 1`, buffer pool 3,072 MB, binlog off.
- **PHP isn't the cost:** about 10 ms per request.
- **COMMIT takes 22 to 101 ms** even for 24 lines. That's the disk sync of the redo log, which a fast SSD does in under 1 ms, so the VM's disk sync is slow. Turning the sync off (`flush_log_at_trx_commit = 2`) isn't an option: an acknowledged batch could be lost in a power cut, after the agent has deleted it.
- **Random stalls** in every phase (auth 97 ms, flows db 64 ms) on an otherwise idle VM point at the host: either the disk or CPU contention on Unraid.
- Next: measure the disk sync on its own (`tools/fsync-test.php`) and CPU steal (`vmstat`), then check the Unraid disk settings during the resize.

**Disk check (2026-11-19):**
- `/var/lib/mariadb` and the home directory are both on `/dev/vda2` (a VirtIO disk, 97 GB).
- `fsync-test.php`, 200 syncs of 4 KB: **p50 17.2 ms, p95 45.7 ms, p99 64.3 ms, max 97.5 ms.** An SSD does this in under 1 ms.
- `vmstat`: CPU 95 to 100 % idle, steal (`st`) 0, wait (`wa`) 0 to 5 %.

**Unraid disk definition (2026-11-19):**
- VirtIO bus, `cache='writeback'`.
- The disk is a chain of 3 qcow2 snapshot files over `vdisk1.img` (raw): `m1-done`, then `m1-ssh`, then `m0-off`.
- Every file is reached through `/mnt/user/`, Unraid's user-share (FUSE) layer.
- Both the snapshot chain and the `/mnt/user` path are known to make disk syncs slow.

- The `domains` share: primary storage **Array**, no secondary storage, no mover action. The VM disk sits on the parity-protected hard-drive array, not on the SSD cache pool the platform assumes (CLAUDE.md, Platform). Each sync waits for a data-disk write plus a parity write.

- Unraid host: no SSD and no pool. Ten 1.2 TB 10k SAS hard drives (HGST HUC101812CSS205), seven of them array data drives; the VM files are on `disk1`.
- **Decided (M-050):** the hardware stays as it is; the milestone 2 gate is 2 servers and 2 laptops; 500 hosts is verified on production hardware; snapshots are merged once there is a working, readable dashboard.

**Snapshot chain removed (2026-11-23, M-050, M-058):** run by the owner on the Unraid host, with the VM shut down.
- The chain (`m1-done` 6.3 GiB, then `m1-ssh` 2.1 GiB, then `m0-off` 173 MiB, over `vdisk1.img`, raw, 6.0 GiB; none corrupt) was copied into one sparse raw file, `vdisk1-flat.img`, with `qemu-img convert -O raw`. `qemu-img compare` against the chain: **Images are identical.**
- The VM definition changed in two lines only (checked with `diff`): the driver `qcow2` became `raw`, and the source became `vdisk1-flat.img`. `writeback`, `discard`, VirtIO and the PCI address are unchanged. The before and after XML (root-only) and Unraid's `snapshots.db` are in `old-chain/` next to the disk. Defining `aio-mothership-before.xml` again puts the old chain back.
- After the start, `hdc` is `vdisk1-flat.img`. mariadb, apache2 and php8.5-fpm are active, with no failed units. `/` is 97 GB, 13 GB used.
- Row counts: net_flows 6,904,203, process_events 533,402, executables 13,141, flow_conn_hourly 12,250, last batch 14,757. All are a little above gate run 2, because batches arrived between the gate run and the shutdown.
- The owner reported everything ready afterwards. The `10-dashboard.sh` and `fsync-test.php` output wasn't pasted, so the fsync figure after the change isn't recorded yet.
- Still to do (owner): move the four old chain files into `old-chain/` after a few days, then delete it (about 15 GB). The disk is still reached through `/mnt/user` and is on the parity-protected array (M-050).

**Finding:** the slow ingest commits come from the disk under the VM, not from the CPU or the code. The cause is on the Unraid side (where the vDisk is stored and its format); it'll be checked and fixed during the resize, before the gate.

**Before the VM:** unit tests and `php -l` on the PC with a portable PHP 8.5.13 (`C:\dev\tools\php`, not in the repo). Nothing has run against MariaDB or Apache yet.

**Found while building:**
- **Rollback records could be dropped:** 0001 keyed `agent_lifecycle` and `inventory_link_index` on `(agent, seq)`, which would have treated a rolled-back `agent_start` as a resend. Fixed by migration 0002 (M-046).
- **§3.4 rule order:** checking "unknown type" before "provisional" would make the provisional list useless. Provisional is checked first (M-045). The contract text is unchanged; the intent is the same.

## Milestone 3: enrolment (started 2026-11-23, M-059)

**Gate:** a revoked agent is refused, and a silent agent is flagged. **Build order (M-059):** the signing service, the token CLI and `POST /api/v1/enroll` first, so agent 5c can test enrolment early. Open points: MPD-40, MPD-41, MPD-43 (MPD-42 answered, M-062).

| Step | What | Files | Requirements | State |
| --- | --- | --- | --- | --- |
| 1 | Signing service (M-060) | `bin/signer.php`, `src/Pki/*`, `setup/templates/aio-signer.*`, `setup/11-enrolment.sh`, `tools/signer-check.php` | MS-SEC-02 | **Done** 2026-11-24. 99 unit tests pass on the PC and the VM (13 new); 09 passed (17 of 17, 22 integration tests). 11 passed, 18 of 18 (OpenSSL 3.5.5): key and socket permissions, only aio-ingest reaches the signer, the signer refuses root, a good CSR signed with the §2.3 profile and chained to the agent CA, RSA / tampered / wrong CN refused, the log has the signing and no CSR or key. The first VM run of the unit tests failed: the test helper's minimal openssl.cnf (needed on Windows) made PHP 8.5 on Linux refuse EC keys ("configured to 0"); fixed in the test only (c0475eb) |
| 2 | Token CLI, CA export (M-061) | `bin/token-create.php`, `bin/agent-ca.php`, `src/Enrol/*`, `src/Pki/CaExport.php`, migration 0005 | MS-SEC-03, MS-SEC-08, MS-SEC-10, M-021, M-059 | **Done** 2026-11-24. 115 unit tests pass on the PC (16 new), 4 new integration tests. 11 passed, 32 of 32 at e0022d8: migration 0005, agent-ca.php matches fingerprints.txt and works without sudo, a token stored only as its SHA-256 (manual, 24 h, cli:aioadmin), audited without the token, list, cancel, second cancel refused, 8-day deployment token refused, CLI needs root. The 06 and 09 output wasn't pasted (0005 applied, so 06 ran). Agent PM: `--ca-sha256` accepts either form |
| 3 | `POST /api/v1/enroll` (M-062) | `src/Enrol/EnrolService.php`, `src/Pki/CertSigner.php`, `AgentApi`, `setup/sql/grants.sql`, `tools/enrol-check.php` | MS-ING-06, MS-SEC-02, MS-SEC-03, MS-SEC-08, MS-SEC-10 | **Done** 2026-11-24. 11 passed, 43 of 43 at 4899a46: aio_ingest INSERT-only on audit_log; a live enrolment over 8443 (201, chain, CA, group, renew_after), a heartbeat with the new certificate (200), the used token refused (403), a second token for the same ID (409), the agent revoked and its heartbeat refused (403 agent_revoked), the throwaway agent removed. The 06 and 09 output (32 integration tests with `EnrolTest`) wasn't pasted; asked for |
| 4 | `POST /api/v1/renew` | | MS-ING-11 | Next |
| 5 | `GET /api/v1/config` | | MS-ING-07 | |
| 6 | Revoke and unrevoke | | MS-SEC-02 | |
| 7 | Admin enrolment page (MPD-40) | | MS-SEC-07 | |
| 8 | Silent agents flagged | | MS-ING-08, MS-UI-01 | |
| 9 | CRL (MPD-41) | | MS-SEC-02 | |
| 10 | Simulator enrols for real; gate | | the gate | |

## Milestone 5: dashboard core (signed off 2026-11-23, M-058)

Plan approved 2026-11-22 with the owner's answers (M-053, M-054): Cytoscape.js for the map, admin includes analyst, no TOTP in this milestone (MPD-38, before the pilot), defaults 12 characters / 30 min idle / 12 h / stale after 5 min, `tools/users.php unlock`, 15 internal nodes plus "other internal".

| Step | What | Files | Requirements | State |
| --- | --- | --- | --- | --- |
| 1 | Sign-in, sessions, CSRF, roles, audit, accounts; analyst DB user, narrowed dashboard grants | `src/Web/*`, `public/dashboard/index.php`, `templates/`, `tools/users.php`, `setup/sql/grants.sql`, `setup/06-mariadb.sh`, vhost and pool templates | MS-SEC-04..08, 10 | Built; on the VM the owner signed in from Edge (2026-11-22, after the M-055 fix) |
| 2 | Connections per hour (migration 0003) and its timer; simulated hosts talk to each other; `--days=N` | `db/migrations/0003_flow_conn_hourly.sql`, `src/Jobs/FlowRollup.php`, `tools/jobs/flow-rollup.php`, `src/Sim/Network.php` | MS-DAT-04, MS-UI-08 | Built; waiting for the VM |
| 3 | Server map and fleet table; `setup/10-dashboard.sh` | `src/Dashboard/*`, `templates/home.php`, `public/dashboard/assets/` | MS-UI-01, 07 | Works on the VM; owner looked 2026-11-23: 4 hosts, lines right, nothing missing |
| 4 | Host page: connections, listening ports, processes (command lines for analysts, audited), executables, inventory, server software; gaps on every view; tab bar leaves room for Security, Shares, Health, Network and Sign-ins (MPD-39) | `src/Dashboard/HostQuery.php`, `Labels.php`, `CommandLineReveal.php`, `templates/host*.php`, `templates/command-line.php` | MS-UI-02, 07, MS-SEC-07..09, MS-DAT-04, 05 | Built (M-056); on the VM 2026-11-23 `09`: 17 of 17, unit 86 pass, integration 22 pass (3,969 assertions; after fixing one test bug, a static closure using $this). Command-line reveal works on the VM: the owner (admin) opened one on SIM-0002; one `view_command_line` audit row (target `20:2272917`; details host, PID, process name, result "shown"; no command line in the row). `10`: 31 of 31 (adds: signed out, a host tab and a command-line POST go to sign-in). Owner looked and OK'd step 5 (2026-11-23) |
| 5 | Page timing (`tools/page-times.php`) and the gate run | `tools/page-times.php`, `db/migrations/0004_host_page_indexes.sql` | MS-UI-08 (M-050) | Run 1, 2026-11-23 16:4x UTC (4 hosts, 7.8 days, 6.9 M flow rows): **FAIL**, processes tab 7 days 16.3 s; executables 1.8 s; every other page under 100 ms. Fixed in M-057 (migration 0004, query rewritten). Run 2: **PASS**, slowest page 416 ms |

- Unit tests on the PC: 86 pass (8 new for the host page: labels, port owners, every tab rendered and escaped, no command line or reveal button for viewers). Integration tests (sign-in and lockout, the hourly table, the map, and the host page queries and audited reveal) run on the VM in `09`.
- 2026-11-23, gate run 1 (`sudo php tools/page-times.php tyler`, 3 runs per page, live simulator running): 4 hosts, 7.8 days of flows (2026-11-15 21:10 to 2026-11-23 16:43 UTC), net_flows 6,860,998 rows, flow_conn_hourly 12,110, process_events 530,114, executables 13,057. 54 URLs x 3 in 32 s. **GATE FAIL.**

  | Page | URLs | p50 ms | max ms | server max | Slowest |
  | --- | ---: | ---: | ---: | ---: | --- |
  | home | 3 | 4 | 4 | 2 | `/?range=1h` |
  | map data | 3 | 15 | 96 | 94 | `/api/map?range=7d` |
  | host: connections | 12 | 16 | 83 | 82 | 7 d |
  | host: listening | 4 | 6 | 6 | 5 | |
  | host: processes | 12 | 381 | **16,346** | 16,341 | 7 d |
  | host: executables | 4 | 216 | 1,814 | 1,812 | |
  | host: inventory | 12 | 17 | 45 | 43 | 7 d |
  | host: software | 4 | 9 | 9 | 8 | |

  Fixed in M-057 (migration 0004 indexes; processes query limits before it joins; counts capped).
- 2026-11-23, after M-057: `06` applied migration 0004 (`10` checks it); `09` 17 of 17 (unit 86, integration 22, 3,959 assertions); `10` 32 of 32.
- 2026-11-23, gate run 2 (same command, live simulator running): 4 hosts, 7.9 days of flows (2026-11-15 21:10 to 2026-11-23 17:35 UTC), net_flows 6,882,853 rows, flow_conn_hourly 12,180, process_events 531,782, executables 13,101. 54 URLs x 3 in 7 s. **GATE PASS: slowest page 416 ms** (processes, 7 days), server side 412 ms.

  | Page | URLs | p50 ms | max ms | server max | Slowest |
  | --- | ---: | ---: | ---: | ---: | --- |
  | home | 3 | 3 | 4 | 2 | `/?range=1h` |
  | map data | 3 | 14 | 95 | 94 | `/api/map?range=7d` |
  | host: connections | 12 | 16 | 84 | 82 | 7 d |
  | host: listening | 4 | 5 | 7 | 6 | |
  | host: processes | 12 | 56 | 416 | 412 | 7 d (was 16,346) |
  | host: executables | 4 | 34 | 34 | 31 | (was 1,814) |
  | host: inventory | 12 | 17 | 23 | 22 | 7 d |
  | host: software | 4 | 9 | 9 | 8 | |
- 2026-11-22, first sign-in on the VM: curl signed in fine, Edge always got "the sign-in form had expired". Edge sent `Origin: null` (Chromium does under `Referrer-Policy: no-referrer`) and the CSRF check refused it. Fixed in M-055: referrer policy `same-origin`, and `Origin: null` accepted only with `Sec-Fetch-Site: same-origin` (token still required). `10` now checks both. Unit tests: 78 pass (4 new CSRF cases).
- 2026-11-23 04:38 UTC, `setup/10-dashboard.sh` at `0d1b130`: **29 passed, 0 failed** (grants and credential files, sessions directory, templates outside the web root, Cytoscape.js SHA-256, flow-rollup timer and job, sign-in page, signed-out redirects and 401, cookie flags, CSRF refusal, CSP, referrer policy and the Edge-style post (M-055), no caching, assets, health page). One admin account.
- 2026-11-23, after the `--days=7` load: `flow-rollup.php --all` found nothing left to do (the timer had caught up, up to batch 9949, the last batch). `flow_conn_hourly`: 13,580 rows, 2026-11-15 21:00 to 2026-11-22 21:00 (7 days, ending when the load ran), 8 host IDs: the 4 from the `--days=7` load plus 4 left over from milestone 2. `tools/dev-agents.php delete` removed those hosts but not their `flow_conn_hourly` rows (the table has no agent column); the map ignores them (it draws only hosts that exist) and showed 4 hosts, as it should. Fixed: `delete` now also clears tables keyed by host only. The leftover rows are removed once by hand.

## Milestone 5 report (2026-11-23)

**What was built:** the dashboard on 443 (decisions M-053 to M-057).
- **Sign-in:** local accounts (Argon2id, at least 12 characters, a 15-minute lock after 5 failures, all *(confirm)*), PHP file sessions with a `__Host-` cookie, 30 minutes idle and 12 hours in all *(confirm)*, CSRF token plus Origin and Sec-Fetch-Site checks (M-055 for Edge), a strict CSP, no caching.
- **Roles and accounts:** viewer < analyst < admin; `tools/users.php` (add, list, role, password, unlock, disable, enable), every change audited.
- **Audit log:** sign-ins, failures, lockouts, sign-outs, account changes and every command-line view, written by database users that can only insert.
- **Database users:** `aio_dashboard` reads everything but command lines and writes only users and audit; `aio_analyst` reads only command lines and writes only audit (M-053).
- **Connections per hour:** `flow_conn_hourly` (migration 0003), kept by a timer job every minute (1 to 3 minutes behind, shown on the pages).
- **Server map and fleet table:** hosts with status and last check-in, lines between hosts, the 15 busiest internal addresses, one "internet" cloud, coverage gaps (Cytoscape.js 3.34.4, vendored, checksum recorded).
- **Host page:** connections first (recent from the hourly table, live from the latest snapshot), listening ports, processes, executables, inventory and server software; coverage gaps on every tab; every "how we know" field shown as fact, likely, guess or unknown; command lines only through an audited "Show" for analysts and admins; room for the agent's 5d tabs (MPD-39).
- **Tools:** `tools/page-times.php` (the gate), simulator `--days=N` and `--laptops=N`, simulated hosts that talk to each other.

**How to run and test it:** `docs/setup.md`, "Step 10: the dashboard" and "Milestone 5: 7 days of simulator data" (with "The gate: page times"). Unit tests: `php vendor/bin/phpunit --testsuite unit` (86, also on the PC). Integration tests run in `setup/09-app.sh` on the VM (22, against `aio_test`). `setup/10-dashboard.sh` checks grants, files, the timer and the HTTPS security behaviour (32 checks).

**Results:**

| Gate (M-050) | Needed | Result |
| --- | --- | --- |
| Data | 2 simulated servers and 2 laptops, 7 days | 4 hosts, 7.9 days: 6.9 M flow rows, 532 k process events, 13 k executables |
| Every page under 5 s | yes | **PASS**: slowest 416 ms (processes, 7 days); 54 URLs x 3 runs |
| Checks | | `09` 17/17 (86 unit, 22 integration tests), `10` 32/32 |

- Found and fixed on the way: Edge sign-in refused under `no-referrer` (M-055); the processes tab at 16.3 s, fixed with indexes and a limit-first query (M-057); simulated-agent deletion left hourly rows behind (fixed in `dev-agents.php`).
- Seen by the owner: the map (4 hosts, lines right, nothing missing), the host page, a command line shown and its audit row.

**Must requirements still open after milestone 5:**
- **MS-UI-08 at 500 hosts:** met at the M-050 gate (4 hosts); 500 hosts is verified on production hardware.
- **MS-UI-01:** open alerts by severity show as "not built yet" (milestone 7).
- **MS-SEC-06:** the optional TOTP second factor (MPD-38, before the pilot).
- **MS-SEC-07:** exports (analysts) and the admin pages for users and enrolment come with milestones 6 and 3; users are managed with `tools/users.php` for now.
- **MS-SEC-08:** export audit comes with exports (milestone 6); keeping the log 2 years is retention (milestone 4).
- **MS-SEC-09:** clearing command lines after 30 days *(confirm)* is retention (milestone 4).
- **MS-UI-03 to 06** (connections view with filters and enrichment, change history, alerts queue, fleet search and export): milestones 6 and 7.

## Milestone 2 report (2026-11-20)

**What was built:** the agent ingest API on 8443 (mTLS identity, validation, dedup, quarantine, one transaction per batch, acknowledgement after commit, back-pressure, heartbeat), migration 0002, the app setup script 09, and the simulator with reconcile, measure, bench and fsync tools. Table above; decisions M-043 to M-050.

**How to run and test it:** `docs/setup.md`, "Milestone 2: the simulator". Unit tests: `php vendor/bin/phpunit --testsuite unit` (67, also on the PC); integration tests run in `setup/09-app.sh` on the VM (12, against `aio_test`).

**Results:**

| Gate (M-050) | Needed | Result |
| --- | --- | --- |
| Hosts | 2 simulated servers, 2 simulated laptops | 4 agents, 1 hour, faults on |
| p95 ingest time (as the agents saw it) | under 500 ms | **133 ms** (server side: Apache 128 ms, PHP 71 ms) |
| Lost | 0 | **0**, all 26 tables matching; 0 again after the day run (738,520 lines) |
| MB per host per day (MS-DAT-06) | reported | **111.3** (servers 120.0, laptops 101.8), on the simulator's estimated volume |

- Every request answered 200; 10 fault lines quarantined as designed; resends counted as duplicates; 0 answer mismatches.
- Throughput seen: about 6,200 lines per s on catch-up batches of 1,160 lines.
- **Hardware found:** no SSD on the Unraid server; the VM disk is on the hard-drive array (sync p50 17 ms). The gate still passes with room to spare. Kept as it is (M-050).

**Open from milestone 2:**
- **MPD-37:** what the 111 MB per host per day means for disk and retention (decide before milestone 4).
- **MPD-35:** the simulator's volume is an estimate. Compare with the real agent on the dev machine once agent 5c can send.
- MS-ING-10 and MS-UI-08 at 500 hosts: on production hardware (M-050).

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
- **Met in milestone 2:** MS-ING-01 to 05, 08, 09 and 12; MS-DAT-01 (migrations 0001, 0002); MS-DAT-06 (measured: 111.3 MB per host per day; the disk sizing is MPD-37).
- **Met at the M-050 gate, open at 500 hosts:** MS-ING-10 (p95 133 ms at 4 hosts), verified on production hardware.
- **Met in milestone 5:** MS-DAT-01 (migrations 0003, 0004), MS-DAT-04 (flows combined by the D-016 key), MS-DAT-05 (every source field shown as fact, likely, guess or unknown), MS-UI-02, MS-UI-07, MS-UI-09 (desktop browser, tested in Edge), MS-SEC-05, MS-SEC-10; MS-SEC-04 for the dashboard and analyst users. Partly: MS-UI-01 (alerts in milestone 7), MS-SEC-06 (TOTP, MPD-38), MS-SEC-07 and 08 (exports and admin pages later; 2-year keep is retention), MS-SEC-09 (the 30-day clearing is retention).
- **Met at the M-050 gate, open at 500 hosts:** MS-UI-08 (every page under 5 s at 4 hosts, 7.9 days), verified on production hardware.
- **Not built yet:** MS-ING-06 and 07 (milestone 3); MS-DAT-02 and 03 (milestone 4).
- **Every other Must** (enrolment, retention, dashboard, alerts, operations) is open, in milestones 3 to 7.
- **Only partly met:** MS-OPS-07 (rebuild from the repo). setup.md and the scripts cover the server; the app, timers and restore come later.

## Next steps

1. **Milestone 3 (enrolment): in progress** (M-059). Step 1 (the signing service) passed on the VM on 2026-11-24; step 2 (token CLI, CA export) done on the VM; step 3 (the enrol endpoint) done on the VM; next step 4 (renew). Then milestone 4 (retention).
2. **Snapshots: removed** (2026-11-23). The owner moves the old chain files into `old-chain/` after a few days and deletes them later. The fsync figure after the change is still to be measured (`php tools/fsync-test.php`).
3. **Open, for later:** MPD-37 (disk sizing and pilot capacity), MPD-35 (simulator volume).
4. **Agent 5c can start now** from the approved contract (ingest-api.md §10), collector address 192.168.0.191. Agents pin the agent CA SHA-256 shown above.
5. **For the tech lead:** copy the changes in the repo's `mothership-plan.md` and CLAUDE.md (M-042, M-050) to the master plan, if one is kept elsewhere.

## Before the pilot (M-031)

- An encrypted copy of the two CA keys and `/etc/aio-mothership/` (app config), kept off the VM (MS-OPS-02).
- Two-step sign-in (TOTP) for the dashboard (MPD-38).

## Before ready to ship (M-026)

- Backups and a timed restore to a fresh VM: MS-OPS-01 to MS-OPS-04, plus the Unraid disk and off-box copy questions.
