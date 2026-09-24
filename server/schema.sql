-- ═══════════════════════════════════════════════════════════════════
-- Collaboration Contract — telemetry schema (PostgreSQL 13+)
-- Target: university-cloud Postgres (Route A) or Open Telekom Cloud RDS,
-- EU region (Route B). Same code either way.
--
-- Adapted from the PBL Board Game schema by @rijdho (Ricardo). GDPR design:
-- names live ONLY in `players`; events reference pseudonyms. Deleting
-- `players` after the feedback period anonymises the whole dataset.
--
-- What is different from the PBL schema (contract-specific):
--   • The contract CONTENT is the research object, and much of it is free
--     text. The client sends it in contract_complete.payload as two parts:
--       - structured, non-identifying:  `values`, `commitment`  (kept in anon view)
--       - free text:                     `text`                  (stripped from anon view)
--   • v_contract_latest reconstructs one row per group's finished contract
--     so feedback/export never has to re-stitch many section_saved events.
--   • v_contract_text is a restricted view over the free-text answers.
-- ═══════════════════════════════════════════════════════════════════

-- Identity table — restricted access (instructor role only).
CREATE TABLE IF NOT EXISTS players (
  player_id   uuid PRIMARY KEY,
  session_id  text NOT NULL,
  name        text NOT NULL,
  created_at  timestamptz NOT NULL DEFAULT now()
);

-- Event stream — pseudonymous. The ingest endpoint strips member names from
-- session_start before rows land here.
CREATE TABLE IF NOT EXISTS events (
  id           bigserial PRIMARY KEY,
  session_id   text NOT NULL,
  seq          bigint NOT NULL,
  event        text NOT NULL,
  ts           timestamptz NOT NULL,
  received_at  timestamptz NOT NULL DEFAULT now(),
  group_name   text,
  cohort       text,
  course       text,
  player_id    uuid,
  payload      jsonb NOT NULL,
  UNIQUE (session_id, seq)     -- dedupe: retries / sendBeacon duplicates are no-ops
);

CREATE INDEX IF NOT EXISTS idx_cc_events_event   ON events (event);
CREATE INDEX IF NOT EXISTS idx_cc_events_session ON events (session_id);
CREATE INDEX IF NOT EXISTS idx_cc_events_cohort  ON events (cohort);
CREATE INDEX IF NOT EXISTS idx_cc_events_ts      ON events (ts);

-- Every identified access is logged (GDPR Art. 25 — controlled re-identification).
CREATE TABLE IF NOT EXISTS reidentification_audit (
  id           bigserial PRIMARY KEY,
  accessed_by  text NOT NULL,
  accessed_at  timestamptz NOT NULL DEFAULT now(),
  reason       text NOT NULL,
  session_id   text
);

-- ── Default view: ANONYMISED. Player identities become per-session codes
--    (P1, P2…). Free-text answers (`text`) are removed here by design.
CREATE OR REPLACE VIEW v_events_anon AS
SELECT e.id, e.session_id, e.seq, e.event, e.ts, e.received_at,
       e.group_name, e.cohort, e.course,
       CASE WHEN e.player_id IS NULL THEN NULL
            ELSE 'P' || dense_rank() OVER (PARTITION BY e.session_id ORDER BY e.player_id NULLS LAST)
       END AS player_code,
       e.payload - 'text' AS payload      -- free text excluded from the analyst view
FROM events e;

-- ── One row per finished contract — STRUCTURED answers only (no free text).
--    Analysts use this for RQ3 (commitment) and RQ4 (values).
CREATE OR REPLACE VIEW v_contract_latest AS
SELECT e.session_id, e.group_name, e.cohort, e.course, e.ts AS completed_at,
       e.payload -> 'values'          AS values_chosen,     -- jsonb array
       e.payload -> 'commitment'      AS commitment_by_code,-- jsonb object keyed by pseudonym
       (e.payload ->> 'valuesCount')::int AS values_count,
       (e.payload ->> 'totalDwellMs')::bigint AS total_dwell_ms
FROM events e
WHERE e.event = 'contract_complete';

-- ── Restricted view over the FREE-TEXT contract answers. Teaching team only;
--    review before sharing outside the team (may name teammates incidentally).
CREATE OR REPLACE VIEW v_contract_text AS
SELECT e.session_id, e.group_name, e.cohort, e.ts AS completed_at,
       e.payload -> 'text' AS answers_text
FROM events e
WHERE e.event = 'contract_complete';

-- ── IDENTIFIED view: feedback only. Instructor role; log access in
--    reidentification_audit first.
CREATE OR REPLACE VIEW v_events_identified AS
SELECT e.*, p.name AS player_name
FROM events e LEFT JOIN players p USING (player_id);

-- ── Roles (run as superuser; adjust names/passwords to your environment) ──
-- CREATE ROLE cc_ingest     LOGIN PASSWORD '...';  -- endpoint: INSERT only
-- CREATE ROLE cc_analyst    LOGIN PASSWORD '...';  -- analysis: anonymised views
-- CREATE ROLE cc_instructor LOGIN PASSWORD '...';  -- feedback: identified view + audit duty
-- GRANT INSERT ON events, players TO cc_ingest;
-- GRANT USAGE, SELECT ON SEQUENCE events_id_seq TO cc_ingest;
-- GRANT SELECT ON v_events_anon, v_contract_latest TO cc_analyst;
-- GRANT SELECT ON v_contract_text TO cc_instructor;             -- qualitative coding
-- GRANT SELECT ON v_events_identified, players TO cc_instructor;
-- GRANT INSERT ON reidentification_audit TO cc_instructor;

-- ── Retention / anonymisation (run at end of the feedback period) ─────────
-- DELETE FROM players;                          -- irreversible; events keep pseudonyms only
-- Withdrawal of one participant (by pseudonym or name):
-- DELETE FROM players WHERE player_id = '<uuid>';   -- removes the name link
-- Optionally also drop that person's contributions from the event stream if requested.
