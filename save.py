#!/usr/bin/env python3
"""
Collaboration Contract — ingest endpoint (stdlib only, no dependencies).

Adapted from the PBL Board Game endpoint by @rijdho (Ricardo). Same contract:
accepts a JSON event or an array of events (the client flushes in batches),
validates the token, splits member names out of session_start into the
`players` table, and stores pseudonymous events with (session_id, seq) dedupe.

Uses SQLite by default — good for local testing and small single-host
deployments. For production PostgreSQL use the PHP variant (server/save.php)
or port this to psycopg (~20-line change).

Run:            python3 save.py                 # http://localhost:8090
Config (env):   CC_PORT, CC_TOKEN, CC_DB (sqlite file), CC_ORIGIN, CC_MAX_BYTES
"""
import json, os, sqlite3
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT      = int(os.environ.get("CC_PORT", "8090"))
TOKEN     = os.environ.get("CC_TOKEN", "collab-contract-itu-2026")
DB        = os.environ.get("CC_DB", os.path.join(os.path.dirname(__file__), "contract.sqlite"))
ORIGIN    = os.environ.get("CC_ORIGIN", "*")
MAX_BYTES = int(os.environ.get("CC_MAX_BYTES", str(2 * 1024 * 1024)))   # reject oversized bodies

SCHEMA = """
CREATE TABLE IF NOT EXISTS players(
  player_id  TEXT PRIMARY KEY,
  session_id TEXT NOT NULL,
  name       TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS events(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT NOT NULL, seq INTEGER NOT NULL,
  event TEXT NOT NULL, ts TEXT NOT NULL,
  received_at TEXT DEFAULT CURRENT_TIMESTAMP,
  group_name TEXT, cohort TEXT, course TEXT, player_id TEXT,
  payload TEXT NOT NULL,
  UNIQUE(session_id, seq));
"""

def init_db():
    con = sqlite3.connect(DB)
    con.executescript(SCHEMA)
    con.close()

def store(batch):
    con = sqlite3.connect(DB, timeout=10)
    stored = accepted = 0
    with con:
        for ev in batch:
            if not isinstance(ev, dict) or ev.get("token") != TOKEN:
                continue
            if not all(k in ev for k in ("event", "sessionId", "seq", "ts")):
                continue
            accepted += 1
            # Identity separation: names go to `players`, never into the event stream.
            if ev["event"] == "session_start" and isinstance(ev.get("players"), list):
                for p in ev["players"]:
                    if isinstance(p, dict) and "playerId" in p and "name" in p:
                        con.execute(
                            "INSERT OR IGNORE INTO players(player_id, session_id, name) VALUES(?,?,?)",
                            (p["playerId"], ev["sessionId"], p["name"]))
                ev["players"] = [{"playerId": p.get("playerId")} for p in ev["players"]]
            ev.pop("token", None)
            cur = con.execute(
                """INSERT OR IGNORE INTO events
                   (session_id, seq, event, ts, group_name, cohort, course, player_id, payload)
                   VALUES(?,?,?,?,?,?,?,?,?)""",
                (ev["sessionId"], ev["seq"], ev["event"], ev["ts"],
                 ev.get("groupName"), ev.get("cohort"), ev.get("course"),
                 ev.get("player_id"), json.dumps(ev, ensure_ascii=False)))
            stored += cur.rowcount
    con.close()
    return accepted, stored

class Handler(BaseHTTPRequestHandler):
    def _headers(self, code):
        self.send_response(code)
        self.send_header("Access-Control-Allow-Origin", ORIGIN)
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Content-Type", "application/json")
        self.end_headers()

    def do_OPTIONS(self):
        self._headers(204)

    def do_POST(self):
        length = int(self.headers.get("Content-Length", 0) or 0)
        if length > MAX_BYTES:
            self._headers(413); self.wfile.write(b'{"error":"payload too large"}'); return
        try:
            data = json.loads(self.rfile.read(length))
        except (ValueError, TypeError):
            self._headers(400); self.wfile.write(b'{"error":"invalid JSON"}'); return
        batch = [data] if isinstance(data, dict) else data if isinstance(data, list) else []
        accepted, stored = store(batch)
        # 403 when a non-empty batch had zero valid events (usually a token mismatch):
        # a 200 here would make the client drop a queue that was never stored.
        if batch and accepted == 0:
            self._headers(403); self.wfile.write(b'{"error":"no events accepted - check token"}'); return
        self._headers(200)
        self.wfile.write(json.dumps({"ok": True, "accepted": accepted, "stored": stored}).encode())

    def log_message(self, *args):   # keep classroom logs quiet
        pass

if __name__ == "__main__":
    init_db()
    print(f"Collaboration Contract ingest on http://localhost:{PORT} → {DB}")
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
