"""Quellen: Kleinanzeigen.de (HTML) und tutti.ch (interne GraphQL-API via tutti-scraper).

Beide liefern einheitliche dicts:
  id, source, title, body, url, image, price, currency, price_raw, location, posted
"""
from __future__ import annotations

import random
import re
import time
from urllib.parse import quote

import requests
from bs4 import BeautifulSoup

UA = ("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36")


class SourceBlocked(RuntimeError):
    pass


def parse_amount(text: str):
    """'1.250 € VB' -> 1250.0 | 'CHF 1'250.–' -> 1250.0 | '25.-' -> 25.0 | 'VB' -> None"""
    t = (text or "").replace("’", "'").replace("–", "-")
    if re.search(r"verschenken|gratis", t, re.I):
        return 0.0
    m = re.search(r"(\d{1,3}(?:[.']\d{3})+|\d+)(?:[.,](\d{1,2}))?(?!\d)", t)
    if not m:
        return None
    whole = re.sub(r"[.']", "", m.group(1))
    return float(f"{whole}.{m.group(2) or 0}")


# --------------------------------------------------------------------------- #
# Kleinanzeigen.de
# --------------------------------------------------------------------------- #
class Kleinanzeigen:
    name = "kleinanzeigen"
    BASE = "https://www.kleinanzeigen.de"

    def __init__(self, delay=(4.0, 9.0), pages=2):
        self.s = requests.Session()
        self.s.headers.update({"User-Agent": UA, "Accept-Language": "de-DE,de;q=0.9",
                               "Accept": "text/html,application/xhtml+xml,*/*;q=0.8"})
        self.delay, self.pages, self._last = delay, pages, 0.0

    def url(self, query: str, page: int = 1) -> str:
        slug = quote("-".join(re.split(r"[\s/]+", query.lower().strip())), safe="-")
        parts = ["s-anzeige:angebote"] + ([f"seite:{page}"] if page > 1 else [])
        return f"{self.BASE}/{'/'.join(parts)}/{slug}/k0"

    def _get(self, url: str) -> str:
        wait = random.uniform(*self.delay) - (time.time() - self._last)
        if wait > 0:
            time.sleep(wait)
        r = self.s.get(url, timeout=25)
        self._last = time.time()
        if r.status_code in (403, 429):
            raise SourceBlocked(f"Kleinanzeigen blockt ({r.status_code})")
        r.raise_for_status()
        return r.text

    @staticmethod
    def parse(html: str) -> list[dict]:
        soup = BeautifulSoup(html, "html.parser")
        out = []
        txt = lambda el: " ".join(el.get_text(" ", strip=True).split()) if el else ""  # noqa: E731
        for art in soup.select("article.aditem, article[data-adid]"):
            a = art.select_one("h2 a, a.ellipsis, a[href*='/s-anzeige/']")
            href = art.get("data-href") or (a.get("href") if a else "") or ""
            ad_id = art.get("data-adid") or (re.search(r"/(\d{6,})-", href) or [None, ""])[1]
            if not ad_id:
                continue
            box = art.select_one("[data-imgsrc]")
            im = art.select_one(".aditem-image img, .imagebox img")
            img = (box.get("data-imgsrc") if box else "") or (
                (im.get("src") or (im.get("srcset") or "").split(" ")[0]) if im else "")
            price_raw = txt(art.select_one(
                ".aditem-main--middle--price-shipping--price, .aditem-main--middle--price"))
            tags = " ".join(txt(t) for t in art.select(".simpletag")).lower()
            out.append(dict(
                id=f"ka:{ad_id}", source="kleinanzeigen",
                title=txt(a) or txt(art.select_one("h2")),
                body=txt(art.select_one(".aditem-main--middle--description")),
                url=href if href.startswith("http") else Kleinanzeigen.BASE + href,
                image=img, price_raw=price_raw, price=parse_amount(price_raw), currency="EUR",
                location=txt(art.select_one(".aditem-main--top--left")),
                posted=txt(art.select_one(".aditem-main--top--right")),
                shipping=("versand möglich" in tags) or None if "nur abholung" not in tags else False,
            ))
        return out

    def search(self, query: str, pages: int | None = None) -> list[dict]:
        res = []
        for p in range(1, (pages or self.pages) + 1):
            items = self.parse(self._get(self.url(query, p)))
            res += items
            if len(items) < 20:
                break
        return res


# --------------------------------------------------------------------------- #
# tutti.ch
# --------------------------------------------------------------------------- #
class Tutti:
    name = "tutti"

    def __init__(self, delay=2.0, page_size=60):
        from tutti_scraper import TuttiClient  # erst hier importieren -> Tests ohne Paket
        self.client = TuttiClient(lang="de", delay=delay, max_retries=3, timeout=30)
        self.page_size = page_size

    @staticmethod
    def convert(node: dict) -> dict:
        from tutti_scraper import listing_url
        pc = node.get("postcodeInformation") or {}
        canton = (pc.get("canton") or {}).get("shortName") or ""
        thumb = ((node.get("thumbnail") or {}).get("retinaRendition") or {}).get("src") \
            or ((node.get("thumbnail") or {}).get("normalRendition") or {}).get("src") or ""
        price_raw = node.get("formattedPrice") or ""
        return dict(
            id=f"tutti:{node['listingID']}", source="tutti",
            title=node.get("title") or "", body=node.get("body") or "",
            url=listing_url(node) or f"https://www.tutti.ch/de/vi/{node['listingID']}",
            image=thumb, price_raw=price_raw, price=parse_amount(price_raw), currency="CHF",
            location=" ".join(x for x in (pc.get("postcode"), pc.get("locationName"),
                                          f"({canton})" if canton else "") if x),
            posted=(node.get("timestamp") or "")[:16].replace("T", " "),
            shipping=None,
        )

    def search(self, query: str, pages: int | None = None) -> list[dict]:
        data = self.client.search(query, first=self.page_size, offset=0)
        edges = ((data or {}).get("listings") or {}).get("edges") or []
        return [self.convert(e["node"]) for e in edges if e.get("node")]
