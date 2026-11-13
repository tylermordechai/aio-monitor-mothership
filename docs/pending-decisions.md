# Pending decisions

Last updated: 2026-11-13 (after the milestone 1 review)

Every *(confirm)* value and open question still open, each with a recommendation. To answer, reply with the ID and your choice, for example "MPD-20: …". Answers are recorded in [decisions.md](decisions.md) and removed from here.

- **Answered at the milestone 1 review:** MPD-01 to MPD-19 and MPD-21 to MPD-24, recorded as M-017 to M-023. The contract is approved.
- **None of the items below blocks the contract.** MPD-20 and MPD-25 are needed before or during the server setup.

## A. Needed for the server setup

### MPD-20 Collector DNS name
- **Plan:** `aio-mothership.<your-domain>` *(confirm)*.
- **Needed:** the domain, and which CA issues the dashboard's 443 certificate (internal CA, or Let's Encrypt with a DNS challenge). The name goes into the 8443 certificate's SAN and the MSI property.
- **Recommendation:** an internal DNS name under your existing domain, with the 443 certificate from Let's Encrypt using a DNS challenge if the domain's DNS has an API, otherwise from an internal CA. No inbound internet port is needed either way.

### MPD-25 Networks
- **Needed:** the networks the servers and the admin workstations sit on, for the firewall rules (8443 from servers, 443 from admin workstations).
- **Recommendation:** allow 8443 only from the server subnets and 443 only from the admin subnet; SSH only from the admin subnet.

## B. Not blocking milestones 1 to 3

### MPD-26 Unraid
- **Needed:** free disk on the cache pool, and where the off-box backup copy goes.
- **Recommendation:** reserve room for 90 days of detail at the size milestone 2 measures (MS-DAT-06), plus 50 %. Keep the off-box copy on a different physical machine or a removable disk.

### MPD-27 Enrichment
- **Threat lists proposed:** abuse.ch Feodo Tracker, Spamhaus DROP, FireHOL Level 1; each list's terms to be checked.
- **GeoLite2:** whether to create a MaxMind account.
- **Recommendation:** those three lists, and a MaxMind account (free, licence key stored outside the repo). Decide before milestone 6.

### MPD-28 Privacy
- **Needed:** who gets analyst access (command lines), and who signs off on privacy before the pilot.
- **Recommendation:** as few analysts as possible, named; sign-off by whoever owns data protection for the hosts in the pilot. Needed before milestone 7.

### MPD-29 Minor defaults found while recording the review
These were *(confirm)* values in ingest-api.md that no earlier MPD covered. They are kept as defaults in config.

| Value | Default | Where | Recommendation |
| --- | --- | --- | --- |
| 8443 server certificate lifetime | 1 year, issued by the agent CA | ingest-api.md §2.3 | Keep, and renew it with a systemd timer 30 days before expiry. Agents pin the CA, not the certificate, so a renewal needs no agent change |
| `seq` rollback alert threshold | more than 1,000 non-duplicate lines below `max_seq_held` (or any `agent_start` below it) | §3.6 | Keep, then tune from the simulator's rollback test in milestone 2 |
| Heartbeat interval | 60 s (agent D-079) | §4 | Keep |
| Config poll backstop | every 15 minutes, besides the `config_etag` check | §5 | Keep |
