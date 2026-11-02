"""Live-Scanner: läuft im Hintergrund-Thread, speichert Treffer in SQLite."""
from __future__ import annotations

import json
import logging
import os
import re
import sqlite3
import threading
import time
from datetime import datetime, timedelta
from pathlib import Path

import requests

from .cards import BY_NUM, CARDS, apply_tcgdex_names
from .matcher import describe, match_listing

log = logging.getLogger("pitchtracker")

SCHEMA = """
CREATE TABLE IF NOT EXISTS hits (
  id TEXT PRIMARY KEY, source TEXT, title TEXT, body TEXT, url TEXT, image TEXT,
  price REAL, currency TEXT, price_raw TEXT, location TEXT, posted TEXT,
  nums TEXT, confidence TEXT, ambiguous INTEGER, is_lot INTEGER, graded TEXT,
  language TEXT, first_seen TEXT, last_seen TEXT, first_price REAL,
  hidden INTEGER DEFAULT 0, viewed INTEGER DEFAULT 0, query TEXT
);
"""


def parse_ranges(text: str) -> set[int]:
    """'1-30, 45 48;116' -> {1..30, 45, 48, 116}"""
    out: set[int] = set()
    for a, b in re.findall(r"(\d{1,3})(?:\s*-\s*(\d{1,3}))?", text or ""):
        lo, hi = int(a), int(b or a)
        out.update(n for n in range(min(lo, hi), max(lo, hi) + 1) if 1 <= n <= 120)
    return out


class Tracker:
    def __init__(self, cfg: dict, data_dir: str | Path = "data", sources=None):
        self.cfg = cfg
        self.data = Path(data_dir)
        self.data.mkdir(parents=True, exist_ok=True)
        self.db_lock = threading.Lock()
        self.db = sqlite3.connect(self.data / "hits.db", check_same_thread=False)
        self.db.row_factory = sqlite3.Row
        self.db.executescript(SCHEMA)
        self.coll_file = self.data / "collection.json"
        self.owned: set[int] = set(json.loads(self.coll_file.read_text())["owned"]) \
            if self.coll_file.exists() else set()
        self.sources = sources if sources is not None else self._make_sources()
        self.status = {s.name: {"ok": None, "error": "", "last": None, "found": 0}
                       for s in self.sources}
        self.scanning = False
        self.last_scan = None
        self.next_scan = None
        self.cycle = 0
        self.log: list[str] = []
        self._wake = threading.Event()
        self._stop = False

    # ------------------------------------------------------------------ setup
    def _make_sources(self):
        from .sources import Kleinanzeigen, Tutti
        out = []
        sc = self.cfg.get("sources", {})
        if sc.get("kleinanzeigen", {}).get("enabled", True):
            k = sc.get("kleinanzeigen", {})
            out.append(Kleinanzeigen(delay=tuple(k.get("delay", [4, 9])), pages=k.get("pages", 2)))
        if sc.get("tutti", {}).get("enabled", True):
            try:
                out.append(Tutti(delay=sc.get("tutti", {}).get("delay", 2.0)))
            except ImportError:
                self._log("tutti-scraper nicht installiert → tutti deaktiviert")
        return out

    def _log(self, msg: str):
        line = f"{datetime.now():%H:%M:%S} {msg}"
        log.info(msg)
        self.log = (self.log + [line])[-60:]

    # ------------------------------------------------------------ collection
    @property
    def missing(self) -> set[int]:
        return {c["num"] for c in CARDS} - self.owned

    def set_owned(self, nums: set[int], owned: bool):
        self.owned = (self.owned | nums) if owned else (self.owned - nums)
        self.coll_file.write_text(json.dumps({"owned": sorted(self.owned)}))

    def replace_collection(self, owned: set[int]):
        self.owned = set(owned)
        self.coll_file.write_text(json.dumps({"owned": sorted(self.owned)}))

    # ---------------------------------------------------------------- queries
    def queries_for_cycle(self) -> list[str]:
        q = list(self.cfg.get("broad_queries", ["dunkelnacht pokemon", "pitch black pokemon"]))
        rar = set(self.cfg.get("targeted_rarities", ["RR", "H", "IR", "UR", "SIR", "MHR"]))
        targets = sorted({(BY_NUM[n]["core_de"] or BY_NUM[n]["core"], n)
                          for n in self.missing if BY_NUM[n]["rarity"] in rar},
                         key=lambda x: -x[1])        # Secret Rares zuerst
        k = int(self.cfg.get("targeted_per_cycle", 6))
        if targets and k:
            start = (self.cycle * k) % len(targets)
            pick = (targets + targets)[start:start + min(k, len(targets))]
            q += [f"{name} {num}" for name, num in pick]
        return q

    # ------------------------------------------------------------------- scan
    def scan_once(self):
        self.scanning = True
        new_hits = []
        try:
            queries = self.queries_for_cycle()
            for src in self.sources:
                st = self.status[src.name]
                found = 0
                try:
                    for q in queries:
                        for item in src.search(q):
                            found += 1
                            h = self._ingest(item, q)
                            if h:
                                new_hits.append(h)
                    st.update(ok=True, error="", last=datetime.now().isoformat(timespec="seconds"),
                              found=found)
                except Exception as e:  # noqa: BLE001
                    st.update(ok=False, error=str(e)[:200],
                              last=datetime.now().isoformat(timespec="seconds"))
                    self._log(f"{src.name}: Fehler – {e}")
            self.cycle += 1
            self.last_scan = datetime.now().isoformat(timespec="seconds")
            self._log(f"Scan #{self.cycle}: {len(queries)} Suchen, {len(new_hits)} neue Treffer")
            for h in new_hits:
                self._notify(h)
        finally:
            self.scanning = False
        return new_hits

    def _ingest(self, item: dict, query: str):
        m = match_listing(item["title"], item.get("body", ""))
        if m.excluded or not m.nums:
            return None
        now = datetime.now().isoformat(timespec="seconds")
        with self.db_lock:
            row = self.db.execute("SELECT id, price FROM hits WHERE id=?", (item["id"],)).fetchone()
            if row:
                self.db.execute("UPDATE hits SET last_seen=?, price=?, price_raw=?, title=? WHERE id=?",
                                (now, item["price"], item["price_raw"], item["title"], item["id"]))
                self.db.commit()
                return None
            self.db.execute(
                "INSERT INTO hits (id, source, title, body, url, image, price, currency, price_raw,"
                " location, posted, nums, confidence, ambiguous, is_lot, graded, language,"
                " first_seen, last_seen, first_price, query) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                (item["id"], item["source"], item["title"], (item.get("body") or "")[:600],
                 item["url"], item.get("image", ""), item["price"], item["currency"],
                 item["price_raw"], item.get("location", ""), item.get("posted", ""),
                 json.dumps(m.nums), m.confidence, int(m.ambiguous), int(m.is_lot), m.graded,
                 m.language, now, now, item["price"], query))
            self.db.commit()
        return {**item, **m.as_dict()}

    def _notify(self, h: dict):
        relevant = set(h["nums"]) & self.missing
        if not relevant or h["confidence"] == "niedrig":
            return
        token, chat = os.getenv("TELEGRAM_BOT_TOKEN"), os.getenv("TELEGRAM_CHAT_ID")
        if not (token and chat):
            return
        cards = ", ".join(describe(sorted(relevant)))
        try:
            requests.post(f"https://api.telegram.org/bot{token}/sendMessage", timeout=15, json={
                "chat_id": chat,
                "text": f"🃏 {cards}\n{h['title']}\n{h['price_raw']} · {h['source']} · "
                        f"{h.get('location', '')}\n{h['url']}"})
        except requests.RequestException as e:
            self._log(f"Telegram-Fehler: {e}")

    # --------------------------------------------------------- market prices
    def load_tcgdex(self):
        """Deutsche Namen + Cardmarket-Preise für fehlende Karten (optional, fail-soft)."""
        api = "https://api.tcgdex.net/v2/de"
        cache_f = self.data / "tcgdex_cache.json"
        cache = json.loads(cache_f.read_text()) if cache_f.exists() else {}
        try:
            set_id = self.cfg.get("tcgdex_set_id", "me05")
            s = requests.get(f"{api}/sets/{set_id}", timeout=20)
            s.raise_for_status()
            ids = {}
            names = {}
            for c in s.json().get("cards", []):
                try:
                    n = int(c["localId"])
                except (ValueError, KeyError):
                    continue
                ids[n], names[n] = c["id"], c.get("name")
            apply_tcgdex_names(names)
            self._log(f"TCGdex: {len(names)} deutsche Kartennamen geladen")
        except Exception as e:  # noqa: BLE001
            self._log(f"TCGdex nicht erreichbar ({e}) – Namen aus eingebauter Liste")
            return
        for n in sorted(self.missing, reverse=True):
            if n not in ids:
                continue
            hit = cache.get(ids[n])
            if not hit or time.time() - hit["ts"] > 20 * 3600:
                try:
                    r = requests.get(f"{api}/cards/{ids[n]}", timeout=20)
                    r.raise_for_status()
                    cm = (r.json().get("pricing") or {}).get("cardmarket") or {}
                    vals = [cm.get(k) for k in ("trend", "avg30", "trend-holo", "avg30-holo") if cm.get(k)]
                    hit = {"ts": time.time(), "eur": min(vals[:2]) if vals[:2] else (min(vals) if vals else None)}
                    cache[ids[n]] = hit
                    time.sleep(0.4)
                except Exception:  # noqa: BLE001
                    continue
            BY_NUM[n]["market"] = hit.get("eur")
        cache_f.write_text(json.dumps(cache))

    # -------------------------------------------------------------- loop
    def run_forever(self):
        threading.Thread(target=self.load_tcgdex, daemon=True).start()
        interval = float(self.cfg.get("interval_minutes", 10))
        while not self._stop:
            try:
                self.scan_once()
            except Exception as e:  # noqa: BLE001
                self._log(f"Scan-Fehler: {e}")
            self.next_scan = (datetime.now() + timedelta(minutes=interval)).isoformat(timespec="seconds")
            self._wake.wait(interval * 60)
            self._wake.clear()

    def trigger(self):
        self._wake.set()

    # ---------------------------------------------------------- dashboard
    def state(self, show_all=False) -> dict:
        eur_chf = float(self.cfg.get("eur_chf", 0.94))
        days = int(self.cfg.get("keep_days", 21))
        since = (datetime.now() - timedelta(days=days)).isoformat()
        with self.db_lock:
            rows = [dict(r) for r in self.db.execute(
                "SELECT * FROM hits WHERE hidden=0 AND last_seen>=? ORDER BY first_seen DESC", (since,))]
        missing = self.missing
        stale_cut = (datetime.now() - timedelta(hours=float(self.cfg.get("stale_hours", 6)))).isoformat()
        hits, per_card = [], {}
        for r in rows:
            nums = json.loads(r["nums"])
            r["nums"] = nums
            r["missing_nums"] = [n for n in nums if n in missing]
            r["price_chf"] = None if r["price"] is None else round(
                r["price"] * (eur_chf if r["currency"] == "EUR" else 1), 2)
            r["stale"] = r["last_seen"] < stale_cut
            r["price_drop"] = bool(r["first_price"] and r["price"] is not None
                                   and r["price"] < r["first_price"])
            mk = [BY_NUM[n]["market"] for n in r["missing_nums"] if BY_NUM[n].get("market")]
            r["market_chf"] = round(mk[0] * eur_chf, 2) if len(mk) == 1 and len(r["missing_nums"]) == 1 else None
            if not r["missing_nums"] and not show_all:
                continue
            hits.append(r)
            if r["confidence"] != "niedrig" and not r["stale"]:
                for n in r["missing_nums"]:
                    per_card[n] = per_card.get(n, 0) + 1
        cards = [{**c, "owned": c["num"] in self.owned, "hits": per_card.get(c["num"], 0)}
                 for c in CARDS]
        return {
            "cards": cards, "hits": hits, "owned": len(self.owned), "total": len(CARDS),
            "scanning": self.scanning, "last_scan": self.last_scan, "next_scan": self.next_scan,
            "status": self.status, "log": self.log[-12:], "cycle": self.cycle,
            "eur_chf": eur_chf,
        }

    def hide(self, hit_id: str):
        with self.db_lock:
            self.db.execute("UPDATE hits SET hidden=1 WHERE id=?", (hit_id,))
            self.db.commit()

    def mark_viewed(self):
        with self.db_lock:
            self.db.execute("UPDATE hits SET viewed=1 WHERE viewed=0")
            self.db.commit()
