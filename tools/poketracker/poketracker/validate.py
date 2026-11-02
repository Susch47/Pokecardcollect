"""Validierung: Ist das wirklich die gesuchte Karte, ist sie echt, und lohnt sich der Kauf?"""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from datetime import date, datetime
from typing import Optional

from .kleinanzeigen import Listing
from .pricing import MarketPrice

# ----------------------------------------------------------------------------
# Schlüsselwortlisten (alles lowercase, Regex)
# ----------------------------------------------------------------------------
FAKE_PATTERNS = [
    r"\bproxy\b", r"\bfake\b", r"replika|replica", r"nachdruck", r"\bcustom\b",
    r"\borica\b", r"nicht original", r"kein original", r"fan ?art", r"\bmetal(l)?\b",
    r"gold(ene|en)? (karte|card)", r"\bsticker\b", r"\bjumbo\b|\boversize", r"\bdeko\b",
    r"\bnachgemacht", r"\bcopy\b|\bkopie\b", r"\bunofficial|inoffiziell",
]
WANTED_PATTERNS = [r"^\s*suche\b", r"\bgesucht\b", r"\bsuche\s+\w+", r"\btausche?\b(?!.*verkauf)"]
LOT_PATTERNS = [r"sammlung", r"konvolut", r"\blot\b", r"\bbundle\b", r"\bpaket\b",
                r"\b\d{2,}\s*(karten|stück|stk)\b", r"auflösung", r"\bbulk\b"]
FOREIGN_LANG = [r"japan(isch)?|\bjpn?\b|\bjp\b", r"chines(isch|e)|\bchn?\b|s-chinese",
                r"korean(isch)?|\bkor\b", r"thai"]
SEALED_PATTERNS = [r"booster ?(box|display|pack)", r"\bdisplay\b", r"\betb\b",
                   r"top ?trainer", r"\bversiegelt\b", r"\bsealed\b", r"\bblister\b"]
OFFPLATFORM = [r"whats ?app", r"telegram", r"e-?mail an", r"@\w+\.\w+",
               r"freunde (und|&) familie", r"\bf ?& ?f\b", r"paypal freunde",
               r"nur (per )?(überweisung|vorkasse)", r"western union", r"wise\b"]
GRADED_RE = re.compile(r"\b(psa|cgc|bgs|beckett|tag|pca|gsg|ace)\s*(?:gem\s*mint\s*|mint\s*)?(10|[1-9](?:[.,]5)?)\b", re.I)
CONDITION_FACTORS = [   # (Regex, Faktor auf Marktpreis, Label)
    (r"\b(hp|heavily played|stark bespielt|beschädigt|damaged|dmg|wasserschaden|riss)\b", 0.45, "stark bespielt/beschädigt"),
    (r"\b(mp|moderately played|knick|geknickt|crease|bent|verbogen)\b", 0.65, "Knick/MP"),
    (r"\b(lp|lightly played|leicht bespielt|whitening|kratzer|scratch)\b", 0.82, "leichte Gebrauchsspuren"),
    (r"\b(played|bespielt|gespielt)\b", 0.75, "bespielt"),
]


def _any(patterns, text) -> Optional[str]:
    for p in patterns:
        m = re.search(p, text, re.I)
        if m:
            return m.group(0)
    return None


@dataclass
class Target:
    """Ein Watchlist-Eintrag aus config.yaml."""
    name: str
    tcgdex_id: Optional[str] = None
    queries: list[str] = field(default_factory=list)
    must_match: list[str] = field(default_factory=list)   # alle Tokens müssen im Titel/Text vorkommen
    any_of: list[str] = field(default_factory=list)       # mind. eines davon (z.B. Namen DE/EN)
    exclude: list[str] = field(default_factory=list)
    variant: str = "auto"
    condition: str = "raw"            # raw | graded | any
    min_grade: Optional[float] = None
    market_override: Optional[float] = None
    max_buy_pct: Optional[float] = None
    price_min: Optional[int] = None
    price_max: Optional[int] = None


@dataclass
class Settings:
    sell_fee_pct: float = 0.10        # Gebühren beim Weiterverkauf (Plattform + Zahlung)
    ship_in_default: float = 5.0      # angenommene Versandkosten beim Kauf
    ship_out: float = 4.0             # Versand beim Weiterverkauf (Brief mit Tracking)
    min_profit_eur: float = 15.0
    min_margin_pct: float = 0.20
    max_buy_pct: float = 0.75         # max. Kaufpreis relativ zum Marktwert
    too_cheap_pct: float = 0.35       # darunter: Scam/Fake-Verdacht
    max_risk_for_deal: int = 40


@dataclass
class Assessment:
    listing: Listing
    target: Target
    relevant: bool
    reasons_skip: list[str] = field(default_factory=list)
    flags: list[str] = field(default_factory=list)     # Warnungen
    positives: list[str] = field(default_factory=list)
    graded: Optional[str] = None
    condition_factor: float = 1.0
    market: Optional[float] = None
    market_adj: Optional[float] = None
    buy_total: Optional[float] = None
    resale_net: Optional[float] = None
    profit: Optional[float] = None
    margin: Optional[float] = None
    max_offer: Optional[float] = None
    risk: int = 0
    verdict: str = "SKIP"             # DEAL | PRÜFEN | SKIP
    message_draft: str = ""


def _norm(s: str) -> str:
    return " ".join((s or "").lower().replace("–", "-").split())


def assess(lst: Listing, t: Target, mp: Optional[MarketPrice], st: Settings,
           today: Optional[date] = None) -> Assessment:
    today = today or date.today()
    title = _norm(lst.title)
    full = _norm(f"{lst.title} {lst.description}")
    a = Assessment(listing=lst, target=t, relevant=True)

    # ---- 1. Relevanz -------------------------------------------------------
    for tok in t.must_match:
        if _norm(tok) not in full:
            a.reasons_skip.append(f"'{tok}' fehlt")
    if t.any_of and not any(_norm(x) in full for x in t.any_of):
        a.reasons_skip.append("Kartenname fehlt")
    for ex in t.exclude:
        if _norm(ex) in full:
            a.reasons_skip.append(f"ausgeschlossen: '{ex}'")
    if (w := _any(WANTED_PATTERNS, title)):
        a.reasons_skip.append(f"Gesuch/Tausch ('{w}')")
    if (s := _any(SEALED_PATTERNS, title)):
        a.reasons_skip.append(f"Sealed-Produkt ('{s}')")
    if (f := _any(FAKE_PATTERNS, full)):
        a.reasons_skip.append(f"Fake/Proxy-Hinweis ('{f}')")
    if (fl := _any(FOREIGN_LANG, full)):
        a.reasons_skip.append(f"andere Sprachversion ('{fl}') – anderer Marktpreis")
    if (lot := _any(LOT_PATTERNS, title)):
        a.flags.append(f"Sammlung/Lot ('{lot}') – Einzelbewertung nötig")

    g = GRADED_RE.search(full)
    if g:
        grade = float(g.group(2).replace(",", "."))
        a.graded = f"{g.group(1).upper()} {g.group(2)}"
        if t.condition == "raw":
            a.reasons_skip.append(f"graded ({a.graded}), Watchlist sucht raw")
        elif t.min_grade and grade < t.min_grade:
            a.reasons_skip.append(f"Grade {grade} < {t.min_grade}")
    elif t.condition == "graded":
        a.reasons_skip.append("nicht graded")

    if a.reasons_skip:
        a.relevant = False
        return a

    # ---- 2. Zustand -------------------------------------------------------
    if not a.graded:
        for pat, factor, label in CONDITION_FACTORS:
            hit = next((m for m in re.finditer(pat, full, re.I)
                        if not re.search(r"\b(kein|keine|keinen|ohne|nicht)\b[\w ]{0,12}$",
                                         full[max(0, m.start() - 20):m.start()])), None)
            if hit:
                a.condition_factor = factor
                a.flags.append(f"Zustand: {label} (Wert ×{factor})")
                break
        if re.search(r"near mint|\bnm\b|\bmint\b|top zustand|wie neu|direkt aus (dem )?(booster|pack)|pack fresh|sleeve", full):
            a.positives.append("als NM/pack fresh beschrieben")

    # ---- 3. Preis & Ökonomie ---------------------------------------------
    if t.market_override:
        a.market = t.market_override
    elif mp and mp.conservative:
        a.market = mp.conservative
    if a.graded and not t.market_override:
        a.flags.append("Graded: Marktpreis ist raw-Preis – market_override setzen")

    max_buy_pct = t.max_buy_pct or st.max_buy_pct
    if a.market:
        a.market_adj = round(a.market * a.condition_factor, 2)
        a.resale_net = round(a.market_adj * (1 - st.sell_fee_pct) - st.ship_out, 2)
        a.max_offer = round(min(a.market_adj * max_buy_pct,
                                a.resale_net / (1 + st.min_margin_pct) - st.ship_in_default), 0)
    else:
        a.flags.append("kein Marktpreis verfügbar")

    ship_in = lst.shipping_cost if lst.shipping_cost is not None else st.ship_in_default
    if lst.price is not None and a.market_adj:
        a.buy_total = round(lst.price + ship_in, 2)
        a.profit = round(a.resale_net - a.buy_total, 2)
        a.margin = round(a.profit / a.buy_total, 3) if a.buy_total else None
        if lst.price < a.market_adj * st.too_cheap_pct:
            a.flags.append(f"verdächtig günstig ({lst.price:.0f} € vs. Markt {a.market_adj:.0f} €)")
            a.risk += 45
    elif lst.price is None:
        a.flags.append("kein Preis (nur VB) – Angebot anfragen")

    # ---- 4. Verkäufer- & Scam-Risiko -------------------------------------
    if (o := _any(OFFPLATFORM, full)):
        a.flags.append(f"will ausserhalb der Plattform zahlen/kommunizieren ('{o}')")
        a.risk += 30
    if lst.shipping_possible is False:
        a.flags.append("nur Abholung")
        a.risk += 5
    if lst.direct_buy:
        a.positives.append("'Direkt kaufen' / Sicher bezahlen möglich")
        a.risk -= 10
    if lst.detail_loaded:
        if lst.image_count <= 1:
            a.flags.append("nur ein Bild – Vorder-/Rückseite anfragen")
            a.risk += 10
        if lst.seller_since:
            try:
                since = datetime.strptime(lst.seller_since, "%d.%m.%Y").date()
                days = (today - since).days
                if days < 30:
                    a.flags.append(f"Konto erst {days} Tage alt")
                    a.risk += 30
                elif days < 180:
                    a.flags.append(f"Konto jung ({days} Tage)")
                    a.risk += 10
                elif days > 3 * 365:
                    a.positives.append(f"Konto seit {since.year}")
                    a.risk -= 5
            except ValueError:
                pass
        if lst.seller_badges:
            a.positives.append("Badges: " + ", ".join(lst.seller_badges))
            a.risk -= 5 * len(lst.seller_badges)
        if lst.seller_type == "gewerblich":
            a.positives.append("gewerblicher Händler (Gewährleistung)")
    if lst.is_topad:
        a.flags.append("bezahlte Top-Anzeige")
    a.risk = max(0, min(100, a.risk))

    # ---- 5. Urteil --------------------------------------------------------
    good_econ = (a.profit is not None and a.profit >= st.min_profit_eur
                 and (a.margin or 0) >= st.min_margin_pct)
    if good_econ and a.risk <= st.max_risk_for_deal:
        a.verdict = "DEAL"
    elif good_econ or (lst.price is None and a.market_adj and a.market_adj >= st.min_profit_eur * 3):
        a.verdict = "PRÜFEN"
    elif (a.profit is not None and lst.negotiable and a.max_offer
          and lst.price and lst.price * 0.8 <= a.max_offer):
        a.verdict = "PRÜFEN"
        a.flags.append(f"VB – lohnt sich bei ≤ {a.max_offer:.0f} €")
    else:
        a.verdict = "SKIP"
    if a.verdict != "SKIP":
        a.message_draft = draft_message(a)
    return a


def draft_message(a: Assessment) -> str:
    lst = a.listing
    ask = []
    if lst.image_count <= 1 or not lst.detail_loaded:
        ask.append("Könntest du mir noch Fotos von Vorder- und Rückseite (inkl. Ecken) schicken?")
    if lst.shipping_possible is not False:
        ask.append("Versand wäre versichert/mit Sendungsverfolgung über 'Sicher bezahlen' ideal.")
    offer = ""
    if a.max_offer and (lst.price is None or a.max_offer < lst.price):
        offer = f" Wäre {a.max_offer:.0f} € inkl. Versand für dich in Ordnung?"
    return (f"Hallo, ist die Karte \"{lst.title}\" noch zu haben?{offer} "
            + " ".join(ask) + " Danke und viele Grüsse")
