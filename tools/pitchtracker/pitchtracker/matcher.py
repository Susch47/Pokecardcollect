"""Erkennt, welche ME05-Karte(n) eine Anzeige anbietet.

Stufen:
  sicher  – Kartennummer xxx/084 gefunden (und ggf. Name passt)
  mittel  – Name + Set-Bezug (Dunkelnacht / Pitch Black / ME05), Seltenheit eindeutig
  niedrig – Name ohne Set-Bezug oder mehrere Varianten möglich
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field

from .cards import BY_NUM, CARDS, GENERIC_NAMES

SET_CONTEXT = re.compile(r"dunkel ?nacht|pitch ?black|\bme ?0?5\b|\bpbl\b|/\s*0?84\b", re.I)
NUM_RE = re.compile(r"(?<![\d.,])(\d{1,3})\s*/\s*0?84(?!\d)")
HASH_NUM_RE = re.compile(r"(?:#|nr\.?\s*|no\.?\s*)(\d{1,3})\b", re.I)

HINTS = [   # (Seltenheit, Regex) – Reihenfolge = Priorität
    ("MHR", r"hyper ?rare|\bmhr\b|\bmur\b|\bgold(en|ene|ener)?\b"),
    ("SIR", r"\bsir\b|\bsar\b|special ?illustration|spezial[- ]?illustration"),
    ("IR", r"\bir\b|\bar\b|illustration ?rare|art ?rare"),
    ("UR", r"\bur\b|ultra ?rare|full ?art|\bfa\b|\bsr\b"),
]

EXCLUDE = [
    (r"\bproxy\b|\bfake\b|replika|replica|\borica\b|\bcustom\b|nicht original|kein original|nachdruck", "Fake/Proxy"),
    (r"japan|\bjpn?\b|abyss ?eye|chines|\bchn?\b|korean|\bkor\b|thai", "andere Sprache"),
]
TITLE_EXCLUDE = [
    (r"^\s*(suche|gesucht|tausche|ich suche)\b|\bwtb\b", "Gesuch/Tausch"),
    (r"\bdisplay\b|booster ?(box|bundle|display)|\betb\b|elite ?trainer|top ?trainer|blister|\bsealed\b|\bversiegelt\b|\bovp\b|build ?& ?battle|\bcollection\b|kollektion|\btin\b|^\s*(\d+\s*x\s*)?booster(s| ?packs?)?\b",
     "Sealed/Produkt"),
]
LOT_RE = re.compile(r"sammlung|konvolut|\blot\b|bundle|paket|\bbulk\b|playset|\b\d{2,}\s*(karten|stück|stk)\b|komplett|masterset|set komplett", re.I)
GRADED_RE = re.compile(r"\b(psa|cgc|bgs|beckett|tag|pca|gsg)\s*(10|[1-9](?:[.,]5)?)\b", re.I)
LANG_DE = re.compile(r"\bdeutsch\b|\bger\b|\bde\b", re.I)
LANG_EN = re.compile(r"\benglisch\b|\benglish\b|\beng?\b", re.I)


@dataclass
class Match:
    nums: list[int] = field(default_factory=list)
    confidence: str = ""                 # sicher | mittel | niedrig
    ambiguous: bool = False
    excluded: str = ""
    is_lot: bool = False
    graded: str = ""
    language: str = ""
    set_context: bool = False

    def as_dict(self):
        return self.__dict__.copy()


def _norm(s: str) -> str:
    return " ".join((s or "").lower().replace("’", "'").replace("‘", "'").split())


def _has_name(text: str, name: str) -> bool:
    if not name:
        return False
    pat = re.escape(name).replace(r"\ ", r"[\s\-]*").replace("'", "'?")
    return re.search(rf"(?<![a-zäöü]){pat}(?![a-zäöü])", text) is not None


def match_listing(title: str, body: str = "") -> Match:
    t, b = _norm(title), _norm(body)
    full = f"{t} \n {b}"
    m = Match(set_context=bool(SET_CONTEXT.search(full)))

    for pat, why in TITLE_EXCLUDE:
        if re.search(pat, t, re.I):
            m.excluded = why
            return m
    for pat, why in EXCLUDE:
        if re.search(pat, full, re.I):
            m.excluded = why
            return m

    g = GRADED_RE.search(full)
    if g:
        m.graded = f"{g.group(1).upper()} {g.group(2)}"
    if LANG_DE.search(full):
        m.language = "DE"
    elif LANG_EN.search(full):
        m.language = "EN"

    # --- 1. Kartennummern ------------------------------------------------
    nums = sorted({int(x) for x in NUM_RE.findall(full) if 1 <= int(x) <= 120})
    if not nums and m.set_context:
        nums = sorted({int(x) for x in HASH_NUM_RE.findall(full) if 1 <= int(x) <= 120})
    if nums:
        m.nums, m.confidence = nums, "sicher"
        m.is_lot = len(nums) > 3 or bool(LOT_RE.search(t))
        return m

    # --- 2. Namen ----------------------------------------------------------
    hint = next((r for r, p in HINTS if re.search(p, full, re.I)), None)
    wants_mega = bool(re.search(r"\bmega\b|\bm[\s-]", full))
    wants_ex = bool(re.search(r"\bex\b|-ex\b", full))
    groups: dict[str, list] = {}
    for c in CARDS:
        names = {c["core"], c["core_de"]} - {""}
        if c["core"] in GENERIC_NAMES and not m.set_context:
            continue
        if any(_has_name(full, n) for n in names):
            groups.setdefault(c["core"], []).append(c)
    if not groups:
        return m

    # Längere Namen schlucken kürzere (z.B. "gladion's final battle" vs. nichts),
    # kurze Allerwelts-Namen nur mit Set-Bezug
    chosen: list[int] = []
    ambiguous = False
    for core, cands in groups.items():
        sel = cands
        if hint:
            sel = [c for c in cands if c["rarity"] == hint] or sel
        elif len(cands) > 1:
            if wants_mega and any(c["mega"] for c in cands):
                sel = [c for c in sel if c["mega"]]
            elif any(c["ex"] for c in cands) and not wants_ex:
                sel = [c for c in sel if not c["ex"]] or sel
            # ohne Hinweis: Basiskarte vor Secret Rares bevorzugen, aber als unklar markieren
        if len(sel) > 1:
            ambiguous = True
        chosen.extend(c["num"] for c in sel)

    m.nums = sorted(set(chosen))
    m.ambiguous = ambiguous
    m.is_lot = len(groups) > 3 or bool(LOT_RE.search(t))
    m.confidence = "mittel" if (m.set_context and not ambiguous) else "niedrig"
    return m


def describe(nums: list[int]) -> list[str]:
    return [f"#{n:03d} {BY_NUM[n]['de']} ({BY_NUM[n]['rarity']})" for n in nums if n in BY_NUM]
