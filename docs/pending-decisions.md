# Pending decisions

Last updated: 2026-11-13 (milestone 1, contract and schema drafted)

Every *(confirm)* value and open question for the tech lead, each with a recommendation. To answer, reply with the ID and your choice, for example "MPD-05: a". Answers are recorded in [decisions.md](decisions.md) and removed from here.

- **Contract-affecting items** (they change what agent 5c builds) are marked **[contract]**. The milestone 1 gate needs these answered.
- Values that live in config or data keep the recommended default until answered.

## A. Ingest contract [contract]

### MPD-01 Maximum compressed batch size
- **Plan:** 10 MB *(confirm)*, MS-ING-01. The agent aims for 1 MB or 10 s (AG-DAT-03).
- **Recommendation:** keep **10 MB**. That is 10× the agent's target, enough for a large inventory snapshot in one request, and small enough for PHP's `post_max_size` and memory.

### MPD-02 Other request limits
- **Proposed:** decompressed at most **64 MB** (zip-bomb guard), at most **50,000 lines**, one line at most **1 MB** (a longer line is quarantined, not the batch).
- **Recommendation:** accept. Measure in the milestone 2 load test and adjust if a real record type comes close.

### MPD-03 Certificate lifetime and a renewal endpoint
- **Plan:** 1 year *(confirm)*, MS-ING-06. MS-ING lists no renewal, so without one every agent needs a new token by hand each year.
- **Options:**
  - (a) 1 year, plus `POST /api/v1/renew` (mTLS with the current certificate and a new CSR), called 30 days before expiry;
  - (b) 1 year, re-enrolment by hand;
  - (c) a longer lifetime, no renewal.
- **Recommendation:** **(a)**. It adds a requirement, proposed as MS-ING-11 (Should), for the plan. A lost certificate still needs a re-enrolment token.

### MPD-04 Enrolment token lifetime
- **Plan:** 24 h *(confirm)*, MS-SEC-03.
- **Recommendation:** **24 h** for tokens typed by hand. Allow up to **7 days** for tokens put into an Intune or GPO deployment, chosen when the token is made, with the longer ones shown as such in the admin page. Each is still single-use.

### MPD-05 What `acked_seq` means
- **Context:**
  - MS-ING-01 says the reply carries "the highest sequence number it now holds".
  - The agent's `seq` is one counter shared by several writers, reserved in ranges (`identity.go`). Records reach the queue out of order and gaps are normal (ingest-api.md §3.6).
- **Options:**
  - (a) **per request:** a 200 acknowledges exactly the lines of that request, and `acked_seq` (the highest `seq` in it) is only a check;
  - (b) **watermark:** the agent deletes everything up to `acked_seq`, which needs the agent to send strictly in `seq` order and the mothership to track gaps, and a crash gap would block it.
- **Recommendation:** **(a)** (M-004). The plan's wording would change to "returns `{"acked_seq": N}`, the highest sequence number in the acknowledged batch".

### MPD-06 Dedup key and `seq` rollbacks
- **Context:** MS-ING-03 says records are unique on `(agent_id, seq)`. Daily partitions need `ts` in every unique key.
- **Options:**
  - (a) unique on `(agent, seq, ts)` per table, with rollback detection (M-005);
  - (b) a separate global `(agent, seq)` ledger: about 40 million extra rows a day at 500 hosts.
- **Recommendation:** **(a)**. It catches every resend and never loses a record after a state rollback.
- **Also asked of agent 5c:** an optional `seq_epoch` on `agent_start`, so rollbacks are detected exactly.

### MPD-07 Heartbeat: own endpoint or empty ingest call
- **Context:** AG-PRF-06 needs a heartbeat even when there is nothing to send; the user asked which form.
- **Options:**
  - (a) `POST /api/v1/heartbeat` with queue size and health;
  - (b) an empty ingest request;
  - (c) a `heartbeat` record type in the batch stream.
- **Recommendation:** **(a)** (M-006). (b) can't carry queue size cleanly. (c) uses up `seq`, waits in the queue, and is resent after an outage, when it no longer means anything.

### MPD-08 Enrolment on 8443 without a client certificate
- **Options:**
  - (a) one port 8443 with `SSLVerifyClient optional`, PHP requiring a certificate everywhere except `/enroll`;
  - (b) a separate enrolment port (e.g. 8444) with no client certificates;
  - (c) per-location `SSLVerifyClient require`, which needs renegotiation that TLS 1.3 and Go don't support.
- **Recommendation:** **(a)**. One firewall rule. The enrolment endpoint is rate-limited, and does nothing without a valid token.

### MPD-09 Server certificate on 8443, how the agent gets the CA, TLS version
- **Options for the 8443 certificate:**
  - (a) issued by the private agent CA, which the agent pins (one CA for both directions);
  - (b) issued by the internal or public CA used for the dashboard, with the agent pinning that CA or the certificate.
- **Recommendation:** **(a)**. Ship the CA certificate's SHA-256 fingerprint as an MSI property, next to the collector address and token (AG-PLT-04). Keep 443 on the normal server certificate.
- **TLS version:** require **TLS 1.3 on 8443** (the agent is Go, so all agents support it). Keep 1.2 or later on 443 for browsers.

### MPD-10 Key types and CA lifetime
- **Proposed:** agent keys ECDSA P-256; CA ECDSA P-384 with a 10-year lifetime; signatures SHA-384.
- **Recommendation:** accept. Plan a CA rollover runbook at year 8 (milestone 4 docs).

### MPD-11 Who issues the agent ID
- **Options:**
  - (a) the agent's existing random UUID (D-004) becomes the certificate CN;
  - (b) the mothership issues an ID at enrolment, and the agent switches to it.
- **Recommendation:** **(a)** (M-003). No mapping, and records queued before enrolment stay valid. Re-enrolling an ID that is already in use needs a token made for that ID.

### MPD-12 Inventory item records: `key` and `snapshot_id` (ask the agent)
- **Context:** `inv_*` and `host_facts` records don't carry their change-detection key or their snapshot ID; only `inventory_change` has the key. The mothership can work both out (the agent's key rules; the `seq` range between the markers), but that copies agent logic and breaks if the agent's rules change.
- **Options:**
  - (a) ask agent 5c to add `key` and `snapshot_id` to every item record (new optional fields, D-073, no schema bump), and derive them only for older agents;
  - (b) always derive them.
- **Recommendation:** **(a)**.

### MPD-13 `inventory_link_index`: table or provisional?
- **Context:** a 5b record with an exact format in the agent's status.md, not in CLAUDE.md's list of record types.
- **Options:**
  - (a) a table now, like the other 5b types;
  - (b) quarantine it as provisional until the PM confirms it.
- **Recommendation:** **(a)** (M-016). Please also add it to CLAUDE.md's list.

### MPD-14 Quarantine retention
- **Plan:** 30 days. Provisional record types are kept in quarantine until their table exists, and 30 days may expire them before then.
- **Recommendation:** **90 days**, the same as detail data. The quarantine should stay small. A health check alerts when it grows by more than 1,000 lines a day.

### MPD-15 Timestamp window
- **Proposed:**
  - lines with `ts` more than **24 h in the future** are quarantined (`ts_out_of_range`);
  - so are lines older than their table's retention, which can only happen after an outage longer than retention;
  - the clock skew from each heartbeat is shown on the fleet page.
- **Recommendation:** accept.

### MPD-16 Retry and backoff numbers
- **Proposed:**
  - exponential backoff with full jitter, base **2 s**, cap **300 s**;
  - `Retry-After` honoured up to **1 h**;
  - request timeout **60 s**;
  - after 401 or 403, a retry once an hour.
- **Recommendation:** accept. With 500 agents, a 5-minute cap spreads the reconnect load after an outage.

### MPD-17 Rate limits
- **Proposed:**
  - one ingest request in flight per agent;
  - 429 when every ingest worker is busy, or when an agent sends more than **5 requests per second for 10 s**, with `Retry-After` of 5 to 60 s;
  - enrolment: **10 failures per address in 10 minutes**.
- **Recommendation:** accept, and tune from the milestone 2 load test.

## B. Values from the plan *(confirm)*

### MPD-18 Retention
- **Plan defaults:**
  - detail (flows, connections, process events) **90 days**;
  - daily summaries **2 years**;
  - command lines **30 days**;
  - full inventory snapshots 90 days;
  - inventory changes 2 years;
  - heartbeats 90 days (new).
- **Recommendation:** accept as defaults (they are rows in `retention_policies`). Re-check the disk after milestone 2 measures MB per host per day (MS-DAT-06).

### MPD-19 Platform versions
- **Plan:** Ubuntu Server **24.04 LTS** *(confirm)*; MariaDB "latest LTS".
- **Recommendation:** Ubuntu 24.04 LTS, and **MariaDB 11.8 LTS** from the MariaDB repository (Ubuntu 24.04's own package is 10.11). 11.8 is supported to 2028 and has everything the schema uses. Please confirm before the server setup.

### MPD-20 Collector DNS name
- **Plan:** `aio-mothership.<your-domain>` *(confirm)*.
- **Needed:** the domain, and which CA issues the dashboard's 443 certificate (internal CA, or Let's Encrypt with a DNS challenge). It goes into the 8443 certificate's SAN and the MSI property.

### MPD-21 Other *(confirm)* values from the plan, kept as defaults

| Value | Default | Requirement | Recommendation |
| --- | --- | --- | --- |
| Agent silent alert | 5 minutes | MS-ALR-05 | Keep. That is 5 missed heartbeats |
| Sign-in lockout | 5 failures | MS-SEC-06 | Keep, with a 15-minute lockout |
| IIS certificate expiry warning | 30 days | MS-ALR-09 | Keep (needs agent AG-INV-18, whose text is pending on the agent side) |
| Agent buffer | 500 MB | Operations, AG-PRF-04 | Keep (agent D-079) |
| Recovery targets | 4 h back in service, at most 24 h of data lost | Operations | Keep. Measured in the milestone 4 restore test |
| Fleet size | 50 pilot, designed for 500 | Scope | Keep. The milestone 2 gate tests 500 |
| Detection list registry keys | first component `SOFTWARE` or `SYSTEM` only | M-008 | Keep. Stricter than the agent, which accepts any HKLM key |

## C. Security and privacy

### MPD-22 Redaction rules pushed in config
- **Proposal:** `/api/v1/config` can **add** redaction rules (e.g. the `-AccessKey` rule from the agent's PD-53) but never remove the built-in ones.
- **Recommendation:** accept. It lets the tech lead add rules without an agent release, while a compromised mothership can't turn redaction off.

### MPD-23 Exclusions by process path
- **Context:** AG-CFG-03 exclusions (process path, remote range, port) make the agent stop recording matching activity. Someone who can change them can hide activity.
- **Recommendation:**
  - admins only;
  - each change audited, with a required note;
  - the active exclusions listed on the host page and next to coverage gaps.
- This differs from the detection-list rule: exclusion paths don't make the agent read anything.

### MPD-24 Cloned hosts and copied certificates
- **Context:** a cloned VM can carry the same MachineGuid (D-003), and a VM clone copies the agent's DPAPI-protected key too.
- **Recommendation:** lines with a `host_id` other than the agent's bound host are quarantined as `host_id_mismatch`, and the agent gets a high-severity health alert. Hostnames switching back and forth on one agent raise the same alert. An admin then revokes and re-enrols.

## D. Open questions from the plan (not blocking the contract)

- **MPD-25 Networks** that the servers and admin workstations sit on, for the firewall rules (8443 and 443).
- **MPD-26 Unraid:** free disk on the cache pool, and where the off-box backup copy goes.
- **MPD-27 Enrichment:**
  - threat lists (proposed: abuse.ch Feodo Tracker, Spamhaus DROP, FireHOL Level 1; each list's terms to be checked);
  - whether to create a MaxMind account for GeoLite2.
- **MPD-28 Privacy:** who gets analyst access (command lines), and who signs off on privacy before the pilot.
