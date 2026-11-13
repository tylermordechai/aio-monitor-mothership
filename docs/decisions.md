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
- **The tech lead's answers:** MPD-01, 02, 04, 10, 14 to 18, 21 to 24 accepted as recommended; MPD-03 (a), 05 (a), 06 (a, with the `seq_epoch` correction in M-020), 07 (a), 08 (a), 09 (a, TLS 1.3 on 8443), 11 (a), 12 (a), 13 (a); MPD-19 Ubuntu 24.04 LTS and MariaDB 11.8 LTS.
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
**Requirements:** MS-OPS-07 · **Status:** Accepted 2026-11-13 (MPD-19)
- **Ubuntu Server 24.04 LTS**, **MariaDB 11.8 LTS** from the MariaDB repository (Ubuntu 24.04's own package is 10.11), PHP 8.3, Apache 2.4.
- 11.8 is supported to 2028 and has everything the schema uses (JSON_VALUE, RANGE COLUMNS partitions, virtual columns).
