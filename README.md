# Collaboration Contract

[![Made with Claude](https://img.shields.io/badge/Made_with-Claude-D97757?logo=claude&logoColor=white)](https://claude.ai)
[![License: CC BY-SA 4.0](https://img.shields.io/badge/License-CC_BY--SA_4.0-lightgrey)](https://creativecommons.org/licenses/by-sa/4.0/)

A single-file web app for the **collaboration contract** students fill in at the start of a
project-based-learning group at **IT:U** (Interdisciplinary Transformation University Austria).
It turns the paper **Team Canvas** into a consent-aware, GDPR-conscious digital tool built around
the five reflective questions of **Holgaard et al. (2021)**.

Groups move through five short sections — who we are, what we can do, how far we will go, what a good
group means, and what good group work looks like — and the app assembles their answers into a Team
Canvas that becomes their contract. At the end the group exports the contract and downloads a
completion badge.

## Files

| File | What it is |
|---|---|
| `index.html` | The app. One self-contained file (HTML + CSS + JS). No build step. |
| `analysis.html` | **Data Console** — in-browser SQLite (sql.js/WASM); analyse pilot data with SQL, export CSV/.sqlite/JSON. Zero backend. |
| `vendor/sqljs/` | Self-hosted sql.js engine (MIT) — no external requests, works offline. |
| `TECHNICAL.md` | Technical documentation: storage & data architecture, event catalog, ingest, GDPR, deployment. |
| `server/schema.sql` | PostgreSQL schema — pseudonymised events, views, roles. |
| `server/save.py` | Ingest endpoint (Python stdlib, SQLite) — name-splitting, `(sessionId, seq)` dedupe. |
| `Collaboration_Contract_Plan.docx` | Design & research plan: architecture, data schema, consent text, RQ mapping. |
| `LICENSE` | CC BY-SA 4.0 full text. |
| `README.md` | This file. |

## Run it

Just open `collaboration-contract.html` in a browser (double-click). It works offline and on phone or
desktop. Pass cohort context via the URL:

```
collaboration-contract.html?cohort=WS2026&course=PBL101
```

**Pilot mode (default).** With no server configured, all research telemetry is buffered in
`localStorage` on the device and can be exported as JSON from the final screen. This mode is for
testing — not for real student cohorts.

**Analyse pilot data — no backend.** Open `analysis.html` (the Data Console). It loads this browser's
buffer and/or JSON files imported from other devices into an in-memory SQLite (sql.js/WASM), applying
the same ingest contract as the server (name-splitting, `(sessionId, seq)` dedupe). Preset queries
cover the five research questions (values chosen, commitment spread, time per section, funnel, consent,
free-text corpus); results export to CSV, the whole DB to `.sqlite` (opens directly in pandas), raw
events to JSON. Mirrors the PBL Board Game's Data Console by @rijdho.

**Production.** Set the `CONFIG` block at the top of the `<script>`:

```js
const CONFIG = { endpoint:'https://your-host/ingest', token:'<shared token>', ... };
```

With an endpoint set, the app attaches the token, batches events in an offline outbox, and flushes
them to the ingest endpoint (`server/save.py` or `save.php`) — retries are safe because the server
dedupes on `(sessionId, seq)`. Run `server/schema.sql` on Postgres first and create the three roles.
The full storage & data architecture is documented in **`TECHNICAL.md`**.

## Colours

The whole colour scheme is controlled by three CSS variables at the top of the file
(`--itu-accent`, `--itu-accent-2`, `--itu-ink`). Replace them with the official IT:U brand hex values.

## Acknowledgements

This project stands on the work of others. Please keep these acknowledgements with any copy or fork.

- **Team Canvas** — the underlying instrument. Created by **Alexey Ivanov & Dmitry Voloshchuk**,
  [theteamcanvas.com](https://theteamcanvas.com/), licensed **CC BY-SA 4.0**. The canvas layout is
  recreated and adapted here under the same licence. (Team Canvas is itself inspired by the Business
  Model Canvas by Strategyzer.)

- **Reflective questions** — the five prompts and their framing are from
  **Holgaard, J. E., et al. (2021)**.

- **Guess the Replication** — the retro interface design is adapted (modified) from
  *Guess the Replication* by **Lukas Röseler & Jasmin Röseler**, part of the FORRT Open Research Games
  project. Licensed **CC BY 4.0** — [source](https://lukasroeseler.github.io/GuessTheReplication/).
  Our thanks to the authors for releasing their work openly.

- **Ricardo ([@rijdho](https://github.com/rijdho))** — the data & privacy architecture this app builds
  on: the pseudonymised event stream, `(sessionId, seq)` dedupe, three EU storage routes,
  anonymised/identified views with a re-identification audit, retention-by-deletion, and the in-browser
  **Data Console** pattern. Originally developed for the PBL Board Game “The Dachstein Ascent” at IT:U;
  this project adapts the same ingest contract and analysis approach. Thank you for the contribution.

- **Made with Claude** — this app and its documentation were built with Claude (Anthropic).

## Licence

Because the Team Canvas is licensed **CC BY-SA 4.0** (ShareAlike), this adaptation is released under
the **same licence: [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/)**. You may share
and adapt it, including commercially, provided you give attribution and license your derivative under
CC BY-SA 4.0. The *Guess the Replication* design contribution (CC BY 4.0) is compatible with inclusion
in this ShareAlike work.

## Contact

**Marjorie Cristina Da Cruz** · [marjorie.da-cruz@it-u.at](mailto:marjorie.da-cruz@it-u.at) · IT:U
