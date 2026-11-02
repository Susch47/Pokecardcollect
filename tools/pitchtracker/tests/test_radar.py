"""Offline-Tests: python tests/test_radar.py"""
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from pitchtracker.cards import CARDS, BY_NUM
from pitchtracker.engine import Tracker, parse_ranges
from pitchtracker.matcher import match_listing
from pitchtracker.sources import Kleinanzeigen, parse_amount

FIX = Path(__file__).parent / "fixtures"


class FakeSource:
    def __init__(self, name, items):
        self.name, self.items, self.calls = name, items, []

    def search(self, q, pages=None):
        self.calls.append(q)
        return self.items


def ka(i, title, price, body=""):
    return dict(id=f"ka:{i}", source="kleinanzeigen", title=title, body=body, url=f"https://www.kleinanzeigen.de/s-anzeige/x/{i}",
                image="", price_raw=price, price=parse_amount(price), currency="EUR", location="66424 Homburg", posted="Heute")


def tu(i, title, price, body=""):
    return dict(id=f"tutti:{i}", source="tutti", title=title, body=body, url=f"https://www.tutti.ch/de/vi/x/{i}",
                image="", price_raw=price, price=parse_amount(price), currency="CHF", location="8157 Dielsdorf (ZH)", posted="")


def test_cards():
    assert len(CARDS) == 120 and BY_NUM[116]["rarity"] == "SIR" and BY_NUM[120]["rarity"] == "MHR"
    assert BY_NUM[48]["de"] == "Mega-Darkrai-ex" and BY_NUM[30]["de"] == "Lahmus"


def test_amounts():
    assert parse_amount("1.250 € VB") == 1250 and parse_amount("CHF 1'250.–") == 1250
    assert parse_amount("25.-") == 25 and parse_amount("12,50 €") == 12.5
    assert parse_amount("VB") is None and parse_amount("Gratis") == 0


def test_ranges():
    assert parse_ranges("85-88, 116;120") == {85, 86, 87, 88, 116, 120}


def test_matcher_cases():
    assert match_listing("Mega Darkrai ex 116/084 SIR Dunkelnacht").nums == [116]
    assert match_listing("Pokemon Dunkelnacht Gwynn SIR").nums == [119]
    assert match_listing("Lahmus Illustration Rare Dunkelnacht").nums == [90]
    assert match_listing("Suche Mega Darkrai 116/084").excluded
    assert match_listing("Mega Darkrai SIR japanisch").excluded
    assert match_listing("Dunkelnacht Display").excluded
    assert match_listing("Glurak ex 199/165").nums == []
    m = match_listing("Mega Darkrai ex Dunkelnacht")
    assert m.ambiguous and m.confidence == "niedrig"


def test_kleinanzeigen_parser():
    items = Kleinanzeigen.parse((FIX / "ka_search.html").read_text())
    assert len(items) == 2 and items[0]["price"] == 89 and items[0]["image"]


def test_tutti_convert():
    try:
        from pitchtracker.sources import Tutti
    except ImportError:
        return
    node = {"listingID": "77001", "title": "Gwynn SIR Dunkelnacht", "body": "neu",
            "formattedPrice": "45.-", "timestamp": "2026-11-01T14:10:00+01:00",
            "postcodeInformation": {"postcode": "8157", "locationName": "Dielsdorf", "canton": {"shortName": "ZH"}},
            "thumbnail": {"normalRendition": {"src": "https://c.tutti.ch/x.jpg"}},
            "seoInformation": {"deSlug": "gwynn-sir"}}
    d = Tutti.convert(node)
    assert d["price"] == 45 and d["currency"] == "CHF" and d["url"].endswith("/gwynn-sir/77001")
    assert "Dielsdorf (ZH)" in d["location"]


def test_engine_end_to_end():
    with tempfile.TemporaryDirectory() as tmp:
        s1 = FakeSource("kleinanzeigen", [
            ka(1, "Mega Darkrai ex 116/084 SIR Dunkelnacht", "289 € VB"),
            ka(2, "Dunkelnacht Sammlung 40 Karten", "35 €", "u.a. 001/084, 002/084, 090/084, 119/084"),
            ka(3, "Glurak ex 199/165", "120 €"),
            ka(4, "Suche Gwynn SIR", "50 €"),
        ])
        s2 = FakeSource("tutti", [
            tu(1, "Pokemon Dunkelnacht Gwynn SIR", "CHF 55.–"),
            tu(2, "Lahmus Pokemon Karte", "3.-"),
        ])
        t = Tracker({"targeted_per_cycle": 3}, data_dir=tmp, sources=[s1, s2])
        t.replace_collection(set(range(1, 85)))            # Hauptset komplett
        new = t.scan_once()
        assert {h["id"] for h in new} == {"ka:1", "ka:2", "tutti:1", "tutti:2"}
        assert len(s1.calls) == 2 + 3 and s1.calls[2] == "darkrai 120"   # Secret Rares zuerst
        st = t.state()
        ids = {h["id"]: h for h in st["hits"]}
        assert ids["ka:2"]["missing_nums"] == [90, 119] and ids["ka:2"]["is_lot"]
        assert ids["ka:1"]["price_chf"] == round(289 * 0.94, 2)
        assert {c["num"]: c["hits"] for c in st["cards"]}[119] == 2       # KA-Lot + tutti
        # zweiter Scan: nichts neu, Preis gesenkt wird erkannt
        s1.items[0]["price"], s1.items[0]["price_raw"] = 249.0, "249 €"
        assert t.scan_once() == []
        assert {h["id"]: h for h in t.state()["hits"]}["ka:1"]["price_drop"]
        # 119 als vorhanden markieren -> tutti:1 verschwindet aus der Liste
        t.set_owned({119}, True)
        assert "tutti:1" not in {h["id"] for h in t.state()["hits"]}


if __name__ == "__main__":
    for n, fn in list(globals().items()):
        if n.startswith("test_"):
            fn()
            print("ok", n)
