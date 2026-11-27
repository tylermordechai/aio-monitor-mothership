# Design decisions

A record of the mothership's design decisions, with the requirement IDs they affect. Agent decisions (D-xxx) are in the agent repo's `docs/decisions.md`.

- **Status values:** *Proposed*, *Accepted*, *Superseded*. M-001 to M-016 were accepted at the milestone 1 review (M-017).
- Values still marked *(confirm)* are defaults kept in config or data, listed in [pending-decisions.md](pending-decisions.md) with a recommendation.

## Milestone 1: contract and schema (2026-11-13)

Proposed in [ingest-api.md](ingest-api.md) and `db/migrations/0001_initial.sql`, for the milestone 1 gate.

### M-001 Agent endpoints and how they authenticate
**Requirements:** MS-ING-01, 02, 06, 07, MS-SEC-01 · **Status:** Accepted 2026-11-13 (M-017)
- Five endpoints on 8443: `enroll`, `ingest`, `heartbeat`, `config`, and `renew` (MS-ING-11, M-018).
- **Apache asks for a client certificate on every connection** (`SSLVerifyClient optional`, agent CA only). PHP requires a verified one on every path except `/api/v1/enroll`.
- **Why not per-location `SSLVerifyClient require`:** that needs TLS renegotiation, which TLS 1.3 doesn't have (it uses post-handshake authentication, which Go clients don't support). A separate enrolment port was the alternative.

### M-002 Certificate profile
**Requirements:** MS-ING-06, MS-SEC-02, AG-SEC-03/04 · **Status:** Accepted 2026-11-13 (M-017)
- **Agent key:** ECDSA P-256, created on the host; the key never leaves it.
- **Certificate:** subject CN = `agent_id`, SAN `urn:aio-monitor:agent:<agent_id>`, EKU `clientAuth` only, 365 days, 128-bit random serial.
- **CA:** ECDSA P-384, 10 years. Its key is readable only by a separate signing service, never by the web PHP process (MS-SEC-02).
- **The 8443 server certificate** is issued by the same CA, so the agent pins one CA for both directions.
- **Revocation is a database check on every request,** so it takes effect at once. The CRL is a second layer.
- **Why ECDSA:** small keys and signatures, fast handshakes at 50 requests per second, native in Go and PHP's OpenSSL.

### M-003 The agent keeps its own agent_id; enrolment certifies it
**Requirements:** MS-ING-02, MS-ING-06, AG-DAT-01, D-004 · **Status:** Accepted 2026-11-13 (M-017)
- The CSR's CN is the UUID the agent already uses (D-004), so queued records and certificate agree with no mapping.
- **An agent ID already enrolled** needs a re-enrolment token made for that ID; otherwise 409. A leaked ordinary token can't take over a live agent's identity.
- **Hosts:** a host row is matched on `host_id` (MachineGuid) and `hostname` together, because clones can share a MachineGuid (D-003). Each agent is bound to one host. Lines with another `host_id` are quarantined (`host_id_mismatch`) and raise a health alert.
- **Rows store** `agent_pk` and `host_pk` (surrogate integers for `agents.agent_id` and `hosts.machine_guid`) instead of repeating the UUID strings on every row. `agent_version` and `hostname` are kept per agent and host, with history in `host_names`.

### M-004 One acknowledgement per request; acked_seq is not a watermark
**Requirements:** MS-ING-01, 03, 04, AG-DAT-04 · **Status:** Accepted 2026-11-13 (M-017)
- **A 200 means every line of that request is durably held** (stored, duplicate or quarantined), after commit. The agent deletes exactly those lines.
- **`acked_seq`** is the highest `seq` in the request, kept as MS-ING-01 asks, as a check for the agent.
- **MS-ING-01 wording (agreed at the review):** returns `{"acked_seq": N}`, the highest sequence number in the acknowledged batch.
- **Why not "everything up to N":** the agent hands out `seq` in reserved ranges to several writers (`identity.go`), so records reach the queue out of order and gaps are normal. A watermark would delete records that were never sent.

### M-005 Dedup key (agent, seq, ts); a seq rollback is detected, not dropped
**Requirements:** MS-ING-03, MS-DAT-02 · **Status:** Accepted 2026-11-13 (M-017)
- **The key:** `PRIMARY KEY (agent_pk, seq, ts)` on each partitioned record table. A daily-partitioned table needs `ts` in every unique key, and a resent line has the same `ts`, so every resend is caught.
- **How ingest inserts:** `INSERT IGNORE` on pre-validated rows. Any warning other than a duplicate key fails the batch, so `IGNORE` can't hide a truncation. The ingest database user then needs no UPDATE privilege on partitioned tables.
- **A state rollback** (a restored VM snapshot reuses `seq` numbers) stores both records instead of dropping the newer one, and is flagged on the agent (`seq_regression_at`) with a health alert. This key is the only thing that catches a VM snapshot revert; the optional `seq_epoch` cannot (M-020).
- **The alternative** was a global `(agent, seq)` ledger: one extra row and index entry for every record (about 40 million a day at 500 hosts). It was rejected for the cost.

### M-006 Heartbeat is its own endpoint
**Requirements:** AG-PRF-06, MS-ING-08, MS-ALR-05 · **Status:** Accepted 2026-11-13 (M-017)
- `POST /api/v1/heartbeat` every 60 s, with no `seq`, never queued. It carries queue size, transport state and health.
- **Its answer** carries `config_etag` and `renew_after`, so config fetches and renewals need no polling.
- **Why not an empty ingest call:** a heartbeat is about the agent now, not a host record. It shouldn't wait in the queue or be resent after an outage, and ingest keeps one meaning.

### M-007 Validation is minimal-required, type-checked, and quarantines per line
**Requirements:** MS-ING-05, D-073 · **Status:** Accepted 2026-11-13 (M-017)
- **Rules** are checked in a fixed order (ingest-api.md §3.4), so every quarantined line has exactly one reason.
- **Required fields** are only those a row needs for keys and lookups. Other known fields are type-checked and NULL when missing.
- **Unknown top-level fields** go to the row's `extra` JSON column. Unknown nested fields stay inside their JSON column.
- **A different `hostname`** is a rename, not an error.
- **The provisional record-type list** is a config setting, empty at the start.
- **The agent ID** is checked across all lines first: one mismatch rejects the whole request (MS-ING-02).

### M-008 Config: overrides on built-in defaults; detection list checked twice; redaction additive
**Requirements:** MS-ING-07, AG-CFG-01..03, AG-INV-16, D-071 · **Status:** Accepted 2026-11-13 (M-017)
- **ETag and 304.** `agent_config` stores per-group overrides; the endpoint merges them with the built-in defaults.
- **The detection list uses the agent's `DetectEntry` fields exactly.** Service-name patterns, HKLM keys (no hive name, first component `SOFTWARE` or `SYSTEM`) and registry value names only. It is checked when an admin saves it and again before it is served, and the agent checks it a third time.
- **Pushed redaction rules only add** to the agent's built-ins, so a compromised mothership can't turn redaction off.
- **Exclusions** can hide activity, so they are admin-only and audited.

### M-009 Error codes and retry rules
**Requirements:** MS-ING-09, MS-OPS-08, AG-DAT-04, AG-PRF-04 · **Status:** Accepted 2026-11-13 (M-017)
- **One JSON error shape** with a stable `code` and a `retryable` flag.
- **Retries:** 429 and 5xx with exponential backoff and full jitter (base 2 s, cap 300 s), with `Retry-After` as a floor.
- **413:** split the batch.
- **401 and 403:** stop sending but keep the queue.
- **Never delete before a 200.**

### M-010 Schema conventions
**Requirements:** MS-DAT-01, 02, 05, CLAUDE.md "Agent data" · **Status:** Accepted 2026-11-13 (M-017); the schema itself is approved once it applies cleanly on the milestone 1 VM
- **One table per agent record type.** Columns use the agent's field names; nested objects and arrays are JSON columns with the same name, plus virtual generated columns for lookups (e.g. `app_name`, `sig_status`).
- **UTC `DATETIME(6)`** for every time. The agent's 7th fractional digit is dropped the same way every time.
- **Partitioning:** daily `RANGE COLUMNS(ts)`. The migration creates `p_start` and `p_future`, and the partition job splits `p_future` 7 days ahead (MS-DAT-02). Lines older than retention or more than 24 h ahead are quarantined, so `p_future` stays empty.
- **IP addresses:** the original text (it can hold an IPv6 zone) plus `VARBINARY(16)` from `INET6_ATON`.
- **No foreign keys on partitioned tables** (InnoDB doesn't allow them); integrity comes from ingest.
- **Retention per table** is data (`retention_policies`).
- **Grants** are set up with the server (MS-SEC-04), not in migrations, because they carry environment-specific passwords.
- **Why columns rather than one JSON blob per record:** the dashboard's 5-second target at 500 hosts (MS-UI-08) needs indexed columns, and MS-DAT-05's "never show a guess as a fact" fields (`direction_source`, `user_source`, `command_line_redacted`, `hash_status`, signature status, `match_source`, IIS `read`) must be plain to query.

### M-011 Command lines live in their own table and expire by partition drop
**Requirements:** MS-SEC-07, 08, 09 · **Status:** Accepted 2026-11-13 (M-017)
- **Where:** `process_command_lines` (30-day daily partitions); the flags stay on `process_events` for 90 days.
- **Clearing:** "Cleared after 30 days" is a partition drop, not an UPDATE over hundreds of millions of rows.
- **Access:** only analyst code paths read the table, and each read writes to the audit log.

### M-012 Inventory: per-type history tables plus inventory_current; item keys derived until the agent sends them
**Requirements:** MS-UI-02, MS-UI-04, AG-INV-11 · **Status:** Accepted 2026-11-13 (M-017)
- **History:** each `inv_*` and `host_facts` record goes to its own partitioned table, 90 days.
- **Current state:** a background job (not ingest) keeps `inventory_current`, one row per host, kind and item. It uses the snapshot end markers (an item missing from a full snapshot, whose source reported no error, is marked removed) and `inventory_change` records.
- **Item keys:** item records don't carry their key today. The mothership derives it with the agent's rules (`apps_windows.go`, `services_windows.go`, `startup_windows.go`, `autostart_windows.go`, `iis_windows.go`, `serverapps_windows.go`) and marks it `key_source = 'derived'`. Snapshot membership is the `seq` range between the start and end markers.
- **Asked of 5c:** add `key` and `snapshot_id` to item records (new optional fields, D-073). The derivation then becomes a fallback.

### M-013 Combining flows: flow_key plus first_seen
**Requirements:** MS-DAT-04, D-016 · **Status:** Accepted 2026-11-13 (M-017)
- **`flow_key`** is a stored generated column: MD5 of protocol, PID, local and remote address and port, which is D-016's key.
- **One connection** is the rows with the same host, `flow_key` and `first_seen`. The agent carries `first_seen` forward across windows. Adding it keeps two connections that reuse the same ports and PID at different times apart.
- **Window counts are added up** across the rows; `closed` is true on the last row.

### M-014 Executables: one row per host and file version, updated in place
**Requirements:** MS-DAT-05, AG-PRC-02/03, D-033, D-056 · **Status:** Accepted 2026-11-13 (M-017)
- **Row identity:** host, path hash, size and modified time, the agent's own join key (D-033). Process records join on the same three fields.
- **Updates:** a later record for the same file updates the row (for example `access_denied` becoming `ok`). `first_seen`, `last_seen`, `first_seq` and `last_seq` are kept.
- **Retention:** rows not seen for 90 days are deleted (plan: "while seen in the last 90 days").

### M-015 Coverage gaps: one row per gap
**Requirements:** MS-UI-07, MS-ALR-06, D-017 · **Status:** Accepted 2026-11-13 (M-017)
- The agent writes a `session_stopped` gap when it opens and again when it closes. Both land on one row, keyed by agent, reason and `start`.
- The table isn't partitioned (2 years, small).

### M-016 inventory_link_index gets a table
**Requirements:** AG-INV-15, D-069 · **Status:** Accepted 2026-11-13 (M-017)
- It is a 5b record with an exact format in the agent's status.md, but it's missing from CLAUDE.md's list of record types.
- It is treated like the other 5b types (table now, adjust at 5b sign-off), not as provisional.

## Milestone 1 review (2026-11-13)

### M-017 Review of the milestone 1 contract and schema
**Requirements:** MS-ING-01..11, MS-DAT-01, 02, 04, 05, MS-SEC-01..04, 07..09 · **Status:** Accepted 2026-11-13
- **The tech lead's answers:** MPD-01, 02, 04, 10, 14 to 18, 21 to 24 accepted as recommended; MPD-03 (a), 05 (a), 06 (a, with the `seq_epoch` correction in M-020), 07 (a), 08 (a), 09 (a, TLS 1.3 on 8443), 11 (a), 12 (a), 13 (a); MPD-19 Ubuntu 24.04 LTS and MariaDB 11.8 LTS (later changed to Ubuntu 26.04.1 LTS, M-029).
- **The contract** ([ingest-api.md](ingest-api.md) v1) is **approved**. Agent 5c builds against it.
- **The schema** (`0001_initial.sql`) is approved once it applies cleanly on the milestone 1 VM.
- **M-001 to M-016** are accepted as written, with the changes in M-018 to M-023.
- **Values now fixed** (still stored as config or data, so they can be changed later without a code change):

| Value | Agreed | From |
| --- | --- | --- |
| Batch size, compressed | 10 MB | MPD-01 |
| Decompressed, lines, one line | 64 MB, 50,000 lines, 1 MB per line | MPD-02 |
| Agent certificate | 365 days, renewed 30 days before expiry | MPD-03, M-018 |
| Agent keys, CA | ECDSA P-256; CA ECDSA P-384, 10 years, SHA-384; CA rollover runbook at year 8 (milestone 4 docs) | MPD-10 |
| Timestamp window | at most 24 h ahead, not older than the table's retention | MPD-15 |
| Backoff | full jitter, base 2 s, cap 300 s; `Retry-After` honoured up to 1 h; request timeout 60 s; after 401 or 403, retry hourly | MPD-16 |
| Rate limits | one ingest request in flight per agent; 429 above 5 requests/s for 10 s, `Retry-After` 5 to 60 s; enrolment 10 failures per address in 10 minutes | MPD-17 |
| Retention | detail 90 days, summaries 2 years, command lines 30 days, inventory snapshots 90 days, inventory changes 2 years, heartbeats 90 days; quarantine 90 days (M-022) | MPD-18 |
| Plan defaults | agent silent 5 minutes; sign-in lockout 5 failures for 15 minutes; IIS certificate warning 30 days; agent buffer 500 MB; recovery 4 h / 24 h; 50 pilot hosts, designed for 500; detection keys under `SOFTWARE` or `SYSTEM` only | MPD-21 |
| Redaction rules in config | add only, never remove the built-ins | MPD-22, M-008 |
| Exclusions | admins only, audited with a required note, shown on the host page and next to coverage gaps | MPD-23, M-008 |
| Cloned hosts | `host_id_mismatch` quarantine plus a high-severity health alert; hostname flapping alerts too | MPD-24, M-003 |

- **Still open:** MPD-20 (DNS name, dashboard CA) and MPD-25 to MPD-28 (networks, Unraid, enrichment, privacy), plus MPD-29 (four minor defaults found while recording this review).

### M-018 Certificate renewal endpoint, new requirement MS-ING-11 (Should)
**Requirements:** MS-ING-06, MS-ING-11 · **Status:** Accepted 2026-11-13 (MPD-03 a)
- **MS-ING-11 (Should):** `POST /api/v1/renew` issues a new agent certificate, with mTLS using the current certificate and a CSR for a **new** key with the same CN. The agent calls it once `renew_after` has passed (30 days before expiry), then daily until it works.
- The old certificate stays valid until it expires, so a lost response costs nothing.
- An expired or lost certificate still needs a re-enrolment token (§2.5 of ingest-api.md).
- **Why:** without it, every agent needs a new token by hand each year.
- The plan (`mothership-plan.md`) doesn't list MS-ING-11 yet; it is defined here and in ingest-api.md §2.6.

### M-019 TLS 1.3 only on 8443
**Requirements:** MS-SEC-01, AG-SEC-04 · **Status:** Accepted 2026-11-13 (MPD-09 a)
- The agent port accepts TLS 1.3 only (`SSLProtocol -all +TLSv1.3`). Every agent is Go, which supports it.
- Port 443 (the dashboard) keeps TLS 1.2 or later for browsers.
- The 8443 server certificate is issued by the agent CA, and the agent pins that CA's SHA-256 fingerprint, delivered as an MSI property (M-002).

### M-020 seq_epoch detects a recreated state file, not a snapshot revert
**Requirements:** MS-ING-03, AG-DAT-01 · **Status:** Accepted 2026-11-13 (MPD-06 a, corrected at the review); amends M-005
- **The correction:** a VM snapshot revert restores the agent's state file, and the old `seq_epoch` with it. So `seq_epoch` can't tell a revert apart from normal running.
- **What `seq_epoch` is for:** detecting a state file that was created anew while the `agent_id` stayed the same. It is new only when the state file is created.
- **What catches snapshot reverts** (and restored backups or clones): the `(agent, seq, ts)` key, which keeps both records, and the regression check that sets `seq_regression_at` (ingest-api.md §3.6).
- It stays an **optional** ask of agent 5c: a new optional field on `agent_start`, `schema_version` 1 (D-073).

### M-021 Enrolment tokens: manual 24 h, deployment up to 7 days
**Requirements:** MS-SEC-03, MS-ING-06 · **Status:** Accepted 2026-11-13 (MPD-04)
- `enrollment_tokens.token_kind`: `manual` (typed by hand, at most 24 h) or `deployment` (in an Intune or GPO package, at most 7 days, chosen when made).
- Deployment tokens are labelled as such on the admin page. Every token is single-use and stored only as a SHA-256 hash.
- The token-create code enforces the limits; the column records the kind for display and audit.

### M-022 Quarantine kept 90 days
**Requirements:** MS-ING-05, MS-DAT-02 · **Status:** Accepted 2026-11-13 (MPD-14)
- The plan's 30 days could expire provisional record types before their table exists. 90 days matches detail data.
- A health check alerts when the quarantine grows by more than 1,000 lines a day.
- `retention_policies` row for `quarantine` set to 90.

### M-023 Platform versions
**Requirements:** MS-OPS-07 · **Status:** Superseded by M-029 (2026-11-13)
- **Ubuntu Server 24.04 LTS**, **MariaDB 11.8 LTS** from the MariaDB repository (Ubuntu 24.04's own package is 10.11), PHP 8.3, Apache 2.4.
- 11.8 is supported to 2028 and has everything the schema uses (JSON_VALUE, RANGE COLUMNS partitions, virtual columns).

## Remaining review answers (2026-11-13)

### M-024 No DNS name: the mothership is reached by its fixed IP; a second private CA for 443
**Requirements:** MS-SEC-01, AG-SEC-04, MS-ING-06 · **Status:** Accepted 2026-11-13 (MPD-20)
- **There is no local domain or DNS server.** Agents and browsers use the mothership's fixed IP on 192.168.0.0/24: **192.168.0.191**, set when the VM was built (2026-11-13; `setup/env.sh`).
- **Both server certificates** (8443 and 443) carry the IP as an `iPAddress` SAN and no DNS name.
- **Two private CAs on the mothership:**
  - the **agent CA** (M-002): agent client certificates and the 8443 server certificate; agents pin it;
  - a separate **web CA**: only the 443 dashboard certificate. The tech lead imports its CA certificate on the admin PC.
- **The web CA is limited by name constraints** to `iPAddress` 192.168.0.0/24 (critical extension), so if its key ever leaked it couldn't sign a certificate the admin PC would trust for any other site. Its key is used about once a year, readable by root only, and never by PHP or the signing service.
- **No SNI** is sent for an IP, so Apache selects the vhost by port: one per port.
- **Changing the IP later** means reissuing both server certificates and changing the collector address on every agent. The IP is treated as permanent once agents are enrolled.

### M-025 Firewall: 8443, 443 and 22 from 192.168.0.0/24 only
**Requirements:** MS-SEC-01, CLAUDE.md "no internet-facing ports" · **Status:** Accepted 2026-11-13 (MPD-25)
- Servers and the admin PC share 192.168.0.0/24. ufw allows **8443, 443 and 22 from 192.168.0.0/24 only**, and denies all other inbound traffic.
- SSH stays keys only, no passwords.
- Outbound: Ubuntu package updates (no third-party repositories, M-029), and the threat-list downloads (M-027).
- Because servers and the admin PC share one range, the port rules can't separate them; mTLS on 8443 and sign-in on 443 do that.

### M-026 Backups deferred to a "before ready to ship" item; milestone 4 without backups
**Requirements:** MS-OPS-01..04, MS-OPS-07 · **Status:** Accepted 2026-11-13 (MPD-26)
- **MS-OPS-01 to 04 stay in the plan** unchanged, but the backup and restore-test work moves out of milestone 4 to a **"before ready to ship"** item.
- **Milestone 4 keeps:** daily partitions, retention, nightly summaries and health checks (MS-DAT-02, 03, MS-OPS-05, 06, 08).
- **Milestone 4's gate** was "restore to a fresh VM tested and timed". A replacement is proposed in MPD-30.
- **Until backups exist:** MS-OPS-07 covers rebuilding the server from the repo, but not restoring data; the recovery targets agreed in M-017 (4 h, 24 h) can't be met; and losing the VM loses all stored data and both CA keys. Losing the agent CA key means re-enrolling every agent (MPD-30).
- The Unraid cache-pool size and the off-box copy (the rest of MPD-26) are settled with the backup work.

### M-027 Enrichment: three threat lists, terms recorded; no GeoLite2
**Requirements:** MS-ENR-02, 03, 04, MS-ALR-07, MS-UI-03 · **Status:** Accepted 2026-11-13 (MPD-27)
- **Threat lists:** abuse.ch Feodo Tracker, Spamhaus DROP (v4 and v6), FireHOL Level 1. Each list's terms are stored in `threat_lists` (checked 2026-11-13):

| List | Terms | Fetch |
| --- | --- | --- |
| Feodo Tracker (`ipblocklist_recommended.txt`) | CC0, commercial or not, no attribution, no export limits (abuse.ch terms s. 3.4); as is, no liability; no AI training without consent | every 15 min (it is rebuilt every 5) |
| Spamhaus DROP (`drop_v4.json`, `drop_v6.json`) | Free for any use; credit Spamhaus and keep the date and copyright with the data | daily; **never more than once an hour**, or the IP may be blocked |
| FireHOL Level 1 (`firehol_level1.netset`) | FireHOL tooling GPL v2; "IP lists are a property of their maintainers". It aggregates dshield, feodo, fullbogons and spamhaus_drop, whose own terms apply | every 4 h |

- **FireHOL Level 1 contains private ranges** (it includes fullbogons, so 10/8, 172.16/12 and 192.168/16 are listed). Matched as is, every internal connection would be "flagged". So **every entry overlapping a private, loopback, link-local, CGNAT, multicast or other reserved range is skipped at import** and counted (`dropped_internal`), and internal addresses are never matched against any list (MS-ENR-04).
- **DShield's terms** (a FireHOL source) are not checked yet (MPD-31).
- **No MaxMind account:** `country`, `asn` and `as_org` stay NULL, and **MS-ENR-02 is not built for now**. The columns stay, so it can be added without a migration.
- **What that switches off:** MS-ALR-07 (new country or network owner, Could) can't work and isn't built; the connections view (MS-UI-03) shows no country column.
- No outbound lookups to other services (no WHOIS, no online GeoIP).

### M-028 Analyst access and privacy sign-off
**Requirements:** MS-SEC-07..09, milestone 7 gate · **Status:** Accepted 2026-11-13 (MPD-28)
- **The owner is the only analyst** (the only role that can see command lines), and signs off on privacy before the pilot.
- Command-line views are still audited (MS-SEC-08), even with one analyst.

### M-029 Platform: Ubuntu 26.04.1 LTS with Ubuntu's own packages (supersedes M-023)
**Requirements:** MS-OPS-07 · **Status:** Accepted 2026-11-13 (MPD-19 changed by the tech lead)
- **The VM runs Ubuntu Server 26.04.1 LTS.**
- **Everything comes from Ubuntu's own archive:** MariaDB 11.8 LTS from `main`, PHP 8.5, Apache 2.4. There is no MariaDB.org or other third-party repository, so updates come only through Ubuntu's normal security updates.
- **MariaDB's data directory on 26.04 is `/var/lib/mariadb`**, not `/var/lib/mysql`. Setup notes, AppArmor expectations, disk checks (MS-OPS-05, 08) and later backup paths (MS-OPS-01) use `/var/lib/mariadb`.
- **The schema is unchanged:** it targets MariaDB 11.8 either way.
- **PHP 8.5:** the code targets 8.5, and any library proposed later must support it.

## Milestone 1: server setup (2026-11-13)

### M-030 Minor defaults accepted
**Requirements:** MS-ING-06, MS-ING-07, MS-ING-08 · **Status:** Accepted 2026-11-13 (MPD-29)
- 8443 server certificate: 1 year from the agent CA, reissued 30 days before expiry (07-pki.sh reissues when run; a timer comes with milestone 4's health checks).
- `seq` rollback alert: more than 1,000 non-duplicate lines below `max_seq_held`, or any `agent_start` below it.
- Heartbeat every 60 s; config fetch backstop every 15 minutes.

### M-031 Milestone 4 gate; the CA keys and app config are backed up before the pilot
**Requirements:** MS-DAT-02, MS-DAT-03, MS-OPS-02, MS-OPS-05 · **Status:** Accepted 2026-11-13 (MPD-30)
- **Milestone 4's gate**, on simulator data with the clock moved forward:
  - partitions are created 7 days ahead and dropped exactly at each table's retention;
  - nightly summaries match the detail they were built from;
  - each health check (disk, database, ingest errors, quarantine growth, job last success, certificate expiry) fires when its condition is forced, and clears after.
- **Before the pilot:** an encrypted copy of the two CA keys and the app config (`/etc/aio-mothership/`), kept off the VM, made once and again after any change (MS-OPS-02). Without it, losing the VM means re-enrolling every pilot agent by hand.
- The rest of the backups stay a "before ready to ship" item (M-026).

### M-032 DShield's terms checked before the threat lists are fetched
**Requirements:** MS-ENR-03 · **Status:** Accepted 2026-11-13 (MPD-31)
- Before milestone 6 enables the fetch, DShield's terms (a FireHOL Level 1 source) are checked and recorded in `threat_lists`.
- If they don't fit and the DShield part can't be excluded, FireHOL Level 1 is disabled; Feodo Tracker and Spamhaus DROP already cover its other sources.

### M-033 Server setup as numbered, idempotent scripts run by the owner
**Requirements:** MS-OPS-07 · **Status:** Accepted 2026-11-13
- `setup/NN-name.sh`, one job each, in order 01 to 08. Each one:
  - starts with what it does, whether it needs sudo and whether it reboots;
  - ends with checks that print PASS or FAIL, and `RESULT: PASS` or `RESULT: FAIL` (exit 1);
  - is safe to run again.
- **Shared parts:** `setup/env.sh` (IP 192.168.0.191, LAN, versions; nothing secret) and `setup/lib.sh` (banner, checks, template rendering). Config files are templates in `setup/templates/`, written only when they change.
- **Claude never logs in to the VM.** The owner runs each script over SSH and pastes the output back. The scripts never print passwords or keys (MS-SEC-10).
- **The repo** is cloned to `/opt/aio-mothership`, read-only. If the repo becomes private, the VM gets a read-only GitHub deploy key for this repo only (docs/setup.md). Nobody commits on the VM.
- **`.gitattributes`** forces LF for scripts, SQL and config, since they're edited on Windows and run on Linux.

### M-034 SSH: keys only, after the owner confirms key login
**Requirements:** MS-SEC-01 · **Status:** Accepted 2026-11-13
- `01-ssh-key.sh` (no sudo) adds the PC's key: ed25519 or ECDSA, or RSA of at least 3072 bits.
- `02-ssh-hardening.sh` runs only with `--key-login-confirmed` and only if `authorized_keys` holds a key. It turns off password, keyboard-interactive and root login, and sets `AuthenticationMethods publickey`, in `/etc/ssh/sshd_config.d/10-aio-mothership.conf`.
- That file is read before cloud-init's `50-…`, and sshd keeps the first value it reads, so a later file can't turn passwords back on. The check reads the effective settings (`sshd -T`).
- The Unraid console still takes the password, which is the way back in if needed.

### M-035 Updates and time
**Requirements:** MS-SEC-12, MS-ING-05 · **Status:** Accepted 2026-11-13; no automatic reboot confirmed (M-039)
- `unattended-upgrades` installs Ubuntu's security updates daily. There is **no automatic reboot**: the script, and from milestone 4 the health checks, report when one is needed, and it is done by hand.
- The clock runs in UTC with NTP sync (chrony or systemd-timesyncd, whichever Ubuntu installed). Certificates, the 24-hour timestamp window (M-007) and heartbeat skew depend on it.

### M-036 MariaDB: local only, strict, three users with table-level grants
**Requirements:** MS-SEC-04, MS-DAT-01, MS-ING-04 · **Status:** Accepted 2026-11-13; dashboard read of command lines *(MPD-34)*
- **Install:** Ubuntu's `mariadb-server`. The script stops if a MariaDB.org apt source exists or the candidate isn't 11.8. The data lives in `/var/lib/mariadb`.
- **Config** (`90-aio-mothership.cnf`):
  - `bind-address 127.0.0.1`, `skip-name-resolve`, `local-infile 0`;
  - UTC, utf8mb4, strict SQL mode, durable commits;
  - a 3 GB buffer pool, to be tuned in milestone 2.
- **Hardening:** no anonymous users, no test database, root only at localhost and only by unix socket.
- **Migrations** are applied by the setup script as root over the socket (an admin action, not an application). Each is recorded in `schema_migrations` with its SHA-256. A changed file that was already applied is an error: changes go in a new migration. A failed first migration is redone with `--recreate-db`, which is refused once any agent exists. Output is kept in `/var/log/aio-mothership/migrations/`.
- **Users** (`setup/sql/grants.sql`, revoked and granted again on every run):
  - `aio_ingest`: INSERT only on record tables; SELECT, INSERT and UPDATE on identity and upsert tables; SELECT on groups, config and retention. It can't read records back, or read users, audit or alerts.
  - `aio_dashboard`: SELECT on everything; writes users, alerts, allow-lists and the admin tables; **INSERT only** on `audit_log` (MS-SEC-08).
  - `aio_maint`: data and DDL privileges on `aio` only, for partitions, retention, summaries and the other jobs.
- **Passwords:** 40 random alphanumeric characters, in `/etc/aio-mothership/db/<user>.cnf` (600 root). They are never printed. In milestone 2 each PHP pool's group gets read access to its own file only.
- **Checks** include negative grant tests, for example that ingest can't read `net_flows` and that the dashboard can't delete from `audit_log`.

### M-037 PKI layout and the web CA's name constraint
**Requirements:** MS-SEC-01, MS-SEC-02, M-002, M-024 · **Status:** Accepted 2026-11-13
- **Agent CA** (P-384, 10 years, `pathlen:0`) in `/etc/aio-mothership/pki/agent-ca/`. Its key is `440 root:aio-signer`; `aio-signer` is the system user for the milestone 3 signing service. PHP pools can't read it (MS-SEC-02).
- **Web CA** (P-384, 10 years) in `pki/web-ca/`, key `400 root`. Its critical name constraint permits only IP 192.168.0.0/24, DNS `invalid` and email `invalid`, so it can't sign for any real DNS name or any other address. The script proves this by trying to issue certificates for `example.com` and `10.0.0.1` and checking that verification fails.
- **Server certificates** (P-256, `serverAuth`, only SAN `IP:192.168.0.191`): 8443 from the agent CA for 365 days; 443 from the web CA for 397 days. Their keys are `400 root`.
- **A CA is never replaced by the script.** A server certificate is reissued when it's missing, expires within 30 days, carries another IP, or no longer chains to its CA.
- Public copies and fingerprints are in `pki/public/`. The agent CA's SHA-256 is the MSI pin value.
- No CRL yet: revocation is the database check (M-002), built in milestone 3.

### M-038 Apache and PHP-FPM layout
**Requirements:** MS-SEC-01, MS-SEC-04, MS-SEC-05, M-001, M-019 · **Status:** Accepted 2026-11-13
- **Apache:**
  - mpm_event with PHP over FastCGI; no mod_php, autoindex, status or CGI;
  - **no port 80**: Listen is 443 and 8443 only;
  - `ServerTokens Prod`, no TRACE, no session tickets.
- **Two PHP-FPM pools under their own system users:** `aio-ingest` behind 8443 and `aio-dashboard` behind 443. The default `www` pool is off. Each pool will read only its own database credentials (MS-SEC-04).
- **8443:**
  - TLS 1.3 only, server certificate from the agent CA;
  - `SSLVerifyClient optional`, `SSLVerifyDepth 1`, agent CA only;
  - request body at most 11 MB;
  - document root `public/agent/`.
- **443:**
  - TLS 1.2 and 1.3, ECDHE-ECDSA AEAD ciphers, server certificate from the web CA;
  - strict headers (`nosniff`, `DENY` framing, no referrer, CSP `default-src 'none'`) until milestone 5 sets the dashboard's own;
  - document root `public/dashboard/`.
- **Separate document roots and pools,** so an agent endpoint can't be reached on 443 and the dashboard can't be reached on 8443.
- **PHP:** `expose_php` off, errors logged but never shown, UTC, Secure, HttpOnly, SameSite=Strict, strict-mode session cookies (MS-SEC-05). Sessions are kept in a directory only the dashboard pool can read.
- **Health pages** (`health.php`) read no database and no secret. On 8443 the page shows `client_verify`, so mTLS can be tested. The script tests it with a throwaway one-day certificate (CN `setup-selftest`, which is not a UUID and so can never act as an agent), and deletes it afterwards.

## Open items answered (2026-11-13)

### M-039 No automatic reboot; a pending reboot is reported
**Requirements:** MS-SEC-12, MS-OPS-05 · **Status:** Accepted 2026-11-13 (MPD-32)
- Security updates install daily; **the VM never reboots on its own**. The owner reboots by hand.
- **`/var/run/reboot-required` is reported in two places:**
  - `03-os-updates.sh` ends with `FAIL: reboot required (...)` and the packages that asked for it;
  - the dashboard health page (`https://192.168.0.191/health.php`) shows `"reboot_required": true|false`. 08 checks the field is there.
- MS-OPS-05 lists a pending reboot among the health checks, so milestone 4 builds it as a real check that fires and clears.

### M-040 MS-OPS-04 reworded
**Requirements:** MS-OPS-02, MS-OPS-04 · **Status:** Accepted 2026-11-13 (MPD-33)
- MS-OPS-04 now reads: a restore is tested to a fresh VM **before ready to ship and every quarter after**, with the time recorded.
- Before the pilot, the CA keys and app config copy (MS-OPS-02, M-031) is checked by decrypting it and comparing it with the VM.

### M-041 A separate analyst database user for command lines
**Requirements:** MS-SEC-04, MS-SEC-07, MS-SEC-08 · **Status:** Accepted 2026-11-13 (MPD-34); details before milestone 5
- A fourth user, `aio_analyst`, will be the only one that can read `process_command_lines`. `aio_dashboard` loses SELECT on that table, so a bug in a viewer page can't read command lines.
- **Decided before milestone 5 builds the views:** the exact grants (whether `aio_analyst` also writes `audit_log`), how the dashboard picks the connection per request, and the credentials file name.
- Until then `grants.sql` is unchanged; nothing reads the table before milestone 5.

### M-042 The repo's plan copy updated; heartbeat is MS-ING-12
**Requirements:** MS-ING-01, 11, 12, MS-DAT-01, MS-OPS-04 · **Status:** Accepted 2026-11-13 (owner)
- `docs/mothership-plan.md` and `CLAUDE.md` now match the accepted decisions:
  - the MS-ING-01 wording (M-004);
  - MS-ING-11 renew (Should, M-018) and new **MS-ING-12 heartbeat (Must)**;
  - the `inventory_link_index` row (90 days, M-016) and quarantine kept 90 days (M-021);
  - the platform, fixed IP, no DNS, two CAs and firewall (M-024, M-025, M-029);
  - MS-OPS-04 (M-040) and the milestone 4 gate (M-031).
- The heartbeat already had its own endpoint in the contract (M-006); MS-ING-12 gives it a requirement ID. ingest-api.md now cites it. The contract itself is unchanged.

## Milestone 2: ingest (2026-11-19)

### M-043 Milestone 2 scope and gate
**Requirements:** MS-ING-01 to 05, 08 to 10, 12, MS-DAT-06 · **Status:** Accepted 2026-11-19 (owner)
- **The gate stays as planned:** 500 simulated hosts, a batch every 10 s and a heartbeat every 60 s each, for 1 hour; p95 under 500 ms and 0 lines lost; MB per host per day reported.
- **The simulator runs on the mothership VM itself,** not on a second VM. It shares the CPU, so the timings it reports are pessimistic. If the gate misses by a little, the simulator's own CPU use is reported next to it.
- **The owner's dev-machine agent is the first real-agent test,** once agent 5c and mothership milestone 3 (enrolment) are done; more hosts are added in the pilot.
- **The heartbeat (MS-ING-12) is built in milestone 2,** so the load test carries real heartbeat traffic. `GET /api/v1/config` (MS-ING-07, Should) moves to milestone 3, with enrolment.
- **The VM is resized** to 4 vCPU, 16 GB and 250 GB just before the gate run; the owner gets step-by-step instructions then.

### M-044 PHPUnit, dev only
**Requirements:** CLAUDE.md (Build and test) · **Status:** Accepted 2026-11-19 (owner)
- PHPUnit through Composer as a `require-dev` dependency. BSD-3-Clause, maintained by its author, with releases for PHP 8.5.
- Composer comes from Ubuntu's own package (M-029). Production code has no Composer dependencies; it uses only PHP's built-in PDO, zlib and JSON. `composer install --no-dev` on the server is enough to run the app.

### M-045 Ingest implementation choices
**Requirements:** MS-ING-01 to 05, 08, 09 · **Status:** Accepted 2026-11-19 (built in milestone 2; contract unchanged)
- **Provisional before unknown.** A record type on the provisional list is quarantined as `provisional type, no table yet` even though it isn't a known type. §3.4 lists "unknown" (rule 6) first, which would make the list useless for the new types it exists for.
- **The batch row comes first.** `ingest_batches` is inserted at the start of the transaction (the rows need its id) and its counts filled in before COMMIT. `aio_ingest` gets `SELECT, UPDATE` on `ingest_batches` (an `UPDATE … WHERE` needs SELECT on the key columns). It still can't read any record table.
- **Counting:** a resent line that was quarantined before counts as `quarantined` (it is held). `acked_seq` is the highest `seq` of all the request's lines, quarantined ones included; `max_seq_held` counts stored lines only, so a quarantined line with a wild `seq` can't upset the rollback check.
- **The rollback check** (§3.6) runs per request: a stored, non-duplicate `agent_start` below `max_seq_held`, or more than 1,000 such lines.
- **Certificates:** `valid` and `superseded` certificates are accepted (a renewing agent may still use the old one briefly); `revoked`, or a revoked agent, gets 403 and sets `last_refused_at`. The certificate's CN must equal the registered agent ID.
- **Times in `data`** may carry an offset and are converted to UTC; the envelope `ts` must end in `Z` (rule 4). Go's zero time is stored as NULL.
- **The column map** is generated from the migrations (`tools/gen-table-map.php` → `src/Ingest/Schema/table_map.php`); an integration test compares it with `information_schema`.
- **Back-pressure** (§8.2) uses lock files in `/run/aio-ingest`: 12 concurrent ingest slots, and 5 requests per second per agent over 10 s. Both are settings in `app.ini`.

### M-046 Migration 0002: agent_lifecycle and inventory_link_index keyed on (agent, seq, ts)
**Requirements:** MS-ING-03, MS-DAT-01 · **Status:** Accepted 2026-11-19
- 0001 keyed these two unpartitioned tables on `(agent_pk, seq)`. After a state rollback the new `agent_start` reuses an old `seq` and would have been dropped as a resend, though it is the record the rollback check looks for (M-005).
- 0002 changes both primary keys to `(agent_pk, seq, ts)`, like every other record table. 0001 is unchanged (it is approved and applied).

### M-047 Database credential files readable by their own pool
**Requirements:** MS-SEC-04, MS-SEC-10 · **Status:** Accepted 2026-11-19
- `/etc/aio-mothership/db/` is `711 root`: a pool can open its own file, nobody can list the directory.
- `ingest.cnf` is `640 root:aio-ingest` and `dashboard.cnf` `640 root:aio-dashboard` once 08 has created those users; `maint.cnf` stays `600 root`. 06 sets this and checks it; 09 checks the ingest pool can read its own file and not `maint.cnf`.

### M-048 Agent API routing
**Requirements:** MS-ING-01, MS-ING-09 · **Status:** Accepted 2026-11-19
- `FallbackResource /index.php` in the 8443 vhost sends every path that isn't a file to `public/agent/index.php`. No `mod_rewrite`.
- Milestone 2 answers `/api/v1/ingest` and `/api/v1/heartbeat`. Every other path, including enrol, config and renew until milestone 3, gets 404 `not_found` in the §7 shape.
- A missing client certificate is refused (401) before the database is touched.

### M-049 Simulated agents and the simulator
**Requirements:** CLAUDE.md "Build and test", MS-ING-10, MS-DAT-06 · **Status:** Accepted 2026-11-19; the volume profile is *(confirm, MPD-35)*
- **Identities:** `tools/dev-agents.php` (root) issues client certificates straight from the agent CA with the §2.3 profile and registers them as enrolment will. All simulated hosts are in host group `simulator`; `delete` removes exactly those rows and files.
- **Behaviour:** the simulator follows §8.1: one request in flight per agent, lines deleted only on a 200, full-jitter backoff with `Retry-After` as a floor, 413 split. With `--faults` it resends acknowledged batches and adds malformed, unknown-type and missing-field lines, and checks every answer's counts.
- **0 lost:** each simulated agent keeps, across runs, the rows it expects from acknowledged lines (executables and gaps by their natural key). `tools/reconcile.php` compares that with the database per agent and table.
- **Figures:** `tools/measure.php latency` (from `ingest_batches` and Apache's `%D`), `tools/measure.php size` (MB per host per day from table sizes). The simulator reports client-side p95, which is the gate figure: it is the strictest.
- **Volume per host** is `Aio\Sim\Profile`: 20 flows per 10 s, a snapshot every 5 minutes (40 connections, 12 listeners, 6 UDP), 2 process start/stop pairs a minute, ETW status every minute, a new executable every 5 minutes, a full inventory (60 apps, 120 services) at start and daily. These are estimates.

### M-050 Milestone 2 gate at 2 servers and 2 laptops; the hardware stays as it is (MPD-36)
**Requirements:** MS-ING-10, MS-DAT-06, MS-UI-08, CLAUDE.md milestone 2 · **Status:** Accepted 2026-11-19 (owner)
- **Found:** the Unraid server has no SSD (ten 1.2 TB 10k SAS hard drives, seven in the parity array, no pool). The VM disk is on `disk1` in the array, as 3 qcow2 snapshots over a raw file, reached through `/mnt/user`. A disk sync takes p50 17 ms, p95 46 ms, max 97 ms, and every ingest COMMIT waits for one.
- **Gate:** milestone 2's gate is **2 simulated servers and 2 simulated laptops** (`simulate.php --agents=4 --laptops=2 --duration=3600 --faults`), p95 under 500 ms, 0 lost, with MB per host per day reported for each kind. It replaces the 500-host gate.
- **Laptops** use `Profile::LAPTOP`: Windows 11, more apps and services, more short-lived connections and processes, no roles, IIS, SQL Server or server apps. Estimates, *(confirm)* with MPD-35.
- **Hardware:** stays as it is for development and the pilot. No resize before the gate: 2 vCPU, 8 GB and 100 GB are enough for 4 hosts. Production hardware (SSD, size) is specified before production, with the backup work (M-026).
- **500 hosts:** still the design target. MS-ING-10 (500 agents, p95 under 500 ms) and MS-UI-08 (pages under 5 s at 500 hosts) stay Must, verified on the production hardware, not on this VM.
- **The database sync stays on** (`innodb_flush_log_at_trx_commit = 1`): relaxing it would make acknowledgements faster but could lose an acknowledged batch in a power cut, after the agent deleted it.
- **Snapshots:** kept for now. Once the project reaches a working state with a readable dashboard, the chain is merged into one raw file and the snapshot files are removed. Until then, reverting a snapshot also throws away everything ingested since it was taken.
- **Milestone 5 gate (owner, 2026-11-19):** the same change: every page under 5 s at 2 simulated servers and 2 simulated laptops with 7 days of data. MS-UI-08 at 500 hosts is verified on the production hardware.

### M-051 Milestone 2 signed off; laptops are test variety only
**Requirements:** MS-ING-01..05, 08..10, 12, MS-DAT-06 · **Status:** Accepted 2026-11-20 (owner)
- Milestone 2 is signed off as reported (status.md, "Milestone 2 report").
- **v1 monitors servers.** The simulator's laptops are there for test variety only; figures for planning (MPD-37) use the server profile.
- Pilot capacity (50 hosts) and disk sizing stay open in pending-decisions.md (MPD-37), decided later.

### M-052 Milestone order: dashboard core next, then enrolment and retention
**Requirements:** CLAUDE.md milestones · **Status:** Accepted 2026-11-20 (owner)
- Order from here: **milestone 5 (dashboard core)** on simulator data, then **milestone 3 (enrolment)**, then **milestone 4 (retention)**. Milestones 6 and 7 follow as before. The numbers stay, so requirement and decision references don't change.
- Effects: the dashboard is built before enrolment, so agents are still the simulator's (`tools/dev-agents.php`); "silent agent flagged" (milestone 3's gate) can already show on the map. Retention and partition jobs come after the dashboard, so the 7 days of simulator data are kept by hand until milestone 4.

### M-053 The analyst database user and the narrowed dashboard grants (M-041 details)
**Requirements:** MS-SEC-04, MS-SEC-07, MS-SEC-08, MS-SEC-09 · **Status:** Accepted 2026-11-22 (owner, milestone 5 plan)
- **`aio_analyst`:** SELECT on `process_command_lines` and INSERT on `audit_log`, nothing else. Credentials in `/etc/aio-mothership/db/analyst.cnf`, 640 root:aio-dashboard (the dashboard pool reads it).
- **When it is used:** only after the server has checked the signed-in user is an analyst or admin. The command line is read and its `view_command_line` audit row written in one transaction on that connection, so no command line is shown without an audit row.
- **`aio_dashboard`:** SELECT on every table except `process_command_lines`, granted per table by `setup/06-mariadb.sh` from the live schema (re-run 06 after a migration). Writes only `users` (INSERT, UPDATE) and `audit_log` (INSERT). The alert, enrolment and admin write grants that `grants.sql` had are removed until the milestones that build those pages (3, 6, 7) add them back.
- 06 checks each of these, both ways (can and can't).

### M-054 Dashboard core design (milestone 5)
**Requirements:** MS-SEC-05..10, MS-UI-01, 02, 07, 08, 09, MS-DAT-04, 05 · **Status:** Accepted 2026-11-22 (owner, milestone 5 plan)
- **Pages:** server-rendered PHP, one front controller (`public/dashboard/index.php`, Apache `FallbackResource`), templates in `templates/` outside the web root, every printed value through `View::e()`. JavaScript in files only.
- **Headers (Apache):** CSP `default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'`, no framing, referrer only to the dashboard itself (M-055), no caching, COOP and CORP same-origin.
- **Sign-in:** local accounts, Argon2id (`password_hash`), at least 12 characters *(confirm)*, lockout after 5 failures for 15 minutes *(confirm)*, one message for every failure; an unknown user name is not stored in the audit log (it could be a mistyped password).
- **Sessions:** PHP file sessions in `/var/lib/php/sessions-aio-dashboard` (700, dashboard pool only), cookie `__Host-aio` (Secure, HttpOnly, SameSite=Strict), new ID at sign-in and sign-out, 30 minutes idle and 12 hours in all *(confirm)*. The user is read from `users` on every request, so a role change, disable or lock applies at once.
- **CSRF:** a per-session token on every form, plus Origin and Sec-Fetch-Site checks when the browser sends them.
- **Roles:** viewer < analyst < admin; admin includes analyst, so it sees command lines; every view is still audited.
- **Accounts:** `tools/users.php` only in milestone 5 (add, list, role, password, unlock, disable, enable), each change audited as `cli:<login>`. `unlock` clears a lockout, so the only admin is never locked out for long.
- **Map:** Cytoscape.js **3.34.4** (MIT, The Cytoscape Consortium), vendored at `public/dashboard/assets/vendor/cytoscape-3.34.4/` with its LICENSE, served from the VM, no CDN.
  - `cytoscape.min.js` SHA-256 `081591ab735b2b6a3b31a3c60f8f3a3e2fee8c92939e0c7820ad1208571b6cd8` (435,649 bytes); `LICENSE` SHA-256 `eb319c6e6f233607f71e8e2f450391751883cfc0eeb3ca7ef574c13d1d9c2203`.
  - From the npm tarball `cytoscape-3.34.4.tgz`, integrity `sha512-msY99tMY8iWTg+sIPGfSqDcPNOt4xcj+v/K/7qUFaCUxxlqnMkUYLBjRUzn9W5CU/R2hrmtJa5+UtovrEM2ctg==` (checked on download). `setup/10-dashboard.sh` checks the SHA-256 on the VM.
  - Cytoscape inserts an inline `<style>` unless an element with id `__________cytoscape_stylesheet` exists; the page's stylesheet link has that id, so the CSP stays strict.
- **Map content:** each host an icon (name, version, last check-in; amber when stale, no check-in for 5 minutes *(confirm)*); lines to other monitored hosts (matched by their IP addresses); the 15 busiest unmonitored internal addresses as their own nodes and the rest as one "other internal" node; every outside address as one "internet" cloud. Internal means the ranges in `dashboard.internal_networks` (RFC 1918, CGNAT, link-local, IPv6 ULA and link-local). Alerts show as "not built yet (milestone 7)".
- **Connections data:** `flow_conn_hourly` (migration 0003) holds one row per connection (D-016 key) per hour, filled every minute by a systemd timer as `aio_maint`, from batches at least 90 s old. The map and connections tab read it; the page says how far it reaches (normally 1 to 3 minutes behind). This replaces "the latest hour read from the flow records" in the plan: it keeps pages fast and the lag is shown.
- **Simulator:** the simulated hosts talk to each other (laptops to servers on 443, 445 and 1433; servers record the inbound side), to an unmonitored domain controller, file and update server, to printers and devices, and to internet addresses. `--days=N` replaces `--day`.

### M-055 Referrer policy same-origin; Origin "null" accepted only with Sec-Fetch-Site same-origin
**Requirements:** MS-SEC-05 · **Status:** Accepted 2026-11-22 (fix found on the VM, milestone 5 step 3)
- **Found:** signing in from Edge always said "the sign-in form had expired". Under `Referrer-Policy: no-referrer` Chromium sends `Origin: null` on a same-origin form post, and the CSRF check refused every opaque origin. curl, which sends a real Origin, signed in fine.
- **Changed:** the dashboard vhost sends `Referrer-Policy: same-origin` (the dashboard's own addresses are still never sent to another site), so browsers send the real Origin. `Csrf::valid` also accepts `Origin: null` when, and only when, the browser sends `Sec-Fetch-Site: same-origin` (a header pages can't set), for browsers or settings that still send null. The session token is required in every case; a cross-site or typed-address request with an opaque origin is still refused.
- Amends M-054's "no referrer" header.

### M-056 Host page design (milestone 5 step 4)
**Requirements:** MS-UI-02, MS-UI-07, MS-UI-08, MS-DAT-04, MS-DAT-05, MS-SEC-07..09 · **Status:** Accepted 2026-11-23 (built to the owner's milestone 5 scope and the plan approved 2026-11-22; owner OK to start step 4)
- **Page:** `/hosts/{id}?tab=…&range=1h|24h|7d` (default 24 h). A header (status, last check-in, agent version, OS, addresses, domain, last boot, hardware), the tabs, the range (on time-based tabs), the host's coverage gaps for the range (on every tab, MS-UI-07), then one tab. Each tab is its own request and reads only what it shows (MS-UI-08). Server-rendered; no new JavaScript.
- **Tabs, in order:** Connections (first, per the owner), Listening ports, Processes, Executables, Inventory, Server software. Room for the agent's milestone 5d tabs (Security, Shares, Health, Network, Sign-ins; MPD-39) is kept in `HostQuery::LATER_TABS`; they are not shown until their records have tables.
- **Connections:** recent = `flow_conn_hourly` over the range, one row per D-016 key, newest first, with how far the data reaches; live = the latest network snapshot's TCP connections. A remote address that belongs to another monitored host links to that host.
- **Listening ports** and **live connections:** the latest network snapshot, with its time.
- **Inventory** and **server software:** the latest *complete* inventory snapshot, read from the `inv_*` tables between its start and end markers (`inventory_current` is filled by a later job, milestone 4). Sources the agent reports as partial or failed are shown. Inventory changes in the range are listed (time-based).
- **Server software:** IIS with its read status always shown, so an unreadable configuration never looks like "no sites"; sites with bindings; app pools; SQL Server instances with static and dynamic ports; other detected products. "Who owns each port": the agent's link on the listener first (with its match_source); else an IIS binding or SQL Server port from the configuration (marked *likely*, since configuration is not proof); else the listener's services; else the process only.
- **How sure (MS-DAT-05):** every source field (direction_source, user_source, match_source, hash_status with D-056's reason and privilege, signature status, IIS read status) is shown as fact, likely, guess or unknown, styled differently; a value the agent adds later is shown as it is, marked unknown. Labels follow the agent's values: `direction_source` etw (fact), etw_first_packet (likely), heuristic (guess); empty `user_source` means the process token (fact); `match_source` msi_component and sql_instance (confirmed), display_icon (likely), install_location (guess).
- **Command lines (MS-SEC-07..09):** never part of any page. An analyst or admin clicks "Show", a POST with the CSRF token (so a link, prefetch or history never opens one). The process is looked up as `aio_dashboard` on the page's host (another host's command line can't be reached by changing numbers); the command line is read as `aio_analyst` and its `view_command_line` audit row (target `process`, `agent_pk:seq`; details: host, PID, process name, shown or cleared; never the command line) is written on that connection in the same transaction. A cleared command line is reported as cleared, and that look is audited too. Viewers see that one is stored, its length and the agent's redaction flag, never the text.
- **Lists** are capped at `dashboard.host_rows` (500) rows and say when they were cut and how many there are in all.

### M-057 Host page indexes and capped counts (gate finding)
**Requirements:** MS-UI-08 (M-050) · **Status:** Accepted 2026-11-23 (fix for the first gate run)
- **Found:** the first `tools/page-times.php` run (4 hosts, 7.8 days, 6.9 M flow rows, 530,114 process events) passed every page except the processes tab over 7 days: **16.3 s**, server side. The executables tab took 1.8 s. Everything else was under 100 ms.
- **Cause:** the processes list and its count filtered `record_type` after reading every row of the range from disk (`ix_pe_host_ts` has no `record_type`), and joined every start to its stop before the 500-row limit. The executables list sorted all of the host's rows.
- **Changed:** migration 0004 adds `process_events (host_pk, record_type, ts)` and `executables (host_pk, last_seen)` (keys only, built online). The processes query takes the newest 500 of each kind through the new index first, then joins only those to their stops, looking for stops only in the range (so older partitions are skipped once milestone 4 makes daily ones). Counts stop at 100,000 and then say "more than 100,000", so a count never reads a whole large range.

### M-058 Milestone 5 signed off
**Requirements:** MS-UI-01, 02, 07, 08, MS-SEC-04..10, MS-DAT-04, 05 · **Status:** Accepted 2026-11-23 (owner)
- The owner signed off milestone 5 (dashboard core) as reported in status.md, "Milestone 5 report": gate passed at 2 simulated servers and 2 laptops with 7.9 days of data, slowest page 416 ms (M-050, M-057).
- Next, per M-052: milestone 3 (enrolment), then 4 (retention), each starting with a plan for the owner's OK.
- The owner now removes the VM snapshot chain, as agreed in M-050 once the dashboard works.

### M-059 Milestone 3 plan accepted; points from the agent's 5c plan
**Requirements:** MS-ING-06, 07, 08, 11, 12, MS-SEC-02, 03, 10 · **Status:** Accepted 2026-11-23 (owner)
- **Plan:** the milestone 3 plan given on 2026-11-23 (status.md) goes ahead. Its four open points are MPD-40 to MPD-43; none blocks items 1 to 3.
- **Build order:** the signing service, the token CLI and `POST /api/v1/enroll` first (plan items 1 to 3), so the real agent (5c, agent D-085) can test enrolment early. **No separate dev signing tool** (agent PD-66 is not needed): the dev PC enrols with a real one-time token.
- **The agent CA travels as a PEM plus its SHA-256** (agent PD-59): the installer takes both and refuses a PEM that doesn't match. The mothership already writes `/etc/aio-mothership/pki/public/agent-ca.crt` and `fingerprints.txt` (07-pki.sh, M-037); milestone 3 adds an export command that prints both in the form the installer takes.
- **`seq_epoch` (agent PD-65, contract §3.6):** the first `seq_epoch` the mothership sees for an agent (its first 5c `agent_start`, also after an upgrade from a pre-5c agent) is a **baseline, not a recreated state file**. Only a later change of epoch under the same `agent_id` counts as one. Today the field is kept as an unknown optional field (extra JSON) and not acted on; this rule applies when the epoch check is built.
- **Batch size (agent PD-61):** the agent closes a batch at 1 MB **before** gzip. The mothership's 10 MB compressed request limit is unaffected.
- **Config editing, when built:** stored values are checked against the agent's own bounds (agent `docs/milestone-5c-plan.md`, PD-62), so the dashboard can't save a value the agent would refuse. The config endpoint in milestone 3 serves defaults only.
- **The sender:** the agent's network code runs as a separate service account and sends heartbeats with the queue size and an **"undeliverable" count**. When that field arrives, the fleet table shows it with the agent. Until its name is in the agent's docs it is kept as an unknown optional field, never quarantined.

### M-060 The signing service (milestone 3 step 1)
**Requirements:** MS-SEC-02, MS-ING-06, MS-ING-11, MS-SEC-10 · **Status:** Done 2026-11-24 (11: 18 of 18 on the VM)
- **Shape:** a systemd socket, `/run/aio-signer.sock` (660 root:aio-ingest, `Accept=yes`, at most 8 at once). Each request starts one `aio-signer@` copy running `bin/signer.php` as `aio-signer` (M-037). The connection is its stdin and stdout: one JSON request, one JSON answer (`SignerProtocol`), then it exits. No daemon stays running with the key loaded.
- **Sandbox:** no network (`PrivateNetwork`), only `AF_UNIX`, no capabilities, read-only system (`ProtectSystem=strict`, `ProtectHome`), private `/tmp`, `@system-service` system calls, 20 s run limit. It has no database credentials. It refuses to run as root.
- **It trusts nothing from its caller:** it re-checks the CSR (`CsrCheck`: a lower-case UUID v4, a single CN equal to it, ECDSA P-256 or P-384), verifies the CSR's own signature, and sets every field of the certificate from `CertProfile`, which is fixed in code, not in a settings file. Then it checks its own output (chain, subject, serial, key, extensions) before answering.
- **The openssl tool, not PHP's extension, does the signing:** PHP can neither verify a CSR's signature nor replace its subject, backdate `notBefore` or drop requested extensions. `openssl x509 -req … -set_subject … -clrext -not_before -not_after` does all of it (OpenSSL 3.4 or later; 11 checks the version). It's run with an argument list, never a shell, with a small environment and a 10 s limit.
- **Serial:** 16 random bytes with the top bit cleared and the next one set, so it is positive and always 32 hex digits (126 random bits). §2.3 says 128; a positive serial of fixed length can't have all 128. The certificate's own serial is what gets stored.
- **Logging (MS-SEC-10):** one journal line per request with the agent ID, serial and outcome; never the CSR, the certificate or a key.
- **The CRL** (MPD-41) isn't part of this. If it is built, it is made by a root job, because it needs the list of revoked serials from the database, which the signer can't read.

### M-061 Enrolment tokens from the CLI, and the CA export (milestone 3 step 2)
**Requirements:** MS-SEC-03, MS-SEC-08, MS-SEC-10, M-021 · **Status:** Done 2026-11-24 (11: 32 of 32 on the VM)
- **`bin/token-create.php create | list | cancel`**, run with sudo as `aio_maint` (its credentials are root-only, M-036). `manual` tokens last 24 h; `deployment` tokens last `--days`, 1 to 7 (default 7). Options: `--group`, `--hostname`, `--reenroll=<agent ID>` (only for an existing, active agent), `--note`. The lifetimes are settings (`[enrol]` in app.ini) with the M-021 values.
- **The token is printed once.** When standard output isn't a terminal, only the token goes there and the details go to standard error, so `> token.txt` gives a file the installer can take as it is (agent `--enroll-token-file`).
- **Stored:** SHA-256 over the whole string, `aioe_` included. The creator's login is kept in the new column `created_by_name` ("cli:aioadmin"), because `created_by` is a dashboard user ID.
- **Cancelling** (migration 0005, `cancelled_at`, `cancelled_by`): only an open token can be cancelled. Enrolment refuses a cancelled token with the same `enrollment_token_invalid` as an unknown one. The admin page (MPD-40) will use the same `TokenStore`.
- **Audit (MS-SEC-08):** `enrollment_token_created` (kind, group, expiry, hostname and re-enrolment limits) and `enrollment_token_cancelled`, as "cli:<login>". Never the token or its hash (MS-SEC-10).
- **`bin/agent-ca.php`** prints the public agent CA's PEM and its SHA-256 fingerprint (DER bytes), as 64 lower-case hex digits and in openssl's colon form; `--pem` and `--sha256` print one each. No sudo needed. **Agent PM (2026-11-24):** the agent's `--ca-sha256` accepts either form; keep printing both.
- **`tools/gen-table-map.php`** now follows `ALTER TABLE … ADD COLUMN` (with `AFTER` / `FIRST`) instead of stopping. Other column changes still stop it.

### M-062 POST /api/v1/enroll (milestone 3 step 3); the ingest user may add audit rows (MPD-42)
**Requirements:** MS-ING-06, MS-SEC-02, MS-SEC-03, MS-SEC-08, MS-SEC-10, M-003, M-021 · **Status:** Done 2026-11-24 (11: 43 of 43 on the VM)
- **MPD-42 (owner, 2026-11-24):** `aio_ingest` gets INSERT only on `audit_log`; no SELECT, UPDATE or DELETE. `setup/sql/grants.sql`; 11 checks it.
- **No client certificate** on this path (Apache asks for one as optional, M-001); the one-time token is the credential. Every other path still needs one.
- **Order:** content type, size (16 KB), the six fields as strings, the token's format, `host_id` (a GUID), `hostname`, `agent_version`, the CSR (`CsrCheck`) — all before the database. Then one transaction: the token row locked (`FOR UPDATE`; unknown, used, cancelled, expired, or the wrong `expected_hostname` all get 403 `enrollment_token_invalid`), the agent row locked, the agent rules of §2.5, signing, host, agent, certificate and token rows, the audit rows, COMMIT. Any refusal rolls back, so **a refused request never spends the token**.
- **Agent ID rules (§2.5):** new: accepted. Revoked: 403 `agent_revoked`. Enrolled (active or replaced): 409 `agent_id_in_use`, unless the token is a re-enrolment token for that ID. A re-enrolment token used for any other agent ID, or for an ID that isn't enrolled, gets 403 `enrollment_token_invalid`.
- **Re-enrolment:** every certificate of the agent that isn't already revoked is revoked ("re-enrolment"), the new one is issued, and the agent points at the host found for this request. Audit: `agent_certificates_revoked` (the serials) and `agent_reenrolled`. An ordinary enrolment writes `agent_enrolled`. The actor is `agent:<agent ID>`; details hold the serial, token ID and kind, group, hostname and version, never the token, CSR or certificate.
- **Host:** found by `host_id` (stored lower-case) and `hostname` together (D-003), or made in the token's group. **An existing host keeps its group**; `host_group` in the answer is the host's actual group.
- **The address limit:** every 400, 403, 409, 413 and 415 adds a row to `enrollment_failures`; at 10 in 10 minutes from one address, 429 `too_many_enrollments` with `Retry-After` until the oldest leaves the window. Settings `enrol.max_failures`, `enrol.failure_window_s`. Old rows are removed by the retention job (milestone 4).
- **The signer down:** 500 `internal_error`, retryable; nothing committed, the token unused.
- **`tools/enrol-check.php`** (run by 11): enrols a throwaway agent through the real API on 8443, sends a heartbeat with its certificate, checks the reused token (403) and a second token for the same ID (409), revokes it and checks its heartbeat is refused (403 `agent_revoked`), then deletes the agent, its host and its rows (group `setup-check`) and its own failure rows. Audit rows stay.

### M-063 Ingest API contract v1.1: the agent's `undeliverable` file (detail only)
**Requirements:** MS-ING-09, MS-ING-12, AG-DAT-04 · **Status:** Recorded 2026-11-27, from the agent's approved 5c plan (agent PD-67, D-087, D-088), passed on by the PM
- **Why v1.1, not v2:** it only adds detail. No field, answer or rule a v1 agent relies on changes meaning, and the URLs stay `/api/v1`.
- **§8.1 rule 7:** after 3 answers retrying can't fix for the same batch (400 `bad_gzip` or `empty_batch`, or an `acked_seq` that doesn't match), the agent splits the batch in half, as for 413, down to single lines. A single line that still fails goes to a local encrypted `undeliverable` file in the queue folder (cap 10 MB, a config value, D-088), is counted in the heartbeat, and gets a `coverage_gap` record with `reason: "undeliverable"`. When the file is full, the line is dropped with the same record. Nothing unacknowledged is deleted for any other reason; 429 and 5xx keep the backoff rules.
- **§4:** the heartbeat gains `queue.undeliverable_records` (lines moved there since install) and `queue.undeliverable_bytes` (the file's size now). **The names are the mothership's proposal; the agent PM confirms them.** Until then, and for any other name, the value is kept in the heartbeat body like every unknown field.
- **§9:** `coverage_gap` may carry `reason: "undeliverable"`. `coverage_gaps.reason` is free text (VARCHAR 64) and the host page shows it as sent, so ingest needs no change.
- **The fleet table** shows the undeliverable count with the agent (M-059). It is built with milestone 3 step 8 (silent agents flagged), which changes the fleet table anyway: a column on `agents` filled by the heartbeat, like `queue_dropped_records`.

### M-064 POST /api/v1/renew (milestone 3 step 4)
**Requirements:** MS-ING-11, MS-SEC-02, MS-SEC-08, MS-SEC-10, M-018 · **Status:** Done 2026-11-27 (11: 50 of 50 on the VM)
- **Who:** the agent named by the client certificate in use (ClientCertAuth, MS-ING-02); a revoked certificate or agent gets 403 `agent_revoked`. The agent row and the certificate in use are locked in the transaction, so a revocation at the same moment is seen.
- **The CSR:** the §2.3 checks with the agent's own ID as the CN, and **a new key**: the key of the certificate in use gets 400 `csr_invalid` (new reason `same_key`, "reuses the key of the certificate in use").
- **The result:** the new certificate is added as `valid` (`issued_via = renew`), every other valid certificate of the agent becomes `superseded`. Superseded certificates still authenticate until they expire (§2.6), and can renew again, so a lost answer costs nothing. The answer has the §2.2 fields, with status 200.
- **Audit:** `agent_certificate_renewed`, actor `agent:<agent ID>`, with the old and new serial and the number superseded; never the CSR or the certificate.
- **The heartbeat's `renew_after`** (§4): null until the certificate in use is due (30 days before its expiry), then that due time. Before, it was always null.
- **Shared code:** `CertIssue` (the JSON body, `csr_invalid`, sign and register, the answer), used by enrolment and renewal. Enrolment's behaviour is unchanged.
- **Not limited:** how often an agent renews. Each renewal is audited and the per-agent request rate applies (§8.2). A health check for an agent renewing much more than once a year can come with milestone 4's health checks.
- **`tools/enrol-check.php`** (run by 11) now also renews over 8443: the same key 400, a new key 200, both certificates working, and after the revocation both refused and no renewal.

### M-065 GET /api/v1/config (milestone 3 step 5)
**Requirements:** MS-ING-07, AG-CFG-01..03, AG-SEC-07, MS-SEC-10, M-008 · **Status:** Built 2026-11-27, waiting for the VM run
- **What is served:** the built-in defaults (the contract's, which are the agent's own), with the stored overrides of the agent's host group (`agent_config`) on top. A group without a row gets the defaults, `config_version` 0. Nothing edits `agent_config` yet (MPD-43).
- **Checked again when served** (`AgentSettings`), so a bad row can never reach an agent:
  - numbers against the agent's bounds (agent 5c plan §5, PD-62). A value out of bounds, not an integer, or an unknown key keeps the default. "MB" and "GB" in the agent's table are read as MiB and GiB, matching its defaults (500 MB = 524,288,000): 64 KiB to 8 MiB, 50 MiB to 4 GiB, 1 to 32 KiB, 0 to 2 GiB, 1 to 256 MiB/s, 1 to 64 MiB. **To confirm with the agent PM.**
  - collectors must be booleans;
  - the detection list against §5.1: only the six `DetectEntry` fields (an unknown field such as a path or command refuses the entry), service names or prefixes, HKLM keys under SOFTWARE or SYSTEM with no hive, `.` or `..`, at most 12 components, value names without `\ / : %`; at most 100 entries and 64 KB (CLAUDE.md hard rule);
  - exclusions: `process_path` (a drive-letter path, or a folder ending in `\*`, no `..`), `remote_cidr` (IPv4 or IPv6), `port` (1 to 65535, or a range), a note up to 200 characters;
  - redaction rules: `next_arg`, `equals` or `conn_string`, a name of 1 to 64 of `[A-Za-z0-9_.-]`. Additive only: nothing served can turn the agent's own redaction off.
- **Left out** entries and values are logged as "aio agent-api config group_id=N: left out <place>: <reason>", never with the value (MS-SEC-10).
- **ETag:** `"cfg-<group id>-<config_version>-<12 hex>"`, the hex a SHA-256 of what is served (without `generated_at`). It changes when the defaults change, not only when the row does. `If-None-Match` with it gets 304 and no body. **The heartbeat now answers with the same computed ETag**; the `etag` column of `agent_config` isn't used for serving.
- **List limits** the contract doesn't give: 500 exclusions and 100 redaction rules, config defaults marked *(confirm)* (MPD-44).
- **`tools/enrol-check.php`** (run by 11) now also fetches the config over 8443: 200 with the group's settings, the ETag equal to the heartbeat's, 304 with `If-None-Match`, 401 without a certificate.
