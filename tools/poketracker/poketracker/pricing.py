"""Marktpreise über TCGdex (kostenlos, ohne API-Key, liefert Cardmarket-Preise in EUR)."""
from __future__ import annotations

import json
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

import requests

API = "https://api.tcgdex.net/v2"


@dataclass
class MarketPrice:
    card_id: str
    name: str
    set_name: str
    trend: Optional[float]
    avg30: Optional[float]
    avg7: Optional[float]
    low: Optional[float]
    updated: str = ""
    source: str = "cardmarket/tcgdex"

    @property
    def conservative(self) -> Optional[float]:
        """Vorsichtiger Wiederverkaufswert: min(trend, avg30) – Ausreisser dämpfen."""
        vals = [v for v in (self.trend, self.avg30) if v]
        return min(vals) if vals else (self.avg7 or None)


def extract_cardmarket(card: dict, variant: str = "auto") -> dict:
    """Wählt aus TCGdex 'pricing.cardmarket' die passenden Felder (normal vs. holo)."""
    cm = ((card.get("pricing") or {}).get("cardmarket")) or {}
    if not cm:
        return {}
    holo = {k: cm.get(f"{k}-holo") for k in ("trend", "avg30", "avg7", "low")}
    norm = {k: cm.get(k) for k in ("trend", "avg30", "avg7", "low")}
    if variant in ("holo", "reverse"):
        chosen = holo if any(holo.values()) else norm
    elif variant == "normal":
        chosen = norm
    else:  # auto: Kartenvariante mit Preis nehmen, normal bevorzugt
        chosen = norm if any(norm.values()) else holo
    chosen["updated"] = cm.get("updated", "")
    return chosen


class PriceService:
    def __init__(self, cache_file: str | Path = "data/price_cache.json",
                 max_age_h: float = 20, lang: str = "de"):
        self.cache_file = Path(cache_file)
        self.cache_file.parent.mkdir(parents=True, exist_ok=True)
        self.max_age = max_age_h * 3600
        self.lang = lang
        self.cache = json.loads(self.cache_file.read_text()) if self.cache_file.exists() else {}

    def _save(self):
        self.cache_file.write_text(json.dumps(self.cache, ensure_ascii=False, indent=1))

    def card(self, card_id: str) -> dict:
        hit = self.cache.get(card_id)
        if hit and time.time() - hit["_ts"] < self.max_age:
            return hit["data"]
        r = requests.get(f"{API}/{self.lang}/cards/{card_id}", timeout=20)
        r.raise_for_status()
        data = r.json()
        self.cache[card_id] = {"_ts": time.time(), "data": data}
        self._save()
        return data

    def price(self, card_id: str, variant: str = "auto") -> MarketPrice:
        c = self.card(card_id)
        cm = extract_cardmarket(c, variant)
        return MarketPrice(
            card_id=card_id,
            name=c.get("name", card_id),
            set_name=(c.get("set") or {}).get("name", ""),
            trend=cm.get("trend"), avg30=cm.get("avg30"),
            avg7=cm.get("avg7"), low=cm.get("low"), updated=cm.get("updated", ""),
        )

    def find(self, name: str) -> list[dict]:
        """Karten-IDs suchen, z.B. find('Glurak') -> [{'id': 'sv03.5-199', ...}]"""
        r = requests.get(f"{API}/{self.lang}/cards", params={"name": name}, timeout=20)
        r.raise_for_status()
        return r.json()
