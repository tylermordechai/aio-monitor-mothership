-- 0004_host_page_indexes.sql (MS-UI-08, M-050, M-057)
--
-- Two indexes for the host page, found by tools/page-times.php on 7 days of
-- simulator data (2026-11-23): the processes tab for 7 days took 16.3 s and
-- the executables tab 1.8 s.
--
-- - process_events (host_pk, record_type, ts): the processes tab lists one
--   host's process_start and process_snapshot rows, newest first, and counts
--   them. ix_pe_host_ts has no record_type, so every row of the range was
--   read from disk to filter it out. With this index the list reads only
--   the rows it shows, and the count is read from the index alone.
-- - executables (host_pk, last_seen): the executables tab lists one host's
--   rows, latest first; this reads them in order instead of sorting them all.
--
-- Keys only, no column changes (tools/gen-table-map.php: table map unchanged).
-- Online: ingest keeps writing while the indexes are built.

ALTER TABLE process_events
    ADD KEY ix_pe_host_type_ts (host_pk, record_type, ts),
    ALGORITHM = INPLACE, LOCK = NONE;

ALTER TABLE executables
    ADD KEY ix_exe_host_last_seen (host_pk, last_seen),
    ALGORITHM = INPLACE, LOCK = NONE;
