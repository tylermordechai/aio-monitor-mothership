# Pending decisions

Last updated: 2026-11-19 (MPD-36 added)

Every *(confirm)* value and open question still open, each with a recommendation. To answer, reply with the ID and your choice, for example "MPD-32: keep". Answers are recorded in [decisions.md](decisions.md) and removed from here.

- **Answered:** MPD-01 to MPD-34, recorded as M-017 to M-041.
- **Open:** MPD-35 (doesn't block building; the default is in the code) and MPD-36 (blocks the resize before the milestone 2 gate).

### MPD-35 How much one simulated server produces
- **Context:** MS-DAT-06 (MB per host per day) and the load test depend on the simulator's volume per host (`src/Sim/Profile.php`, M-049). The defaults are estimates.
- **Default:** 20 flows per 10 s, a network snapshot every 5 minutes (40 connections, 12 listeners, 6 UDP), 2 process start/stop pairs a minute, ETW status every minute, a new executable every 5 minutes, a full inventory (60 apps, 120 services) at start and daily.
- **Recommendation:** keep these for the milestone 2 gate, then compare with the real agent on your dev machine once agent 5c can send, and with the agent's own output files before that if you can share a day of them. The MB per host per day figure is reported with the profile it was measured on.

### MPD-36 Where the VM disk lives (no SSD on the server)
- **Context:** the plan assumes the VM disk is on an SSD cache pool (CLAUDE.md, Platform). The Unraid server has no SSD: ten 1.2 TB 10k SAS drives (HGST HUC101812CSS205), seven of them array data drives, no pool. The VM disk is on `disk1` in the parity array, as a chain of 3 qcow2 snapshots over a raw file, reached through `/mnt/user`. A disk sync takes 17 ms at the median and up to 97 ms (an SSD: under 1 ms), and every ingest acknowledgement waits for one (MS-ING-03, MS-ING-10).
- **Options:**
  1. **Free fixes now, decide on hardware after the gate:** at the resize, merge the chain into one raw 250 GB file on `/mnt/disk1` directly (no qcow2 layers, no FUSE layer), keep the old files as the M1-done rollback, re-measure, run the gate. Still on the parity array.
  2. **Add an SSD pool now** (ideally an SSD with power-loss protection), move the VM disk there. Matches the plan; needs a drive bought and fitted.
  3. **A pool from a spare SAS drive** (mirrored, if two are free) outside the parity array. Only if drives are unassigned; faster than the array, slower than an SSD.
  - Not an option: relaxing the database sync (`innodb_flush_log_at_trx_commit = 2`), because an acknowledged batch could be lost in a power cut after the agent deleted it.
- **Recommendation:** option 1 for the milestone 2 gate, since it costs nothing and shows what the hard drives can do. If the gate passes, the pilot can run on it, with an SSD added before ready to ship (with the backup work, M-026). If it fails, option 2.

### Deferred with the backups (M-026)
- **Unraid:** free disk on the cache pool, and where the off-box backup copy goes. Settled with the "before ready to ship" backup work.
