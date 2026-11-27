# Pending decisions

Last updated: 2026-11-27 (MPD-44 added, M-065)

Every *(confirm)* value and open question still open, each with a recommendation. To answer, reply with the ID and your choice, for example "MPD-32: keep". Answers are recorded in [decisions.md](decisions.md) and removed from here.

- **Answered:** MPD-01 to MPD-34, recorded as M-017 to M-041; MPD-36, recorded as M-050; MPD-42, recorded as M-062.
- **Open:** MPD-35 (the default is in the code), MPD-37 (later), MPD-38 (before the pilot), MPD-39 (when the agent's 5d formats are final), and MPD-40, MPD-41, MPD-43 and MPD-44 (milestone 3).

### MPD-35 How much one simulated server produces
- **Context:** MS-DAT-06 (MB per host per day) and the load test depend on the simulator's volume per host (`src/Sim/Profile.php`, M-049). The defaults are estimates.
- **Default:** 20 flows per 10 s, a network snapshot every 5 minutes (40 connections, 12 listeners, 6 UDP), 2 process start/stop pairs a minute, ETW status every minute, a new executable every 5 minutes, a full inventory (60 apps, 120 services) at start and daily.
- **Recommendation:** keep these for the milestone 2 gate, then compare with the real agent on your dev machine once agent 5c can send, and with the agent's own output files before that if you can share a day of them. The MB per host per day figure is reported with the profile it was measured on.

### MPD-37 Disk and retention for 111 MB per host per day, and pilot capacity
- **Owner (2026-11-20, M-051):** left for later, with the pilot capacity (50 hosts). v1 monitors servers, so plan with the server figure (120 MB per host per day).
- **Context:** milestone 2 measured **111.3 MB per host per day** (servers 120, laptops 102) on the simulator's estimated volume (MPD-35), against the plan's estimate of 25 MB (MS-DAT-06). `net_flows` is 85 % of it (95 MB per host per day, about 670 bytes per row with indexes). With 90 days of detail *(confirm)*: this VM with 4 hosts about 40 GB (fits the 100 GB disk); a 50-host pilot about 500 GB (plan: 250 GB); 500 hosts about 4.9 TB (plan: 1.5 TB).
- **Options** (they combine):
  1. **Measure the real agent first:** the dev-machine agent's flows per day may be well above or below the simulator's 20 per 10 s.
  2. **Keep flow detail shorter** (e.g. 30 days) and everything else 90 days; the daily summaries (MS-DAT-03) keep the history of who talked to whom. About 55 % less disk.
  3. **Compress `net_flows`** (InnoDB compression, a migration): often about half the size, for some CPU. Tested on simulator data before deciding.
  4. **Slim the `net_flows` row:** review its columns and indexes for repeated strings (process path, addresses) that could be stored once.
- **Recommendation:** 1 in any case; 3 and 4 tried in milestone 4 on simulator data, with the figures reported; 2 decided after that, with the real agent's numbers. Not needed for the development VM: 4 hosts fit.

### MPD-38 Two-step sign-in (TOTP) before the pilot
- **Context:** MS-SEC-06 asks for an optional TOTP second factor. The owner left it out of milestone 5 (2026-11-22); `users.totp_secret_enc` and `totp_enabled` are already in the schema.
- **Proposal:** RFC 6238 codes (30 s, 6 digits, SHA-1, one step either side), the secret encrypted with an app key kept in `/etc/aio-mothership/`, set up by `tools/users.php totp <name>` showing the secret as text for the authenticator app (no QR library), and required for admins.
- **Recommendation:** build it before the pilot, when real hosts and command lines are on the dashboard.

### MPD-39 Agent milestone 5d record types (provisional until their formats are final)
- **Context (owner, 2026-11-23, FYI, no action now):** agent milestone 5d will add record types for local users and admins, firewall, browser extensions, security settings, shares, health samples (every 5 minutes), patch level, network setup and sign-ins. Their names and formats are not final; the local copy of the agent repo (`6bd6de7`) does not name them yet.
- **Until the formats are final:** quarantine them as "provisional type, no table yet" (CLAUDE.md, ingest-api.md §9), never dropped. Note: today a type not on `ingest.provisional_record_types` (empty) is still quarantined, but with the reason "unknown record_type". When the agent publishes the 5d type names, add them to that list (config only, no code) so the reason reads "provisional type, no table yet"; tables and a migration follow once the PM sends the final formats.
- **Host page (milestone 5 step 4):** the tab bar is built so more tabs can be added; room is left for **Security** (local users and admins, firewall, security settings, patch level, browser extensions), **Shares**, **Health** (the 5-minute samples, a time-based view, so coverage gaps show on it), **Network** (network setup) and **Sign-ins** (also time-based, with gaps). They are not shown until their data exists.
- **Open questions for later:** the size of the health samples (every 5 min, per host) for MPD-37; whether sign-ins and local-account names are sensitive like command lines (analysts only, audited) or open to viewers.
- **Recommendation:** no change now; revisit when the agent's 5d formats are final.

### MPD-40 Admin enrolment page in milestone 3, or the CLI only?
- **Context:** the contract (§2.1) had the token page in milestone 5, which was built without it (M-054). Milestone 3 builds the CLI first (M-059).
- **Recommendation:** both. After items 1 to 3, an admin-only page creates a token (shown once), lists and cancels tokens, and shows agents with their certificates, plus a revoke button on the host page; CSRF and an audit row on every change.

### MPD-41 Build the CRL in milestone 3?
- **Context:** the contract (§2.4) has a CRL that Apache loads as a second check; the database check is the authority and already refuses revoked agents at once. Risk: if Apache loads a CRL past its next-update time, it refuses **every** agent.
- **Recommendation:** build it with a safeguard: valid 30 days *(confirm)*, rebuilt on every revocation and daily, and a health-page warning within 7 days of expiry. Alternative: database check only, CRL later.

### MPD-43 Config editing later
- **Context:** `GET /api/v1/config` (item 5) serves what's stored, which is the defaults until a page edits it.
- **Recommendation:** the page for editing settings, exclusions and the detection list comes in milestone 6 or 7, checked against the agent's bounds (M-059).

### MPD-44 Limits on exclusions and redaction rules served to an agent
- **Context:** the contract (§5) limits the detection list (100 entries, 64 KB) but not exclusions or extra redaction rules. `GET /api/v1/config` (M-065) needs a ceiling so one group's row can't send an agent an enormous list.
- **Default:** 500 exclusions and 100 redaction rules (`[agent_config]` in app.ini); anything past that is left out and logged.
- **Recommendation:** keep these, and ask the agent PM whether the agent has its own limits to match.

### Deferred with the backups (M-026)
- **Unraid:** free disk on the cache pool, and where the off-box backup copy goes. Settled with the "before ready to ship" backup work.
