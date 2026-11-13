# Pending decisions

Last updated: 2026-11-13 (milestone 1 server setup written)

Every *(confirm)* value and open question still open, each with a recommendation. To answer, reply with the ID and your choice, for example "MPD-32: keep". Answers are recorded in [decisions.md](decisions.md) and removed from here.

- **Answered:** MPD-01 to MPD-31, recorded as M-017 to M-032.
- **None of the items below blocks the server setup;** each has a safe default already in the scripts.

### MPD-32 Automatic reboots after security updates
- **Default in `03-os-updates.sh`:** security updates install daily, with **no automatic reboot**. A pending reboot is reported, and the milestone 4 health checks will show it.
- **Options:**
  - (a) keep: reboot by hand when told;
  - (b) automatic reboot at a fixed quiet time (e.g. 04:00 UTC) when an update needs one.
- **Recommendation:** **(a)** for development and the pilot. A reboot pauses ingest for a minute or two, which agents absorb by buffering, but a surprise reboot during a load test or a migration is worse. Reconsider (b) before ready to ship, once the health page shows a pending reboot clearly.

### MPD-33 MS-OPS-04 wording after the backup deferral
- **Context:** MS-OPS-04 says a restore is tested "before the pilot and every quarter". M-026 moved the backup and restore work to "before ready to ship", and M-031 backs up only the CA keys and app config before the pilot.
- **Recommendation:** reword MS-OPS-04 in the plan to "before ready to ship and every quarter after", and add that the CA keys and config copy is checked (decrypted and compared) before the pilot. The plan still has the old wording until you answer.

### MPD-34 The dashboard database user can read command lines
- **Context:** `aio_dashboard` has SELECT on every table, including `process_command_lines`. "Analysts only, every view audited" (MS-SEC-07, 08) is enforced in the dashboard code, not by the database.
- **Options:**
  - (a) keep, with the check in one code path that writes the audit row before it reads;
  - (b) a fourth database user, `aio_analyst`, the only one with SELECT on `process_command_lines`. The dashboard uses it only for analyst views, so a bug in a viewer page can't read command lines even by mistake.
- **Recommendation:** **(b)**, decided before milestone 5 builds the views. It costs one more credentials file and a few lines in `grants.sql`. Until then nothing reads the table.

### Deferred with the backups (M-026)
- **Unraid:** free disk on the cache pool, and where the off-box backup copy goes. Settled with the "before ready to ship" backup work.
