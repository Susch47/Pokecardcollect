# Pokémon Deal-Tracker (Kleinanzeigen.de)

Findet Pokémon-Karten aus deiner Watchlist auf Kleinanzeigen, prüft jede Anzeige und sagt dir, ob sich der Kauf zum Weiterverkauf lohnt.

## Was er prüft

| Schritt | Prüfung |
|---|---|
| **Relevanz** | Kartennummer und Name müssen vorkommen. Gesuche, Tausch, Sealed-Produkte und Ausschlusswörter (z. B. 1st Edition) fliegen raus |
| **Echtheit** | Proxy/Fake/Orica/Custom/Metal-Hinweise, andere Sprachversionen (JP/CN/KR = anderer Markt) |
| **Grading** | erkennt PSA/CGC/BGS/TAG + Note und trennt raw von graded |
| **Zustand** | Knick, Whitening, LP/MP/HP senken den Marktwert (×0,45–0,82). Verneinungen wie „kein Knick“ werden erkannt |
| **Marktwert** | Cardmarket-Preise über TCGdex (kostenlos). Konservativ: min(Trend, 30-Tage-Ø) |
| **Ökonomie** | Gewinn = Marktwert × (1 − Gebühren) − Versand raus − (Preis + Versand rein). Dazu ein **maximales Angebot** für VB-Anzeigen |
| **Scam-Risiko** (0–100) | verdächtig günstig, PayPal F&F/Vorkasse/WhatsApp, Konto < 30 Tage, nur 1 Bild. Positiv: Direkt kaufen, Badges, altes Konto |
| **Urteil** | **DEAL** / **PRÜFEN** / **SKIP**, dazu ein Nachrichtenentwurf an den Verkäufer |

Detailseiten (Verkäufer, Bilder, Versandkosten) lädt er nur für Kandidaten. Das spart Anfragen.

## Installation

```bash
cd poketracker
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python tests/test_tracker.py        # Offline-Tests
```

## Benutzung

```bash
python -m poketracker find Nachtara            # Karten-IDs finden
python -m poketracker price sv08.5-161         # Marktpreis prüfen
python -m poketracker run --open               # ein Durchlauf + Report öffnen
python -m poketracker run --loop 30            # alle 30 min (Terminal offen lassen)
```

Ergebnisse: `data/report.html` (Report), `data/tracker.db` (Verlauf, Preissenkungen).

**Push aufs Handy (optional):** Erstelle einen Telegram-Bot über @BotFather und setze dann `TELEGRAM_BOT_TOKEN` und `TELEGRAM_CHAT_ID` als Umgebungsvariablen. Du bekommst neue Deals und Preissenkungen aufs Handy.

## Watchlist anpassen (`config.yaml`)

- `queries`: Suchbegriffe (DE + EN, Kleinanzeigen-Verkäufer mischen)
- `must_match`: muss vorkommen (Kartennummer)
- `any_of`: mind. einer davon (Namen)
- `exclude`: z. B. „1st edition“, wenn das eine andere Preisklasse ist
- `condition: graded` + `market_override`: für PSA-Karten (TCGdex kennt nur raw-Preise)

## Grenzen

- Kleinanzeigen erlaubt automatisiertes Auslesen laut AGB nicht. Bei seltener privater Nutzung mit Pausen (Standard 4–9 s) ist das Risiko gering. Intervall nicht zu klein wählen. Bei HTTP 403/429 pausieren.
- Ändert Kleinanzeigen sein HTML, liefern die Selektoren in `kleinanzeigen.py` evtl. weniger Felder. Die Tests zeigen dann, wo es klemmt.
- Fake-Erkennung über Bilder gibt es nicht. Vor dem Kauf immer Fotos von Vorder- und Rückseite verlangen und „Sicher bezahlen“ nutzen.
- Der TCGdex-Preis unterscheidet nicht immer zwischen Varianten (Base Set unlimited/shadowless/1st). Darum gibt es die `exclude`-Liste.
