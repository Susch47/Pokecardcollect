"""Offline-Tests: python -m pytest tests/  (oder: python tests/test_tracker.py)"""
from datetime import date
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from poketracker.kleinanzeigen import parse_price, parse_search, parse_detail, search_url
from poketracker.pricing import MarketPrice, extract_cardmarket
from poketracker.validate import Settings, Target, assess
from poketracker.report import write_html

FIX = Path(__file__).parent / "fixtures"
T = Target(name="Glurak-ex SIR", tcgdex_id="sv03.5-199", must_match=["199"],
           any_of=["glurak", "charizard"])
MP = MarketPrice("sv03.5-199", "Glurak-ex", "151", trend=110.0, avg30=100.0, avg7=105.0, low=85.0)
ST = Settings()
TODAY = date(2026, 11, 1)


def ads():
    return {l.ad_id: l for l in parse_search((FIX / "search.html").read_text())}


def test_price_parsing():
    assert parse_price("1.250 € VB") == (1250.0, True)
    assert parse_price("49,90 €") == (49.9, False)
    assert parse_price("VB") == (None, True)
    assert parse_price("Zu verschenken") == (0.0, False)


def test_search_url():
    assert search_url("Glurak 199/165", 2, price_min=40) == \
        "https://www.kleinanzeigen.de/s-anzeige:angebote/preis:40:/seite:2/glurak-199-165/k0"


def test_parse_search():
    a = ads()
    assert len(a) == 7
    d = a["3001"]
    assert d.price == 55 and d.negotiable and d.shipping_possible and d.direct_buy
    assert d.url.startswith("https://www.kleinanzeigen.de/s-anzeige/")
    assert "Kirkel" in d.location and d.image


def test_verdicts():
    a = ads()
    v = {k: assess(l, T, MP, ST, TODAY) for k, l in a.items()}
    assert v["3001"].verdict == "DEAL", v["3001"].flags          # 55 € bei Markt 100
    assert v["3002"].verdict == "SKIP" and "Proxy" in v["3002"].reasons_skip[0]
    assert v["3003"].verdict == "SKIP" and "graded" in v["3003"].reasons_skip[0]
    assert v["3004"].verdict == "SKIP"                            # japanisch
    assert v["3005"].verdict == "SKIP"                            # zu teuer
    assert any("verdächtig" in f for f in v["3006"].flags) and v["3006"].risk >= 35
    assert v["3007"].verdict == "SKIP"                            # Gesuch


def test_condition_negation():
    l = ads()["3001"]
    l.description = "Kein Knick, keine Kratzer, direkt aus dem Booster in die Sleeve."
    a = assess(l, T, MP, ST, TODAY)
    assert a.condition_factor == 1.0 and a.positives
    l.description = "Leichter Knick an der Ecke."
    assert assess(l, T, MP, ST, TODAY).condition_factor == 0.65


def test_detail_and_seller_risk():
    l = ads()["3001"]
    parse_detail((FIX / "detail_scam.html").read_text(), l)
    assert l.seller_since == "20.10.2026" and l.image_count == 1
    a = assess(l, T, MP, ST, TODAY)
    assert a.verdict != "DEAL" and a.risk > 40, (a.risk, a.flags)
    assert any("ausserhalb" in f for f in a.flags)

    l2 = ads()["3001"]
    parse_detail((FIX / "detail_good.html").read_text(), l2)
    assert l2.shipping_cost == 4.89 and l2.image_count == 3 and "Zuverlässig" in l2.seller_badges
    a2 = assess(l2, T, MP, ST, TODAY)
    assert a2.verdict == "DEAL" and a2.risk == 0 and a2.message_draft


def test_tcgdex_pricing():
    card = {"pricing": {"cardmarket": {"updated": "2026-10-31", "trend": 3.1, "avg30": 2.9,
                                       "trend-holo": 410.0, "avg30-holo": 395.0}}}
    assert extract_cardmarket(card, "holo")["trend"] == 410.0
    assert extract_cardmarket(card, "auto")["trend"] == 3.1


def test_report(tmp_path=Path("/tmp")):
    res = [assess(l, T, MP, ST, TODAY) for l in ads().values()]
    p = write_html(res, tmp_path / "report.html", show_skips=True)
    assert "DEAL" in p.read_text()


if __name__ == "__main__":
    for name, fn in list(globals().items()):
        if name.startswith("test_"):
            fn()
            print("ok", name)
