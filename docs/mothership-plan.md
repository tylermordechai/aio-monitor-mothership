# Mothership plan

Copy of the "Mothership plan" tab in the live project plan (https://claude.ai/artifact/XmMXUfbW9r9gePPc4H3L7Z), saved 2026-11-07. The live doc is the source of truth; ask the user to refresh this file after changes.

## Scope and assumptions

The mothership is a single Ubuntu VM on Unraid running Apache, PHP and MariaDB. It receives every agent's data, keeps it long-term, and serves the dashboard; agents delete their copy once the mothership confirms receipt.

- **In scope:** ingest API, agent enrolment and certificates, database, enrichment of remote IPs, alert rules, dashboard, backups and health monitoring.
- **Out of scope for v1:** high availability (one VM; agents buffer during outages), multi-site, changing anything on the hosts (read-only, like the agent).
- **Requirement IDs:** MS-ING (ingest), MS-DAT (data), MS-ENR (enrichment), MS-ALR (alerts), MS-UI (dashboard), MS-SEC (security), MS-OPS (operations). Priorities Must / Should / Could, as in the agent spec.

| Assumption | Default | Status |
| --- | --- | --- |
| Fleet size | 50 servers in the pilot; designed and load-tested for 500 | *(confirm)* |
| Detailed data kept (flows, connections, process events) | 90 days | *(confirm)* |
| Daily summaries kept | 2 years | *(confirm)* |
| Command lines kept | 30 days, then removed from records | *(confirm)* |
| Host OS for the VM | Ubuntu Server 26.04.1 LTS | Decided (M-029) |
| Where it runs | VM on Unraid, on the internal network only | Decided |
| Stack | Apache 2.4, PHP 8.5, MariaDB 11.8 LTS, all from Ubuntu's own packages | Decided (M-029) |

## Architecture

Everything runs on one VM: Apache serves the ingest API to agents and the dashboard to people, MariaDB is the only long-term store, and timed jobs do the slow work in the background.

```
Windows servers (50-500)          Unraid VM: Ubuntu, Apache, PHP, MariaDB                 Outside the VM
+------------------+        +------------------------+   +----------------------+
| Endpoint agent   |------->| Ingest API             |   | Dashboard            |<------> Analyst browser
| sends batches    |  mTLS  | :8443, mutual TLS      |   | :443, sign-in        |         (signed-in users)
| deletes after ack|        | dedup, quarantine      |   | roles, audit log     |
+------------------+        +-----------+------------+   +----------^-----------+
                                        | writes                    | reads
                            +-----------v-------------------------------------+
                            | MariaDB: the only long-term store               |-------> Backup share
                            | daily partitions, 90 days of detail             |         (Unraid array
                            | daily summaries kept 2 years                    |          + off-box copy)
                            +-----------^-------------------------------------+
                                        | reads and writes
                            +-----------v-------------------------------------+
                            | Background jobs                                 |<------- Outside feeds
                            | enrichment: DNS, reputation                     |         (threat IP
                            | alert rules every minute                        |          lists)
                            | nightly retention, summaries                    |
                            +-------------------------------------------------+
```

Ingest only writes and the dashboard only reads, each with its own database user, so a dashboard bug can't change stored data and an agent can never read it.

## Unraid VM and server setup

Start the pilot VM at 4 vCPU, 16 GB RAM and a 250 GB disk on Unraid's SSD cache pool; the disk size is an estimate until milestone 2 measures real data per host per day.

| Item | Pilot (50 hosts) | Full (500 hosts) | Why |
| --- | --- | --- | --- |
| vCPU | 4 | 8 | PHP ingest workers and nightly jobs |
| RAM | 16 GB | 32 GB | Most goes to MariaDB's buffer pool (about 60% of RAM) |
| Database disk | 250 GB | 1.5 TB | Estimate: about 25 MB per host per day of detail × 90 days, plus summaries and indexes *(replace with measured figure in milestone 2)* |
| Backup space | 2× database size | 2× database size | On an Unraid array share, outside the VM |

**Development VM (M-050):** 2 vCPU, 8 GB RAM and a 100 GB disk on the Unraid array (the server has hard drives only, no SSD), kept as it is through development and the pilot. The sizes in the table are for production hardware, specified before production with the backup work (M-026); MS-ING-10 and MS-UI-08 at 500 hosts are verified there.

**Unraid settings**

- Put the VM's disk on the **cache pool (SSD/NVMe)**, not the parity array. Database writes on the array are slow and wear parity drives.
- Network: **bridge (br0)** so the VM has its own address on your LAN. Fixed IP **192.168.0.191** (M-024); no DNS name.
- Turn on **autostart** for the VM, and have the Unraid host on a UPS so a power cut doesn't corrupt the database.
- Don't rely on Unraid's VM disk snapshots for backups; a running database needs its own backup tool (see Operations).

**Names and certificates**

- No DNS name: agents and the dashboard use the VM's fixed IP, 192.168.0.191, which goes into both server certificates as an IP SAN (decision M-024).
- Two private CAs on the mothership: a **private agent CA** for agent client certificates and the 8443 server certificate, and a separate **web CA**, name-constrained to 192.168.0.0/24, for the 443 dashboard certificate; its CA certificate is imported on the admin PC (M-024).

**Network and firewall** (ufw on the VM, plus your router or switch rules)

| Port | Purpose | Allowed from |
| --- | --- | --- |
| 8443 | Agent ingest, mutual TLS required | 192.168.0.0/24 only (M-025) |
| 443 | Dashboard and admin | 192.168.0.0/24 only (M-025) |
| 22 | SSH, keys only, no passwords | 192.168.0.0/24 only (M-025) |
| everything else | blocked | — |

Outbound from the VM: OS updates, and the enrichment feed downloads (MS-ENR). No internet-facing ports during development or the pilot.

## Ingest API

Agents send gzipped JSON Lines batches over mutual TLS; the mothership stores each batch in one transaction and acknowledges exactly that request's lines (M-004). The contract is written to `docs/ingest-api.md` and reviewed before any code.

| ID | Requirement | Priority |
| --- | --- | --- |
| MS-ING-01 | `POST /api/v1/ingest` on port 8443 accepts a gzip JSON Lines body (max 10 MB compressed *(confirm)*) and returns `{"acked_seq": N}`. A 200 acknowledges exactly the lines of that request (stored, duplicate or quarantined); `acked_seq` is the highest `seq` in it, a check for the agent, not a watermark (M-004). | Must |
| MS-ING-02 | Client certificate required; the agent's identity is the certificate, and a batch whose `agent_id` differs from it is rejected. | Must |
| MS-ING-03 | Idempotent: records are unique on `(agent_id, seq)`; resends are dropped without error. | Must |
| MS-ING-04 | Each batch is stored in one database transaction; `acked_seq` is sent only after commit. | Must |
| MS-ING-05 | Lines with an unknown `record_type` or `schema_version`, or that fail validation, go to a quarantine table with the reason; the rest of the batch is stored. Unknown optional fields in a known record type and schema_version are accepted and kept (in an extra JSON column), never quarantined: the agent adds new types and optional fields without changing schema_version, and only renames, removals or changed meanings increase it. | Must |
| MS-ING-06 | `POST /api/v1/enroll`: one-time enrolment token plus a certificate signing request; returns a client certificate signed by the agent CA (valid 1 year *(confirm)*). | Must |
| MS-ING-07 | `GET /api/v1/config`: the agent's settings for its host group (intervals, caps, exclusions, redaction rules, server-software detection list), read-only. The detection list may hold only service names and HKLM registry keys, never file paths or commands. | Should |
| MS-ING-08 | Every request updates the agent's last check-in time, version and source IP. | Must |
| MS-ING-09 | Clear error codes: 400 bad batch, 401/403 certificate problems, 413 too large, 429 slow down (with Retry-After), 503 database unavailable. The agent retries 429 and 5xx with backoff. | Must |
| MS-ING-10 | Keeps up with 500 agents each sending a batch every 10 s (50 requests/s), with p95 response under 500 ms. | Must |
| MS-ING-11 | `POST /api/v1/renew`: an agent with a valid certificate gets a new one before the current one expires, without a new enrolment token (M-018). | Should |
| MS-ING-12 | `POST /api/v1/heartbeat`: every agent sends its version, health and queue size every 60 s, even with nothing to send; no `seq`, never queued or resent; updates the check-in (MS-ING-08) and is kept 90 days (M-006, M-030, M-042). | Must |

## Data model

Each agent record type gets its own MariaDB table, big tables are split into one partition per day, and old data is removed by dropping whole partitions. Every row keeps the envelope fields (`agent_id`, `host_id`, `seq`, `ts`) and the agent's original field names. New agent fields are added as columns when the agent reports them; final since agent milestone 5 (signed off Nov 7): `priority_class`, `priority_boosts` and the backup-privilege counter on `etw_status`, and `access_denied_reason` and `would_need_privilege` on `executable`.

| Agent record type | Table | Partitioned by day | Detail kept | Main lookups |
| --- | --- | --- | --- | --- |
| `net_flow` | `net_flows` | Yes | 90 days | host + time; remote IP; local port; process exe |
| `tcp_conn`, `tcp_listener`, `udp_endpoint`, `net_snapshot_summary` | `snap_tcp_conns`, `snap_listeners`, `snap_udp_endpoints`, `snapshots` | Yes | 90 days | host + snapshot; listening port |
| `process_start`, `process_stop`, `process_snapshot` | `process_events` | Yes | 90 days; `command_line` cleared after 30 days | host + time; exe; parent PID; user |
| `executable` | `executables` | No (one row per host, path and hash) | While seen in the last 90 days | SHA-256; signature status; signer; company |
| `etw_status` | `agent_status` | Yes | 90 days | host + time |
| `coverage_gap` | `coverage_gaps` | No | 2 years | host + time range |
| Inventory (final, agent milestone 4): `inv_app`, `inv_service`, `inv_task`, `inv_startup`, `inv_driver`, `inv_role`, `host_facts`, `inventory_snapshot`, `inventory_change` | current state per host and type; `inventory_snapshots`; `inventory_changes` | Snapshots and changes: yes | Current state: always; full snapshots 90 days; changes 2 years *(confirm)* | host + type; item name; change time; product |
| `agent_start`, `agent_stop` *(final; formats in the agent's status.md)* | `agent_lifecycle` | No | 2 years | host + time; `previous_exit` |
| Server software (agent milestone 5b, built, sign-off pending): `inv_iis` (with its read status), `inv_iis_site`, `inv_iis_app_pool`, `inv_sql_instance`, `inv_server_app`; plus an optional `app` link on `inv_service`, `inv_iis_app_pool`, `inv_sql_instance`, `tcp_listener` and `udp_endpoint` | own migration; formats in the agent's status.md; create now, adjust only if 5b sign-off changes them | — | current state; changes 2 years, like inventory | host; port; product; `match_source` (guessed or confirmed) |
| `inventory_link_index` (agent milestone 5b; M-016) | `inventory_link_index` | No | 90 days | host + time |
| anything unrecognised, or a provisional type with no table yet | `quarantine` | Yes | 90 days (M-021) | reason |

**Mothership's own tables:** `hosts` (MachineGuid, hostname, group, first and last seen), `agents` (agent ID, certificate fingerprint, version, check-in, revoked), `enrollment_tokens`, `ip_enrichment`, `alerts`, `alert_rules`, `users`, `audit_log`, `daily_*` summaries.

| ID | Requirement | Priority |
| --- | --- | --- |
| MS-DAT-01 | Schema changes only as numbered migrations in `db/migrations/`. | Must |
| MS-DAT-02 | Partitions are created ahead (7 days) and dropped after retention by a nightly job; retention is configurable per table. | Must |
| MS-DAT-03 | A nightly job builds daily summaries per host before detail expires: remote destinations, local listening ports, processes and executables seen, bytes and connection counts, and inventory changes (apps, services, tasks, startup items, drivers, roles). | Must |
| MS-DAT-04 | A connection's `net_flow` records (one per 10 s window) are combined in queries by protocol, PID, local and remote address and port, as the agent's decision D-016 requires. | Must |
| MS-DAT-05 | `direction_source`, `user_source`, `command_line_redacted`, `hash_status` and signature status are kept and shown, so the dashboard never presents a guess as a fact. The same goes for `access_denied_reason` and `would_need_privilege` on executables *(final since agent milestone 5)*. | Must |
| MS-DAT-06 | Measure and report database size per host per day in milestone 2, and re-size the VM disk from it. | Must |

## Enrichment

A background job adds a hostname and reputation (country not for now, M-027) to each new remote IP after it is stored, so ingest never waits on a lookup. Results are cached in `ip_enrichment` and shown beside every connection.

| ID | Requirement | Priority |
| --- | --- | --- |
| MS-ENR-01 | Reverse DNS for each new public and internal remote IP, cached 24 hours; failures cached too, so a dead DNS server can't slow the job. | Must |
| MS-ENR-02 | Country and network owner (ASN) from MaxMind GeoLite2 (free account and licence key needed), updated weekly. **Not built for now: no MaxMind account (M-027).** | Should |
| MS-ENR-03 | Reputation from free threat-intel IP lists, refreshed every few hours; matches stored with the list name and the date they were listed. Lists: abuse.ch Feodo Tracker, Spamhaus DROP, FireHOL Level 1, with each list's terms recorded; private and reserved ranges are removed at import (M-027). | Should |
| MS-ENR-04 | Private, loopback and link-local addresses are labelled "internal" and never sent to outside lookups. | Must |
| MS-ENR-05 | When a list update flags an IP, connections from the last 30 days to it are re-checked and alerted on (MS-ALR-03). | Should |
| MS-ENR-06 | Certificate revocation for executables: the agent sends each signing certificate's thumbprint and serial number (agent decision PD-5); the mothership checks them against the issuers' revocation lists daily, marks revoked signers, and raises MS-ALR-02 for processes using them. The agent itself never checks revocation over the network. | Should |
| MS-ENR-07 | Product build names: SQL Server build numbers from the agent (e.g. 16.0.4135.4) are turned into release and update names (e.g. "SQL Server 2022 CU14") from a lookup table kept in the repo and updated by hand monthly from Microsoft's published SQL Server build list, with no automatic outside download; a build newer than the table shows as "unknown build", never a guess *(provisional, agent milestone 5b)*. | Should |

## Alert rules

Alert rules run every minute over newly stored data and raise one alert per host and finding, so a busy host doesn't flood the queue. Each rule can be turned on, off or tuned per host group.

| ID | Alert | Fires when | Default severity | Priority |
| --- | --- | --- | --- | --- |
| MS-ALR-01 | New listening port | A host starts listening on a port it hasn't listened on in the last 30 days | Medium | Must |
| MS-ALR-02 | Unsigned program on the network | A process whose executable is unsigned, has an invalid signature, or is signed by a revoked certificate (MS-ENR-06) makes or accepts a connection | High | Must |
| MS-ALR-03 | Flagged destination | A connection to or from an IP on a threat-intel list (MS-ENR-03) | High | Must |
| MS-ALR-04 | New executable | A hash never seen before on this host runs | Low | Should |
| MS-ALR-05 | Agent silent | No check-in for 5 minutes *(confirm)* | High | Must |
| MS-ALR-06 | Coverage gap | The agent reports a gap (session stopped, events lost, table full, not elevated) | Medium | Must |
| MS-ALR-07 | New outbound destination for a server role | A host connects out to a country or network owner it hasn't used in 30 days | Low | Could |
| MS-ALR-08 | Agent crashed and restarted | An `agent_start` record has `previous_exit: "crash"`; repeated crashes on one host raise the severity | Medium | Should |
| MS-ALR-09 | IIS certificate expiring or weak | An HTTPS binding's certificate expires within 30 days *(confirm)*, or uses a weak key or signature (e.g. RSA under 2048 bits, SHA-1); needs agent AG-INV-18 | Medium | Could |

- Alerts have states: open, acknowledged, closed, with who and when.
- An allow-list per rule (process, port, IP range, host group) silences known-good findings, with a note on why.
- Email or webhook notification is a Could for v1; the dashboard queue is the Must.

## Dashboard

The dashboard is server-rendered PHP pages on the same VM, reading only from the database through a read-only database user. It delivers the six views in the main plan's Dashboard section.

| ID | Requirement | Priority |
| --- | --- | --- |
| MS-UI-01 | Fleet overview: hosts, agent version, last check-in, stale agents, open alerts by severity, coverage gaps in the last 24 hours. | Must |
| MS-UI-02 | Host detail with tabs: connections (live and recent), listening ports, processes, executables (hash, signature, version), inventory, and a **server software** tab: IIS sites with their bindings and app pools, SQL Server instances and their ports, and which product owns each listening port, each link shown as guessed or confirmed; IIS shows its read status, so a host whose IIS configuration couldn't be read is never shown as having no sites. | Must |
| MS-UI-03 | Connections view: direction and how it was decided, process, user, remote host, country, reputation, first and last seen, bytes; filters and a simple graph of host to processes to destinations. | Must |
| MS-UI-04 | Change history per host: new listening ports, new executables, new destinations, and inventory changes (apps installed or removed, services, tasks, startup items, drivers, roles added or changed), by day. | Must |
| MS-UI-05 | Alerts queue: filter, acknowledge, close, add allow-list entries. | Must |
| MS-UI-06 | Fleet search: which hosts talk to an IP, listen on a port, or ran a hash or exe; export results to CSV and JSON. | Must |
| MS-UI-07 | Coverage gaps shown on every time-based view, so missing data is never read as "no activity". | Must |
| MS-UI-08 | Every page answers in under 5 seconds for a 7-day range at 500 hosts. | Must |
| MS-UI-09 | Works in a current desktop browser; phones are not a v1 goal. | Must |

## Security and sensitive data

The mothership holds a map of every server's activity, its usernames and its command lines, so it is treated as the most sensitive system in the project.

| ID | Requirement | Priority |
| --- | --- | --- |
| MS-SEC-01 | HTTPS only, TLS 1.2 or later. Ingest requires client certificates from the agent CA; the dashboard uses user sign-in. | Must |
| MS-SEC-02 | The agent CA's private key is readable only by the signing process; agent certificates can be revoked, and a revoked agent is refused at once. | Must |
| MS-SEC-03 | Enrolment tokens are single-use, expire after 24 hours *(confirm)*, and are stored only as hashes. | Must |
| MS-SEC-04 | Separate database users: ingest (insert into data tables), dashboard (read data; write its own user, alert and audit tables), maintenance (partitions and retention). No application uses root. | Must |
| MS-SEC-05 | Prepared statements for every query; output escaping; CSRF tokens on forms; secure, HttpOnly, SameSite session cookies. | Must |
| MS-SEC-06 | Sign-in with local accounts first: strong password hashing, lockout after 5 failures *(confirm)*, optional TOTP second factor. Designed so Entra ID or Active Directory sign-in can be added later. | Must |
| MS-SEC-07 | Roles: **viewer** (fleet and connections, command lines hidden), **analyst** (everything, including command lines and exports), **admin** (users, enrolment, rules, retention). | Must |
| MS-SEC-08 | Audit log of sign-ins, every view of command lines, every export, and every admin change; kept 2 years and not deletable from the dashboard. | Must |
| MS-SEC-09 | Command lines are shown only to analysts and cleared from records after 30 days *(confirm)*; the agent's redaction flag is shown beside them. | Must |
| MS-SEC-10 | Never log private keys, enrolment tokens, passwords or session secrets. | Must |
| MS-SEC-11 | Database and backups encrypted at rest (MariaDB table encryption plus encrypted backup files). | Should |
| MS-SEC-12 | Security updates applied automatically for the OS (unattended-upgrades); PHP and Composer packages reviewed monthly. | Must |

Who may view this data, and for how long, needs sign-off from privacy or legal before the pilot (the main plan's privacy risk). The list to review: usernames, command lines, service account names, IIS app pool identities, scheduled-task run-as accounts, local users and Administrators members (AG-INV-08), per-user apps and browser extensions (which carry profile names), and remote IPs with their hostnames.

## Operations

When the mothership is down, agents keep collecting and buffer up to 500 MB each *(confirm)*, then resend; so an outage costs data only if it outlasts the buffer. Backups and health checks make sure that outage stays short and recoverable.

| ID | Requirement | Priority |
| --- | --- | --- |
| MS-OPS-01 | Nightly full database backup with `mariabackup` (consistent while running), plus binary logs for point-in-time recovery, written to an Unraid array share. | Must |
| MS-OPS-02 | Agent CA key, server certificates and app config backed up separately, encrypted, and kept off the VM. | Must |
| MS-OPS-03 | One backup copy leaves the Unraid server (another machine or offsite), so one failed box can't lose everything. | Should |
| MS-OPS-04 | A restore is tested to a fresh VM before ready to ship and every quarter after; time taken is recorded. Before the pilot, the CA keys and app config copy (MS-OPS-02) is checked by decrypting it and comparing it with the VM (M-040). | Must |
| MS-OPS-05 | Health page and checks: disk free, database up, ingest rate and errors, quarantine count, enrichment and nightly job last success, certificate expiry within 30 days, and a pending OS reboot (`/var/run/reboot-required`; M-039). | Must |
| MS-OPS-06 | Health problems raise alerts in the dashboard (MS-ALR) and, once added, by email. | Should |
| MS-OPS-07 | The whole server can be rebuilt from the repo: `docs/setup.md`, Apache config, systemd timers, migrations, and a restore from backup. | Must |
| MS-OPS-08 | Disk filling up: ingest returns 503 at 90% full so agents keep buffering, rather than the database crashing. | Must |

**Rough recovery targets** *(confirm)*: back in service within 4 hours of a VM failure; at most 24 hours of stored data lost (less with binary logs), plus whatever the agents still hold in their buffers is resent.

## Matching agent changes

The agent needs one new milestone, **collector transport**, before it can talk to the mothership. It is built against `docs/ingest-api.md` once that contract is approved, and is scheduled as agent milestone 5c: after 5b (server software) and before milestone 6 (MSI), whose installer needs the collector address and enrolment token. It can start once this plan's milestone 1 contract is approved; the end-to-end test waits for mothership milestone 3 (enrolment).

| Agent requirement | What changes |
| --- | --- |
| AG-SEC-03, 04 | Enrol with a one-time token, keep its own key and certificate, pin the agent CA. |
| AG-DAT-03 | Send gzip batches every 10 s or 1 MB, whichever comes first, over mutual TLS. |
| AG-DAT-04 | Keep records until `acked_seq` covers them; resend on failure with backoff; honour 429 and 503. |
| AG-PRF-04, AG-SEC-06 | Replace local JSON files with an encrypted, size-capped queue in a folder only SYSTEM and Administrators can read; delete records once acknowledged. |
| AG-PRF-06 | Heartbeat even when there is nothing to send. |
| AG-CFG-01 | Fetch settings from `/api/v1/config`. |
| AG-DAT-02 | Wire schema stays JSON Lines `schema_version: 1` for v1 *(confirm)*; Protocol Buffers only if ingest load tests need it. |

After this milestone **no collected data stays on the host** once the mothership confirms it, which is the goal the user set.

## Milestones

Seven milestones, one at a time, each ending in a test you can check; milestone 1 needs no agent changes, so it can start now.

| # | Milestone | What gets built | Gate to pass |
| --- | --- | --- | --- |
| 1 | Server and contract | VM, Apache, PHP, MariaDB, TLS, firewall; agent CA; `docs/ingest-api.md` and schema | API contract and schema approved by the user |
| 2 | Ingest | Ingest endpoint, dedup, quarantine; agent simulator; size per host per day | 2 simulated servers and 2 simulated laptops: p95 under 500 ms, 0 lost; MB per host per day for each (M-050) |
| 3 | Enrolment | One-time tokens, certificate signing, revocation, agent check-ins | Revoked agent refused, silent agent flagged |
| 4 | Retention | Daily partitions, retention, nightly summaries, health checks (backups moved to "before ready to ship", M-026) | On simulator data: partitions made 7 days ahead and dropped at retention; summaries match detail; each health check fires and clears (M-031) |
| 5 | Dashboard core | Sign-in, roles, audit log, fleet overview, host detail | Every page under 5 s at 500 hosts, 7 days |
| 6 | Dashboard full | Connections, history, search, export; enrichment: DNS, reputation | Views match agent data on simulator output |
| 7 | Alerts and pilot | Alert rules, allow-lists; agent transport live on pilot hosts | Pilot go/no-go; privacy sign-off done |

**Before the pilot:** an encrypted off-VM copy of the two CA keys and the app config (MS-OPS-02, M-031). **Before ready to ship** (not a milestone gate): the rest of the backups and a timed restore test, MS-OPS-01 to MS-OPS-04 (M-026).

Milestones 1 to 6 are tested with an agent simulator, so they don't wait for the agent's transport milestone; real agents connect in milestone 7.

## Open questions

- [x] Fleet size for the pilot and for production (default: 50, designed for 500): kept (M-017).
- [x] Retention: detail 90 days, summaries 2 years, command lines 30 days: accepted as defaults (M-017).
- [x] Internal DNS name and which CA issues the dashboard's HTTPS certificate: none; fixed IP, web CA on the mothership (M-024).
- [x] Which networks the servers and the admin workstations sit on: 192.168.0.0/24 (M-025).
- [ ] Free disk on Unraid's cache pool for the VM, and where the off-box backup copy goes: deferred with the backups (M-026).
- [x] Threat-intel lists to use, and whether to create a MaxMind account for GeoLite2: the three lists, no MaxMind (M-027).
- [x] Who gets analyst access (command lines) and who signs off privacy: the owner (M-028).
- [x] Order: settled. The mothership starts now, in parallel with the agent.
