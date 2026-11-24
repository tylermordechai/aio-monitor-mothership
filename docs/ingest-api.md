# Ingest API contract (v1)

**Status: approved (v1), 2026-11-13, at the milestone 1 review.** Nothing here is built yet. Agent milestone 5c (collector transport) is built against this document. Changes from here on are recorded as decisions (M-xxx) and keep `/api/v1`; a breaking change needs `/api/v2`.

- Requirements: MS-ING-01 to MS-ING-12 (MS-ING-11 added at the review, M-018; MS-ING-12 for the heartbeat, M-042), plus MS-SEC-01..03, MS-SEC-10, MS-OPS-08. Agent side: AG-SEC-03/04/07, AG-DAT-01..04, AG-PRF-04/06, AG-CFG-01..03, AG-INV-16.
- Decisions: M-001 to M-042 in [decisions.md](decisions.md); the review answers are M-017, M-024 to M-028 and M-030 to M-032. Values still open are in [pending-decisions.md](pending-decisions.md), each with a recommendation.
- Agent sources read for this contract (agent repo, `master`, 2026-11-13): `docs/status.md`, `docs/decisions.md` (D-004, D-008, D-016, D-039, D-060, D-071, D-073), `docs/agent-requirements.md`, `internal/config/identity.go`, `internal/output/jsonfile.go`, the record structs in `internal/netcollect`, `internal/proc` and `internal/inventory`.

## 1. Overview

| Endpoint | Method | Client certificate | Purpose | Requirement |
| --- | --- | --- | --- | --- |
| `/api/v1/enroll` | POST | none | One-time token + CSR → client certificate | MS-ING-06 |
| `/api/v1/ingest` | POST | required | gzip JSON Lines batch → `acked_seq` | MS-ING-01..05, 09, 10 |
| `/api/v1/heartbeat` | POST | required | Liveness, version, health and queue size every 60 s | MS-ING-12, AG-PRF-06, MS-ING-08 |
| `/api/v1/config` | GET | required | The agent's settings for its host group | MS-ING-07 |
| `/api/v1/renew` | POST | required | New certificate before the current one expires | MS-ING-11 (Should), M-018 |

- **Base URL:** `https://192.168.0.191:8443`. There is no DNS name: agents and browsers use the mothership's fixed IP, 192.168.0.191 (M-024). Port 8443 serves agents only; the dashboard is on 443.
- **TLS:** **1.3 only on 8443** (M-019, MS-SEC-01); every agent is Go, which supports it. Port 443 keeps 1.2 or later for browsers. ECDHE with AEAD ciphers only.
- **Server certificate on 8443:** issued by the private **agent CA**, which the agent pins (AG-SEC-04). The agent never trusts the Windows certificate store for 8443. See §2.4 and M-002.
- **Client certificate:** Apache asks for one on every connection to 8443 (`SSLVerifyClient optional`, `SSLVerifyDepth 1`, CA = agent CA). `/api/v1/enroll` works without one; every other endpoint returns 401 without a valid one (M-001).
- **Every request carries** `User-Agent: aio-monitor-agent/<agent_version>`, for example `aio-monitor-agent/0.5.0`. The mothership stores the version from it on each request (MS-ING-08).
- **Every response** is JSON (`Content-Type: application/json`), except `304`. Errors use one shape (§7).
- **No secrets in URLs or logs:** the enrolment token travels only in a POST body. Apache logs no request bodies, and the PHP code never logs a token, CSR private part, key or session secret (MS-SEC-10).
- **One request in flight per agent.** The agent sends its next ingest request only after the previous one has been answered or has timed out. Heartbeat and config requests may run alongside it.

## 2. Enrolment and certificates (MS-ING-06, MS-SEC-02, MS-SEC-03, AG-SEC-03/04)

### 2.1 Flow

1. **An admin creates an enrolment token** (admin page in milestone 5, CLI script `bin/token-create.php` in milestone 3). It is shown once and stored only as its SHA-256 hash, taken over the whole token string including `aioe_` (MS-SEC-03). An open token can be cancelled (`bin/token-create.php cancel`, M-061); a cancelled token gets the same answer as an unknown one. The token can be tied to a host group, and optionally to an expected hostname and a re-enrolment agent ID (§2.5). Each token is single-use and one of two kinds (M-021): **`manual`**, typed by hand, valid 24 h; or **`deployment`**, put into an Intune or GPO package, valid up to 7 days (chosen when it is made) and marked as such on the admin page.
2. **The installer gets** the collector address (the mothership's IP, M-024), the token, and the agent CA certificate **as a PEM together with its SHA-256 fingerprint** as MSI properties (AG-PLT-04, M-002). It refuses a PEM that doesn't match the fingerprint (M-059, agent PD-59). On the mothership, `bin/agent-ca.php` prints both (`--pem`, `--sha256`). The fingerprint is the SHA-256 of the certificate's DER bytes, printed as 64 lower-case hex digits and in the colon form of `openssl x509 -fingerprint -sha256` (M-061).
3. **The agent creates its key pair** locally (§2.3). The private key never leaves the host. 5c stores it in the protected data folder, encrypted with DPAPI (AG-SEC-06).
4. **The agent sends** `POST /api/v1/enroll` with the token and a CSR whose subject CN is its `agent_id`. It first checks that the server's certificate chains to the pinned agent CA. It never sends a token to a server it hasn't verified.
5. **The mothership checks**, in one database transaction:
   - the token's hash exists, isn't used or cancelled, and hasn't expired (24 h for a token typed by hand, up to 7 days for a deployment token; M-021). The row is locked (`SELECT … FOR UPDATE`) and marked used, so two requests can't both use it;
   - the CSR (§2.3);
   - the agent ID (§2.5).
6. **The signer signs.** The PHP endpoint never reads the CA key. It hands the CSR to a separate signing service that runs as its own system user and is the only reader of the CA key (MS-SEC-02; built in milestone 3).
7. **The mothership stores** the agent, the host and the certificate's serial and SHA-256 fingerprint, and returns the certificate.
8. **From then on**, the agent's identity is the certificate (MS-ING-02). `agent_id` values in request bodies are checked against it, never trusted on their own.

### 2.2 Request and response

`POST /api/v1/enroll`, `Content-Type: application/json`, body at most 16 KB:

| Field | Type | Required | Meaning |
| --- | --- | --- | --- |
| `enrollment_token` | string | yes | `aioe_` followed by 43 base64url characters (32 random bytes) |
| `csr` | string | yes | PKCS#10 CSR, PEM |
| `agent_id` | string | yes | Must equal the CSR's subject CN |
| `host_id` | string | yes | The MachineGuid (D-003) |
| `hostname` | string | yes | The computer name |
| `agent_version` | string | yes | As in the envelope |

`host_id` and `hostname` only create or match the host row and show the host in the dashboard (M-003). They are not identity.

**201 Created:**

| Field | Type | Meaning |
| --- | --- | --- |
| `agent_id` | string | The certificate's CN |
| `certificate` | string | The client certificate, PEM |
| `ca_certificate` | string | The agent CA certificate, PEM. The agent checks it against its pinned CA and refuses to continue if it differs |
| `not_after` | string | RFC 3339 UTC, the certificate's expiry |
| `renew_after` | string | RFC 3339 UTC; when to call `/api/v1/renew` (30 days before `not_after`) |
| `host_group` | string | The group the token placed the host in |

### 2.3 Certificate profile (M-002)

| Item | Agent client certificate | Agent CA |
| --- | --- | --- |
| Key | ECDSA P-256, created by the agent | ECDSA P-384, created on the mothership |
| Accepted in a CSR | ECDSA P-256 or P-384. RSA and other key types are refused (400 `csr_invalid`) | n/a |
| Subject | `CN=<agent_id>, O=AIO Monitor Agent`. Anything else in the CSR's subject is ignored and replaced | `CN=AIO Monitor Agent CA, O=AIO Monitor` |
| Subject alternative name | `URI:urn:aio-monitor:agent:<agent_id>` | none |
| Key usage | `digitalSignature` (critical) | `keyCertSign`, `cRLSign` (critical) |
| Extended key usage | `clientAuth` only | n/a |
| Basic constraints | `CA:FALSE` (critical) | `CA:TRUE, pathlen:0` (critical) |
| Serial | 128 random bits | |
| Lifetime | **365 days** (M-018), `notBefore` set 5 minutes back for clock skew | 10 years (M-002) |
| Signature | ecdsa-with-SHA384 | |
| CSR extensions | Ignored; the mothership sets every extension itself | |

The CSR's own signature must verify. `agent_id` must be a lower-case UUID version 4 (the agent's D-004 format, `^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`).

**The server certificate for 8443** comes from the same agent CA, with `serverAuth` and the mothership's fixed IP as its only SAN (an `iPAddress` entry; no DNS name, M-024), and a 1-year lifetime (M-030). One pinned CA then covers both directions (M-002).

**Addressing by IP (M-024):** the agent connects to the IP and checks it against the certificate's `iPAddress` SAN (Go does this for an IP host). A client sends no SNI for an IP, so Apache picks the vhost by port alone: one vhost on 8443 (agent CA) and one on 443 (web CA). **Changing the mothership's IP** means reissuing both server certificates and changing the collector address on every agent, so the IP is treated as permanent once agents are enrolled.

### 2.4 How a request is authenticated (MS-ING-02, MS-SEC-02)

1. **TLS:** Apache checks that the chain leads to the agent CA and that the certificate is within its dates. A certificate from another CA, or an expired one, fails the TLS handshake. That is not an HTTP status (§8.3).
2. **Application:** PHP takes the certificate's serial and SHA-256 fingerprint from Apache (`SSL_CLIENT_M_SERIAL`, `SSL_CLIENT_CERT`) and looks them up in `agent_certificates`:
   - not found → 403 `certificate_unknown`;
   - the certificate or its agent is revoked → 403 `agent_revoked`. This check runs on every request, so revocation takes effect at once (MS-SEC-02);
   - otherwise the request belongs to that agent.
3. **CRL:** the agent CA also publishes a CRL that Apache loads, as a second check. The database lookup is the authority.

### 2.5 Agent ID (M-003)

- **The agent keeps its own `agent_id`**: the UUID it generated into `agent-state.json` (D-004). Enrolment certifies that ID; it doesn't issue a new one. Records already in the agent's queue keep a matching `agent_id`, and the mothership needs no ID mapping.
- **A new agent ID** (never seen before): accepted.
- **An agent ID that is already enrolled and not revoked:** 409 `agent_id_in_use`, unless the token was created as a **re-enrolment token for that agent ID**. Then the old certificates are revoked, the new one issued, and both steps written to the audit log. This covers an agent that lost its certificate but kept its state. It stops a leaked ordinary token from taking over an existing agent's identity.
- **A revoked agent ID:** 403 `agent_revoked`. A revoked agent must get a new `agent_id`, which means a clean reinstall.
- **A re-enrolment token** works only for its agent ID; for any other ID it gets 403 `enrollment_token_invalid` (M-062).
- **A refused request never spends the token:** everything happens in one transaction, rolled back on any refusal (M-062).
- **Host binding:** at enrolment the agent is bound to a host row, found by `host_id` and `hostname` together (cloned VMs can share a MachineGuid, D-003), or created in the token's group. An existing host keeps its group, and `host_group` in the answer is the host's group (M-062). Several agents may point at one host over time (reinstall with `--purge`).

### 2.6 Renewal (MS-ING-11, Should; M-018)

`POST /api/v1/renew`, with the current client certificate, body `{"csr": "<PEM>"}` with a **new** key and the same CN.

- **200:** the same fields as the enrolment response. The old certificate stays valid until it expires, so a lost response costs nothing; it is marked `superseded`.
- The agent calls it once `renew_after` has passed, then daily until it succeeds.
- An expired certificate can't renew (the handshake fails); the host then needs a re-enrolment token.

## 3. `POST /api/v1/ingest` (MS-ING-01..05)

### 3.1 Request

| Item | Rule |
| --- | --- |
| Headers | `Content-Type: application/x-ndjson`, `Content-Encoding: gzip`, `Content-Length` (required; chunked bodies get 411), `User-Agent` |
| Optional header | `X-AIO-Batch-Id`: a UUID the agent picks per request, logged in `ingest_batches` for tracing. It doesn't change any behaviour |
| Body | gzip of UTF-8 JSON Lines: one envelope per line, `\n` between lines; `\r\n`, a trailing newline and empty lines are accepted. No byte-order mark |
| Compressed size | at most **10 MB** (M-017), else 413. The agent aims for 1 MB or 10 s (AG-DAT-03) |
| Decompressed size | at most **64 MB** (M-017), else 413 `batch_too_large`, checked while inflating (zip-bomb guard) |
| Lines | at most **50,000** (M-017), else 413 `batch_too_large` |
| One line | at most **1 MB** (M-017). A longer line is quarantined (`line_too_long`), not the batch |
| Order of lines | Any. Lines from every stream (flows, processes, snapshots, inventory, agent records) can be mixed. The agent sends oldest first |
| Empty batch | 400 `empty_batch`. Heartbeats have their own endpoint (§4) |

**Each line** is the agent's envelope (AG-DAT-01, D-008), exactly as the agent writes it today:

```json
{"schema_version":1,"agent_id":"…","host_id":"…","hostname":"…","agent_version":"…","record_type":"…","seq":123,"ts":"2026-11-14T10:00:00.1234567Z","data":{…}}
```

### 3.2 Processing order (MS-ING-04)

1. TLS and certificate checks (§2.4). The agent's check-in time, version and source IP are updated, even if the request fails later (MS-ING-08).
2. Headers and size checks, then decompression and line splitting. A request-level failure here stores nothing.
3. **The agent ID check (MS-ING-02):** every line that parses as JSON and has an `agent_id` must equal the certificate's agent ID. **One mismatch rejects the whole request** with 403 `agent_id_mismatch`, and nothing is stored.
4. **Line by line validation** (§3.4), each line becoming a stored row, a duplicate, or a quarantine row.
5. **One database transaction** writes all rows and quarantine rows, plus one `ingest_batches` row.
6. **Only after `COMMIT`** comes the 200 with `acked_seq` (MS-ING-04). If the commit fails, the answer is 503 and nothing is acknowledged.

### 3.3 Response and `acked_seq` (MS-ING-01, MS-ING-04, M-004)

**MS-ING-01 as agreed at the review (M-004):** `POST /api/v1/ingest` returns `{"acked_seq": N}`, **the highest sequence number in the acknowledged batch**. The plan's earlier wording ("the highest sequence number it now holds") is replaced; the agent never treats `acked_seq` as a watermark.

**200 OK:**

```json
{"acked_seq":48213,"lines":312,"stored":309,"duplicates":2,"quarantined":1,"max_seq_held":48213,"batch_id":"…"}
```

| Field | Meaning |
| --- | --- |
| `acked_seq` | The highest `seq` among this request's lines, after commit. `0` if no line had a usable `seq` |
| `lines` | Lines in the request (empty lines not counted) |
| `stored`, `duplicates`, `quarantined` | How each line ended up; they add up to `lines` |
| `max_seq_held` | The highest `seq` the mothership holds for this agent, from any request. For diagnostics |
| `batch_id` | The `X-AIO-Batch-Id` sent, or one the server made up |

**What a 200 means:** every line of this request is durably held: stored, already stored before (duplicate), or quarantined with a reason. **The agent deletes exactly the lines it sent in this request**, and nothing else.

**`acked_seq` is not a watermark.** The agent must **not** delete lines with a lower `seq` that weren't in this request. The reason is in how the agent numbers records today (§3.6): one counter across several writers, reserved in ranges. So records reach the queue out of `seq` order, and gaps are normal. "Everything up to N" would delete records that were never sent. `acked_seq` is there as a check: the agent can confirm it equals the highest `seq` it sent. If it doesn't, the agent logs an error and keeps the lines.

Any answer other than 200, or no answer at all, acknowledges nothing. The agent keeps the lines and sends them again (§8). Resending is always safe (§3.5).

### 3.4 Validation and quarantine (MS-ING-05, M-007)

Each line is checked in this order. The first failure decides the quarantine reason. Quarantine keeps the raw line (up to the 1 MB line limit), the reason, the agent, the `seq` and `record_type` if they could be read, and the receive time.

| # | Check | On failure: quarantine reason |
| --- | --- | --- |
| 1 | Line at most 1 MB | `line_too_long` (the first 64 KB are kept) |
| 2 | Valid UTF-8 JSON object | `invalid_json` |
| 3 | `schema_version` is the integer `1` | `unknown_schema_version` |
| 4 | Envelope fields present with the right types: `agent_id`, `host_id`, `hostname`, `agent_version`, `record_type` (strings), `seq` (integer 1 to 2^63−1), `ts` (RFC 3339, UTC `Z`), `data` (object) | `invalid_envelope:<field>` |
| 5 | `host_id` equals the agent's bound host (§2.5) | `host_id_mismatch` (also raises a health alert: a cloned VM or a copied certificate) |
| 6 | `record_type` is known (§9) | `unknown_record_type` |
| 7 | `record_type` is not on the provisional list | `provisional type, no table yet` (exact text, CLAUDE.md) |
| 8 | `ts` is no more than **24 h ahead** of the server clock (M-007), and not older than the retention of the record's table | `ts_out_of_range` |
| 9 | `data` has the record type's required fields (§9), and every known field has the expected JSON type | `missing_field:<name>` or `invalid_field:<name>` |

- **Unknown optional fields** in a known type and schema version are accepted and kept in the row's `extra` JSON column, never quarantined (MS-ING-05, D-073). That covers unknown top-level `data` fields. Unknown fields inside a nested object (for example inside `signature` or `app`) stay in that object's JSON column.
- **A different `hostname`** is accepted (hosts get renamed). The host's current name is updated, and the old one kept in `host_names`.
- **The provisional list** is a config setting, empty at the start (§9). It lets a new agent record type arrive before its table exists without losing it. Quarantined lines can be replayed into a table later.
- **A known field with an unexpected value** that is still the right JSON type (for example a new `direction_source` value) is stored as is. The dashboard shows values it doesn't know as they are, never mapped to a guess.
- **Required fields are kept to a minimum:** the fields a row needs for its keys and lookups. Everything else may be missing and is stored as NULL. A stricter check would quarantine good data whenever the agent leaves an empty field out.

### 3.5 Duplicates and idempotency (MS-ING-03, M-005)

- **A resend is byte-for-byte the same line**, so it has the same `seq` and the same `ts`. It is dropped without error and counted in `duplicates`.
- **The unique key is `(agent, seq, ts)`** on every partitioned table. A table split into daily partitions needs the partition column (`ts`) in each unique key, and the `ts` of a resent line never changes. So the key catches every resend. It differs from MS-ING-03's `(agent_id, seq)` only for the state rollback below, which then stores both records instead of silently dropping the newer one.
- Tables that keep one row per item (`executables`, `coverage_gaps`) are updated in place by their natural key, so a resend changes nothing.
- **A quarantined line sent again** on the same day is quarantined only once (unique on agent, line hash and day).

### 3.6 How the agent's `seq` works today, and what happens when it resets

Facts from the agent source (`internal/config/identity.go`, D-004, D-060, status.md "Record reference"):

| Question | Answer today |
| --- | --- |
| Scope | **One counter per `agent_id`**, shared by every record type and every output file (snapshots, flows, processes, inventory, agent records) |
| How numbers are handed out | Each writer **reserves a range** (`Reserve(n)`) and saves the new top to `agent-state.json` before writing. A crash can leave gaps; numbers are never reused |
| Order in the files | Increasing within one writer. Across writers, a record with a higher `seq` can be written before one with a lower `seq` |
| Restarts and reboots | Kept: the counter is in the state file |
| Service reinstall, no `--purge` | Kept: the data folder stays |
| Uninstall with `--purge`, the data folder deleted by hand, or renamed aside because its ACL was wrong (D-060) | A new state file: **a new random `agent_id`, and `seq` starts again at 1** |
| Console runs with another `--data-dir` | Their own `agent_id` and counter (development only) |
| Restored state (VM snapshot reverted, backup restored, cloned VM) | **The same `agent_id` with a lower counter**: `seq` numbers are used again for new records |

**What the mothership does:**

| Case | Effect | Handling |
| --- | --- | --- |
| Gaps in `seq` | Normal | Nothing. The mothership never waits for a missing number |
| Out-of-order arrival | Normal | Nothing (§3.3) |
| New `agent_id` after a purge or a renamed folder | A new identity. In 5c the certificate and key live in the same data folder, so they are gone too, and the agent must enrol again | A new `agents` row, the same host row if `host_id` and `hostname` match. The old agent is marked `replaced` and stops counting as silent |
| Same `agent_id`, counter rolled back | A new record can get the `seq` of an older one | The `(agent, seq, ts)` key keeps both, because their `ts` differ, so nothing is lost. The mothership notices when a stored, non-duplicate line has a `seq` below the agent's `max_seq_held` and is an `agent_start`, or when more than 1,000 (M-030) such lines arrive. It then sets `agents.seq_regression_at` and raises a health alert. Counting duplicates, and the dashboard's view of that agent, treat the rollback as a new run |
| Two hosts with one certificate (a cloned VM) | Two `host_id`s, or one `host_id` with two hostnames, interleaved | `host_id_mismatch` quarantine (§3.4). Hostnames flipping back and forth raise a health alert |

**Asked of agent 5c (M-005, M-020):** keep `seq` in the same protected state file, and keep the "reserve before use" rule. Optionally add a `seq_epoch` to `agent_start`: random, and new only when the state file is **created anew** (first install, purge, a folder renamed aside, a lost or corrupt file). It is a new optional field, so it keeps `schema_version` 1 (D-073).

- **What `seq_epoch` detects:** a recreated state file that somehow keeps its `agent_id` (for example a hand-repaired file), so counting restarts under the same identity.
- **The first epoch is a baseline (M-059, agent PD-65):** the first `seq_epoch` seen for an agent, including the first one from an agent upgraded from before 5c, is a starting point, not a recreated state file. Only a later change of epoch under the same `agent_id` counts as one.
- **What it can't detect: a VM snapshot revert.** The revert restores the old state file, and the old epoch with it, so the epoch looks unchanged. Snapshot reverts (and restored backups or clones) are caught only by the `(agent, seq, ts)` key and the regression check in the table above, which is why that check stays the primary mechanism.

## 4. `POST /api/v1/heartbeat` (MS-ING-12, AG-PRF-06, MS-ING-08, M-006; agreed at the review)

**Its own endpoint, not an empty ingest call:**
- a heartbeat is about the agent now (its queue and its connection), not a host record, so it gets no `seq` and never waits in the queue;
- during an outage the agent keeps no heartbeats to resend later;
- ingest keeps one meaning: a non-empty batch of records.

**The agent sends it** every 60 s (D-079, M-030), whether or not it has records to send. It keeps sending heartbeats while ingest is backing off, so the dashboard can show "connected, 40 MB queued" instead of "silent". It stops only on 401 or 403.

Request: `Content-Type: application/json`, body at most 64 KB. **No `agent_id` field**: the certificate says who it is.

| Field | Type | Meaning |
| --- | --- | --- |
| `agent_time` | string | The agent's clock, RFC 3339 UTC. The mothership records the skew |
| `uptime_s` | number | Seconds since this run started |
| `queue.records`, `queue.bytes` | integer | What is waiting to be sent |
| `queue.cap_bytes` | integer | The queue cap (AG-PRF-04, 500 MB) |
| `queue.oldest_ts` | string or null | The `ts` of the oldest queued record |
| `queue.dropped_records`, `queue.dropped_bytes` | integer | Dropped because the cap was hit, since install. Should be 0; anything else raises a coverage alert |
| `transport.last_acked_seq` | integer | The last `acked_seq` received |
| `transport.last_success` | string or null | When the last ingest succeeded |
| `transport.consecutive_failures` | integer | Ingest failures in a row |
| `transport.last_error` | string or null | The last error code (§7), e.g. `slow_down`, or `tls_handshake` |
| `health` | object | The agent's own summary, for example `etw` (`running`, `restarting`, `not_elevated`, `off`), `priority_class`, `working_set_bytes`, `open_coverage_gap` (boolean) |
| `config_etag` | string or null | The ETag of the settings in use |
| `cert_not_after` | string | The expiry of the certificate in use |

Unknown fields are accepted and kept with the heartbeat.

**200 OK:**

```json
{"server_time":"2026-11-14T10:00:00Z","heartbeat_s":60,"config_etag":"\"cfg-3-7\"","renew_after":null}
```

- `config_etag`: if it differs from the agent's, the agent fetches `/api/v1/config`.
- `renew_after`: set when the agent should renew (§2.6).
- `heartbeat_s`: the interval to use.

Each heartbeat updates the agent's check-in time, version and IP (MS-ING-08) and is kept in `agent_heartbeats` (90 days). The "agent silent" alert (MS-ALR-05) fires after 5 minutes with no request of any kind.

## 5. `GET /api/v1/config` (MS-ING-07, AG-CFG-01..03, M-008)

**Read-only for the agent.** It only changes agent settings, never the host (AG-SEC-07). With `If-None-Match` equal to the current ETag, the answer is `304 Not Modified` with no body.

**When the agent fetches it:** at start, when a heartbeat's `config_etag` differs, and every 15 minutes (M-030) as a backstop. If a setting is missing or invalid, the agent keeps its built-in default for it (AG-CFG-02) and reports the problem in its heartbeat `health`.

**200 OK**, with an `ETag` header:

| Section | Fields | Notes |
| --- | --- | --- |
| top level | `config_version` (integer), `host_group` (string), `generated_at` | |
| `intervals` | `snapshot_s` (300), `flow_window_s` (10), `inventory_full_h` (24), `inventory_rescan_min` (15), `heartbeat_s` (60), `config_poll_min` (15) | The agent's accepted defaults (D-026, D-044, D-079) |
| `transport` | `batch_max_bytes` (1,048,576), `batch_max_s` (10), `queue_cap_bytes` (524,288,000) | AG-DAT-03, AG-PRF-04 |
| `collectors` | `etw`, `tcp_bytes` (false), `include_loopback` (false), `processes`, `inventory`, `server_software` | Booleans |
| `caps` | `command_line_max_bytes` (8,192), `hash_max_file_bytes` (536,870,912), `hash_read_bytes_per_s` (33,554,432), `flow_table_max` (32,768), `iis_config_max_bytes` (16,777,216) | Agent limits (D-022, D-030, D-031, D-075) |
| `exclusions` | list of `{"type": "process_path" \| "remote_cidr" \| "port", "value": "…", "note": "…"}` | AG-CFG-03. Can hide activity, so only admins can change them, every change is audited, and an excluded item is never silently dropped from the dashboard's coverage view |
| `redaction` | `extra_rules`: list of `{"form": "next_arg" \| "equals" \| "conn_string", "name": "…"}` | **Additive only:** pushed rules add to the agent's built-in redaction (D-030, D-036) and can never turn it off, so a compromised mothership can't make the agent send unredacted secrets |
| `server_software` | `detection_list` (below), `replace_builtin` (false) | AG-INV-16, D-071 |

### 5.1 The server-software detection list (D-071, AG-INV-16)

**Hard rule (CLAUDE.md, MS-ING-07):** an entry holds only **service-name patterns** and **HKLM registry keys**, and registry **value names**. It never holds a file path, a command, a URL or another hive. The format is the agent's own `DetectEntry` (`internal/inventory/serverapps.go`), field for field:

| Field | Type | Rule (checked when an admin saves it, and again by the agent) |
| --- | --- | --- |
| `product` | string, required | 1 to 100 characters, no control characters |
| `services` | array of string | An exact service name or a prefix ending in `*`: `^[A-Za-z0-9_.$@#+ -]{1,255}\*?$`. At most one `*`, only at the end. No `\ / : " < > \|` |
| `registry_keys` | array of string | Relative to HKLM, with no hive name (`HKLM`, `HKEY_…`, `HKCU` are refused). Components joined by `\`. Each is `*` or 1 to 255 characters without `\ / : * ? " < > \|` or `%`. No `..`, no leading `\`. **The first component must be `SOFTWARE` or `SYSTEM`** *(stricter than the agent; M-017)*. At most 12 components |
| `version_values` | array of string | Registry **value names** under a matched key, joined with `.` (Exchange's four values). Empty means the version info of the service's own exe, which the agent finds itself from the service settings. Each 1 to 255 characters, no `\` `/` `:` `%` |
| `service_value` | string | A value name holding the matching service's name (PostgreSQL's `Service ID`). Same character rule |
| `install_value` | string | A value name holding the install folder. The agent only reports that folder; it never reads files from it. Same character rule |

- **At least one** of `services` or `registry_keys` is needed. At most 100 entries and 64 KB for the whole list.
- **The dashboard refuses to save** a list that fails any rule, and the config endpoint re-checks before serving. An entry that fails is left out and logged, never served.
- **`replace_builtin: false`** (the default) adds the pushed entries to the agent's built-in list (MySQL, PostgreSQL, Apache HTTP Server, nginx, Apache Tomcat, Exchange). Records from pushed entries carry `list_source: "mothership"`.

### 5.2 Example

```http
GET /api/v1/config HTTP/1.1
Host: 192.168.0.191:8443
User-Agent: aio-monitor-agent/0.5.0
If-None-Match: "cfg-3-6"
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
ETag: "cfg-3-7"
```

```json
{
  "config_version": 7,
  "host_group": "pilot-web",
  "generated_at": "2026-11-14T09:58:02Z",
  "intervals": {"snapshot_s": 300, "flow_window_s": 10, "inventory_full_h": 24, "inventory_rescan_min": 15, "heartbeat_s": 60, "config_poll_min": 15},
  "transport": {"batch_max_bytes": 1048576, "batch_max_s": 10, "queue_cap_bytes": 524288000},
  "collectors": {"etw": true, "tcp_bytes": false, "include_loopback": false, "processes": true, "inventory": true, "server_software": true},
  "caps": {"command_line_max_bytes": 8192, "hash_max_file_bytes": 536870912, "hash_read_bytes_per_s": 33554432, "flow_table_max": 32768, "iis_config_max_bytes": 16777216},
  "exclusions": [{"type": "remote_cidr", "value": "10.20.30.0/24", "note": "backup network, ticket 4411"}],
  "redaction": {"extra_rules": [{"form": "next_arg", "name": "-AccessKey"}]},
  "server_software": {
    "replace_builtin": false,
    "detection_list": [
      {"product": "Redis", "services": ["Redis*"], "registry_keys": ["SOFTWARE\\Redis\\*"], "version_values": ["Version"], "install_value": "InstallPath"},
      {"product": "RabbitMQ", "services": ["RabbitMQ"]}
    ]
  }
}
```

Refused examples: `"registry_keys": ["HKLM\\SOFTWARE\\X"]` (hive name), `["SOFTWARE\\..\\SAM"]` (`..`), `["SAM\\Domains"]` (not under SOFTWARE or SYSTEM), `"services": ["C:\\Program Files\\x.exe"]` (a path), `"version_values": ["%ProgramFiles%\\x"]` (not a value name).

## 6. Worked examples

These use `curl` with a client certificate, as the simulator and tests will. Real values differ.

### 6.1 Enrolment

```http
POST /api/v1/enroll HTTP/1.1
Host: 192.168.0.191:8443
Content-Type: application/json
User-Agent: aio-monitor-agent/0.5.0

{"enrollment_token":"aioe_q3T…(43 chars)","csr":"-----BEGIN CERTIFICATE REQUEST-----\nMIIBNTCB3AIBADBLMS0w…\n-----END CERTIFICATE REQUEST-----\n","agent_id":"290738b4-dc89-4cc4-aa7e-e38885792dd9","host_id":"39139114-1ffc-43f5-90ee-718a146ab4be","hostname":"SRV-WEB-01","agent_version":"0.5.0"}
```

```http
HTTP/1.1 201 Created
Content-Type: application/json

{"agent_id":"290738b4-dc89-4cc4-aa7e-e38885792dd9","certificate":"-----BEGIN CERTIFICATE-----\nMIIB…\n-----END CERTIFICATE-----\n","ca_certificate":"-----BEGIN CERTIFICATE-----\nMIIC…\n-----END CERTIFICATE-----\n","not_after":"2027-11-14T10:05:00Z","renew_after":"2027-10-15T10:05:00Z","host_group":"pilot-web"}
```

The same token used a second time:

```http
HTTP/1.1 403 Forbidden

{"error":{"code":"enrollment_token_invalid","message":"The enrolment token is unknown, used or expired.","retryable":false}}
```

(The three cases share one answer, so the endpoint can't be used to test which tokens exist.)

### 6.2 Ingest

Uncompressed body, 4 lines (the agent sends it gzipped):

```json
{"schema_version":1,"agent_id":"290738b4-dc89-4cc4-aa7e-e38885792dd9","host_id":"39139114-1ffc-43f5-90ee-718a146ab4be","hostname":"SRV-WEB-01","agent_version":"0.5.0","record_type":"tcp_listener","seq":48210,"ts":"2026-11-14T10:00:00.0000000Z","data":{"family":"ipv4","local_addr":"0.0.0.0","local_port":1433,"pid":4120,"process_name":"sqlservr.exe","exe":"C:\\Program Files\\Microsoft SQL Server\\MSSQL16.SQLEXPRESS\\MSSQL\\Binn\\sqlservr.exe","user":"NT Service\\MSSQL$SQLEXPRESS","services":["MSSQL$SQLEXPRESS"],"app":{"name":"Microsoft SQL Server 2022 (64-bit)","version":"16.0.4135.4","publisher":"Microsoft Corporation","app_key":"uninstall|machine||x64|microsoft sql server sqlserver2022","match_source":"sql_instance"}}}
{"schema_version":1,"agent_id":"290738b4-dc89-4cc4-aa7e-e38885792dd9","host_id":"39139114-1ffc-43f5-90ee-718a146ab4be","hostname":"SRV-WEB-01","agent_version":"0.5.0","record_type":"net_flow","seq":48211,"ts":"2026-11-14T10:00:10.0012000Z","data":{"proto":"tcp","family":"ipv4","local_addr":"10.1.2.15","local_port":51544,"remote_addr":"52.168.117.170","remote_port":443,"direction":"outbound","direction_source":"etw","first_seen":"2026-11-14T10:00:03.1180000Z","last_seen":"2026-11-14T10:00:03.4410000Z","window_start":"2026-11-14T10:00:00Z","window_end":"2026-11-14T10:00:10Z","connects":1,"closes":1,"closed":true,"pid":2212,"process_name":"svchost.exe","exe":"C:\\Windows\\System32\\svchost.exe","user":"NT AUTHORITY\\NETWORK SERVICE","process_start_time":"2026-11-14T08:01:12.5500000Z","services":["CryptSvc"]}}
{"schema_version":1,"agent_id":"290738b4-dc89-4cc4-aa7e-e38885792dd9","host_id":"39139114-1ffc-43f5-90ee-718a146ab4be","hostname":"SRV-WEB-01","agent_version":"0.5.0","record_type":"inv_firewall_rule","seq":48212,"ts":"2026-11-14T10:00:10.0012000Z","data":{"name":"Allow 8080"}}
{"schema_version":1,"agent_id":"290738b4-dc89-4cc4-aa7e-e38885792dd9","host_id":"39139114-1ffc-43f5-90ee-718a146ab4be","hostname":"SRV-WEB-01","agent_version":"0.5.0","record_type":"etw_status","seq":48213,"ts":"2026-11-14T10:00:10.0012000Z","data":{"event":"periodic","events":174728,"agent_dropped":0,"decode_errors":0,"handler_panics":0,"processes_tracked":290,"tcp_bytes":false,"go_heap_bytes":11444224,"working_set_bytes":31674368,"working_set_trims":2,"priority_class":"below_normal","priority_boosts":0,"queue_depth_max":12}}
```

```sh
gzip -c batch.jsonl > batch.jsonl.gz
curl --cert agent.crt --key agent.key --cacert agent-ca.crt \
  -H 'Content-Type: application/x-ndjson' -H 'Content-Encoding: gzip' \
  -H 'User-Agent: aio-monitor-agent/0.5.0' -H 'X-AIO-Batch-Id: 3f0c6d2e-7a51-4c1b-9f0a-0d2b8e6f1a77' \
  --data-binary @batch.jsonl.gz https://192.168.0.191:8443/api/v1/ingest
```

```json
{"acked_seq":48213,"lines":4,"stored":3,"duplicates":0,"quarantined":1,"max_seq_held":48213,"batch_id":"3f0c6d2e-7a51-4c1b-9f0a-0d2b8e6f1a77"}
```

- Line 3 (`inv_firewall_rule`, a type with no table) is quarantined with `unknown_record_type`, and the other three are stored (MS-ING-05).
- Line 4 has `queue_depth_max`, a field the mothership doesn't know. It is stored, with that field in `agent_status.extra`.
- The agent deletes lines 48210 to 48213.

**The same request again** (say the 200 was lost on the way):

```json
{"acked_seq":48213,"lines":4,"stored":0,"duplicates":3,"quarantined":1,"max_seq_held":48213,"batch_id":"3f0c6d2e-7a51-4c1b-9f0a-0d2b8e6f1a77"}
```

The quarantined line counts as `quarantined` again, but is stored only once that day (§3.5).

**Busy server:**

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 20

{"error":{"code":"slow_down","message":"Ingest is at capacity; retry later.","retryable":true}}
```

**Disk 90 % full (MS-OPS-08):**

```http
HTTP/1.1 503 Service Unavailable
Retry-After: 300

{"error":{"code":"storage_full","message":"The collector is not accepting data; keep buffering.","retryable":true}}
```

### 6.3 Heartbeat

```http
POST /api/v1/heartbeat HTTP/1.1
Host: 192.168.0.191:8443
Content-Type: application/json
User-Agent: aio-monitor-agent/0.5.0

{"agent_time":"2026-11-14T10:00:01.2Z","uptime_s":7210.4,"queue":{"records":0,"bytes":0,"cap_bytes":524288000,"oldest_ts":null,"dropped_records":0,"dropped_bytes":0},"transport":{"last_acked_seq":48213,"last_success":"2026-11-14T10:00:00.9Z","consecutive_failures":0,"last_error":null},"health":{"etw":"running","priority_class":"below_normal","working_set_bytes":31674368,"open_coverage_gap":false},"config_etag":"\"cfg-3-6\"","cert_not_after":"2027-11-14T10:05:00Z"}
```

```json
{"server_time":"2026-11-14T10:00:01Z","heartbeat_s":60,"config_etag":"\"cfg-3-7\"","renew_after":null}
```

The ETag differs, so the agent fetches `/api/v1/config` (§5.2).

### 6.4 Config

See §5.2. Unchanged:

```http
HTTP/1.1 304 Not Modified
ETag: "cfg-3-7"
```

### 6.5 Renewal

```http
POST /api/v1/renew HTTP/1.1
Content-Type: application/json

{"csr":"-----BEGIN CERTIFICATE REQUEST-----\n…\n-----END CERTIFICATE REQUEST-----\n"}
```

200 with the fields of §2.2. A CSR whose CN isn't the certificate's agent ID gets 400 `csr_invalid`.

## 7. Errors (MS-ING-09)

Every error has this body:

```json
{"error":{"code":"<code>","message":"<text for a person>","retryable":true}}
```

The message never repeats request content (no token, no CSR, no record data).

| HTTP | `code` | Endpoints | Meaning | Retryable |
| --- | --- | --- | --- | --- |
| 400 | `bad_gzip` | ingest | The body isn't valid gzip | no |
| 400 | `empty_batch` | ingest | No lines | no |
| 400 | `invalid_json` | enroll, heartbeat, renew | The body isn't a JSON object | no |
| 400 | `missing_field` | enroll, heartbeat, renew | A required field is missing; `message` names it | no |
| 400 | `csr_invalid` | enroll, renew | Bad PEM, bad signature, key type not allowed, CN not a UUID v4 or not matching | no |
| 401 | `client_certificate_required` | all but enroll | No client certificate | no: enrol |
| 403 | `certificate_unknown` | all but enroll | Signed by the agent CA but not in the database (for example a restored CA backup issued it) | no |
| 403 | `agent_revoked` | all | The certificate or agent is revoked | no |
| 403 | `agent_id_mismatch` | ingest | A line's `agent_id` differs from the certificate (MS-ING-02). Nothing stored | no |
| 403 | `enrollment_token_invalid` | enroll | Unknown, used or expired | no |
| 404 | `not_found` | | Unknown path | no |
| 405 | `method_not_allowed` | | | no |
| 409 | `agent_id_in_use` | enroll | The agent ID is enrolled and the token isn't a re-enrolment token for it | no |
| 411 | `length_required` | ingest | No `Content-Length` | no |
| 413 | `batch_too_large` | ingest | Over the compressed, decompressed or line-count limit; `message` says which | no: split (§8) |
| 415 | `unsupported_media_type` | ingest, enroll, heartbeat, renew | Wrong `Content-Type` or `Content-Encoding` | no |
| 429 | `slow_down` | all | Rate or capacity limit, with `Retry-After` | yes |
| 429 | `too_many_enrollments` | enroll | Too many failed enrolments from this address (10 in 10 minutes), with `Retry-After` | yes |
| 500 | `internal_error` | all | A bug, or (enroll) the signing service is down; nothing was committed | yes |
| 503 | `database_unavailable` | all | The database can't be reached, or the commit failed, with `Retry-After` | yes |
| 503 | `storage_full` | ingest | Disk at 90 % or more (MS-OPS-08), with `Retry-After` | yes |
| 503 | `maintenance` | all | A planned stop, with `Retry-After` | yes |

## 8. Retry and backoff (MS-ING-09, AG-DAT-04)

### 8.1 Rules for the agent

1. **Retry** on 429, 500, 502, 503, 504, a timeout, a dropped connection, or a TLS handshake that fails for network reasons.
2. **Backoff:** exponential with full jitter. Wait a random time between 0 and `min(cap, base × 2^attempt)`, with **base 2 s and cap 300 s** (M-009). Reset after a success.
3. **`Retry-After`** (seconds) is a floor: wait at least that long, and at most 1 hour.
4. **Request timeout:** 60 s. A timeout counts as a failure. The lines stay in the queue, and resending them is safe (§3.5).
5. **Draining a backlog:** after an outage the agent sends batches back to back (no 10 s pause), still one request at a time, oldest first. The server holds back the fleet with 429 (§8.2).
6. **413:** split the batch in half and send each half, down to a single line. A single line that still gets 413 can't happen within the limits (the line limit is below the batch limit).
7. **400 (`bad_gzip`, `empty_batch`):** an agent bug. The agent keeps the lines, logs to the Event Log, and tries again at the cap interval. It never deletes unacknowledged data.
8. **401, 403:** stop sending ingest and heartbeats, keep the queue, log to the Event Log, and try once an hour. An admin fixes it on the mothership (an unrevoke, a re-enrolment token) without losing the queue. `agent_id_mismatch` is handled the same way.
9. **Never delete a line before a 200 that includes it** (AG-DAT-04). When the queue reaches its cap, drop the oldest first (AG-PRF-04), count it, and report it in the heartbeat.

### 8.2 Rules on the mothership

- **Normal load** is 500 agents × one batch every 10 s = 50 requests per second (MS-ING-10).
- **429 `slow_down`** is sent when the ingest workers are all busy, or when an agent sends more than 5 requests per second for 10 s (M-009). `Retry-After` is 5 to 60 s, chosen by load.
- **503 `storage_full`** at 90 % disk (MS-OPS-08), `Retry-After: 300`, so agents keep buffering rather than the database crashing.
- **503 `database_unavailable`**, `Retry-After: 30`.

### 8.3 TLS-level failures

They happen before HTTP, so there is no status code. The agent reports them as `tls_handshake` in the heartbeat's `transport.last_error` (if a heartbeat gets through) and in its log:

| Cause | Agent action |
| --- | --- |
| The server's certificate doesn't chain to the pinned CA | Stop and log a security warning. Never send |
| The agent's certificate expired | Stop. It needs a re-enrolment token |
| A network problem (reset, timeout) | Retry with backoff |

## 9. Record types (schema_version 1)

The tables are in `db/migrations/0001_initial.sql`. "Required" means a line without it is quarantined (§3.4, rule 9). Every other known field is optional (NULL when missing). Envelope fields are always required.

| `record_type` | Table | Required `data` fields | Notes |
| --- | --- | --- | --- |
| `tcp_conn` | `snap_tcp_conns` | `family`, `local_addr`, `local_port`, `remote_addr`, `remote_port`, `state`, `direction`, `direction_source`, `pid` | A snapshot's records share their `ts` (D-008) |
| `tcp_listener` | `snap_listeners` | `family`, `local_addr`, `local_port`, `pid` | `app` link (5b) |
| `udp_endpoint` | `snap_udp_endpoints` | `family`, `local_addr`, `local_port`, `pid` | `app` link (5b) |
| `net_snapshot_summary` | `snapshots` | `tcp_conns`, `tcp_listeners`, `udp_endpoints` | Ends each snapshot |
| `net_flow` | `net_flows` | `proto`, `family`, `local_addr`, `local_port`, `remote_addr`, `remote_port`, `direction`, `window_start`, `window_end`, `pid` | Combined per D-016 (M-013) |
| `etw_status` | `agent_status` | `event` | |
| `coverage_gap` | `coverage_gaps` | `reason`, `start` | Written at open and again at close (D-017) |
| `process_start`, `process_snapshot` | `process_events` (+ `process_command_lines`) | `pid` | Command line stored apart (M-011) |
| `process_stop` | `process_events` | `pid`, `exit_time` | |
| `executable` | `executables` | `path`, `size`, `mod_time`, `hash_status` | One row per host and file version (M-014) |
| `agent_start` | `agent_lifecycle` | `mode`, `pid`, `previous_exit` | |
| `agent_stop` | `agent_lifecycle` | `reason` | |
| `inventory_snapshot` | `inventory_snapshots` | `snapshot_id`, `event`, `reason` | |
| `inventory_change` | `inventory_changes` | `kind`, `key`, `change` | |
| `inv_app` | `inv_apps` | `source`, `name` | |
| `inv_service` | `inv_services` | `name` | `app` link (5b) |
| `inv_task` | `inv_tasks` | `path` | |
| `inv_startup` | `inv_startup` | `location`, `name` | |
| `inv_driver` | `inv_drivers` | none beyond the envelope (`name` is empty for a loaded image with no service) | |
| `inv_role` | `inv_roles` | `name` | |
| `host_facts` | `host_facts` | `hostname` | |
| `inv_iis` | `inv_iis` | `installed`, `read` | The read status is shown, so unread IIS never looks like "no sites" |
| `inv_iis_site` | `inv_iis_sites` | `name`, `id` | |
| `inv_iis_app_pool` | `inv_iis_app_pools` | `name` | `app` link |
| `inv_sql_instance` | `inv_sql_instances` | `instance_name` | `app` link |
| `inv_server_app` | `inv_server_apps` | `product` | |
| `inventory_link_index` | `inventory_link_index` | `reason` | 5b, in the agent's status.md but not in CLAUDE.md's list; M-016 |

- **5b types** (`inv_iis` to `inv_server_app`, `inventory_link_index`, and the `app` links) have tables now, per the plan ("create now, adjust only if 5b sign-off changes them").
- **The provisional list** is empty at the start. It is a config setting (`ingest.provisional_record_types`), so a type the PM marks provisional is quarantined with `provisional type, no table yet` until its table exists.
- **Inventory item keys:** item records (`inv_*`, `host_facts`) don't carry their `key`; only `inventory_change` does. The mothership works the key out with the agent's rules (M-012). **Asked of agent 5c (M-012):** add `key` and `snapshot_id` to each item record, as new optional fields (D-073).

## 10. What agent milestone 5c needs to build against this

| Item | Section |
| --- | --- |
| P-256 key, CSR with CN = the existing `agent_id`, enrolment, the pinned agent CA for 8443 | §2 |
| Renewal 30 days before expiry | §2.6 |
| gzip JSON Lines batches of the existing envelopes, 1 MB or 10 s, one request in flight | §3.1 |
| Delete only the lines of a request that got 200; `acked_seq` is a check, not a watermark | §3.3 |
| Keep `seq` in the protected state file with "reserve before use"; optional `seq_epoch` on `agent_start` (detects a recreated state file only, not a snapshot revert; M-020) | §3.6 |
| Heartbeat every 60 s, its own endpoint, with queue size | §4 |
| Config with ETag; pushed detection list validated again on the agent; additive redaction | §5 |
| Backoff, `Retry-After`, 413 splitting, stop on 401 and 403 | §8 |
| Optional: `key` and `snapshot_id` on inventory item records | §9 |
