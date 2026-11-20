# Pending decisions

Last updated: 2026-11-20 (MPD-37 added)

Every *(confirm)* value and open question still open, each with a recommendation. To answer, reply with the ID and your choice, for example "MPD-32: keep". Answers are recorded in [decisions.md](decisions.md) and removed from here.

- **Answered:** MPD-01 to MPD-34, recorded as M-017 to M-041; MPD-36, recorded as M-050.
- **Open:** MPD-35 (the default is in the code) and MPD-37 (before milestone 4).

### MPD-35 How much one simulated server produces
- **Context:** MS-DAT-06 (MB per host per day) and the load test depend on the simulator's volume per host (`src/Sim/Profile.php`, M-049). The defaults are estimates.
- **Default:** 20 flows per 10 s, a network snapshot every 5 minutes (40 connections, 12 listeners, 6 UDP), 2 process start/stop pairs a minute, ETW status every minute, a new executable every 5 minutes, a full inventory (60 apps, 120 services) at start and daily.
- **Recommendation:** keep these for the milestone 2 gate, then compare with the real agent on your dev machine once agent 5c can send, and with the agent's own output files before that if you can share a day of them. The MB per host per day figure is reported with the profile it was measured on.

### MPD-37 Disk and retention for 111 MB per host per day
- **Context:** milestone 2 measured **111.3 MB per host per day** (servers 120, laptops 102) on the simulator's estimated volume (MPD-35), against the plan's estimate of 25 MB (MS-DAT-06). `net_flows` is 85 % of it (95 MB per host per day, about 670 bytes per row with indexes). With 90 days of detail *(confirm)*: this VM with 4 hosts about 40 GB (fits the 100 GB disk); a 50-host pilot about 500 GB (plan: 250 GB); 500 hosts about 4.9 TB (plan: 1.5 TB).
- **Options** (they combine):
  1. **Measure the real agent first:** the dev-machine agent's flows per day may be well above or below the simulator's 20 per 10 s.
  2. **Keep flow detail shorter** (e.g. 30 days) and everything else 90 days; the daily summaries (MS-DAT-03) keep the history of who talked to whom. About 55 % less disk.
  3. **Compress `net_flows`** (InnoDB compression, a migration): often about half the size, for some CPU. Tested on simulator data before deciding.
  4. **Slim the `net_flows` row:** review its columns and indexes for repeated strings (process path, addresses) that could be stored once.
- **Recommendation:** 1 in any case; 3 and 4 tried in milestone 4 on simulator data, with the figures reported; 2 decided after that, with the real agent's numbers. Not needed for the development VM: 4 hosts fit.

### Deferred with the backups (M-026)
- **Unraid:** free disk on the cache pool, and where the off-box backup copy goes. Settled with the "before ready to ship" backup work.
