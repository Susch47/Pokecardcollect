"""Kommandozeile:
  python -m poketracker run              # ein Durchlauf, Report nach data/report.html
  python -m poketracker run --loop 30    # alle 30 Minuten
  python -m poketracker find Glurak      # TCGdex-IDs für die Watchlist finden
  python -m poketracker price sv03.5-199 # Marktpreis einer Karte anzeigen
"""
from __future__ import annotations

import argparse
import sys
import time
import webbrowser
from pathlib import Path

import yaml

from .kleinanzeigen import KleinanzeigenClient
from .pricing import PriceService
from .report import notify_telegram, write_html
from .store import Store
from .validate import Settings, Target, assess


def load_config(path: str):
    cfg = yaml.safe_load(Path(path).read_text(encoding="utf-8"))
    st = Settings(**(cfg.get("settings") or {}))
    targets = [Target(**t) for t in cfg.get("watchlist", [])]
    return cfg, st, targets


def run_once(cfg_path: str, detail_limit: int, show_skips: bool, open_report: bool):
    cfg, st, targets = load_config(cfg_path)
    ka = KleinanzeigenClient(delay=tuple(cfg.get("request_delay", (4, 9))))
    prices = PriceService()
    store = Store()
    results = []
    for t in targets:
        mp = None
        if t.tcgdex_id and not t.market_override:
            try:
                mp = prices.price(t.tcgdex_id, t.variant)
                print(f"▶ {t.name}: Markt {mp.conservative} € (Trend {mp.trend}, Ø30 {mp.avg30})")
            except Exception as e:  # noqa: BLE001
                print(f"  ! Preis für {t.tcgdex_id} nicht abrufbar: {e}")
        seen: dict[str, object] = {}
        for q in (t.queries or [t.name]):
            try:
                for lst in ka.search(q, pages=cfg.get("pages", 1),
                                     price_min=t.price_min, price_max=t.price_max):
                    seen.setdefault(lst.ad_id, lst)
            except Exception as e:  # noqa: BLE001
                print(f"  ! Suche '{q}' fehlgeschlagen: {e}")
        print(f"  {len(seen)} Anzeigen gefunden")
        # Erste Bewertung nur mit Suchergebnis, Detailseiten nur für Kandidaten
        prelim = [assess(l, t, mp, st) for l in seen.values()]
        cands = sorted((a for a in prelim if a.verdict != "SKIP"),
                       key=lambda a: -(a.profit or 0))[:detail_limit]
        cand_ids = {a.listing.ad_id for a in cands}
        for a in prelim:
            if a.listing.ad_id in cand_ids:
                try:
                    ka.load_detail(a.listing)
                    a = assess(a.listing, t, mp, st)
                except Exception as e:  # noqa: BLE001
                    a.flags.append(f"Detailseite nicht ladbar: {e}")
            info = store.upsert(a)
            if a.verdict == "DEAL" and (not info["notified"] or info["price_drop"]):
                if notify_telegram(a, info):
                    store.mark_notified(a.listing.ad_id)
            if a.verdict != "SKIP":
                tag = "NEU " if info["new"] else ("↓ " if info["price_drop"] else "")
                print(f"  [{a.verdict}] {tag}{a.listing.price_raw:>10} | Gewinn {a.profit} € | "
                      f"Risiko {a.risk} | {a.listing.title[:60]}")
            results.append(a)
    p = write_html(results, show_skips=show_skips)
    print(f"\nReport: {p.resolve()}")
    if open_report:
        webbrowser.open(p.resolve().as_uri())


def main(argv=None):
    ap = argparse.ArgumentParser(prog="poketracker")
    sub = ap.add_subparsers(dest="cmd", required=True)
    r = sub.add_parser("run")
    r.add_argument("--config", default="config.yaml")
    r.add_argument("--loop", type=int, default=0, help="Minuten zwischen Durchläufen")
    r.add_argument("--details", type=int, default=8, help="max. Detailseiten pro Karte")
    r.add_argument("--show-skips", action="store_true")
    r.add_argument("--open", action="store_true", help="Report im Browser öffnen")
    f = sub.add_parser("find")
    f.add_argument("name")
    pr = sub.add_parser("price")
    pr.add_argument("card_id")
    pr.add_argument("--variant", default="auto")
    args = ap.parse_args(argv)

    if args.cmd == "find":
        for c in PriceService().find(args.name)[:40]:
            print(f"{c['id']:<18} {c.get('localId', ''):<6} {c['name']}")
    elif args.cmd == "price":
        mp = PriceService().price(args.card_id, args.variant)
        print(f"{mp.name} ({mp.set_name}) – Trend {mp.trend} €, Ø30 {mp.avg30} €, "
              f"Ø7 {mp.avg7} €, Tief {mp.low} € → konservativ {mp.conservative} €")
    else:
        while True:
            run_once(args.config, args.details, args.show_skips, args.open and not args.loop)
            if not args.loop:
                break
            print(f"… nächster Lauf in {args.loop} min")
            time.sleep(args.loop * 60)


if __name__ == "__main__":
    sys.exit(main())
