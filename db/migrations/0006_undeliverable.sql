-- 0006_undeliverable.sql (MS-ING-12, MS-UI-01, M-063, M-067)
--
-- The agent's `undeliverable` file (ingest-api.md v1.1, §4, §8.1 rule 7):
-- lines that still failed after splitting. The heartbeat reports
-- queue.undeliverable_records and queue.undeliverable_bytes; the latest
-- values are kept on the agent for the fleet table, like the queue figures.
-- The full heartbeat body (agent_heartbeats.body) already keeps them.
--
-- Columns only; agents is small, so a plain ALTER is fine.

ALTER TABLE agents
    ADD COLUMN queue_undeliverable_records BIGINT UNSIGNED NULL COMMENT 'heartbeat queue.undeliverable_records (v1.1)' AFTER queue_dropped_records,
    ADD COLUMN queue_undeliverable_bytes   BIGINT UNSIGNED NULL COMMENT 'heartbeat queue.undeliverable_bytes (v1.1)' AFTER queue_undeliverable_records;
