"""Kleinanzeigen.de: Suchergebnisse und Detailseiten holen und parsen.

Bewusst defensiv geschrieben: Kleinanzeigen ändert sein HTML gelegentlich.
Jedes Feld hat mehrere Fallbacks, und Detaildaten werden zusätzlich per
Regex aus dem Seitentext gezogen.
"""
from __future__ import annotations

import random
import re
import time
from dataclasses import dataclass, field
from typing import Optional
from urllib.parse import quote

import requests
from bs4 import BeautifulSoup

BASE = "https://www.kleinanzeigen.de"

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
    ),
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "de-DE,de;q=0.9,en;q=0.6",
}


@dataclass
class Listing:
    ad_id: str
    url: str
    title: str
    price_raw: str = ""
    price: Optional[float] = None      # None = nur "VB" / unbekannt
    negotiable: bool = False
    location: str = ""
    posted: str = ""
    description: str = ""
    image: str = ""
    tags: list[str] = field(default_factory=list)
    shipping_possible: Optional[bool] = None
    direct_buy: bool = False
    is_topad: bool = False
    # Detailseite
    detail_loaded: bool = False
    image_count: int = 0
    seller_name: str = ""
    seller_type: str = ""              # "privat" / "gewerblich"
    seller_since: str = ""             # "dd.mm.yyyy"
    seller_badges: list[str] = field(default_factory=list)
    shipping_cost: Optional[float] = None


# --------------------------------------------------------------------------- #
# Hilfsfunktionen
# --------------------------------------------------------------------------- #
def parse_price(text: str) -> tuple[Optional[float], bool]:
    """'1.250 € VB' -> (1250.0, True); 'VB' -> (None, True); 'Zu verschenken' -> (0, False)."""
    t = (text or "").strip()
    neg = "VB" in t
    if "verschenken" in t.lower():
        return 0.0, False
    m = re.search(r"(\d{1,3}(?:\.\d{3})*|\d+)(?:,(\d{1,2}))?\s*€", t)
    if not m:
        return None, neg
    whole = m.group(1).replace(".", "")
    cents = m.group(2) or "0"
    return float(f"{whole}.{cents}"), neg


def _txt(el) -> str:
    return " ".join(el.get_text(" ", strip=True).split()) if el else ""


def search_url(query: str, page: int = 1, price_min: int | None = None,
               price_max: int | None = None) -> str:
    slug = quote("-".join(re.split(r"[\s/]+", query.lower().strip())), safe="-")
    parts = ["s-anzeige:angebote"]           # nur Angebote, keine Gesuche
    if price_min is not None or price_max is not None:
        parts.append(f"preis:{price_min or ''}:{price_max or ''}")
    if page > 1:
        parts.append(f"seite:{page}")
    return f"{BASE}/{'/'.join(parts)}/{slug}/k0"


# --------------------------------------------------------------------------- #
# Parser
# --------------------------------------------------------------------------- #
def parse_search(html: str) -> list[Listing]:
    soup = BeautifulSoup(html, "html.parser")
    out: list[Listing] = []
    for art in soup.select("article.aditem, article[data-adid]"):
        ad_id = art.get("data-adid") or ""
        href = art.get("data-href") or ""
        a = art.select_one("h2 a, a.ellipsis, a[href*='/s-anzeige/']")
        if not href and a:
            href = a.get("href", "")
        if not ad_id:
            m = re.search(r"/(\d{6,})-", href)
            ad_id = m.group(1) if m else ""
        if not ad_id:
            continue
        title = _txt(a) or _txt(art.select_one("h2"))
        price_raw = _txt(art.select_one(
            ".aditem-main--middle--price-shipping--price, .aditem-main--middle--price"))
        price, neg = parse_price(price_raw)
        tags = [_txt(t) for t in art.select(".simpletag, .tag-small")]
        tag_l = " ".join(tags).lower()
        img = ""
        box = art.select_one(".imagebox, .aditem-image")
        if box:
            img = box.get("data-imgsrc", "") or ""
            im = box.select_one("img")
            if not img and im:
                img = im.get("src", "") or (im.get("srcset", "").split(" ")[0])
        shipping = None
        if "versand möglich" in tag_l:
            shipping = True
        elif "nur abholung" in tag_l:
            shipping = False
        out.append(Listing(
            ad_id=ad_id,
            url=href if href.startswith("http") else BASE + href,
            title=title,
            price_raw=price_raw,
            price=price,
            negotiable=neg,
            location=_txt(art.select_one(".aditem-main--top--left")),
            posted=_txt(art.select_one(".aditem-main--top--right")),
            description=_txt(art.select_one(".aditem-main--middle--description")),
            image=img,
            tags=tags,
            shipping_possible=shipping,
            direct_buy="direkt kaufen" in tag_l,
            is_topad="is-topad" in (art.get("class") or [])
                     or bool(art.select_one(".badge-hint-pro-small-srp")),
        ))
    return out


def parse_detail(html: str, lst: Listing) -> Listing:
    soup = BeautifulSoup(html, "html.parser")
    text = " ".join(soup.get_text(" ", strip=True).split())

    desc = soup.select_one("#viewad-description-text, [itemprop='description']")
    if desc:
        lst.description = _txt(desc)
    price_el = soup.select_one("#viewad-price, [itemprop='price']")
    if price_el:
        p, neg = parse_price(_txt(price_el))
        if p is not None or lst.price is None:
            lst.price, lst.negotiable = p, neg
        lst.price_raw = _txt(price_el)

    imgs = {i.get("src") or i.get("data-imgsrc")
            for i in soup.select(".galleryimage-element img, #viewad-image, "
                                 ".galleryimage-element [data-imgsrc]")}
    imgs.discard(None)
    lst.image_count = len(imgs) or (1 if lst.image else 0)

    seller = soup.select_one(".userprofile-vip a, .userprofile-vip, #viewad-contact .text-body-regular-strong")
    lst.seller_name = _txt(seller)
    if re.search(r"Gewerbliche[rn]? (Nutzer|Anbieter)", text):
        lst.seller_type = "gewerblich"
    elif "Privater Nutzer" in text or "Privat" in text:
        lst.seller_type = "privat"
    m = re.search(r"Aktiv seit\s*(\d{2}\.\d{2}\.\d{4})", text)
    lst.seller_since = m.group(1) if m else ""
    lst.seller_badges = sorted({b for b in (
        "TOP Zufriedenheit", "Besonders zufrieden", "Zuverlässig", "Freundlich",
        "Sehr freundlich", "Sehr zuverlässig") if b in text})

    m = re.search(r"Versand(?:kosten)?\s*(?:ab|:)?\s*(\d+(?:,\d{1,2})?)\s*€", text)
    if m:
        lst.shipping_cost = float(m.group(1).replace(",", "."))
        lst.shipping_possible = True
    elif "Nur Abholung" in text:
        lst.shipping_possible = False
    if "Direkt kaufen" in text or "Sicher bezahlen" in text:
        lst.direct_buy = True
    lst.detail_loaded = True
    return lst


# --------------------------------------------------------------------------- #
# HTTP-Client mit Drosselung
# --------------------------------------------------------------------------- #
class KleinanzeigenClient:
    def __init__(self, delay: tuple[float, float] = (4.0, 9.0), timeout: int = 20):
        self.s = requests.Session()
        self.s.headers.update(HEADERS)
        self.delay = delay
        self.timeout = timeout
        self._last = 0.0

    def _get(self, url: str) -> str:
        wait = random.uniform(*self.delay) - (time.time() - self._last)
        if wait > 0:
            time.sleep(wait)
        r = self.s.get(url, timeout=self.timeout)
        self._last = time.time()
        if r.status_code in (403, 429):
            raise RuntimeError(
                f"Kleinanzeigen blockt gerade ({r.status_code}). Pause machen, "
                "Intervall erhöhen oder später erneut versuchen.")
        r.raise_for_status()
        return r.text

    def search(self, query: str, pages: int = 1, **kw) -> list[Listing]:
        res: list[Listing] = []
        for p in range(1, pages + 1):
            items = parse_search(self._get(search_url(query, p, **kw)))
            res.extend(items)
            if len(items) < 20:
                break
        return res

    def load_detail(self, lst: Listing) -> Listing:
        return parse_detail(self._get(lst.url), lst)
