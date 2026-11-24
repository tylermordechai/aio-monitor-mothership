-- 0005_token_cancel.sql (MS-SEC-03, M-021, M-061)
--
-- Enrolment tokens can be cancelled before use (bin/token-create.php cancel,
-- later the admin page, MPD-40). A cancelled token is refused like an unknown,
-- used or expired one (ingest-api.md §2.1: one answer, enrollment_token_invalid).
-- created_by holds a users.id; a token made by the CLI has none, so the CLI
-- login ("cli:aioadmin") is kept in created_by_name.
--
-- Columns only; enrollment_tokens is small, so a plain ALTER is fine.

ALTER TABLE enrollment_tokens
    ADD COLUMN created_by_name VARCHAR(100) NULL COMMENT 'who made it: cli:<login> or a dashboard user name' AFTER created_by,
    ADD COLUMN cancelled_at    DATETIME(6)  NULL COMMENT 'set: cancelled before use, refused by enrol' AFTER used_from_ip,
    ADD COLUMN cancelled_by    VARCHAR(100) NULL AFTER cancelled_at;
