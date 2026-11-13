# Pending decisions

Last updated: 2026-11-13 (after the remaining review answers)

Every *(confirm)* value and open question still open, each with a recommendation. To answer, reply with the ID and your choice, for example "MPD-30: a". Answers are recorded in [decisions.md](decisions.md) and removed from here.

- **Answered:** MPD-01 to MPD-28, recorded as M-017 to M-028. The contract is approved.
- **None of the items below blocks the server setup.** MPD-30 should be settled before milestone 4.

### MPD-29 Minor defaults found while recording the review
These were *(confirm)* values in ingest-api.md that no earlier MPD covered. They are kept as defaults in config.

| Value | Default | Where | Recommendation |
| --- | --- | --- | --- |
| 8443 server certificate lifetime | 1 year, issued by the agent CA | ingest-api.md §2.3 | Keep, and renew it with a systemd timer 30 days before expiry. Agents pin the CA, not the certificate, so a renewal needs no agent change |
| `seq` rollback alert threshold | more than 1,000 non-duplicate lines below `max_seq_held` (or any `agent_start` below it) | §3.6 | Keep, then tune from the simulator's rollback test in milestone 2 |
| Heartbeat interval | 60 s (agent D-079) | §4 | Keep |
| Config poll backstop | every 15 minutes, besides the `config_etag` check | §5 | Keep |

### MPD-30 Milestone 4's new gate, and what to back up before the pilot
- **Context:** with backups moved out (M-026), milestone 4's old gate ("restore to a fresh VM tested and timed") no longer applies.
- **Proposed gate:** on the simulator's data, with the clock moved forward:
  - partitions are created 7 days ahead and dropped exactly at each table's retention;
  - nightly summaries match the detail they were built from;
  - each health check (disk, database, ingest errors, quarantine growth, job last success, certificate expiry) fires when its condition is forced, and clears after.
- **Before the pilot, not only before ship:** the pilot's agents enrol against the agent CA. If the VM is lost without a copy of that key, every pilot agent must be re-enrolled by hand, and the admin PC must import a new web CA.
  - (a) back up **only the two CA keys and the app config** (MS-OPS-02) before the pilot: an encrypted copy, kept off the VM, done once and then on change. Everything else waits as agreed.
  - (b) nothing before ship, as agreed; accept re-enrolling the pilot after a VM loss.
- **Also:** MS-OPS-04 says "before the pilot and every quarter"; with the deferral it would read "before ready to ship".
- **Recommendation:** the gate above, and **(a)**. It is small (a few KB, no database tooling), and it avoids the one loss that can't be fixed from the repo.

### MPD-31 DShield's terms (a FireHOL Level 1 source)
- **Context:** FireHOL Level 1 aggregates dshield, feodo, fullbogons and spamhaus_drop. Feodo and Spamhaus DROP terms are recorded (M-027). DShield's (SANS Internet Storm Center) aren't checked yet; FireHOL says the lists "are a property of their maintainers".
- **Recommendation:** check DShield's terms before milestone 6 enables the fetch. If they don't fit, use FireHOL Level 1 anyway only if the DShield part can be excluded, otherwise disable that list. The other two lists already cover FireHOL's feodo and spamhaus_drop parts.

### Deferred with the backups (M-026)
- **Unraid:** free disk on the cache pool, and where the off-box backup copy goes (the rest of MPD-26). Settled with the "before ready to ship" backup work.
