"""HTML-Report und optionale Telegram-Benachrichtigung."""
from __future__ import annotations

import html
import os
from datetime import datetime
from pathlib import Path

import requests

COLORS = {"DEAL": "#1a7f37", "PRÜFEN": "#b26a00", "SKIP": "#8c959f"}


def _eur(v):
    return "–" if v is None else f"{v:,.0f} €".replace(",", "'")


def write_html(assessments, path="data/report.html", show_skips=False) -> Path:
    order = {"DEAL": 0, "PRÜFEN": 1, "SKIP": 2}
    rows = sorted((a for a in assessments if show_skips or a.verdict != "SKIP"),
                  key=lambda a: (order[a.verdict], -(a.profit or -9999)))
    trs = []
    for a in rows:
        l = a.listing
        flags = "".join(f"<li class=f>⚠ {html.escape(f)}</li>" for f in a.flags)
        pos = "".join(f"<li class=p>✓ {html.escape(p)}</li>" for p in a.positives)
        skip = "".join(f"<li class=s>✗ {html.escape(s)}</li>" for s in a.reasons_skip)
        img = f"<img src='{html.escape(l.image)}' loading=lazy>" if l.image else ""
        msg = (f"<details><summary>Nachricht an Verkäufer</summary><textarea readonly>"
               f"{html.escape(a.message_draft)}</textarea></details>") if a.message_draft else ""
        trs.append(f"""
<tr>
 <td><span class=v style='background:{COLORS[a.verdict]}'>{a.verdict}</span><br><small>Risiko {a.risk}</small></td>
 <td>{img}</td>
 <td><a href='{html.escape(l.url)}' target=_blank>{html.escape(l.title)}</a><br>
     <small>{html.escape(a.target.name)} · {html.escape(l.location)} · {html.escape(l.posted)}</small>
     <ul>{skip}{flags}{pos}</ul>{msg}</td>
 <td class=n>{html.escape(l.price_raw or '–')}</td>
 <td class=n>{_eur(a.market_adj)}</td>
 <td class=n>{_eur(a.max_offer)}</td>
 <td class=n><b>{_eur(a.profit)}</b><br><small>{'' if a.margin is None else f'{a.margin:.0%}'}</small></td>
</tr>""")
    doc = f"""<!DOCTYPE html><html lang=de><meta charset=utf-8>
<title>Pokémon Deal-Tracker</title>
<style>
body{{font:14px system-ui,sans-serif;margin:24px;color:#1f2328}}
table{{border-collapse:collapse;width:100%}} td,th{{border-bottom:1px solid #d0d7de;padding:8px;vertical-align:top;text-align:left}}
th{{background:#f6f8fa;position:sticky;top:0}} .n{{text-align:right;white-space:nowrap}}
img{{width:90px;border-radius:4px}} ul{{margin:4px 0;padding-left:16px}} li.f{{color:#9a6700}} li.p{{color:#1a7f37}} li.s{{color:#8c959f}}
.v{{color:#fff;padding:2px 8px;border-radius:10px;font-weight:600;font-size:12px}}
textarea{{width:100%;height:70px;font:inherit}}
</style>
<h1>Pokémon Deal-Tracker</h1>
<p>Stand {datetime.now():%d.%m.%Y %H:%M} · {sum(a.verdict=='DEAL' for a in assessments)} Deals ·
{sum(a.verdict=='PRÜFEN' for a in assessments)} zu prüfen · {len(assessments)} Anzeigen bewertet.
Marktwert = min(Cardmarket-Trend, 30-Tage-Ø) × Zustandsfaktor. Gewinn nach Gebühren und Versand.</p>
<table><tr><th>Urteil</th><th></th><th>Anzeige</th><th>Preis</th><th>Marktwert</th><th>Max. Angebot</th><th>Gewinn</th></tr>
{''.join(trs) or '<tr><td colspan=7>Keine Treffer.</td></tr>'}
</table></html>"""
    p = Path(path)
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(doc, encoding="utf-8")
    return p


def notify_telegram(a, info: dict) -> bool:
    token, chat = os.getenv("TELEGRAM_BOT_TOKEN"), os.getenv("TELEGRAM_CHAT_ID")
    if not (token and chat):
        return False
    l = a.listing
    head = "📉 Preissenkung" if info.get("price_drop") else "🔥 Neuer Deal" if a.verdict == "DEAL" else "👀 Prüfen"
    text = (f"{head}: {l.title}\n{l.price_raw} · Markt {_eur(a.market_adj)} · Gewinn {_eur(a.profit)} "
            f"· Risiko {a.risk}\n" + ("⚠ " + "; ".join(a.flags[:3]) + "\n" if a.flags else "") + l.url)
    r = requests.post(f"https://api.telegram.org/bot{token}/sendMessage",
                      json={"chat_id": chat, "text": text, "disable_web_page_preview": False},
                      timeout=15)
    return r.ok
