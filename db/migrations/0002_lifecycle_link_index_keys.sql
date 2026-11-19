-- 0002_lifecycle_link_index_keys.sql (MS-DAT-01, MS-ING-03; M-005, M-046)
--
-- agent_lifecycle and inventory_link_index are not partitioned, and 0001 gave
-- them PRIMARY KEY (agent_pk, seq) without ts. After a state rollback (a
-- restored VM snapshot reuses seq numbers, ingest-api.md §3.6) the new
-- agent_start would then look like a resend and be dropped, and it is the
-- very record the rollback check looks for. Every other record table already
-- keys on (agent_pk, seq, ts); these two now do as well. Columns are unchanged.

ALTER TABLE agent_lifecycle
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (agent_pk, seq, ts);

ALTER TABLE inventory_link_index
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (agent_pk, seq, ts);
