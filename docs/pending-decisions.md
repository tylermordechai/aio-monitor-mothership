# Pending decisions

Last updated: 2026-11-19 (MPD-35 added)

Every *(confirm)* value and open question still open, each with a recommendation. To answer, reply with the ID and your choice, for example "MPD-32: keep". Answers are recorded in [decisions.md](decisions.md) and removed from here.

- **Answered:** MPD-01 to MPD-34, recorded as M-017 to M-041.
- **Open:** MPD-35. It doesn't block building; the default is in the code.

### MPD-35 How much one simulated server produces
- **Context:** MS-DAT-06 (MB per host per day) and the load test depend on the simulator's volume per host (`src/Sim/Profile.php`, M-049). The defaults are estimates.
- **Default:** 20 flows per 10 s, a network snapshot every 5 minutes (40 connections, 12 listeners, 6 UDP), 2 process start/stop pairs a minute, ETW status every minute, a new executable every 5 minutes, a full inventory (60 apps, 120 services) at start and daily.
- **Recommendation:** keep these for the milestone 2 gate, then compare with the real agent on your dev machine once agent 5c can send, and with the agent's own output files before that if you can share a day of them. The MB per host per day figure is reported with the profile it was measured on.

### Deferred with the backups (M-026)
- **Unraid:** free disk on the cache pool, and where the off-box backup copy goes. Settled with the "before ready to ship" backup work.
