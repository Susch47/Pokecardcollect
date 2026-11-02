"""SQLite: welche Anzeigen schon gesehen wurden, Preisverlauf, Bewertungen."""
from __future__ import annotations

import json
import sqlite3
from datetime import datetime
from pathlib import Path

SCHEMA = """
CREATE TABLE IF NOT EXISTS ads (
  ad_id TEXT PRIMARY KEY,
  target TEXT, title TEXT, url TEXT, first_seen TEXT, last_seen TEXT,
  first_price REAL, last_price REAL, verdict TEXT, profit REAL, risk INTEGER,
  notified INTEGER DEFAULT 0, data TEXT
);
CREATE TABLE IF NOT EXISTS price_history (
  ad_id TEXT, seen TEXT, price REAL
);
"""


class Store:
    def __init__(self, path: str | Path = "data/tracker.db"):
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(path)
        self.db.executescript(SCHEMA)

    def known(self, ad_id: str) -> dict | None:
        r = self.db.execute("SELECT ad_id, last_price, verdict, notified FROM ads WHERE ad_id=?",
                            (ad_id,)).fetchone()
        return dict(zip(("ad_id", "last_price", "verdict", "notified"), r)) if r else None

    def upsert(self, a) -> dict:
        """Speichert eine Bewertung; gibt Infos zurück (neu? Preissenkung?)."""
        now = datetime.now().isoformat(timespec="seconds")
        lst = a.listing
        prev = self.known(lst.ad_id)
        payload = json.dumps({"flags": a.flags, "positives": a.positives,
                              "market": a.market_adj, "max_offer": a.max_offer,
                              "location": lst.location, "image": lst.image,
                              "message": a.message_draft}, ensure_ascii=False)
        if prev is None:
            self.db.execute(
                "INSERT INTO ads VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?)",
                (lst.ad_id, a.target.name, lst.title, lst.url, now, now, lst.price,
                 lst.price, a.verdict, a.profit, a.risk, payload))
        else:
            self.db.execute(
                "UPDATE ads SET last_seen=?, last_price=?, verdict=?, profit=?, risk=?, data=? "
                "WHERE ad_id=?",
                (now, lst.price, a.verdict, a.profit, a.risk, payload, lst.ad_id))
        if prev is None or prev["last_price"] != lst.price:
            self.db.execute("INSERT INTO price_history VALUES (?,?,?)", (lst.ad_id, now, lst.price))
        self.db.commit()
        drop = (prev and prev["last_price"] and lst.price is not None
                and lst.price < prev["last_price"])
        return {"new": prev is None, "price_drop": bool(drop),
                "old_price": prev["last_price"] if prev else None,
                "notified": bool(prev and prev["notified"])}

    def mark_notified(self, ad_id: str):
        self.db.execute("UPDATE ads SET notified=1 WHERE ad_id=?", (ad_id,))
        self.db.commit()
